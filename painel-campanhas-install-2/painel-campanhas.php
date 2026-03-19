<?php
/**
 * Plugin Name: Painel de Campanhas
 * Plugin URI:
 * Description: Sistema COMPLETO e INDEPENDENTE de gerenciamento de campanhas multicanal (WhatsApp, RCS, SMS) com interface moderna, controle de custos, carteiras, aprovacao de campanhas e integracao com microservico NestJS. Suporta RCS Otima, WhatsApp Otima, RCS CDA, CDA, GOSAC, NOAH e Salesforce.
 * Version: 1.0.1
 * Author: Daniel Cayres
 * Author URI:
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: painel-campanhas
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

class Painel_Campanhas
{
    private static $instance = null;
    private $plugin_path;
    private $plugin_url;
    private $version = '1.0.0';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        // Register error handler for debugging
        $plugin_path_local = $this->plugin_path;
        register_shutdown_function(function () use ($plugin_path_local) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $log_dir = $plugin_path_local . '.cursor';
                if (!is_dir($log_dir)) {
                    @mkdir($log_dir, 0755, true);
                }
                $log_path = $log_dir . '/debug.log';
                $log_entry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'FATAL',
                    'location' => $error['file'] . ':' . $error['line'],
                    'message' => 'PHP Fatal Error',
                    'data' => [
                        'error_type' => $error['type'],
                        'error_message' => $error['message'],
                        'error_file' => $error['file'],
                        'error_line' => $error['line']
                    ],
                    'timestamp' => time() * 1000
                ]) . "\n";
                @file_put_contents($log_path, $log_entry, FILE_APPEND);
            }
        });

        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Ativação/Desativação (registrado fora da classe, mas mantemos aqui para referência)
        // register_activation_hook precisa ser chamado fora da classe para funcionar corretamente
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Salesforce MC import cron (every 6 hours)
        add_action('pc_salesforce_import_cron', [$this, 'run_salesforce_import_cron']);
        if (!wp_next_scheduled('pc_salesforce_import_cron')) {
            wp_schedule_event(time(), 'twicedaily', 'pc_salesforce_import_cron');
        }

        // Inicialização
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Rotas customizadas
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_custom_routes']);

        // Remove admin bar nas páginas do plugin
        add_filter('show_admin_bar', [$this, 'hide_admin_bar_on_plugin_pages']);

        // AJAX
        add_action('wp_ajax_pc_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_pc_login', [$this, 'handle_login']);
        add_action('wp_ajax_pc_logout', [$this, 'handle_logout']);

        // AJAX para campanhas CPF
        add_action('wp_ajax_cpf_cm_upload_csv', [$this, 'handle_cpf_upload_csv']);
        add_action('wp_ajax_cpf_cm_get_custom_filters', [$this, 'handle_cpf_get_custom_filters']);
        add_action('wp_ajax_cpf_cm_preview_count', [$this, 'handle_cpf_preview_count']);
        add_action('wp_ajax_cpf_cm_generate_clean_file', [$this, 'handle_cpf_generate_clean_file']);
        add_action('wp_ajax_cpf_cm_create_campaign', [$this, 'handle_create_cpf_campaign']);

        // AJAX para campanhas recorrentes
        add_action('wp_ajax_cm_save_recurring', [$this, 'handle_save_recurring']);
        add_action('wp_ajax_cm_get_recurring', [$this, 'handle_get_recurring']);
        add_action('wp_ajax_cm_delete_recurring', [$this, 'handle_delete_recurring']);
        add_action('wp_ajax_cm_toggle_recurring', [$this, 'handle_toggle_recurring']);
        add_action('wp_ajax_cm_execute_recurring_now', [$this, 'handle_execute_recurring_now']);
        add_action('wp_ajax_cm_preview_recurring_count', [$this, 'handle_preview_recurring_count']);
        add_action('wp_ajax_cm_get_recurring_estimates', [$this, 'handle_get_recurring_estimates']);

        // AJAX para criar campanhas (delegar para campaign-manager se disponível, senão usar handler próprio)
        add_action('wp_ajax_cm_schedule_campaign', [$this, 'handle_schedule_campaign']);
        add_action('wp_ajax_cm_get_filters', [$this, 'handle_get_filters']);
        add_action('wp_ajax_cm_get_count', [$this, 'handle_get_count']);
        add_action('wp_ajax_cm_get_count_detailed', [$this, 'handle_get_count_detailed']);
        add_action('wp_ajax_cm_get_template_content', [$this, 'handle_get_template_content']);

        // AJAX para mensagens
        add_action('wp_ajax_pc_get_messages', [$this, 'handle_get_messages']);
        add_action('wp_ajax_pc_get_message', [$this, 'handle_get_message']);
        add_action('wp_ajax_pc_create_message', [$this, 'handle_create_message']);
        add_action('wp_ajax_pc_update_message', [$this, 'handle_update_message']);
        add_action('wp_ajax_pc_delete_message', [$this, 'handle_delete_message']);

        // AJAX para relatórios
        add_action('wp_ajax_pc_get_report_data', [$this, 'handle_get_report_data']);
        add_action('wp_ajax_pc_get_report_1x1_stats', [$this, 'handle_get_report_1x1_stats']);

        // AJAX para verificar atualização da base
        add_action('wp_ajax_cm_check_base_update', [$this, 'handle_check_base_update']);

        // Download CSV
        add_action('admin_post_pc_download_csv_geral', [$this, 'handle_download_csv_geral']);
        add_action('admin_post_pc_download_csv_agendamento', [$this, 'handle_download_csv_agendamento']);
        add_action('admin_post_pc_download_salesforce_csv_file', [$this, 'handle_download_salesforce_csv_file']);

        // AJAX para API Manager
        add_action('wp_ajax_pc_save_master_api_key', [$this, 'handle_save_master_api_key']);
        add_action('wp_ajax_pc_get_master_api_key', [$this, 'handle_get_master_api_key']);
        add_action('wp_ajax_pc_get_static_credentials', [$this, 'handle_get_static_credentials']);
        add_action('wp_ajax_pc_get_otima_customers', [$this, 'handle_get_otima_customers']);
        add_action('wp_ajax_pc_save_microservice_config', [$this, 'handle_save_microservice_config']);
        add_action('wp_ajax_pc_save_static_credentials', [$this, 'handle_save_static_credentials']);
        add_action('wp_ajax_pc_create_credential', [$this, 'handle_create_credential']);
        add_action('wp_ajax_pc_get_credential', [$this, 'handle_get_credential']);
        add_action('wp_ajax_pc_list_credentials', [$this, 'handle_list_credentials']);
        add_action('wp_ajax_pc_update_credential', [$this, 'handle_update_credential']);
        add_action('wp_ajax_pc_delete_credential', [$this, 'handle_delete_credential']);

        // AJAX para Providers Customizados
        add_action('wp_ajax_pc_create_custom_provider', [$this, 'handle_create_custom_provider']);
        add_action('wp_ajax_pc_get_custom_provider', [$this, 'handle_get_custom_provider']);
        add_action('wp_ajax_pc_list_custom_providers', [$this, 'handle_list_custom_providers']);
        add_action('wp_ajax_pc_update_custom_provider', [$this, 'handle_update_custom_provider']);
        add_action('wp_ajax_pc_delete_custom_provider', [$this, 'handle_delete_custom_provider']);

        // AJAX Otima Templates
        add_action('wp_ajax_pc_get_otima_templates', [$this, 'handle_get_otima_templates']);
        add_action('wp_ajax_pc_get_otima_brokers', [$this, 'handle_get_otima_brokers']);
        add_action('wp_ajax_pc_get_gosac_oficial_templates', [$this, 'handle_get_gosac_oficial_templates']);
        add_action('wp_ajax_pc_get_gosac_oficial_connections', [$this, 'handle_get_gosac_oficial_connections']);
        add_action('wp_ajax_pc_get_noah_oficial_templates', [$this, 'handle_get_noah_oficial_templates']);
        add_action('wp_ajax_pc_get_noah_oficial_channels', [$this, 'handle_get_noah_oficial_channels']);
        add_action('wp_ajax_pc_get_robbu_oficial_templates', [$this, 'handle_get_robbu_oficial_templates']);
        add_action('wp_ajax_pc_get_robbu_webhook_stats', [$this, 'handle_get_robbu_webhook_stats']);
        add_action('wp_ajax_pc_get_all_connections_health', [$this, 'handle_get_all_connections_health']);
        add_action('wp_ajax_pc_get_templates_by_wallet', [$this, 'handle_get_templates_by_wallet']);

        // AJAX Salesforce Manual Import
        add_action('wp_ajax_pc_run_salesforce_import', [$this, 'handle_run_salesforce_import']);
        // AJAX para Aprovar Campanhas
        add_action('wp_ajax_pc_get_pending_campaigns', [$this, 'handle_get_pending_campaigns']);
        add_action('wp_ajax_pc_get_microservice_config', [$this, 'handle_get_microservice_config']);
        add_action('wp_ajax_pc_update_campaign_status', [$this, 'handle_update_campaign_status']);
        add_action('wp_ajax_pc_approve_campaign', [$this, 'handle_approve_campaign']);
        add_action('wp_ajax_pc_deny_campaign', [$this, 'handle_deny_campaign']);

        // AJAX para Controle de Custo
        add_action('wp_ajax_pc_save_custo_provider', [$this, 'handle_save_custo_provider']);
        add_action('wp_ajax_pc_get_custos_providers', [$this, 'handle_get_custos_providers']);
        add_action('wp_ajax_pc_delete_custo_provider', [$this, 'handle_delete_custo_provider']);
        add_action('wp_ajax_pc_save_orcamento_base', [$this, 'handle_save_orcamento_base']);
        add_action('wp_ajax_pc_get_orcamentos_bases', [$this, 'handle_get_orcamentos_bases']);
        add_action('wp_ajax_pc_delete_orcamento_base', [$this, 'handle_delete_orcamento_base']);
        add_action('wp_ajax_pc_get_relatorio_custos', [$this, 'handle_get_relatorio_custos']);

        // AJAX para Carteiras
        add_action('wp_ajax_pc_create_carteira', [$this, 'handle_create_carteira']);
        add_action('wp_ajax_pc_get_carteiras', [$this, 'handle_get_carteiras']);
        add_action('wp_ajax_pc_get_carteira', [$this, 'handle_get_carteira']);
        add_action('wp_ajax_pc_update_carteira', [$this, 'handle_update_carteira']);
        add_action('wp_ajax_pc_delete_carteira', [$this, 'handle_delete_carteira']);
        add_action('wp_ajax_pc_vincular_base_carteira', [$this, 'handle_vincular_base_carteira']);
        add_action('wp_ajax_pc_remover_base_carteira', [$this, 'handle_remover_base_carteira']);
        add_action('wp_ajax_pc_get_bases_carteira', [$this, 'handle_get_bases_carteira']);
        add_action('wp_ajax_pc_limpar_vinculos_ruins', [$this, 'handle_limpar_vinculos_ruins']);
        add_action('wp_ajax_pc_resetar_tabelas_carteiras', [$this, 'handle_resetar_tabelas_carteiras']);

        // AJAX para Iscas
        add_action('wp_ajax_pc_create_isca', [$this, 'handle_create_isca']);
        add_action('wp_ajax_pc_get_iscas', [$this, 'handle_get_iscas']);
        add_action('wp_ajax_pc_get_isca', [$this, 'handle_get_isca']);
        add_action('wp_ajax_pc_update_isca', [$this, 'handle_update_isca']);
        add_action('wp_ajax_pc_delete_isca', [$this, 'handle_delete_isca']);

        // AJAX para Ranking
        add_action('wp_ajax_pc_get_ranking', [$this, 'handle_get_ranking']);

        // AJAX para Tracking Salesforce
        add_action('wp_ajax_pc_get_salesforce_tracking', [$this, 'handle_get_salesforce_tracking']);
        add_action('wp_ajax_pc_download_salesforce_csv', [$this, 'handle_download_salesforce_csv']);

        // AJAX para Relatórios Multi-Tabela
        add_action('wp_ajax_pc_get_envios_pendentes', [$this, 'handle_get_envios_pendentes']);
        add_action('wp_ajax_pc_get_eventos_envios', [$this, 'handle_get_eventos_envios']);
        add_action('wp_ajax_pc_get_eventos_indicadores', [$this, 'handle_get_eventos_indicadores']);
        add_action('wp_ajax_pc_get_eventos_tempos', [$this, 'handle_get_eventos_tempos']);
        add_action('wp_ajax_pc_get_report_summary', [$this, 'handle_get_report_summary']);

        // AJAX para upload de mídia de campanha
        add_action('wp_ajax_pc_upload_campaign_media', [$this, 'handle_upload_campaign_media']);

        // AJAX para Campanha via Arquivo
        add_action('wp_ajax_pc_upload_campaign_file', [$this, 'handle_upload_campaign_file']);
        add_action('wp_ajax_pc_preview_campaign_file', [$this, 'handle_preview_campaign_file']);
        add_action('wp_ajax_pc_create_campaign_from_file', [$this, 'handle_create_campaign_from_file']);

        // AJAX para Dashboard
        add_action('wp_ajax_pc_get_dashboard_stats', [$this, 'handle_get_dashboard_stats']);
        add_action('wp_ajax_pc_get_campanhas', [$this, 'handle_get_campanhas']);
        add_action('wp_ajax_pc_get_available_bases', [$this, 'handle_get_available_bases']);

        // AJAX para Blocklist
        add_action('wp_ajax_pc_get_blocklist', [$this, 'handle_get_blocklist']);
        add_action('wp_ajax_pc_add_to_blocklist', [$this, 'handle_add_to_blocklist']);
        add_action('wp_ajax_pc_remove_from_blocklist', [$this, 'handle_remove_from_blocklist']);
        add_action('wp_ajax_pc_check_blocklist', [$this, 'handle_check_blocklist']);
        add_action('wp_ajax_pc_import_blocklist_csv', [$this, 'handle_import_blocklist_csv']);

        // Admin Post handlers
        add_action('admin_post_save_master_api_key', [$this, 'handle_save_master_api_key']);

        // Proteção de rotas
        add_action('template_redirect', [$this, 'check_authentication']);

        // REST API para microserviço buscar dados
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Validates that a table name exists in the database and matches allowed patterns.
     * Prevents SQL injection via user-supplied table names.
     */
    private function validate_table_name(string $table_name): bool
    {
        if (empty($table_name)) return false;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) return false;

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return !empty($exists);
    }

    /**
     * Sanitizes and validates table_name from user input.
     * Returns validated name or sends JSON error and returns empty string.
     */
    private function get_safe_table_name(): string
    {
        $table_name = sanitize_text_field($_POST['table_name'] ?? '');
        if (empty($table_name)) {
            return '';
        }
        if (!$this->validate_table_name($table_name)) {
            wp_send_json_error('Nome de tabela inválido ou não encontrado.');
            return '';
        }
        return $table_name;
    }

    public function register_rest_routes()
    {
        register_rest_route('campaigns/v1', '/data/(?P<agendamento_id>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_campaign_data_rest'],
            'permission_callback' => [$this, 'check_api_key_rest'],
        ]);

        register_rest_route('api-manager/v1', '/credentials/(?P<provider>[^/]+)/(?P<env_id>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_credentials_rest'],
            'permission_callback' => [$this, 'check_api_key_rest'],
        ]);

        register_rest_route('webhook-status/v1', '/update', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_status_update'],
            'permission_callback' => [$this, 'check_api_key_rest'],
        ]);

        // Webhook Robbu - recebe eventos do Invenio (status de mensagens, saúde das linhas, etc.)
        // URL para cadastrar na Robbu: https://SEU-SITE.com/wp-json/robbu-webhook/v2/receive
        register_rest_route('robbu-webhook/v2', '/receive', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_robbu_webhook_receive'],
            'permission_callback' => '__return_true', // Robbu não envia autenticação; use firewall por IP se necessário
        ]);
        register_rest_route('campaigns/v1', '/config/(?P<agendamento_id>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_campaign_config'],
            'permission_callback' => [$this, 'check_api_key_rest'],
            'args' => [
                'agendamento_id' => [
                    'required' => true,
                    'validate_callback' => function ($param, $request, $key) {
                        return is_string($param);
                    }
                ],
            ],
        ]);
    }

    public function check_api_key_rest($request)
    {
        $master_key = trim(get_option('acm_master_api_key', ''));
        if (empty($master_key)) {
            error_log('🔴 [REST API] Master API Key não configurada');
            return new WP_Error('no_master_key', 'Master API Key não configurada.', ['status' => 503]);
        }

        $provided_key = $request->get_header('x-api-key') ?: $request->get_header('x_api_key');

        if (empty($provided_key)) {
            $provided_key = $request->get_param('api_key');
        }

        if (empty($provided_key) && isset($_GET['api_key'])) {
            $provided_key = $_GET['api_key'];
        }

        $provided_key = trim($provided_key ?: '');

        if (empty($provided_key)) {
            error_log('🔴 [REST API] X-API-KEY header ou api_key query param não fornecidos');
            error_log('🔴 [REST API] Headers recebidos: ' . json_encode(array_keys($request->get_headers())));
            return new WP_Error('no_key_provided', 'API Key não fornecida no header X-API-KEY nem na URL.', ['status' => 401]);
        }

        if ($provided_key !== $master_key) {
            $mask = function ($k) {
                return strlen($k) > 8 ? substr($k, 0, 4) . '...' . substr($k, -4) : '[' . strlen($k) . ' chars]';
            };
            error_log('🔴 [REST API] API Key inválida!');
            error_log('🔴 [REST API]   Fornecida: "' . $mask($provided_key) . '" (len=' . strlen($provided_key) . ')');
            error_log('🔴 [REST API]   Esperada:  "' . $mask($master_key) . '" (len=' . strlen($master_key) . ')');
            return new WP_Error('invalid_key', 'API Key inválida.', ['status' => 401]);
        }

        error_log('✅ [REST API] API Key válida');
        return true;
    }

    public function get_campaign_data_rest($request)
    {
        $agendamento_id = $request->get_param('agendamento_id');

        if (empty($agendamento_id)) {
            return new WP_Error('invalid_agendamento', 'Agendamento ID é obrigatório.', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';

        // Lazy migrations (Garantiro que colunas vitais novas existam caso usuário não reativou plugin)
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'id_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN id_carteira varchar(100) DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'idcob_contrato'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN idcob_contrato bigint(20) DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'midia_campanha'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN midia_campanha text DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'carteira_id'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN carteira_id bigint(20) DEFAULT NULL");
        }

        $query = $wpdb->prepare("
            SELECT 
                CONCAT('55', telefone) as telefone,
                nome,
                COALESCE(id_carteira, '') as id_carteira,
                COALESCE(carteira_id, '') as carteira_id,
                idgis_ambiente,
                idcob_contrato,
                COALESCE(cpf_cnpj, '') as cpf_cnpj,
                data_cadastro as data_cadastro,
                mensagem,
                COALESCE(midia_campanha, '') as midia_campanha
            FROM {$table}
            WHERE agendamento_id = %s
            AND status IN ('pendente_aprovacao', 'pendente')
            ORDER BY id ASC
        ", $agendamento_id);

        $results = $wpdb->get_results($query, ARRAY_A);

        if ($wpdb->last_error) {
            $err = $wpdb->last_error;
            error_log('🔴 [REST API DB ERRO] ' . $err . ' | Query: ' . $query);
            return new WP_Error('db_error', 'Erro no banco de dados: ' . $err, ['status' => 500]);
        }

        if (empty($results)) {
            error_log('🔴 [REST API VAZIO] Agendamento ID: ' . $agendamento_id . ' retornou 0 linhas. Verifique o status da campanha no MySQL.');
            return new WP_Error('no_data', 'Nenhum dado encontrado para este agendamento.', ['status' => 404]);
        }

        // Formata os dados conforme esperado pelo microserviço (CampaignData interface)
        $formatted_data = [];
        foreach ($results as $row) {
            // Se não tiver id_carteira, tenta buscar pelo idgis_ambiente
            $id_carteira = $row['id_carteira'];
            if (empty($id_carteira) && !empty($row['idgis_ambiente'])) {
                $id_carteira = $this->get_id_carteira_from_idgis($row['idgis_ambiente']);
            }

            // carteira_nome: para GOSAC - lookup por nome quando há múltiplas carteiras com mesmo id_carteira
            $carteira_nome = '';
            if (!empty($row['carteira_id'])) {
                $carteira_nome = $this->get_carteira_nome_by_id($row['carteira_id']);
            }

            $formatted_data[] = [
                'telefone' => (string) $row['telefone'],
                'nome' => (string) $row['nome'],
                'id_carteira' => (string) $id_carteira,
                'carteira_nome' => $carteira_nome,
                'idgis_ambiente' => (string) $row['idgis_ambiente'],
                'idcob_contrato' => (string) $row['idcob_contrato'],
                'cpf_cnpj' => (string) $row['cpf_cnpj'],
                'mensagem' => (string) $row['mensagem'],
                'data_cadastro' => (string) ($row['data_cadastro'] ?: date('Y-m-d H:i:s')),
                'midia_campanha' => (string) ($row['midia_campanha'] ?? ''),
            ];
        }

        return rest_ensure_response($formatted_data);
    }

    public function activate()
    {
        $this->add_rewrite_rules();
        $this->create_tables();
        flush_rewrite_rules();
    }

    private function create_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // ============================================
        // TABELA PRINCIPAL: envios_pendentes
        // ============================================
        $table_envios = $wpdb->prefix . 'envios_pendentes';
        $sql_envios = "CREATE TABLE IF NOT EXISTS $table_envios (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            telefone varchar(20) NOT NULL,
            nome varchar(255) DEFAULT NULL,
            idgis_ambiente int(11) DEFAULT NULL,
            id_carteira varchar(100) DEFAULT NULL,
            idcob_contrato bigint(20) DEFAULT NULL,
            cpf_cnpj varchar(20) DEFAULT NULL,
            mensagem text,
            fornecedor varchar(50) DEFAULT NULL,
            agendamento_id varchar(100) DEFAULT NULL,
            status varchar(50) DEFAULT 'pendente',
            current_user_id bigint(20) DEFAULT NULL,
            valido tinyint(1) DEFAULT 1,
            data_cadastro datetime DEFAULT CURRENT_TIMESTAMP,
            data_disparo datetime DEFAULT NULL,
            resposta_api text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_telefone (telefone),
            KEY idx_agendamento (agendamento_id),
            KEY idx_status (status),
            KEY idx_fornecedor (fornecedor),
            KEY idx_user (current_user_id),
            KEY idx_data_cadastro (data_cadastro),
            KEY idx_idgis (idgis_ambiente),
            KEY idx_carteira (id_carteira)
        ) $charset_collate;";
        dbDelta($sql_envios);

        // Tabela de custos por provider
        $table_custos = $wpdb->prefix . 'pc_custos_providers';
        $sql_custos = "CREATE TABLE IF NOT EXISTS $table_custos (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            provider varchar(50) NOT NULL,
            custo_por_disparo decimal(10,4) NOT NULL DEFAULT 0.0000,
            ativo tinyint(1) DEFAULT 1,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_provider (provider)
        ) $charset_collate;";
        dbDelta($sql_custos);

        // Tabela de orçamentos por base (VW_BASE*)
        $table_orcamentos = $wpdb->prefix . 'pc_orcamentos_bases';
        $sql_orcamentos = "CREATE TABLE IF NOT EXISTS $table_orcamentos (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        nome_base varchar(150) NOT NULL,
        orcamento_total decimal(10,2) NOT NULL DEFAULT 0.00,
        mes int(2) NOT NULL DEFAULT 0,
        ano int(4) NOT NULL DEFAULT 0,
        criado_em datetime DEFAULT CURRENT_TIMESTAMP,
        atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_base_periodo (nome_base, mes, ano)
    ) $charset_collate;";
        dbDelta($sql_orcamentos);

        // MIGRATION: Adicionar colunas mes/ano se não existirem
        $cols = $wpdb->get_results("SHOW COLUMNS FROM $table_orcamentos LIKE 'mes'");
        if (empty($cols)) {
            $wpdb->query("ALTER TABLE $table_orcamentos ADD COLUMN mes int(2) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE $table_orcamentos ADD COLUMN ano int(4) NOT NULL DEFAULT 0");

            // Atualiza index
            // Primeiro remove o antigo se existir
            $wpdb->query("ALTER TABLE $table_orcamentos DROP INDEX unique_base");
            // Adiciona o novo (se já não estiver lá pelo dbDelta)
            $wpdb->query("ALTER TABLE $table_orcamentos ADD UNIQUE KEY unique_base_periodo (nome_base, mes, ano)");
        }

        // ✨ CRIA TABELAS V2 - COMPLETAMENTE NOVAS (antigas não são tocadas)
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $table_carteiras_bases = $wpdb->prefix . 'pc_carteiras_bases_v2';

        error_log('✨ [Plugin] Criando tabelas V2 (novas e limpas)');

        // Cria tabela de carteiras V2
        $sql_carteiras = "CREATE TABLE IF NOT EXISTS $table_carteiras (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            nome varchar(255) NOT NULL,
            id_carteira varchar(100) NOT NULL,
            id_ruler varchar(100) DEFAULT NULL,
            descricao text,
            ativo tinyint(1) DEFAULT 1,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_id_carteira (id_carteira)
        ) $charset_collate;";
        $wpdb->query($sql_carteiras);

        // Migração: Adiciona coluna id_ruler se não existir na tabela antiga
        $col_id_ruler_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_carteiras LIKE 'id_ruler'");
        if (empty($col_id_ruler_exists)) {
            $wpdb->query("ALTER TABLE $table_carteiras ADD COLUMN id_ruler varchar(100) DEFAULT NULL AFTER id_carteira");
        }

        // Cria tabela de vínculos V2
        $sql_carteiras_bases = "CREATE TABLE IF NOT EXISTS $table_carteiras_bases (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            carteira_id bigint(20) NOT NULL,
            nome_base varchar(150) NOT NULL,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_carteira_base (carteira_id, nome_base)
        ) $charset_collate;";
        $wpdb->query($sql_carteiras_bases);

        error_log('✅ [Plugin] Tabelas V2 prontas! Usando tabelas NOVAS sem dados antigos!');

        // Tabela de iscas (baits)
        $table_baits = $wpdb->prefix . 'cm_baits';
        $sql_baits = "CREATE TABLE IF NOT EXISTS $table_baits (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            telefone varchar(20) NOT NULL,
            nome varchar(255) NOT NULL,
            idgis_ambiente int(11) DEFAULT NULL,
            id_carteira bigint(20) DEFAULT NULL,
            cpf varchar(20) DEFAULT NULL,
            ativo tinyint(1) DEFAULT 1,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_carteira (id_carteira)
        ) $charset_collate;";
        dbDelta($sql_baits);

        // Migração: Adiciona coluna id_carteira se não existir (para atualizações)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_baits LIKE 'id_carteira'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_baits ADD COLUMN id_carteira bigint(20) DEFAULT NULL AFTER idgis_ambiente");
            $wpdb->query("ALTER TABLE $table_baits ADD KEY idx_carteira (id_carteira)");
        }

        // Migração: Adiciona coluna cpf se não existir
        $cpf_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_baits LIKE 'cpf'");
        if (empty($cpf_exists)) {
            $wpdb->query("ALTER TABLE $table_baits ADD COLUMN cpf varchar(20) DEFAULT NULL AFTER id_carteira");
        }

        // Migração: Torna idgis_ambiente nullable
        $wpdb->query("ALTER TABLE $table_baits MODIFY COLUMN idgis_ambiente int(11) DEFAULT NULL");

        // Tabela de mapeamento IDGIS
        $table_idgis_mappings = $wpdb->prefix . 'cm_idgis_mappings';
        $sql_idgis_mappings = "CREATE TABLE IF NOT EXISTS $table_idgis_mappings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tabela_origem varchar(150) NOT NULL,
            provedor_destino varchar(100) NOT NULL,
            idgis_ambiente_original int(11) NOT NULL,
            idgis_ambiente_mapeado int(11) NOT NULL,
            ativo tinyint(1) DEFAULT 1,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_mapping (tabela_origem, provedor_destino, idgis_ambiente_original)
        ) $charset_collate;";
        dbDelta($sql_idgis_mappings);

        // Tabela de blocklist
        $table_blocklist = $wpdb->prefix . 'pc_blocklist';
        $sql_blocklist = "CREATE TABLE IF NOT EXISTS $table_blocklist (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tipo enum('telefone','cpf') NOT NULL,
            valor varchar(20) NOT NULL,
            motivo text,
            criado_por bigint(20),
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_tipo_valor (tipo, valor),
            KEY idx_tipo (tipo),
            KEY idx_valor (valor)
        ) $charset_collate;";
        dbDelta($sql_blocklist);
        // Tabela de configurações de campanha (Throttling)
        $table_settings = $wpdb->prefix . 'pc_campaign_settings';
        $sql_settings = "CREATE TABLE IF NOT EXISTS $table_settings (
            agendamento_id varchar(100) NOT NULL,
            throttling_type enum('none', 'linear', 'split') DEFAULT 'none',
            throttling_config JSON DEFAULT NULL,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (agendamento_id)
        ) $charset_collate;";
        dbDelta($sql_settings);

        // ── Robbu Webhook ───────────────────────────────────────────────────────
        $this->create_robbu_webhook_tables();

        // ── salesforce_returns ────────────────────────────────────────────────
        // Table populated by import_salesforce.php cron job.
        $table = 'salesforce_returns'; // intentionally un-prefixed, matching the cron script
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uniqueid text NOT NULL,
            uniqueid_hash varchar(64) NOT NULL,
            trackingtype varchar(100),
            sendtype varchar(100),
            mid varchar(100),
            eid varchar(200),
            contactkey varchar(200),
            mobilenumber varchar(50),
            eventdateutc datetime,
            appid varchar(100),
            channelid varchar(100),
            channeltype varchar(50),
            conversationtype varchar(50),
            activityname varchar(150),
            channelname varchar(150),
            status varchar(100),
            reason text,
            jbdefinitionid varchar(200),
            sendidentifier varchar(200),
            assetid varchar(100),
            messagetypeid varchar(100),
            operacao__c varchar(100),
            cpf_cnpj__c varchar(50),
            name varchar(255),
            TemplateName varchar(255),
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniqueid_hash (uniqueid_hash)
        ) {$charset_collate};";
        dbDelta($sql);

        // Tabelas eventos_indicadores e eventos_tempos já existem no banco.
        // Não usar dbDelta aqui para não conflitar com o schema existente.
    }

    public function deactivate()
    {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('pc_salesforce_import_cron');
    }

    /**
     * WP-Cron hook: runs Salesforce MC import automatically.
     * Delegates to the standalone import_salesforce.php via include.
     */
    public function run_salesforce_import_cron()
    {
        $log_file = WP_CONTENT_DIR . '/sf_cron.log';
        $start = microtime(true);
        $timestamp = date('Y-m-d H:i:s');

        @file_put_contents($log_file, "[{$timestamp}] Salesforce cron started\n", FILE_APPEND);

        try {
            $script = __DIR__ . '/import_salesforce.php';
            if (!file_exists($script)) {
                @file_put_contents($log_file, "[{$timestamp}] ERROR: import_salesforce.php not found\n", FILE_APPEND);
                return;
            }

            ob_start();
            include $script;
            $output = ob_get_clean();

            $elapsed = round(microtime(true) - $start, 2);
            @file_put_contents($log_file, "[{$timestamp}] Completed in {$elapsed}s. Output: " . substr($output, 0, 500) . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
            @file_put_contents($log_file, "[{$timestamp}] FATAL: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    public function init()
    {
        // Inicializa componentes
    }

    public function add_rewrite_rules()
    {
        // Rotas principais
        add_rewrite_rule('^painel/login/?$', 'index.php?pc_page=login', 'top');
        add_rewrite_rule('^painel/home/?$', 'index.php?pc_page=home', 'top');
        add_rewrite_rule('^painel/campanhas/?$', 'index.php?pc_page=campanhas', 'top');
        add_rewrite_rule('^painel/nova-campanha/?$', 'index.php?pc_page=nova-campanha', 'top');
        add_rewrite_rule('^painel/campanhas-recorrentes/?$', 'index.php?pc_page=campanhas-recorrentes', 'top');
        add_rewrite_rule('^painel/aprovar-campanhas/?$', 'index.php?pc_page=aprovar-campanhas', 'top');
        add_rewrite_rule('^painel/mensagens/?$', 'index.php?pc_page=mensagens', 'top');
        add_rewrite_rule('^painel/relatorios/?$', 'index.php?pc_page=relatorios', 'top');
        add_rewrite_rule('^painel/api-manager/?$', 'index.php?pc_page=api-manager', 'top');
        add_rewrite_rule('^painel/configuracoes/?$', 'index.php?pc_page=configuracoes', 'top');
        add_rewrite_rule('^painel/iscas/?$', 'index.php?pc_page=iscas', 'top');
        add_rewrite_rule('^painel/ranking/?$', 'index.php?pc_page=ranking', 'top');
        add_rewrite_rule('^painel/blocklist/?$', 'index.php?pc_page=blocklist', 'top');
        add_rewrite_rule('^painel/controle-custo/?$', 'index.php?pc_page=controle-custo', 'top');
        add_rewrite_rule('^painel/controle-custo/cadastro/?$', 'index.php?pc_page=controle-custo-cadastro', 'top');
        add_rewrite_rule('^painel/controle-custo/relatorio/?$', 'index.php?pc_page=controle-custo-relatorio', 'top');
        add_rewrite_rule('^painel/campanha-arquivo/?$', 'index.php?pc_page=campanha-arquivo', 'top');
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'pc_page';
        return $vars;
    }

    public function handle_custom_routes()
    {
        $page = get_query_var('pc_page');

        // Fallback: se get_query_var não funcionar (ex: subdiretórios), tenta detectar pela URL
        if (empty($page)) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $home_path = parse_url(home_url(), PHP_URL_PATH);

            // Remove o caminho base do WordPress da URI
            if ($home_path && strpos($request_uri, $home_path) === 0) {
                $request_uri = substr($request_uri, strlen($home_path));
            }

            // Remove query string e barras do início/fim
            $request_uri = trim(strtok($request_uri, '?'), '/');

            // Mapeia URLs conhecidas para páginas
            $route_map = [
                'painel/login' => 'login',
                'painel/home' => 'home',
                'painel/campanhas' => 'campanhas',
                'painel/nova-campanha' => 'nova-campanha',
                'painel/campanhas-recorrentes' => 'campanhas-recorrentes',
                'painel/aprovar-campanhas' => 'aprovar-campanhas',
                'painel/mensagens' => 'mensagens',
                'painel/relatorios' => 'relatorios',
                'painel/api-manager' => 'api-manager',
                'painel/configuracoes' => 'configuracoes',
                'painel/iscas' => 'iscas',
                'painel/ranking' => 'ranking',
                'painel/blocklist' => 'blocklist',
                'painel/controle-custo' => 'controle-custo',
                'painel/controle-custo/cadastro' => 'controle-custo-cadastro',
                'painel/controle-custo/relatorio' => 'controle-custo-relatorio',
                'painel/campanha-arquivo' => 'campanha-arquivo',
            ];

            if (isset($route_map[$request_uri])) {
                $page = $route_map[$request_uri];
            } else {
                return; // Não é uma rota do painel
            }
        }

        // Redireciona para login se não autenticado (exceto página de login)
        if ($page !== 'login' && !$this->is_authenticated()) {
            wp_redirect(home_url('/painel/login'));
            exit;
        }

        // Redireciona para home se já autenticado e tentando acessar login
        if ($page === 'login' && $this->is_authenticated()) {
            wp_redirect(home_url('/painel/home'));
            exit;
        }

        // Verifica permissão para páginas de administrador
        $admin_pages = ['aprovar-campanhas', 'api-manager'];
        if (in_array($page, $admin_pages) && !current_user_can('manage_options')) {
            wp_redirect(home_url('/painel/home'));
            exit;
        }

        // Carrega a página correspondente
        $this->render_page($page);
        exit;
    }

    public function check_authentication()
    {
        $page = get_query_var('pc_page');

        if (empty($page) || $page === 'login') {
            return;
        }

        if (!$this->is_authenticated()) {
            wp_redirect(home_url('/painel/login'));
            exit;
        }
    }

    public function is_authenticated()
    {
        return is_user_logged_in();
    }

    public function can_access_admin_pages()
    {
        return current_user_can('manage_options');
    }

    /**
     * Remove admin bar nas páginas do plugin
     */
    public function hide_admin_bar_on_plugin_pages($show)
    {
        $current_page = get_query_var('pc_page');
        if (!empty($current_page)) {
            return false;
        }

        // Verifica também pela URL diretamente
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/painel/') !== false) {
            return false;
        }

        return $show;
    }

    public function render_page($page)
    {
        // Usa React automaticamente se build existir, senão usa templates PHP
        $react_dist_path = $this->plugin_path . 'react/dist/index.html';
        $react_wrapper = $this->plugin_path . 'react-wrapper.php';

        // Tenta usar React automaticamente se build existir
        if (file_exists($react_dist_path) && file_exists($react_wrapper)) {
            include $react_wrapper;
            return;
        }

        // Fallback para templates PHP
        $template_file = $this->plugin_path . $page . '.php';

        if (!file_exists($template_file)) {
            // Fallback para pasta templates (caso exista no futuro)
            $template_file = $this->plugin_path . 'templates/' . $page . '.php';
        }

        if (file_exists($template_file)) {
            // Define variáveis globais para os templates
            global $pc_current_page, $pc_plugin_path;
            $pc_current_page = $page;
            $pc_plugin_path = $this->plugin_path;

            include $template_file;
        } else {
            wp_die('Página não encontrada: ' . esc_html($page), 'Erro 404', ['response' => 404]);
        }
    }

    public function get_plugin_path()
    {
        return $this->plugin_path;
    }

    public function get_plugin_url()
    {
        return $this->plugin_url;
    }

    public function enqueue_assets()
    {
        $page = get_query_var('pc_page');

        if (empty($page)) {
            return;
        }

        // Se o React está ativo (build existe), não carrega assets antigos
        $react_dist_path = $this->plugin_path . 'react/dist/index.html';
        if (file_exists($react_dist_path)) {
            return; // React cuida dos assets
        }

        // Tailwind CSS via CDN (apenas se React não estiver ativo)
        wp_enqueue_script('tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false);

        // Font Awesome
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', [], '6.4.0');

        // CSS customizado
        wp_enqueue_style('painel-campanhas', $this->plugin_url . 'assets/css/style.css', [], $this->version);

        // CSS para filtros dinâmicos
        if ($page === 'nova-campanha') {
            wp_enqueue_style('filters-dynamic', $this->plugin_url . 'assets/css/filters.css', [], $this->version);
        }

        // JavaScript customizado (jQuery já está no WordPress)
        wp_enqueue_script('painel-campanhas', $this->plugin_url . 'assets/js/main.js', ['jquery'], $this->version, true);

        // Localize script
        wp_localize_script('painel-campanhas', 'pcData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pc_nonce'),
            'homeUrl' => home_url(),
            'apiUrl' => rest_url('painel-campanhas/v1/'),
        ]);

        // JavaScript específico para nova campanha
        if ($page === 'nova-campanha') {
            wp_enqueue_script('nova-campanha', $this->plugin_url . 'assets/js/nova-campanha.js', ['jquery', 'painel-campanhas'], $this->version, true);

            // Localize script para nova campanha
            wp_localize_script('nova-campanha', 'pcAjax', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pc_nonce'),
                'cmNonce' => wp_create_nonce('campaign-manager-nonce'),
                'csvNonce' => wp_create_nonce('pc_csv_download'),
                'adminPostUrl' => admin_url('admin-post.php'),
                'homeUrl' => home_url(),
            ]);
        }

        // Localize pcAjax para todas as páginas que precisam (configuracoes, etc)
        wp_localize_script('painel-campanhas', 'pcAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pc_nonce'),
            'cmNonce' => wp_create_nonce('campaign-manager-nonce'),
            'csvNonce' => wp_create_nonce('pc_csv_download'),
            'adminPostUrl' => admin_url('admin-post.php'),
            'homeUrl' => home_url(),
        ]);
    }

    public function handle_login()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        // Aceita tanto 'username' quanto 'email' para compatibilidade
        $username = sanitize_user($_POST['username'] ?? $_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] === '1';

        if (empty($username) || empty($password)) {
            wp_send_json_error(['message' => 'Usuário/e-mail e senha são obrigatórios']);
        }

        $creds = [
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember,
        ];

        $user = wp_signon($creds, is_ssl());

        if (is_wp_error($user)) {
            wp_send_json_error(['message' => $user->get_error_message() ?: 'Credenciais inválidas']);
        }

        wp_send_json_success([
            'message' => 'Login realizado com sucesso',
            'redirect' => home_url('/painel/home'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ],
        ]);
    }

    public function handle_logout()
    {
        // Não verifica nonce pois o usuário pode já ter sessão expirada
        // check_ajax_referer('pc_nonce', 'nonce');

        // Limpa todos os cookies de autenticação do WordPress
        wp_clear_auth_cookie();

        // Faz logout do WordPress
        wp_logout();

        // Destroi a sessão PHP se existir
        if (session_id()) {
            session_destroy();
        }

        wp_send_json_success(['redirect' => wp_login_url()]);
    }

    public function handle_save_master_api_key()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $master_api_key = sanitize_text_field($_POST['master_api_key'] ?? '');
        update_option('acm_master_api_key', $master_api_key);

        wp_send_json_success(['message' => 'Master API Key salva com sucesso!']);
    }

    public function handle_get_master_api_key()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $master_api_key = get_option('acm_master_api_key', '');

        wp_send_json_success(['master_api_key' => $master_api_key]);
    }

    // ========== HANDLERS PARA API MANAGER ==========

    public function handle_save_microservice_config()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $config = [
            'url' => esc_url_raw($_POST['microservice_url'] ?? ''),
            'api_key' => sanitize_text_field($_POST['microservice_api_key'] ?? '')
        ];

        update_option('acm_microservice_config', $config);

        wp_send_json_success(['message' => 'Configuração do microserviço salva com sucesso!']);
    }

    public function handle_save_static_credentials()
    {
        try {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Acesso negado');
                return;
            }

            check_ajax_referer('pc_nonce', 'nonce');

            $static_data_raw = $_POST['static_credentials'] ?? null;

            // Log para debug
            error_log('🔵 [Save Static Creds] Tipo recebido: ' . gettype($static_data_raw));
            if (is_string($static_data_raw)) {
                error_log('🔵 [Save Static Creds] String recebida (primeiros 200 chars): ' . substr($static_data_raw, 0, 200));
            }

            // O frontend pode enviar como JSON string ou como array nativo
            $static_data = [];
            if (is_array($static_data_raw)) {
                $static_data = $static_data_raw;
                error_log('🔵 [Save Static Creds] Dados recebidos como array nativo');
            } elseif (is_string($static_data_raw) && !empty($static_data_raw)) {
                // Remove slashes adicionados pelo WordPress
                $cleaned = stripslashes($static_data_raw);
                $decoded = json_decode($cleaned, true);
                $json_error = json_last_error();

                if ($json_error === JSON_ERROR_NONE && is_array($decoded)) {
                    $static_data = $decoded;
                    error_log('🔵 [Save Static Creds] JSON decodificado com sucesso. Campos: ' . implode(', ', array_keys($decoded)));
                } else {
                    error_log('🔴 [Save Static Creds] Erro ao decodificar JSON: ' . json_last_error_msg());
                    wp_send_json_error('Dados inválidos: não foi possível decodificar JSON - ' . json_last_error_msg());
                    return;
                }
            } else {
                error_log('🔴 [Save Static Creds] Dados vazios ou tipo inválido');
                wp_send_json_error('Dados inválidos: static_credentials não foi recebido');
                return;
            }

            if (!is_array($static_data)) {
                error_log('🔴 [Save Static Creds] static_data não é array após processamento');
                wp_send_json_error('Dados inválidos: static_credentials deve ser um array');
                return;
            }

            // IMPORTANTE: Busca credenciais existentes para fazer merge
            $existing_credentials = get_option('acm_static_credentials', []);
            if (!is_array($existing_credentials)) {
                $existing_credentials = [];
            }

            // SIMPLES: Começa com os valores existentes e só atualiza campos que foram enviados COM VALOR
            $static_credentials = $existing_credentials;

            // Lista de campos válidos
            $valid_fields = [
                'cda_api_url' => 'esc_url_raw',
                'cda_api_key' => 'sanitize_text_field',
                'sf_client_id' => 'sanitize_text_field',
                'sf_client_secret' => 'sanitize_text_field',
                'sf_username' => 'sanitize_text_field',
                'sf_password' => 'sanitize_text_field',
                'sf_token_url' => 'esc_url_raw',
                'sf_api_url' => 'esc_url_raw',
                'mkc_client_id' => 'sanitize_text_field',
                'mkc_client_secret' => 'sanitize_text_field',
                'mkc_token_url' => 'esc_url_raw',
                'mkc_api_url' => 'esc_url_raw',
                'mkc_auth_url' => 'esc_url_raw',
                'mkc_rest_url' => 'esc_url_raw',
                'mkc_account_id' => 'sanitize_text_field',
                'mkc_de_key' => 'sanitize_text_field',
                'rcs_chave_api' => 'sanitize_text_field',
                'rcs_base_url' => 'esc_url_raw',
                'rcs_token' => 'sanitize_text_field',
                'otima_wpp_token' => 'sanitize_text_field',
                'otima_wpp_customer_code' => 'sanitize_text_field',
                'otima_wpp_broker_code' => 'sanitize_text_field',
                'otima_rcs_token' => 'sanitize_text_field',
                'otima_rcs_customer_code' => 'sanitize_text_field',
                'gosac_oficial_token' => 'sanitize_text_field',
                'gosac_oficial_url' => 'esc_url_raw',
                'robbu_company' => 'sanitize_text_field',
                'robbu_username' => 'sanitize_text_field',
                'robbu_password' => 'sanitize_text_field',
                'robbu_invenio_token' => 'sanitize_text_field',
                'dashboard_password' => 'sanitize_text_field'
            ];

            // Atualiza APENAS campos que foram enviados E têm valor (não vazio)
            foreach ($valid_fields as $field => $sanitize_func) {
                if (isset($static_data[$field])) {
                    $raw_value = trim($static_data[$field]);

                    // Só atualiza se o valor não está vazio
                    if (!empty($raw_value)) {
                        if ($sanitize_func === 'esc_url_raw') {
                            $static_credentials[$field] = esc_url_raw($raw_value);
                        } else {
                            $static_credentials[$field] = sanitize_text_field($raw_value);
                        }
                    }
                    // Se está vazio, não faz nada (mantém o valor existente)
                }
            }

            // Garante que todos os campos válidos existam no array final (mesmo que vazios)
            foreach ($valid_fields as $field => $sanitize_func) {
                if (!isset($static_credentials[$field])) {
                    $static_credentials[$field] = '';
                }
            }

            // Valida que temos um array válido antes de salvar
            if (!is_array($static_credentials)) {
                wp_send_json_error('Erro ao processar credenciais');
                return;
            }

            // Log do que será salvo
            $campos_com_valor = [];
            foreach ($static_credentials as $key => $value) {
                if (!empty($value)) {
                    $campos_com_valor[] = $key . '=' . substr($value, 0, 20);
                }
            }

            error_log('🔵 [Save] Total de campos: ' . count($static_credentials));
            error_log('🔵 [Save] Campos COM valores: ' . implode(', ', $campos_com_valor));
            error_log('🔵 [Save] Total de campos COM valores: ' . count($campos_com_valor));

            // Salva SIMPLESMENTE
            $result = update_option('acm_static_credentials', $static_credentials);

            // Verifica se foi salvo
            $verificacao = get_option('acm_static_credentials', []);
            $campos_salvos = [];
            foreach ($verificacao as $key => $value) {
                if (!empty($value)) {
                    $campos_salvos[] = $key;
                }
            }
            error_log('✅ [Save] Verificação após salvar - Campos com valores: ' . implode(', ', $campos_salvos));
            error_log('✅ [Save] Total salvo com valores: ' . count($campos_salvos));

            // Verifica se realmente foi salvo (mesmo que update_option tenha retornado false)
            $saved_value = get_option('acm_static_credentials', []);
            $was_saved = is_array($saved_value);

            // Log dos campos salvos
            if ($was_saved) {
                $campos_salvos_com_valor = [];
                foreach ($saved_value as $key => $value) {
                    if (!empty($value)) {
                        $campos_salvos_com_valor[] = $key;
                    }
                }
                error_log('✅ [Save Static Creds] Campos salvos com valores: ' . implode(', ', $campos_salvos_com_valor));
                error_log('✅ [Save Static Creds] Total de campos salvos com valores: ' . count($campos_salvos_com_valor));
            }

            if (!$was_saved && count($static_credentials) > 0) {
                // Se não foi salvo e deveria ter sido, tenta com add_option
                $option_exists = get_option('acm_static_credentials') !== false;
                if (!$option_exists) {
                    add_option('acm_static_credentials', $static_credentials);
                    error_log('🔵 [Save Static Creds] Usando add_option (opção não existia)');
                } else {
                    error_log('🔴 [Save Static Creds] Aviso: update_option retornou false mas opção existe');
                }
            }

            error_log('✅ [Save Static Creds] Operação concluída. Result: ' . ($result ? 'true' : 'false'));

            // Salva também no option antigo para compatibilidade
            if (!empty($static_credentials['dashboard_password'])) {
                update_option('ga_dashboard_password', $static_credentials['dashboard_password']);
            }

            wp_send_json_success(['message' => 'Static credentials salvas com sucesso!']);

        } catch (Exception $e) {
            error_log('🔴 [Save Static Creds] Erro fatal: ' . $e->getMessage());
            error_log('🔴 [Save Static Creds] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error('Erro ao salvar credenciais: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('🔴 [Save Static Creds] Erro fatal: ' . $e->getMessage());
            error_log('🔴 [Save Static Creds] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error('Erro ao salvar credenciais: ' . $e->getMessage());
        }
    }

    public function handle_get_static_credentials()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $static_credentials = get_option('acm_static_credentials', []);

        if (!is_array($static_credentials)) {
            $static_credentials = [];
        }

        // Garante que todos os campos esperados existam (mesmo que vazios)
        $default_fields = [
            'cda_api_url',
            'cda_api_key',
            'sf_client_id',
            'sf_client_secret',
            'sf_username',
            'sf_password',
            'sf_token_url',
            'sf_api_url',
            'mkc_client_id',
            'mkc_client_secret',
            'mkc_token_url',
            'mkc_api_url',
            'mkc_auth_url',
            'mkc_rest_url',
            'mkc_account_id',
            'mkc_de_key',
            'rcs_chave_api',
            'rcs_base_url',
            'rcs_token',
            'otima_wpp_token',
            'otima_wpp_customer_code',
            'otima_wpp_broker_code',
            'otima_rcs_token',
            'otima_rcs_customer_code',
            'gosac_oficial_token',
            'gosac_oficial_url',
            'robbu_company',
            'robbu_username',
            'robbu_password',
            'robbu_invenio_token',
            'dashboard_password'
        ];

        foreach ($default_fields as $field) {
            if (!isset($static_credentials[$field])) {
                $static_credentials[$field] = '';
            }
        }

        wp_send_json_success($static_credentials);
    }

    public function handle_get_otima_customers()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider = sanitize_text_field($_POST['provider'] ?? 'rcs'); // 'rcs' ou 'wpp'

        $static_credentials = get_option('acm_static_credentials', []);

        // Para RCS usa otima_rcs_token, para WPP usa otima_wpp_token
        $token_field = $provider === 'wpp' ? 'otima_wpp_token' : 'otima_rcs_token';
        $token = trim($static_credentials[$token_field] ?? '');

        if (empty($token)) {
            wp_send_json_error('Token de autenticação não configurado. Configure o token nas credenciais estáticas primeiro.');
            return;
        }

        // Limpa o token (remove "Bearer " se já estiver presente)
        $token_clean = trim(preg_replace('/^Bearer\s+/i', '', $token));

        // Endpoint da API da Ótima
        $url = $provider === 'wpp'
            ? 'https://services.otima.digital/v1/whatsapp/customer'
            : 'https://services.otima.digital/v1/rcs/customer';

        error_log('🔵 [Ótima Customers] Buscando customers para provider: ' . $provider);
        error_log('🔵 [Ótima Customers] URL: ' . $url);
        error_log('🔵 [Ótima Customers] Token (primeiros 20 chars): ' . substr($token_clean, 0, 20) . '...');

        // Tenta primeiro com "Bearer " (formato padrão OAuth)
        // Se falhar com 400, pode ser que a API aceite apenas o token
        $auth_header = 'Bearer ' . $token_clean;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        // Se retornar 400, tenta sem "Bearer " (algumas APIs usam apenas o token)
        if (!is_wp_error($response)) {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 400 || $status_code === 401) {
                error_log('🟡 [Ótima Customers] Tentando sem "Bearer " prefix...');
                $response = wp_remote_get($url, [
                    'headers' => [
                        'Authorization' => $token_clean,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 30,
                ]);
            }
        }

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('🔴 [Ótima Customers] Erro ao buscar customers: ' . $error_message);
            wp_send_json_error('Erro ao buscar customers: ' . $error_message);
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        error_log('🔵 [Ótima Customers] Status Code: ' . $status_code);
        error_log('🔵 [Ótima Customers] Response Body (primeiros 500 chars): ' . substr($body, 0, 500));

        if ($status_code !== 200) {
            $error_details = 'Status: ' . $status_code;
            if (!empty($body)) {
                $error_data = json_decode($body, true);
                if (is_array($error_data) && isset($error_data['message'])) {
                    $error_details .= ' - ' . $error_data['message'];
                } elseif (is_array($error_data) && isset($error_data['error'])) {
                    $error_details .= ' - ' . $error_data['error'];
                } else {
                    $error_details .= ' - ' . substr($body, 0, 200);
                }
            }
            error_log('🔴 [Ótima Customers] Erro HTTP ' . $status_code . ': ' . $body);
            wp_send_json_error('Erro ao buscar customers. ' . $error_details);
            return;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            error_log('🔴 [Ótima Customers] Resposta inválida: ' . $body);
            wp_send_json_error('Resposta inválida da API');
            return;
        }

        // A API pode retornar um array de customer codes ou um objeto com uma propriedade
        // Vamos normalizar para sempre retornar um array
        $customers = [];
        if (isset($data['data']) && is_array($data['data'])) {
            $customers = $data['data'];
        } elseif (isset($data['customers']) && is_array($data['customers'])) {
            $customers = $data['customers'];
        } elseif (is_array($data)) {
            // Se já é um array, pode ser que seja diretamente a lista
            $customers = $data;
        }

        // Se os customers são objetos, extrai apenas os códigos
        $customer_codes = [];
        foreach ($customers as $customer) {
            if (is_string($customer)) {
                $customer_codes[] = $customer;
            } elseif (is_array($customer) && isset($customer['code'])) {
                $customer_codes[] = $customer['code'];
            } elseif (is_array($customer) && isset($customer['customer_code'])) {
                $customer_codes[] = $customer['customer_code'];
            } elseif (is_array($customer) && isset($customer['id'])) {
                $customer_codes[] = $customer['id'];
            }
        }

        error_log('✅ [Ótima Customers] Customer codes encontrados: ' . count($customer_codes));

        wp_send_json_success($customer_codes);
    }

    public function handle_create_credential()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider = sanitize_key($_POST['provider'] ?? '');
        $env_id = sanitize_text_field($_POST['env_id'] ?? '');
        $credential_data = $_POST['credential_data'] ?? [];

        // credential_data pode vir como JSON string ou como array nativo (credential_data[url], etc.)
        if (is_string($credential_data)) {
            $decoded = json_decode($credential_data, true);
            $credential_data = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($credential_data)) {
            $credential_data = [];
        }

        if (empty($provider) || empty($env_id) || empty($credential_data)) {
            wp_send_json_error('Dados incompletos');
            return;
        }

        $credentials = get_option('acm_provider_credentials', []);
        if (!is_array($credentials)) {
            $credentials = [];
        }

        // Sanitiza os dados da credencial
        $sanitized_data = [];
        foreach ($credential_data as $key => $value) {
            if ($key === 'url') {
                $sanitized_data[$key] = esc_url_raw($value);
            } elseif ($key === 'channel_ids') {
                if (is_array($value)) {
                    $sanitized_data[$key] = array_map('intval', array_filter($value, 'is_numeric'));
                } elseif (is_string($value)) {
                    $arr = json_decode($value, true);
                    $sanitized_data[$key] = is_array($arr) ? array_map('intval', array_filter($arr, 'is_numeric')) : [];
                } else {
                    $sanitized_data[$key] = [];
                }
            } elseif (is_array($value)) {
                $sanitized_data[$key] = array_map('sanitize_text_field', $value);
            } else {
                $sanitized_data[$key] = sanitize_text_field($value);
            }
        }

        if (!isset($credentials[$provider])) {
            $credentials[$provider] = [];
        }

        $credentials[$provider][$env_id] = $sanitized_data;
        update_option('acm_provider_credentials', $credentials);

        wp_send_json_success(['message' => 'Credencial criada com sucesso!']);
    }

    public function handle_get_credential()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider = sanitize_key($_POST['provider'] ?? '');
        $env_id = sanitize_text_field($_POST['env_id'] ?? '');

        if (empty($provider) || empty($env_id)) {
            wp_send_json_error('Provider e Environment ID são obrigatórios');
            return;
        }

        $credentials = get_option('acm_provider_credentials', []);

        if (!isset($credentials[$provider][$env_id])) {
            wp_send_json_error('Credencial não encontrada');
            return;
        }

        wp_send_json_success(['data' => $credentials[$provider][$env_id]]);
    }

    public function handle_list_credentials()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $credentials = get_option('acm_provider_credentials', []);
        if (!is_array($credentials)) {
            $credentials = [];
        }

        // Formata para o frontend: lista todas as credenciais com provider e env_id
        $formatted = [];
        foreach ($credentials as $provider => $envs) {
            if (is_array($envs)) {
                foreach ($envs as $env_id => $credential_data) {
                    $formatted[] = [
                        'provider' => $provider,
                        'env_id' => $env_id,
                        'data' => $credential_data,
                    ];
                }
            }
        }

        wp_send_json_success($formatted);
    }

    public function handle_update_credential()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider = sanitize_key($_POST['provider'] ?? '');
        $env_id = sanitize_text_field($_POST['env_id'] ?? '');
        $credential_data = $_POST['credential_data'] ?? [];

        // credential_data pode vir como JSON string ou como array nativo (credential_data[url], etc.)
        if (is_string($credential_data)) {
            $decoded = json_decode($credential_data, true);
            $credential_data = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($credential_data)) {
            $credential_data = [];
        }

        if (empty($provider) || empty($env_id) || empty($credential_data)) {
            wp_send_json_error('Dados incompletos');
            return;
        }

        $credentials = get_option('acm_provider_credentials', []);

        if (!isset($credentials[$provider][$env_id])) {
            wp_send_json_error('Credencial não encontrada');
            return;
        }

        // Sanitiza os dados da credencial
        $sanitized_data = [];
        foreach ($credential_data as $key => $value) {
            if ($key === 'url') {
                $sanitized_data[$key] = esc_url_raw($value);
            } elseif ($key === 'channel_ids') {
                if (is_array($value)) {
                    $sanitized_data[$key] = array_map('intval', array_filter($value, 'is_numeric'));
                } elseif (is_string($value)) {
                    $arr = json_decode($value, true);
                    $sanitized_data[$key] = is_array($arr) ? array_map('intval', array_filter($arr, 'is_numeric')) : [];
                } else {
                    $sanitized_data[$key] = [];
                }
            } elseif (is_array($value)) {
                $sanitized_data[$key] = array_map('sanitize_text_field', $value);
            } else {
                $sanitized_data[$key] = sanitize_text_field($value);
            }
        }

        $credentials[$provider][$env_id] = $sanitized_data;
        update_option('acm_provider_credentials', $credentials);

        wp_send_json_success(['message' => 'Credencial atualizada com sucesso!']);
    }

    // ========== HANDLERS PARA CUSTOM PROVIDERS ==========

    public function handle_list_custom_providers()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $custom_providers = get_option('acm_custom_providers', []);
        if (!is_array($custom_providers)) {
            $custom_providers = [];
        }

        // Formata para o formato esperado pelo frontend
        $formatted = [];
        foreach ($custom_providers as $key => $provider) {
            $formatted[] = [
                'key' => $key,
                'name' => $provider['name'] ?? $key,
                'requires_credentials' => $provider['requires_credentials'] ?? false,
                'credential_fields' => $provider['credential_fields'] ?? [],
            ];
        }

        wp_send_json_success($formatted);
    }

    public function handle_create_custom_provider()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider_key = sanitize_key($_POST['provider_key'] ?? '');
        $provider_name = sanitize_text_field($_POST['provider_name'] ?? '');
        $json_template = $_POST['json_template'] ?? '{}';
        $requires_credentials = isset($_POST['requires_credentials']) ? (bool) $_POST['requires_credentials'] : false;
        $credential_fields = $_POST['credential_fields'] ?? [];

        if (empty($provider_key) || empty($provider_name)) {
            wp_send_json_error('Provider key e name são obrigatórios');
            return;
        }

        // Valida JSON template
        $template_decoded = json_decode($json_template, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('JSON template inválido: ' . json_last_error_msg());
            return;
        }

        $custom_providers = get_option('acm_custom_providers', []);
        if (!is_array($custom_providers)) {
            $custom_providers = [];
        }

        // Verifica se já existe
        if (isset($custom_providers[$provider_key])) {
            wp_send_json_error('Provider com esta chave já existe');
            return;
        }

        // Sanitiza credential_fields
        $sanitized_fields = [];
        if (is_array($credential_fields)) {
            foreach ($credential_fields as $field) {
                $sanitized_fields[] = sanitize_key($field);
            }
        }

        $custom_providers[$provider_key] = [
            'name' => $provider_name,
            'json_template' => $template_decoded,
            'requires_credentials' => $requires_credentials,
            'credential_fields' => $sanitized_fields,
        ];

        update_option('acm_custom_providers', $custom_providers);

        wp_send_json_success(['message' => 'Provider customizado criado com sucesso!']);
    }

    public function handle_get_custom_provider()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider_key = sanitize_key($_POST['provider_key'] ?? '');

        if (empty($provider_key)) {
            wp_send_json_error('Provider key é obrigatório');
            return;
        }

        $custom_providers = get_option('acm_custom_providers', []);
        if (!is_array($custom_providers) || !isset($custom_providers[$provider_key])) {
            wp_send_json_error('Provider não encontrado');
            return;
        }

        $provider = $custom_providers[$provider_key];
        wp_send_json_success([
            'key' => $provider_key,
            'name' => $provider['name'] ?? '',
            'json_template' => $provider['json_template'] ?? [],
            'requires_credentials' => $provider['requires_credentials'] ?? false,
            'credential_fields' => $provider['credential_fields'] ?? [],
        ]);
    }

    public function handle_update_custom_provider()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider_key = sanitize_key($_POST['provider_key'] ?? '');
        $provider_name = sanitize_text_field($_POST['provider_name'] ?? '');
        $json_template = $_POST['json_template'] ?? '{}';
        $requires_credentials = isset($_POST['requires_credentials']) ? (bool) $_POST['requires_credentials'] : false;
        $credential_fields = $_POST['credential_fields'] ?? [];

        if (empty($provider_key) || empty($provider_name)) {
            wp_send_json_error('Provider key e name são obrigatórios');
            return;
        }

        $custom_providers = get_option('acm_custom_providers', []);
        if (!is_array($custom_providers) || !isset($custom_providers[$provider_key])) {
            wp_send_json_error('Provider não encontrado');
            return;
        }

        // Valida JSON template
        $template_decoded = json_decode($json_template, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('JSON template inválido: ' . json_last_error_msg());
            return;
        }

        // Sanitiza credential_fields
        $sanitized_fields = [];
        if (is_array($credential_fields)) {
            foreach ($credential_fields as $field) {
                $sanitized_fields[] = sanitize_key($field);
            }
        }

        $custom_providers[$provider_key] = [
            'name' => $provider_name,
            'json_template' => $template_decoded,
            'requires_credentials' => $requires_credentials,
            'credential_fields' => $sanitized_fields,
        ];

        update_option('acm_custom_providers', $custom_providers);

        wp_send_json_success(['message' => 'Provider customizado atualizado com sucesso!']);
    }

    public function handle_delete_custom_provider()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider_key = sanitize_key($_POST['provider_key'] ?? '');

        if (empty($provider_key)) {
            wp_send_json_error('Provider key é obrigatório');
            return;
        }

        $custom_providers = get_option('acm_custom_providers', []);
        if (!is_array($custom_providers) || !isset($custom_providers[$provider_key])) {
            wp_send_json_error('Provider não encontrado');
            return;
        }

        unset($custom_providers[$provider_key]);
        update_option('acm_custom_providers', $custom_providers);

        wp_send_json_success(['message' => 'Provider customizado deletado com sucesso!']);
    }

    public function handle_delete_credential()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $provider = sanitize_key($_POST['provider'] ?? '');
        $env_id = sanitize_text_field($_POST['env_id'] ?? '');

        if (empty($provider) || empty($env_id)) {
            wp_send_json_error('Provider e Environment ID são obrigatórios');
            return;
        }

        $credentials = get_option('acm_provider_credentials', []);

        if (isset($credentials[$provider][$env_id])) {
            unset($credentials[$provider][$env_id]);
            update_option('acm_provider_credentials', $credentials);
            wp_send_json_success(['message' => 'Credencial deletada com sucesso!']);
        } else {
            wp_send_json_error('Credencial não encontrada');
        }
    }

    public function handle_cpf_upload_csv()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('Nenhum arquivo enviado');
        }

        $match_field = sanitize_text_field($_POST['match_field'] ?? '');
        if (!in_array($match_field, ['cpf', 'telefone'], true)) {
            wp_send_json_error('Tipo de cruzamento inválido');
        }

        $file = $_FILES['csv_file'];

        // Validações básicas
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Erro no upload do arquivo: ' . $file['error']);
        }

        // Valida extensão do arquivo
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'csv') {
            wp_send_json_error('Apenas arquivos CSV são permitidos');
        }

        // Valida tamanho
        if ($file['size'] > 10 * 1024 * 1024) { // 10MB
            wp_send_json_error('Arquivo muito grande (máx 10MB)');
        }

        // Lê o arquivo
        if (!is_uploaded_file($file['tmp_name'])) {
            wp_send_json_error('Arquivo inválido ou não foi enviado corretamente');
        }

        $content = file_get_contents($file['tmp_name']);
        $lines = array_filter(array_map('trim', explode("\n", $content)));

        if (empty($lines)) {
            wp_send_json_error('Arquivo CSV vazio');
        }

        // Remove primeira linha se for cabeçalho (verifica se contém palavras como NOME, TELEFONE, CPF)
        $first_line = strtoupper($lines[0]);
        if (strpos($first_line, 'NOME') !== false || strpos($first_line, 'TELEFONE') !== false || strpos($first_line, 'CPF') !== false) {
            array_shift($lines); // Remove cabeçalho
        }

        // Extrai valores do CSV (formato: NOME;TELEFONE;CPF ou similar)
        $values = [];
        foreach ($lines as $line) {
            // Remove espaços e quebras de linha
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Divide por ponto e vírgula ou vírgula
            $columns = preg_split('/[;,]/', $line);
            $columns = array_map('trim', $columns);

            if ('cpf' === $match_field) {
                // Procura CPF nas colunas (geralmente última ou terceira)
                // Tenta encontrar um valor com 11 dígitos
                foreach ($columns as $col) {
                    $value = preg_replace('/[^0-9]/', '', $col);
                    if (strlen($value) === 11) {
                        $values[] = $value;
                        break; // Encontrou, passa para próxima linha
                    }
                }
            } else {
                // Procura telefone nas colunas (geralmente segunda coluna)
                // Tenta encontrar um valor com 10 ou 11 dígitos
                foreach ($columns as $col) {
                    $value = preg_replace('/[^0-9]/', '', $col);
                    $length = strlen($value);
                    // Telefone pode ter de 10 até 13 dígitos (com ou sem 55, com ou sem nono dígito)
                    if ($length >= 10 && $length <= 13) {
                        $values[] = $value;
                        break; // Encontrou, passa para próxima linha
                    }
                }
            }
        }

        $values = array_values(array_unique($values));

        if (empty($values)) {
            wp_send_json_error('Nenhum dado válido encontrado no arquivo');
        }

        // Salva temporariamente
        $uploads_dir = wp_upload_dir()['basedir'] . '/cpf-campaigns/';
        if (!file_exists($uploads_dir)) {
            wp_mkdir_p($uploads_dir);
            file_put_contents($uploads_dir . '.htaccess', 'deny from all');
        }

        $temp_id = uniqid('cpf_', true);
        $temp_file = $uploads_dir . $temp_id . '.json';
        $payload = [
            'match_field' => $match_field,
            'values' => $values
        ];
        file_put_contents($temp_file, wp_json_encode($payload));

        wp_send_json_success([
            'temp_id' => $temp_id,
            'count' => count($values),
            'preview' => array_slice($values, 0, 5),
            'match_field' => $match_field
        ]);
    }

    public function handle_cpf_get_custom_filters()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) {
            wp_send_json_error('Tabela não especificada ou inválida');
            return;
        }

        // Filtros permitidos
        $allowed_filters = [
            'STATUS_TELEFONE' => 'categorical',
            'VISAO_CPF_V8' => 'categorical',
            'SCORE_V8' => 'categorical',
        ];

        global $wpdb;
        $filters = [];

        foreach ($allowed_filters as $column => $type) {
            // Verifica se a coluna existe
            $column_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_name,
                $column
            ));

            if ($column_exists) {
                // Busca valores únicos
                $values = $wpdb->get_col(
                    "SELECT DISTINCT `{$column}` 
                     FROM `{$table_name}` 
                     WHERE `{$column}` IS NOT NULL 
                     AND `{$column}` != '' 
                     ORDER BY `{$column}` ASC
                     LIMIT 100"
                );

                if (!empty($values)) {
                    $filters[$column] = [
                        'type' => $type,
                        'values' => $values
                    ];
                }
            }
        }

        wp_send_json_success($filters);
    }

    public function handle_cpf_preview_count()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $table_name = sanitize_text_field($_POST['table_name'] ?? '');
        $temp_id = sanitize_text_field($_POST['temp_id'] ?? '');
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);

        if (empty($table_name) || empty($temp_id)) {
            wp_send_json_error('Dados incompletos');
        }

        $temp_payload = $this->load_cpf_temp_payload($temp_id);
        if (empty($temp_payload)) {
            wp_send_json_error('Arquivo temporário não encontrado');
        }

        $values = $temp_payload['values'];
        $match_field = $temp_payload['match_field'] ?? 'cpf';

        $show_already_sent = isset($_POST['show_already_sent']) ? intval($_POST['show_already_sent']) : 0;

        // Busca registros usando o método que remove duplicatas
        // Assim a contagem será precisa (sem duplicatas)
        global $wpdb;
        $records = $this->get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent);
        $count = count($records);

        wp_send_json_success(['count' => intval($count)]);
    }

    public function handle_cpf_generate_clean_file()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table_name = sanitize_text_field($_POST['table_name'] ?? '');
        $temp_id = sanitize_text_field($_POST['temp_id'] ?? '');
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);

        if (empty($table_name) || empty($temp_id)) {
            wp_send_json_error('Dados incompletos');
        }

        $temp_payload = $this->load_cpf_temp_payload($temp_id);
        if (empty($temp_payload)) {
            wp_send_json_error('Arquivo temporário não encontrado');
        }

        $values = $temp_payload['values'];
        $match_field = $temp_payload['match_field'] ?? 'cpf';

        $show_already_sent = isset($_POST['show_already_sent']) ? intval($_POST['show_already_sent']) : 0;
        $records = $this->get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent);

        if (empty($records)) {
            wp_send_json_error('Nenhum registro encontrado');
        }

        $csv = $this->build_cpf_clean_csv($records);
        $filename = 'cpf-campaign-' . current_time('YmdHis') . '.csv';

        wp_send_json_success([
            'file' => base64_encode($csv),
            'filename' => $filename
        ]);
    }

    private function load_cpf_temp_payload($temp_id)
    {
        $uploads_dir = wp_upload_dir()['basedir'] . '/cpf-campaigns/';
        $temp_file = $uploads_dir . $temp_id . '.json';
        if (!file_exists($temp_file)) {
            return null;
        }

        $payload = json_decode(file_get_contents($temp_file), true);
        if (empty($payload['values'])) {
            return null;
        }

        return $payload;
    }

    private function get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent = 0)
    {
        error_log('🔵 [get_cpf_records] Efetuando busca na tabela: ' . $table_name . ' | Match Field: ' . $match_field . ' | Values count: ' . count($values));
        if (count($values) > 0) {
            error_log('🔵 [get_cpf_records] Amostra de valores: ' . implode(',', array_slice($values, 0, 3)));
        }

        // Dinamicamente resolve os nomes das colunas preservando o casing original
        $columns_raw = (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        $all_columns_upper = array_map('strtoupper', $columns_raw);
        $col_map = array_combine($all_columns_upper, $columns_raw);

        $col_phone = isset($col_map['TELEFONE']) ? $col_map['TELEFONE'] : (isset($col_map['CELULAR']) ? $col_map['CELULAR'] : (isset($col_map['PHONE']) ? $col_map['PHONE'] : 'TELEFONE'));
        $col_cpf = isset($col_map['CPF']) ? $col_map['CPF'] : (isset($col_map['CPF_CNPJ']) ? $col_map['CPF_CNPJ'] : (isset($col_map['DOCUMENTO']) ? $col_map['DOCUMENTO'] : 'CPF'));
        $col_nome = isset($col_map['NOME']) ? $col_map['NOME'] : (isset($col_map['CLIENTE']) ? $col_map['CLIENTE'] : 'NOME');
        $col_contrato = isset($col_map['IDCOB_CONTRATO']) ? $col_map['IDCOB_CONTRATO'] : (isset($col_map['CONTRATO']) ? $col_map['CONTRATO'] : 'IDCOB_CONTRATO');
        $col_ambiente = isset($col_map['IDGIS_AMBIENTE']) ? $col_map['IDGIS_AMBIENTE'] : (isset($col_map['AMBIENTE']) ? $col_map['AMBIENTE'] : 'IDGIS_AMBIENTE');

        error_log("[get_cpf_records] Colunas resolvidas - Fone: $col_phone, CPF: $col_cpf, Nome: $col_nome");

        $where_sql = $this->build_cpf_where_sql($wpdb, 't', $values, $filters, $match_field, $show_already_sent, [
            'phone' => $col_phone,
            'cpf' => $col_cpf
        ]);
        $envios_table = $wpdb->prefix . 'envios_pendentes';

        if (!$show_already_sent) {
            $mysql_version = $wpdb->get_var("SELECT VERSION()");
            if (version_compare($mysql_version, '8.0.0', '<')) {
                // Versão compatível para MySQL < 8.0 (usando LIKE e REPLACE simples)
                $join_sql = "
                    LEFT JOIN {$envios_table} c ON (
                        c.telefone = t.`{$col_phone}`
                        OR c.telefone LIKE CONCAT('%', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.`{$col_phone}`, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '%')
                        OR t.`{$col_phone}` LIKE CONCAT('%', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.telefone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '%')
                    )
                    AND CAST(c.data_disparo AS DATE) BETWEEN DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) AND CURRENT_DATE
                    AND c.status IN ('enviado', 'pendente', 'pendente_aprovacao')
                ";
            } else {
                // Usa REGEXP_REPLACE (MySQL 8.0+)
                $join_sql = "
                    LEFT JOIN {$envios_table} c ON (
                        REGEXP_REPLACE(c.telefone, '[^0-9]', '') = REGEXP_REPLACE(t.`{$col_phone}`, '[^0-9]', '')
                        OR
                        (LENGTH(REGEXP_REPLACE(c.telefone, '[^0-9]', '')) > 11 
                         AND SUBSTRING(REGEXP_REPLACE(c.telefone, '[^0-9]', ''), 1, 2) = '55'
                         AND SUBSTRING(REGEXP_REPLACE(c.telefone, '[^0-9]', ''), 3) = REGEXP_REPLACE(t.`{$col_phone}`, '[^0-9]', ''))
                        OR
                        (LENGTH(REGEXP_REPLACE(t.`{$col_phone}`, '[^0-9]', '')) > 11 
                         AND SUBSTRING(REGEXP_REPLACE(t.`{$col_phone}`, '[^0-9]', ''), 1, 2) = '55'
                         AND SUBSTRING(REGEXP_REPLACE(t.`{$col_phone}`, '[^0-9]', ''), 3) = REGEXP_REPLACE(c.telefone, '[^0-9]', ''))
                    )
                    AND CAST(c.data_disparo AS DATE) BETWEEN DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) AND CURRENT_DATE
                    AND c.status IN ('enviado', 'pendente', 'pendente_aprovacao')
                ";
            }
            $where_sql .= " AND c.telefone IS NULL";
        } else {
            $join_sql = "";
        }

        // Usa DISTINCT e GROUP BY para evitar duplicatas baseado em telefone + CPF
        $sql = "SELECT 
                    t.`{$col_nome}` as nome,
                    t.`{$col_phone}` as telefone,
                    t.`{$col_cpf}` as cpf_cnpj,
                    t.`{$col_contrato}` as idcob_contrato,
                    t.`{$col_ambiente}` as idgis_ambiente
                FROM `{$table_name}` t
                {$join_sql}
                {$where_sql}
                GROUP BY t.`{$col_phone}`, t.`{$col_cpf}`, t.`{$col_nome}`, t.`{$col_contrato}`, t.`{$col_ambiente}`";

        error_log('[get_cpf_records] SQL: ' . $sql);

        $records = $wpdb->get_results($sql, ARRAY_A);
        error_log('[get_cpf_records] Registros encontrados: ' . (is_array($records) ? count($records) : 0));

        // Remove duplicatas adicionais baseado em telefone normalizado + CPF
        // Isso garante que mesmo com formatações diferentes, não teremos duplicatas
        $seen = [];
        $unique_records = [];
        if (is_array($records)) {
            foreach ($records as $record) {
                // Normaliza telefone
                $phone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
                if (strlen($phone) > 11 && substr($phone, 0, 2) === '55') {
                    $phone = substr($phone, 2);
                }
                // Normaliza CPF
                $cpf = preg_replace('/[^0-9]/', '', $record['cpf_cnpj'] ?? '');
                // Cria chave única
                $key = $phone . '_' . $cpf;

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    // Garante que idgis_ambiente seja int
                    $record['idgis_ambiente'] = isset($record['idgis_ambiente']) ? intval($record['idgis_ambiente']) : 0;
                    $unique_records[] = $record;
                }
            }
        }

        return $unique_records;
    }

    private function build_cpf_clean_csv($records)
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['nome', 'telefone', 'cpf', 'idcob_contrato'], ';');

        foreach ($records as $record) {
            $phone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
            if (strlen($phone) > 11 && substr($phone, 0, 2) === '55') {
                $phone = substr($phone, 2);
            }

            fputcsv($handle, [
                $record['nome'] ?? '',
                $phone,
                $record['cpf_cnpj'] ?? '',
                $record['idcob_contrato'] ?? ''
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function build_cpf_where_sql($wpdb, $table_alias, $values, $filters, $match_field, $show_already_sent = 0, $resolved_columns = [])
    {
        $where_clauses = ['1=1'];
        $col_phone = $resolved_columns['phone'] ?? 'TELEFONE';
        $col_cpf = $resolved_columns['cpf'] ?? 'CPF';

        // Condição de matching (CPF ou telefone)
        if (empty($values)) {
            $where_clauses[] = '0=1'; // Retorna nada se não houver valores
        } else {
            $where_clauses[] = $this->build_cpf_match_condition($wpdb, $values, $match_field, $col_phone, $col_cpf, $table_alias);
        }

        // Adiciona filtros adicionais
        if (!empty($filters) && is_array($filters)) {
            foreach ($filters as $column => $filter_values) {
                if (empty($filter_values) || !is_array($filter_values)) {
                    continue;
                }

                $sanitized_column = esc_sql($column);
                $placeholders = implode(',', array_fill(0, count($filter_values), '%s'));
                $where_clauses[] = $wpdb->prepare(
                    "{$table_alias}.`{$sanitized_column}` IN ($placeholders)",
                    ...array_values($filter_values)
                );
            }
        }

        return 'WHERE ' . implode(' AND ', $where_clauses);
    }

    private function build_cpf_match_condition($wpdb, $values, $match_field, $col_phone = 'TELEFONE', $col_cpf = 'CPF', $table_alias = 't')
    {
        if (empty($values)) {
            return '0=1';
        }

        $placeholders = implode(',', array_fill(0, count($values), '%s'));

        if ('telefone' === $match_field) {
            // Normaliza telefone no SQL (remove caracteres especiais)
            $normalized_phone = $this->normalize_phone_sql("{$table_alias}.`$col_phone`");
            // Normaliza valores também (remove 55 do início se existir)
            $normalized_values = array_map(function ($val) {
                $val = preg_replace('/[^0-9]/', '', $val);
                if (strlen($val) > 11 && substr($val, 0, 2) === '55') {
                    $val = substr($val, 2);
                }
                return $val;
            }, $values);

            return $wpdb->prepare(
                "{$normalized_phone} IN ($placeholders)",
                ...array_values($normalized_values)
            );
        }

        // Para CPF, remove pontos, traços e barras
        $normalized_cpf = "REPLACE(REPLACE(REPLACE({$table_alias}.`$col_cpf`, '.', ''), '-', ''), '/', '')";
        return $wpdb->prepare(
            "{$normalized_cpf} IN ($placeholders)",
            ...array_values($values)
        );
    }

    private function normalize_phone_sql($column)
    {
        $expr = $column;
        $chars = ['.', '-', '/', '(', ')', ' ', '+'];
        foreach ($chars as $char) {
            $expr = "REPLACE({$expr}, '{$char}', '')";
        }
        return $expr;
    }

    public function handle_create_cpf_campaign()
    {
        try {
            check_ajax_referer('pc_nonce', 'nonce');
            if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }

            global $wpdb;
            $table_name = sanitize_text_field($_POST['table_name'] ?? '');
            $temp_id = sanitize_text_field($_POST['temp_id'] ?? '');
            $match_field = sanitize_text_field($_POST['match_field'] ?? 'cpf');
            $template_id = intval($_POST['template_id'] ?? 0);
            $template_code = sanitize_text_field($_POST['template_code'] ?? '');
            $template_source = sanitize_text_field($_POST['template_source'] ?? 'local');
            $broker_code = sanitize_text_field($_POST['broker_code'] ?? '');
            $customer_code = sanitize_text_field($_POST['customer_code'] ?? '');
            $noah_channel_id = intval($_POST['noah_channel_id'] ?? 0);
            $noah_template_id = intval($_POST['noah_template_id'] ?? 0);
            $noah_language = sanitize_text_field($_POST['noah_language'] ?? 'pt_BR');
            $filters_json = stripslashes($_POST['filters'] ?? '[]');
            $filters = json_decode($filters_json, true);
            $providers_config_json = stripslashes($_POST['providers_config'] ?? '{}');
            $providers_config = json_decode($providers_config_json, true);
            $variables_map_json = stripslashes($_POST['variables_map'] ?? '{}');
            $variables_map = json_decode($variables_map_json, true);
            $carteira = sanitize_text_field($_POST['carteira'] ?? '');

            // id_carteira da carteira selecionada (herança para todos os registros da fila)
            $campaign_id_carteira = '';
            if (!empty($carteira)) {
                $campaign_id_carteira = $this->resolve_id_carteira_from_carteira_id($carteira);
            }

            $is_template_ok = ($template_source === 'local' && $template_id > 0)
                || (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code))
                || (($template_source === 'gosac_oficial' || $template_source === 'noah_oficial' || $template_source === 'robbu_oficial') && !empty($template_code));

            if (empty($table_name) || empty($temp_id) || !$is_template_ok || empty($providers_config['providers'])) {
                error_log('🔴 [handle_create_cpf_campaign] Dados incompletos: table_name=' . $table_name . ', temp_id=' . $temp_id . ', template_source=' . $template_source . ', template_code=' . $template_code . ', template_id=' . $template_id . ', providers_count=' . (is_array($providers_config['providers'] ?? null) ? count($providers_config['providers']) : 0));
                wp_send_json_error('Dados incompletos');
            }

            // Carrega template
            if ($template_source === 'local') {
                $template = get_post($template_id);
                if (!$template || $template->post_type !== 'message_template') {
                    wp_send_json_error('Template inválido');
                }
                $message_content = $template->post_content;
            } elseif ($template_source === 'gosac_oficial') {
                $message_content = 'Template GOSAC Oficial: ' . $template_code;
            } elseif ($template_source === 'noah_oficial') {
                $message_content = 'Template NOAH Oficial: ' . $template_code;
            } elseif ($template_source === 'robbu_oficial') {
                $message_content = 'Template Robbu Oficial: ' . $template_code;
            } else {
                // Templates da Ótima usam template_code
                $message_content = 'Template da Ótima: ' . $template_code;
            }

            // Carrega arquivo temporário
            $uploads_dir = wp_upload_dir()['basedir'] . '/cpf-campaigns/';
            $temp_file = $uploads_dir . $temp_id . '.json';
            if (!file_exists($temp_file)) {
                wp_send_json_error('Arquivo temporário não encontrado');
            }
            $temp_payload = json_decode(file_get_contents($temp_file), true);
            $values = $temp_payload['values'] ?? [];

            $show_already_sent = isset($_POST['show_already_sent']) ? intval($_POST['show_already_sent']) : 0;

            // Busca registros usando o método que já remove duplicatas
            $records = $this->get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent);

            if (empty($records)) {
                wp_send_json_error('Nenhum registro encontrado');
            }

            // 🎣 ISCAS - Adiciona iscas ativas se solicitado
            $include_baits = isset($_POST['include_baits']) ? intval($_POST['include_baits']) : 0;
            $baits_added = 0;

            if ($include_baits) {
                $table_iscas = $wpdb->prefix . 'cm_baits';
                $iscas = $wpdb->get_results(
                    "SELECT * FROM $table_iscas WHERE ativo = 1",
                    ARRAY_A
                );

                if (!empty($iscas)) {
                    foreach ($iscas as $isca) {
                        // Iscas: SEMPRE usa a carteira selecionada na campanha (primeira tela)
                        if (!empty($campaign_id_carteira)) {
                            $isca_id_carteira = $campaign_id_carteira;
                        } else {
                            $isca_id_carteira = $isca['id_carteira'] ?? '';
                            if (!empty($isca_id_carteira)) {
                                $resolved = $this->resolve_id_carteira_from_carteira_id($isca_id_carteira);
                                if (!empty($resolved)) {
                                    $isca_id_carteira = $resolved;
                                }
                            }
                        }
                        $isca_record = [
                            'telefone' => $isca['telefone'],
                            'nome' => $isca['nome'],
                            'cpf_cnpj' => $isca['cpf'] ?? '',
                            'idgis_ambiente' => $isca['idgis_ambiente'] ?? 0,
                            'id_carteira' => $isca_id_carteira,
                            'idcob_contrato' => 0,
                        ];
                        $records[] = $isca_record;
                        $baits_added++;
                    }
                    error_log("🎣 Iscas: Adicionados $baits_added registros de iscas na campanha por arquivo");
                }
            }

            // Distribui entre provedores
            $distributed_records = $this->distribute_records($records, $providers_config);

            // Insere na tabela envios_pendentes
            $envios_table = $wpdb->prefix . 'envios_pendentes';
            $current_user_id = get_current_user_id();
            $agendamento_base_id = date('YmdHis', current_time('timestamp'));
            $total_inserted = 0;

            if (empty($wpdb->get_results("SHOW COLUMNS FROM {$envios_table} LIKE 'carteira_id'"))) {
                $wpdb->query("ALTER TABLE {$envios_table} ADD COLUMN carteira_id bigint(20) DEFAULT NULL");
            }

            foreach ($distributed_records as $provider_data) {
                $provider = $provider_data['provider'];
                $provider_records = $provider_data['records'];
                $prefix = strtoupper(substr($provider, 0, 1));
                // CDA_RCS usa prefixo R (RCS) para buscar credenciais RCS (URL importarcs), não CDA (URL importar/campanha)
                if ($provider === 'CDA_RCS') {
                    $prefix = 'R';
                }
                $agendamento_id = $prefix . $agendamento_base_id;

                foreach ($provider_records as $record) {
                    $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
                    if (strlen($telefone) > 11 && substr($telefone, 0, 2) === '55') {
                        $telefone = substr($telefone, 2);
                    }

                    // Para templates Ótima/GOSAC/NOAH, não substitui placeholders (será resolvido no microserviço)
                    if (in_array($template_source, ['otima_wpp', 'otima_rcs', 'gosac_oficial', 'noah_oficial', 'robbu_oficial'])) {
                        $mensagem_final = $message_content;
                    } else {
                        $mensagem_final = $this->replace_placeholders($message_content, $record);
                    }

                    // Busca id_carteira: registro, coluna carteira do CSV, ou herda da carteira selecionada
                    $id_carteira = $record['id_carteira'] ?? '';
                    if (empty($id_carteira) && !empty($record['carteira'])) {
                        $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';
                        $carteira_row = $wpdb->get_row($wpdb->prepare(
                            "SELECT id_carteira FROM $carteiras_table WHERE nome = %s AND ativo = 1 LIMIT 1",
                            $record['carteira']
                        ), ARRAY_A);
                        if ($carteira_row) {
                            $id_carteira = $carteira_row['id_carteira'];
                        }
                    }
                    if (empty($id_carteira) && !empty($campaign_id_carteira)) {
                        $id_carteira = $campaign_id_carteira;
                    }

                    // carteira_id: id interno da carteira selecionada (para GOSAC: lookup correto quando há múltiplas com mesmo id_carteira)
                    $carteira_id_insert = !empty($carteira) && ($id_carteira === $campaign_id_carteira) ? intval($carteira) : 0;

                    // Para templates da Ótima, GOSAC ou NOAH, armazena JSON no campo mensagem
                    $mensagem_para_armazenar = $mensagem_final;
                    if (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code)) {
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => $template_source,
                            'broker_code' => $broker_code,
                            'customer_code' => (string) $id_carteira,
                            'original_message' => $mensagem_final,
                            'variables_map' => $variables_map
                        ]);
                    } elseif ($template_source === 'gosac_oficial' && !empty($template_code)) {
                        $gosac_template_id = intval($_POST['gosac_template_id'] ?? 0);
                        $gosac_connection_id = intval($_POST['gosac_connection_id'] ?? 0);
                        $gosac_variable_components = isset($_POST['gosac_variable_components']) ? json_decode(stripslashes($_POST['gosac_variable_components']), true) : [];
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'gosac_oficial',
                            'id' => $gosac_template_id,
                            'connectionId' => $gosac_connection_id,
                            'variables_map' => $variables_map,
                            'variableComponents' => is_array($gosac_variable_components) ? $gosac_variable_components : [],
                            'original_message' => $mensagem_final,
                        ]);
                    } elseif ($template_source === 'noah_oficial' && !empty($template_code)) {
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'noah_oficial',
                            'channelId' => $noah_channel_id,
                            'templateId' => $noah_template_id,
                            'templateName' => $template_code,
                            'language' => $noah_language,
                            'original_message' => $mensagem_final,
                            'variables_map' => $variables_map
                        ]);
                    } elseif ($template_source === 'robbu_oficial' && !empty($template_code)) {
                        $robbu_channel = intval($_POST['robbu_channel'] ?? 3);
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'robbu_oficial',
                            'templateName' => $template_code,
                            'channel' => $robbu_channel,
                            'original_message' => $mensagem_final,
                            'variables_map' => $variables_map
                        ]);
                    }

                    $insert_data = [
                        'telefone' => $telefone,
                        'nome' => $record['nome'] ?? '',
                        'idgis_ambiente' => intval($record['idgis_ambiente'] ?? 0),
                        'id_carteira' => $id_carteira,
                        'carteira_id' => $carteira_id_insert,
                        'idcob_contrato' => intval($record['idcob_contrato'] ?? 0),
                        'cpf_cnpj' => $record['cpf_cnpj'] ?? '',
                        'mensagem' => $mensagem_para_armazenar,
                        'fornecedor' => $provider,
                        'agendamento_id' => $agendamento_id,
                        'status' => 'pendente_aprovacao',
                        'current_user_id' => $current_user_id,
                        'valido' => 1,
                        'data_cadastro' => current_time('mysql')
                    ];

                    $wpdb->insert($envios_table, $insert_data, ['%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);
                    $total_inserted++;
                }
            }

            // Remove arquivo temporário
            @unlink($temp_file);

            $message = "Campanha criada com sucesso! {$total_inserted} registros inseridos.";
            if ($baits_added > 0) {
                $message .= " {$baits_added} iscas incluídas.";
            }

            wp_send_json_success([
                'message' => $message,
                'agendamento_id' => $agendamento_base_id,
                'records_inserted' => $total_inserted,
                'baits_added' => $baits_added
            ]);
        } catch (Throwable $e) {
            error_log('[handle_create_cpf_campaign] ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error('Erro interno no servidor: ' . $e->getMessage() . ' no arquivo ' . basename($e->getFile()) . ' linha ' . $e->getLine());
        }
    }

    private function distribute_records($records, $providers_config)
    {
        $total_records = count($records);
        $distribution_mode = $providers_config['mode'] ?? 'split';
        $raw_providers = $providers_config['providers'] ?? [];

        // Normaliza providers para garantir que são strings (nomes/IDs)
        // Isso evita o erro "Cannot access offset of type array on array" no PHP 8
        $providers = [];
        foreach ($raw_providers as $p) {
            if (is_array($p)) {
                $providers[] = $p['id'] ?? $p['name'] ?? 'unknown';
            } else {
                $providers[] = (string) $p;
            }
        }

        if ($distribution_mode === 'all') {
            $result = [];
            foreach ($providers as $provider) {
                $result[] = ['provider' => $provider, 'records' => $records];
            }
            return $result;
        }

        $percentages = $providers_config['percentages'] ?? [];
        $total_percent = array_sum($percentages);
        if ($total_percent != 100 && $total_percent > 0) {
            foreach ($percentages as $provider => $percent) {
                $percentages[$provider] = ($percent / $total_percent) * 100;
            }
        }

        shuffle($records);
        $result = [];
        $start_index = 0;

        foreach ($providers as $i => $provider) {
            $percent = $percentages[$provider] ?? (100 / count($providers));
            $count = round(($percent / 100) * $total_records);

            if ($i === count($providers) - 1) {
                $count = $total_records - $start_index;
            }

            $provider_records = array_slice($records, $start_index, $count);
            if (!empty($provider_records)) {
                $result[] = ['provider' => $provider, 'records' => $provider_records];
            }
            $start_index += $count;
        }

        return $result;
    }

    private function replace_placeholders($message, $record)
    {
        $replacements = [
            '[[NOME]]' => $record['nome'] ?? '',
            '[[TELEFONE]]' => $record['telefone'] ?? '',
            '[[CPF]]' => $record['cpf_cnpj'] ?? '',
            '[[CONTRATO]]' => $record['idcob_contrato'] ?? '',
        ];

        foreach ($replacements as $placeholder => $value) {
            $message = str_replace($placeholder, $value, $message);
        }

        return $message;
    }

    /**
     * Transforma dados padrão para formato do provider customizado
     */
    public function transform_data_for_custom_provider($provider_key, $standard_data)
    {
        $custom_providers = get_option('acm_custom_providers', []);

        if (!isset($custom_providers[$provider_key])) {
            return null;
        }

        $provider = $custom_providers[$provider_key];
        $template = $provider['json_template'];
        $transformed = [];

        // Mapeia os dados padrão para o formato do provider
        foreach ($template as $custom_field => $template_value) {
            if (is_string($template_value) && preg_match('/\{\{(\w+)\}\}/', $template_value, $matches)) {
                $standard_field = strtoupper($matches[1]);

                // Converte nome do campo padrão para chave do array
                $field_map = [
                    'NOME' => 'nome',
                    'TELEFONE' => 'telefone',
                    'CPF_CNPJ' => 'cpf_cnpj',
                    'IDGIS_AMBIENTE' => 'idgis_ambiente',
                    'IDCOB_CONTRATO' => 'idcob_contrato',
                    'MENSAGEM' => 'mensagem',
                    'DATA_CADASTRO' => 'data_cadastro',
                ];

                $data_key = $field_map[$standard_field] ?? strtolower($standard_field);
                $transformed[$custom_field] = $standard_data[$data_key] ?? '';
            } else {
                // Valor estático ou fixo
                $transformed[$custom_field] = $template_value;
            }
        }

        return $transformed;
    }

    // Helpers para integração com outros plugins
    public function get_api_credentials($provider, $env_id)
    {
        $credentials = get_option('acm_provider_credentials', []);

        if (isset($credentials[$provider][$env_id])) {
            return $credentials[$provider][$env_id];
        }

        return null;
    }

    public function get_master_api_key()
    {
        return get_option('acm_master_api_key', '');
    }

    public function get_agendamentos($status = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agendamentos';

        $query = "SELECT * FROM {$table}";

        if ($status) {
            $query .= $wpdb->prepare(" WHERE status = %s", $status);
        }

        $query .= " ORDER BY data_cadastro DESC";

        return $wpdb->get_results($query);
    }

    public function handle_save_recurring()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $nome_campanha = sanitize_text_field($_POST['nome_campanha'] ?? '');
        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) { wp_send_json_error('Tabela inválida.'); return; }
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $providers_config_json = stripslashes($_POST['providers_config'] ?? '{}');
        $template_id = intval($_POST['template_id'] ?? 0);
        $template_code = sanitize_text_field($_POST['template_code'] ?? '');
        $template_source = sanitize_text_field($_POST['template_source'] ?? 'local');
        $broker_code = sanitize_text_field($_POST['broker_code'] ?? '');
        $customer_code = sanitize_text_field($_POST['customer_code'] ?? '');
        $carteira = sanitize_text_field($_POST['carteira'] ?? '');
        $record_limit = intval($_POST['record_limit'] ?? 0);
        $exclude_recent_phones = isset($_POST['exclude_recent_phones']) ? intval($_POST['exclude_recent_phones']) : 1;
        $exclude_recent_hours = isset($_POST['exclude_recent_hours']) ? intval($_POST['exclude_recent_hours']) : 48;
        $include_baits = isset($_POST['include_baits']) ? intval($_POST['include_baits']) : 0;

        $throttling_type = sanitize_text_field($_POST['throttling_type'] ?? 'none');
        $throttling_config_json = stripslashes($_POST['throttling_config'] ?? '{}');

        // Validation based on source
        if (empty($nome_campanha) || empty($table_name)) {
            wp_send_json_error('Dados incompletos para criar filtro salvo.');
        }

        if ($template_source === 'local' && $template_id <= 0) {
            wp_send_json_error('Template_id inválido para template local.');
        }

        // Cria tabela se não existir
        $table = $wpdb->prefix . 'cm_recurring_campaigns';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            nome_campanha varchar(255) NOT NULL,
            tabela_origem varchar(150) NOT NULL,
            filtros_json text,
            providers_config text NOT NULL,
            template_id bigint(20) NOT NULL,
            template_code varchar(255) DEFAULT NULL,
            template_source varchar(100) DEFAULT 'local',
            record_limit int(11) DEFAULT 0,
            ativo tinyint(1) DEFAULT 1,
            ultima_execucao datetime DEFAULT NULL,
            criado_por bigint(20) NOT NULL,
            criado_em datetime DEFAULT CURRENT_TIMESTAMP,
            atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            throttling_type varchar(50) DEFAULT 'none',
            throttling_config text,
            include_baits tinyint(1) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verifica se as novas colunas existem e adiciona se necessário (migração)
        $column_check = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'template_code'");
        if (empty($column_check)) {
            $wpdb->query("ALTER TABLE `$table` 
                ADD COLUMN template_code varchar(255) DEFAULT NULL AFTER template_id,
                ADD COLUMN template_source varchar(100) DEFAULT 'local' AFTER template_code,
                ADD COLUMN throttling_type varchar(50) DEFAULT 'none' AFTER atualizado_em,
                ADD COLUMN throttling_config text AFTER throttling_type,
                ADD COLUMN include_baits tinyint(1) DEFAULT 0 AFTER throttling_config;
            ");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'broker_code'"))) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN broker_code varchar(100) DEFAULT '' AFTER template_source, ADD COLUMN customer_code varchar(100) DEFAULT '' AFTER broker_code");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'carteira'"))) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN carteira varchar(50) DEFAULT '' AFTER customer_code");
        }

        // Adiciona exclusão ao config
        $config_array = json_decode($providers_config_json, true);
        if (!is_array($config_array)) {
            $config_array = [];
        }
        $config_array['exclude_recent_phones'] = $exclude_recent_phones;
        $config_array['exclude_recent_hours'] = $exclude_recent_hours;
        $providers_config_json = json_encode($config_array);

        $result = $wpdb->insert(
            $table,
            [
                'nome_campanha' => $nome_campanha,
                'tabela_origem' => $table_name,
                'filtros_json' => $filters_json,
                'providers_config' => $providers_config_json,
                'template_id' => $template_id,
                'template_code' => $template_code,
                'template_source' => $template_source,
                'broker_code' => $broker_code,
                'customer_code' => $customer_code,
                'carteira' => $carteira,
                'record_limit' => $record_limit,
                'throttling_type' => $throttling_type,
                'throttling_config' => $throttling_config_json,
                'include_baits' => $include_baits,
                'ativo' => 1,
                'criado_por' => get_current_user_id()
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao salvar filtro: ' . $wpdb->last_error);
        }

        wp_send_json_success('Filtro salvo com sucesso!');
    }

    public function handle_schedule_campaign()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) { wp_send_json_error('Tabela inválida.'); return; }
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);
        $providers_config_json = stripslashes($_POST['providers_config'] ?? '{}');
        $providers_config = json_decode($providers_config_json, true);
        $template_id = intval($_POST['template_id'] ?? 0);
        $template_code = sanitize_text_field($_POST['template_code'] ?? '');
        $template_source = sanitize_text_field($_POST['template_source'] ?? 'local');
        $broker_code = sanitize_text_field($_POST['broker_code'] ?? '');
        $customer_code = sanitize_text_field($_POST['customer_code'] ?? '');
        $variables_map_json = stripslashes($_POST['variables_map'] ?? '{}');
        $variables_map = is_string($variables_map_json) ? json_decode($variables_map_json, true) : [];
        $record_limit = intval($_POST['record_limit'] ?? 0);
        $exclude_recent_phones = isset($_POST['exclude_recent_phones']) ? intval($_POST['exclude_recent_phones']) : 1;
        $exclude_recent_hours = isset($_POST['exclude_recent_hours']) ? intval($_POST['exclude_recent_hours']) : 48;
        $midia_campanha = esc_url_raw($_POST['midia_campanha'] ?? '');
        $carteira = sanitize_text_field($_POST['carteira'] ?? '');

        // id_carteira da carteira selecionada (herança para todos os registros da fila)
        $campaign_id_carteira = '';
        if (!empty($carteira)) {
            $campaign_id_carteira = $this->resolve_id_carteira_from_carteira_id($carteira);
        }

        error_log('🔵 Dados recebidos: ' . json_encode([
            'table_name' => $table_name,
            'template_id' => $template_id,
            'template_code' => $template_code,
            'template_source' => $template_source,
            'broker_code' => $broker_code,
            'providers_config' => $providers_config,
            'filters_count' => count($filters ?? []),
            'midia_campanha' => $midia_campanha,
            'exclude_recent_phones' => $exclude_recent_phones
        ]));

        if (empty($table_name) || empty($providers_config)) {
            error_log('❌ Dados inválidos: table_name=' . $table_name . ', providers=' . json_encode($providers_config));
            wp_send_json_error('Dados da campanha inválidos.');
        }

        // Valida template baseado na origem
        $message_content = '';
        $template_info = [];
        if ($template_source === 'local' && $template_id > 0) {
            // Template local
            $message_post = get_post($template_id);
            if (!$message_post || $message_post->post_type !== 'message_template') {
                wp_send_json_error('Template de mensagem inválido.');
            }
            $message_content = $message_post->post_content;
            $template_info = ['template_id' => $template_id, 'source' => 'local'];
        } elseif (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code)) {
            // Template da Ótima - não precisa de conteúdo local, será usado o template_code
            $message_content = 'Template da Ótima: ' . $template_code;
            $template_info = ['template_code' => $template_code, 'source' => $template_source];
        } elseif ($template_source === 'gosac_oficial' && !empty($template_code)) {
            $gosac_tid = intval($_POST['gosac_template_id'] ?? 0);
            $gosac_cid = intval($_POST['gosac_connection_id'] ?? 0);
            error_log('🔵 [GOSAC] Recebido: gosac_template_id=' . ($_POST['gosac_template_id'] ?? '') . ' -> ' . $gosac_tid . ', gosac_connection_id=' . ($_POST['gosac_connection_id'] ?? '') . ' -> ' . $gosac_cid);
            $message_content = 'Template GOSAC Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'gosac_oficial',
                'template_id' => $gosac_tid,
                'connection_id' => $gosac_cid,
                'variable_components' => isset($_POST['gosac_variable_components']) ? json_decode(stripslashes($_POST['gosac_variable_components']), true) : [],
            ];
        } elseif ($template_source === 'noah_oficial' && !empty($template_code)) {
            $message_content = 'Template NOAH Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'noah_oficial',
                'channel_id' => intval($_POST['noah_channel_id'] ?? 0),
                'template_id' => intval($_POST['noah_template_id'] ?? 0),
                'template_name' => $template_code,
                'language' => sanitize_text_field($_POST['noah_language'] ?? 'pt_BR'),
            ];
        } elseif ($template_source === 'robbu_oficial' && !empty($template_code)) {
            $message_content = 'Template Robbu Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'robbu_oficial',
                'template_name' => $template_code,
                'channel' => 3,
            ];
        } else {
            wp_send_json_error('Template inválido. Informe template_id para templates locais ou template_code para templates externos (Ótima, GOSAC Oficial, NOAH Oficial).');
        }

        // Busca registros filtrados
        $records = PC_Campaign_Filters::get_filtered_records($table_name, $filters, $record_limit);
        error_log("🔍 [Debug pc_create_campaign] Registros após filtros: " . count($records));

        if (empty($records)) {
            wp_send_json_error('Nenhum registro encontrado com os filtros aplicados.');
        }

        // ✅ VALIDAÇÃO BLOCKLIST - Remove registros bloqueados
        $original_count = count($records);
        $records = PC_Blocklist_Validator::filter_blocked_records($records);
        $blocked_count = $original_count - count($records);
        error_log("🔍 [Debug pc_create_campaign] Registros após blocklist: " . count($records) . " (Bloqueados: $blocked_count)");

        if (empty($records)) {
            wp_send_json_error('Todos os registros estão na blocklist. Nenhum envio será criado.');
        }

        if ($blocked_count > 0) {
            error_log("✅ Blocklist: Removidos $blocked_count registros bloqueados de $original_count");
        }

        // 🎣 ISCAS - Adiciona iscas ativas se solicitado
        $include_baits = isset($_POST['include_baits']) ? intval($_POST['include_baits']) : 0;
        $test_only = isset($_POST['test_only']) ? intval($_POST['test_only']) : 0;
        $baits_added = 0;

        // Se for um disparo de teste, ignoramos os clientes e usamos SÓ as iscas.
        if ($include_baits && $test_only) {
            $records = [];
            error_log("🧪 [Test Send] Ativado Disparo de Teste. Ignorando base e disparando apenas para Iscas.");
        }

        if ($include_baits) {
            $table_iscas = $wpdb->prefix . 'cm_baits';
            $iscas = $wpdb->get_results(
                "SELECT * FROM $table_iscas WHERE ativo = 1",
                ARRAY_A
            );

            if (!empty($iscas)) {
                foreach ($iscas as $isca) {
                    // Iscas de teste: SEMPRE usa a carteira selecionada na campanha (primeira tela)
                    if (!empty($campaign_id_carteira)) {
                        $isca_id_carteira = $campaign_id_carteira;
                    } else {
                        // Fallback: usa id_carteira da isca quando não há carteira selecionada
                        $isca_id_carteira = $isca['id_carteira'] ?? '';
                        if (!empty($isca_id_carteira)) {
                            $resolved = $this->resolve_id_carteira_from_carteira_id($isca_id_carteira);
                            if (!empty($resolved)) {
                                $isca_id_carteira = $resolved;
                            }
                        }
                    }
                    $isca_record = [
                        'telefone' => $isca['telefone'],
                        'nome' => $isca['nome'],
                        'cpf_cnpj' => $isca['cpf'] ?? '',
                        'idgis_ambiente' => $isca['idgis_ambiente'] ?? 0,
                        'id_carteira' => $isca_id_carteira,
                        'idcob_contrato' => 0,
                    ];
                    $records[] = $isca_record;
                    $baits_added++;
                }
                error_log("🎣 Iscas: Adicionados $baits_added registros de iscas");
            }
        }

        if (empty($records) && $test_only) {
            wp_send_json_error('Nenhuma isca ativa encontrada para o Disparo de Teste.');
        }

        error_log("🔍 [Debug pc_create_campaign] Registros finais antes da distribuição: " . count($records));

        // Throttling Data
        $throttling_type = sanitize_text_field($_POST['throttling_type'] ?? 'none');
        $throttling_config_json = stripslashes($_POST['throttling_config'] ?? '{}');
        // Validate JSON
        json_decode($throttling_config_json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $throttling_config_json = '{}';
        }

        error_log("🔍 [Debug pc_create_campaign] Providers Config Recebido: " . print_r($providers_config, true));

        // Distribui entre provedores
        $distributed_records = $this->distribute_records($records, $providers_config);

        $dist_count = 0;
        foreach ($distributed_records as $dr) {
            $dist_count += count($dr['records'] ?? []);
        }
        error_log("🔍 [Debug pc_create_campaign] Registros após distribute_records: " . count($distributed_records) . " blocos, total " . $dist_count . " registros");

        // Insere na tabela envios_pendentes
        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $current_user_id = get_current_user_id();
        $agendamento_base_id = current_time('YmdHis');
        $total_inserted = 0;
        $total_skipped = 0;

        // 🚀 OTIMIZAÇÃO: Busca todos os telefones recentes de uma vez (se necessário)
        $recent_phones = [];
        if ($exclude_recent_phones) {
            $recent_phones = $this->get_recent_phones_batch($envios_table, $exclude_recent_hours);
            error_log('🔵 Telefones recentes encontrados: ' . count($recent_phones));
        }

        // Prepara todos os dados para inserção em lote
        $all_insert_data = [];
        $generated_campaign_ids = [];

        foreach ($distributed_records as $provider_data) {
            $provider = $provider_data['provider'];
            $provider_records = $provider_data['records'];
            $prefix = strtoupper(substr($provider, 0, 1));
            // CORREÇÃO: WhatsApp da Ótima precisa do prefixo W, mas começa com O (OTIMA_WPP)
            if ($provider === 'OTIMA_WPP') {
                $prefix = 'W';
            } elseif ($provider === 'GOSAC_OFICIAL') {
                $prefix = 'F';
            } elseif ($provider === 'NOAH_OFICIAL') {
                $prefix = 'H';
            } elseif ($provider === 'ROBBU_OFICIAL') {
                $prefix = 'B';
            } elseif ($provider === 'CDA_RCS') {
                // CDA_RCS usa prefixo R (RCS) para buscar credenciais RCS (URL importarcs), não CDA (URL importar/campanha)
                $prefix = 'R';
            }
            $agendamento_id = $prefix . $agendamento_base_id;

            // Store unique ID for settings
            if (!in_array($agendamento_id, $generated_campaign_ids)) {
                $generated_campaign_ids[] = $agendamento_id;
            }

            foreach ($provider_records as $record) {
                // ... (rest of the loop same as before)
                $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
                if (strlen($telefone) > 11 && substr($telefone, 0, 2) === '55') {
                    $telefone = substr($telefone, 2);
                }

                // Verifica se deve excluir telefones recentes (usando array em memória)
                if ($exclude_recent_phones && isset($recent_phones[$telefone])) {
                    $total_skipped++;
                    continue;
                }

                $mensagem_final = $this->replace_placeholders($message_content, $record);

                // Aplica mapeamento IDGIS
                $idgis_original = intval($record['idgis_ambiente'] ?? 0);
                $idgis_ambiente = $idgis_original;
                if ($idgis_original > 0) {
                    $idgis_ambiente = PC_IDGIS_Mapper::get_mapped_idgis($table_name, $provider, $idgis_original);
                }

                // Usa id_carteira do registro (iscas já trazem esse campo), tabela+idgis, ou herda da carteira selecionada
                $id_carteira = !empty($record['id_carteira'])
                    ? $record['id_carteira']
                    : ($this->get_id_carteira_from_table_idgis($table_name, $idgis_ambiente) ?: $campaign_id_carteira);

                // Para templates da Ótima, armazena template_code no campo mensagem
                $mensagem_para_armazenar = $mensagem_final;
                if (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code)) {
                    // JSON Ótima: igual Campanha por Arquivo - broker_code, customer_code=id_carteira, variables_map
                    $mensagem_para_armazenar = json_encode([
                        'template_code' => $template_code,
                        'template_source' => $template_source,
                        'broker_code' => $broker_code,
                        'customer_code' => (string) $id_carteira,
                        'original_message' => $mensagem_final,
                        'variables_map' => !empty($variables_map) ? $variables_map : null
                    ]);
                } elseif ($template_source === 'noah_oficial' && !empty($template_code)) {
                    $channel_id = intval($template_info['channel_id'] ?? 0);
                    $noah_template_id = intval($template_info['template_id'] ?? 0);
                    $noah_template_name = $template_info['template_name'] ?? $template_code;
                    $noah_language = $template_info['language'] ?? 'pt_BR';
                    $noah_components = [];
                    if (!empty($variables_map) && is_array($variables_map)) {
                        $body_params = [];
                        foreach ($variables_map as $var_name => $field) {
                            $val = $record[$field] ?? $record[strtoupper($field)] ?? '';
                            $body_params[] = ['type' => 'text', 'text' => (string) $val];
                        }
                        if (!empty($body_params)) {
                            $noah_components[] = ['type' => 'body', 'parameters' => $body_params];
                        }
                    }
                    $mensagem_para_armazenar = json_encode([
                        'channelId' => $channel_id,
                        'templateId' => $noah_template_id,
                        'templateName' => $noah_template_name,
                        'language' => $noah_language,
                        'components' => $noah_components,
                    ]);
                } elseif ($template_source === 'robbu_oficial' && !empty($template_code)) {
                    $robbu_params = [];
                    if (!empty($variables_map) && is_array($variables_map)) {
                        foreach ($variables_map as $param_name => $field) {
                            $val = $record[$field] ?? $record[strtoupper($field)] ?? '';
                            $robbu_params[] = [
                                'parameterName' => $param_name,
                                'parameterValue' => (string) $val,
                            ];
                        }
                    }
                    $mensagem_para_armazenar = json_encode([
                        'template_source' => 'robbu_oficial',
                        'templateName' => $template_code,
                        'channel' => 3,
                        'templateParameters' => $robbu_params,
                    ]);
                } elseif ($template_source === 'gosac_oficial' && !empty($template_code)) {
                    $gosac_template_id = intval($template_info['template_id'] ?? 0);
                    $gosac_connection_id = intval($template_info['connection_id'] ?? 0);
                    $variable_components = $template_info['variable_components'] ?? [];
                    $mensagem_para_armazenar = json_encode([
                        'template_source' => 'gosac_oficial',
                        'template_code' => $template_code,
                        'id' => $gosac_template_id,
                        'connectionId' => $gosac_connection_id,
                        'variables_map' => $variables_map,
                        'variableComponents' => $variable_components,
                        'original_message' => $mensagem_final,
                    ]);
                }

                // carteira_id: id interno da carteira selecionada (para GOSAC: lookup correto de id_ruler quando há múltiplas carteiras com mesmo id_carteira)
                $carteira_id_insert = !empty($carteira) && ($id_carteira === $campaign_id_carteira) ? intval($carteira) : null;

                $all_insert_data[] = [
                    'telefone' => $telefone,
                    'nome' => $record['nome'] ?? '',
                    'idgis_ambiente' => $idgis_ambiente,
                    'id_carteira' => $id_carteira,
                    'carteira_id' => $carteira_id_insert,
                    'idcob_contrato' => intval($record['idcob_contrato'] ?? 0),
                    'cpf_cnpj' => $record['cpf_cnpj'] ?? '',
                    'mensagem' => $mensagem_para_armazenar,
                    'midia_campanha' => $midia_campanha,
                    'fornecedor' => $provider,
                    'agendamento_id' => $agendamento_id,
                    'status' => 'pendente_aprovacao',
                    'current_user_id' => $current_user_id,
                    'valido' => 1,
                    'data_cadastro' => current_time('mysql')
                ];
            }
        }

        // 🚀 OTIMIZAÇÃO: Insere em lotes de 500 registros
        $last_db_error = '';
        if (!empty($all_insert_data)) {
            $batch_size = 500;
            $batches = array_chunk($all_insert_data, $batch_size);

            foreach ($batches as $batch) {
                $inserted = $this->bulk_insert($envios_table, $batch);
                if ($inserted === false || $inserted === 0) {
                    $last_db_error = $wpdb->last_error;
                    error_log('🚨 [ERRO] bulk_insert falhou para lote de ' . count($batch) . ' registros. Erro: ' . $last_db_error);
                } else {
                    $total_inserted += $inserted;
                }
            }

            // Save Throttling Settings
            if ($throttling_type !== 'none' && !empty($generated_campaign_ids)) {
                $table_settings = $wpdb->prefix . 'pc_campaign_settings';
                foreach ($generated_campaign_ids as $camp_id) {
                    $wpdb->replace(
                        $table_settings,
                        [
                            'agendamento_id' => $camp_id,
                            'throttling_type' => $throttling_type,
                            'throttling_config' => $throttling_config_json,
                            'criado_em' => current_time('mysql')
                        ],
                        ['%s', '%s', '%s', '%s']
                    );
                }
            }
        }

        if ($total_inserted === 0) {
            $err_msg = 'Nenhum registro foi inserido. Verifique os filtros e tente novamente.';
            if (!empty($last_db_error)) {
                $err_msg .= ' Erro do Banco: ' . $last_db_error;
            } else {
                // If it's not a DB Error, it means all records were filtered out before reaching bulk_insert
                if ($total_skipped > 0 || $blocked_count > 0) {
                    $err_msg .= ' Motivo técnico: ' . count($records) . ' registros passaram pelos filtros primários, mas ';
                    $reasons = [];
                    if ($blocked_count > 0) {
                        $reasons[] = "{$blocked_count} caíram na blocklist";
                    }
                    if ($total_skipped > 0) {
                        $reasons[] = "{$total_skipped} foram pulados por bloqueio de 24h";
                    }
                    if (count($records) === 0) {
                        // Means records were 0 before even reaching blocklist/skipped tally
                        $reasons[] = "a consulta à base retornou 0 clientes";
                    }
                    $err_msg .= implode(' e ', $reasons) . '.';
                }
            }
            wp_send_json_error($err_msg);
        }

        $message = "Campanha agendada! {$total_inserted} clientes inseridos.";
        if ($blocked_count > 0) {
            $message .= " {$blocked_count} registros removidos pela blocklist.";
        }
        if ($total_skipped > 0) {
            $message .= " {$total_skipped} telefones excluídos (já receberam mensagem recentemente).";
        }
        if ($baits_added > 0) {
            $message .= " {$baits_added} iscas incluídas.";
        }

        wp_send_json_success([
            'message' => $message,
            'agendamento_id' => $agendamento_base_id,
            'records_inserted' => $total_inserted,
            'records_skipped' => $total_skipped,
            'records_blocked' => $blocked_count,
            'baits_added' => $baits_added,
            'exclusion_enabled' => $exclude_recent_phones,
            'exclude_recent_hours' => $exclude_recent_hours
        ]);
    }

    public function handle_get_filters()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) {
            wp_send_json_error('Nome da tabela não fornecido ou inválido');
            return;
        }

        $filters = PC_Campaign_Filters::get_filterable_columns($table_name);

        if (is_wp_error($filters)) {
            wp_send_json_error($filters->get_error_message());
        }

        // Garante que sempre retorna um array
        if (!is_array($filters)) {
            error_log('⚠️ [get_filters] Filtros não é array, convertendo. Tipo: ' . gettype($filters));
            $filters = [];
        }

        error_log('🔍 [get_filters] Retornando ' . count($filters) . ' filtros para tabela: ' . $table_name);

        wp_send_json_success($filters);
    }

    public function handle_get_count()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);

        if (empty($table_name)) {
            wp_send_json_error('Nome da tabela não fornecido ou inválido');
            return;
        }

        $count = PC_Campaign_Filters::count_records($table_name, $filters);

        wp_send_json_success($count);
    }

    public function handle_get_count_detailed()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);
        $exclude_recent = isset($_POST['exclude_recent']) && $_POST['exclude_recent'] === 'true';
        $exclude_recent_hours = isset($_POST['exclude_recent_hours']) ? intval($_POST['exclude_recent_hours']) : 48;

        if (empty($table_name)) {
            wp_send_json_error('Nome da tabela não fornecido ou inválido');
            return;
        }

        global $wpdb;
        $envios_table = $wpdb->prefix . 'envios_pendentes';

        // 1. Pega apenas os campos necessários para validação (TELEFONE, CPF_CNPJ)
        // Isso simula o comportamento da criação da campanha, mas otimizado na memória
        $where_sql = PC_Campaign_Filters::build_where_clause($filters);

        // Verifica as colunas disponíveis para fazer SELECT correto sem gerar erro de coluna inexistente
        $columns = array_map('strtoupper', (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`"));
        $select_fields = [];

        // Telefone (Obrigatório, mas verificamos nomes comuns)
        if (in_array('TELEFONE', $columns)) {
            $select_fields[] = 'TELEFONE as telefone';
        } elseif (in_array('CELULAR', $columns)) {
            $select_fields[] = 'CELULAR as telefone';
        } elseif (in_array('PHONE', $columns)) {
            $select_fields[] = 'PHONE as telefone';
        } else {
            // Se não achar nada, tenta a primeira coluna que parece telefone ou bota NULL
            $found_tel = false;
            foreach ($columns as $col) {
                if (strpos($col, 'TEL') !== false || strpos($col, 'CEL') !== false) {
                    $select_fields[] = "`$col` as telefone";
                    $found_tel = true;
                    break;
                }
            }
            if (!$found_tel)
                $select_fields[] = 'NULL as telefone';
        }

        // CPF/CNPJ
        if (in_array('CPF', $columns) && in_array('CPF_CNPJ', $columns)) {
            $select_fields[] = 'COALESCE(CPF, CPF_CNPJ) as cpf_cnpj';
        } elseif (in_array('CPF', $columns)) {
            $select_fields[] = 'CPF as cpf_cnpj';
        } elseif (in_array('CPF_CNPJ', $columns)) {
            $select_fields[] = 'CPF_CNPJ as cpf_cnpj';
        } elseif (in_array('CNPJ', $columns)) {
            $select_fields[] = 'CNPJ as cpf_cnpj';
        } elseif (in_array('DOCUMENTO', $columns)) {
            $select_fields[] = 'DOCUMENTO as cpf_cnpj';
        } else {
            $select_fields[] = 'NULL as cpf_cnpj';
        }

        $select_clause = implode(', ', $select_fields);

        $sql = "SELECT {$select_clause} FROM `{$table_name}`" . $where_sql;
        // Fallback caso a tabela não tenha essas colunas nomeadas assim (vai tentar a padrão do plugin)
        $suprime_erro = $wpdb->suppress_errors(true);
        $records = $wpdb->get_results($sql, ARRAY_A);
        $wpdb->suppress_errors($suprime_erro);

        // Se falhou (nome de coluna diferente), tenta o método get_filtered_records_optimized que já resolve nomes
        if ($records === null || $wpdb->last_error) {
            $records = $this->get_filtered_records_optimized($table_name, $filters, 0, false);
        }

        $total_count = is_array($records) ? count($records) : 0;

        if ($total_count === 0) {
            wp_send_json_success([
                'total' => 0,
                'recent_excluded' => 0,
                'blocked' => 0,
                'effective' => 0
            ]);
            return;
        }

        // 2. Coleta telefones recentes em memória
        $recent_phones = [];
        if ($exclude_recent) {
            $recent_phones = $this->get_recent_phones_batch($envios_table, $exclude_recent_hours);
        }

        // 3. Obtém dados de blocklist (telefones e cpfs em lote como a validação normal faz)
        $telefones = [];
        $cpfs = [];
        foreach ($records as $record) {
            if (!empty($record['telefone'])) {
                $tel_clean = preg_replace('/[^0-9]/', '', $record['telefone']);
                if (strlen($tel_clean) >= 10) {
                    $telefones[] = $tel_clean;
                }
            }
            if (!empty($record['cpf_cnpj'])) {
                $cpf_clean = preg_replace('/[^0-9]/', '', $record['cpf_cnpj']);
                if (strlen($cpf_clean) === 11) {
                    $cpfs[] = $cpf_clean;
                }
            }
        }

        $table_blocklist = $wpdb->prefix . 'pc_blocklist';
        $blocked_telefones = [];
        $blocked_cpfs = [];

        if (!empty($telefones)) {
            $telefones_unique = array_unique($telefones);
            // Processa em chunks se for muito grande
            foreach (array_chunk($telefones_unique, 5000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $query = $wpdb->prepare("SELECT valor FROM $table_blocklist WHERE tipo = 'telefone' AND valor IN ($placeholders)", $chunk);
                $blocked_telefones = array_merge($blocked_telefones, $wpdb->get_col($query));
            }
        }

        if (!empty($cpfs)) {
            $cpfs_unique = array_unique($cpfs);
            foreach (array_chunk($cpfs_unique, 5000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $query = $wpdb->prepare("SELECT valor FROM $table_blocklist WHERE tipo = 'cpf' AND valor IN ($placeholders)", $chunk);
                $blocked_cpfs = array_merge($blocked_cpfs, $wpdb->get_col($query));
            }
        }

        $blocked_telefones_map = array_flip($blocked_telefones);
        $blocked_cpfs_map = array_flip($blocked_cpfs);

        // 4. Itera para contar
        $recent_excluded_count = 0;
        $blocked_count = 0;
        $effective_count = 0;

        foreach ($records as $record) {
            $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
            $telefone_normalizado = $telefone;
            if (strlen($telefone_normalizado) > 11 && substr($telefone_normalizado, 0, 2) === '55') {
                $telefone_normalizado = substr($telefone_normalizado, 2);
            }

            $cpf = preg_replace('/[^0-9]/', '', $record['cpf_cnpj'] ?? '');

            $is_blocked = false;
            // Blocklist priority
            if (isset($blocked_telefones_map[$telefone])) {
                $is_blocked = true;
            } elseif (strlen($cpf) === 11 && isset($blocked_cpfs_map[$cpf])) {
                $is_blocked = true;
            }

            if ($is_blocked) {
                $blocked_count++;
                continue; // Blocked records are dropped first
            }

            // Exclusão recente second priority
            if ($exclude_recent && isset($recent_phones[$telefone_normalizado])) {
                $recent_excluded_count++;
                continue;
            }

            $effective_count++;
        }

        wp_send_json_success([
            'total' => $total_count,
            'recent_excluded' => $recent_excluded_count,
            'blocked' => $blocked_count,
            'effective' => $effective_count
        ]);
    }

    public function handle_check_base_update()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) {
            wp_send_json_error('Nome da tabela não fornecido ou inválido.');
            return;
        }

        global $wpdb;

        // Verifica se a coluna ult_atualizacao existe na tabela
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s 
             AND COLUMN_NAME = 'ult_atualizacao'",
            DB_NAME,
            $table_name
        ));

        if (!$column_exists) {
            // Se a coluna não existir, considera como atualizada (compatibilidade)
            wp_send_json_success([
                'is_updated' => true,
                'message' => 'Coluna ult_atualizacao não encontrada na tabela',
                'ult_atualizacao' => null
            ]);
            return;
        }

        // Busca a data de última atualização
        $table_name_escaped = esc_sql($table_name);
        $ult_atualizacao = $wpdb->get_var(
            "SELECT MAX(ult_atualizacao) FROM `{$table_name_escaped}`"
        );

        if (empty($ult_atualizacao)) {
            // Se não houver data, considera como desatualizada por segurança
            wp_send_json_success([
                'is_updated' => false,
                'message' => 'Data de atualização não encontrada na base',
                'ult_atualizacao' => null
            ]);
            return;
        }

        // Compara com a data de hoje
        $today = current_time('Y-m-d');
        $ult_atualizacao_date = date('Y-m-d', strtotime($ult_atualizacao));

        $is_updated = ($ult_atualizacao_date === $today);

        // Log para debug
        error_log('🔍 [check_base_update] Table: ' . $table_name);
        error_log('🔍 [check_base_update] Today: ' . $today);
        error_log('🔍 [check_base_update] Last update: ' . $ult_atualizacao_date);
        error_log('🔍 [check_base_update] Is updated: ' . ($is_updated ? 'true' : 'false'));

        wp_send_json_success([
            'is_updated' => $is_updated,
            'ult_atualizacao' => $ult_atualizacao_date,
            'today' => $today,
            'message' => $is_updated
                ? 'Base está atualizada'
                : "Base desatualizada. Última atualização: {$ult_atualizacao_date}"
        ]);
    }

    public function handle_get_template_content()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $template_id_raw = $_POST['template_id'] ?? null;
        error_log('📄 [get_template_content] Valor recebido: ' . var_export($template_id_raw, true) . ' | Tipo: ' . gettype($template_id_raw));

        $template_id = intval($template_id_raw);
        error_log('📄 [get_template_content] Após intval: ' . $template_id);

        if ($template_id <= 0) {
            error_log('🔴 [get_template_content] ID inválido: ' . $template_id);
            wp_send_json_error('ID do template inválido.');
            return;
        }

        $template_post = get_post($template_id);
        error_log('📄 [get_template_content] Post encontrado: ' . ($template_post ? 'Sim (tipo: ' . $template_post->post_type . ')' : 'Não'));

        if (!$template_post || $template_post->post_type !== 'message_template') {
            error_log('🔴 [get_template_content] Template não encontrado ou tipo incorreto');
            wp_send_json_error('Template não encontrado.');
            return;
        }

        // Retorna apenas o conteúdo como string
        wp_send_json_success($template_post->post_content);
    }

    // ========== HANDLERS PARA MENSAGENS ==========

    public function handle_get_messages()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $current_user_id = get_current_user_id();

        // Buscar templates locais
        $messages = get_posts([
            'post_type' => 'message_template',
            'author' => $current_user_id,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'publish'
        ]);

        $formatted_messages = array_map(function ($post) {
            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'date' => $post->post_date,
                'source' => 'local',
                'provider' => get_post_meta($post->ID, '_template_provider', true) ?: '',
                'wallet_id' => get_post_meta($post->ID, '_template_wallet_id', true) ?: '',
            ];
        }, $messages);

        // Buscar templates da Ótima WPP
        $otima_wpp_templates = $this->fetch_otima_wpp_templates();
        if (!empty($otima_wpp_templates)) {
            $formatted_messages = array_merge($formatted_messages, $otima_wpp_templates);
        }

        // Buscar templates da Ótima RCS
        $otima_rcs_templates = $this->fetch_otima_rcs_templates();
        if (!empty($otima_rcs_templates)) {
            $formatted_messages = array_merge($formatted_messages, $otima_rcs_templates);
        }

        wp_send_json_success($formatted_messages);
    }

    private function fetch_otima_wpp_templates()
    {
        $static_credentials = get_option('acm_static_credentials', []);
        $token = $static_credentials['otima_wpp_token'] ?? '';
        $customer_code = $static_credentials['otima_wpp_customer_code'] ?? '';

        if (empty($token) || empty($customer_code)) {
            return [];
        }

        $url = "https://services.otima.digital/v1/whatsapp/template/hsm/{$customer_code}";

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('🔴 [Ótima WPP] Erro ao buscar templates: ' . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return [];
        }

        // Filtrar apenas templates ativos (status 'A')
        $active_templates = array_filter($data, function ($template) {
            return isset($template['status']) && $template['status'] === 'A';
        });

        return array_map(function ($template) {
            return [
                'id' => 'otima_wpp_' . ($template['template_code'] ?? uniqid()),
                'title' => ($template['name'] ?? $template['template_code'] ?? 'Template sem nome') . ' (Ótima WPP)',
                'content' => $template['content'] ?? '',
                'date' => date('Y-m-d H:i:s'),
                'source' => 'otima_wpp',
                'template_code' => $template['template_code'] ?? '',
                'status' => $template['status'] ?? '',
            ];
        }, $active_templates);
    }

    private function fetch_otima_rcs_templates()
    {
        $static_credentials = get_option('acm_static_credentials', []);
        $token = $static_credentials['otima_rcs_token'] ?? '';
        $customer_code = $static_credentials['otima_rcs_customer_code'] ?? '';

        if (empty($token) || empty($customer_code)) {
            return [];
        }

        $url = "https://services.otima.digital/v1/rcs/template/{$customer_code}";

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('🔴 [Ótima RCS] Erro ao buscar templates: ' . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return [];
        }

        // Filtrar apenas templates ativos (se houver campo status)
        $active_templates = array_filter($data, function ($template) {
            // Se não tiver campo status, assume que está ativo
            return !isset($template['status']) || $template['status'] === 'A' || $template['status'] === 'ACTIVE';
        });

        return array_map(function ($template) {
            return [
                'id' => 'otima_rcs_' . ($template['template_id'] ?? $template['id'] ?? uniqid()),
                'title' => ($template['name'] ?? $template['template_name'] ?? 'Template sem nome') . ' (Ótima RCS)',
                'content' => $template['content'] ?? $template['message_text'] ?? '',
                'date' => date('Y-m-d H:i:s'),
                'source' => 'otima_rcs',
                'template_id' => $template['template_id'] ?? $template['id'] ?? '',
            ];
        }, $active_templates);
    }

    public function handle_get_message()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $message_id = intval($_POST['message_id'] ?? 0);
        $current_user_id = get_current_user_id();

        if ($message_id <= 0) {
            wp_send_json_error('ID da mensagem inválido.');
            return;
        }

        $post = get_post($message_id);

        if (!$post || $post->post_type !== 'message_template') {
            wp_send_json_error('Mensagem não encontrada.');
            return;
        }

        // Verifica se a mensagem pertence ao usuário
        if ($post->post_author != $current_user_id) {
            wp_send_json_error('Você não tem permissão para acessar esta mensagem.');
            return;
        }

        wp_send_json_success([
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content
        ]);
    }

    public function handle_create_message()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $title = sanitize_text_field($_POST['title'] ?? '');
        $content = sanitize_textarea_field($_POST['content'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? '');
        $current_user_id = get_current_user_id();

        if (empty($title) || empty($content)) {
            wp_send_json_error('Título e conteúdo são obrigatórios.');
            return;
        }

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'message_template',
            'post_status' => 'publish',
            'post_author' => $current_user_id
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error('Erro ao criar mensagem: ' . $post_id->get_error_message());
            return;
        }

        // Salva metadados de fornecedor e carteira
        if (!empty($provider)) {
            update_post_meta($post_id, '_template_provider', $provider);
        }
        if (!empty($wallet_id)) {
            update_post_meta($post_id, '_template_wallet_id', $wallet_id);
        }

        wp_send_json_success([
            'message' => 'Mensagem criada com sucesso!',
            'id' => $post_id
        ]);
    }

    public function handle_update_message()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $message_id = intval($_POST['message_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $content = sanitize_textarea_field($_POST['content'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? '');
        $current_user_id = get_current_user_id();

        if ($message_id <= 0) {
            wp_send_json_error('ID da mensagem inválido.');
            return;
        }

        if (empty($title) || empty($content)) {
            wp_send_json_error('Título e conteúdo são obrigatórios.');
            return;
        }

        $post = get_post($message_id);

        if (!$post || $post->post_type !== 'message_template') {
            wp_send_json_error('Mensagem não encontrada.');
            return;
        }

        // Verifica se a mensagem pertence ao usuário
        if ($post->post_author != $current_user_id) {
            wp_send_json_error('Você não tem permissão para editar esta mensagem.');
            return;
        }

        $updated = wp_update_post([
            'ID' => $message_id,
            'post_title' => $title,
            'post_content' => $content
        ]);

        if (is_wp_error($updated)) {
            wp_send_json_error('Erro ao atualizar mensagem: ' . $updated->get_error_message());
            return;
        }

        // Atualiza metadados de fornecedor e carteira
        update_post_meta($message_id, '_template_provider', $provider);
        update_post_meta($message_id, '_template_wallet_id', $wallet_id);

        wp_send_json_success([
            'message' => 'Mensagem atualizada com sucesso!'
        ]);
    }

    public function handle_delete_message()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $message_id = intval($_POST['message_id'] ?? 0);
        $current_user_id = get_current_user_id();

        if ($message_id <= 0) {
            wp_send_json_error('ID da mensagem inválido.');
            return;
        }

        $post = get_post($message_id);

        if (!$post || $post->post_type !== 'message_template') {
            wp_send_json_error('Mensagem não encontrada.');
            return;
        }

        // Verifica se a mensagem pertence ao usuário
        if ($post->post_author != $current_user_id) {
            wp_send_json_error('Você não tem permissão para deletar esta mensagem.');
            return;
        }

        $deleted = wp_delete_post($message_id, true);

        if (!$deleted) {
            wp_send_json_error('Erro ao deletar mensagem.');
            return;
        }

        wp_send_json_success([
            'message' => 'Mensagem deletada com sucesso!'
        ]);
    }

    // ========== HANDLERS PARA RELATÓRIOS ==========

    /**
     * Coleta e sanitiza filtros do relatório
     */
    private function collect_report_filters($source)
    {
        $source = wp_unslash($source);

        $sanitize_date = function ($value) {
            $value = sanitize_text_field($value);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };

        return [
            'filter_user' => isset($source['filter_user']) ? sanitize_text_field($source['filter_user']) : '',
            'filter_fornecedor' => isset($source['filter_fornecedor']) ? sanitize_text_field($source['filter_fornecedor']) : '',
            'filter_ambiente' => isset($source['filter_ambiente']) ? sanitize_text_field($source['filter_ambiente']) : '',
            'filter_agendamento' => isset($source['filter_agendamento']) ? sanitize_text_field($source['filter_agendamento']) : '',
            'filter_idgis' => isset($source['filter_idgis']) ? absint($source['filter_idgis']) : 0,
            'filter_date_start' => !empty($source['filter_date_start']) ? $sanitize_date($source['filter_date_start']) : '',
            'filter_date_end' => !empty($source['filter_date_end']) ? $sanitize_date($source['filter_date_end']) : '',
        ];
    }

    /**
     * Constrói cláusula WHERE para relatórios
     */
    private function build_report_where_sql($filters)
    {
        global $wpdb;

        $where = ['1=1'];

        if (!empty($filters['filter_user'])) {
            $where[] = $wpdb->prepare('E.display_name LIKE %s', '%' . $wpdb->esc_like($filters['filter_user']) . '%');
        }
        if (!empty($filters['filter_fornecedor'])) {
            $where[] = $wpdb->prepare('P.fornecedor LIKE %s', '%' . $wpdb->esc_like($filters['filter_fornecedor']) . '%');
        }
        if (!empty($filters['filter_ambiente'])) {
            // Verifica se a tabela existe antes de usar
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                'NOME_AMBIENTE'
            ));
            if ($table_exists) {
                $where[] = $wpdb->prepare('T.NOME_AMBIENTE LIKE %s', '%' . $wpdb->esc_like($filters['filter_ambiente']) . '%');
            }
        }
        if (!empty($filters['filter_agendamento'])) {
            $where[] = $wpdb->prepare('P.agendamento_id LIKE %s', '%' . $wpdb->esc_like($filters['filter_agendamento']) . '%');
        }
        if (!empty($filters['filter_date_start'])) {
            $where[] = $wpdb->prepare('CAST(P.data_cadastro AS DATE) >= %s', $filters['filter_date_start']);
        }
        if (!empty($filters['filter_date_end'])) {
            $where[] = $wpdb->prepare('CAST(P.data_cadastro AS DATE) <= %s', $filters['filter_date_end']);
        }
        if (!empty($filters['filter_idgis'])) {
            $where[] = $wpdb->prepare('P.idgis_ambiente = %d', $filters['filter_idgis']);
        }

        return implode(' AND ', $where);
    }

    /**
     * Conta registros agrupados
     */
    private function count_report_grouped_records($where_sql)
    {
        global $wpdb;

        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->prefix . 'users';
        $ambiente_table = 'NOME_AMBIENTE';

        // Verifica se a tabela de ambiente existe
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $ambiente_table
        ));

        $join_ambiente = $table_exists ? "LEFT JOIN {$ambiente_table} T ON T.IDGIS_AMBIENTE = P.idgis_ambiente" : "";

        $query = "
            SELECT COUNT(DISTINCT CONCAT(
                CAST(P.data_cadastro AS DATE), '-', P.current_user_id, '-', P.fornecedor, '-', P.agendamento_id, '-', P.idgis_ambiente
            )) AS total
            FROM {$envios_table} P
            LEFT JOIN {$users_table} E ON E.ID = P.current_user_id
            {$join_ambiente}
            WHERE {$where_sql}
        ";

        return (int) $wpdb->get_var($query);
    }

    /**
     * Busca totais por status
     */
    private function fetch_report_status_totals($where_sql, $filters = [])
    {
        global $wpdb;

        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->prefix . 'users';

        // Primeiro, vamos verificar se há dados na tabela e quais status existem
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$envios_table}");
        error_log('🔵 Total de registros na tabela: ' . $total_records);

        $status_check = $wpdb->get_col("SELECT DISTINCT status FROM {$envios_table} LIMIT 20");
        error_log('🔵 Status encontrados na tabela: ' . print_r($status_check, true));

        // Verifica se a tabela de ambiente existe
        $ambiente_table = 'NOME_AMBIENTE';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $ambiente_table
        ));

        $join_ambiente = $table_exists ? "LEFT JOIN {$ambiente_table} T ON T.IDGIS_AMBIENTE = P.idgis_ambiente" : "";

        // Query simplificada - busca direto da tabela envios_pendentes
        $query = "
            SELECT
                SUM(CASE WHEN LOWER(TRIM(P.status)) = 'enviado' THEN 1 ELSE 0 END) AS total_enviado,
                SUM(CASE WHEN LOWER(TRIM(P.status)) = 'pendente_aprovacao' THEN 1 ELSE 0 END) AS total_pendente_aprovacao,
                SUM(CASE WHEN LOWER(TRIM(P.status)) = 'agendado_mkc' THEN 1 ELSE 0 END) AS total_agendado_mkc,
                SUM(CASE WHEN LOWER(TRIM(P.status)) = 'pendente' THEN 1 ELSE 0 END) AS total_pendente,
                SUM(CASE WHEN LOWER(TRIM(P.status)) = 'negado' THEN 1 ELSE 0 END) AS total_negado
            FROM {$envios_table} P
            LEFT JOIN {$users_table} E ON E.ID = P.current_user_id
            {$join_ambiente}
            WHERE {$where_sql}
        ";

        error_log('🔵 Query de totais: ' . $query);

        $result = $wpdb->get_row($query, OBJECT);

        error_log('🔵 Resultado totais (raw): ' . print_r($result, true));

        // Se não retornou resultado ou todos são NULL, tenta query mais simples
        if (!$result || (is_null($result->total_enviado) && is_null($result->total_pendente_aprovacao))) {
            error_log('🔵 Resultado vazio, tentando query sem JOINs...');

            // Query sem JOINs para garantir que funcione
            $simple_query = "
                SELECT
                    SUM(CASE WHEN LOWER(TRIM(status)) = 'enviado' THEN 1 ELSE 0 END) AS total_enviado,
                    SUM(CASE WHEN LOWER(TRIM(status)) = 'pendente_aprovacao' THEN 1 ELSE 0 END) AS total_pendente_aprovacao,
                    SUM(CASE WHEN LOWER(TRIM(status)) = 'agendado_mkc' THEN 1 ELSE 0 END) AS total_agendado_mkc,
                    SUM(CASE WHEN LOWER(TRIM(status)) = 'pendente' THEN 1 ELSE 0 END) AS total_pendente,
                    SUM(CASE WHEN LOWER(TRIM(status)) = 'negado' THEN 1 ELSE 0 END) AS total_negado
                FROM {$envios_table}
                WHERE 1=1
            ";

            // Aplica filtros básicos que não dependem de JOINs
            $simple_where = ['1=1'];
            if (!empty($filters['filter_fornecedor'] ?? '')) {
                $simple_where[] = $wpdb->prepare('fornecedor LIKE %s', '%' . $wpdb->esc_like($filters['filter_fornecedor']) . '%');
            }
            if (!empty($filters['filter_agendamento'] ?? '')) {
                $simple_where[] = $wpdb->prepare('agendamento_id LIKE %s', '%' . $wpdb->esc_like($filters['filter_agendamento']) . '%');
            }
            if (!empty($filters['filter_date_start'] ?? '')) {
                $simple_where[] = $wpdb->prepare('CAST(data_cadastro AS DATE) >= %s', $filters['filter_date_start']);
            }
            if (!empty($filters['filter_date_end'] ?? '')) {
                $simple_where[] = $wpdb->prepare('CAST(data_cadastro AS DATE) <= %s', $filters['filter_date_end']);
            }
            if (!empty($filters['filter_idgis'] ?? 0)) {
                $simple_where[] = $wpdb->prepare('idgis_ambiente = %d', $filters['filter_idgis']);
            }

            $simple_query = str_replace('WHERE 1=1', 'WHERE ' . implode(' AND ', $simple_where), $simple_query);

            error_log('🔵 Query simples: ' . $simple_query);
            $result = $wpdb->get_row($simple_query, OBJECT);
            error_log('🔵 Resultado query simples: ' . print_r($result, true));
        }

        if (!$result) {
            return (object) [
                'total_enviado' => 0,
                'total_pendente_aprovacao' => 0,
                'total_agendado_mkc' => 0,
                'total_pendente' => 0,
                'total_negado' => 0,
            ];
        }

        // Garante que os valores são números
        return (object) [
            'total_enviado' => (int) ($result->total_enviado ?? 0),
            'total_pendente_aprovacao' => (int) ($result->total_pendente_aprovacao ?? 0),
            'total_agendado_mkc' => (int) ($result->total_agendado_mkc ?? 0),
            'total_pendente' => (int) ($result->total_pendente ?? 0),
            'total_negado' => (int) ($result->total_negado ?? 0),
        ];
    }

    public function handle_get_report_data()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        $filters = $this->collect_report_filters($_POST);
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(10, intval($_POST['per_page'] ?? 25));
        $offset = ($page - 1) * $per_page;

        $where_sql = $this->build_report_where_sql($filters);

        global $wpdb;
        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->prefix . 'users';
        $ambiente_table = 'NOME_AMBIENTE';

        // Verifica se a tabela de ambiente existe antes de fazer JOIN
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $ambiente_table
        ));

        $join_ambiente = $table_exists ? "LEFT JOIN {$ambiente_table} T ON T.IDGIS_AMBIENTE = P.idgis_ambiente" : "";
        $select_ambiente = $table_exists ? "T.NOME_AMBIENTE," : "NULL AS NOME_AMBIENTE,";
        $group_ambiente = $table_exists ? "T.NOME_AMBIENTE," : "";

        $query = "
            SELECT
                CAST(P.data_cadastro AS DATE) AS DATA,
                COALESCE(E.display_name, 'N/A') AS USUARIO,
                P.fornecedor AS FORNECEDOR,
                P.agendamento_id AS AGENDAMENTO_ID,
                {$select_ambiente}
                P.idgis_ambiente,
                SUM(CASE 
                    WHEN LOWER(TRIM(P.status)) IN ('enviado', 'mkc_executado') THEN 1 
                    ELSE 0 
                END) AS QTD_ENVIADO,
                SUM(CASE 
                    WHEN LOWER(TRIM(P.status)) = 'pendente_aprovacao' THEN 1 
                    ELSE 0 
                END) AS QTD_PENDENTE_APROVACAO,
                SUM(CASE 
                    WHEN LOWER(TRIM(P.status)) = 'agendado_mkc' THEN 1 
                    ELSE 0 
                END) AS QTD_AGENDADO_MKC,
                SUM(CASE 
                    WHEN LOWER(TRIM(P.status)) IN ('pendente', 'processando') THEN 1 
                    ELSE 0 
                END) AS QTD_PENDENTE,
                SUM(CASE 
                    WHEN LOWER(TRIM(P.status)) IN ('negado', 'erro', 'erro_envio', 'erro_credenciais', 'erro_validacao', 'mkc_erro') THEN 1 
                    ELSE 0 
                END) AS QTD_NEGADO
            FROM {$envios_table} P
            LEFT JOIN {$users_table} E ON E.ID = P.current_user_id
            {$join_ambiente}
            WHERE {$where_sql}
            GROUP BY
                CAST(P.data_cadastro AS DATE),
                COALESCE(E.display_name, 'N/A'),
                P.fornecedor,
                P.agendamento_id,
                {$group_ambiente}
                P.idgis_ambiente
            ORDER BY DATA DESC
            LIMIT {$per_page} OFFSET {$offset}
        ";

        // Debug: verifica dados antes da query
        $test_query = "SELECT COUNT(*) as total FROM {$envios_table}";
        $total_in_table = $wpdb->get_var($test_query);
        error_log('🔵 [RELATÓRIOS] Total de registros na tabela: ' . $total_in_table);

        // Debug: verifica status únicos
        $status_list = $wpdb->get_col("SELECT DISTINCT status FROM {$envios_table} LIMIT 20");
        error_log('🔵 [RELATÓRIOS] Status únicos na tabela: ' . print_r($status_list, true));

        $rows = $wpdb->get_results($query);

        // Debug: verifica resultado
        if (!empty($rows)) {
            error_log('🔵 [RELATÓRIOS] Total de linhas retornadas: ' . count($rows));
            error_log('🔵 [RELATÓRIOS] Primeira linha: ' . print_r($rows[0], true));
        } else {
            error_log('🔴 [RELATÓRIOS] NENHUMA LINHA RETORNADA!');
            // Testa query sem WHERE para ver se há dados
            $test_no_where = "SELECT COUNT(*) FROM {$envios_table} P LEFT JOIN {$users_table} E ON E.ID = P.current_user_id";
            $count_no_where = $wpdb->get_var($test_no_where);
            error_log('🔵 [RELATÓRIOS] Total sem WHERE: ' . $count_no_where);
        }

        $totals = $this->fetch_report_status_totals($where_sql);
        $total_records = $this->count_report_grouped_records($where_sql);

        wp_send_json_success([
            'data' => $rows,
            'totals' => $totals,
            'total_records' => $total_records,
        ]);
    }

    public function handle_get_report_1x1_stats()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table_eventos = $wpdb->prefix . 'eventos_envios';

        $results = $wpdb->get_results(
            "SELECT carteira, COUNT(*) as total 
             FROM {$table_eventos} 
             WHERE tipo = '1X1' 
             GROUP BY carteira 
             ORDER BY total DESC",
            ARRAY_A
        );

        $total_1x1 = 0;
        foreach ($results as $row) {
            $total_1x1 += $row['total'];
        }

        wp_send_json_success([
            'total' => $total_1x1,
            'carteiras' => $results
        ]);
    }

    public function handle_download_csv_geral()
    {
        if (!is_user_logged_in()) {
            wp_die('Acesso negado. Faça login para continuar.');
        }
        if (!current_user_can('edit_posts')) {
            wp_die('Permissão insuficiente.');
        }

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'pc_csv_download')) {
            wp_die('Requisição inválida.');
        }

        try {
            global $wpdb;
            $envios_table = $wpdb->prefix . 'envios_pendentes';
            $users_table = $wpdb->prefix . 'users';
            $ambiente_table = 'NOME_AMBIENTE';

            $filters = $this->collect_report_filters($_GET);
            $where_sql = $this->build_report_where_sql($filters);

            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME, $ambiente_table
            ));
            $join_ambiente = $table_exists ? "LEFT JOIN {$ambiente_table} T ON T.IDGIS_AMBIENTE = P.idgis_ambiente" : "";
            $select_ambiente = $table_exists ? "T.NOME_AMBIENTE AS ambiente," : "P.idgis_ambiente AS ambiente,";

            $max_csv_rows = 100000;
            $query = "
                SELECT
                    P.id,
                    CAST(P.data_cadastro AS DATE) AS data,
                    E.display_name AS usuario,
                    P.agendamento_id,
                    P.fornecedor,
                    {$select_ambiente}
                    P.idgis_ambiente,
                    P.telefone,
                    P.nome AS nome_cliente,
                    P.status,
                    P.cpf_cnpj,
                    P.idcob_contrato,
                    P.data_disparo
                FROM {$envios_table} P
                LEFT JOIN {$users_table} E ON E.ID = P.current_user_id
                {$join_ambiente}
                WHERE {$where_sql}
                ORDER BY P.data_cadastro DESC
                LIMIT {$max_csv_rows}
            ";

            $results = $wpdb->get_results($query, ARRAY_A);

            if (empty($results)) {
                wp_die('Nenhum registro encontrado com os filtros aplicados.');
            }

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="relatorio_geral_' . date('Y-m-d_His') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $headers = array_keys($results[0]);
            fputcsv($output, $headers, ';');

            foreach ($results as $row) {
                fputcsv($output, $row, ';');
            }

            fclose($output);
            exit;
        } catch (\Throwable $e) {
            error_log('[CSV Geral] Fatal: ' . $e->getMessage());
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'data' => 'Erro ao gerar CSV: ' . $e->getMessage()]);
            }
            exit;
        }
    }

    public function handle_download_csv_agendamento()
    {
        if (!is_user_logged_in()) {
            wp_die('Acesso negado. Faça login para continuar.');
        }
        if (!current_user_can('edit_posts')) {
            wp_die('Permissão insuficiente.');
        }

        if (!isset($_REQUEST['agendamento_id']) || empty($_REQUEST['agendamento_id'])) {
            wp_die('Agendamento ID não fornecido.');
        }

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'pc_csv_download')) {
            wp_die('Requisição inválida.');
        }

        try {
            $agendamento_id = sanitize_text_field($_REQUEST['agendamento_id']);

            global $wpdb;
            $table_envios = $wpdb->prefix . 'envios_pendentes';

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_envios} WHERE agendamento_id = %s ORDER BY id ASC LIMIT 100000",
                $agendamento_id
            ), ARRAY_A);

            if (empty($results)) {
                wp_die('Nenhum registro encontrado para este agendamento.');
            }

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="agendamento_' . $agendamento_id . '_' . date('Y-m-d_His') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $headers = array_keys($results[0]);
            fputcsv($output, $headers, ';');

            foreach ($results as $row) {
                fputcsv($output, $row, ';');
            }

            fclose($output);
            exit;
        } catch (\Throwable $e) {
            error_log('[CSV Agendamento] Fatal: ' . $e->getMessage());
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'data' => 'Erro ao gerar CSV: ' . $e->getMessage()]);
            }
            exit;
        }
    }

    /**
     * 🚀 OTIMIZAÇÃO: Busca todos os telefones recentes de uma vez
     */
    /**
     * 🚀 OTIMIZADO: Busca telefones recentes com query simples e normalização eficiente
     */
    private function get_recent_phones_batch($envios_table, $hours = 48)
    {
        global $wpdb;

        // 🚀 Query simples e rápida - usa índices em data_cadastro e status
        $hours_safe = intval($hours);
        $sql = "SELECT DISTINCT telefone 
                FROM {$envios_table} 
                WHERE data_cadastro >= DATE_SUB(NOW(), INTERVAL {$hours_safe} HOUR)
                  AND status IN ('enviado', 'pendente', 'pendente_aprovacao')
                  AND telefone IS NOT NULL 
                  AND telefone != ''
                LIMIT 100000";

        error_log('🔵 Executando query de telefones recentes...');
        $start_time = microtime(true);

        $recent_phones = $wpdb->get_col($sql);

        $query_time = microtime(true) - $start_time;
        error_log('🔵 Query executada em ' . round($query_time, 2) . 's. Telefones encontrados: ' . count($recent_phones));

        if (empty($recent_phones)) {
            return [];
        }

        // 🚀 Normalização otimizada em batch usando array_map
        error_log('🔵 Normalizando telefones...');
        $normalize_start = microtime(true);

        $phones_map = [];
        $batch_size = 1000;
        $total = count($recent_phones);

        // Processa em lotes para não sobrecarregar memória
        for ($i = 0; $i < $total; $i += $batch_size) {
            $batch = array_slice($recent_phones, $i, $batch_size);

            foreach ($batch as $phone) {
                // Normalização rápida: remove não numéricos
                $phone_normalized = preg_replace('/[^0-9]/', '', $phone);

                // Remove código do país (55) se presente
                if (strlen($phone_normalized) > 11 && substr($phone_normalized, 0, 2) === '55') {
                    $phone_normalized = substr($phone_normalized, 2);
                }

                // Só adiciona se tiver tamanho válido (10 ou 11 dígitos)
                if (strlen($phone_normalized) >= 10 && strlen($phone_normalized) <= 11) {
                    $phones_map[$phone_normalized] = true;
                }
            }
        }

        $normalize_time = microtime(true) - $normalize_start;
        error_log('🔵 Normalização concluída em ' . round($normalize_time, 2) . 's. Telefones únicos: ' . count($phones_map));

        return $phones_map;
    }

    /**
     * 🚀 OTIMIZAÇÃO: Insere múltiplos registros de uma vez
     */
    private function bulk_insert($table, $data_array)
    {
        global $wpdb;

        if (empty($data_array)) {
            return 0;
        }

        // Lazy migrations (Garantiro que colunas vitais novas existam caso usuário não reativou plugin)
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'id_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN id_carteira varchar(100) DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'idcob_contrato'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN idcob_contrato bigint(20) DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'midia_campanha'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN midia_campanha text DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'carteira_id'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN carteira_id bigint(20) DEFAULT NULL");
        }

        // Prepara valores para INSERT múltiplo
        $values = [];

        foreach ($data_array as $data) {
            $id_carteira = isset($data['id_carteira']) ? $data['id_carteira'] : '';
            $idcob_contrato = isset($data['idcob_contrato']) ? $data['idcob_contrato'] : 0;
            $midia_campanha = isset($data['midia_campanha']) ? $data['midia_campanha'] : '';
            $carteira_id = isset($data['carteira_id']) && $data['carteira_id'] !== null && $data['carteira_id'] !== '' ? intval($data['carteira_id']) : 0;

            $values[] = $wpdb->prepare(
                "(%s, %s, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d, %s)",
                $data['telefone'],
                $data['nome'],
                $data['idgis_ambiente'],
                $id_carteira,
                $carteira_id,
                $idcob_contrato,
                $data['cpf_cnpj'],
                $data['mensagem'],
                $midia_campanha,
                $data['fornecedor'],
                $data['agendamento_id'],
                $data['status'],
                $data['current_user_id'],
                $data['valido'],
                $data['data_cadastro']
            );
        }

        $sql = "INSERT INTO {$table} 
                (telefone, nome, idgis_ambiente, id_carteira, carteira_id, idcob_contrato, cpf_cnpj, mensagem, midia_campanha, fornecedor, agendamento_id, status, current_user_id, valido, data_cadastro) 
                VALUES " . implode(', ', $values);

        error_log('🔵 [bulk_insert] Inserindo ' . count($data_array) . ' registros na tabela ' . $table);

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('🚨 [ERRO MySQL bulk_insert] ' . $wpdb->last_error);
            error_log('🚨 [ERRO MySQL Query] ' . substr($sql, 0, 1000) . '...');
            return 0;
        }

        error_log('✅ [bulk_insert] ' . $result . ' registros inseridos com sucesso');
        return $result;
    }

    public function handle_get_recurring()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        global $wpdb;

        $table = $wpdb->prefix . 'cm_recurring_campaigns';
        $current_user_id = get_current_user_id();

        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE criado_por = %d ORDER BY criado_em DESC",
            $current_user_id
        ), ARRAY_A);

        foreach ($campaigns as &$campaign) {
            $filters = json_decode($campaign['filtros_json'] ?? '[]', true) ?: [];
            $providers_config = json_decode($campaign['providers_config'] ?? '{}', true) ?: [];
            $campaign['parsed_filters'] = $filters;

            // Calcula contagem estimada
            try {
                $count_filters = $filters;
                $exclude_recent = isset($providers_config['exclude_recent_phones'])
                    ? intval($providers_config['exclude_recent_phones']) : 1;
                if ($exclude_recent === 1) {
                    $count_filters[] = [
                        'field' => 'exclude_recent',
                        'operator' => 'exclude_recent',
                        'value' => 'true'
                    ];
                }
                $count = PC_Campaign_Filters::count_records($campaign['tabela_origem'], $count_filters);
                $record_limit = intval($campaign['record_limit'] ?? 0);
                if ($record_limit > 0 && $count > $record_limit) {
                    $count = $record_limit;
                }
                $campaign['estimated_count'] = $count;
            } catch (\Exception $e) {
                error_log('⚠️ [Recurring] Erro ao estimar contagem para campanha ' . $campaign['id'] . ': ' . $e->getMessage());
                $campaign['estimated_count'] = -1;
            }
        }
        unset($campaign);

        wp_send_json_success($campaigns);
    }

    public function handle_delete_recurring()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $current_user_id = get_current_user_id();
        $table = $wpdb->prefix . 'cm_recurring_campaigns';

        // Verifica se a campanha pertence ao usuário
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND criado_por = %d",
            $id,
            $current_user_id
        ), ARRAY_A);

        if (!$campaign) {
            wp_send_json_error('Campanha não encontrada ou você não tem permissão para deletá-la.');
            return;
        }

        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($result === false) {
            wp_send_json_error('Erro ao deletar campanha.');
        } else {
            wp_send_json_success('Campanha deletada com sucesso!');
        }
    }

    public function handle_get_recurring_estimates()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $current_user_id = get_current_user_id();
        $table = $wpdb->prefix . 'cm_recurring_campaigns';

        // Busca o filtro salvo
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND criado_por = %d",
            $id,
            $current_user_id
        ), ARRAY_A);

        if (!$campaign) {
            wp_send_json_error('Filtro salvo não encontrado.');
            return;
        }

        $table_name = $campaign['tabela_origem'];
        $filters_json = $campaign['filtros_json'];
        $providers_config_json = $campaign['providers_config'];

        $filters = json_decode($filters_json, true) ?: [];
        $providers_config = json_decode($providers_config_json, true) ?: [];
        $exclude_recent_phones = isset($providers_config['exclude_recent_phones']) ? intval($providers_config['exclude_recent_phones']) : 1;

        // Adiciona a regra de 24h se necessário
        if ($exclude_recent_phones === 1) {
            $filters[] = [
                'field' => 'exclude_recent',
                'operator' => 'exclude_recent',
                'value' => 'true'
            ];
        }

        $count = PC_Campaign_Filters::count_records($table_name, $filters);

        // Se houver limitador de registros configurado no filtro salvo, usamos o menor valor
        $record_limit = intval($campaign['record_limit']);
        if ($record_limit > 0 && $count > $record_limit) {
            $count = $record_limit;
        }

        wp_send_json_success(['estimate' => $count]);
    }

    public function handle_toggle_recurring()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $ativo = intval($_POST['ativo'] ?? 0);
        $current_user_id = get_current_user_id();
        $table = $wpdb->prefix . 'cm_recurring_campaigns';

        // Verifica se a campanha pertence ao usuário
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND criado_por = %d",
            $id,
            $current_user_id
        ), ARRAY_A);

        if (!$campaign) {
            wp_send_json_error('Campanha não encontrada ou você não tem permissão para alterá-la.');
            return;
        }

        $result = $wpdb->update(
            $table,
            ['ativo' => $ativo],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao atualizar status.');
        } else {
            wp_send_json_success($ativo ? 'Campanha ativada!' : 'Campanha desativada!');
        }
    }

    public function handle_execute_recurring_now()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $current_user_id = get_current_user_id();
        $table = $wpdb->prefix . 'cm_recurring_campaigns';

        // Busca a campanha recorrente
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND criado_por = %d",
            $id,
            $current_user_id
        ), ARRAY_A);

        if (!$campaign) {
            wp_send_json_error('Campanha não encontrada ou você não tem permissão para executá-la.');
            return;
        }

        // Verifica se a base está atualizada
        $table_name = $campaign['tabela_origem'];
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s 
             AND COLUMN_NAME = 'ult_atualizacao'",
            DB_NAME,
            $table_name
        ));

        if ($column_exists) {
            $table_name_escaped = esc_sql($table_name);
            $ult_atualizacao = $wpdb->get_var(
                "SELECT MAX(ult_atualizacao) FROM `{$table_name_escaped}`"
            );

            if (!empty($ult_atualizacao)) {
                $today = current_time('Y-m-d');
                $ult_atualizacao_date = date('Y-m-d', strtotime($ult_atualizacao));

                if ($ult_atualizacao_date !== $today) {
                    wp_send_json_error(
                        "Base desatualizada. A base '{$table_name}' não foi atualizada hoje. " .
                        "Última atualização: {$ult_atualizacao_date}. " .
                        "Não é possível executar campanhas com bases desatualizadas."
                    );
                    return;
                }
            }
        }

        if ($campaign['ativo'] != 1) {
            wp_send_json_error('Esta campanha está desativada. Ative-a antes de executar.');
            return;
        }

        $exclude_recent_execution = isset($_POST['exclude_recent_phones']) ? intval($_POST['exclude_recent_phones']) : null;

        // Se foi passado uma opção de exclusão na execução, sobrescreve a config salva
        if ($exclude_recent_execution !== null) {
            $providers_config = json_decode($campaign['providers_config'], true);
            if (!is_array($providers_config)) {
                $providers_config = [];
            }
            $providers_config['exclude_recent_phones'] = $exclude_recent_execution;
            $campaign['providers_config'] = json_encode($providers_config);
        }

        // Usa versão otimizada própria para melhor performance
        $result = $this->execute_recurring_campaign_optimized($campaign, $exclude_recent_execution);

        // Atualiza última execução
        $wpdb->update(
            $table,
            ['ultima_execucao' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'records_inserted' => $result['records_inserted'] ?? 0,
                'records_skipped' => $result['records_skipped'] ?? 0,
                'exclusion_enabled' => $exclude_recent_execution ?? 1
            ]);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function handle_preview_recurring_count()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $current_user_id = get_current_user_id();
        $table = $wpdb->prefix . 'cm_recurring_campaigns';

        // Verifica se a campanha pertence ao usuário
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND criado_por = %d",
            $id,
            $current_user_id
        ), ARRAY_A);

        if (!$campaign) {
            wp_send_json_error('Campanha não encontrada.');
            return;
        }

        $filters = json_decode($campaign['filtros_json'], true);
        if (!is_array($filters)) {
            $filters = [];
        }

        $total_count = PC_Campaign_Filters::count_records($campaign['tabela_origem'], $filters);
        $final_count = $campaign['record_limit'] > 0 ? min($total_count, $campaign['record_limit']) : $total_count;

        wp_send_json_success([
            'count' => $final_count,
            'total_available' => $total_count,
            'has_limit' => $campaign['record_limit'] > 0
        ]);
    }

    /**
     * 🚀 SELECT DIRETO: Busca registros filtrados sem overhead do Campaign Manager
     * @param bool $exclude_recent_phones Se true, faz LEFT JOIN para excluir telefones com envios recentes
     */
    private function get_filtered_records_optimized($table_name, $filters, $limit = 0, $exclude_recent_phones = false, $exclude_recent_hours = 48)
    {
        global $wpdb;

        if (empty($table_name)) {
            return [];
        }

        $envios_table = $wpdb->prefix . 'envios_pendentes';

        // Constrói WHERE direto
        $where_clauses = [];
        if (!empty($filters) && is_array($filters)) {
            foreach ($filters as $column => $filter_data) {
                if (!is_array($filter_data) || empty($filter_data['operator']) || !isset($filter_data['value']) || $filter_data['value'] === '') {
                    continue;
                }

                $sanitized_column = esc_sql(str_replace('`', '', $column));
                $operator = strtoupper($filter_data['operator']);
                $value = $filter_data['value'];

                if ($operator === 'IN' && is_array($value) && !empty($value)) {
                    $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                    $where_clauses[] = $wpdb->prepare(
                        "t.`{$sanitized_column}` IN ({$placeholders})",
                        $value
                    );
                } elseif (in_array($operator, ['=', '!=', '>', '<', '>=', '<='])) {
                    $where_clauses[] = $wpdb->prepare(
                        "t.`{$sanitized_column}` {$operator} %s",
                        $value
                    );
                }
            }
        }

        $where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : ' WHERE 1=1';

        $limit_sql = $limit > 0 ? $wpdb->prepare(" LIMIT %d", $limit) : '';

        // Dinamicamente monta o SELECT baseado nas colunas existentes para evitar erros de UNKNOWN COLUMN
        $columns = array_map('strtoupper', (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`"));

        $select_fields = [];

        // TELEFONE
        if (in_array('TELEFONE', $columns)) {
            $select_fields[] = 't.`TELEFONE` as telefone';
        } elseif (in_array('CELULAR', $columns)) {
            $select_fields[] = 't.`CELULAR` as telefone';
        } else {
            $select_fields[] = 'NULL as telefone';
        }

        // NOME
        if (in_array('NOME', $columns)) {
            $select_fields[] = 't.`NOME` as nome';
        } elseif (in_array('CLIENTE', $columns)) {
            $select_fields[] = 't.`CLIENTE` as nome';
        } else {
            $select_fields[] = 'NULL as nome';
        }

        // IDGIS_AMBIENTE
        if (in_array('IDGIS_AMBIENTE', $columns)) {
            $select_fields[] = 't.`IDGIS_AMBIENTE` as idgis_ambiente';
        } elseif (in_array('AMBIENTE', $columns)) {
            $select_fields[] = 't.`AMBIENTE` as idgis_ambiente';
        } else {
            $select_fields[] = '0 as idgis_ambiente';
        }

        // IDCOB_CONTRATO
        if (in_array('IDCOB_CONTRATO', $columns)) {
            $select_fields[] = 't.`IDCOB_CONTRATO` as idcob_contrato';
        } elseif (in_array('CONTRATO', $columns)) {
            $select_fields[] = 't.`CONTRATO` as idcob_contrato';
        } else {
            $select_fields[] = '0 as idcob_contrato';
        }

        // CPF_CNPJ
        if (in_array('CPF', $columns) && in_array('CPF_CNPJ', $columns)) {
            $select_fields[] = 'COALESCE(t.`CPF`, t.`CPF_CNPJ`) as cpf_cnpj';
        } elseif (in_array('CPF', $columns)) {
            $select_fields[] = 't.`CPF` as cpf_cnpj';
        } elseif (in_array('CPF_CNPJ', $columns)) {
            $select_fields[] = 't.`CPF_CNPJ` as cpf_cnpj';
        } else {
            $select_fields[] = 'NULL as cpf_cnpj';
        }

        $select_clause = implode(', ', $select_fields);

        // 🚀 OTIMIZAÇÃO: LEFT JOIN para excluir telefones recentes diretamente na query
        if ($exclude_recent_phones) {
            // Usa LEFT JOIN com WHERE IS NULL - muito mais rápido que NOT EXISTS
            $sql = "SELECT {$select_clause}
                    FROM `{$table_name}` t
                    LEFT JOIN {$envios_table} c ON (
                        -- Compara telefones (normaliza removendo caracteres não numéricos)
                        REGEXP_REPLACE(c.telefone, '[^0-9]', '') = REGEXP_REPLACE(t.TELEFONE, '[^0-9]', '')
                        OR
                        -- Remove código 55 se presente em ambos
                        (LENGTH(REGEXP_REPLACE(c.telefone, '[^0-9]', '')) > 11 
                         AND SUBSTRING(REGEXP_REPLACE(c.telefone, '[^0-9]', ''), 1, 2) = '55'
                         AND SUBSTRING(REGEXP_REPLACE(c.telefone, '[^0-9]', ''), 3) = REGEXP_REPLACE(t.TELEFONE, '[^0-9]', ''))
                        OR
                        (LENGTH(REGEXP_REPLACE(t.TELEFONE, '[^0-9]', '')) > 11 
                         AND SUBSTRING(REGEXP_REPLACE(t.TELEFONE, '[^0-9]', ''), 1, 2) = '55'
                         AND SUBSTRING(REGEXP_REPLACE(t.TELEFONE, '[^0-9]', ''), 3) = REGEXP_REPLACE(c.telefone, '[^0-9]', ''))
                    )
                    AND c.data_cadastro >= DATE_SUB(NOW(), INTERVAL " . intval($exclude_recent_hours) . " HOUR)
                    AND c.status IN ('enviado', 'pendente', 'pendente_aprovacao')
                    " . $where_sql . "
                    AND c.telefone IS NULL" . $limit_sql;

            // Se REGEXP_REPLACE não estiver disponível (MySQL < 8.0), usa versão compatível
            $mysql_version = $wpdb->get_var("SELECT VERSION()");
            if (version_compare($mysql_version, '8.0.0', '<')) {
                // Versão compatível: compara telefones diretamente (pode ter pequenas diferenças de formatação)
                $sql = "SELECT {$select_clause}
                        FROM `{$table_name}` t
                        LEFT JOIN {$envios_table} c ON (
                            c.telefone = t.TELEFONE
                            OR c.telefone LIKE CONCAT('%', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.TELEFONE, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '%')
                            OR t.TELEFONE LIKE CONCAT('%', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.telefone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '%')
                        )
                        AND c.data_cadastro >= DATE_SUB(NOW(), INTERVAL " . intval($exclude_recent_hours) . " HOUR)
                        AND c.status IN ('enviado', 'pendente', 'pendente_aprovacao')
                        " . $where_sql . "
                        AND c.telefone IS NULL" . $limit_sql;
            }
        } else {
            // SELECT direto - busca apenas campos necessários
            $sql = "SELECT {$select_clause}
                    FROM `{$table_name}` t" . $where_sql . $limit_sql;
        }

        $records = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            error_log('🔴 Erro ao buscar registros: ' . $wpdb->last_error);
            error_log('SQL: ' . $sql);
            return [];
        }

        // Retorna direto sem normalização desnecessária
        return $records ?: [];
    }

    /**
     * 🚀 VERSÃO OTIMIZADA: Executa campanha recorrente com inserção em lote
     */
    private function execute_recurring_campaign_optimized($campaign, $exclude_recent_execution)
    {
        global $wpdb;

        error_log('🔵 Painel Campanhas - Iniciando execução otimizada de campanha recorrente');
        $start_time = microtime(true);

        try {
            // 1. Decodifica configurações
            $filters = json_decode($campaign['filtros_json'], true);
            if (!is_array($filters)) {
                $filters = [];
            }

            $providers_config = json_decode($campaign['providers_config'], true);

            if (!$providers_config || empty($providers_config['providers'])) {
                return [
                    'success' => false,
                    'message' => 'Configuração de provedores inválida'
                ];
            }

            // Usa a opção de exclusão passada ou a configurada
            $exclude_recent_phones = $exclude_recent_execution !== null ? $exclude_recent_execution :
                (isset($providers_config['exclude_recent_phones']) ? intval($providers_config['exclude_recent_phones']) : 1);

            $exclude_recent_hours = isset($providers_config['exclude_recent_hours']) ? intval($providers_config['exclude_recent_hours']) : 48;

            // 2. 🚀 OTIMIZADO: Busca registros com SELECT direto + LEFT JOIN para excluir telefones recentes
            error_log('🔵 Buscando registros filtrados (SELECT direto com exclusão de telefones recentes)...');
            $step_start = microtime(true);
            $records = $this->get_filtered_records_optimized(
                $campaign['tabela_origem'],
                $filters,
                $campaign['record_limit'],
                $exclude_recent_phones, // Passa flag para fazer LEFT JOIN
                $exclude_recent_hours
            );
            error_log('🔵 Registros encontrados: ' . count($records) . ' em ' . round(microtime(true) - $step_start, 2) . 's');

            if (empty($records)) {
                return [
                    'success' => false,
                    'message' => 'Nenhum registro encontrado com os filtros aplicados'
                ];
            }

            // 3. 🎣 ADICIONA ISCAS ATIVAS (apenas com IDGIS compatível)
            $baits_count = 0;
            $all_baits = PC_Campaign_Baits::get_active_baits();
            $recurring_campaign_id_carteira_pre = !empty($campaign['carteira'])
                ? $this->resolve_id_carteira_from_carteira_id($campaign['carteira'])
                : '';
            if (!empty($all_baits)) {
                $idgis_found = [];

                foreach ($records as $record) {
                    if (!empty($record['idgis_ambiente'])) {
                        $idgis_found[$record['idgis_ambiente']] = true;
                    }
                }

                foreach ($all_baits as $bait) {
                    if (isset($idgis_found[$bait['idgis_ambiente']])) {
                        $bait_id_carteira = $bait['id_carteira'] ?? '';
                        if (!empty($bait_id_carteira)) {
                            $resolved = $this->resolve_id_carteira_from_carteira_id($bait_id_carteira);
                            if (!empty($resolved)) {
                                $bait_id_carteira = $resolved;
                            }
                        }
                        if (empty($bait_id_carteira) && !empty($recurring_campaign_id_carteira_pre)) {
                            $bait_id_carteira = $recurring_campaign_id_carteira_pre;
                        }
                        $records[] = [
                            'telefone' => $bait['telefone'],
                            'nome' => $bait['nome'] . ' [ISCA]',
                            'idgis_ambiente' => $bait['idgis_ambiente'],
                            'id_carteira' => $bait_id_carteira,
                            'idcob_contrato' => 0,
                            'cpf_cnpj' => ''
                        ];
                        $baits_count++;
                    }
                }
            }

            // 4. 🚀 OTIMIZAÇÃO: Exclusão de telefones recentes já feita no LEFT JOIN da query
            // Não precisa mais buscar telefones separadamente - já vem filtrado!

            // 5. Busca template
            $template_post = get_post($campaign['template_id']);
            if (!$template_post) {
                return [
                    'success' => false,
                    'message' => 'Template de mensagem não encontrado'
                ];
            }
            $mensagem_template = $template_post->post_content;

            // 6. Distribui registros entre provedores
            $distribution = $this->distribute_records_for_recurring($records, $providers_config);

            if (empty($distribution)) {
                return [
                    'success' => false,
                    'message' => 'Erro ao distribuir registros entre provedores'
                ];
            }

            // 7. Prepara todos os dados para inserção em lote
            error_log('🔵 Preparando dados para inserção...');
            $prep_start = microtime(true);
            $all_insert_data = [];
            $total_skipped = 0;
            $envios_table = $wpdb->prefix . 'envios_pendentes';
            $current_user_id = get_current_user_id();
            $agendamento_base_id = current_time('YmdHis');

            $campaign_carteira = $campaign['carteira'] ?? '';
            $recurring_campaign_id_carteira = !empty($campaign_carteira)
                ? $this->resolve_id_carteira_from_carteira_id($campaign_carteira)
                : '';

            foreach ($distribution as $provider => $provider_records) {
                error_log("🔵 Processando provedor {$provider}: " . count($provider_records) . " registros");
                $prefix = strtoupper(substr($provider, 0, 1));
                if ($provider === 'CDA_RCS') {
                    $prefix = 'R';
                }
                $campaign_name_clean = preg_replace('/[^a-zA-Z0-9]/', '', $campaign['nome_campanha']);
                $campaign_name_short = substr($campaign_name_clean, 0, 30);
                $agendamento_id = $prefix . $agendamento_base_id . '_' . $campaign_name_short;

                $template_source = $campaign['template_source'] ?? 'local';
                $template_code = $campaign['template_code'] ?? '';
                $broker_code = $campaign['broker_code'] ?? '';
                $customer_code = $campaign['customer_code'] ?? '';
                $generated_campaign_ids = [];

                foreach ($provider_records as $record) {
                    // 🚀 Telefones recentes já foram excluídos no LEFT JOIN da query
                    // Não precisa mais verificar aqui!

                    // Aplica mapeamento IDGIS
                    $idgis_original = intval($record['idgis_ambiente'] ?? 0);
                    $idgis_mapeado = $idgis_original;

                    if ($idgis_original > 0) {
                        $idgis_mapeado = PC_IDGIS_Mapper::get_mapped_idgis(
                            $campaign['tabela_origem'],
                            $provider,
                            $idgis_original
                        );
                    }

                    // Busca id_carteira: tabela+idgis ou herda da carteira da campanha recorrente
                    $id_carteira = $this->get_id_carteira_from_table_idgis($campaign['tabela_origem'], $idgis_mapeado) ?: $recurring_campaign_id_carteira;

                    // Prepara mensagem
                    $mensagem_final = $this->replace_placeholders($mensagem_template, $record);

                    // Para templates da Ótima, armazena template_code no campo mensagem
                    $mensagem_para_armazenar = $mensagem_final;
                    if (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code)) {
                        // broker_code = da campanha; customer_code = id_carteira do contato
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => $template_source,
                            'broker_code' => $broker_code,
                            'customer_code' => (string) $id_carteira,
                            'original_message' => $mensagem_final
                        ]);
                    }

                    if (!in_array($agendamento_id, $generated_campaign_ids)) {
                        $generated_campaign_ids[] = $agendamento_id;
                    }

                    $all_insert_data[] = [
                        'telefone' => $telefone_normalizado,
                        'nome' => $record['nome'] ?? '',
                        'idgis_ambiente' => $idgis_mapeado, // Mantém para compatibilidade
                        'id_carteira' => $id_carteira, // Novo campo
                        'idcob_contrato' => intval($record['idcob_contrato'] ?? 0),
                        'cpf_cnpj' => $record['cpf_cnpj'] ?? '',
                        'mensagem' => $mensagem_para_armazenar,
                        'fornecedor' => $provider,
                        'agendamento_id' => $agendamento_id,
                        'status' => 'pendente_aprovacao',
                        'current_user_id' => $current_user_id,
                        'valido' => 1,
                        'data_cadastro' => current_time('mysql')
                    ];
                }
            }

            error_log('🔵 Preparação concluída em ' . round(microtime(true) - $prep_start, 2) . 's. Total: ' . count($all_insert_data) . ' registros');

            // 8. 🚀 OTIMIZAÇÃO: Insere em lotes de 500 registros
            $total_inserted = 0;
            if (!empty($all_insert_data)) {
                error_log('🔵 Preparando inserção em lote de ' . count($all_insert_data) . ' registros...');
                $batch_size = 500;
                $batches = array_chunk($all_insert_data, $batch_size);
                error_log('🔵 Total de lotes: ' . count($batches));

                foreach ($batches as $batch_index => $batch) {
                    error_log("🔵 Inserindo lote " . ($batch_index + 1) . " de " . count($batches) . " (" . count($batch) . " registros)...");
                    $this->bulk_insert_recurring($envios_table, $batch);
                    $total_inserted += count($batch);
                }
                error_log('🔵 Inserção concluída! Total: ' . $total_inserted);
            }

            // Save Throttling Settings
            $throttling_type = $campaign['throttling_type'] ?? 'none';
            $throttling_config_json = $campaign['throttling_config'] ?? '{}';

            if ($throttling_type !== 'none' && !empty($generated_campaign_ids)) {
                $table_settings = $wpdb->prefix . 'pc_campaign_settings';
                foreach ($generated_campaign_ids as $camp_id) {
                    $wpdb->replace(
                        $table_settings,
                        [
                            'agendamento_id' => $camp_id,
                            'throttling_type' => $throttling_type,
                            'throttling_config' => $throttling_config_json,
                            'criado_em' => current_time('mysql')
                        ],
                        ['%s', '%s', '%s', '%s']
                    );
                }
            }

            if ($total_inserted === 0) {
                return [
                    'success' => false,
                    'message' => 'Nenhum registro foi agendado. Verifique os filtros e tente novamente.'
                ];
            }

            $skipped_message = '';
            if ($exclude_recent_phones && $total_skipped > 0) {
                $skipped_message = " | ⏭️ {$total_skipped} telefones excluídos (já receberam mensagem recentemente)";
            }

            $baits_message = '';
            if ($baits_count > 0) {
                $baits_message = " | 🎣 {$baits_count} iscas";
            }

            $duration = microtime(true) - $start_time;
            error_log('🔵 Execução concluída em ' . round($duration, 2) . ' segundos');

            return [
                'success' => true,
                'message' => sprintf(
                    'Campanha executada! %d registros agendados em %d provedor(es)%s%s',
                    $total_inserted,
                    count($distribution),
                    $baits_message,
                    $skipped_message
                ),
                'records_inserted' => $total_inserted,
                'records_skipped' => $total_skipped,
                'exclusion_enabled' => $exclude_recent_phones
            ];

        } catch (Exception $e) {
            error_log('Painel Campanhas - Erro ao executar template: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'Erro ao executar campanha: ' . $e->getMessage()
            ];
        }
    }

    private function extract_phone_for_recurring($record)
    {
        $phone = $record['telefone'] ?? '';
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 11 && substr($phone, 0, 2) === '55') {
            $phone = substr($phone, 2);
        }
        return $phone;
    }

    private function distribute_records_for_recurring($records, $providers_config)
    {
        $mode = $providers_config['mode'] ?? 'split';
        $providers = $providers_config['providers'] ?? [];
        $percentages = $providers_config['percentages'] ?? [];

        if (empty($providers)) {
            return [];
        }

        $distribution = [];

        if ($mode === 'all') {
            foreach ($providers as $provider) {
                $distribution[$provider] = $records;
            }
        } else {
            $total_records = count($records);
            $shuffled_records = $records;
            shuffle($shuffled_records);

            $start_index = 0;

            foreach ($providers as $i => $provider) {
                $percentage = $percentages[$provider] ?? (100 / count($providers));
                $count = (int) ceil(($percentage / 100) * $total_records);

                if ($i === count($providers) - 1) {
                    $count = $total_records - $start_index;
                }

                $provider_records = array_slice($shuffled_records, $start_index, $count);

                if (!empty($provider_records)) {
                    $distribution[$provider] = $provider_records;
                }

                $start_index += $count;

                if ($start_index >= $total_records) {
                    break;
                }
            }
        }

        return $distribution;
    }

    private function bulk_insert_recurring($table, $data_array)
    {
        global $wpdb;

        if (empty($data_array)) {
            return;
        }

        // Lazy migrations (Garantiro que colunas vitais novas existam caso usuário não reativou plugin)
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'id_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN id_carteira varchar(100) DEFAULT NULL");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'idcob_contrato'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN idcob_contrato bigint(20) DEFAULT NULL");
        }

        $values = [];

        foreach ($data_array as $data) {
            $id_carteira = isset($data['id_carteira']) ? $data['id_carteira'] : '';
            $idcob_contrato = isset($data['idcob_contrato']) ? $data['idcob_contrato'] : 0;

            $values[] = $wpdb->prepare(
                "(%s, %s, %d, %s, %d, %s, %s, %s, %s, %s, %d, %d, %s)",
                $data['telefone'],
                $data['nome'],
                $data['idgis_ambiente'],
                $id_carteira,
                $idcob_contrato,
                $data['cpf_cnpj'],
                $data['mensagem'],
                $data['fornecedor'],
                $data['agendamento_id'],
                $data['status'],
                $data['current_user_id'],
                $data['valido'],
                $data['data_cadastro']
            );
        }

        $sql = "INSERT INTO {$table} 
                (telefone, nome, idgis_ambiente, id_carteira, idcob_contrato, cpf_cnpj, mensagem, fornecedor, agendamento_id, status, current_user_id, valido, data_cadastro) 
                VALUES " . implode(', ', $values);

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('🚨 [ERRO MySQL bulk_insert_recurring] ' . $wpdb->last_error);
        }
    }

    // ========== HANDLERS PARA APROVAR CAMPANHAS ==========

    public function handle_get_pending_campaigns()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->prefix . 'users';

        $filter_agendamento = sanitize_text_field($_POST['filter_agendamento'] ?? '');
        $filter_fornecedor = sanitize_text_field($_POST['filter_fornecedor'] ?? '');

        $where = ["LOWER(TRIM(t1.status)) = 'pendente_aprovacao'"];

        if (!empty($filter_agendamento)) {
            $where[] = $wpdb->prepare("t1.agendamento_id LIKE %s", '%' . $wpdb->esc_like($filter_agendamento) . '%');
        }

        if (!empty($filter_fornecedor)) {
            $where[] = $wpdb->prepare("t1.fornecedor LIKE %s", '%' . $wpdb->esc_like($filter_fornecedor) . '%');
        }

        $where_sql = implode(' AND ', $where);

        // Query otimizada: agrupa corretamente e conta todos os registros
        $query = "
            SELECT
                t1.agendamento_id,
                MAX(t1.idgis_ambiente) AS idgis_ambiente,
                t1.fornecedor AS provider,
                MAX(t1.status) AS status,
                MIN(t1.data_cadastro) AS created_at,
                COUNT(*) AS total_clients,
                MAX(t1.current_user_id) AS current_user_id,
                COALESCE(MAX(u.display_name), 'Usuário Desconhecido') AS scheduled_by
            FROM `{$table}` AS t1
            LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
            WHERE {$where_sql}
            GROUP BY t1.agendamento_id, t1.fornecedor
            ORDER BY MIN(t1.data_cadastro) DESC
        ";

        error_log('🔵 [Aprovar Campanhas] Query: ' . $query);

        $results = $wpdb->get_results($query, ARRAY_A);

        error_log('🔵 [Aprovar Campanhas] Resultados encontrados: ' . count($results));
        if (!empty($results)) {
            error_log('🔵 [Aprovar Campanhas] Primeiro resultado: ' . print_r($results[0], true));
        }

        wp_send_json_success($results ?: []);
    }

    private function build_dispatch_url($microservice_url)
    {
        $base_url = rtrim($microservice_url, '/');

        // Remove /api se estiver na URL base (o NestJS não tem prefixo /api por padrão)
        if (substr($base_url, -4) === '/api') {
            $base_url = substr($base_url, 0, -4);
        }

        return $base_url . '/campaigns/dispatch';
    }

    public function handle_get_microservice_config()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $microservice_config = get_option('acm_microservice_config', []);
        $microservice_url = $microservice_config['url'] ?? '';
        $microservice_api_key = $microservice_config['api_key'] ?? '';
        $master_api_key = get_option('acm_master_api_key', '');

        // Usa a API key do microserviço, ou fallback para master API key
        $api_key = !empty($microservice_api_key) ? $microservice_api_key : $master_api_key;

        wp_send_json_success([
            'url' => $microservice_url,
            'api_key' => $api_key,
            'dispatch_url' => !empty($microservice_url) ? $this->build_dispatch_url($microservice_url) : ''
        ]);
    }

    public function handle_update_campaign_status()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        $agendamento_id = sanitize_text_field($_POST['agendamento_id'] ?? '');
        $new_status = sanitize_text_field($_POST['status'] ?? '');

        if (empty($agendamento_id) || empty($new_status)) {
            wp_send_json_error('Parâmetros inválidos');
            return;
        }

        $updated = $wpdb->update(
            $table,
            ['status' => $new_status],
            [
                'agendamento_id' => $agendamento_id,
                'status' => 'pendente_aprovacao'
            ],
            ['%s'],
            ['%s', '%s']
        );

        if ($updated === false) {
            wp_send_json_error('Erro ao atualizar status no banco de dados');
            return;
        }

        wp_send_json_success([
            'message' => 'Status atualizado com sucesso!',
            'agendamento_id' => $agendamento_id,
            'new_status' => $new_status
        ]);
    }

    public function handle_approve_campaign()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        $agendamento_id = sanitize_text_field($_POST['agendamento_id'] ?? '');
        $fornecedor = sanitize_text_field($_POST['fornecedor'] ?? '');

        if (empty($agendamento_id)) {
            wp_send_json_error('Agendamento ID é obrigatório');
            return;
        }

        // Busca configuração do microserviço
        $microservice_config = get_option('acm_microservice_config', []);
        $microservice_url = $microservice_config['url'] ?? '';
        $microservice_api_key = $microservice_config['api_key'] ?? '';
        $master_api_key = get_option('acm_master_api_key', '');

        if (empty($microservice_url)) {
            wp_send_json_error('URL do microserviço não configurada. Configure em API Manager.');
            return;
        }

        // Envia para o microserviço
        $api_key = trim(!empty($microservice_api_key) ? $microservice_api_key : $master_api_key);

        if (empty($api_key)) {
            wp_send_json_error('API Key não configurada. Configure em API Manager.');
            return;
        }

        // Endpoint correto: /campaigns/dispatch (sem /api, pois não há prefixo global)
        $base_url = rtrim($microservice_url, '/');

        // Remove /api se estiver na URL base (o NestJS não tem prefixo /api por padrão)
        if (substr($base_url, -4) === '/api') {
            $base_url = substr($base_url, 0, -4);
        }

        $dispatch_url = $base_url . '/campaigns/dispatch';

        $payload = [
            'agendamento_id' => $agendamento_id
        ];

        // Inclui credenciais estáticas no payload conforme o fornecedor
        $static_credentials = get_option('acm_static_credentials', []);
        $fornecedor_upper = strtoupper($fornecedor);

        if (!empty($static_credentials)) {
            if ($fornecedor_upper === 'SALESFORCE') {
                $payload['salesforce_credentials'] = [
                    'client_id' => $static_credentials['sf_client_id'] ?? '',
                    'client_secret' => $static_credentials['sf_client_secret'] ?? '',
                    'username' => $static_credentials['sf_username'] ?? '',
                    'password' => $static_credentials['sf_password'] ?? '',
                    'token_url' => $static_credentials['sf_token_url'] ?? 'https://concilig.my.salesforce.com/services/oauth2/token',
                    'api_url' => $static_credentials['sf_api_url'] ?? 'https://concilig.my.salesforce.com/services/data/v59.0/composite/sobjects',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do Salesforce incluídas no payload');
            } elseif ($fornecedor_upper === 'MKC' || $fornecedor_upper === 'MARKETING CLOUD') {
                $payload['mkc_credentials'] = [
                    'client_id' => $static_credentials['mkc_client_id'] ?? '',
                    'client_secret' => $static_credentials['mkc_client_secret'] ?? '',
                    'token_url' => $static_credentials['mkc_token_url'] ?? '',
                    'api_url' => $static_credentials['mkc_api_url'] ?? '',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do Marketing Cloud incluídas no payload');
            } elseif ($fornecedor_upper === 'CDA') {
                $payload['cda_credentials'] = [
                    'api_url' => $static_credentials['cda_api_url'] ?? '',
                    'api_key' => $static_credentials['cda_api_key'] ?? '',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do CDA incluídas no payload');
            } elseif ($fornecedor_upper === 'RCS') {
                $payload['rcs_credentials'] = [
                    'chave_api' => $static_credentials['rcs_chave_api'] ?? $static_credentials['rcs_token'] ?? '',
                    'base_url' => $static_credentials['rcs_base_url'] ?? 'https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do RCS incluídas no payload');
            } elseif (
                in_array($fornecedor_upper, ['OTIMA WPP', 'ÓTIMA WPP', 'OTIMA WHATSAPP', 'ÓTIMA WHATSAPP', 'OTIMAWPP', 'ÓTIMAWPP', 'OTIMAWHATSAPP', 'ÓTIMAWHATSAPP']) ||
                (strpos($fornecedor_upper, 'OTIMA') !== false && (strpos($fornecedor_upper, 'WPP') !== false || strpos($fornecedor_upper, 'WHATSAPP') !== false))
            ) {
                $payload['otima_wpp_credentials'] = [
                    'token' => $static_credentials['otima_wpp_token'] ?? '',
                    'broker_code' => $static_credentials['otima_wpp_broker_code'] ?? '',
                    'customer_code' => $static_credentials['otima_wpp_customer_code'] ?? '',
                    'api_url' => 'https://services.otima.digital/v1/whatsapp',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do Ótima WhatsApp incluídas no payload');
            } elseif (
                in_array($fornecedor_upper, ['OTIMA RCS', 'ÓTIMA RCS', 'OTIMARCS', 'ÓTIMARCS']) ||
                (strpos($fornecedor_upper, 'OTIMA') !== false && strpos($fornecedor_upper, 'RCS') !== false)
            ) {
                $payload['otima_rcs_credentials'] = [
                    'token' => $static_credentials['otima_rcs_token'] ?? '',
                    'broker_code' => '', // Vem do JSON da mensagem (template selecionado)
                    'customer_code' => '', // Vem do JSON da mensagem (id_carteira do contato)
                    'api_url' => 'https://services.otima.digital/v1/rcs',
                ];
                error_log('🔵 [Aprovar Campanha] Credenciais do Ótima RCS incluídas no payload');
            }
        }

        // Busca uma mensagem de exemplo para verificar se é template da Ótima
        $sample_message_query = $wpdb->prepare("
            SELECT mensagem
            FROM {$table}
            WHERE agendamento_id = %s
            AND status IN ('pendente_aprovacao', 'pendente')
            LIMIT 1
        ", $agendamento_id);

        $sample_message = $wpdb->get_var($sample_message_query);

        // Verifica se a mensagem contém template_code da Ótima
        if (!empty($sample_message)) {
            $message_data = json_decode($sample_message, true);
            if (is_array($message_data) && isset($message_data['template_code']) && isset($message_data['template_source'])) {
                $payload['template_code'] = $message_data['template_code'];
                $payload['template_source'] = $message_data['template_source'];
                error_log('🔵 [Aprovar Campanha] Template da Ótima detectado: ' . $message_data['template_code'] . ' (' . $message_data['template_source'] . ')');

                // Injeta broker_code e customer_code nas credenciais correspondentes
                $msg_broker = $message_data['broker_code'] ?? '';
                $msg_customer = $message_data['customer_code'] ?? '';

                if (!empty($msg_broker) || !empty($msg_customer)) {
                    $source = $message_data['template_source'] ?? '';
                    if ($source === 'otima_rcs' && isset($payload['otima_rcs_credentials'])) {
                        $payload['otima_rcs_credentials']['broker_code'] = $msg_broker;
                        $payload['otima_rcs_credentials']['customer_code'] = $msg_customer;
                        error_log('🔵 [Aprovar Campanha] broker_code/customer_code RCS injetados: ' . $msg_broker . ' / ' . $msg_customer);
                    } elseif ($source === 'otima_wpp' && isset($payload['otima_wpp_credentials'])) {
                        $payload['otima_wpp_credentials']['broker_code'] = $msg_broker;
                        $payload['otima_wpp_credentials']['customer_code'] = $msg_customer;
                        error_log('🔵 [Aprovar Campanha] broker_code/customer_code WPP injetados: ' . $msg_broker . ' / ' . $msg_customer);
                    }
                }
            }
        }

        // Verifica se é um provider customizado
        $custom_providers = get_option('acm_custom_providers', []);
        $provider_key_lower = strtolower($fornecedor);

        if (isset($custom_providers[$provider_key_lower])) {
            $custom_provider = $custom_providers[$provider_key_lower];

            // Busca os dados padrão da campanha
            $standard_data_query = $wpdb->prepare("
                SELECT 
                    CONCAT('55', telefone) as telefone,
                    nome,
                    idgis_ambiente,
                    idcob_contrato,
                    COALESCE(cpf_cnpj, '') as cpf_cnpj,
                    mensagem,
                    data_cadastro
                FROM {$table}
                WHERE agendamento_id = %s
                AND status IN ('pendente_aprovacao', 'pendente')
                LIMIT 1
            ", $agendamento_id);

            $standard_data = $wpdb->get_row($standard_data_query, ARRAY_A);

            if ($standard_data) {
                // Transforma os dados para o formato do provider customizado
                $transformed_data = $this->transform_data_for_custom_provider($provider_key_lower, $standard_data);

                if ($transformed_data) {
                    $payload['custom_provider_data'] = $transformed_data;
                    $payload['custom_provider_key'] = $provider_key_lower;

                    // Se o provider customizado requer credenciais, busca elas
                    if ($custom_provider['requires_credentials'] && !empty($custom_provider['credential_fields'])) {
                        $provider_credentials = get_option('acm_provider_credentials', []);
                        if (isset($provider_credentials[$provider_key_lower])) {
                            // Busca credenciais por env_id se disponível, senão pega a primeira
                            $env_ids = array_keys($provider_credentials[$provider_key_lower]);
                            if (!empty($env_ids)) {
                                $env_id = $env_ids[0]; // Pega o primeiro ambiente ou pode ser passado como parâmetro
                                $payload['custom_provider_credentials'] = $provider_credentials[$provider_key_lower][$env_id];
                            }
                        }
                    }

                    error_log('🔵 [Aprovar Campanha] Provider customizado detectado: ' . $provider_key_lower);
                    error_log('🔵 [Aprovar Campanha] Dados transformados: ' . json_encode($transformed_data, JSON_PRETTY_PRINT));
                }
            }
        }

        error_log('🔵 [Aprovar Campanha] ========================================');
        error_log('🔵 [Aprovar Campanha] URL do Microserviço: ' . $dispatch_url);
        error_log('🔵 [Aprovar Campanha] API Key: ' . substr($api_key, 0, 10) . '...' . substr($api_key, -4));
        error_log('🔵 [Aprovar Campanha] Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
        error_log('🔵 [Aprovar Campanha] Agendamento ID: ' . $agendamento_id);
        error_log('🔵 [Aprovar Campanha] Fornecedor: ' . $fornecedor);

        $start_time = microtime(true);

        $response = wp_remote_post($dispatch_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key
            ],
            'body' => json_encode($payload),
            'timeout' => 30,
            'sslverify' => false,
            'blocking' => true,
            'data_format' => 'body'
        ]);

        $elapsed_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log('🔵 [Aprovar Campanha] Tempo de resposta: ' . $elapsed_time . 'ms');

        // Se falhar a comunicação, mantém como pendente_aprovacao
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('🔴 [Aprovar Campanha] Erro WP: ' . $error_message);
            error_log('🔴 [Aprovar Campanha] Código do erro: ' . $error_code);
            error_log('🔴 [Aprovar Campanha] Dados do erro: ' . print_r($response->get_error_data(), true));
            wp_send_json_error('Erro ao comunicar com o microserviço: ' . $error_message . ' (Código: ' . $error_code . '). A campanha permanecerá pendente para nova tentativa.');
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        error_log('🔵 [Aprovar Campanha] Status HTTP: ' . $response_code);
        error_log('🔵 [Aprovar Campanha] Headers: ' . print_r($response_headers, true));
        error_log('🔵 [Aprovar Campanha] Body completo: ' . $response_body);

        // Aceita 202 (Accepted) e 200 (OK) como sucesso
        if ($response_code < 200 || $response_code >= 300) {
            error_log('🔴 [Aprovar Campanha] Erro HTTP: ' . $response_code . ' - ' . $response_body);
            $error_msg = 'Microserviço retornou erro (' . $response_code . ')';
            if (!empty($response_body)) {
                try {
                    $error_data = json_decode($response_body, true);
                    if (isset($error_data['message'])) {
                        $error_msg .= ': ' . $error_data['message'];
                    } elseif (isset($error_data['error'])) {
                        $error_msg .= ': ' . $error_data['error'];
                    } else {
                        $error_msg .= ': ' . substr($response_body, 0, 200);
                    }
                } catch (Exception $e) {
                    $error_msg .= ': ' . substr($response_body, 0, 200);
                }
            }
            $error_msg .= '. A campanha permanecerá pendente para nova tentativa.';
            wp_send_json_error($error_msg);
            return;
        }

        // Se sucesso, atualiza status para 'pendente' (será processado pelo microserviço)
        $updated = $wpdb->update(
            $table,
            ['status' => 'pendente'],
            [
                'agendamento_id' => $agendamento_id,
                'status' => 'pendente_aprovacao'
            ],
            ['%s'],
            ['%s', '%s']
        );

        if ($updated === false) {
            error_log('🔴 Erro ao atualizar status no banco');
            wp_send_json_error('Erro ao atualizar status no banco de dados');
            return;
        }

        error_log('🔵 Campanha aprovada e enviada com sucesso!');
        wp_send_json_success([
            'message' => 'Campanha aprovada e enviada ao microserviço com sucesso!',
            'agendamento_id' => $agendamento_id
        ]);
    }

    public function handle_deny_campaign()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        $agendamento_id = sanitize_text_field($_POST['agendamento_id'] ?? '');

        if (empty($agendamento_id)) {
            wp_send_json_error('Agendamento ID é obrigatório');
            return;
        }

        $updated = $wpdb->update(
            $table,
            ['status' => 'negado'],
            [
                'agendamento_id' => $agendamento_id,
                'status' => 'pendente_aprovacao'
            ],
            ['%s'],
            ['%s', '%s']
        );

        if ($updated === false) {
            wp_send_json_error('Erro ao atualizar status no banco de dados');
            return;
        }

        wp_send_json_success([
            'message' => 'Campanha negada com sucesso!',
            'agendamento_id' => $agendamento_id
        ]);
    }

    public function get_credentials_rest($request)
    {
        $provider = strtoupper($request->get_param('provider'));
        $env_id = $request->get_param('env_id');

        // Log para debug
        error_log('🔵 [REST API] Buscando credenciais: Provider=' . $provider . ', EnvId=' . $env_id);

        // Lista de providers que usam credenciais estáticas
        // Para Ótima, verificamos se contém "OTIMA" no nome (case-insensitive)
        $static_providers = ['RCS', 'CDA', 'SALESFORCE', 'MKC', 'GOSAC_OFICIAL', 'ROBBU_OFICIAL'];

        // Verifica se é provider estático (incluindo variações de Ótima)
        $is_static_provider = in_array($provider, $static_providers) ||
            (stripos($provider, 'OTIMA') !== false);

        if ($is_static_provider) {
            // Para providers estáticos, ignoramos o envId
            error_log('🔵 [REST API] Provider estático detectado: ' . $provider . ' (envId ignorado)');

            // Retorna credenciais estáticas
            $static_credentials = get_option('acm_static_credentials', []);

            $credentials = [];

            if ($provider === 'RCS') {
                // RCS CDA (CromosApp) - funciona igual ao CDA
                // codigo_equipe = idgis_ambiente (vem dos dados da campanha)
                // codigo_usuario = sempre '1'
                // chave_api = vem das credenciais estáticas
                // Chave API RCS: mesma do CDA WPP se rcs_chave_api não configurada (?: trata string vazia)
                $chave_api = trim($static_credentials['rcs_chave_api'] ?? '') ?: trim($static_credentials['rcs_token'] ?? '') ?: trim($static_credentials['cda_api_key'] ?? '');

                error_log('🔵 [REST API] Credenciais RCS encontradas: chave_api=' . (!empty($chave_api) ? 'SIM' : 'NÃO'));

                if (empty($chave_api)) {
                    $error_message = 'Credenciais RCS incompletas. Configure a Chave API no API Manager. Acesse /painel/api-manager e preencha o campo "Chave API" na seção "Static Provider Credentials" > "RCS CDA (CromosApp)".';
                    error_log('🔴 [REST API] Credenciais RCS incompletas. Faltando: chave_api');

                    return new WP_Error(
                        'invalid_credentials',
                        $error_message,
                        [
                            'status' => 400,
                            'code' => 'INCOMPLETE_RCS_CREDENTIALS',
                            'missing_fields' => ['chave_api'],
                            'provider' => 'RCS'
                        ]
                    );
                }

                // Retorna apenas chave_api e base_url
                // codigo_equipe e codigo_usuario serão definidos no microserviço usando idgis_ambiente e '1'
                $credentials = [
                    'chave_api' => $chave_api,
                    'base_url' => $static_credentials['rcs_base_url'] ?? 'https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI',
                ];

                error_log('✅ [REST API] Credenciais RCS retornadas com sucesso (codigo_equipe e codigo_usuario serão definidos no microserviço)');
            } elseif ($provider === 'CDA') {
                $credentials = [
                    'api_url' => $static_credentials['cda_api_url'] ?? '',
                    'api_key' => $static_credentials['cda_api_key'] ?? '',
                ];
            } elseif ($provider === 'SALESFORCE') {
                $credentials = [
                    'client_id' => $static_credentials['sf_client_id'] ?? '',
                    'client_secret' => $static_credentials['sf_client_secret'] ?? '',
                    'username' => $static_credentials['sf_username'] ?? '',
                    'password' => $static_credentials['sf_password'] ?? '',
                    'token_url' => $static_credentials['sf_token_url'] ?? 'https://concilig.my.salesforce.com/services/oauth2/token',
                    'api_url' => $static_credentials['sf_api_url'] ?? 'https://concilig.my.salesforce.com/services/data/v59.0/composite/sobjects',
                ];
            } elseif ($provider === 'MKC') {
                $credentials = [
                    'client_id' => $static_credentials['mkc_client_id'] ?? '',
                    'client_secret' => $static_credentials['mkc_client_secret'] ?? '',
                    'token_url' => $static_credentials['mkc_token_url'] ?? '',
                    'api_url' => $static_credentials['mkc_api_url'] ?? '',
                ];
            } elseif ((stripos($provider, 'OTIMA') !== false && (stripos($provider, 'WPP') !== false || stripos($provider, 'WHATSAPP') !== false))) {
                $token = $static_credentials['otima_wpp_token'] ?? '';
                // As credenciais dinâmicas do broker e customer code virão injetadas via formData original
                // Então apenas enviamos o token principal por enquanto.

                if (empty($token)) {
                    $error_message = 'Credenciais Ótima WhatsApp incompletas. Configure o Token no API Manager. Acesse /painel/api-manager e preencha o campo "Token de Autenticação" na seção "Static Provider Credentials" > "Ótima WhatsApp".';
                    error_log('🔴 [REST API] Credenciais Ótima WhatsApp incompletas. Faltando: token');

                    return new WP_Error(
                        'invalid_credentials',
                        $error_message,
                        [
                            'status' => 400,
                            'code' => 'INCOMPLETE_OTIMA_WPP_CREDENTIALS',
                            'missing_fields' => ['token'],
                            'provider' => 'OTIMA WPP'
                        ]
                    );
                }

                $credentials = [
                    'token' => $token,
                    'api_url' => 'https://services.otima.digital/v1/whatsapp',
                ];

                error_log('✅ [REST API] Credenciais Ótima WhatsApp retornadas com sucesso');
            } elseif (stripos($provider, 'OTIMA') !== false && stripos($provider, 'RCS') !== false) {
                $token = $static_credentials['otima_rcs_token'] ?? '';

                if (empty($token)) {
                    $error_message = 'Credenciais Ótima RCS incompletas. Configure o Token no API Manager. Acesse /painel/api-manager e preencha os campos na seção "Static Provider Credentials" > "Ótima RCS".';
                    error_log('🔴 [REST API] Credenciais Ótima RCS incompletas. Faltando token');

                    return new WP_Error(
                        'invalid_credentials',
                        $error_message,
                        [
                            'status' => 400,
                            'code' => 'INCOMPLETE_OTIMA_RCS_CREDENTIALS',
                            'provider' => 'OTIMA RCS'
                        ]
                    );
                }

                // broker_code e customer_code vêm do JSON da mensagem (template + id_carteira)
                $credentials = [
                    'token' => $token,
                    'api_url' => 'https://services.otima.digital/v1/rcs',
                    'broker_code' => '',
                    'customer_code' => '',
                ];

                error_log('✅ [REST API] Credenciais Ótima RCS retornadas (broker/customer vêm do JSON da mensagem)');
            } elseif ($provider === 'GOSAC_OFICIAL') {
                global $wpdb;
                $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';
                $nome = trim(urldecode((string) $env_id));
                $carteira = $wpdb->get_row($wpdb->prepare(
                    "SELECT id_carteira, id_ruler FROM $carteiras_table WHERE nome = %s AND ativo = 1 LIMIT 1",
                    $nome
                ), ARRAY_A);

                $credentials = [
                    'token' => $static_credentials['gosac_oficial_token'] ?? '',
                    'url' => $static_credentials['gosac_oficial_url'] ?? '',
                    'idRuler' => $carteira['id_ruler'] ?? '',
                    'id_carteira' => $carteira['id_carteira'] ?? '',
                ];

                error_log('✅ [REST API] GOSAC_OFICIAL nome=' . $nome . ' -> id_carteira=' . ($credentials['id_carteira'] ?? '') . ', idRuler=' . ($credentials['idRuler'] ?? ''));
            } elseif ($provider === 'ROBBU_OFICIAL') {
                $credentials = [
                    'company' => $static_credentials['robbu_company'] ?? '',
                    'username' => $static_credentials['robbu_username'] ?? '',
                    'password' => $static_credentials['robbu_password'] ?? '',
                    'invenio_private_token' => $static_credentials['robbu_invenio_token'] ?? '',
                ];
                error_log('✅ [REST API] Credenciais Robbu Oficial retornadas (estáticas)');
            }

            if (empty($credentials) || !$this->has_valid_credentials($credentials)) {
                return new WP_Error('no_credentials', 'Credenciais estáticas não configuradas para ' . $provider, ['status' => 404]);
            }

            return rest_ensure_response($credentials);
        } else {
            // Providers dinâmicos (GOSAC, NOAH) - busca credenciais por envId
            global $wpdb;
            $table = $wpdb->prefix . 'api_consumer_credentials';

            $query = "";
            if (is_numeric($env_id)) {
                // Tenta buscar no wp_api_consumer_credentials pelo numero primeiro se for numerico
                // Ou pela string pura.
                $query = $wpdb->prepare("
                    SELECT credentials
                    FROM {$table}
                    WHERE provider = %s AND (env_id = %s OR env_id = %d)
                    LIMIT 1
                ", $provider, $env_id, intval($env_id));
            } else {
                $query = $wpdb->prepare("
                    SELECT credentials
                    FROM {$table}
                    WHERE provider = %s AND env_id = %s
                    LIMIT 1
                ", $provider, $env_id);
            }

            $result = $wpdb->get_var($query);

            if (empty($result)) {
                // Fallback: NOAH_OFICIAL usa acm_provider_credentials (API Manager)
                if (strtoupper($provider) === 'NOAH_OFICIAL') {
                    $acm_creds = get_option('acm_provider_credentials', []);
                    $provider_key = 'noah_oficial';
                    if (isset($acm_creds[$provider_key][$env_id]) && $this->has_valid_credentials($acm_creds[$provider_key][$env_id])) {
                        return rest_ensure_response($acm_creds[$provider_key][$env_id]);
                    }
                }
                return new WP_Error('no_credentials', 'Credenciais não encontradas para ' . $provider . ':' . $env_id, ['status' => 404]);
            }

            $credentials = maybe_unserialize($result);
            return rest_ensure_response($credentials);
        }
    }

    private function has_valid_credentials($credentials)
    {
        // Verifica se pelo menos um campo não está vazio
        foreach ($credentials as $value) {
            if (!empty($value)) {
                return true;
            }
        }
        return false;
    }

    public function handle_webhook_status_update($request)
    {
        error_log('🔵 [Webhook] Recebendo atualização de status');

        $body = $request->get_json_params();

        if (empty($body)) {
            error_log('🔴 [Webhook] Body vazio');
            return new WP_Error('invalid_request', 'Body vazio', ['status' => 400]);
        }

        // Bulk mode: { bulk: true, updates: [ ...payloads ] }
        if (!empty($body['bulk']) && is_array($body['updates'] ?? null)) {
            return $this->handle_webhook_bulk_update($body['updates']);
        }

        return $this->process_single_webhook_update($body);
    }

    private function handle_webhook_bulk_update(array $updates)
    {
        error_log('📦 [Webhook] Processando bulk update: ' . count($updates) . ' itens');

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $has_resposta_api = in_array('resposta_api', $columns);

        $results = [];
        $total_updated = 0;
        $total_errors = 0;

        $status_map = [
            'enviado' => 'enviado',
            'erro_envio' => 'erro',
            'erro_credenciais' => 'erro',
            'erro_validacao' => 'erro',
            'processando' => 'pendente',
            'iniciado' => 'enviado',
            'erro_inicio' => 'erro',
            'mkc_executado' => 'enviado',
            'mkc_erro' => 'erro',
        ];

        foreach ($updates as $item) {
            $agendamento_id = sanitize_text_field($item['agendamento_id'] ?? '');
            $status = sanitize_text_field($item['status'] ?? '');

            if (empty($agendamento_id) || empty($status)) {
                $total_errors++;
                $results[] = ['agendamento_id' => $agendamento_id, 'ok' => false, 'reason' => 'missing_fields'];
                continue;
            }

            $wp_status = $status_map[$status] ?? 'erro';
            $data_disparo = sanitize_text_field($item['data_disparo'] ?? '');
            $resposta_api = sanitize_textarea_field($item['resposta_api'] ?? '');

            $update_data = ['status' => $wp_status];
            $update_formats = ['%s'];

            if (!empty($data_disparo)) {
                $update_data['data_disparo'] = date('Y-m-d H:i:s', strtotime($data_disparo));
                $update_formats[] = '%s';
            }

            if ($has_resposta_api && !empty($resposta_api)) {
                $resposta_decoded = json_decode($resposta_api, true);
                $update_data['resposta_api'] = (json_last_error() === JSON_ERROR_NONE)
                    ? json_encode($resposta_decoded, JSON_UNESCAPED_UNICODE)
                    : $resposta_api;
                $update_formats[] = '%s';
            }

            $updated = $wpdb->update(
                $table,
                $update_data,
                ['agendamento_id' => $agendamento_id],
                $update_formats,
                ['%s']
            );

            if ($updated === false) {
                $total_errors++;
                $results[] = ['agendamento_id' => $agendamento_id, 'ok' => false, 'reason' => $wpdb->last_error];
            } else {
                $total_updated += $updated;
                $results[] = ['agendamento_id' => $agendamento_id, 'ok' => true, 'records' => $updated];
            }
        }

        error_log("📦 [Webhook] Bulk concluído: {$total_updated} registros atualizados, {$total_errors} erros");

        return rest_ensure_response([
            'success' => $total_errors === 0,
            'message' => "Bulk update: {$total_updated} registros atualizados, {$total_errors} erros",
            'total_items' => count($updates),
            'total_records_updated' => $total_updated,
            'total_errors' => $total_errors,
            'results' => $results,
        ]);
    }

    private function process_single_webhook_update(array $body)
    {
        $agendamento_id = sanitize_text_field($body['agendamento_id'] ?? '');
        $status = sanitize_text_field($body['status'] ?? '');
        $provider = sanitize_text_field($body['provider'] ?? '');
        $resposta_api = sanitize_textarea_field($body['resposta_api'] ?? '');
        $data_disparo = sanitize_text_field($body['data_disparo'] ?? '');
        $total_enviados = intval($body['total_enviados'] ?? 0);
        $total_falhas = intval($body['total_falhas'] ?? 0);

        error_log('🔵 [Webhook] Agendamento ID: ' . $agendamento_id);
        error_log('🔵 [Webhook] Status: ' . $status);
        error_log('🔵 [Webhook] Provider: ' . $provider);

        if (empty($agendamento_id) || empty($status)) {
            error_log('🔴 [Webhook] Dados incompletos: agendamento_id=' . $agendamento_id . ', status=' . $status);
            return new WP_Error('invalid_request', 'agendamento_id e status são obrigatórios', ['status' => 400]);
        }

        $status_map = [
            'enviado' => 'enviado',
            'erro_envio' => 'erro',
            'erro_credenciais' => 'erro',
            'erro_validacao' => 'erro',
            'processando' => 'pendente',
            'iniciado' => 'enviado',
            'erro_inicio' => 'erro',
            'mkc_executado' => 'enviado',
            'mkc_erro' => 'erro',
        ];

        $wp_status = $status_map[$status] ?? 'erro';

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';

        $update_data = ['status' => $wp_status];
        $update_formats = ['%s'];

        if (!empty($data_disparo)) {
            $update_data['data_disparo'] = date('Y-m-d H:i:s', strtotime($data_disparo));
            $update_formats[] = '%s';
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (in_array('resposta_api', $columns) && !empty($resposta_api)) {
            $resposta_decoded = json_decode($resposta_api, true);
            $update_data['resposta_api'] = (json_last_error() === JSON_ERROR_NONE)
                ? json_encode($resposta_decoded, JSON_UNESCAPED_UNICODE)
                : $resposta_api;
            $update_formats[] = '%s';
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            ['agendamento_id' => $agendamento_id],
            $update_formats,
            ['%s']
        );

        if ($updated === false) {
            error_log('🔴 [Webhook] Erro ao atualizar status no banco de dados: ' . $wpdb->last_error);
            return new WP_Error('database_error', 'Erro ao atualizar status no banco de dados: ' . $wpdb->last_error, ['status' => 500]);
        }

        error_log('✅ [Webhook] Status atualizado com sucesso: ' . $agendamento_id . ' -> ' . $wp_status . ' (' . $updated . ' registros)');

        return rest_ensure_response([
            'success' => true,
            'message' => 'Status atualizado com sucesso',
            'agendamento_id' => $agendamento_id,
            'status' => $wp_status,
            'records_updated' => $updated,
            'total_enviados' => $total_enviados,
            'total_falhas' => $total_falhas
        ]);
    }

    /**
     * Webhook Robbu/Invenio - recebe eventos em tempo real.
     * URL para cadastrar: https://SEU-SITE.com/wp-json/robbu-webhook/v2/receive
     * IPs Robbu (liberar no firewall se necessário): 104.41.15.44, 104.41.14.184, 104.41.13.132, etc.
     */
    public function handle_robbu_webhook_receive($request)
    {
        // GET: validação/ping (Robbu ou teste manual) - retorna 200 OK
        if ($request->get_method() === 'GET') {
            error_log('🔵 [Robbu Webhook] GET recebido (validação/ping) - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return new WP_REST_Response(['ok' => true, 'message' => 'Webhook Robbu ativo', 'method' => 'GET'], 200);
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $raw = $request->get_body();
            $body = json_decode($raw, true);
        }
        if (!is_array($body)) {
            error_log('🔴 [Robbu Webhook] POST com body inválido ou vazio - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return new WP_REST_Response(['ok' => true, 'message' => 'Received'], 200);
        }

        error_log('🔵 [Robbu Webhook] POST recebido - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' - Items: ' . (isset($body[0]) ? count($body) : 1));

        global $wpdb;
        $table_events = $wpdb->prefix . 'pc_robbu_webhook_events';
        $table_lines = $wpdb->prefix . 'pc_robbu_line_status';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_events'") !== $table_events) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $this->create_robbu_webhook_tables();
        }

        $processed = 0;
        $items = isset($body[0]) ? $body : [$body];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['whatsappNumber'])) {
                $wn = $item['whatsappNumber'];
                if (is_array($wn)) {
                    $line_id = intval($wn['id'] ?? 0);
                    if ($line_id > 0) {
                        $wpdb->replace($table_lines, [
                            'robbu_line_id' => $line_id,
                            'wallet_id' => isset($wn['walletId']) ? intval($wn['walletId']) : null,
                            'status' => sanitize_text_field($wn['status'] ?? ''),
                            'country_code' => sanitize_text_field($wn['countryCode'] ?? ''),
                            'area_code' => sanitize_text_field($wn['areaCode'] ?? ''),
                            'phone_number' => sanitize_text_field($wn['phoneNumber'] ?? ''),
                            'is_active' => !empty($wn['isActive']) ? 1 : 0,
                            'broadcast_limit_per_day' => isset($wn['broadcastLimitPerDay']) ? intval($wn['broadcastLimitPerDay']) : null,
                            'can_send_hsm' => !empty($wn['canSendHsm']) ? 1 : 0,
                            'event_at' => !empty($wn['eventAt']) ? date('Y-m-d H:i:s', strtotime($wn['eventAt'])) : null,
                        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']);
                        $processed++;
                    }
                }
            }

            $event_type = array_key_first($item);
            if ($event_type) {
                $wpdb->insert($table_events, [
                    'event_type' => $event_type,
                    'payload_json' => wp_json_encode($item),
                    'processed' => isset($item['whatsappNumber']) ? 1 : 0,
                ], ['%s', '%s', '%d']);
            }
        }

        error_log('✅ [Robbu Webhook] Recebidos ' . count($items) . ' itens, processados ' . $processed . ' linhas');
        return new WP_REST_Response(['ok' => true, 'received' => count($items), 'processed' => $processed], 200);
    }

    private function create_robbu_webhook_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_events = $wpdb->prefix . 'pc_robbu_webhook_events';
        $sql_events = "CREATE TABLE IF NOT EXISTS $table_events (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            payload_json longtext,
            processed tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta($sql_events);

        $table_lines = $wpdb->prefix . 'pc_robbu_line_status';
        $sql_lines = "CREATE TABLE IF NOT EXISTS $table_lines (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            robbu_line_id bigint(20) NOT NULL,
            wallet_id bigint(20) DEFAULT NULL,
            status varchar(20) DEFAULT NULL,
            country_code varchar(5) DEFAULT NULL,
            area_code varchar(10) DEFAULT NULL,
            phone_number varchar(20) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            broadcast_limit_per_day int(11) DEFAULT NULL,
            can_send_hsm tinyint(1) DEFAULT 1,
            event_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_robbu_line (robbu_line_id),
            KEY idx_wallet (wallet_id),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql_lines);
    }

    // ========== HANDLERS PARA CONTROLE DE CUSTO ==========

    public function handle_save_custo_provider()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $custo_por_disparo = floatval($_POST['custo_por_disparo'] ?? 0);

        if (empty($provider) || $custo_por_disparo < 0) {
            wp_send_json_error('Dados inválidos');
        }

        $table = $wpdb->prefix . 'pc_custos_providers';

        // Verifica se já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE provider = %s",
            $provider
        ));

        if ($exists) {
            // Atualiza
            $result = $wpdb->update(
                $table,
                ['custo_por_disparo' => $custo_por_disparo],
                ['provider' => $provider],
                ['%f'],
                ['%s']
            );
        } else {
            // Insere
            $result = $wpdb->insert(
                $table,
                [
                    'provider' => $provider,
                    'custo_por_disparo' => $custo_por_disparo
                ],
                ['%s', '%f']
            );
        }

        if ($result === false) {
            wp_send_json_error('Erro ao salvar custo');
        }

        wp_send_json_success('Custo salvo com sucesso');
    }

    public function handle_get_custos_providers()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table = $wpdb->prefix . 'pc_custos_providers';
        $custos = $wpdb->get_results(
            "SELECT * FROM $table WHERE ativo = 1 ORDER BY provider",
            ARRAY_A
        );

        wp_send_json_success($custos ?: []);
    }

    public function handle_delete_custo_provider()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table = $wpdb->prefix . 'pc_custos_providers';
        $result = $wpdb->update(
            $table,
            ['ativo' => 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao excluir custo');
        }

        wp_send_json_success('Custo excluído com sucesso');
    }

    public function handle_save_orcamento_base()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $nome_base = sanitize_text_field($_POST['nome_base'] ?? '');
        $orcamento_total = floatval($_POST['orcamento_total'] ?? 0);
        $mes = intval($_POST['mes'] ?? 0);
        $ano = intval($_POST['ano'] ?? 0);

        if (empty($nome_base) || $orcamento_total < 0 || $mes <= 0 || $ano <= 0) {
            wp_send_json_error('Dados inválidos. Verifique carteira, orçamento, mês e ano.');
        }

        $table = $wpdb->prefix . 'pc_orcamentos_bases';

        // Verifica se já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE nome_base = %s AND mes = %d AND ano = %d",
            $nome_base,
            $mes,
            $ano
        ));

        if ($exists) {
            // Atualiza
            $result = $wpdb->update(
                $table,
                ['orcamento_total' => $orcamento_total],
                ['nome_base' => $nome_base, 'mes' => $mes, 'ano' => $ano],
                ['%f'],
                ['%s', '%d', '%d']
            );
        } else {
            // Insere
            $result = $wpdb->insert(
                $table,
                [
                    'nome_base' => $nome_base,
                    'orcamento_total' => $orcamento_total,
                    'mes' => $mes,
                    'ano' => $ano
                ],
                ['%s', '%f', '%d', '%d']
            );
        }

        if ($result === false) {
            wp_send_json_error('Erro ao salvar orçamento');
        }

        wp_send_json_success('Orçamento salvo com sucesso');
    }

    public function handle_get_orcamentos_bases()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table = $wpdb->prefix . 'pc_orcamentos_bases';
        $mes = intval($_POST['mes'] ?? $_REQUEST['mes'] ?? 0);
        $ano = intval($_POST['ano'] ?? $_REQUEST['ano'] ?? 0);

        $where = "WHERE 1=1";
        if ($mes > 0) {
            $where .= $wpdb->prepare(" AND mes = %d", $mes);
        }
        if ($ano > 0) {
            $where .= $wpdb->prepare(" AND ano = %d", $ano);
        }

        $orcamentos = $wpdb->get_results(
            "SELECT * FROM $table $where ORDER BY nome_base",
            ARRAY_A
        );

        wp_send_json_success($orcamentos ?: []);
    }

    public function handle_delete_orcamento_base()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table = $wpdb->prefix . 'pc_orcamentos_bases';
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($result === false) {
            wp_send_json_error('Erro ao excluir orçamento');
        }

        wp_send_json_success('Orçamento excluído com sucesso');
    }

    public function handle_get_relatorio_custos()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $base_filter = sanitize_text_field($_POST['base'] ?? '');
        $data_inicio = sanitize_text_field($_POST['data_inicio'] ?? '');
        $data_fim = sanitize_text_field($_POST['data_fim'] ?? '');

        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $custos_table = $wpdb->prefix . 'pc_custos_providers';
        $orcamentos_table = $wpdb->prefix . 'pc_orcamentos_bases';

        // Query base para envios
        $where_conditions = ["status = 'enviado'"];

        if ($base_filter) {
            // Precisa identificar a base pelo agendamento_id ou outra forma
            // Por enquanto, vamos usar uma abordagem mais simples
        }

        if ($data_inicio) {
            $where_conditions[] = $wpdb->prepare("DATE(data_cadastro) >= %s", $data_inicio);
        }

        if ($data_fim) {
            $where_conditions[] = $wpdb->prepare("DATE(data_cadastro) <= %s", $data_fim);
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Gastos por provider
        $gastos_providers = $wpdb->get_results("
            SELECT 
                e.fornecedor AS provider,
                COUNT(e.id) AS total_disparos,
                AVG(c.custo_por_disparo) AS custo_unitario,
                COUNT(e.id) * AVG(c.custo_por_disparo) AS total_gasto
            FROM $envios_table e
            LEFT JOIN $custos_table c ON e.fornecedor = c.provider AND c.ativo = 1
            WHERE $where_clause
            GROUP BY e.fornecedor
            ORDER BY total_gasto DESC
        ", ARRAY_A);

        // Total geral
        $total_disparos = $wpdb->get_var("
            SELECT COUNT(*) FROM $envios_table WHERE $where_clause
        ");

        $total_gasto = 0;
        foreach ($gastos_providers as $item) {
            $total_gasto += floatval($item['total_gasto'] ?? 0);
        }

        // Orçamentos e gastos por base
        $orcamentos = $wpdb->get_results("
            SELECT nome_base, orcamento_total FROM $orcamentos_table
        ", ARRAY_A);

        $gastos_bases = [];
        $total_orcamento = 0;

        // Busca gastos por base através das carteiras vinculadas
        foreach ($orcamentos as $orcamento) {
            $nome_base = $orcamento['nome_base'];
            $orcamento_valor = floatval($orcamento['orcamento_total']);
            $total_orcamento += $orcamento_valor;

            // Busca carteiras vinculadas à base
            $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';
            $carteiras_bases_table = $wpdb->prefix . 'pc_carteiras_bases_v2';

            // Busca id_carteira das carteiras vinculadas
            $carteiras = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id_carteira 
                 FROM $carteiras_table c
                 INNER JOIN $carteiras_bases_table cb ON c.id = cb.carteira_id
                 WHERE cb.nome_base = %s AND c.ativo = 1",
                $nome_base
            ), ARRAY_A);

            $gasto_base = 0;

            if (!empty($carteiras)) {
                // Calcula gasto baseado nos envios com id_carteira correspondente
                $id_carteiras = array_column($carteiras, 'id_carteira');
                $placeholders = implode(',', array_fill(0, count($id_carteiras), '%s'));

                // Busca envios com id_carteira correspondente e calcula custo
                $query = "SELECT 
                    e.fornecedor, 
                    COUNT(e.id) as total_disparos,
                    AVG(c.custo_por_disparo) as custo_unitario
                FROM $envios_table e
                INNER JOIN $custos_table c ON e.fornecedor = c.provider AND c.ativo = 1
                WHERE e.id_carteira IN ($placeholders) AND ($where_clause)
                GROUP BY e.fornecedor";

                $prepared_query = $wpdb->prepare($query, ...$id_carteiras);
                $envios_base = $wpdb->get_results($prepared_query, ARRAY_A);

                foreach ($envios_base as $envio) {
                    $gasto_base += floatval($envio['total_disparos']) * floatval($envio['custo_unitario']);
                }
            }

            $gastos_bases[] = [
                'nome_base' => $nome_base,
                'orcamento' => $orcamento_valor,
                'gasto' => $gasto_base,
                'saldo' => $orcamento_valor - $gasto_base
            ];
        }

        wp_send_json_success([
            'total_orcamento' => $total_orcamento,
            'total_gasto' => $total_gasto,
            'saldo_disponivel' => $total_orcamento - $total_gasto,
            'total_disparos' => intval($total_disparos),
            'gastos_providers' => $gastos_providers,
            'gastos_bases' => $gastos_bases
        ]);
    }



    public function handle_import_blocklist_csv()
    {
        check_ajax_referer('pc_security_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Erro no upload do arquivo']);
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');

        if ($handle === false) {
            wp_send_json_error(['message' => 'Não foi possível ler o arquivo']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pc_blocklist';
        $user_id = get_current_user_id();

        $imported = 0;
        $errors = 0;
        $duplicates = 0;
        $row_count = 0;

        // Detectar delimitador
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';

        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            $row_count++;

            // Pula cabeçalho se parecer ser um
            if ($row_count === 1) {
                // Verificação simples: se o primeiro campo não for nuemrico e contiver texto comum de header
                if (
                    !is_numeric(str_replace(['-', ' ', '(', ')'], '', $data[0])) &&
                    (stripos($data[0], 'tel') !== false || stripos($data[0], 'cpf') !== false || stripos($data[0], 'nome') !== false)
                ) {
                    continue;
                }
            }

            // Lógica para detectar colunas (similar ao Campaign Manager mas simplificado)
            // Prioridade: Telefone na col 0, CPF na col 1 (ou vice-versa se detectado)

            $telefone = '';
            $cpf = '';
            $motivo = 'Importação CSV';

            // Tenta identificar por padrão
            foreach ($data as $cell) {
                $clean = preg_replace('/[^0-9]/', '', $cell);

                // CPF (11 dígitos) - prioriza se ainda não encontrou
                if (empty($cpf) && strlen($clean) === 11) {
                    // Validação básica de CPF repetido (ex: 111.111.111-11)
                    if (!preg_match('/(\d)\1{10}/', $clean)) {
                        $cpf = $clean;
                        continue;
                    }
                }

                // Telefone (10 ou 11 dígitos, começa com range móvel ou fixo)
                if (empty($telefone) && (strlen($clean) === 10 || strlen($clean) === 11)) {
                    $telefone = $clean;
                    continue;
                }
            }

            // Se encontrou dados, tenta inserir
            if (!empty($telefone)) {
                $inserted = $this->insert_blocklist_item('telefone', $telefone, $motivo, $user_id);
                if ($inserted === true)
                    $imported++;
                elseif ($inserted === 'duplicate')
                    $duplicates++;
                else
                    $errors++;
            }

            if (!empty($cpf)) {
                $inserted = $this->insert_blocklist_item('cpf', $cpf, $motivo, $user_id);
                if ($inserted === true)
                    $imported++;
                elseif ($inserted === 'duplicate')
                    $duplicates++;
                else
                    $errors++;
            }
        }

        fclose($handle);

        wp_send_json_success([
            'message' => "Importação concluída. Importados: $imported. Duplicados: $duplicates. Erros: $errors.",
            'stats' => [
                'imported' => $imported,
                'duplicates' => $duplicates,
                'errors' => $errors
            ]
        ]);
    }

    private function insert_blocklist_item($type, $value, $reason, $user_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pc_blocklist';

        // Verifica duplicidade
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE tipo = %s AND valor = %s",
            $type,
            $value
        ));

        if ($exists) {
            return 'duplicate';
        }

        $result = $wpdb->insert(
            $table,
            [
                'tipo' => $type,
                'valor' => $value,
                'motivo' => $reason,
                'criado_por' => $user_id,
                'criado_em' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    // ========== HANDLERS PARA CARTEIRAS ==========

    public function handle_create_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $nome = sanitize_text_field($_POST['nome'] ?? '');
        $id_carteira = sanitize_text_field($_POST['id_carteira'] ?? '');
        $id_ruler = sanitize_text_field($_POST['id_ruler'] ?? '');
        $descricao = sanitize_textarea_field($_POST['descricao'] ?? '');

        if (empty($nome) || empty($id_carteira)) {
            wp_send_json_error('Nome e ID da carteira são obrigatórios');
        }

        $table = $wpdb->prefix . 'pc_carteiras_v2';

        // Verifica se ID já existe combinado com o id_ruler (apenas entre carteiras ativas)
        // Se id_ruler for vazio, deve ser único para id_ruler vazio.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id_carteira = %s AND id_ruler = %s AND ativo = 1",
            $id_carteira,
            $id_ruler
        ));

        if ($exists) {
            wp_send_json_error('ID da carteira com este ID Ruler já existe');
        }

        $result = $wpdb->insert(
            $table,
            [
                'nome' => $nome,
                'id_carteira' => $id_carteira,
                'id_ruler' => $id_ruler,
                'descricao' => $descricao
            ],
            ['%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            $error_message = 'Erro ao criar carteira';
            if ($wpdb->last_error) {
                $error_message .= ': ' . $wpdb->last_error;
            }
            error_log('Erro ao criar carteira: ' . $wpdb->last_error);
            wp_send_json_error($error_message);
        }

        wp_send_json_success([
            'message' => 'Carteira criada com sucesso',
            'id' => $wpdb->insert_id
        ]);
    }

    public function handle_get_carteiras()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table = $wpdb->prefix . 'pc_carteiras_v2';
        $carteiras = $wpdb->get_results(
            "SELECT * FROM $table WHERE ativo = 1 ORDER BY nome",
            ARRAY_A
        );

        wp_send_json_success($carteiras ?: []);
    }

    public function handle_get_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table = $wpdb->prefix . 'pc_carteiras_v2';
        $carteira = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$carteira) {
            wp_send_json_error('Carteira não encontrada');
        }

        wp_send_json_success($carteira);
    }

    public function handle_update_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $nome = sanitize_text_field($_POST['nome'] ?? '');
        $id_carteira = sanitize_text_field($_POST['id_carteira'] ?? '');
        $id_ruler = sanitize_text_field($_POST['id_ruler'] ?? '');
        $descricao = sanitize_textarea_field($_POST['descricao'] ?? '');

        if (!$id || empty($nome) || empty($id_carteira)) {
            wp_send_json_error('Dados inválidos');
        }

        $table = $wpdb->prefix . 'pc_carteiras_v2';

        // Verifica se outro registro ativo já usa essa exata combinação de id_carteira e id_ruler
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id_carteira = %s AND id_ruler = %s AND id != %d AND ativo = 1",
            $id_carteira,
            $id_ruler,
            $id
        ));

        if ($exists) {
            wp_send_json_error('ID da carteira com este ID Ruler já está em uso por outra carteira ativa');
        }

        $result = $wpdb->update(
            $table,
            [
                'nome' => $nome,
                'id_carteira' => $id_carteira,
                'id_ruler' => $id_ruler,
                'descricao' => $descricao
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao atualizar carteira');
        }

        wp_send_json_success('Carteira atualizada com sucesso');
    }

    public function handle_delete_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $table_vinculos = $wpdb->prefix . 'pc_carteiras_bases_v2';

        // Remove vínculos
        $wpdb->delete($table_vinculos, ['carteira_id' => $id], ['%d']);

        // Desativa carteira
        $result = $wpdb->update(
            $table_carteiras,
            ['ativo' => 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao excluir carteira');
        }

        wp_send_json_success('Carteira excluída com sucesso');
    }

    // ========== NOVO: LÓGICA ULTRA-SIMPLES PARA VÍNCULOS ==========

    public function handle_vincular_base_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $carteira_id = intval($_POST['carteira_id'] ?? 0);

        // Tenta pegar como array nativo primeiro (bases[0], bases[1], etc)
        $bases = [];
        if (isset($_POST['bases']) && is_array($_POST['bases'])) {
            $bases = $_POST['bases'];
            error_log('✅ [NOVO Vincular] Bases recebidas como array PHP nativo: ' . print_r($bases, true));
        } else {
            // Se não for array, tenta como string JSON
            $bases_raw = $_POST['bases'] ?? '';
            error_log('🟢 [NOVO Vincular] Carteira: ' . $carteira_id);
            error_log('🟢 [NOVO Vincular] $_POST completo: ' . print_r($_POST, true));
            error_log('🟢 [NOVO Vincular] Bases raw: ' . $bases_raw);
            error_log('🟢 [NOVO Vincular] Tipo: ' . gettype($bases_raw));

            if (!$carteira_id) {
                wp_send_json_error('ID da carteira inválido');
                return;
            }

            // Se não for array, tenta decodificar JSON
            if (is_string($bases_raw) && !empty($bases_raw)) {
                $bases_raw_trimmed = trim($bases_raw);
                error_log('🟢 [NOVO Vincular] Tentando decodificar JSON: ' . $bases_raw_trimmed);

                $decoded = json_decode($bases_raw_trimmed, true);
                $json_error = json_last_error();

                if ($json_error === JSON_ERROR_NONE && is_array($decoded)) {
                    $bases = $decoded;
                    error_log('✅ [NOVO Vincular] JSON decodificado com sucesso! Count: ' . count($bases));
                } else {
                    error_log('🔴 [NOVO Vincular] Erro ao decodificar JSON. Error code: ' . $json_error);
                    error_log('🔴 [NOVO Vincular] Error message: ' . json_last_error_msg());
                    error_log('🔴 [NOVO Vincular] String recebida: ' . $bases_raw_trimmed);
                    wp_send_json_error('Erro ao processar bases: ' . json_last_error_msg());
                    return;
                }
            } else {
                error_log('🔴 [NOVO Vincular] Bases raw é vazio ou tipo inválido. Tipo: ' . gettype($bases_raw));
                wp_send_json_error('Nenhuma base recebida');
                return;
            }
        }

        error_log('🟢 [NOVO Vincular] Bases antes de limpar: ' . print_r($bases, true));
        error_log('🟢 [NOVO Vincular] Count de bases antes de limpar: ' . count($bases));

        // Limpa array: remove vazios e normaliza strings
        $bases_limpas = [];
        foreach ($bases as $base) {
            if (is_string($base)) {
                $base_trimmed = trim($base);
                if (!empty($base_trimmed)) {
                    $bases_limpas[] = $base_trimmed;
                }
            } elseif (!empty($base)) {
                $bases_limpas[] = trim((string) $base);
            }
        }
        $bases = $bases_limpas;

        error_log('🟢 [NOVO Vincular] Bases processadas (após limpar): ' . implode(', ', $bases));
        error_log('🟢 [NOVO Vincular] Total de bases após processar: ' . count($bases));

        if (empty($bases)) {
            error_log('🔴 [NOVO Vincular] CRÍTICO: Array de bases está VAZIO após processamento!');
            error_log('🔴 [NOVO Vincular] Bases antes de limpar eram: ' . print_r($bases_limpas, true));
            wp_send_json_error('Nenhuma base válida para vincular após processamento');
            return;
        }

        $table = $wpdb->prefix . 'pc_carteiras_bases_v2';

        // PASSO 1: Remove TODOS os vínculos antigos desta carteira
        $wpdb->delete($table, ['carteira_id' => $carteira_id], ['%d']);

        // PASSO 2: Insere os novos vínculos
        $inserted = 0;
        $errors = [];

        if (empty($bases)) {
            error_log('🔴 [NOVO Vincular] Array de bases está VAZIO após processamento!');
            wp_send_json_error('Nenhuma base válida para vincular');
            return;
        }

        foreach ($bases as $base_nome) {
            // Não usa sanitize_text_field pois pode alterar o nome da base
            // Apenas remove espaços e valida
            $base_nome_clean = trim($base_nome);

            if (empty($base_nome_clean)) {
                error_log('⚠️ [NOVO Vincular] Base vazia ignorada');
                continue;
            }

            error_log('🟢 [NOVO Vincular] Tentando inserir: carteira_id=' . $carteira_id . ', nome_base=' . $base_nome_clean);

            // Verifica se já existe (pode ter sido inserido antes do delete)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE carteira_id = %d AND nome_base = %s",
                $carteira_id,
                $base_nome_clean
            ));

            if ($exists) {
                error_log('⚠️ [NOVO Vincular] Base já existe, pulando: ' . $base_nome_clean);
                $inserted++; // Conta como inserida
                continue;
            }

            $result = $wpdb->insert(
                $table,
                [
                    'carteira_id' => $carteira_id,
                    'nome_base' => $base_nome_clean
                ],
                ['%d', '%s']
            );

            if ($result !== false) {
                $inserted++;
                error_log('✅ [NOVO Vincular] Inserido com sucesso: ' . $base_nome_clean);
            } else {
                $error_msg = $wpdb->last_error ?: 'Erro desconhecido';
                $errors[] = $base_nome_clean . ': ' . $error_msg;
                error_log('🔴 [NOVO Vincular] ERRO ao inserir: ' . $base_nome_clean);
                error_log('🔴 [NOVO Vincular] Erro do WordPress: ' . $error_msg);
                error_log('🔴 [NOVO Vincular] Query: ' . $wpdb->last_query);
            }
        }

        if (!empty($errors)) {
            error_log('🔴 [NOVO Vincular] Erros encontrados: ' . implode('; ', $errors));
        }

        error_log('🟢 [NOVO Vincular] Total inserido: ' . $inserted);

        wp_send_json_success([
            'message' => 'Bases vinculadas com sucesso',
            'count' => $inserted
        ]);
    }

    public function handle_get_bases_carteira()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $carteira_id = intval($_POST['carteira_id'] ?? 0);

        error_log('🟢 [NOVO Get Bases] Iniciando busca para carteira_id: ' . $carteira_id);

        if (!$carteira_id) {
            error_log('🔴 [NOVO Get Bases] ID da carteira inválido');
            wp_send_json_error('ID da carteira inválido');
            return;
        }

        $table = $wpdb->prefix . 'pc_carteiras_bases_v2';

        // Verifica se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            error_log('🔴 [NOVO Get Bases] Tabela não existe: ' . $table);
            wp_send_json_success([]);
            return;
        }

        // Verifica quantos registros existem para esta carteira (debug)
        $total_registros = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE carteira_id = %d",
            $carteira_id
        ));
        error_log('🟢 [NOVO Get Bases] Total de registros na tabela para carteira ' . $carteira_id . ': ' . $total_registros);

        // Busca APENAS os nomes das bases (array simples de strings)
        $bases = $wpdb->get_col($wpdb->prepare(
            "SELECT nome_base FROM $table WHERE carteira_id = %d ORDER BY nome_base",
            $carteira_id
        ));

        $result = is_array($bases) ? $bases : [];

        error_log('🟢 [NOVO Get Bases] Carteira: ' . $carteira_id);
        error_log('🟢 [NOVO Get Bases] Total encontrado: ' . count($result));
        error_log('🟢 [NOVO Get Bases] Bases: ' . implode(', ', $result));

        // Debug: verifica se há erro na query
        if ($wpdb->last_error) {
            error_log('🔴 [NOVO Get Bases] Erro na query: ' . $wpdb->last_error);
            error_log('🔴 [NOVO Get Bases] Query: ' . $wpdb->last_query);
        }

        // Retorna array simples de strings
        wp_send_json_success($result);
    }

    // Handler para limpar dados ruins (usar via console se necessário)
    public function handle_limpar_vinculos_ruins()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $table = $wpdb->prefix . 'pc_carteiras_bases_v2';

        // Remove todos os vínculos com nomes que parecem JSON
        $deleted = $wpdb->query(
            "DELETE FROM $table WHERE nome_base LIKE '[%' OR nome_base LIKE '\"%'"
        );

        error_log('🧹 [Limpar Vínculos] Removidos: ' . $deleted);

        wp_send_json_success([
            'message' => 'Vínculos ruins removidos',
            'count' => $deleted
        ]);
    }

    // ========== HANDLERS PARA ISCAS ==========

    public function handle_create_isca()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $nome = sanitize_text_field($_POST['nome'] ?? '');
        $telefone = sanitize_text_field($_POST['telefone'] ?? '');
        $id_carteira = intval($_POST['id_carteira'] ?? 0);
        $cpf = sanitize_text_field($_POST['cpf'] ?? '');

        if (empty($nome) || empty($telefone)) {
            wp_send_json_error('Nome e telefone são obrigatórios');
        }

        // Validar formato do telefone (deve ter 13 dígitos: 55 + DDD + número)
        $telefone_limpo = preg_replace('/[^0-9]/', '', $telefone);
        if (strlen($telefone_limpo) < 12 || strlen($telefone_limpo) > 13) {
            wp_send_json_error('Telefone deve estar no formato correto (55 + DDD + número)');
        }

        $table = $wpdb->prefix . 'cm_baits';

        // Verifica se telefone já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE telefone = %s AND ativo = 1",
            $telefone_limpo
        ));

        if ($exists) {
            wp_send_json_error('Este telefone já está cadastrado como isca');
        }

        // Dados para inserir
        $data = [
            'nome' => $nome,
            'telefone' => $telefone_limpo,
            'ativo' => 1
        ];
        $formats = ['%s', '%s', '%d'];

        if ($id_carteira > 0) {
            $data['id_carteira'] = $id_carteira;
            $formats[] = '%d';
        }

        if (!empty($cpf)) {
            $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
            $data['cpf'] = $cpf_limpo;
            $formats[] = '%s';
        }

        $result = $wpdb->insert($table, $data, $formats);

        if ($result === false) {
            $error_message = 'Erro ao criar isca';
            if ($wpdb->last_error) {
                $error_message .= ': ' . $wpdb->last_error;
            }
            error_log('Erro ao criar isca: ' . $wpdb->last_error);
            wp_send_json_error($error_message);
        }

        wp_send_json_success([
            'message' => 'Isca criada com sucesso',
            'id' => $wpdb->insert_id
        ]);
    }

    public function handle_get_iscas()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table_iscas = $wpdb->prefix . 'cm_baits';
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';

        $iscas = $wpdb->get_results(
            "SELECT i.*, c.nome AS nome_carteira
             FROM $table_iscas i
             LEFT JOIN $table_carteiras c ON c.id = i.id_carteira AND c.ativo = 1
             WHERE i.ativo = 1
             ORDER BY i.criado_em DESC",
            ARRAY_A
        );

        wp_send_json_success($iscas ?: []);
    }

    public function handle_get_isca()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table = $wpdb->prefix . 'cm_baits';
        $isca = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$isca) {
            wp_send_json_error('Isca não encontrada');
        }

        wp_send_json_success($isca);
    }

    public function handle_update_isca()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $nome = sanitize_text_field($_POST['nome'] ?? '');
        $telefone = sanitize_text_field($_POST['telefone'] ?? '');
        $id_carteira = intval($_POST['id_carteira'] ?? 0);
        $cpf = sanitize_text_field($_POST['cpf'] ?? '');

        if (!$id || empty($nome) || empty($telefone)) {
            wp_send_json_error('Dados inválidos');
        }

        // Validar formato do telefone
        $telefone_limpo = preg_replace('/[^0-9]/', '', $telefone);
        if (strlen($telefone_limpo) < 12 || strlen($telefone_limpo) > 13) {
            wp_send_json_error('Telefone deve estar no formato correto (55 + DDD + número)');
        }

        $table = $wpdb->prefix . 'cm_baits';

        // Verifica se outro registro já usa esse telefone
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE telefone = %s AND id != %d AND ativo = 1",
            $telefone_limpo,
            $id
        ));

        if ($exists) {
            wp_send_json_error('Este telefone já está cadastrado em outra isca');
        }

        // Dados para atualizar
        $data = [
            'nome' => $nome,
            'telefone' => $telefone_limpo
        ];
        $formats = ['%s', '%s'];

        if ($id_carteira > 0) {
            $data['id_carteira'] = $id_carteira;
            $formats[] = '%d';
        } else {
            $data['id_carteira'] = null;
            $formats[] = '%d';
        }

        if (!empty($cpf)) {
            $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
            $data['cpf'] = $cpf_limpo;
            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $id],
            $formats,
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao atualizar isca');
        }

        wp_send_json_success('Isca atualizada com sucesso');
    }

    public function handle_delete_isca()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID inválido');
        }

        $table = $wpdb->prefix . 'cm_baits';

        // Desativa isca (soft delete)
        $result = $wpdb->update(
            $table,
            ['ativo' => 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Erro ao excluir isca');
        }

        wp_send_json_success('Isca excluída com sucesso');
    }

    // ========== HANDLERS PARA RANKING ==========

    public function handle_get_ranking()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $table = $wpdb->prefix . 'envios_pendentes';

        // Busca ranking de envios por usuário
        $ranking_usuarios = $wpdb->get_results(
            "SELECT
                u.ID as user_id,
                u.display_name as user_name,
                u.user_email as user_email,
                COUNT(e.id) as total_envios,
                SUM(CASE WHEN e.status = 'enviado' THEN 1 ELSE 0 END) as enviados,
                SUM(CASE WHEN e.status = 'erro' THEN 1 ELSE 0 END) as erros,
                SUM(CASE WHEN e.status = 'pendente_aprovacao' THEN 1 ELSE 0 END) as pendentes
            FROM {$wpdb->users} u
            LEFT JOIN $table e ON u.ID = e.current_user_id
            WHERE e.id IS NOT NULL
            GROUP BY u.ID
            ORDER BY total_envios DESC
            LIMIT 50",
            ARRAY_A
        );

        // Busca ranking por plataforma para cada usuário
        $ranking_por_plataforma = $wpdb->get_results(
            "SELECT
                e.current_user_id as user_id,
                e.fornecedor as plataforma,
                COUNT(e.id) as total,
                SUM(CASE WHEN e.status = 'enviado' THEN 1 ELSE 0 END) as enviados
            FROM $table e
            WHERE e.current_user_id IS NOT NULL
            GROUP BY e.current_user_id, e.fornecedor
            ORDER BY e.current_user_id, total DESC",
            ARRAY_A
        );

        // Organiza dados por plataforma para cada usuário
        $plataformas_por_usuario = [];
        foreach ($ranking_por_plataforma as $item) {
            $user_id = $item['user_id'];
            if (!isset($plataformas_por_usuario[$user_id])) {
                $plataformas_por_usuario[$user_id] = [];
            }
            $plataformas_por_usuario[$user_id][] = [
                'plataforma' => $item['plataforma'],
                'total' => intval($item['total']),
                'enviados' => intval($item['enviados']),
            ];
        }

        // Adiciona plataformas ao ranking de usuários
        foreach ($ranking_usuarios as &$usuario) {
            $user_id = $usuario['user_id'];
            $usuario['plataformas'] = $plataformas_por_usuario[$user_id] ?? [];
        }

        wp_send_json_success([
            'ranking' => $ranking_usuarios,
            'total_usuarios' => count($ranking_usuarios),
        ]);
    }

    // ========== HANDLERS PARA TRACKING SALESFORCE ==========

    public function handle_get_salesforce_tracking()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada.');
            return;
        }

        try {
        global $wpdb;
        $table = 'salesforce_returns';

        $table_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if (!$table_check) {
            wp_send_json_success([
                'records' => [], 'total_count' => 0, 'page' => 1,
                'per_page' => 50, 'total_pages' => 0, 'statuses' => [],
            ]);
            return;
        }

        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(10, intval($_POST['per_page'] ?? 50)));
        $offset = ($page - 1) * $per_page;

        $search = sanitize_text_field($_POST['search'] ?? '');
        $status_filter = sanitize_text_field($_POST['status_filter'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');

        $where_clauses = ['1=1'];
        $where_values = [];

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(mobilenumber LIKE %s OR uniqueid LIKE %s OR contactkey LIKE %s OR name LIKE %s OR cpf_cnpj__c LIKE %s OR TemplateName LIKE %s)';
            $where_values = array_merge($where_values, [$like, $like, $like, $like, $like, $like]);
        }

        if (!empty($status_filter)) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $status_filter;
        }

        if (!empty($date_from)) {
            $where_clauses[] = 'eventdateutc >= %s';
            $where_values[] = $date_from . ' 00:00:00';
        }

        if (!empty($date_to)) {
            $where_clauses[] = 'eventdateutc <= %s';
            $where_values[] = $date_to . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Total count
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, ...$where_values);
        }
        $total_count = intval($wpdb->get_var($count_query));

        // Paginated data
        $data_query = "SELECT id, mobilenumber, name, cpf_cnpj__c, status, trackingtype, 
                              sendtype, channeltype, activityname, channelname, reason,
                              eventdateutc, criado_em, contactkey, operacao__c, TemplateName
                       FROM {$table} WHERE {$where_sql}
                       ORDER BY eventdateutc DESC
                       LIMIT %d OFFSET %d";

        $query_values = array_merge($where_values, [$per_page, $offset]);
        $data_query = $wpdb->prepare($data_query, ...$query_values);

        $results = $wpdb->get_results($data_query, ARRAY_A);

        // Distinct statuses for filter dropdown
        $statuses = $wpdb->get_col("SELECT DISTINCT status FROM {$table} WHERE status IS NOT NULL AND status != '' ORDER BY status ASC");

        wp_send_json_success([
            'records' => $results ?: [],
            'total_count' => $total_count,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_count / $per_page),
            'statuses' => $statuses ?: [],
        ]);

        } catch (\Throwable $e) {
            error_log('[Tracking SF] Fatal: ' . $e->getMessage());
            wp_send_json_error('Erro interno: ' . $e->getMessage());
        }
    }

    public function handle_download_salesforce_csv()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada.');
            return;
        }

        try {
        global $wpdb;
        $table = 'salesforce_returns';

        $table_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if (!$table_check) {
            wp_send_json_error('Tabela salesforce_returns não encontrada. Execute a importação Salesforce primeiro.');
            return;
        }

        $search = sanitize_text_field($_POST['search'] ?? '');
        $status_filter = sanitize_text_field($_POST['status_filter'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $max_rows = min(50000, max(1, intval($_POST['max_rows'] ?? 10000)));

        $where_clauses = ['1=1'];
        $where_values = [];

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(mobilenumber LIKE %s OR uniqueid LIKE %s OR contactkey LIKE %s OR name LIKE %s OR cpf_cnpj__c LIKE %s OR TemplateName LIKE %s)';
            $where_values = array_merge($where_values, [$like, $like, $like, $like, $like, $like]);
        }

        if (!empty($status_filter)) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $status_filter;
        }

        if (!empty($date_from)) {
            $where_clauses[] = 'eventdateutc >= %s';
            $where_values[] = $date_from . ' 00:00:00';
        }

        if (!empty($date_to)) {
            $where_clauses[] = 'eventdateutc <= %s';
            $where_values[] = $date_to . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where_clauses);

        $data_query = "SELECT id, mobilenumber, name, cpf_cnpj__c, status, trackingtype,
                              sendtype, channeltype, activityname, channelname, reason,
                              eventdateutc, criado_em, contactkey, operacao__c, TemplateName
                       FROM {$table} WHERE {$where_sql}
                       ORDER BY eventdateutc DESC LIMIT %d";

        $query_values = array_merge($where_values, [$max_rows]);
        $data_query = $wpdb->prepare($data_query, ...$query_values);

        $results = $wpdb->get_results($data_query, ARRAY_A);

        if (empty($results)) {
            wp_send_json_error('Nenhum registro encontrado para exportar.');
            return;
        }

        $csv_lines = [];
        $csv_lines[] = implode(';', array_keys($results[0]));
        foreach ($results as $row) {
            $csv_lines[] = implode(';', array_map(function ($v) {
                $v = str_replace(['"', ';', "\n", "\r"], ['""', ',', ' ', ' '], $v ?? '');
                return '"' . $v . '"';
            }, array_values($row)));
        }

        wp_send_json_success([
            'csv_content' => implode("\n", $csv_lines),
            'filename' => 'salesforce_tracking_' . date('Y-m-d_His') . '.csv',
            'total_rows' => count($results),
        ]);

        } catch (\Throwable $e) {
            error_log('[CSV Salesforce] Fatal: ' . $e->getMessage());
            wp_send_json_error('Erro interno ao gerar CSV: ' . $e->getMessage());
        }
    }

    /**
     * Direct file download for Salesforce CSV (admin_post, streams to php://output).
     */
    public function handle_download_salesforce_csv_file()
    {
        if (!is_user_logged_in()) {
            wp_die('Acesso negado.');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Permissão insuficiente.');
        }
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'pc_csv_download')) {
            wp_die('Requisição inválida.');
        }

        try {
            global $wpdb;
            $table = 'salesforce_returns';

            $table_check = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME, $table
            ));
            if (!$table_check) {
                wp_die('Tabela salesforce_returns não encontrada.');
            }

            $search = sanitize_text_field($_REQUEST['search'] ?? '');
            $status_filter = sanitize_text_field($_REQUEST['status_filter'] ?? '');
            $date_from = sanitize_text_field($_REQUEST['date_from'] ?? '');
            $date_to = sanitize_text_field($_REQUEST['date_to'] ?? '');
            $max_rows = min(50000, max(1, intval($_REQUEST['max_rows'] ?? 50000)));

            $where_clauses = ['1=1'];
            $where_values = [];

            if (!empty($search)) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where_clauses[] = '(mobilenumber LIKE %s OR uniqueid LIKE %s OR contactkey LIKE %s OR name LIKE %s OR cpf_cnpj__c LIKE %s OR TemplateName LIKE %s)';
                $where_values = array_merge($where_values, [$like, $like, $like, $like, $like, $like]);
            }
            if (!empty($status_filter)) {
                $where_clauses[] = 'status = %s';
                $where_values[] = $status_filter;
            }
            if (!empty($date_from)) {
                $where_clauses[] = 'eventdateutc >= %s';
                $where_values[] = $date_from . ' 00:00:00';
            }
            if (!empty($date_to)) {
                $where_clauses[] = 'eventdateutc <= %s';
                $where_values[] = $date_to . ' 23:59:59';
            }

            $where_sql = implode(' AND ', $where_clauses);

            $data_query = "SELECT id, mobilenumber, name, cpf_cnpj__c, status, trackingtype,
                                  sendtype, channeltype, activityname, channelname, reason,
                                  eventdateutc, criado_em, contactkey, operacao__c, TemplateName
                           FROM {$table} WHERE {$where_sql}
                           ORDER BY eventdateutc DESC LIMIT %d";

            $query_values = array_merge($where_values, [$max_rows]);
            $data_query = $wpdb->prepare($data_query, ...$query_values);

            $results = $wpdb->get_results($data_query, ARRAY_A);

            if (empty($results)) {
                wp_die('Nenhum registro encontrado com os filtros aplicados.');
            }

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="salesforce_tracking_' . date('Y-m-d_His') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_keys($results[0]), ';');

            foreach ($results as $row) {
                fputcsv($output, $row, ';');
            }

            fclose($output);
            exit;
        } catch (\Throwable $e) {
            error_log('[CSV SF File] Fatal: ' . $e->getMessage());
            if (!headers_sent()) {
                wp_die('Erro ao gerar CSV: ' . esc_html($e->getMessage()));
            }
            exit;
        }
    }

    // ========== HANDLERS PARA RELATÓRIOS MULTI-TABELA ==========

    public function handle_get_envios_pendentes()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'envios_pendentes';

            $page = max(1, intval($_POST['page'] ?? 1));
            $per_page = min(100, max(10, intval($_POST['per_page'] ?? 50)));
            $offset = ($page - 1) * $per_page;

            $search = sanitize_text_field($_POST['search'] ?? '');
            $status_filter = sanitize_text_field($_POST['status_filter'] ?? '');
            $fornecedor_filter = sanitize_text_field($_POST['fornecedor_filter'] ?? '');
            $date_from = sanitize_text_field($_POST['date_from'] ?? '');
            $date_to = sanitize_text_field($_POST['date_to'] ?? '');
            $agendamento_filter = sanitize_text_field($_POST['agendamento_filter'] ?? '');

            $where = ['1=1'];
            $vals = [];

            if ($search) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where[] = '(telefone LIKE %s OR nome LIKE %s OR cpf_cnpj LIKE %s OR agendamento_id LIKE %s)';
                array_push($vals, $like, $like, $like, $like);
            }
            if ($status_filter) { $where[] = 'status = %s'; $vals[] = $status_filter; }
            if ($fornecedor_filter) { $where[] = 'fornecedor = %s'; $vals[] = $fornecedor_filter; }
            if ($agendamento_filter) { $where[] = 'agendamento_id = %s'; $vals[] = $agendamento_filter; }
            if ($date_from) { $where[] = 'data_cadastro >= %s'; $vals[] = $date_from . ' 00:00:00'; }
            if ($date_to) { $where[] = 'data_cadastro <= %s'; $vals[] = $date_to . ' 23:59:59'; }

            $where_sql = implode(' AND ', $where);

            $count_q = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
            $total = intval($wpdb->get_var(empty($vals) ? $count_q : $wpdb->prepare($count_q, ...$vals)));

            $data_q = "SELECT id, telefone, nome, cpf_cnpj, status, fornecedor, agendamento_id,
                               idgis_ambiente, data_cadastro, data_disparo, resposta_api
                        FROM {$table} WHERE {$where_sql}
                        ORDER BY data_cadastro DESC LIMIT %d OFFSET %d";
            $all_vals = array_merge($vals, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($data_q, ...$all_vals), ARRAY_A);

            $statuses = $wpdb->get_col("SELECT DISTINCT status FROM {$table} WHERE status IS NOT NULL AND status != '' ORDER BY status");
            $fornecedores = $wpdb->get_col("SELECT DISTINCT fornecedor FROM {$table} WHERE fornecedor IS NOT NULL AND fornecedor != '' ORDER BY fornecedor");

            wp_send_json_success([
                'records' => $rows ?: [],
                'total_count' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => max(1, ceil($total / $per_page)),
                'statuses' => $statuses ?: [],
                'fornecedores' => $fornecedores ?: [],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error('Erro: ' . $e->getMessage());
        }
    }

    public function handle_get_eventos_envios()
    {
        global $wpdb;
        $this->handle_generic_table_query($wpdb->prefix . 'eventos_envios');
    }

    /**
     * Generic handler for dynamic tables — auto-discovers columns from the real DB schema.
     */
    private function handle_generic_table_query(string $table_name)
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }

        try {
            global $wpdb;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME, $table_name
            ));
            if (!$exists) {
                wp_send_json_success([
                    'records' => [], 'total_count' => 0, 'page' => 1,
                    'per_page' => 50, 'total_pages' => 0, 'table_exists' => false,
                    'columns' => [],
                ]);
                return;
            }

            $col_rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", ARRAY_A);
            $all_columns = array_column($col_rows, 'Field');

            $text_cols = [];
            $date_cols = [];
            $order_col = $all_columns[0] ?? 'id';
            foreach ($col_rows as $c) {
                $type = strtolower($c['Type']);
                if (preg_match('/varchar|text|char/', $type)) {
                    $text_cols[] = $c['Field'];
                }
                if (preg_match('/date|time/', $type)) {
                    $date_cols[] = $c['Field'];
                    $order_col = $c['Field'];
                }
            }
            if (in_array('id', $all_columns)) {
                $order_col = 'id';
            }

            $page = max(1, intval($_POST['page'] ?? 1));
            $per_page = min(100, max(10, intval($_POST['per_page'] ?? 50)));
            $offset = ($page - 1) * $per_page;

            $search = sanitize_text_field($_POST['search'] ?? '');
            $col_filter = sanitize_text_field($_POST['col_filter'] ?? '');
            $col_filter_val = sanitize_text_field($_POST['col_filter_val'] ?? '');
            $date_from = sanitize_text_field($_POST['date_from'] ?? '');
            $date_to = sanitize_text_field($_POST['date_to'] ?? '');

            $where = ['1=1'];
            $vals = [];

            if ($search && !empty($text_cols)) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $like_parts = [];
                foreach ($text_cols as $tc) {
                    $like_parts[] = "`{$tc}` LIKE %s";
                    $vals[] = $like;
                }
                $where[] = '(' . implode(' OR ', $like_parts) . ')';
            }

            if ($col_filter && $col_filter_val && in_array($col_filter, $all_columns)) {
                $where[] = "`{$col_filter}` = %s";
                $vals[] = $col_filter_val;
            }

            $primary_date = $date_cols[0] ?? null;
            if ($date_from && $primary_date) {
                $where[] = "`{$primary_date}` >= %s";
                $vals[] = $date_from . ' 00:00:00';
            }
            if ($date_to && $primary_date) {
                $where[] = "`{$primary_date}` <= %s";
                $vals[] = $date_to . ' 23:59:59';
            }

            $where_sql = implode(' AND ', $where);

            $count_q = "SELECT COUNT(*) FROM `{$table_name}` WHERE {$where_sql}";
            $total = intval($wpdb->get_var(empty($vals) ? $count_q : $wpdb->prepare($count_q, ...$vals)));

            $data_q = "SELECT * FROM `{$table_name}` WHERE {$where_sql} ORDER BY `{$order_col}` DESC LIMIT %d OFFSET %d";
            $all_vals = array_merge($vals, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($data_q, ...$all_vals), ARRAY_A);

            $filter_options = [];
            foreach ($text_cols as $tc) {
                $distinct = $wpdb->get_col("SELECT DISTINCT `{$tc}` FROM `{$table_name}` WHERE `{$tc}` IS NOT NULL AND `{$tc}` != '' ORDER BY `{$tc}` ASC LIMIT 100");
                if (!empty($distinct)) {
                    $filter_options[$tc] = $distinct;
                }
            }

            wp_send_json_success([
                'records' => $rows ?: [],
                'total_count' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => max(1, ceil($total / $per_page)),
                'table_exists' => true,
                'columns' => $all_columns,
                'text_columns' => $text_cols,
                'date_columns' => $date_cols,
                'filter_options' => $filter_options,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error('Erro: ' . $e->getMessage());
        }
    }

    public function handle_get_eventos_indicadores()
    {
        global $wpdb;
        $this->handle_generic_table_query($wpdb->prefix . 'eventos_indicadores');
    }

    public function handle_get_eventos_tempos()
    {
        global $wpdb;
        $this->handle_generic_table_query($wpdb->prefix . 'eventos_tempos');
    }

    public function handle_get_report_summary()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }

        try {
            global $wpdb;
            $envios = $wpdb->prefix . 'envios_pendentes';

            $date_from = sanitize_text_field($_POST['date_from'] ?? '');
            $date_to = sanitize_text_field($_POST['date_to'] ?? '');

            $where = ['1=1'];
            $vals = [];
            if ($date_from) { $where[] = 'data_cadastro >= %s'; $vals[] = $date_from . ' 00:00:00'; }
            if ($date_to) { $where[] = 'data_cadastro <= %s'; $vals[] = $date_to . ' 23:59:59'; }
            $w = implode(' AND ', $where);

            $status_q = "SELECT status, COUNT(*) as total FROM {$envios} WHERE {$w} GROUP BY status ORDER BY total DESC";
            $by_status = $wpdb->get_results(empty($vals) ? $status_q : $wpdb->prepare($status_q, ...$vals), ARRAY_A);

            $provider_q = "SELECT fornecedor, COUNT(*) as total,
                           SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) as enviados,
                           SUM(CASE WHEN status IN ('erro','erro_envio','erro_credenciais') THEN 1 ELSE 0 END) as erros
                           FROM {$envios} WHERE {$w} GROUP BY fornecedor ORDER BY total DESC";
            $by_provider = $wpdb->get_results(empty($vals) ? $provider_q : $wpdb->prepare($provider_q, ...$vals), ARRAY_A);

            $daily_q = "SELECT DATE(data_cadastro) as dia, COUNT(*) as total,
                        SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) as enviados
                        FROM {$envios} WHERE {$w} GROUP BY DATE(data_cadastro) ORDER BY dia DESC LIMIT 30";
            $daily = $wpdb->get_results(empty($vals) ? $daily_q : $wpdb->prepare($daily_q, ...$vals), ARRAY_A);

            $total_q = "SELECT COUNT(*) FROM {$envios} WHERE {$w}";
            $total = intval($wpdb->get_var(empty($vals) ? $total_q : $wpdb->prepare($total_q, ...$vals)));

            wp_send_json_success([
                'total_records' => $total,
                'by_status' => $by_status ?: [],
                'by_provider' => $by_provider ?: [],
                'daily' => $daily ?: [],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error('Erro: ' . $e->getMessage());
        }
    }

    // ========== HANDLER UPLOAD MÍDIA CAMPANHA ==========

    public function handle_upload_campaign_media()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permissão negada.');
            return;
        }

        if (empty($_FILES['media_file'])) {
            wp_send_json_error('Nenhum arquivo enviado.');
            return;
        }

        $file = $_FILES['media_file'];
        $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];

        if (!in_array($file['type'], $allowed_types)) {
            wp_send_json_error('Tipo de arquivo não permitido. Aceitos: PNG, JPEG.');
            return;
        }

        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            wp_send_json_error('Arquivo muito grande. Máximo: 5MB.');
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $attachment_id = media_handle_upload('media_file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error('Erro no upload: ' . $attachment_id->get_error_message());
            return;
        }

        $url = wp_get_attachment_url($attachment_id);

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'url' => $url,
            'filename' => basename($url),
        ]);
    }

    // ========== HANDLERS PARA CAMPANHA VIA ARQUIVO ==========

    public function handle_upload_campaign_file()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Erro ao fazer upload do arquivo');
        }

        $file = $_FILES['file'];
        $file_type = wp_check_filetype($file['name']);

        if ($file_type['ext'] !== 'csv') {
            wp_send_json_error('Apenas arquivos CSV são permitidos');
        }

        // Lê o arquivo
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error('Erro ao ler arquivo');
        }

        // Lê o cabeçalho
        $header = fgetcsv($handle, 1000, ',');
        if (!$header) {
            fclose($handle);
            wp_send_json_error('Arquivo vazio ou inválido');
        }

        // Normaliza cabeçalhos (minúsculas, sem espaços)
        $header = array_map(function ($h) {
            return strtolower(trim($h));
        }, $header);

        // Valida colunas obrigatórias
        $required = ['nome', 'telefone', 'cpf'];
        $missing = array_diff($required, $header);
        if (!empty($missing)) {
            fclose($handle);
            wp_send_json_error('Colunas obrigatórias não encontradas: ' . implode(', ', $missing));
        }

        // Lê os dados
        $records = [];
        $valid_records = 0;
        $invalid_records = [];
        $line = 1;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $line++;
            if (count($row) !== count($header)) {
                $invalid_records[] = "Linha $line: Número de colunas não corresponde ao cabeçalho";
                continue; // Linha inválida
            }

            $record = array_combine($header, $row);

            // Valida CPF (obrigatório)
            $cpf = preg_replace('/[^0-9]/', '', $record['cpf'] ?? '');
            if (empty($cpf) || strlen($cpf) < 11) {
                $invalid_records[] = "Linha $line: CPF inválido ou vazio";
                continue; // CPF inválido
            }

            // Valida telefone (aceita com ou sem código do país 55)
            $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
            if (empty($telefone)) {
                $invalid_records[] = "Linha $line: Telefone vazio";
                continue;
            }

            // Remove código do país 55 se presente
            if (strlen($telefone) >= 12 && substr($telefone, 0, 2) === '55') {
                $telefone = substr($telefone, 2);
            }

            // Telefone deve ter DDD (2 dígitos) + número (8 ou 9 dígitos) = 10 ou 11 dígitos
            if (strlen($telefone) < 10 || strlen($telefone) > 11) {
                $invalid_records[] = "Linha $line: Telefone inválido (deve ter DDD + número: 11999999999 ou 1199999999)";
                continue;
            }

            // Valida DDD (primeiro dígito deve ser 1-9)
            if ($telefone[0] < '1' || $telefone[0] > '9') {
                $invalid_records[] = "Linha $line: DDD inválido (deve começar com dígito 1-9)";
                continue;
            }

            // Busca id_carteira se não informado
            $id_carteira = $record['id_carteira'] ?? '';
            if (empty($id_carteira) && !empty($record['carteira'])) {
                global $wpdb;
                $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';
                $carteira_bases_table = $wpdb->prefix . 'pc_carteiras_bases_v2';

                // Busca carteira pelo nome
                $carteira = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.id_carteira 
                     FROM $carteiras_table c
                     WHERE c.nome = %s AND c.ativo = 1
                     LIMIT 1",
                    $record['carteira']
                ), ARRAY_A);

                if ($carteira) {
                    $id_carteira = $carteira['id_carteira'];
                }
            }

            $records[] = [
                'nome' => sanitize_text_field($record['nome'] ?? ''),
                'telefone' => $telefone,
                'cpf_cnpj' => $cpf,
                'contrato' => sanitize_text_field($record['contrato'] ?? ''),
                'id_carteira' => $id_carteira,
                'carteira' => sanitize_text_field($record['carteira'] ?? '')
            ];

            $valid_records++;
        }

        fclose($handle);

        if (empty($records)) {
            $error_message = 'Nenhum registro válido encontrado no arquivo';
            if (!empty($invalid_records)) {
                $error_message .= '. Erros encontrados: ' . implode('; ', array_slice($invalid_records, 0, 5));
                if (count($invalid_records) > 5) {
                    $error_message .= ' (e mais ' . (count($invalid_records) - 5) . ' erros)';
                }
            }
            wp_send_json_error($error_message);
        }

        wp_send_json_success([
            'total_records' => $line - 1,
            'valid_records' => $valid_records,
            'invalid_records' => count($invalid_records),
            'records' => $records,
            'errors' => !empty($invalid_records) ? array_slice($invalid_records, 0, 10) : []
        ]);
    }

    public function handle_create_campaign_from_file()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $file_data = isset($_POST['file_data']) ? json_decode(stripslashes($_POST['file_data']), true) : null;
        $template_id = intval($_POST['template_id'] ?? 0);
        $provider = sanitize_text_field($_POST['provider'] ?? '');

        if (!$file_data || !$template_id || empty($provider)) {
            wp_send_json_error('Dados incompletos');
        }

        $records = $file_data['records'] ?? [];
        if (empty($records)) {
            wp_send_json_error('Nenhum registro válido');
        }

        // ✅ VALIDAÇÃO BLOCKLIST - Remove registros bloqueados
        $original_count = count($records);
        $records = PC_Blocklist_Validator::filter_blocked_records($records);
        $blocked_count = $original_count - count($records);

        if (empty($records)) {
            wp_send_json_error('Todos os registros estão na blocklist. Nenhum envio será criado.');
        }

        if ($blocked_count > 0) {
            error_log("✅ Blocklist: Removidos $blocked_count registros bloqueados de $original_count no upload de arquivo");
        }

        // Busca template
        $message_post = get_post($template_id);
        if (!$message_post || $message_post->post_type !== 'message_template') {
            wp_send_json_error('Template de mensagem inválido');
        }
        $message_content = $message_post->post_content;

        // Insere na tabela envios_pendentes
        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $current_user_id = get_current_user_id();
        $agendamento_base_id = current_time('YmdHis');
        $prefix = strtoupper(substr($provider, 0, 1));
        if ($provider === 'CDA_RCS') {
            $prefix = 'R';
        }
        $agendamento_id = $prefix . $agendamento_base_id;

        $total_inserted = 0;
        $all_insert_data = [];

        foreach ($records as $record) {
            $mensagem_final = $this->replace_placeholders($message_content, $record);

            $all_insert_data[] = [
                'telefone' => $record['telefone'],
                'nome' => $record['nome'],
                'id_carteira' => $record['id_carteira'] ?? '',
                'idcob_contrato' => intval($record['contrato'] ?? 0),
                'cpf_cnpj' => $record['cpf_cnpj'] ?? '',
                'mensagem' => $mensagem_final,
                'fornecedor' => $provider,
                'agendamento_id' => $agendamento_id,
                'status' => 'pendente_aprovacao',
                'current_user_id' => $current_user_id,
                'valido' => 1,
                'data_cadastro' => current_time('mysql')
            ];
        }

        // Insere em lotes
        if (!empty($all_insert_data)) {
            $batch_size = 500;
            $batches = array_chunk($all_insert_data, $batch_size);

            foreach ($batches as $batch) {
                $this->bulk_insert($envios_table, $batch);
                $total_inserted += count($batch);
            }
        }

        if ($total_inserted === 0) {
            wp_send_json_error('Nenhum registro foi inserido');
        }

        $message = "Campanha criada! {$total_inserted} clientes inseridos.";
        if ($blocked_count > 0) {
            $message .= " {$blocked_count} registros removidos pela blocklist.";
        }

        wp_send_json_success([
            'message' => $message,
            'agendamento_id' => $agendamento_base_id,
            'records_inserted' => $total_inserted,
            'records_blocked' => $blocked_count
        ]);
    }

    // ========== FUNÇÕES HELPER PARA ID_CARTEIRA ==========

    /**
     * Retorna o nome da carteira pelo id interno.
     */
    private function get_carteira_nome_by_id($carteira_id)
    {
        if (empty($carteira_id)) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pc_carteiras_v2';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT nome FROM $table WHERE id = %d AND ativo = 1 LIMIT 1",
            intval($carteira_id)
        ), ARRAY_A);
        return ($row && !empty($row['nome'])) ? (string) $row['nome'] : '';
    }

    /**
     * Converte carteira_id (id interno de pc_carteiras_v2) para id_carteira (código cliente).
     * cm_baits.id_carteira armazena o id interno, não o código.
     */
    private function resolve_id_carteira_from_carteira_id($carteira_id)
    {
        if (empty($carteira_id)) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pc_carteiras_v2';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id_carteira FROM $table WHERE id = %d AND ativo = 1 LIMIT 1",
            intval($carteira_id)
        ), ARRAY_A);
        return ($row && !empty($row['id_carteira'])) ? (string) $row['id_carteira'] : '';
    }

    /**
     * Busca id_carteira baseado na tabela e idgis_ambiente
     * Verifica se a tabela está vinculada a alguma carteira e retorna o id_carteira
     */
    private function get_id_carteira_from_table_idgis($table_name, $idgis_ambiente)
    {
        global $wpdb;

        if (empty($table_name) || empty($idgis_ambiente)) {
            return '';
        }

        // Busca carteiras vinculadas à tabela
        $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';
        $carteiras_bases_table = $wpdb->prefix . 'pc_carteiras_bases_v2';

        $carteira = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id_carteira 
             FROM $carteiras_table c
             INNER JOIN $carteiras_bases_table cb ON c.id = cb.carteira_id
             WHERE cb.nome_base = %s AND c.ativo = 1
             LIMIT 1",
            $table_name
        ), ARRAY_A);

        if ($carteira && !empty($carteira['id_carteira'])) {
            return (string) $carteira['id_carteira'];
        }

        return '';
    }

    /**
     * Busca id_carteira baseado apenas no idgis_ambiente
     * Tenta encontrar através de qualquer tabela vinculada
     * Retorna id_carteira (código cliente, ex: "373"), NÃO c.id (interno)
     */
    private function get_id_carteira_from_idgis($idgis_ambiente)
    {
        global $wpdb;

        if (empty($idgis_ambiente)) {
            return '';
        }

        // Busca em todas as bases vinculadas
        $carteiras_table = $wpdb->prefix . 'pc_carteiras_v2';

        // Pega a primeira carteira ativa - retorna id_carteira (código), não id (interno)
        $carteira = $wpdb->get_row(
            "SELECT c.id_carteira 
             FROM $carteiras_table c
             WHERE c.ativo = 1
             LIMIT 1",
            ARRAY_A
        );

        if ($carteira && !empty($carteira['id_carteira'])) {
            return (string) $carteira['id_carteira'];
        }

        return '';
    }

    /**
     * Handler AJAX para buscar bases disponíveis (VW_BASE*)
     */
    public function handle_get_available_bases()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error('Permissão negada.'); return; }

        global $wpdb;
        $db_prefix = 'VW_BASE';

        // Busca tabelas disponíveis
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$db_prefix}%'", ARRAY_N);

        $bases = [];
        if ($tables) {
            foreach ($tables as $table) {
                $table_name = $table[0];
                // Usa INFORMATION_SCHEMA para estimativa rápida (não exata mas rápida)
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                    $table_name
                ));

                // Se não conseguir pela INFORMATION_SCHEMA, tenta count simples (pode ser lento)
                if (!$count) {
                    $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
                }

                $count_formatted = $count >= 1000000 ? round($count / 1000000, 1) . 'M' :
                    ($count >= 1000 ? round($count / 1000, 1) . 'K' : ($count ?: '0'));

                $bases[] = [
                    'id' => $table_name,
                    'name' => $table_name,
                    'records' => $count_formatted,
                ];
            }
        }

        wp_send_json_success($bases);
    }

    /**
     * Handler AJAX para buscar estatísticas do dashboard
     */
    public function handle_get_dashboard_stats()
    {
        // #region agent log
        $log_dir = dirname(__FILE__) . '/.cursor';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_path = $log_dir . '/debug.log';
        $log_entry = json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'painel-campanhas.php:4815',
            'message' => 'Function entry',
            'data' => ['function' => 'handle_get_dashboard_stats', 'post_data' => $_POST ?? [], 'get_data' => $_GET ?? []],
            'timestamp' => time() * 1000
        ]) . "\n";
        @file_put_contents($log_path, $log_entry, FILE_APPEND);
        error_log('DEBUG: handle_get_dashboard_stats called'); // Backup log
        // #endregion

        try {
            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B',
                'location' => 'painel-campanhas.php:4820',
                'message' => 'Before check_ajax_referer',
                'data' => ['nonce' => $_POST['nonce'] ?? $_GET['nonce'] ?? 'missing'],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            // Verifica nonce
            $nonce_check = check_ajax_referer('pc_nonce', 'nonce', false);

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B',
                'location' => 'painel-campanhas.php:4825',
                'message' => 'After check_ajax_referer',
                'data' => ['nonce_check_result' => $nonce_check],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            if (!$nonce_check) {
                // #region agent log
                $log_entry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'B',
                    'location' => 'painel-campanhas.php:4830',
                    'message' => 'Nonce check failed',
                    'data' => [],
                    'timestamp' => time() * 1000
                ]) . "\n";
                @file_put_contents($log_path, $log_entry, FILE_APPEND);
                // #endregion
                wp_send_json_error(['message' => 'Erro de autenticação. Por favor, recarregue a página.']);
                return;
            }

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'painel-campanhas.php:4835',
                'message' => 'Before is_user_logged_in',
                'data' => [],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            // Verifica se usuário está logado
            $user_logged_in = is_user_logged_in();

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'painel-campanhas.php:4840',
                'message' => 'After is_user_logged_in',
                'data' => ['user_logged_in' => $user_logged_in, 'user_id' => get_current_user_id()],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            if (!$user_logged_in) {
                wp_send_json_error(['message' => 'Usuário não autenticado.']);
                return;
            }

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'painel-campanhas.php:4845',
                'message' => 'Before global $wpdb',
                'data' => [],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            global $wpdb;
            $envios_table = $wpdb->prefix . 'envios_pendentes';
            $users_table = $wpdb->prefix . 'users';

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'painel-campanhas.php:4850',
                'message' => 'After table names',
                'data' => ['envios_table' => $envios_table, 'users_table' => $users_table, 'wpdb_prefix' => $wpdb->prefix],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'painel-campanhas.php:4855',
                'message' => 'Before table exists check',
                'data' => ['query' => "SHOW TABLES LIKE '{$envios_table}'"],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            // Verifica se a tabela existe
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $envios_table));

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'painel-campanhas.php:4860',
                'message' => 'After table exists check',
                'data' => ['table_exists' => $table_exists ? true : false],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            if (!$table_exists) {
                // #region agent log
                $log_entry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'painel-campanhas.php:4865',
                    'message' => 'Table does not exist, sending empty response',
                    'data' => [],
                    'timestamp' => time() * 1000
                ]) . "\n";
                @file_put_contents($log_path, $log_entry, FILE_APPEND);
                // #endregion
                wp_send_json_success([
                    'total' => 0,
                    'pending' => 0,
                    'sent' => 0,
                    'today' => 0,
                    'recentCampaigns' => [],
                ]);
                return;
            }

            // Total de campanhas únicas (agrupadas por agendamento_id, fornecedor)
            $total_campanhas = $wpdb->get_var("
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
            ");
            $total_campanhas = $total_campanhas ? intval($total_campanhas) : 0;

            // Campanhas pendentes de aprovação
            $campanhas_pendentes = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE status = %s
            ", 'pendente_aprovacao'));
            $campanhas_pendentes = $campanhas_pendentes ? intval($campanhas_pendentes) : 0;

            // Campanhas enviadas
            $campanhas_enviadas = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE status = %s
            ", 'enviado'));
            $campanhas_enviadas = $campanhas_enviadas ? intval($campanhas_enviadas) : 0;

            // Campanhas criadas hoje
            $campanhas_hoje = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE DATE(data_cadastro) = %s
            ", current_time('Y-m-d')));
            $campanhas_hoje = $campanhas_hoje ? intval($campanhas_hoje) : 0;

            // Últimas 5 campanhas (garantindo diversidade de providers)
            $recent_query = "
                SELECT
                    t1.agendamento_id,
                    t1.idgis_ambiente,
                    COALESCE(t1.fornecedor, '') AS provider,
                    t1.status,
                    MIN(t1.data_cadastro) AS created_at,
                    COUNT(t1.id) AS total_clients,
                    COALESCE(u.display_name, 'Usuário Desconhecido') AS user
                FROM `{$envios_table}` AS t1
                LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
                WHERE t1.fornecedor IS NOT NULL AND t1.fornecedor != ''
                GROUP BY t1.agendamento_id, t1.idgis_ambiente, t1.fornecedor, t1.status
                ORDER BY MIN(t1.data_cadastro) DESC
                LIMIT 10
            ";

            $recent_campanhas_raw = $wpdb->get_results($recent_query, ARRAY_A);

            // Garante diversidade: pega no máximo 1 de cada provider para ter variedade
            $recent_campanhas = [];
            $providers_seen = [];
            foreach ($recent_campanhas_raw as $camp) {
                $provider = strtoupper(trim($camp['provider']));
                if (!in_array($provider, $providers_seen)) {
                    $providers_seen[] = $provider;
                    $recent_campanhas[] = $camp;
                    if (count($recent_campanhas) >= 5) {
                        break;
                    }
                }
            }

            // Se não tiver 5 ainda, completa com os mais recentes independente do provider
            if (count($recent_campanhas) < 5) {
                $remaining = 5 - count($recent_campanhas);
                foreach ($recent_campanhas_raw as $camp) {
                    if (!in_array($camp, $recent_campanhas)) {
                        $recent_campanhas[] = $camp;
                        $remaining--;
                        if ($remaining <= 0) {
                            break;
                        }
                    }
                }
            }

            // Formata as campanhas recentes
            $formatted_campaigns = array_map(function ($camp) {
                return [
                    'id' => $camp['agendamento_id'] . '-' . $camp['provider'],
                    'name' => $camp['agendamento_id'],
                    'status' => str_replace('_', '-', $camp['status']),
                    'provider' => strtoupper($camp['provider']),
                    'quantity' => intval($camp['total_clients']),
                    'createdAt' => date('d/m/Y', strtotime($camp['created_at'])),
                    'user' => $camp['user'],
                ];
            }, $recent_campanhas ?: []);

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'painel-campanhas.php:4920',
                'message' => 'Before wp_send_json_success',
                'data' => [
                    'total' => $total_campanhas,
                    'pending' => $campanhas_pendentes,
                    'sent' => $campanhas_enviadas,
                    'today' => $campanhas_hoje,
                    'recent_count' => count($formatted_campaigns)
                ],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion

            wp_send_json_success([
                'total' => $total_campanhas,
                'pending' => $campanhas_pendentes,
                'sent' => $campanhas_enviadas,
                'today' => $campanhas_hoje,
                'recentCampaigns' => $formatted_campaigns,
            ]);

            // #region agent log
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'painel-campanhas.php:4930',
                'message' => 'After wp_send_json_success (should not reach here)',
                'data' => [],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            // #endregion
        } catch (Throwable $e) {
            // #region agent log
            $log_dir = dirname(__FILE__) . '/.cursor';
            if (!is_dir($log_dir)) {
                @mkdir($log_dir, 0755, true);
            }
            $log_path = $log_dir . '/debug.log';
            $log_entry = json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'painel-campanhas.php:5125',
                'message' => 'Fatal Error/Exception caught',
                'data' => [
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'error_type' => get_class($e),
                    'error_trace' => $e->getTraceAsString()
                ],
                'timestamp' => time() * 1000
            ]) . "\n";
            @file_put_contents($log_path, $log_entry, FILE_APPEND);
            error_log('DEBUG: Exception in handle_get_dashboard_stats: ' . $e->getMessage());
            // #endregion

            error_log('Erro fatal em handle_get_dashboard_stats: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Erro ao carregar dados: ' . $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para buscar lista de campanhas
     */
    public function handle_get_campanhas()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->users;

        // Filtros
        $status_filter = sanitize_text_field($_POST['status'] ?? $_GET['status'] ?? '');
        $fornecedor_filter = sanitize_text_field($_POST['fornecedor'] ?? $_GET['fornecedor'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? $_GET['search'] ?? '');
        $current_user_id = get_current_user_id();

        // Query base
        $query = "
            SELECT
                t1.agendamento_id,
                MAX(t1.idgis_ambiente) AS idgis_ambiente,
                t1.fornecedor AS provider,
                MAX(t1.status) AS status,
                MIN(t1.data_cadastro) AS data_cadastro,
                COUNT(t1.id) AS total_clients,
                COALESCE(MAX(u.display_name), 'Usuário Desconhecido') AS scheduled_by
            FROM `{$envios_table}` AS t1
            LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
            WHERE t1.current_user_id = %d
        ";

        $params = [$current_user_id];

        // Aplica filtros
        if ($status_filter) {
            $query .= " AND t1.status = %s";
            $params[] = $status_filter;
        }

        if ($fornecedor_filter) {
            $query .= " AND t1.fornecedor = %s";
            $params[] = $fornecedor_filter;
        }

        if ($search) {
            $query .= " AND (t1.agendamento_id LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $query = $wpdb->prepare($query, $params);

        $query .= "
            GROUP BY t1.agendamento_id, t1.fornecedor
            ORDER BY MIN(t1.data_cadastro) DESC
        ";

        $campanhas = $wpdb->get_results($query, ARRAY_A);

        // Formata as campanhas
        $formatted = array_map(function ($camp) {
            return [
                'id' => $camp['agendamento_id'] . '-' . $camp['provider'],
                'name' => $camp['agendamento_id'],
                'status' => str_replace('_', '-', $camp['status']),
                'provider' => strtoupper($camp['provider']),
                'quantity' => intval($camp['total_clients']),
                'createdAt' => date('d/m/Y', strtotime($camp['data_cadastro'])),
                'user' => $camp['scheduled_by'],
            ];
        }, $campanhas);

        wp_send_json_success($formatted);
    }

    // ========== HANDLERS PARA BLOCKLIST ==========

    public function handle_get_blocklist()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $tipo = isset($_POST['tipo']) ? sanitize_text_field($_POST['tipo']) : '';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        $table = $wpdb->prefix . 'pc_blocklist';

        $query = "SELECT b.*, u.user_login as criado_por_nome FROM $table b
                  LEFT JOIN {$wpdb->users} u ON b.criado_por = u.ID
                  WHERE 1=1";

        $params = [];

        if ($tipo && in_array($tipo, ['telefone', 'cpf'])) {
            $query .= " AND b.tipo = %s";
            $params[] = $tipo;
        }

        if ($search) {
            $query .= " AND b.valor LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $query .= " ORDER BY b.criado_em DESC";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $items = $wpdb->get_results($query, ARRAY_A);

        wp_send_json_success($items ?: []);
    }

    public function handle_add_to_blocklist()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $tipo = sanitize_text_field($_POST['tipo'] ?? '');
        $valor = sanitize_text_field($_POST['valor'] ?? '');
        $motivo = sanitize_textarea_field($_POST['motivo'] ?? '');

        if (!in_array($tipo, ['telefone', 'cpf'])) {
            wp_send_json_error('Tipo inválido. Use "telefone" ou "cpf".');
        }

        if (empty($valor)) {
            wp_send_json_error('Valor é obrigatório.');
        }

        // Limpa formatação
        if ($tipo === 'telefone') {
            $valor = preg_replace('/[^0-9]/', '', $valor);
            if (strlen($valor) < 10 || strlen($valor) > 13) {
                wp_send_json_error('Telefone inválido. Use formato: (11) 98765-4321');
            }
        } elseif ($tipo === 'cpf') {
            $valor = preg_replace('/[^0-9]/', '', $valor);
            if (strlen($valor) !== 11) {
                wp_send_json_error('CPF inválido. Use 11 dígitos.');
            }
        }

        $table = $wpdb->prefix . 'pc_blocklist';
        $current_user_id = get_current_user_id();

        $inserted = $wpdb->insert(
            $table,
            [
                'tipo' => $tipo,
                'valor' => $valor,
                'motivo' => $motivo,
                'criado_por' => $current_user_id,
            ],
            ['%s', '%s', '%s', '%d']
        );

        if ($inserted === false) {
            if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                wp_send_json_error('Este ' . $tipo . ' já está na blocklist.');
            }
            wp_send_json_error('Erro ao adicionar à blocklist: ' . $wpdb->last_error);
        }

        wp_send_json_success('Adicionado à blocklist com sucesso.');
    }

    public function handle_remove_from_blocklist()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        $id = intval($_POST['id'] ?? 0);

        if (!$id) {
            wp_send_json_error('ID inválido.');
        }

        $table = $wpdb->prefix . 'pc_blocklist';

        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($deleted === false) {
            wp_send_json_error('Erro ao remover da blocklist.');
        }

        wp_send_json_success('Removido da blocklist com sucesso.');
    }

    public function handle_check_blocklist()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;

        $telefones = isset($_POST['telefones']) ? json_decode(stripslashes($_POST['telefones']), true) : [];
        $cpfs = isset($_POST['cpfs']) ? json_decode(stripslashes($_POST['cpfs']), true) : [];

        $table = $wpdb->prefix . 'pc_blocklist';
        $blocked = [];

        // Verifica telefones
        if (!empty($telefones)) {
            $telefones_clean = array_map(function ($tel) {
                return preg_replace('/[^0-9]/', '', $tel);
            }, $telefones);

            $placeholders = implode(',', array_fill(0, count($telefones_clean), '%s'));
            $query = $wpdb->prepare(
                "SELECT valor FROM $table WHERE tipo = 'telefone' AND valor IN ($placeholders)",
                $telefones_clean
            );
            $results = $wpdb->get_col($query);
            $blocked = array_merge($blocked, array_map(function ($val) {
                return ['tipo' => 'telefone', 'valor' => $val];
            }, $results));
        }

        // Verifica CPFs
        if (!empty($cpfs)) {
            $cpfs_clean = array_map(function ($cpf) {
                return preg_replace('/[^0-9]/', '', $cpf);
            }, $cpfs);

            $placeholders = implode(',', array_fill(0, count($cpfs_clean), '%s'));
            $query = $wpdb->prepare(
                "SELECT valor FROM $table WHERE tipo = 'cpf' AND valor IN ($placeholders)",
                $cpfs_clean
            );
            $results = $wpdb->get_col($query);
            $blocked = array_merge($blocked, array_map(function ($val) {
                return ['tipo' => 'cpf', 'valor' => $val];
            }, $results));
        }

        wp_send_json_success(['blocked' => $blocked]);
    }
    /**
     * Endpoint de teste (apenas para debug interno, action removida do nopriv)
     */
    public function handle_ajax_test()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        wp_send_json_success([
            'message' => 'AJAX OK',
            'timestamp' => current_time('mysql'),
        ]);
    }

    public function handle_get_gosac_oficial_templates()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $static_creds = get_option('acm_static_credentials', []);
        $gosac_url = trim($static_creds['gosac_oficial_url'] ?? '');
        $gosac_token = trim($static_creds['gosac_oficial_token'] ?? '');

        if (empty($gosac_url) || empty($gosac_token)) {
            wp_send_json_success([]);
            return;
        }

        if (stripos($gosac_token, 'Bearer ') !== 0) {
            $gosac_token = 'Bearer ' . $gosac_token;
        }

        $all_templates = [];
        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $carteiras = $wpdb->get_results("SELECT id, nome, id_carteira, id_ruler FROM $table_carteiras WHERE ativo = 1 AND id_carteira IS NOT NULL AND id_carteira != ''", ARRAY_A);

        $pairs = [];
        if (!empty($carteiras)) {
            foreach ($carteiras as $c) {
                $id_ambient = trim($c['id_carteira'] ?? '');
                $id_ruler = trim($c['id_ruler'] ?? '');
                if (empty($id_ambient)) continue;
                $key = $id_ambient . '|' . $id_ruler;
                if (!isset($pairs[$key])) {
                    $pairs[$key] = ['id_ambient' => $id_ambient, 'id_ruler' => $id_ruler, 'nome' => $c['nome'] ?? ''];
                }
            }
        }
        if (empty($pairs)) {
            $pairs['default'] = ['id_ambient' => 'default', 'id_ruler' => '', 'nome' => ''];
        }

        foreach ($pairs as $pair) {
            $id_ambient = $pair['id_ambient'];
            $id_ruler = $pair['id_ruler'];
            $url = rtrim($gosac_url, '/') . '/templates/waba?idAmbient=' . urlencode($id_ambient);
            if (!empty($id_ruler)) {
                $url .= '&idRuler=' . urlencode($id_ruler);
            }
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $gosac_token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
                'sslverify' => false,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log("🔴 [Gosac Oficial] erro ao buscar templates para idAmbient=$id_ambient: " . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $templates_data = json_decode($body, true);
            $tpls = $this->extract_gosac_templates($templates_data);

            foreach ($tpls as $tpl) {
                $conn_id = $tpl['connectionId'] ?? null;
                $all_templates[] = [
                    'id' => $tpl['id'] ?? $tpl['name'] ?? '',
                    'name' => $tpl['name'] ?? $tpl['id'] ?? '',
                    'content' => $tpl['content'] ?? '',
                    'status' => $tpl['status'] ?? '',
                    'category' => $tpl['category'] ?? '',
                    'language' => $tpl['language'] ?? 'pt_BR',
                    'components' => $tpl['components'] ?? [],
                    'provider' => 'Gosac Oficial',
                    'id_ambient' => $id_ambient,
                    'templateName' => $tpl['name'] ?? $tpl['id'] ?? '',
                    'idRuler' => $tpl['idRuler'] ?? $id_ruler,
                    'connectionId' => $conn_id,
                    'variableComponents' => $tpl['variableComponents'] ?? $tpl['variable_components'] ?? [],
                    'carteira_nome' => $pair['nome'] ?? '',
                ];
            }
        }

        wp_send_json_success($all_templates);
    }

    private function extract_gosac_templates($templates_data)
    {
        $out = [];
        if (!is_array($templates_data)) {
            return $out;
        }
        $envs = isset($templates_data['data']) ? $templates_data['data'] : $templates_data;
        if (!is_array($envs)) {
            return $out;
        }
        foreach ($envs as $env) {
            if (!is_array($env) || isset($env['error'])) {
                continue;
            }
            $tpls = isset($env['templates']) && is_array($env['templates']) ? $env['templates'] : [];
            if (empty($tpls) && isset($env['id']) && isset($env['name'])) {
                $tpls = [$env];
            }
            foreach ($tpls as $t) {
                if (is_array($t) && (isset($t['id']) || isset($t['name']))) {
                    $t['idRuler'] = $env['idRuler'] ?? '';
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    public function handle_get_all_connections_health()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');
        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $carteiras = $wpdb->get_results("SELECT id, nome, id_carteira FROM $table_carteiras WHERE ativo = 1", ARRAY_A);

        $credentials = get_option('acm_provider_credentials', []);

        // Inject static GOSAC OFICIAL credentials
        $static_creds = get_option('acm_static_credentials', []);
        $gosac_url = $static_creds['gosac_oficial_url'] ?? '';
        $gosac_token = $static_creds['gosac_oficial_token'] ?? '';

        if (!empty($gosac_url) && !empty($gosac_token)) {
            if (!isset($credentials['gosac_oficial'])) {
                $credentials['gosac_oficial'] = [];
            }
            foreach ($carteiras as $wallet) {
                $id_amb = trim($wallet['id_carteira']);
                if (!empty($id_amb) && !isset($credentials['gosac_oficial'][$id_amb])) {
                    $credentials['gosac_oficial'][$id_amb] = [
                        'url' => $gosac_url,
                        'token' => $gosac_token
                    ];
                }
            }
        }

        // Inject static ROBBU OFICIAL credentials (linhas vêm do webhook, não da API)
        $robbu_token = trim($static_creds['robbu_invenio_token'] ?? '');
        if (!empty($robbu_token)) {
            if (!isset($credentials['robbu_oficial'])) {
                $credentials['robbu_oficial'] = [];
            }
            // Sempre inclui 'static' para exibir linhas Robbu (webhook)
            if (!isset($credentials['robbu_oficial']['static'])) {
                $credentials['robbu_oficial']['static'] = ['invenio_private_token' => $robbu_token];
            }
            foreach ($carteiras as $wallet) {
                $id_amb = trim($wallet['id_carteira']);
                if (!empty($id_amb) && !isset($credentials['robbu_oficial'][$id_amb])) {
                    $credentials['robbu_oficial'][$id_amb] = ['invenio_private_token' => $robbu_token];
                }
            }
        }

        $all_health_data = [];
        $fetched_envs = []; // Cache para evitar requisições duplicadas para o mesmo ambiente
        $debug_info = []; // Debug: collect external request details

        // Mapa id_ambient -> primeiro nome de carteira (evita duplicação quando várias carteiras usam o mesmo id)
        $id_ambient_to_wallet_name = [];
        foreach ($carteiras as $wallet) {
            $id = trim($wallet['id_carteira']);
            if (!empty($id) && !isset($id_ambient_to_wallet_name[$id])) {
                $id_ambient_to_wallet_name[$id] = $wallet['nome'];
            }
        }

        // Itera por (provider, id_ambient) único para evitar duplicar conexões
        foreach ($credentials as $provider => $envs) {
            if (!is_array($envs)) continue;
            foreach ($envs as $id_ambient => $data) {
                $id_ambient = trim($id_ambient);
                if (empty($id_ambient)) continue;

                $cache_key = $provider . '_' . $id_ambient;

                if (!isset($fetched_envs[$cache_key])) {
                    $provider_conns = [];
                    if ($provider === 'gosac_oficial') {
                        $url = rtrim($data['url'], '/') . '/connections/official?idAmbient=' . urlencode($id_ambient);
                        $token = $data['token'] ?? '';
                        if (stripos($token, 'Bearer ') !== 0) {
                            $token = 'Bearer ' . $token;
                        }

                        if (!empty($url) && !empty($token)) {
                            $request_headers = [
                                'Authorization' => $token,
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                            ];

                            $response = wp_remote_get($url, [
                                'headers' => $request_headers,
                                'timeout' => 45,
                                'sslverify' => false, // Workaround: certificado do GOSAC pode estar expirado (ERR_CERT_DATE_INVALID)
                            ]);

                            $http_code = is_wp_error($response) ? 'WP_ERROR' : wp_remote_retrieve_response_code($response);
                            $raw_body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);

                            // Collect debug info for this call (mask token partially)
                            $token_masked = substr($token, 0, 14) . '...' . substr($token, -6);
                            $debug_info[] = [
                                'external_url' => $url,
                                'method' => 'GET',
                                'headers_sent' => [
                                    'Authorization' => $token_masked,
                                    'Content-Type' => 'application/json',
                                    'Accept' => 'application/json',
                                ],
                                'http_status' => $http_code,
                                'raw_response' => $raw_body,
                                'id_ambient' => $id_ambient,
                                'provider' => 'gosac_oficial',
                            ];

                            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                                $body = $raw_body;
                                $connections_data = json_decode($body, true);
                                $env_items = isset($connections_data['data']) ? $connections_data['data'] : $connections_data;

                                if (is_array($env_items)) {
                                    foreach ($env_items as $env_item) {
                                        // Skip only when connections is empty/missing (ignore error field — some items may have both error text AND connections)
                                        if (!is_array($env_item) || empty($env_item['connections'])) {
                                            continue;
                                        }

                                        $conns = $env_item['connections'];
                                        if (!is_array($conns))
                                            continue;

                                        foreach ($conns as $conn) {
                                            // Parse stringified accountRestriction JSON
                                            $account_restriction = $conn['accountRestriction'] ?? '';
                                            if (is_string($account_restriction) && !empty($account_restriction)) {
                                                $parsed = json_decode($account_restriction, true);
                                                if (json_last_error() === JSON_ERROR_NONE) {
                                                    $account_restriction = $parsed;
                                                }
                                            }

                                            $provider_conns[] = [
                                                'id' => $conn['id'] ?? '',
                                                'name' => $conn['name'] ?? '',
                                                'status' => $conn['status'] ?? '',
                                                'type' => $conn['type'] ?? '',
                                                'messagingLimit' => $conn['messagingLimit'] ?? '',
                                                'accountRestriction' => $account_restriction,
                                                'provider' => 'Gosac Oficial',
                                                'id_ambient' => $id_ambient,
                                                'idRuler' => $env_item['idRuler'] ?? ''
                                            ];
                                        }
                                    }
                            }
                        }
                    }
                    } elseif ($provider === 'noah_oficial') {
                        $base_url = rtrim($data['url'], '/');
                        $token_raw = trim($data['token'] ?? '');
                        // NOAH API usa "INTEGRATION" no Authorization, não Bearer
                        $token = $token_raw;
                        if (!empty($token)) {
                            $token = preg_replace('/^(Bearer|INTEGRATION)\s+/i', '', $token);
                            $token = 'INTEGRATION ' . $token;
                        }

                        if (!empty($base_url) && !empty($token)) {
                            $channels = [];
                            $channel_ids = $data['channel_ids'] ?? null;
                            if (is_array($channel_ids) && !empty($channel_ids)) {
                                foreach ($channel_ids as $cid) {
                                    $channels[] = ['id' => $cid, 'channelId' => $cid];
                                }
                            } else {
                                $channels_url = $base_url . '/channels';
                                $ch_response = wp_remote_get($channels_url, [
                                    'headers' => [
                                        'Authorization' => $token,
                                        'Content-Type' => 'application/json',
                                        'Accept' => 'application/json',
                                    ],
                                    'timeout' => 15,
                                    'sslverify' => false,
                                ]);

                                if (!is_wp_error($ch_response) && wp_remote_retrieve_response_code($ch_response) === 200) {
                                    $ch_body = wp_remote_retrieve_body($ch_response);
                                    $ch_data = json_decode($ch_body, true);
                                    $channels = is_array($ch_data) ? (isset($ch_data['data']) ? $ch_data['data'] : $ch_data) : [];
                                    if (!is_array($channels)) {
                                        $channels = [];
                                    }
                                }
                            }

                            $seen_noah_ids = [];
                            if (is_array($channels)) {
                                foreach ($channels as $ch) {
                                    $ch_id = $ch['id'] ?? $ch['channelId'] ?? null;
                                    if ($ch_id === null || $ch_id === '') continue;

                                    // Novo formato: item já tem quality_rating (ex: /channels retorna array completo)
                                    if (isset($ch['quality_rating']) || isset($ch['qualityRating'])) {
                                        $conn_id = (string) $ch_id;
                                        if (isset($seen_noah_ids[$conn_id])) continue;
                                        $seen_noah_ids[$conn_id] = true;
                                        $quality_rating = $ch['quality_rating'] ?? $ch['qualityRating'] ?? '';
                                        $provider_conns[] = [
                                            'id' => $ch_id,
                                            'name' => $ch['verified_name'] ?? $ch['name'] ?? $ch['number'] ?? 'Canal ' . $ch_id,
                                            'number' => $ch['display_phone_number'] ?? $ch['phoneNumber'] ?? $ch['number'] ?? '',
                                            'status' => $ch['status'] ?? '',
                                            'qualityRating' => $quality_rating,
                                            'messagingLimit' => $ch['messaging_limit_tier'] ?? '',
                                            'provider' => 'Noah Oficial',
                                            'id_ambient' => $id_ambient,
                                        ];
                                        continue;
                                    }

                                    $quality_url = $base_url . '/phone-quality?channelId=' . urlencode($ch_id);
                                        $q_response = wp_remote_get($quality_url, [
                                            'headers' => [
                                                'Authorization' => $token,
                                                'Content-Type' => 'application/json',
                                                'Accept' => 'application/json',
                                            ],
                                            'timeout' => 15,
                                            'sslverify' => false,
                                        ]);

                                        $added_from_response = 0;
                                        if (!is_wp_error($q_response) && wp_remote_retrieve_response_code($q_response) === 200) {
                                            $q_body = wp_remote_retrieve_body($q_response);
                                            $q_data = json_decode($q_body, true);
                                            $items = is_array($q_data) ? (isset($q_data['data']) ? $q_data['data'] : $q_data) : [];
                                            if (!is_array($items)) {
                                                $items = [];
                                            }
                                            foreach ($items as $item) {
                                                if (!is_array($item)) continue;
                                                $item_id = (string) ($item['id'] ?? $ch_id);
                                                if (isset($seen_noah_ids[$item_id])) continue;
                                                $seen_noah_ids[$item_id] = true;
                                                $quality_rating = $item['quality_rating'] ?? $item['qualityRating'] ?? '';
                                                $quality_phone = $item['display_phone_number'] ?? $item['phoneNumber'] ?? '';
                                                $provider_conns[] = [
                                                    'id' => $item['id'] ?? $ch_id,
                                                    'name' => $item['verified_name'] ?? $ch['name'] ?? $ch['number'] ?? 'Canal ' . $ch_id,
                                                    'number' => $quality_phone,
                                                    'status' => $item['status'] ?? $ch['status'] ?? '',
                                                    'qualityRating' => $quality_rating,
                                                    'messagingLimit' => $item['messaging_limit_tier'] ?? '',
                                                    'provider' => 'Noah Oficial',
                                                    'id_ambient' => $id_ambient,
                                                ];
                                                $added_from_response++;
                                            }
                                        }
                                        if ($added_from_response === 0) {
                                            $provider_conns[] = [
                                                'id' => $ch_id,
                                                'name' => $ch['name'] ?? $ch['number'] ?? 'Canal ' . $ch_id,
                                                'number' => $ch['number'] ?? '',
                                                'status' => $ch['status'] ?? '',
                                                'qualityRating' => '',
                                                'provider' => 'Noah Oficial',
                                                'id_ambient' => $id_ambient,
                                            ];
                                        }
                                }
                            }
                        }
                    } elseif ($provider === 'robbu_oficial') {
                        $table_robbu = $wpdb->prefix . 'pc_robbu_line_status';
                        $robbu_lines = $wpdb->get_results("SELECT * FROM $table_robbu ORDER BY updated_at DESC", ARRAY_A);
                        if (is_array($robbu_lines)) {
                            foreach ($robbu_lines as $line) {
                                $num = trim(($line['area_code'] ?? '') . ($line['phone_number'] ?? ''));
                                $provider_conns[] = [
                                    'id' => $line['robbu_line_id'],
                                    'name' => $num ? ('+55 ' . $num) : ('Linha ' . $line['robbu_line_id']),
                                    'number' => ($line['country_code'] ?: '55') . ($line['area_code'] ?: '') . ($line['phone_number'] ?: ''),
                                    'status' => $line['status'] ?: '',
                                    'qualityRating' => $line['status'] ?: '',
                                    'messagingLimit' => $line['broadcast_limit_per_day'] ?: '',
                                    'provider' => 'Robbu Oficial',
                                    'id_ambient' => $id_ambient,
                                ];
                            }
                        }
                    }
                    $fetched_envs[$cache_key] = $provider_conns;
                }

                $wallet_name = $id_ambient_to_wallet_name[$id_ambient] ?? $id_ambient;
                foreach ($fetched_envs[$cache_key] as $conn) {
                    $conn_copy = $conn;
                    $conn_copy['wallet_name'] = $wallet_name;
                    $all_health_data[] = $conn_copy;
                }
            }
        }

        wp_send_json_success([
            'connections' => $all_health_data,
            'debug_info' => $debug_info,
        ]);
    }

    public function handle_get_templates_by_wallet()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $wallet_id = intval($_POST['wallet_id'] ?? 0);
        if (!$wallet_id) {
            wp_send_json_error('ID da carteira inválido');
            return;
        }

        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $wallet = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_carteiras WHERE id = %d", $wallet_id), ARRAY_A);

        if (!$wallet || empty($wallet['id_carteira'])) {
            wp_send_json_error('Carteira não encontrada ou sem ID de ambiente');
            return;
        }

        $id_ambient = trim($wallet['id_carteira']);
        $credentials = get_option('acm_provider_credentials', []);

        // Inject static GOSAC OFICIAL credentials
        $static_creds = get_option('acm_static_credentials', []);
        $gosac_url = $static_creds['gosac_oficial_url'] ?? '';
        $gosac_token = $static_creds['gosac_oficial_token'] ?? '';

        if (!empty($gosac_url) && !empty($gosac_token)) {
            if (!isset($credentials['gosac_oficial'])) {
                $credentials['gosac_oficial'] = [];
            }
            if (!isset($credentials['gosac_oficial'][$id_ambient])) {
                $credentials['gosac_oficial'][$id_ambient] = [
                    'url' => $gosac_url,
                    'token' => $gosac_token
                ];
            }
        }

        $all_templates = [];

        foreach ($credentials as $provider => $envs) {
            if (!is_array($envs) || !isset($envs[$id_ambient]))
                continue;

            $data = $envs[$id_ambient];

            if ($provider === 'gosac_oficial') {
                $url = rtrim($data['url'], '/') . '/templates/waba?idAmbient=' . urlencode($id_ambient);
                $token = $data['token'] ?? '';
                if (stripos($token, 'Bearer ') !== 0) {
                    $token = 'Bearer ' . $token;
                }

                if (!empty($url) && !empty($token)) {
                    $response = wp_remote_get($url, [
                        'headers' => [
                            'Authorization' => $token,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 15,
                        'sslverify' => false,
                    ]);

                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $body = wp_remote_retrieve_body($response);
                        $templates_data = json_decode($body, true);
                        $temps = $this->extract_gosac_templates($templates_data);
                        foreach ($temps as $template) {
                            $all_templates[] = [
                                'id' => $template['id'] ?? $template['name'] ?? '',
                                'name' => $template['name'] ?? $template['id'] ?? '',
                                'content' => $template['content'] ?? '',
                                'category' => $template['category'] ?? '',
                                'language' => $template['language'] ?? 'pt_BR',
                                'status' => $template['status'] ?? '',
                                'provider' => 'Gosac Oficial',
                                'id_ambient' => $id_ambient,
                                'idRuler' => $template['idRuler'] ?? ''
                            ];
                        }
                    } else {
                        error_log('🔴 [Gosac] getTemplatesByWallet falhou: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
                    }
                } elseif ($provider === 'noah_oficial') {
                    $base_url = rtrim($data['url'], '/');
                    $token_raw = trim($data['token'] ?? '');
                    $token = $token_raw;
                    if (!empty($token)) {
                        $token = preg_replace('/^(Bearer|INTEGRATION)\s+/i', '', $token);
                        $token = 'INTEGRATION ' . $token;
                    }

                    if (!empty($base_url) && !empty($token)) {
                        $url = $base_url . '/message-templates';
                        $response = wp_remote_get($url, [
                            'headers' => [
                                'Authorization' => $token,
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                            ],
                            'timeout' => 15,
                            'sslverify' => false,
                        ]);

                        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                            $body = wp_remote_retrieve_body($response);
                            $templates_data = json_decode($body, true);
                            $items = is_array($templates_data) ? (isset($templates_data['data']) ? $templates_data['data'] : (isset($templates_data['templates']) ? $templates_data['templates'] : $templates_data)) : [];

                            if (is_array($items)) {
                                foreach ($items as $tpl) {
                                    $all_templates[] = [
                                        'id' => $tpl['id'] ?? $tpl['templateId'] ?? '',
                                        'name' => $tpl['name'] ?? $tpl['templateName'] ?? '',
                                        'content' => '',
                                        'category' => $tpl['category'] ?? '',
                                        'language' => $tpl['language'] ?? 'pt_BR',
                                        'status' => $tpl['status'] ?? '',
                                        'provider' => 'Noah Oficial',
                                        'id_ambient' => $id_ambient,
                                        'templateName' => $tpl['name'] ?? $tpl['templateName'] ?? '',
                                        'templateId' => $tpl['templateId'] ?? $tpl['id'] ?? '',
                                        'channelId' => $tpl['channelId'] ?? '',
                                    ];
                                }
                            }
                        }
                    }
                }
                // robbu_oficial: templates via pc_get_robbu_oficial_templates (não dependem da carteira)
            }
        }

        wp_send_json_success($all_templates);
    }

    public function handle_get_robbu_oficial_templates()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }
        check_ajax_referer('pc_nonce', 'nonce');

        $static_creds = get_option('acm_static_credentials', []);
        $token_privado = trim($static_creds['robbu_invenio_token'] ?? '');
        if (empty($token_privado)) {
            wp_send_json_success([]);
            return;
        }

        $all_templates = [];
        $templates_url = 'http://s.robbu.com.br/wsInvenioAPI.ashx?token=' . urlencode($token_privado) . '&acao=buscartemplates';
        $response = wp_remote_get($templates_url, [
            'timeout' => 15,
            'sslverify' => false,
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $items = json_decode($body, true);
            if (!is_array($items)) $items = [];
            foreach ($items as $tpl) {
                if (!is_array($tpl)) continue;
                if (($tpl['IdCanal'] ?? 0) != 3) continue;
                $all_templates[] = [
                    'id' => $tpl['NomeTemplateWhatsapp'] ?? $tpl['NomeTemplate'] ?? '',
                    'name' => $tpl['NomeTemplateWhatsapp'] ?? $tpl['NomeTemplate'] ?? '',
                    'templateName' => $tpl['NomeTemplateWhatsapp'] ?? $tpl['NomeTemplate'] ?? '',
                    'content' => $tpl['Template'] ?? '',
                    'language' => $tpl['Linguagem'] ?? 'pt_BR',
                    'status' => $tpl['StatusWhatsapp'] ?? '',
                    'env_id' => 'static',
                    'provider' => 'Robbu Oficial',
                    'channelId' => $tpl['IdCanal'] ?? 3,
                ];
            }
        }
        wp_send_json_success($all_templates);
    }

    public function handle_get_robbu_webhook_stats()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }
        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table_events = $wpdb->prefix . 'pc_robbu_webhook_events';
        $table_lines = $wpdb->prefix . 'pc_robbu_line_status';

        $total_events = 0;
        $last_event_at = null;
        $events_by_type = [];
        $recent_events = [];

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_events'") === $table_events) {
            $total_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_events");
            $last_event_at = $wpdb->get_var("SELECT MAX(created_at) FROM $table_events");
            $events_by_type = $wpdb->get_results(
                "SELECT event_type, COUNT(*) as cnt FROM $table_events GROUP BY event_type",
                ARRAY_A
            );
            $recent_events = $wpdb->get_results(
                "SELECT id, event_type, created_at FROM $table_events ORDER BY created_at DESC LIMIT 10",
                ARRAY_A
            );
        }

        $total_lines = 0;
        $lines = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_lines'") === $table_lines) {
            $total_lines = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_lines");
            $lines = $wpdb->get_results(
                "SELECT robbu_line_id, status, phone_number, area_code, broadcast_limit_per_day, updated_at FROM $table_lines ORDER BY updated_at DESC LIMIT 20",
                ARRAY_A
            );
        }

        wp_send_json_success([
            'total_events' => $total_events,
            'last_event_at' => $last_event_at,
            'events_by_type' => $events_by_type,
            'recent_events' => $recent_events,
            'total_lines' => $total_lines,
            'lines' => $lines,
        ]);
    }

    public function handle_get_gosac_oficial_connections()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $credentials = get_option('acm_provider_credentials', []);
        $gosac_oficial_creds = $credentials['gosac_oficial'] ?? [];

        if (empty($gosac_oficial_creds)) {
            wp_send_json_success([]);
            return;
        }

        $all_connections = [];

        foreach ($gosac_oficial_creds as $env_id => $data) {
            $url = rtrim($data['url'], '/') . '/connections/official';
            $token = $data['token'] ?? '';

            if (empty($url) || empty($token))
                continue;

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log("🔴 [Gosac Oficial] erro ao buscar conexões para $env_id");
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $connections_data = json_decode($body, true);

            if (is_array($connections_data)) {
                $conns = isset($connections_data['data']) ? $connections_data['data'] : $connections_data;

                if (is_array($conns)) {
                    foreach ($conns as $conn) {
                        $all_connections[] = [
                            'id' => $conn['id'] ?? '',
                            'name' => $conn['name'] ?? '',
                            'status' => $conn['status'] ?? '',
                            'messagingLimit' => $conn['messagingLimit'] ?? '',
                            'accountRestriction' => $conn['accountRestriction'] ?? '',
                            'env_id' => $env_id
                        ];
                    }
                }
            }
        }

        wp_send_json_success($all_connections);
    }

    /**
     * NOAH Oficial: Lista templates aprovados (GET /v1/api/external/:apiId/message-templates)
     */
    public function handle_get_noah_oficial_templates()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $credentials = get_option('acm_provider_credentials', []);
        $noah_oficial_creds = $credentials['noah_oficial'] ?? [];

        if (empty($noah_oficial_creds)) {
            wp_send_json_success([]);
            return;
        }

        $all_templates = [];

        foreach ($noah_oficial_creds as $env_id => $data) {
            $base_url = rtrim($data['url'], '/');
            $token_raw = trim($data['token'] ?? '');
            $token = $token_raw;
            if (!empty($token)) {
                $token = preg_replace('/^(Bearer|INTEGRATION)\s+/i', '', $token);
                $token = 'INTEGRATION ' . $token;
            }

            if (empty($base_url) || empty($token)) {
                continue;
            }

            $url = $base_url . '/message-templates';

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
                'sslverify' => false,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log("🔴 [Noah Oficial] erro ao buscar templates para $env_id: " . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $templates_data = json_decode($body, true);

            if (is_array($templates_data)) {
                $items = isset($templates_data['data']) ? $templates_data['data'] : (isset($templates_data['templates']) ? $templates_data['templates'] : $templates_data);
                if (is_array($items)) {
                    foreach ($items as $tpl) {
                        $all_templates[] = [
                            'id' => $tpl['id'] ?? $tpl['templateId'] ?? '',
                            'templateId' => $tpl['templateId'] ?? $tpl['id'] ?? '',
                            'templateName' => $tpl['name'] ?? $tpl['templateName'] ?? '',
                            'name' => $tpl['name'] ?? $tpl['templateName'] ?? '',
                            'language' => $tpl['language'] ?? 'pt_BR',
                            'status' => $tpl['status'] ?? '',
                            'channelId' => $tpl['channelId'] ?? '',
                            'components' => $tpl['components'] ?? [],
                            'env_id' => $env_id,
                            'provider' => 'Noah Oficial',
                        ];
                    }
                }
            }
        }

        wp_send_json_success($all_templates);
    }

    /**
     * NOAH Oficial: Lista canais WABA (GET /v1/api/external/:apiId/channels)
     */
    public function handle_get_noah_oficial_channels()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $carteira_id = isset($_POST['carteira_id']) ? sanitize_text_field($_POST['carteira_id']) : '';
        $id_carteira = '';

        if (!empty($carteira_id)) {
            global $wpdb;
            $table = $wpdb->prefix . 'pc_carteiras_v2';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id_carteira FROM $table WHERE id = %d AND ativo = 1 LIMIT 1",
                intval($carteira_id)
            ), ARRAY_A);
            if ($row) {
                $id_carteira = $row['id_carteira'];
            }
        }

        $credentials = get_option('acm_provider_credentials', []);
        $noah_oficial_creds = $credentials['noah_oficial'] ?? [];

        if (empty($id_carteira) || !isset($noah_oficial_creds[$id_carteira])) {
            wp_send_json_success([]);
            return;
        }

        $data = $noah_oficial_creds[$id_carteira];
        $base_url = rtrim($data['url'], '/');
        $token_raw = trim($data['token'] ?? '');
        $token = $token_raw;
        if (!empty($token)) {
            $token = preg_replace('/^(Bearer|INTEGRATION)\s+/i', '', $token);
            $token = 'INTEGRATION ' . $token;
        }

        $url = $base_url . '/channels';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
            'sslverify' => false,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Erro ao buscar canais NOAH: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $channels_data = json_decode($body, true);
        $items = is_array($channels_data) ? (isset($channels_data['data']) ? $channels_data['data'] : $channels_data) : [];

        wp_send_json_success(is_array($items) ? $items : []);
    }

    public function handle_get_otima_templates()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';

        // wallet_id = id_carteira (ex: "373") - fetch sob demanda para uma carteira só
        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? $_GET['wallet_id'] ?? '');

        if (!empty($wallet_id)) {
            // Fetch direcionado: apenas a carteira solicitada
            $carteira = $wpdb->get_row($wpdb->prepare(
                "SELECT id_carteira, nome FROM $table_carteiras WHERE (id_carteira = %s OR id = %d) AND ativo = 1 LIMIT 1",
                $wallet_id,
                intval($wallet_id)
            ), ARRAY_A);
            $carteiras = $carteira ? [$carteira] : [];
        } else {
            // Fallback: todas as carteiras (ex: página Mensagens)
            $carteiras = $wpdb->get_results("SELECT id_carteira, nome FROM $table_carteiras WHERE ativo = 1", ARRAY_A);
        }

        if (empty($carteiras)) {
            wp_send_json_success([]);
            return;
        }

        // Busca tokens
        $static_credentials = get_option('acm_static_credentials', []);
        $token_rcs = trim($static_credentials['otima_rcs_token'] ?? '');
        $token_wpp = trim($static_credentials['otima_wpp_token'] ?? '');

        // Remove 'Bearer ' se existir
        $token_rcs = trim(preg_replace('/^Bearer\s+/i', '', $token_rcs));
        $token_wpp = trim(preg_replace('/^Bearer\s+/i', '', $token_wpp));

        $templates = [];

        // Função auxiliar para chamada API
        $fetch_otima = function ($url, $token) {
            if (empty($token)) {
                error_log('🟡 [Otima Debug] Token está vazio para URL: ' . $url);
                return null;
            }

            error_log('🔵 [Otima Debug] Requesting URL: ' . $url);
            error_log('🔵 [Otima Debug] Token Prefix: ' . substr($token, 0, 10) . '...');

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            // Fallback sem Bearer se der 400/401
            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 400 || $code === 401) {
                    $response = wp_remote_get($url, [
                        'headers' => [
                            'Authorization' => $token,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 15,
                    ]);
                }
            }

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Normaliza retorno
            if (isset($data['data']) && is_array($data['data']))
                return $data['data'];
            if (is_array($data))
                return $data;
            return [];
        };

        foreach ($carteiras as $carteira) {
            $customer_code = $carteira['id_carteira'];
            $carteira_nome = $carteira['nome'];

            // --- RCS Templates ---
            if (!empty($token_rcs)) {
                $url_rcs = "https://services.otima.digital/v1/rcs/template/{$customer_code}";
                $rcs_data = $fetch_otima($url_rcs, $token_rcs);

                if ($rcs_data && is_array($rcs_data)) {
                    foreach ($rcs_data as $tpl) {
                        // FILTRO REMOVIDO: A Ótima nem sempre retorna o ID da carteira no campo 'code' exato.
                        // Permitindo que o template carregue normalmente.
                        // Nome do template: Prioriza 'description', depois 'title' do rich card
                        $tpl_name = $tpl['description'] ?? '';
                        if (empty($tpl_name) && isset($tpl['rich_card']['title'])) {
                            $tpl_name = $tpl['rich_card']['title'];
                        }
                        if (empty($tpl_name)) {
                            $tpl_name = 'Template RCS ' . ($tpl['template_id'] ?? '');
                        }

                        // Estrutura RCS
                        $content = '';
                        if (isset($tpl['rich_card']) && is_array($tpl['rich_card'])) {
                            if (!empty($tpl['rich_card']['title']))
                                $content .= $tpl['rich_card']['title'] . "\n";
                            if (!empty($tpl['rich_card']['description']))
                                $content .= $tpl['rich_card']['description'];
                        } elseif (isset($tpl['text'])) {
                            $content = $tpl['text'];
                        }

                        $image_url = $tpl['rich_card']['image_url'] ?? null;

                        $templates[] = [
                            'id' => 'rcs_' . ($tpl['template_id'] ?? uniqid()),
                            'name' => $tpl_name,
                            'content' => $content,
                            'date' => date('Y-m-d H:i:s'),
                            'source' => 'otima_rcs',
                            'template_code' => $tpl['code'] ?? $tpl['template_id'] ?? '', // No RCS usamos o 'code' (apelido) para envio via Bulk API
                            'wallet_id' => $customer_code,
                            'wallet_name' => $carteira_nome,
                            'broker_code' => $tpl['broker_code'] ?? $tpl['brokerCode'] ?? '',
                            'customer_code' => $customer_code,
                            'image_url' => $image_url,
                            'raw_data' => $tpl
                        ];
                    }
                }
            }

            // --- WhatsApp Templates ---
            if (!empty($token_wpp)) {
                $url_wpp = "https://services.otima.digital/v1/whatsapp/template/hsm/{$customer_code}";
                $wpp_data = $fetch_otima($url_wpp, $token_wpp);

                if ($wpp_data && is_array($wpp_data)) {
                    foreach ($wpp_data as $tpl) {
                        // No WhatsApp, o template_code é o nome técnico do template
                        $templates[] = [
                            'id' => 'wpp_' . ($tpl['template_code'] ?? uniqid()),
                            'name' => $tpl['template_code'] ?? 'Template WhatsApp',
                            'content' => $tpl['content'] ?? '',
                            'date' => $tpl['created_date'] ?? date('Y-m-d H:i:s'),
                            'source' => 'otima_wpp',
                            'template_code' => $tpl['template_code'] ?? '',
                            'status' => $tpl['status'] ?? '',
                            'wallet_id' => $customer_code,
                            'wallet_name' => $carteira_nome,
                            'broker_code' => $tpl['broker_code'] ?? $tpl['brokerCode'] ?? '',
                            'customer_code' => $customer_code,
                            'status_desc' => $tpl['status_description'] ?? '',
                            'category' => $tpl['category'] ?? '',
                            'raw_data' => $tpl
                        ];
                    }
                }
            }
        }

        wp_send_json_success($templates);
    }

    public function handle_get_otima_brokers()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        // Busca tokens globais (igual no templates)
        $static_credentials = get_option('acm_static_credentials', []);
        $token_rcs = trim($static_credentials['otima_rcs_token'] ?? '');
        $token_wpp = trim($static_credentials['otima_wpp_token'] ?? '');

        // Remove 'Bearer ' da string do token se o usuário salvou com ele, e limpa quebras de linha invisíveis (HTTP 400)
        $token_rcs = preg_replace('/^Bearer\s+/i', '', trim($token_rcs));
        $token_wpp = preg_replace('/^Bearer\s+/i', '', trim($token_wpp));
        // Remove caracteres de controle (muito comum dar erro 400 em \r\n ocultos no banco)
        $token_rcs = preg_replace('/[\r\n\t]/', '', $token_rcs);
        $token_wpp = preg_replace('/[\r\n\t]/', '', $token_wpp);

        $brokers = [];
        $seen = [];
        $debug_log = [];

        $fetch_brokers = function ($url, $token) use (&$debug_log) {
            if (empty($token)) {
                $debug_log[] = "Token está vazio.";
                return null;
            }

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);

            if (is_wp_error($response)) {
                $debug_log[] = "WP Error: " . $response->get_error_message();
                return null;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status_code !== 200) {
                $debug_log[] = "HTTP $status_code: " . substr($body, 0, 150);
                return null;
            }

            $data = json_decode($body, true);

            if (isset($data['data']) && is_array($data['data']))
                return $data['data'];
            if (is_array($data))
                return $data;

            $debug_log[] = "JSON Invalido: " . substr($body, 0, 100);
            return [];
        };

        // Otima RCS Credential Endpoint
        if (empty($token_rcs)) {
            $debug_log[] = "Token RCS não configurado no painel.";
        } else {
            $url_rcs = "https://services.otima.digital/v1/rcs/credential";
            $rcs_creds = $fetch_brokers($url_rcs, $token_rcs);
            if ($rcs_creds && is_array($rcs_creds)) {
                foreach ($rcs_creds as $cred) {
                    $code = $cred['code'] ?? '';
                    if (!empty($code) && !isset($seen[$code])) {
                        $seen[$code] = true;
                        $nome = $cred['name'] ?? $code;
                        $brokers[] = [
                            'code' => $code,
                            'name' => "RCS - " . $nome,
                            'raw' => $cred
                        ];
                    }
                }
            }
        }

        if (empty($brokers) && !empty($debug_log)) {
            // Se não encontrou nenhum, e tem erro no log, joga na tela pro dev ver
            foreach ($debug_log as $idx => $msg) {
                $brokers[] = [
                    'code' => "error_$idx",
                    'name' => "ERRO: " . $msg,
                    'raw' => []
                ];
            }
        }

        wp_send_json_success($brokers);
    }

    /**
     * AJAX: Manually trigger the Salesforce ingestion.
     * Wraps everything in try/catch(Throwable) so any fatal returns clean JSON.
     */
    public function handle_run_salesforce_import()
    {
        // ── FATAL ERROR INTERCEPTOR ─────────────────────────────────────────
        // Captures compilation errors, memory leaks, and other uncatchable fatals.
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
                $log_msg = date('Y-m-d H:i:s') . " - SF FATAL ERROR: " . print_r($err, true) . "\n";
                @file_put_contents(WP_CONTENT_DIR . '/sf_fatal.log', $log_msg, FILE_APPEND);
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'success' => false,
                        'data' => ['error' => 'Uncatchable Fatal Error', 'details' => $err]
                    ]);
                }
            }
        });

        $tracer_file = __DIR__ . '/sf_ajax_trace.log';
        @file_put_contents($tracer_file, "--- NEW AJAX RUN ---\n");
        $trace = function ($msg) use ($tracer_file) {
            @file_put_contents($tracer_file, date('H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
        };

        $trace('Salesforce Import Step 1: Handler started.');
        error_log('Salesforce Import Step 1: Handler started.');

        // Soft nonce check — wp_verify_nonce returns 0 on failure (never wp_die)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'pc_nonce')) {
            $trace('Salesforce Import: Nonce validation failed.');
            wp_send_json_error(['error' => 'Nonce invalido ou expirado. Recarregue a pagina e tente novamente.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            $trace('Salesforce Import: Permission denied (not manage_options).');
            wp_send_json_error(['error' => 'Acesso negado: permissao manage_options necessaria.']);
            return;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            global $wpdb;
            error_log('Salesforce Import Step 2: Config variables initialized.');

            // ── Config (loaded from wp_options — never hardcode secrets) ────────
            $mkc_creds = get_option('acm_static_credentials', []);
            $sf_client_id     = trim($mkc_creds['mkc_client_id']     ?? '');
            $sf_client_secret = trim($mkc_creds['mkc_client_secret'] ?? '');
            $sf_account_id    = trim($mkc_creds['mkc_account_id']    ?? '');
            $sf_de_key        = trim($mkc_creds['mkc_de_key']        ?? 'Tracking_WhatsApp_Importado_FINAL');

            // mkc_auth_url ou fallback: derivar de mkc_token_url (ex: .../v2/token -> base)
            $sf_auth_url = trim($mkc_creds['mkc_auth_url'] ?? '');
            if (empty($sf_auth_url) && !empty($mkc_creds['mkc_token_url'])) {
                $tok = trim($mkc_creds['mkc_token_url']);
                $sf_auth_url = preg_replace('#/v2/token/?$#i', '', $tok);
            }

            // mkc_rest_url ou fallback: derivar de mkc_api_url (scheme + host)
            $sf_rest_url = trim($mkc_creds['mkc_rest_url'] ?? '');
            if (empty($sf_rest_url) && !empty($mkc_creds['mkc_api_url'])) {
                $parsed = wp_parse_url(trim($mkc_creds['mkc_api_url']));
                if (!empty($parsed['host'])) {
                    $sf_rest_url = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
                }
            }

            if (empty($sf_auth_url) || empty($sf_client_id) || empty($sf_client_secret)) {
                wp_send_json_error([
                    'error' => 'Credenciais da Salesforce Marketing Cloud incompletas. Configure: Token URL (ou Auth URL), Client ID e Client Secret em API Manager > Credenciais Estáticas.',
                ]);
                return;
            }
            if (empty($sf_account_id)) {
                wp_send_json_error([
                    'error' => 'Account ID da Salesforce Marketing Cloud não configurado. Adicione mkc_account_id em API Manager > Credenciais Estáticas (Marketing Cloud).',
                ]);
                return;
            }
            if (empty($sf_rest_url)) {
                wp_send_json_error([
                    'error' => 'API Base URL da Salesforce Marketing Cloud não configurada. Configure mkc_api_url ou mkc_rest_url em Credenciais Estáticas.',
                ]);
                return;
            }

            $page_size = 200;
            $table_name = 'salesforce_returns';

            $errors = array();
            $total_inserted = 0;
            $pages_processed = 0;

            // Soft Timeout Tracker
            $start_time = microtime(true);
            $max_execution = 20; // Safe limit before server force-kills PHP (30s max)

            error_log('Salesforce Import Step 3: Ensuring table exists (dbDelta).');
            // ── Ensure table exists ─────────────────────────────────────────────
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();
            $sql_create = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uniqueid text NOT NULL,
                uniqueid_hash varchar(64) NOT NULL,
                trackingtype varchar(100) DEFAULT '',
                sendtype varchar(100) DEFAULT '',
                mid varchar(100) DEFAULT '',
                eid varchar(200) DEFAULT '',
                contactkey varchar(200) DEFAULT '',
                mobilenumber varchar(50) DEFAULT '',
                eventdateutc datetime DEFAULT NULL,
                appid varchar(100) DEFAULT '',
                channelid varchar(100) DEFAULT '',
                channeltype varchar(50) DEFAULT '',
                conversationtype varchar(50) DEFAULT '',
                activityname varchar(150) DEFAULT '',
                channelname varchar(150) DEFAULT '',
                status varchar(100) DEFAULT '',
                reason text,
                jbdefinitionid varchar(200) DEFAULT '',
                sendidentifier varchar(200) DEFAULT '',
                assetid varchar(100) DEFAULT '',
                messagetypeid varchar(100) DEFAULT '',
                operacao__c varchar(100) DEFAULT '',
                cpf_cnpj__c varchar(50) DEFAULT '',
                name varchar(255) DEFAULT '',
                TemplateName varchar(255) DEFAULT '',
                criado_em datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniqueid_hash (uniqueid_hash)
            ) {$charset_collate};";

            $wpdb->query($sql_create);

            if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'TemplateName'"))) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN TemplateName varchar(255) DEFAULT ''");
            }

            error_log('Salesforce Import Step 4: Requesting OAuth2 Token... URL: ' . $sf_auth_url . '/v2/token');
            // ── OAuth2 Token ────────────────────────────────────────────────────
            $token_response = wp_remote_post($sf_auth_url . '/v2/token', array(
                'body' => wp_json_encode(array(
                    'grant_type' => 'client_credentials',
                    'client_id' => $sf_client_id,
                    'client_secret' => $sf_client_secret,
                    'account_id' => $sf_account_id,
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 30,
            ));

            if (is_wp_error($token_response)) {
                $err_msg = $token_response->get_error_message();
                error_log('Salesforce Import Token Error: ' . $err_msg);
                wp_send_json_error(array('error' => 'Token request failed: ' . $err_msg));
                return;
            }

            $token_http = wp_remote_retrieve_response_code($token_response);
            $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
            $access_token = isset($token_body['access_token']) ? $token_body['access_token'] : '';

            if (empty($access_token)) {
                error_log('Salesforce Import Token Empty. HTTP Code: ' . $token_http);
                wp_send_json_error(array(
                    'error' => 'Token Salesforce nao retornado (HTTP ' . $token_http . ')',
                    'details' => wp_remote_retrieve_body($token_response),
                ));
                return;
            }

            error_log('Salesforce Import Step 5: Token retrieved successfully. Access Token starts with: ' . substr($access_token, 0, 10) . '...');

            // ── Helper: normalize dates ─────────────────────────────────────────
            $normalize_date = function ($value) {
                if (empty($value))
                    return null;
                $str = trim(preg_replace('/\s+/', ' ', $value));
                if (strpos($str, 'T') !== false && strpos($str, 'Z') !== false) {
                    $str = str_replace('Z', '+00:00', $str);
                    $t = strtotime($str);
                    if ($t !== false)
                        return date('Y-m-d H:i:s', $t);
                }
                $t = strtotime($str);
                if ($t !== false)
                    return date('Y-m-d H:i:s', $t);
                $pm = array();
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(.+))?$/', $str, $pm)) {
                    $a = (int) $pm[1];
                    $b = (int) $pm[2];
                    $y = (int) $pm[3];
                    $tail = isset($pm[4]) ? trim($pm[4]) : '';
                    $m = $a > 12 ? $b : $a;
                    $d = $a > 12 ? $a : $b;
                    $t = strtotime($y . '-' . $m . '-' . $d . ' ' . $tail);
                    if ($t)
                        return date('Y-m-d H:i:s', $t);
                }
                return null;
            };

            // ── Paginated Fetch & Insert ────────────────────────────────────────
            $page = 1;
            error_log('Salesforce Import Step 6: Starting pagination loop.');
            while (true) {
                $url = $sf_rest_url . '/data/v1/customobjectdata/key/' . $sf_de_key . '/rowset'
                    . '?$page=' . $page . '&$pageSize=' . $page_size;

                error_log("Salesforce Import: Requesting Page {$page}... URL: {$url}");

                $data_response = wp_remote_get($url, array(
                    'headers' => array('Authorization' => 'Bearer ' . $access_token),
                    'timeout' => 60,
                ));

                if (is_wp_error($data_response)) {
                    error_log("Salesforce Import Page {$page} Error: " . $data_response->get_error_message());
                    $errors[] = 'Pagina ' . $page . ': ' . $data_response->get_error_message();
                    break;
                }

                $http_code = wp_remote_retrieve_response_code($data_response);
                error_log("Salesforce Import Page {$page} HTTP Code: {$http_code}");

                if ($http_code === 401 || $http_code === 403) {
                    $errors[] = 'Token expirado ou acesso negado (HTTP ' . $http_code . ') na pagina ' . $page . '.';
                    break;
                }

                if ($http_code !== 200) {
                    $errors[] = 'HTTP ' . $http_code . ' na pagina ' . $page . ': ' . wp_remote_retrieve_body($data_response);
                    break;
                }

                $body = json_decode(wp_remote_retrieve_body($data_response), true);
                if (!is_array($body)) {
                    $errors[] = 'Resposta invalida (nao e JSON) na pagina ' . $page . '.';
                    break;
                }

                $items = isset($body['items']) ? $body['items'] : array();
                if (empty($items)) {
                    error_log("Salesforce Import: No more items on page {$page}. Exiting loop.");
                    break;
                }

                error_log("Salesforce Import Page {$page}: Processing " . count($items) . " items.");

                foreach ($items as $item) {
                    $row = array_merge(
                        isset($item['keys']) && is_array($item['keys']) ? $item['keys'] : array(),
                        isset($item['values']) && is_array($item['values']) ? $item['values'] : array()
                    );

                    $uniqueid = isset($row['uniqueid']) ? (string) $row['uniqueid'] : '';
                    if (empty($uniqueid))
                        continue;

                    $uniqueid_hash = hash('sha256', $uniqueid);
                    $raw_date = isset($row['eventdateutc']) ? $row['eventdateutc']
                        : (isset($row['eventdateu']) ? $row['eventdateu'] : null);
                    $eventdateutc = $normalize_date($raw_date);

                    $trace("Item $uniqueid_hash - Preparing query");
                    $raw_query = $wpdb->prepare(
                        "INSERT INTO {$table_name}
                                (uniqueid, uniqueid_hash, trackingtype, sendtype, mid, eid, contactkey, mobilenumber,
                                 eventdateutc, appid, channelid, channeltype, conversationtype, activityname,
                                 channelname, status, reason, jbdefinitionid, sendidentifier, assetid,
                                 messagetypeid, operacao__c, cpf_cnpj__c, name, TemplateName)
                             VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                             ON DUPLICATE KEY UPDATE
                                 trackingtype=VALUES(trackingtype), sendtype=VALUES(sendtype),
                                 mid=VALUES(mid), eid=VALUES(eid), contactkey=VALUES(contactkey),
                                 mobilenumber=VALUES(mobilenumber), eventdateutc=VALUES(eventdateutc),
                                 appid=VALUES(appid), channelid=VALUES(channelid),
                                 channeltype=VALUES(channeltype), conversationtype=VALUES(conversationtype),
                                 activityname=VALUES(activityname), channelname=VALUES(channelname),
                                 status=VALUES(status), reason=VALUES(reason),
                                 jbdefinitionid=VALUES(jbdefinitionid), sendidentifier=VALUES(sendidentifier),
                                 assetid=VALUES(assetid), messagetypeid=VALUES(messagetypeid),
                                 operacao__c=VALUES(operacao__c), cpf_cnpj__c=VALUES(cpf_cnpj__c),
                                 name=VALUES(name), TemplateName=VALUES(TemplateName)",
                        array(
                            $uniqueid,
                            $uniqueid_hash,
                            isset($row['trackingtype']) ? (string) $row['trackingtype'] : '',
                            isset($row['sendtype']) ? (string) $row['sendtype'] : '',
                            isset($row['mid']) ? (string) $row['mid'] : '',
                            isset($row['eid']) ? (string) $row['eid'] : '',
                            isset($row['contactkey']) ? (string) $row['contactkey'] : '',
                            isset($row['mobilenumber']) ? (string) $row['mobilenumber'] : '',
                            $eventdateutc,
                            isset($row['appid']) ? (string) $row['appid'] : '',
                            isset($row['channelid']) ? (string) $row['channelid'] : '',
                            isset($row['channeltype']) ? (string) $row['channeltype'] : '',
                            isset($row['conversationtype']) ? (string) $row['conversationtype'] : '',
                            isset($row['activityname']) ? (string) $row['activityname'] : '',
                            isset($row['channelname']) ? (string) $row['channelname'] : '',
                            isset($row['status']) ? (string) $row['status'] : '',
                            isset($row['reason']) ? (string) $row['reason'] : '',
                            isset($row['jbdefinitionid']) ? (string) $row['jbdefinitionid'] : '',
                            isset($row['sendidentifier']) ? (string) $row['sendidentifier'] : '',
                            isset($row['assetid']) ? (string) $row['assetid'] : '',
                            isset($row['messagetypeid']) ? (string) $row['messagetypeid'] : '',
                            isset($row['operacao__c']) ? (string) $row['operacao__c'] : '',
                            isset($row['cpf_cnpj__c']) ? (string) $row['cpf_cnpj__c'] : '',
                            isset($row['name']) ? (string) $row['name'] : '',
                            isset($row['TemplateName']) ? (string) $row['TemplateName'] : (isset($row['templatename']) ? (string) $row['templatename'] : '')
                        )
                    );

                    $trace("Item $uniqueid_hash - Executing query");
                    $result = $wpdb->query($raw_query);
                    $trace("Item $uniqueid_hash - Query done");

                    if ($result === false) {
                        $errors[] = 'DB erro row uniqueid=' . substr($uniqueid, 0, 20) . ': ' . $wpdb->last_error;
                    } else {
                        $total_inserted++;
                    }
                }

                $pages_processed++;

                $has_next = isset($body['links']['next']) && !empty($body['links']['next']);
                if (!$has_next) {
                    $trace("Salesforce Import: Finished pagination at page {$page} (no next link).");
                    break;
                }


                $page++;
            }

            $trace("Salesforce Import Complete. Rows inserted/updated: {$total_inserted}, Pages processed: {$pages_processed}, Errors: " . count($errors));
            $trace("Calling wp_send_json_success() now.");

            wp_send_json_success(array(
                'rows_inserted' => $total_inserted,
                'pages_processed' => $pages_processed,
                'errors' => $errors,
                // Include diagnostic info as requested by user
                'diagnostic_info' => array(
                    'auth_url' => $sf_auth_url . '/v2/token',
                    'rest_base_url' => $sf_rest_url,
                    'tested_url' => $sf_rest_url . '/data/v1/customobjectdata/key/' . $sf_de_key . '/rowset?$page=1&$pageSize=200',
                    'token' => $access_token,
                ),
            ));

            $trace("Returned from wp_send_json_success() OK - this should never be reached as it calls die()");

        } catch (\Throwable $e) {
            $trace("Salesforce Import Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            wp_send_json_error(array(
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ));
        }
    }
}




// ========== CLASSES INTERNAS - Funcionalidades do Campaign Manager ==========

/**
 * Classe para validação de blocklist
 */
class PC_Blocklist_Validator
{

    public static function filter_blocked_records($records)
    {
        global $wpdb;

        if (empty($records) || !is_array($records)) {
            return $records;
        }

        $table = $wpdb->prefix . 'pc_blocklist';

        // Extrai telefones e CPFs dos registros
        $telefones = [];
        $cpfs = [];

        foreach ($records as $record) {
            if (!empty($record['telefone'])) {
                $tel_clean = preg_replace('/[^0-9]/', '', $record['telefone']);
                if (strlen($tel_clean) >= 10) {
                    $telefones[] = $tel_clean;
                }
            }
            if (!empty($record['cpf_cnpj'])) {
                $cpf_clean = preg_replace('/[^0-9]/', '', $record['cpf_cnpj']);
                if (strlen($cpf_clean) === 11) {
                    $cpfs[] = $cpf_clean;
                }
            }
        }

        $blocked_telefones = [];
        $blocked_cpfs = [];

        // Busca telefones bloqueados
        if (!empty($telefones)) {
            $telefones_unique = array_unique($telefones);
            $placeholders = implode(',', array_fill(0, count($telefones_unique), '%s'));
            $query = $wpdb->prepare(
                "SELECT valor FROM $table WHERE tipo = 'telefone' AND valor IN ($placeholders)",
                $telefones_unique
            );
            $blocked_telefones = $wpdb->get_col($query);
        }

        // Busca CPFs bloqueados
        if (!empty($cpfs)) {
            $cpfs_unique = array_unique($cpfs);
            $placeholders = implode(',', array_fill(0, count($cpfs_unique), '%s'));
            $query = $wpdb->prepare(
                "SELECT valor FROM $table WHERE tipo = 'cpf' AND valor IN ($placeholders)",
                $cpfs_unique
            );
            $blocked_cpfs = $wpdb->get_col($query);
        }

        // Filtra registros que não estão na blocklist
        $filtered_records = [];
        $blocked_count = 0;

        foreach ($records as $record) {
            $is_blocked = false;

            // Verifica telefone
            if (!empty($record['telefone'])) {
                $tel_clean = preg_replace('/[^0-9]/', '', $record['telefone']);
                if (in_array($tel_clean, $blocked_telefones)) {
                    $is_blocked = true;
                }
            }

            // Verifica CPF
            if (!$is_blocked && !empty($record['cpf_cnpj'])) {
                $cpf_clean = preg_replace('/[^0-9]/', '', $record['cpf_cnpj']);
                if (strlen($cpf_clean) === 11 && in_array($cpf_clean, $blocked_cpfs)) {
                    $is_blocked = true;
                }
            }

            if (!$is_blocked) {
                $filtered_records[] = $record;
            } else {
                $blocked_count++;
            }
        }

        // Log para debug
        if ($blocked_count > 0) {
            error_log("PC Blocklist: Filtrados $blocked_count registros bloqueados de um total de " . count($records));
        }

        return $filtered_records;
    }

    public static function get_blocked_count($records)
    {
        $original_count = count($records);
        $filtered = self::filter_blocked_records($records);
        return $original_count - count($filtered);
    }
}

/**
 * Classe interna para filtros (substitui Campaign_Manager_Filters)
 */
class PC_Campaign_Filters
{

    private static $excluded_columns = [
        'TELEFONE',
        'NOME',
        'IDGIS_AMBIENTE',
        'IDCOB_CONTRATO',
        'CPF',
        'CPF_CNPJ',
        'DATA_ATUALIZACAO',
        'DATA_CRIACAO',
        'DATA_INCLUSAO',
        'IDCOB_CLIENTE',
        'ID',
        'CODIGO_CLIENTE',
        'ULT_ATUALIZACAO',
        'CONTRATO',
        'ULTIMO_ENVIO_SMS',
        'FORNECEDOR',
        'ULT_FUP',
        'OPERADORA',
        'CONTRATO_PRODUTO',
        'IDCOB_TELEFONE',
        'ORIGEM_INFORMACAO',
        'PORTAL',
        'placa'
    ];

    public static function get_filterable_columns($table_name)
    {
        global $wpdb;

        if (empty($table_name)) {
            return new WP_Error('invalid_table', 'Nome de tabela inválido');
        }

        error_log('🔍 [get_filterable_columns] Buscando filtros para tabela: ' . $table_name);

        $columns_info = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             ORDER BY ORDINAL_POSITION",
            DB_NAME,
            $table_name
        ), ARRAY_A);

        if (empty($columns_info)) {
            error_log('🔴 [get_filterable_columns] Nenhuma coluna encontrada para tabela: ' . $table_name);
            return new WP_Error('no_columns', 'Não foi possível obter colunas da tabela');
        }

        error_log('🔍 [get_filterable_columns] Total de colunas na tabela: ' . count($columns_info));

        $numeric_types = ['int', 'bigint', 'decimal', 'float', 'double', 'tinyint', 'smallint', 'mediumint', 'real'];
        $categorical_threshold = 50;
        $filters = [];

        foreach ($columns_info as $column) {
            $column_name = $column['COLUMN_NAME'];
            $data_type = strtolower($column['DATA_TYPE']);

            // Pula colunas excluídas
            if (in_array(strtoupper($column_name), self::$excluded_columns)) {
                continue;
            }

            // Formata o label (capitaliza e substitui _ por espaço)
            $label = ucwords(strtolower(str_replace('_', ' ', $column_name)));

            $is_numeric = in_array($data_type, $numeric_types);
            $distinct_count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT `{$column_name}`)
                 FROM `{$table_name}`
                 WHERE `{$column_name}` IS NOT NULL"
            );

            if ($distinct_count === null || $distinct_count == 0) {
                // Pula colunas vazias
                continue;
            }

            if ($is_numeric && $distinct_count > $categorical_threshold) {
                // Filtro numérico (range)
                $filters[] = [
                    'column' => $column_name,
                    'label' => $label,
                    'type' => 'numeric',
                    'data_type' => $data_type
                ];
            } else {
                // Filtro categórico (select)
                $values = $wpdb->get_col(
                    "SELECT DISTINCT `{$column_name}`
                     FROM `{$table_name}`
                     WHERE `{$column_name}` IS NOT NULL
                     AND `{$column_name}` != ''
                     ORDER BY `{$column_name}` ASC
                     LIMIT 100"
                );

                if (!empty($values)) {
                    $filters[] = [
                        'column' => $column_name,
                        'label' => $label,
                        'type' => 'select',
                        'options' => $values
                    ];
                }
            }
        }

        error_log('✅ [get_filterable_columns] Total de filtros disponíveis: ' . count($filters));

        return $filters;
    }

    public static function build_where_clause($filters)
    {
        global $wpdb;

        $where_clauses = ['1=1'];
        $allowed_operators = [
            'equals',
            'not_equals',
            'greater',
            'greater_equals',
            'less',
            'less_equals',
            'contains',
            'not_contains',
            'starts_with',
            'ends_with',
            'in',
            'not_in'
        ];

        if (empty($filters) || !is_array($filters)) {
            return ' WHERE 1=1';
        }

        // Tenta detectar se é o formato antigo (chave-valor) ou novo (array de objetos)
        // Se a primeira chave for string e não numérico, provavelmente é o formato antigo
        $first_key = array_key_first($filters);
        $is_old_format = !is_int($first_key);

        if ($is_old_format) {
            // Mantém compatibilidade com formato antigo
            foreach ($filters as $column => $value) {
                if ($value === '' || $value === null)
                    continue;

                $sanitized_column = esc_sql(str_replace('`', '', $column));

                if (is_array($value)) {
                    $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                    $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` IN ({$placeholders})", $value);
                } else {
                    $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` = %s", $value);
                }
            }
        } else {
            // Novo formato: Array de objetos {column, operator, value}
            foreach ($filters as $filter) {
                if (empty($filter['column']) || empty($filter['operator'])) {
                    continue;
                }

                $column = $filter['column'];
                $operator = $filter['operator'];
                $value = $filter['value'] ?? null;

                // Pula valores vazios, exceto se for verificação de nulo (futuro)
                if ($value === '' || $value === null) {
                    continue;
                }

                if (!in_array($operator, $allowed_operators)) {
                    continue;
                }

                $sanitized_column = esc_sql(str_replace('`', '', $column));

                switch ($operator) {
                    case 'equals':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` = %s", $value);
                        break;
                    case 'not_equals':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` != %s", $value);
                        break;
                    case 'greater':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` > %s", $value);
                        break;
                    case 'greater_equals':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` >= %s", $value);
                        break;
                    case 'less':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` < %s", $value);
                        break;
                    case 'less_equals':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` <= %s", $value);
                        break;
                    case 'contains':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` LIKE %s", '%' . $wpdb->esc_like($value) . '%');
                        break;
                    case 'not_contains':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` NOT LIKE %s", '%' . $wpdb->esc_like($value) . '%');
                        break;
                    case 'starts_with':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` LIKE %s", $wpdb->esc_like($value) . '%');
                        break;
                    case 'ends_with':
                        $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` LIKE %s", '%' . $wpdb->esc_like($value));
                        break;
                    case 'in':
                        if (is_string($value)) {
                            // Tenta converter string separada por vírgula em array
                            $value = array_map('trim', explode(',', $value));
                        }
                        if (is_array($value) && !empty($value)) {
                            $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                            $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` IN ({$placeholders})", $value);
                        }
                        break;
                    case 'not_in':
                        if (is_string($value)) {
                            $value = array_map('trim', explode(',', $value));
                        }
                        if (is_array($value) && !empty($value)) {
                            $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                            $where_clauses[] = $wpdb->prepare("`{$sanitized_column}` NOT IN ({$placeholders})", $value);
                        }
                        break;
                }
            }
        }

        return ' WHERE ' . implode(' AND ', $where_clauses);
    }

    public static function count_records($table_name, $filters)
    {
        global $wpdb;

        if (empty($table_name)) {
            return 0;
        }

        $where_sql = self::build_where_clause($filters);
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`" . $where_sql);

        return intval($count);
    }

    public static function get_filtered_records($table_name, $filters, $limit = 0)
    {
        global $wpdb;

        if (empty($table_name)) {
            return [];
        }

        $where_sql = self::build_where_clause($filters);
        $limit_sql = '';
        if ($limit > 0) {
            $limit_sql = $wpdb->prepare(" LIMIT %d", $limit);
        }

        // Use SELECT * to avoid issues with column names case sensitivity or missing columns
        $sql = "SELECT * FROM `{$table_name}`" . $where_sql . $limit_sql;

        $records = $wpdb->get_results($sql, ARRAY_A);

        if ($records === null || $wpdb->last_error) {
            error_log('PC Campaign Filters - Erro ao buscar registros: ' . $wpdb->last_error);
            return [];
        }

        // Helper function to get value from record case-insensitively
        $get_val = function ($record, $keys) {
            if (!is_array($keys))
                $keys = [$keys];
            foreach ($keys as $key) {
                if (isset($record[$key]))
                    return $record[$key];
                if (isset($record[strtolower($key)]))
                    return $record[strtolower($key)];
                if (isset($record[strtoupper($key)]))
                    return $record[strtoupper($key)];
                // Try to find key case-insensitively
                foreach ($record as $k => $v) {
                    if (strcasecmp($k, $key) === 0)
                        return $v;
                }
            }
            return '';
        };

        $normalized_records = [];
        foreach ($records as $record) {
            $telefone = $get_val($record, ['TELEFONE', 'celular', 'phone']);
            // Fallback: try to find any column that looks like a phone
            if (empty($telefone)) {
                foreach ($record as $k => $v) {
                    if (stripos($k, 'tel') !== false || stripos($k, 'cel') !== false) {
                        $telefone = $v;
                        break;
                    }
                }
            }

            $normalized_records[] = [
                'telefone' => $telefone,
                'nome' => $get_val($record, ['NOME', 'name', 'cliente']),
                'idgis_ambiente' => $get_val($record, ['IDGIS_AMBIENTE', 'id_gis', 'ambiente']),
                'idcob_contrato' => $get_val($record, ['IDCOB_CONTRATO', 'contrato', 'id_contrato']),
                'cpf_cnpj' => $get_val($record, ['CPF', 'CNPJ', 'cpf_cnpj', 'doc'])
            ];
        }

        return $normalized_records;
    }
}

/**
 * Classe interna para iscas (substitui Campaign_Manager_Baits)
 */
class PC_Campaign_Baits
{

    public static function get_active_baits()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cm_baits';

        $baits = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE ativo = 1",
            ARRAY_A
        );

        return $baits ? $baits : [];
    }
}

/**
 * Classe interna para mapeamento IDGIS (substitui CM_IDGIS_Mapper)
 */
class PC_IDGIS_Mapper
{

    public static function get_mapped_idgis($tabela_origem, $provedor_destino, $idgis_original)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cm_idgis_mappings';

        if (empty($tabela_origem) || $idgis_original <= 0) {
            return intval($idgis_original);
        }

        $idgis_original = intval($idgis_original);

        // Tenta mapeamento específico do provedor
        if (!empty($provedor_destino)) {
            $mapped = $wpdb->get_var($wpdb->prepare(
                "SELECT idgis_ambiente_mapeado 
                 FROM {$table} 
                 WHERE tabela_origem = %s 
                 AND provedor_destino = %s 
                 AND idgis_ambiente_original = %d 
                 AND ativo = 1
                 LIMIT 1",
                $tabela_origem,
                $provedor_destino,
                $idgis_original
            ));

            if ($mapped) {
                return intval($mapped);
            }
        }

        // Tenta mapeamento coringa (*)
        $mapped = $wpdb->get_var($wpdb->prepare(
            "SELECT idgis_ambiente_mapeado 
             FROM {$table} 
             WHERE tabela_origem = %s 
             AND (provedor_destino = '*' OR provedor_destino = '' OR provedor_destino IS NULL) 
             AND idgis_ambiente_original = %d 
             AND ativo = 1
             LIMIT 1",
            $tabela_origem,
            $idgis_original
        ));

        if ($mapped) {
            return intval($mapped);
        }

        return $idgis_original;
    }
}



register_activation_hook(__FILE__, function () {
    $instance = Painel_Campanhas::get_instance();
    $instance->activate();
});


// Inicializa o plugin
function painel_campanhas()
{
    return Painel_Campanhas::get_instance();
}

// Inicia após plugins carregados
add_action('plugins_loaded', 'painel_campanhas');

