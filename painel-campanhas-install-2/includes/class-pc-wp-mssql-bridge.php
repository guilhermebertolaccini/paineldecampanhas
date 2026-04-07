<?php
/**
 * Ponte operacional: MySQL (WordPress) → SQL Server (DB_DIGITAL).
 *
 * - Espelha tabelas operacionais em dbo.PC_WP_MIRROR_ROWS (JSON por linha, MERGE por PK).
 * - Mantém dbo.PC_LINE_HEALTH_SNAPSHOT (saúde/tier agregado por linha fornecedor + idgis).
 * - Snapshot recalculado a partir do MySQL (fonte da verdade operacional no WP).
 * - Espelho completo recomendado no cron diário; snapshot também na abertura das telas de saúde.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PC_Wp_Mssql_Bridge
{
    private const MIRROR_TABLE = 'PC_WP_MIRROR_ROWS';
    private const SNAPSHOT_TABLE = 'PC_LINE_HEALTH_SNAPSHOT';

    /** Chaves lógicas permitidas (anti-injeção no nome da origem). */
    private const LOGICAL_TABLES = [
        'envios_pendentes',
        'eventos_envios',
        'eventos_indicadores',
        'eventos_tempos',
        'salesforce_returns',
    ];

    /** @var bool */
    private static $schema_checked = false;

    /**
     * Após alterar host/banco/credenciais no mesmo processo PHP, força nova tentativa de DDL.
     */
    public static function reset_schema_cache(): void
    {
        self::$schema_checked = false;
    }

    public static function is_available(): bool
    {
        return class_exists('PC_SqlServer_Connector') && PC_SqlServer_Connector::is_enabled();
    }

    /**
     * Cria no SQL Server (ex.: .26) as tabelas dbo.PC_WP_MIRROR_ROWS e dbo.PC_LINE_HEALTH_SNAPSHOT
     * se ainda não existirem — não é necessário criar manualmente.
     */
    public static function ensure_schema(): bool
    {
        if (self::$schema_checked) {
            return true;
        }
        if (!self::is_available()) {
            return false;
        }
        $pdo = PC_SqlServer_Connector::get_pdo_primary();
        if (!$pdo) {
            return false;
        }
        $ddl = "
IF OBJECT_ID(N'dbo." . self::MIRROR_TABLE . "', N'U') IS NULL
BEGIN
    CREATE TABLE dbo." . self::MIRROR_TABLE . " (
        source_table NVARCHAR(64) NOT NULL,
        wp_row_id BIGINT NOT NULL,
        payload_json NVARCHAR(MAX) NULL,
        synced_at DATETIME2 NOT NULL CONSTRAINT DF_" . self::MIRROR_TABLE . "_SYNC DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_" . self::MIRROR_TABLE . " PRIMARY KEY (source_table, wp_row_id)
    );
END
IF OBJECT_ID(N'dbo." . self::SNAPSHOT_TABLE . "', N'U') IS NULL
BEGIN
    CREATE TABLE dbo." . self::SNAPSHOT_TABLE . " (
        line_key NVARCHAR(200) NOT NULL,
        nome_linha NVARCHAR(512) NULL,
        provedor NVARCHAR(128) NULL,
        idgis_ambiente NVARCHAR(64) NULL,
        saude_tier NVARCHAR(32) NOT NULL,
        metricas_json NVARCHAR(MAX) NULL,
        updated_at DATETIME2 NOT NULL CONSTRAINT DF_" . self::SNAPSHOT_TABLE . "_UPD DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_" . self::SNAPSHOT_TABLE . " PRIMARY KEY (line_key)
    );
END
";
        try {
            $pdo->exec($ddl);
            self::$schema_checked = true;
            return true;
        } catch (PDOException $e) {
            error_log('[PC_Wp_Mssql_Bridge] ensure_schema: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve nome físico da tabela no MySQL.
     */
    public static function mysql_table_name(string $logical): ?string
    {
        if (!in_array($logical, self::LOGICAL_TABLES, true)) {
            return null;
        }
        global $wpdb;
        if ($logical === 'salesforce_returns') {
            return 'salesforce_returns';
        }
        return $wpdb->prefix . $logical;
    }

    /**
     * Descobre coluna PRIMARY KEY no MySQL.
     */
    private static function mysql_primary_key(string $mysql_table): string
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_KEY = %s LIMIT 1',
                DB_NAME,
                $mysql_table,
                'PRI'
            ),
            ARRAY_A
        );
        if (!empty($row['COLUMN_NAME'])) {
            return (string) $row['COLUMN_NAME'];
        }
        return 'id';
    }

    /**
     * MERGE uma linha no espelho MSSQL.
     */
    public static function merge_mirror_row(string $logical, int $wp_row_id, string $json_payload): bool
    {
        if (!self::ensure_schema() || !in_array($logical, self::LOGICAL_TABLES, true)) {
            return false;
        }
        $pdo = PC_SqlServer_Connector::get_pdo_primary();
        if (!$pdo) {
            return false;
        }
        $t = self::MIRROR_TABLE;
        $sql = "
MERGE dbo.{$t} WITH (HOLDLOCK) AS target
USING (SELECT ? AS source_table, ? AS wp_row_id, ? AS payload_json) AS src
ON target.source_table = src.source_table AND target.wp_row_id = src.wp_row_id
WHEN MATCHED THEN
    UPDATE SET payload_json = src.payload_json, synced_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (source_table, wp_row_id, payload_json, synced_at)
    VALUES (src.source_table, src.wp_row_id, src.payload_json, SYSUTCDATETIME());
";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$logical, $wp_row_id, $json_payload]);
            return true;
        } catch (PDOException $e) {
            error_log('[PC_Wp_Mssql_Bridge] merge_mirror_row: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sincroniza tabela MySQL inteira para o espelho (em lotes).
     *
     * @param int $batch tamanho do lote SELECT
     * @param int $max_rows limite de segurança (0 = sem limite)
     */
    public static function sync_mysql_table_to_mirror(string $logical, int $batch = 500, int $max_rows = 0): int
    {
        $mysql_table = self::mysql_table_name($logical);
        if ($mysql_table === null) {
            return 0;
        }
        if (!self::ensure_schema()) {
            return 0;
        }
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $mysql_table
        ));
        if (empty($exists)) {
            return 0;
        }
        $pk = self::mysql_primary_key($mysql_table);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $pk)) {
            return 0;
        }
        $cols = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            DB_NAME,
            $mysql_table
        ), ARRAY_A);
        $col_names = array_column($cols ?: [], 'COLUMN_NAME');
        $col_names = array_values(array_filter($col_names, static function ($c) {
            return is_string($c) && preg_match('/^[a-zA-Z0-9_]+$/', $c);
        }));
        if ($col_names === []) {
            return 0;
        }
        $select_list = '`' . implode('`,`', $col_names) . '`';
        $last_id = 0;
        $synced = 0;
        while (true) {
            $q = "SELECT {$select_list} FROM `{$mysql_table}` WHERE `{$pk}` > %d ORDER BY `{$pk}` ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($q, $last_id, $batch), ARRAY_A);
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $rid = isset($row[$pk]) ? (int) $row[$pk] : 0;
                if ($rid <= 0) {
                    continue;
                }
                $json = wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json === false) {
                    continue;
                }
                if (self::merge_mirror_row($logical, $rid, $json)) {
                    $synced++;
                }
                $last_id = max($last_id, $rid);
                if ($max_rows > 0 && $synced >= $max_rows) {
                    break 2;
                }
            }
            if (count($rows) < $batch) {
                break;
            }
        }
        return $synced;
    }

    /**
     * Sincroniza todas as tabelas operacionais configuradas.
     */
    public static function sync_all_mirror_tables(): array
    {
        $out = [];
        foreach (self::LOGICAL_TABLES as $logical) {
            $out[$logical] = self::sync_mysql_table_to_mirror($logical, 500, 0);
        }
        update_option('pc_mssql_last_mirror_sync', current_time('mysql'));
        return $out;
    }

    /**
     * Recalcula PC_LINE_HEALTH_SNAPSHOT a partir do MySQL (envios + contagens auxiliares).
     */
    public static function rebuild_line_health_snapshot_from_mysql(): bool
    {
        if (!self::ensure_schema()) {
            return false;
        }
        global $wpdb;
        $env = $wpdb->prefix . 'envios_pendentes';
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $env
        ));
        if (empty($exists)) {
            return false;
        }

        $cols = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $env
        ), 0);
        $colset = array_fill_keys(array_map('strtolower', is_array($cols) ? $cols : []), true);
        if (empty($colset['fornecedor']) || empty($colset['status'])) {
            return false;
        }
        $idgis_expr = !empty($colset['idgis_ambiente'])
            ? 'COALESCE(CAST(idgis_ambiente AS CHAR), \'0\')'
            : '\'0\'';
        $nome_expr = !empty($colset['nome']) ? 'MAX(TRIM(COALESCE(nome, \'\')))' : '\'\'';
        $date_filter = !empty($colset['data_cadastro'])
            ? 'WHERE data_cadastro >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            : 'WHERE 1=1';
        $last_cad = !empty($colset['data_cadastro']) ? 'MAX(data_cadastro)' : 'NULL';

        $sql = "
