<?php
/**
 * Ponte PDO (sqlsrv) → SQL Server DB_DIGITAL (.26) e views VW_BASE* (.26 local, .27 via linked server ou segundo host).
 *
 * Credenciais: defina em wp-config.php (recomendado) ou em opções `pc_mssql_*` na base WordPress.
 * Nunca commite senhas no repositório.
 *
 * Exemplo wp-config:
 *   define('PC_MSSQL_ENABLED', true);
 *   define('PC_MSSQL_HOST', '10.103.2.26');
 *   define('PC_MSSQL_PORT', '1433');
 *   define('PC_MSSQL_DATABASE', 'DB_DIGITAL');
 *   define('PC_MSSQL_USER', 'user_digital');
 *   define('PC_MSSQL_PASSWORD', getenv('PC_MSSQL_PASSWORD')); // ou string local fora do git
 *
 * Views no .27 via linked server (catálogo INFORMATION_SCHEMA no remoto, a partir do .26):
 *   define('PC_MSSQL_VIEWS_INFO_SCHEMA_CATALOG', '[SRV27].[DB_DIGITAL]');
 *   (alias aceito: PC_MSSQL_VIEWS_INFO_SCHEMA_PREFIX)
 *
 * Alternativa: conexão direta ao host das views:
 *   define('PC_MSSQL_VIEWS_HOST', '10.103.2.27');
 *
 * Leitura de dados com four-part name (FROM):
 *   define('PC_MSSQL_LINKED_FOUR_PART_PREFIX', '[SRV27].[DB_DIGITAL].[dbo]');
 *
 * Requisito PHP: extensão pdo_sqlsrv (ambiente Windows típico). pdo_dblib não é usado aqui.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PC_SqlServer_Connector
{
    /** @var PDO|null */
    private static $pdo_primary = null;

    /** @var PDO|null */
    private static $pdo_views = null;

    /**
     * Libera pools estáticos após alterar credenciais nas opções (mesmo worker PHP).
     */
    public static function reset_static_connections(): void
    {
        self::$pdo_primary = null;
        self::$pdo_views = null;
    }

    public static function is_enabled(): bool
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            return false;
        }
        if (defined('PC_MSSQL_ENABLED') && !PC_MSSQL_ENABLED) {
            return false;
        }
        $opt = get_option('pc_mssql_enabled', '');
        if ($opt === '0' || $opt === 'false') {
            return false;
        }
        if (self::host_primary() === '' || self::database() === '' || self::user() === '') {
            return false;
        }
        if (defined('PC_MSSQL_ENABLED')) {
            return (bool) PC_MSSQL_ENABLED;
        }
        return $opt === '1' || $opt === 1 || $opt === true;
    }

    private static function host_primary(): string
    {
        if (defined('PC_MSSQL_HOST')) {
            return (string) PC_MSSQL_HOST;
        }
        return (string) get_option('pc_mssql_host', '');
    }

    private static function host_views(): string
    {
        if (defined('PC_MSSQL_VIEWS_HOST')) {
            return (string) PC_MSSQL_VIEWS_HOST;
        }
        $h = (string) get_option('pc_mssql_views_host', '');
        return $h !== '' ? $h : self::host_primary();
    }

    private static function port(): string
    {
        if (defined('PC_MSSQL_PORT')) {
            return (string) PC_MSSQL_PORT;
        }
        $p = (string) get_option('pc_mssql_port', '1433');
        return $p !== '' ? $p : '1433';
    }

    private static function database(): string
    {
        if (defined('PC_MSSQL_DATABASE')) {
            return (string) PC_MSSQL_DATABASE;
        }
        return (string) get_option('pc_mssql_database', '');
    }

    private static function user(): string
    {
        if (defined('PC_MSSQL_USER')) {
            return (string) PC_MSSQL_USER;
        }
        return (string) get_option('pc_mssql_user', '');
    }

    private static function password(): string
    {
        if (defined('PC_MSSQL_PASSWORD')) {
            return (string) PC_MSSQL_PASSWORD;
        }
        return (string) get_option('pc_mssql_password', '');
    }

    private static function linked_four_part_prefix(): string
    {
        if (defined('PC_MSSQL_LINKED_FOUR_PART_PREFIX')) {
            return (string) PC_MSSQL_LINKED_FOUR_PART_PREFIX;
        }
        return (string) get_option('pc_mssql_linked_four_part_prefix', '');
    }

    /**
     * Prefixo de catálogo para INFORMATION_SCHEMA.VIEWS no servidor remoto (ex.: [SRV27].[DB_DIGITAL]).
     * Vazio = catálogo da conexão atual (DB_DIGITAL no .26).
     */
    private static function views_info_schema_catalog(): string
    {
        if (defined('PC_MSSQL_VIEWS_INFO_SCHEMA_CATALOG')) {
            return trim((string) PC_MSSQL_VIEWS_INFO_SCHEMA_CATALOG);
        }
        if (defined('PC_MSSQL_VIEWS_INFO_SCHEMA_PREFIX')) {
            return trim((string) PC_MSSQL_VIEWS_INFO_SCHEMA_PREFIX);
        }
        $opt = trim((string) get_option('pc_mssql_views_info_schema_catalog', ''));
        if ($opt !== '') {
            return $opt;
        }
        return trim((string) get_option('pc_mssql_views_info_schema_prefix', ''));
    }

    /**
     * Evita injeção em fragmento de catálogo four-part.
     */
    private static function is_safe_catalog_prefix(string $prefix): bool
    {
        return (bool) preg_match('/^(\[[A-Za-z0-9_]+\])(\.\[[A-Za-z0-9_]+\])+$/', trim($prefix));
    }

    /**
     * @return PDO|null
     */
    public static function get_pdo_primary()
    {
        if (!self::is_enabled() || !extension_loaded('pdo_sqlsrv')) {
            return null;
        }
        if (self::$pdo_primary instanceof PDO) {
            return self::$pdo_primary;
        }
        $dsn = self::build_dsn(self::host_primary(), self::port(), self::database());
        try {
            self::$pdo_primary = new PDO($dsn, self::user(), self::password(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            return self::$pdo_primary;
        } catch (PDOException $e) {
            error_log('[PC_SqlServer_Connector] primary: ' . $e->getMessage());
            self::$pdo_primary = null;
            return null;
        }
    }

    /**
     * PDO para metadados/dados de views (linked server → mesmo PDO .26; host diferente → nova conexão).
     *
     * @return PDO|null
     */
    public static function get_pdo_views()
    {
        if (!self::is_enabled() || !extension_loaded('pdo_sqlsrv')) {
            return null;
        }
        $prefix = trim(self::linked_four_part_prefix());
        if ($prefix !== '') {
            return self::get_pdo_primary();
        }
        if (self::host_views() === self::host_primary()) {
            return self::get_pdo_primary();
        }
        if (self::$pdo_views instanceof PDO) {
            return self::$pdo_views;
        }
        $dsn = self::build_dsn(self::host_views(), self::port(), self::database());
        try {
            self::$pdo_views = new PDO($dsn, self::user(), self::password(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            return self::$pdo_views;
        } catch (PDOException $e) {
            error_log('[PC_SqlServer_Connector] views: ' . $e->getMessage());
            self::$pdo_views = null;
            return null;
        }
    }

    private static function build_dsn(string $host, string $port, string $db): string
    {
        return 'sqlsrv:Server=tcp:' . $host . ',' . $port . ';Database=' . $db . ';Encrypt=1;TrustServerCertificate=1';
    }

    public static function is_safe_sql_identifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    /**
     * Lista views VW_BASE% — mesma intenção do contrato operacional:
     * SELECT TABLE_NAME FROM … INFORMATION_SCHEMA.VIEWS WHERE TABLE_NAME LIKE 'VW_BASE%'
     *
     * @return string[]
     */
    public static function list_vw_base_view_names()
    {
        $pdo = self::get_pdo_views();
        if (!$pdo) {
            return [];
        }
        $cat = self::views_info_schema_catalog();
        if ($cat !== '' && !self::is_safe_catalog_prefix($cat)) {
            error_log('[PC_SqlServer_Connector] prefixo de catálogo inválido para INFORMATION_SCHEMA (rejeitado).');
            return [];
        }
        $from = ($cat !== '') ? ($cat . '.INFORMATION_SCHEMA.VIEWS') : 'INFORMATION_SCHEMA.VIEWS';
        $sql = 'SELECT TABLE_NAME FROM ' . $from . " WHERE TABLE_NAME LIKE N'VW_BASE%' ORDER BY TABLE_NAME";
        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $out = [];
            foreach ($rows as $row) {
                $tn = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
                if (self::is_safe_sql_identifier($tn)) {
                    $out[] = $tn;
                }
            }
            return $out;
        } catch (PDOException $e) {
            error_log('[PC_SqlServer_Connector] list_vw_base_view_names: ' . $e->getMessage());
            return [];
        }
    }

    private static function view_from_clause(string $view_name): string
    {
        $prefix = trim(self::linked_four_part_prefix());
        if ($prefix !== '') {
            return $prefix . '.' . self::bracket_quote($view_name);
        }
        return 'dbo.' . self::bracket_quote($view_name);
    }

    private static function bracket_quote(string $ident): string
    {
        return '[' . str_replace(']', ']]', $ident) . ']';
    }

    /**
     * @param string $view_name
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    public static function fetch_external_view_data($view_name, $limit = 500)
    {
        if (!self::is_safe_sql_identifier($view_name)) {
            return [];
        }
        $limit = min(max((int) $limit, 1), 5000);
        $pdo = self::get_pdo_views();
        if (!$pdo) {
            return [];
        }
        $from = self::view_from_clause($view_name);
        $sql = 'SELECT TOP (' . $limit . ') * FROM ' . $from;
        try {
            $stmt = $pdo->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            error_log('[PC_SqlServer_Connector] fetch_external_view_data: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fetch_line_health_rows($max = 200)
    {
        $pdo = self::get_pdo_primary();
        if (!$pdo) {
            return [];
        }
        $max = min(max((int) $max, 1), 500);
        $sql = 'SELECT TOP (' . $max . ') id, id_linha, nome_linha, provedor, status_qualidade, detalhes_retorno FROM dbo.TB_SAUDE_LINHAS ORDER BY id DESC';
        try {
            $stmt = $pdo->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            error_log('[PC_SqlServer_Connector] fetch_line_health_rows: ' . $e->getMessage());
            return [];
        }
    }
}
