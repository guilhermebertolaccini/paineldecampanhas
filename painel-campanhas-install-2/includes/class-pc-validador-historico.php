<?php
/**
 * Histórico do Validador WhatsApp — persistência em disco, tabela MySQL e retenção 15 dias.
 *
 * @package Painel_Campanhas
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PC_Validador_Historico
{
    public const CRON_HOOK = 'pc_limpar_arquivos_validador_antigos';
    public const RETENTION_DAYS = 15;

    /**
     * Diretório base: wp-content/uploads/pc_validador/
     */
    public static function base_dir(): string
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return '';
        }

        return trailingslashit($upload['basedir']) . 'pc_validador';
    }

    public static function ensure_dirs(): bool
    {
        $base = self::base_dir();
        if ($base === '') {
            return false;
        }
        $ok = wp_mkdir_p($base . '/jobs')
            && wp_mkdir_p($base . '/originais')
            && wp_mkdir_p($base . '/validados');
        if ($ok) {
            foreach (['/index.html', '/jobs/index.html', '/originais/index.html', '/validados/index.html'] as $rel) {
                $f = $base . $rel;
                if (!is_file($f)) {
                    @file_put_contents($f, '');
                }
            }
        }

        return $ok;
    }

    /**
     * @return string
     */
    public static function historico_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'pc_validador_historico';
    }

    public static function ensure_table(): void
    {
        global $wpdb;

        $table = self::historico_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            job_id varchar(64) NOT NULL DEFAULT '',
            nome_arquivo varchar(255) NOT NULL DEFAULT '',
            caminho_arquivo_original varchar(512) NOT NULL DEFAULT '',
            caminho_arquivo_validado varchar(512) NOT NULL DEFAULT '',
            total_linhas int(10) unsigned NOT NULL DEFAULT 0,
            linhas_validas int(10) unsigned NOT NULL DEFAULT 0,
            linhas_invalidas int(10) unsigned NOT NULL DEFAULT 0,
            data_criacao datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_pc_val_hist_user_data (user_id, data_criacao),
            KEY idx_pc_val_hist_data (data_criacao),
            KEY idx_pc_val_hist_job (job_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function maybe_schedule_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function cron_limpar_antigos(): void
    {
        global $wpdb;

        self::ensure_table();

        $table = self::historico_table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, caminho_arquivo_original, caminho_arquivo_validado FROM `{$table}` WHERE data_criacao < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                self::RETENTION_DAYS
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orig = (string) ($row['caminho_arquivo_original'] ?? '');
            $val = (string) ($row['caminho_arquivo_validado'] ?? '');
            if ($orig !== '' && is_file($orig) && self::path_is_under_base($orig)) {
                @unlink($orig);
            }
            if ($val !== '' && is_file($val) && self::path_is_under_base($val)) {
                @unlink($val);
            }
            $wpdb->delete($table, ['id' => (int) ($row['id'] ?? 0)], ['%d']);
        }
    }

    /**
     * @param string $path
     */
    private static function path_is_under_base($path): bool
    {
        $base = self::base_dir();
        if ($base === '' || $path === '') {
            return false;
        }
        $real_base = realpath($base);
        $real_file = realpath($path);
        if ($real_base === false || $real_file === false) {
            return strpos(wp_normalize_path($path), wp_normalize_path($base)) === 0;
        }

        return strpos(wp_normalize_path($real_file), wp_normalize_path($real_base)) === 0;
    }

    /**
     * Copia o upload original para originais/ e grava caminhos no state (via referência).
     *
     * @param string $tmp_upload
     * @param string $client_name
     * @param string $job_id
     * @param array<string, mixed> $state
     */
    public static function store_original_upload($tmp_upload, $client_name, $job_id, array &$state): void
    {
        if (!is_uploaded_file($tmp_upload) && !is_readable($tmp_upload)) {
            return;
        }
        if (!self::ensure_dirs()) {
            return;
        }
        $base = self::base_dir();
        $safe = sanitize_file_name($client_name);
        if ($safe === '' || strtolower(pathinfo($safe, PATHINFO_EXTENSION)) !== 'csv') {
            $safe = 'upload.csv';
        }
        $dir = $base . '/originais';
        $filename = wp_unique_filename($dir, $job_id . '_' . $safe);
        $dest = $dir . '/' . $filename;
        if (@copy($tmp_upload, $dest)) {
            $state['original_client_name'] = $client_name;
            $state['original_stored_path'] = $dest;
        }
    }

    /**
     * @param string $path
     * @return array{total:int, validas:int, invalidas:int}
     */
    public static function count_result_csv($path): array
    {
        $out = ['total' => 0, 'validas' => 0, 'invalidas' => 0];
        if (!is_readable($path)) {
            return $out;
        }
        $h = fopen($path, 'rb');
        if (!$h) {
            return $out;
        }
        $first = true;
        while (($row = fgetcsv($h)) !== false) {
            if ($first) {
                $first = false;
                continue;
            }
            if (!isset($row[1])) {
                continue;
            }
            $out['total']++;
            $w = strtolower(trim((string) $row[1]));
            if ($w === 'verdadeiro') {
                $out['validas']++;
            } elseif ($w === 'falso') {
                $out['invalidas']++;
            }
        }
        fclose($h);

        return $out;
    }

    /**
     * Ao concluir o job: copia result.csv para validados/, INSERT no histórico (uma vez).
     *
     * @param string $job_id
     * @param array<string, mixed> $state
     * @param string $result_csv
     */
    public static function record_completed_job($job_id, array &$state, $result_csv): void
    {
        if (!empty($state['historico_gravado'])) {
            return;
        }
        if (!is_readable($result_csv)) {
            return;
        }

        self::ensure_table();
        self::ensure_dirs();

        $uid = (int) ($state['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $base = self::base_dir();
        if ($base === '') {
            return;
        }

        $nome = (string) ($state['original_client_name'] ?? 'upload.csv');
        $orig_path = (string) ($state['original_stored_path'] ?? '');
        if ($orig_path === '' || !is_file($orig_path)) {
            $orig_path = '';
        }

        $val_dir = $base . '/validados';
        $val_name = wp_unique_filename($val_dir, $job_id . '_validado.csv');
        $val_path = $val_dir . '/' . $val_name;
        if (!@copy($result_csv, $val_path)) {
            error_log('[PC Validador Historico] Falha ao copiar CSV validado para histórico.');
            return;
        }

        $stats = self::count_result_csv($val_path);

        global $wpdb;
        $table = self::historico_table_name();
        $wpdb->insert(
            $table,
            [
                'user_id' => $uid,
                'job_id' => substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $job_id), 0, 64),
                'nome_arquivo' => substr($nome, 0, 255),
                'caminho_arquivo_original' => substr($orig_path, 0, 512),
                'caminho_arquivo_validado' => substr($val_path, 0, 512),
                'total_linhas' => (int) $stats['total'],
                'linhas_validas' => (int) $stats['validas'],
                'linhas_invalidas' => (int) $stats['invalidas'],
                'data_criacao' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']
        );

        if ($wpdb->last_error) {
            error_log('[PC Validador Historico] INSERT falhou: ' . $wpdb->last_error);
            @unlink($val_path);

            return;
        }

        $state['historico_gravado'] = 1;
    }

    /**
     * GET /wp-json/validador/v1/historico
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_historico($request)
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return new WP_Error('pc_val_hist_forbidden', 'Acesso negado.', ['status' => 403]);
        }

        self::ensure_table();

        global $wpdb;
        $table = self::historico_table_name();
        $uid = get_current_user_id();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, nome_arquivo, total_linhas, linhas_validas, linhas_invalidas, data_criacao, job_id
                 FROM `{$table}`
                 WHERE user_id = %d
                   AND data_criacao >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                 ORDER BY data_criacao DESC
                 LIMIT 100",
                $uid,
                self::RETENTION_DAYS
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            $rows = [];
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'nome_arquivo' => (string) ($r['nome_arquivo'] ?? ''),
                'total_linhas' => (int) ($r['total_linhas'] ?? 0),
                'linhas_validas' => (int) ($r['linhas_validas'] ?? 0),
                'linhas_invalidas' => (int) ($r['linhas_invalidas'] ?? 0),
                'data_criacao' => (string) ($r['data_criacao'] ?? ''),
                'job_id' => (string) ($r['job_id'] ?? ''),
                'download_original_nonce' => wp_create_nonce('pc_wa_validator_hist_' . $id . '_orig'),
                'download_validado_nonce' => wp_create_nonce('pc_wa_validator_hist_' . $id . '_val'),
            ];
        }

        return new WP_REST_Response(['itens' => $out], 200);
    }

    /**
     * admin-post: download original ou validado do histórico.
     *
     * @param string $kind orig|val
     */
    public static function handle_download_historico($kind)
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            wp_die('Acesso negado', '', ['response' => 403]);
        }

        $id = isset($_GET['historico_id']) ? (int) $_GET['historico_id'] : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ($id <= 0) {
            wp_die('ID inválido.', '', ['response' => 400]);
        }

        $nonce_action = $kind === 'orig' ? 'pc_wa_validator_hist_' . $id . '_orig' : 'pc_wa_validator_hist_' . $id . '_val';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_die('Link inválido ou expirado.', '', ['response' => 403]);
        }

        self::ensure_table();
        global $wpdb;
        $table = self::historico_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d AND user_id = %d", $id, get_current_user_id()),
            ARRAY_A
        );

        if (!is_array($row)) {
            wp_die('Registro não encontrado.', '', ['response' => 404]);
        }

        $path = $kind === 'orig'
            ? (string) ($row['caminho_arquivo_original'] ?? '')
            : (string) ($row['caminho_arquivo_validado'] ?? '');

        if ($path === '' || !is_readable($path) || !self::path_is_under_base($path)) {
            wp_die('Arquivo não disponível.', '', ['response' => 404]);
        }

        $nome = sanitize_file_name((string) ($row['nome_arquivo'] ?? 'arquivo.csv'));
        if ($kind === 'val') {
            $nome = preg_replace('/\.csv$/i', '', $nome) . '-validado.csv';
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        readfile($path);
        exit;
    }
}
