<?php
/**
 * Validador WhatsApp via Evolution API — armazenamento seguro, filas e fallback entre instâncias.
 *
 * @package Painel_Campanhas
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PC_Evolution_WA_Validator
{
    private const OPTION_KEY = 'acm_evolution_api';
    private const BATCH_SIZE = 18;
    private const THROTTLE_US = 180000;
    private const STEP_BUDGET_SEC = 22;
    private const MAX_FAIL_STREAK = 12;

    /** @return string */
    private static function crypto_key_binary()
    {
        $src = defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : wp_salt('auth'));
        return substr(hash('sha256', 'pc_evolution_wa_v1|' . $src, true), 0, 32);
    }

    /**
     * @param string $plain
     * @return string base64 payload
     */
    public static function encrypt_token($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return base64_encode('plain:' . $plain);
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::crypto_key_binary(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            return base64_encode('plain:' . $plain);
        }
        return base64_encode($iv . $tag . $ct);
    }

    /**
     * @param string $stored
     * @return string
     */
    public static function decrypt_token($stored)
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }
        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return '';
        }
        if (strpos($raw, 'plain:') === 0) {
            return substr($raw, 6);
        }
        if (!function_exists('openssl_decrypt') || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::crypto_key_binary(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt !== false ? $pt : '';
    }

    /**
     * @return array{api_url: string, token: string}
     */
    public static function get_config_decrypted()
    {
        if (defined('PC_EVOLUTION_API_URL') && PC_EVOLUTION_API_URL !== '') {
            $u = esc_url_raw((string) PC_EVOLUTION_API_URL);
            $tok = defined('PC_EVOLUTION_API_TOKEN') ? (string) PC_EVOLUTION_API_TOKEN : '';
            if ($tok === '' && defined('PC_EVOLUTION_API_TOKEN_FILE') && is_readable(PC_EVOLUTION_API_TOKEN_FILE)) {
                $tok = trim((string) file_get_contents(PC_EVOLUTION_API_TOKEN_FILE));
            }
            return [
                'api_url' => $u ? rtrim($u, '/') : '',
                'token' => $tok,
            ];
        }

        $opt = get_option(self::OPTION_KEY, []);
        if (!is_array($opt)) {
            return ['api_url' => '', 'token' => ''];
        }
        $url = isset($opt['api_url']) ? esc_url_raw($opt['api_url']) : '';
        $enc = isset($opt['token_enc']) ? (string) $opt['token_enc'] : '';
        return [
            'api_url' => $url ? rtrim($url, '/') : '',
            'token' => self::decrypt_token($enc),
        ];
    }

    /**
     * Aceita vários formatos da Evolution (v2 doc, Manager API, etc.).
     *
     * @param mixed $data json_decode raiz
     * @return array<int, mixed>
     */
    private static function normalize_fetch_instances_list($data)
    {
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['response']) && is_array($data['response'])) {
            $inner = $data['response'];
            if (isset($inner[0])) {
                return $inner;
            }
            if (isset($inner['instance']) && is_array($inner['instance'])) {
                return [$inner];
            }
            if (isset($inner['name']) || isset($inner['instanceName'])) {
                return [$inner];
            }
            foreach ($inner as $v) {
                if (is_array($v)) {
                    return array_values($inner);
                }
            }
            return [];
        }

        if (isset($data['data']) && is_array($data['data']) && isset($data['data'][0])) {
            return $data['data'];
        }

        if (isset($data['instances']) && is_array($data['instances'])) {
            return array_values($data['instances']);
        }

        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        if (isset($data['name']) || isset($data['instanceName'])) {
            return [$data];
        }

        return [];
    }

    /**
     * @param string $base_url
     * @param string $token
     * @return array<int, array{name: string, status: string}>
     */
    public static function fetch_connected_instances($base_url, $token)
    {
        $base_url = rtrim($base_url, '/');
        $url = $base_url . '/instance/fetchInstances';
        $response = wp_remote_get($url, [
            'headers' => [
                'apikey' => $token,
                'Accept' => 'application/json',
            ],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            error_log('[PC Evolution Validator] fetchInstances WP_Error: ' . $response->get_error_message());
            return [];
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            error_log('[PC Evolution Validator] fetchInstances HTTP ' . $code . ' body: ' . substr($body, 0, 500));
            return [];
        }

        $data = json_decode($body, true);
        $list = self::normalize_fetch_instances_list($data);

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $inst = $row;
            if (isset($row['instance']) && is_array($row['instance'])) {
                $inst = $row['instance'];
            }

            $name = '';
            if (!empty($inst['instanceName'])) {
                $name = (string) $inst['instanceName'];
            } elseif (!empty($inst['name'])) {
                $name = (string) $inst['name'];
            }

            $status = '';
            foreach (['status', 'connectionStatus', 'state'] as $sk) {
                if (isset($inst[$sk]) && (string) $inst[$sk] !== '') {
                    $status = strtolower((string) $inst[$sk]);
                    break;
                }
            }

            if ($name === '') {
                continue;
            }
            if ($status !== 'open') {
                continue;
            }
            $out[] = ['name' => $name, 'status' => $status];
        }

        return $out;
    }

    /**
     * @param string $base_url
     * @param string $token
     * @param string $instance_name
     * @param string[] $numbers E.164 digits only
     * @return array<string, bool>|WP_Error map normalized number => exists
     */
    public static function post_whatsapp_numbers($base_url, $token, $instance_name, array $numbers)
    {
        $base_url = rtrim($base_url, '/');
        $url = $base_url . '/chat/whatsappNumbers/' . rawurlencode($instance_name);
        $response = wp_remote_post($url, [
            'headers' => [
                'apikey' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode(['numbers' => array_values($numbers)]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            error_log('[PC Evolution Validator] whatsappNumbers WP_Error: ' . $response->get_error_message());
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            error_log('[PC Evolution Validator] whatsappNumbers HTTP ' . $code . ' inst=' . $instance_name);
            return new WP_Error('evo_http', 'Evolution API retornou HTTP ' . $code, ['body' => substr($body, 0, 300)]);
        }

        $decoded = json_decode($body, true);
        if (isset($decoded['response']) && is_array($decoded['response'])) {
            $decoded = $decoded['response'];
        }
        if (!is_array($decoded)) {
            return new WP_Error('evo_parse', 'Resposta JSON inválida da Evolution API');
        }

        $map = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $num = isset($item['number']) ? preg_replace('/\D+/', '', (string) $item['number']) : '';
            if ($num === '') {
                continue;
            }
            $map[$num] = !empty($item['exists']);
        }

        return $map;
    }

    /**
     * @param string $raw
     * @return string digits, BR default 55
     */
    public static function normalize_phone_br($raw)
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return '';
        }
        if (strlen($d) >= 10 && strlen($d) <= 11 && $d[0] !== '0') {
            $d = '55' . $d;
        }
        return $d;
    }

    /**
     * @param string $tmp_upload php upload tmp path
     * @param int $user_id
     * @param string $original_client_name nome exibido / histórico
     * @return string|WP_Error job id
     */
    public static function create_job_from_upload($tmp_upload, $user_id, $original_client_name = '')
    {
        if (!is_uploaded_file($tmp_upload)) {
            return new WP_Error('upload', 'Arquivo de upload inválido.');
        }

        $job_id = wp_generate_uuid4();
        $dir = self::job_dir($job_id);
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('dir', 'Não foi possível criar diretório do job.');
        }

        $pairs_path = $dir . '/pairs.csv';
        $pf = fopen($pairs_path, 'wb');
        if (!$pf) {
            return new WP_Error('io', 'Não foi possível gravar pairs.csv');
        }

        $in = fopen($tmp_upload, 'rb');
        if (!$in) {
            fclose($pf);
            return new WP_Error('io', 'Não foi possível ler o CSV');
        }

        $header = fgetcsv($in);
        if ($header === false) {
            fclose($in);
            fclose($pf);
            return new WP_Error('csv', 'CSV vazio ou ilegível.');
        }
        $col_index = -1;
        foreach ($header as $i => $h) {
            if (strtoupper(trim((string) $h)) === 'TELEFONE') {
                $col_index = (int) $i;
                break;
            }
        }
        if ($col_index < 0) {
            fclose($in);
            fclose($pf);
            return new WP_Error('csv', 'Cabeçalho obrigatório: TELEFONE.');
        }

        $count = 0;
        while (($row = fgetcsv($in)) !== false) {
            if (!isset($row[$col_index])) {
                continue;
            }
            $original = trim((string) $row[$col_index]);
            if ($original === '') {
                continue;
            }
            $norm = self::normalize_phone_br($original);
            if ($norm === '') {
                continue;
            }
            fputcsv($pf, [$original, $norm]);
            $count++;
        }
        fclose($in);
        fclose($pf);

        if ($count === 0) {
            self::delete_job_dir($job_id);
            return new WP_Error('csv', 'Nenhum telefone válido encontrado.');
        }

        $state = [
            'user_id' => (int) $user_id,
            'created' => time(),
            'phase' => 'init',
            'total_pairs' => $count,
            'processed_pairs' => 0,
            'instances' => [],
            'last_success_instance' => null,
            'last_error' => '',
        ];
        $client_name = $original_client_name !== '' ? $original_client_name : 'upload.csv';
        if (class_exists('PC_Validador_Historico')) {
            PC_Validador_Historico::store_original_upload($tmp_upload, $client_name, $job_id, $state);
        }
        file_put_contents($dir . '/state.json', wp_json_encode($state));

        return $job_id;
    }

    /**
     * @param string $job_id
     * @return string
     */
    public static function job_dir($job_id)
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return '';
        }
        $safe = preg_replace('/[^a-zA-Z0-9\-]/', '', $job_id);

        return trailingslashit($upload['basedir']) . 'pc_validador/jobs/' . $safe;
    }

    /**
     * @param string $job_id
     */
    public static function delete_job_dir($job_id)
    {
        $dir = self::job_dir($job_id);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param string $job_id
     * @param int $user_id
     * @return array<string, mixed>
     */
    public static function process_tick($job_id, $user_id)
    {
        @set_time_limit(120);
        $dir = self::job_dir($job_id);
        if ($dir === '' || !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Job não encontrado.'];
        }

        $state_path = $dir . '/state.json';
        $state = json_decode((string) file_get_contents($state_path), true);
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== (int) $user_id) {
            return ['ok' => false, 'message' => 'Acesso negado a este job.'];
        }

        if (($state['phase'] ?? '') === 'done') {
            return [
                'ok' => true,
                'done' => true,
                'progress' => 100,
                'processed' => (int) ($state['processed_pairs'] ?? 0),
                'total' => (int) ($state['total_pairs'] ?? 0),
            ];
        }

        if (($state['phase'] ?? '') === 'error') {
            return ['ok' => false, 'message' => (string) ($state['last_error'] ?? 'Erro desconhecido')];
        }

        $cfg = self::get_config_decrypted();
        if ($cfg['api_url'] === '' || $cfg['token'] === '') {
            $state['phase'] = 'error';
            $state['last_error'] = 'Evolution API não configurada (URL/token).';
            file_put_contents($state_path, wp_json_encode($state));
            return ['ok' => false, 'message' => $state['last_error']];
        }

        $deadline = microtime(true) + self::STEP_BUDGET_SEC;
        $pairs_csv = $dir . '/pairs.csv';
        $pending = $dir . '/pending';
        $result_csv = $dir . '/result.csv';

        if (($state['phase'] ?? '') === 'init') {
            $instances = self::fetch_connected_instances($cfg['api_url'], $cfg['token']);
            if (empty($instances)) {
                $state['phase'] = 'error';
                $state['last_error'] = 'Nenhuma instância Evolution com status conectado (open). Verifique suas sessões.';
                file_put_contents($state_path, wp_json_encode($state));
                error_log('[PC Evolution Validator] Abort: no connected instances');
                return ['ok' => false, 'message' => $state['last_error']];
            }
            $state['instances'] = $instances;
            wp_mkdir_p($pending);

            $names = array_column($instances, 'name');
            $k = count($names);
            $fh = fopen($pairs_csv, 'rb');
            if (!$fh) {
                $state['phase'] = 'error';
                $state['last_error'] = 'Falha ao abrir pairs.csv';
                file_put_contents($state_path, wp_json_encode($state));
                return ['ok' => false, 'message' => $state['last_error']];
            }
            $batch = [];
            $batch_idx = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2) {
                    continue;
                }
                $batch[] = [$row[0], $row[1]];
                if (count($batch) >= self::BATCH_SIZE) {
                    $inst = $names[$batch_idx % $k];
                    $payload = [
                        'instance' => $inst,
                        'fail_streak' => 0,
                        'pairs' => $batch,
                    ];
                    file_put_contents($pending . '/batch_' . str_pad((string) $batch_idx, 6, '0', STR_PAD_LEFT) . '.json', wp_json_encode($payload));
                    $batch = [];
                    $batch_idx++;
                }
            }
            if (!empty($batch)) {
                $inst = $names[$batch_idx % $k];
                $payload = [
                    'instance' => $inst,
                    'fail_streak' => 0,
                    'pairs' => $batch,
                ];
                file_put_contents($pending . '/batch_' . str_pad((string) $batch_idx, 6, '0', STR_PAD_LEFT) . '.json', wp_json_encode($payload));
            }
            fclose($fh);

            $rf = fopen($result_csv, 'wb');
            if ($rf) {
                fwrite($rf, "\xEF\xBB\xBF");
                fputcsv($rf, ['TELEFONE', 'WPP']);
                fclose($rf);
            }

            $state['phase'] = 'running';
            $g = glob($pending . '/batch_*.json');
            $state['pending_count'] = is_array($g) ? count($g) : 0;
            file_put_contents($state_path, wp_json_encode($state));
        }

        if (($state['phase'] ?? '') !== 'running') {
            $total = max(1, (int) ($state['total_pairs'] ?? 1));
            $proc = (int) ($state['processed_pairs'] ?? 0);
            return [
                'ok' => true,
                'done' => false,
                'progress' => (int) round(100 * $proc / $total),
                'processed' => $proc,
                'total' => $total,
                'message' => 'Inicializando…',
            ];
        }

        $files = glob($pending . '/batch_*.json');
        sort($files);
        $names = array_column((array) ($state['instances'] ?? []), 'name');
        $k = max(1, count($names));

        while (microtime(true) < $deadline && !empty($files)) {
            $batch_file = array_shift($files);
            $raw = file_get_contents($batch_file);
            $job = json_decode($raw, true);
            if (!is_array($job) || empty($job['pairs']) || empty($job['instance'])) {
                @unlink($batch_file);
                continue;
            }

            $instance = (string) $job['instance'];
            $pairs = $job['pairs'];
            $nums = [];
            foreach ($pairs as $p) {
                if (isset($p[1])) {
                    $nums[] = (string) $p[1];
                }
            }

            $map = self::post_whatsapp_numbers($cfg['api_url'], $cfg['token'], $instance, $nums);
            if (is_wp_error($map)) {
                $fail = (int) ($job['fail_streak'] ?? 0) + 1;
                $last_ok = $state['last_success_instance'] ?? null;
                $next = $last_ok;
                if ($next === null || $next === $instance) {
                    $pos = array_search($instance, $names, true);
                    $next = $names[($pos !== false ? $pos + 1 : 0) % $k];
                }
                error_log('[PC Evolution Validator] Fallback inst ' . $instance . ' -> ' . $next . ' (falha ' . $fail . '): ' . $map->get_error_message());

                if ($fail >= self::MAX_FAIL_STREAK) {
                    $rf = fopen($result_csv, 'ab');
                    if ($rf) {
                        foreach ($pairs as $p) {
                            fputcsv($rf, [$p[0], 'falso']);
                        }
                        fclose($rf);
                    }
                    $state['processed_pairs'] = (int) ($state['processed_pairs'] ?? 0) + count($pairs);
                    @unlink($batch_file);
                    file_put_contents($state_path, wp_json_encode($state));
                    usleep(self::THROTTLE_US);
                    continue;
                }

                $job['instance'] = $next;
                $job['fail_streak'] = $fail;
                file_put_contents($batch_file, wp_json_encode($job));
                array_unshift($files, $batch_file);
                usleep(self::THROTTLE_US);
                break;
            }

            $rf = fopen($result_csv, 'ab');
            if ($rf) {
                foreach ($pairs as $p) {
                    $orig = $p[0];
                    $norm = preg_replace('/\D+/', '', (string) $p[1]);
                    $exists = isset($map[$norm]) ? (bool) $map[$norm] : false;
                    fputcsv($rf, [$orig, $exists ? 'verdadeiro' : 'falso']);
                }
                fclose($rf);
            }

            $state['last_success_instance'] = $instance;
            $state['processed_pairs'] = (int) ($state['processed_pairs'] ?? 0) + count($pairs);
            @unlink($batch_file);
            file_put_contents($state_path, wp_json_encode($state));
            usleep(self::THROTTLE_US);
        }

        $g_rem = glob($pending . '/batch_*.json');
        $remaining = is_array($g_rem) ? count($g_rem) : 0;
        if ($remaining === 0) {
            $state['phase'] = 'done';
            $job_folder = basename($dir);
            if (class_exists('PC_Validador_Historico')) {
                PC_Validador_Historico::record_completed_job($job_folder, $state, $result_csv);
            }
            self::maybe_log_run_metrics($state_path, $state, $result_csv);
            file_put_contents($state_path, wp_json_encode($state));
        }

        $total = max(1, (int) ($state['total_pairs'] ?? 1));
        $proc = (int) ($state['processed_pairs'] ?? 0);
        $done = ($state['phase'] ?? '') === 'done';

        return [
            'ok' => true,
            'done' => $done,
            'progress' => (int) min(100, round(100 * $proc / $total)),
            'processed' => $proc,
            'total' => $total,
        ];
    }

    public static function ajax_save_config()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado.');
        }
        check_ajax_referer('pc_nonce', 'nonce');

        $url = esc_url_raw($_POST['evolution_api_url'] ?? '');
        $token_in = isset($_POST['evolution_api_token']) ? trim((string) wp_unslash($_POST['evolution_api_token'])) : '';

        $opt = get_option(self::OPTION_KEY, []);
        if (!is_array($opt)) {
            $opt = [];
        }
        $opt['api_url'] = $url ? rtrim($url, '/') : '';

        if ($opt['api_url'] === '') {
            wp_send_json_error('Informe a URL base da Evolution API.');
        }

        if ($token_in !== '') {
            $opt['token_enc'] = self::encrypt_token($token_in);
        } elseif (empty($opt['token_enc'])) {
            wp_send_json_error('Informe o token (API Key) na primeira configuração.');
        }

        $opt['updated_at'] = time();
        update_option(self::OPTION_KEY, $opt);

        wp_send_json_success(['message' => 'Credenciais Evolution API salvas.']);
    }

    public static function ajax_get_config()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Acesso negado.');
        }
        check_ajax_referer('pc_nonce', 'nonce');

        $opt = get_option(self::OPTION_KEY, []);
        $url = is_array($opt) && !empty($opt['api_url']) ? esc_url_raw($opt['api_url']) : '';
        $has_token = is_array($opt) && !empty($opt['token_enc']);

        wp_send_json_success([
            'evolution_api_url' => $url,
            'evolution_token_configured' => $has_token,
        ]);
    }

    public static function ajax_upload()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Permissão negada.');
        }
        check_ajax_referer('pc_wa_validator', 'nonce');

        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error('Nenhum arquivo enviado.');
        }
        $name = isset($_FILES['file']['name']) ? (string) $_FILES['file']['name'] : '';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            wp_send_json_error('Apenas arquivos .csv são permitidos.');
        }

        $orig_name = isset($_FILES['file']['name']) ? (string) $_FILES['file']['name'] : '';
        $job = self::create_job_from_upload($_FILES['file']['tmp_name'], get_current_user_id(), $orig_name);
        if (is_wp_error($job)) {
            wp_send_json_error($job->get_error_message());
        }

        wp_send_json_success([
            'job_id' => $job,
            'download_nonce' => wp_create_nonce('pc_wa_validator_dl_' . $job),
        ]);
    }

    public static function ajax_step()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Permissão negada.');
        }
        check_ajax_referer('pc_wa_validator', 'nonce');

        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        if ($job_id === '') {
            wp_send_json_error('job_id obrigatório.');
        }

        $out = self::process_tick($job_id, get_current_user_id());
        if (empty($out['ok'])) {
            wp_send_json_error($out['message'] ?? 'Erro no processamento.');
        }

        $download_nonce = '';
        if (!empty($out['done'])) {
            $download_nonce = wp_create_nonce('pc_wa_validator_dl_' . $job_id);
        }

        wp_send_json_success([
            'done' => !empty($out['done']),
            'progress' => $out['progress'] ?? 0,
            'processed' => $out['processed'] ?? 0,
            'total' => $out['total'] ?? 0,
            'download_nonce' => $download_nonce,
        ]);
    }

    public static function handle_download()
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_die('Acesso negado', 403);
        }

        $job_id = sanitize_text_field($_GET['job_id'] ?? '');
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if ($job_id === '' || !wp_verify_nonce($nonce, 'pc_wa_validator_dl_' . $job_id)) {
            wp_die('Link inválido ou expirado.', 403);
        }

        $dir = self::job_dir($job_id);
        $state = json_decode((string) @file_get_contents($dir . '/state.json'), true);
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== get_current_user_id()) {
            wp_die('Job não encontrado.', 404);
        }
        if (($state['phase'] ?? '') !== 'done') {
            wp_die('Processamento ainda não concluído.', 400);
        }

        $path = $dir . '/result.csv';
        if (!is_readable($path)) {
            wp_die('Arquivo de resultado não encontrado.', 404);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="whatsapp-validado-' . $job_id . '.csv"');
        readfile($path);
        exit;
    }

    /**
     * @return string
     */
    public static function metrics_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'pc_wa_validator_runs';
    }

    public static function ensure_metrics_table()
    {
        global $wpdb;

        $table = self::metrics_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            user_name varchar(255) NOT NULL DEFAULT '',
            quantidade_total int(10) unsigned NOT NULL DEFAULT 0,
            quantidade_verdadeiros int(10) unsigned NOT NULL DEFAULT 0,
            data_processamento datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_pc_wa_val_data (data_processamento),
            KEY idx_pc_wa_val_user (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param string $path
     * @return int
     */
    private static function count_verdadeiros_in_result_csv($path)
    {
        if (!is_readable($path)) {
            return 0;
        }
        $h = fopen($path, 'rb');
        if (!$h) {
            return 0;
        }
        $n = 0;
        $first = true;
        while (($row = fgetcsv($h)) !== false) {
            if ($first) {
                $first = false;
                continue;
            }
            if (!isset($row[1])) {
                continue;
            }
            if (strtolower(trim((string) $row[1])) === 'verdadeiro') {
                $n++;
            }
        }
        fclose($h);

        return $n;
    }

    /**
     * @param string $state_path
     * @param array<string, mixed> $state
     * @param string $result_csv
     */
    private static function maybe_log_run_metrics($state_path, array &$state, $result_csv)
    {
        if (!empty($state['metrics_logged'])) {
            return;
        }

        $uid = (int) ($state['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        self::ensure_metrics_table();
        global $wpdb;

        $table = self::metrics_table_name();
        $total = (int) ($state['total_pairs'] ?? 0);
        $verdadeiros = self::count_verdadeiros_in_result_csv($result_csv);

        $u = get_userdata($uid);
        $nome = ($u && !empty($u->display_name)) ? $u->display_name : sprintf('Usuário #%d', $uid);

        $wpdb->insert(
            $table,
            [
                'user_id' => $uid,
                'user_name' => $nome,
                'quantidade_total' => $total,
                'quantidade_verdadeiros' => $verdadeiros,
                'data_processamento' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%d', '%s']
        );

        if ($wpdb->last_error) {
            error_log('[PC Evolution Validator] Falha ao gravar métricas: ' . $wpdb->last_error);
            return;
        }

        $state['metrics_logged'] = 1;
    }

    /**
     * GET /wp-json/api/v1/validador/metricas
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_validador_metricas($request)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return new WP_Error('pc_wa_val_forbidden', 'Acesso negado.', ['status' => 403]);
        }

        self::ensure_metrics_table();

        $di = sanitize_text_field($request->get_param('data_inicio') ?? '');
        $df = sanitize_text_field($request->get_param('data_fim') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $di) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
            return new WP_Error('pc_wa_val_bad_date', 'Use data_inicio e data_fim no formato YYYY-MM-DD.', ['status' => 400]);
        }

        try {
            $tz = wp_timezone();
            $start = new DateTimeImmutable($di . ' 00:00:00', $tz);
            $end = new DateTimeImmutable($df . ' 23:59:59', $tz);
        } catch (Exception $e) {
            return new WP_Error('pc_wa_val_bad_date', 'Datas inválidas.', ['status' => 400]);
        }

        if ($end < $start) {
            return new WP_Error('pc_wa_val_bad_range', 'data_fim deve ser >= data_inicio.', ['status' => 400]);
        }

        $start_s = $start->format('Y-m-d H:i:s');
        $end_s = $end->format('Y-m-d H:i:s');

        global $wpdb;
        $table = self::metrics_table_name();

        $sql = $wpdb->prepare(
            "SELECT user_id,
                    MAX(user_name) AS user_name,
                    SUM(quantidade_total) AS total_enviado,
                    SUM(quantidade_verdadeiros) AS total_validos
             FROM `{$table}`
             WHERE data_processamento >= %s AND data_processamento <= %s
             GROUP BY user_id
             ORDER BY total_enviado DESC",
            $start_s,
            $end_s
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $linhas = [];
        foreach ($rows as $r) {
            $t = (int) ($r['total_enviado'] ?? 0);
            $v = (int) ($r['total_validos'] ?? 0);
            $pct = $t > 0 ? round(100 * $v / $t, 2) : 0.0;
            $linhas[] = [
                'usuario_id' => (int) ($r['user_id'] ?? 0),
                'usuario_nome' => (string) ($r['user_name'] ?? ''),
                'total_enviado' => $t,
                'total_validos' => $v,
                'taxa_qualidade_pct' => $pct,
            ];
        }

        return new WP_REST_Response(
            [
                'periodo' => [
                    'data_inicio' => $di,
                    'data_fim' => $df,
                    'timezone' => $tz->getName(),
                ],
                'linhas' => $linhas,
            ],
            200
        );
    }
}