SELECT
  COALESCE(NULLIF(TRIM(fornecedor), ''), '—') AS provedor,
  {$idgis_expr} AS idgis,
  {$nome_expr} AS nome_any,
  COUNT(*) AS total,
  SUM(CASE
    WHEN LOWER(COALESCE(status, '')) LIKE '%erro%'
      OR LOWER(COALESCE(status, '')) LIKE '%fail%'
      OR LOWER(COALESCE(status, '')) IN ('negado', 'denied', 'cancelled', 'cancelada')
    THEN 1 ELSE 0 END) AS bad_cnt,
  {$last_cad} AS last_cadastro
FROM `{$env}`
{$date_filter}
GROUP BY fornecedor" . (!empty($colset['idgis_ambiente']) ? ', idgis_ambiente' : '') . '
';
        $groups = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($groups)) {
            $groups = [];
        }

        $evento_stats = self::aggregate_event_table_counts();

        $pdo = PC_SqlServer_Connector::get_pdo_primary();
        if (!$pdo) {
            return false;
        }

        try {
            $pdo->exec('DELETE FROM dbo.' . self::SNAPSHOT_TABLE . ';');
        } catch (PDOException $e) {
            error_log('[PC_Wp_Mssql_Bridge] snapshot delete: ' . $e->getMessage());
            return false;
        }

        $ins = $pdo->prepare(
            'INSERT INTO dbo.' . self::SNAPSHOT_TABLE .
            ' (line_key, nome_linha, provedor, idgis_ambiente, saude_tier, metricas_json) VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($groups as $g) {
            $prov = (string) ($g['provedor'] ?? '');
            $idgis = (string) ($g['idgis'] ?? '');
            $line_key = $prov . '|' . $idgis;
            if (strlen($line_key) > 200) {
                $line_key = substr($line_key, 0, 200);
            }
            $total = max(0, (int) ($g['total'] ?? 0));
            $bad = max(0, (int) ($g['bad_cnt'] ?? 0));
            $rate = $total > 0 ? ($bad / $total) : 0.0;
            if ($total === 0) {
                $tier = 'SEM_DADOS';
            } elseif ($rate > 0.15) {
                $tier = 'RED';
            } elseif ($rate > 0.03) {
                $tier = 'YELLOW';
            } else {
                $tier = 'GREEN';
            }
            $nome = (string) ($g['nome_any'] ?? '');
            $metrics = [
                'window_days' => 30,
                'envios_total' => $total,
                'envios_bad_like' => $bad,
                'bad_rate' => round($rate, 4),
                'last_data_cadastro' => $g['last_cadastro'] ?? null,
                'event_tables' => $evento_stats,
            ];
            $mj = wp_json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($mj === false) {
                $mj = '{}';
            }
            try {
                $ins->execute([$line_key, $nome, $prov, $idgis, $tier, $mj]);
            } catch (PDOException $e) {
                error_log('[PC_Wp_Mssql_Bridge] snapshot insert: ' . $e->getMessage());
            }
        }

        update_option('pc_mssql_last_snapshot_refresh', current_time('mysql'));
        return true;
    }

    /**
     * Contagens recentes das tabelas de eventos (MySQL), para enriquecer metricas_json.
     */
    private static function aggregate_event_table_counts(): array
    {
        global $wpdb;
        $out = [];
        foreach (['eventos_envios', 'eventos_indicadores', 'eventos_tempos'] as $logical) {
            $t = self::mysql_table_name($logical);
            if ($t === null) {
                continue;
            }
            $exists = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $t
            ));
            if (empty($exists)) {
                $out[$logical] = ['rows_7d' => null, 'note' => 'tabela ausente'];
                continue;
            }
            $date_col = self::guess_date_column($t);
            if ($date_col === null) {
                $cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`");
                $out[$logical] = ['rows_total' => $cnt, 'note' => 'sem coluna de data inferida'];
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $date_col)) {
                $out[$logical] = ['rows_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`")];
                continue;
            }
            $cnt = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$t}` WHERE `{$date_col}` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $out[$logical] = ['rows_7d' => $cnt, 'date_column' => $date_col];
        }
        $sf = self::mysql_table_name('salesforce_returns');
        if ($sf !== null) {
            $exists = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $sf
            ));
            if (!empty($exists)) {
                $dc = self::guess_date_column($sf);
                if ($dc !== null && preg_match('/^[a-zA-Z0-9_]+$/', $dc)) {
                    $out['salesforce_returns'] = [
                        'rows_7d' => (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM `{$sf}` WHERE `{$dc}` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                        ),
                        'date_column' => $dc,
                    ];
                } else {
                    $out['salesforce_returns'] = [
                        'rows_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$sf}`"),
                    ];
                }
            }
        }
        return $out;
    }

    private static function guess_date_column(string $mysql_table): ?string
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $mysql_table
        ), ARRAY_A);
        if (empty($rows)) {
            return null;
        }
        $prefer = ['data_evento', 'created_at', 'data_cadastro', 'eventdate', 'updated_at', 'data'];
        foreach ($prefer as $p) {
            foreach ($rows as $r) {
                if (strtolower((string) ($r['COLUMN_NAME'] ?? '')) === $p) {
                    return (string) $r['COLUMN_NAME'];
                }
            }
        }
        foreach ($rows as $r) {
            $dt = strtolower((string) ($r['DATA_TYPE'] ?? ''));
            if (strpos($dt, 'date') !== false || strpos($dt, 'time') !== false) {
                return (string) $r['COLUMN_NAME'];
            }
        }
        return null;
    }

    /**
     * Chamado ao abrir telas de saúde: só recalcula snapshot (rápido). Espelho completo fica no cron.
     */
    public static function on_operational_health_page_visit(): void
    {
        if (!self::is_available()) {
            return;
        }
        self::rebuild_line_health_snapshot_from_mysql();
    }

    /**
     * Cron diário ou sincronização manual: garante schema, espelho completo + snapshot.
     *
     * @return array{ok: bool, reason?: string, schema_ok?: bool, mirror_row_counts_by_table?: array<string,int>, snapshot_ok?: bool, last_mirror_sync?: string, last_snapshot_refresh?: string}
     */
    public static function run_daily_operational_job(): array
    {
        if (!self::is_available()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if (!self::ensure_schema()) {
            return ['ok' => false, 'reason' => 'schema_or_connection'];
        }
        $mirror = self::sync_all_mirror_tables();
        $snap = self::rebuild_line_health_snapshot_from_mysql();
        return [
            'ok' => true,
            'schema_ok' => true,
            'mirror_row_counts_by_table' => $mirror,
            'snapshot_ok' => $snap,
            'last_mirror_sync' => (string) get_option('pc_mssql_last_mirror_sync', ''),
            'last_snapshot_refresh' => (string) get_option('pc_mssql_last_snapshot_refresh', ''),
        ];
    }

    /**
     * Linhas para API (compatível com OperationsHealth / getLineHealth).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetch_snapshot_rows_for_api(int $max = 200): array
    {
        if (!self::ensure_schema()) {
            return [];
        }
        $pdo = PC_SqlServer_Connector::get_pdo_primary();
        if (!$pdo) {
            return [];
        }
        $max = min(max($max, 1), 500);
        $t = self::SNAPSHOT_TABLE;
        $sql = "SELECT TOP ({$max}) line_key, nome_linha, provedor, idgis_ambiente, saude_tier, metricas_json, updated_at FROM dbo.{$t} ORDER BY updated_at DESC";
        try {
            $stmt = $pdo->query($sql);
            $raw = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            error_log('[PC_Wp_Mssql_Bridge] fetch_snapshot_rows_for_api: ' . $e->getMessage());
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($raw as $row) {
            $lk = (string) ($row['line_key'] ?? '');
            $out[] = [
                'id' => (string) (++$i),
                'id_linha' => $lk,
                'nome_linha' => (string) ($row['nome_linha'] ?? ''),
                'provedor' => (string) ($row['provedor'] ?? ''),
                'status_qualidade' => (string) ($row['saude_tier'] ?? ''),
                'detalhes_retorno' => (string) ($row['metricas_json'] ?? ''),
            ];
        }
        return $out;
    }
}
