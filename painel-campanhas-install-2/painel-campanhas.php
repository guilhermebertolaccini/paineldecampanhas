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

/** Token fixo para GET /campaigns/v1/bases/{base}/stats (scripts externos). Sobrescreva em wp-config.php antes do plugin carregar. */
if (!defined('PC_AUDIT_API_TOKEN')) {
    define('PC_AUDIT_API_TOKEN', 'ABC123');
}

require_once __DIR__ . '/includes/class-pc-evolution-wa-validator.php';
require_once __DIR__ . '/includes/class-pc-validador-historico.php';

// MSSQL: carrega só se o arquivo existir (deploy incompleto não derruba o plugin inteiro).
$pc_sqlserver_connector = __DIR__ . '/includes/class-pc-sqlserver-connector.php';
if (is_readable($pc_sqlserver_connector)) {
    require_once $pc_sqlserver_connector;
} elseif (function_exists('error_log')) {
    error_log('Painel Campanhas: inclua includes/class-pc-sqlserver-connector.php — arquivo ausente: ' . $pc_sqlserver_connector);
}

$pc_wp_mssql_bridge = __DIR__ . '/includes/class-pc-wp-mssql-bridge.php';
if (is_readable($pc_wp_mssql_bridge)) {
    require_once $pc_wp_mssql_bridge;
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

    /**
     * Exige sessão WordPress válida e capability read (analistas / painel operacional).
     * Ações administrativas continuam com checagem adicional (ex.: manage_options).
     */
    private function pc_forbid_subscriber_ajax()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Sessão inválida', 'code' => 'forbidden'], 401);
        }
        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Acesso negado.', 'code' => 'forbidden'], 403);
        }
    }

    /**
     * Garante colunas para motivo de cancelamento/negativa e auditoria (quem cancelou).
     */
    private function maybe_add_envios_cancel_columns()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $has_motivo = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'motivo_cancelamento'");
        if (empty($has_motivo)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN motivo_cancelamento text NULL");
        }
        $has_cancel = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'cancelado_por'");
        if (empty($has_cancel)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN cancelado_por bigint(20) UNSIGNED NULL DEFAULT NULL");
        }
        $has_nome_campanha = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'nome_campanha'");
        if (empty($has_nome_campanha)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN nome_campanha varchar(255) NULL DEFAULT NULL AFTER agendamento_id");
        }
        $has_carteira_id = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'carteira_id'");
        if (empty($has_carteira_id)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN carteira_id bigint(20) DEFAULT NULL");
        }
        $has_nome_carteira = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'nome_carteira'");
        if (empty($has_nome_carteira)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN nome_carteira varchar(255) NULL DEFAULT NULL");
        }
    }

    /**
     * Normaliza status do MySQL para chaves usadas no React (filtros e badges).
     */
    private function map_campanha_status_ui($raw_status)
    {
        $s = strtolower(trim(str_replace('-', '_', (string) $raw_status)));
        $map = [
            'pendente_aprovacao' => 'pending',
            'pendente' => 'scheduled',
            'agendado_mkc' => 'scheduled',
            'processando' => 'scheduled',
            'enviado' => 'sent',
            'negado' => 'denied',
            'cancelada' => 'cancelled',
            'erro' => 'denied',
            'erro_envio' => 'denied',
            'erro_credenciais' => 'denied',
            'erro_validacao' => 'denied',
            'mkc_erro' => 'denied',
            'erro_inicio' => 'denied',
        ];

        return $map[$s] ?? str_replace('_', '-', $s);
    }

    /**
     * Administrador do painel: mesma regra de pcAjax.canManageOptions (capability manage_options).
     * Visão global de campanhas / métricas do dashboard só para este perfil.
     */
    private function is_pc_dashboard_admin(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Formata uma linha agregada de envios_pendentes (GROUP BY agendamento_id, fornecedor)
     * para o JSON consumido pelo React (Minhas Campanhas e Últimas Campanhas).
     */
    private function format_campanha_envios_row(array $camp): array
    {
        $cancel_id = !empty($camp['cancelado_por_id']) ? (int) $camp['cancelado_por_id'] : 0;
        $cancel_name = '';
        if ($cancel_id > 0) {
            $u = get_userdata($cancel_id);
            $cancel_name = $u ? ($u->display_name ?: $u->user_login) : '';
        }

        $total = max(0, (int) ($camp['total_messages'] ?? 0));
        $processed = max(0, (int) ($camp['processed_messages'] ?? 0));
        $err = max(0, (int) ($camp['error_messages'] ?? 0));
        $sent = max(0, (int) ($camp['cnt_enviado'] ?? 0));

        if ($total > 0) {
            $processed = min($processed, $total);
        }

        $agg_raw = strtolower(trim((string) ($camp['status'] ?? '')));
        if ($total > 0 && $agg_raw === 'cancelada' && $sent === 0 && $err === 0) {
            $processed = 0;
        }

        $progress_percent = 0.0;
        if ($total > 0) {
            $progress_percent = round(100.0 * ($processed / $total), 2);
            if ($progress_percent > 100.0) {
                $progress_percent = 100.0;
            }
        }

        $ag_id = (string) ($camp['agendamento_id'] ?? '');
        $nome_amigavel = trim((string) ($camp['nome_campanha'] ?? ''));
        $nome_exibicao = $nome_amigavel !== '' ? $nome_amigavel : $ag_id;

        $nome_carteira = trim((string) ($camp['nome_carteira_denorm'] ?? ''));

        return [
            'id' => $camp['agendamento_id'] . '-' . $camp['provider'],
            'agendamentoId' => $ag_id,
            'agendamento_id' => $ag_id,
            'name' => $nome_exibicao,
            'status' => $this->map_campanha_status_ui($camp['status']),
            'statusRaw' => (string) ($camp['status'] ?? ''),
            'provider' => strtoupper($camp['provider']),
            'fornecedor' => $camp['provider'],
            'quantity' => $total,
            'createdAt' => date('d/m/Y', strtotime($camp['data_cadastro'])),
            'user' => $camp['scheduled_by'],
            'motivoCancelamento' => $camp['motivo_cancelamento'] ?? '',
            'canceladoPor' => $cancel_name,
            'idCarteira' => isset($camp['id_carteira']) ? (string) $camp['id_carteira'] : '',
            'nomeCarteira' => $nome_carteira,
            'carteira_nome' => $nome_carteira,
            'wallet_name' => $nome_carteira,
            'total_messages' => $total,
            'processed_messages' => $processed,
            'error_messages' => $err,
            'progress_percent' => $progress_percent,
            'totalMessages' => $total,
            'totalProcessed' => $processed,
            'messagesSent' => $sent,
            'messagesError' => $err,
            'progressPercent' => $progress_percent,
        ];
    }

    private function init_hooks()
    {
        // Ativação/Desativação (registrado fora da classe, mas mantemos aqui para referência)
        // register_activation_hook precisa ser chamado fora da classe para funcionar corretamente
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Salesforce MC import cron — diário às 09:00 (fuso do WordPress)
        add_action('pc_salesforce_import_cron', [$this, 'run_salesforce_import_cron']);
        add_action('init', [$this, 'maybe_reschedule_salesforce_cron_9am'], 5);

        // Ponte MySQL → MSSQL: espelho operacional + snapshot de saúde (diário)
        add_action('painel_campanhas_daily_mssql_bridge', [$this, 'run_mssql_wp_bridge_cron']);
        add_action('init', [$this, 'maybe_schedule_mssql_bridge_cron'], 6);

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
        add_action('wp_ajax_pc_get_robbu_webhook_config', [$this, 'handle_get_robbu_webhook_config']);
        add_action('wp_ajax_pc_save_robbu_webhook_secret', [$this, 'handle_save_robbu_webhook_secret']);
        add_action('wp_ajax_pc_save_static_credentials', [$this, 'handle_save_static_credentials']);
        add_action('wp_ajax_pc_evolution_save_config', ['PC_Evolution_WA_Validator', 'ajax_save_config']);
        add_action('wp_ajax_pc_evolution_get_config', ['PC_Evolution_WA_Validator', 'ajax_get_config']);
        add_action('wp_ajax_pc_wa_validator_upload', ['PC_Evolution_WA_Validator', 'ajax_upload']);
        add_action('wp_ajax_pc_wa_validator_step', ['PC_Evolution_WA_Validator', 'ajax_step']);
        add_action('admin_post_pc_wa_validator_download', ['PC_Evolution_WA_Validator', 'handle_download']);
        add_action('admin_post_pc_wa_validator_download_hist_original', static function () {
            PC_Validador_Historico::handle_download_historico('orig');
        });
        add_action('admin_post_pc_wa_validator_download_hist_validado', static function () {
            PC_Validador_Historico::handle_download_historico('val');
        });
        add_action(PC_Validador_Historico::CRON_HOOK, ['PC_Validador_Historico', 'cron_limpar_antigos']);
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
        add_action('wp_ajax_pc_get_making_teams', [$this, 'handle_get_making_teams']);
        add_action('wp_ajax_pc_get_making_cost_centers', [$this, 'handle_get_making_cost_centers']);

        // AJAX Salesforce Manual Import
        add_action('wp_ajax_pc_run_salesforce_import', [$this, 'handle_run_salesforce_import']);
        // AJAX para Aprovar Campanhas
        add_action('wp_ajax_pc_get_pending_campaigns', [$this, 'handle_get_pending_campaigns']);
        add_action('wp_ajax_pc_get_microservice_config', [$this, 'handle_get_microservice_config']);
        add_action('wp_ajax_pc_get_nest_client_config', [$this, 'handle_get_nest_client_config']);
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
        add_action('wp_ajax_pc_get_salesforce_sync_status', [$this, 'handle_get_salesforce_sync_status']);
        add_action('wp_ajax_pc_clear_base_cache', [$this, 'handle_clear_base_cache']);
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
        add_action('wp_ajax_pc_cancel_campanha', [$this, 'handle_cancel_campanha']);
        add_action('wp_ajax_pc_cancel_campaign', [$this, 'handle_cancel_campanha']);
        add_action('wp_ajax_pc_get_available_bases', [$this, 'handle_get_available_bases']);
        add_action('wp_ajax_pc_get_bases_dados', [$this, 'handle_get_available_bases']);
        add_action('wp_ajax_pc_get_line_health', [$this, 'handle_get_line_health']);
        add_action('wp_ajax_pc_get_mssql_settings', [$this, 'handle_get_mssql_settings']);
        add_action('wp_ajax_pc_save_mssql_settings', [$this, 'handle_save_mssql_settings']);
        add_action('wp_ajax_pc_mssql_operational_sync_now', [$this, 'handle_mssql_operational_sync_now']);

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

        // Webhook Robbu — autenticação: header X-Robbu-Token, Bearer ou ?token= (segredo em API Manager)
        register_rest_route('robbu-webhook/v2', '/receive', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_robbu_webhook_receive'],
            'permission_callback' => [$this, 'check_robbu_webhook_permission'],
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

        register_rest_route('campaigns/v1', '/bases/(?P<base_name>[a-zA-Z0-9_]+)/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_base_min_ult_atualizacao'],
            'permission_callback' => function ($request) {
                $expected = defined('PC_AUDIT_API_TOKEN') ? (string) PC_AUDIT_API_TOKEN : '';
                if ($expected === '') {
                    return new WP_Error(
                        'audit_token_not_configured',
                        'PC_AUDIT_API_TOKEN não está definido ou está vazio. Configure em wp-config.php.',
                        ['status' => 503]
                    );
                }
                $client_token = trim(
                    (string) (
                        $request->get_header('x-api-key')
                        ?: $request->get_header('x_api_key')
                        ?: ''
                    )
                );
                if ($client_token === '') {
                    $auth = $request->get_header('authorization');
                    if (is_string($auth) && preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
                        $client_token = trim($m[1]);
                    }
                }
                if ($client_token !== '' && hash_equals($expected, $client_token)) {
                    return true;
                }
                return new WP_Error(
                    'rest_forbidden',
                    'Token de API inválido ou ausente.',
                    ['status' => 401]
                );
            },
            'args' => [
                'base_name' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        // Métricas do Validador WhatsApp (Evolution) — usuários do painel (read)
        register_rest_route('api/v1', '/validador/metricas', [
            'methods' => 'GET',
            'callback' => ['PC_Evolution_WA_Validator', 'rest_validador_metricas'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('read');
            },
            'args' => [
                'data_inicio' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'data_fim' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route('validador/v1', '/historico', [
            'methods' => 'GET',
            'callback' => ['PC_Validador_Historico', 'rest_historico'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('read');
            },
        ]);
    }

    public function check_api_key_rest($request)
    {
        $master_key = trim(get_option('acm_master_api_key', ''));
        if (empty($master_key)) {
            error_log('🔴 [REST API] Master API Key não configurada');
            return new WP_Error('no_master_key', 'Master API Key não configurada.', ['status' => 503]);
        }

        // Autenticação apenas via header (api_key na query foi removida — evita vazamento em logs/Referer)
        $provided_key = $request->get_header('x-api-key') ?: $request->get_header('x_api_key');

        $provided_key = trim($provided_key ?: '');

        if (empty($provided_key)) {
            error_log('🔴 [REST API] Header X-API-KEY não fornecido');
            error_log('🔴 [REST API] Headers recebidos: ' . json_encode(array_keys($request->get_headers())));
            return new WP_Error('no_key_provided', 'API Key não fornecida no header X-API-KEY.', ['status' => 401]);
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

    /**
     * Nome de base para auditoria REST: somente VW_BASE_* com [A-Za-z0-9_].
     */
    private function is_valid_vw_base_audit_table_name(string $name): bool
    {
        return (bool) preg_match('/^VW_BASE_[A-Za-z0-9_]+$/', $name);
    }

    /**
     * GET /wp-json/campaigns/v1/bases/{base_name}/stats — MIN(ult_atualizacao) (MSSQL primeiro, fallback MySQL).
     */
    public function rest_get_base_min_ult_atualizacao($request)
    {
        $base_name = sanitize_text_field((string) $request->get_param('base_name'));
        if (!$this->is_valid_vw_base_audit_table_name($base_name)) {
            return new WP_Error(
                'invalid_base_name',
                'Nome de base inválido. Use apenas VW_BASE_ seguido de letras, números e underscores.',
                ['status' => 400]
            );
        }

        if (class_exists('PC_SqlServer_Connector') && PC_SqlServer_Connector::is_enabled()) {
            $mssql = PC_SqlServer_Connector::fetch_min_ult_atualizacao($base_name);
            if (!empty($mssql['ok'])) {
                return rest_ensure_response([
                    'base' => $base_name,
                    'min_atualizacao' => $mssql['min'],
                    'source' => 'MSSQL',
                ]);
            }
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $base_name));
        if (empty($exists)) {
            return new WP_Error(
                'base_not_found',
                'Base não encontrada no MySQL nem consultável no MSSQL.',
                ['status' => 404]
            );
        }

        $wpdb->suppress_errors(true);
        $min = $wpdb->get_var(
            'SELECT MIN(`ult_atualizacao`) FROM `' . esc_sql($base_name) . '`'
        );
        $db_err = $wpdb->last_error;
        $wpdb->suppress_errors(false);

        if ($db_err !== '') {
            return new WP_Error(
                'db_error',
                'Erro ao consultar a base (confira se a coluna ult_atualizacao existe): ' . $db_err,
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'base' => $base_name,
            'min_atualizacao' => ($min !== null && $min !== '') ? (string) $min : null,
            'source' => 'MySQL',
        ]);
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

        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_campanha'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_campanha varchar(255) NULL DEFAULT NULL AFTER agendamento_id");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_carteira varchar(255) NULL DEFAULT NULL");
        }

        $query = $wpdb->prepare("
            SELECT 
                id,
                agendamento_id,
                CONCAT('55', telefone) as telefone,
                nome,
                COALESCE(id_carteira, '') as id_carteira,
                COALESCE(carteira_id, '') as carteira_id,
                idgis_ambiente,
                idcob_contrato,
                COALESCE(cpf_cnpj, '') as cpf_cnpj,
                data_cadastro as data_cadastro,
                mensagem,
                COALESCE(midia_campanha, '') as midia_campanha,
                COALESCE(nome_campanha, '') as nome_campanha,
                COALESCE(nome_carteira, '') as nome_carteira
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

            // carteira_nome: desnormalizado em envios_pendentes.nome_carteira; fallback por PK
            $carteira_nome = trim((string) ($row['nome_carteira'] ?? ''));
            if ($carteira_nome === '' && !empty($row['carteira_id'])) {
                $carteira_nome = $this->get_carteira_nome_by_id($row['carteira_id']);
            }

            // Objeto `variables`: espelha colunas da linha para o Nest (GOSAC/NOAH).
            $nome_campanha_row = (string) ($row['nome_campanha'] ?? '');
            $msg_decoded = json_decode((string) ($row['mensagem'] ?? ''), true);
            $noah_channel_rest = '';
            if (is_array($msg_decoded) && !empty($msg_decoded['channelId'])) {
                $cid_n = (int) $msg_decoded['channelId'];
                if ($cid_n > 0) {
                    $noah_channel_rest = (string) $cid_n;
                }
            }
            $variables_row = [
                'nome' => (string) ($row['nome'] ?? ''),
                'telefone' => (string) ($row['telefone'] ?? ''),
                'cpf_cnpj' => (string) ($row['cpf_cnpj'] ?? ''),
                'idcob_contrato' => (string) ($row['idcob_contrato'] ?? ''),
                'idgis_ambiente' => (string) ($row['idgis_ambiente'] ?? ''),
                'nome_campanha' => $nome_campanha_row,
            ];
            if ($noah_channel_rest !== '') {
                $variables_row['noah_channel_id'] = $noah_channel_rest;
                $variables_row['broker_code'] = $noah_channel_rest;
            }
            if (is_array($msg_decoded) && ($msg_decoded['template_source'] ?? '') === 'making_oficial') {
                $mk_vars = $msg_decoded['variables'] ?? [];
                if (is_array($mk_vars)) {
                    foreach ($mk_vars as $mk_k => $mk_v) {
                        if (is_string($mk_k) && $mk_k !== '') {
                            $variables_row[$mk_k] = (string) $mk_v;
                        }
                    }
                }
            }

            $formatted_data[] = [
                'id' => isset($row['id']) ? (string) $row['id'] : '',
                'agendamento_id' => isset($row['agendamento_id']) ? (string) $row['agendamento_id'] : '',
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
                'nome_campanha' => $nome_campanha_row,
                'broker_code' => $noah_channel_rest,
                'variables' => $variables_row,
            ];
        }

        return rest_ensure_response($formatted_data);
    }

    public function activate()
    {
        $this->add_rewrite_rules();
        $this->create_tables();
        PC_Validador_Historico::ensure_table();
        PC_Validador_Historico::maybe_schedule_cron();
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
        $this->maybe_add_envios_cancel_columns();

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
        wp_clear_scheduled_hook(PC_Validador_Historico::CRON_HOOK);
        wp_clear_scheduled_hook('painel_campanhas_daily_mssql_bridge');
    }

    /**
     * Agenda sincronização diária MySQL → MSSQL (espelho + snapshot de saúde).
     */
    public function maybe_schedule_mssql_bridge_cron()
    {
        if (!wp_next_scheduled('painel_campanhas_daily_mssql_bridge')) {
            wp_schedule_event(time() + 300, 'daily', 'painel_campanhas_daily_mssql_bridge');
        }
    }

    public function run_mssql_wp_bridge_cron()
    {
        if (class_exists('PC_Wp_Mssql_Bridge')) {
            PC_Wp_Mssql_Bridge::run_daily_operational_job();
        }
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

            $import_ok = is_string($output)
                && strpos($output, '[DONE]') !== false
                && stripos($output, '[DB ERROR]') === false
                && stripos($output, 'Error: wp-load.php') === false;
            if ($import_ok) {
                update_option('pc_last_salesforce_tracking_run', current_time('mysql'));
            } else {
                @file_put_contents($log_file, "[{$timestamp}] WARN: import não marcou sucesso; pc_last_salesforce_tracking_run não atualizado.\n", FILE_APPEND);
            }
        } catch (\Throwable $e) {
            @file_put_contents($log_file, "[{$timestamp}] FATAL: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    /**
     * Realinha o WP-Cron do import Salesforce para execução diária às 09:00 (timezone WP).
     */
    public function maybe_reschedule_salesforce_cron_9am()
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        $v = (int) get_option('pc_salesforce_cron_schedule_v', 0);
        if ($v >= 4) {
            if (!wp_next_scheduled('pc_salesforce_import_cron')) {
                $this->schedule_salesforce_cron_next_9am();
            }
            return;
        }
        wp_clear_scheduled_hook('pc_salesforce_import_cron');
        $this->schedule_salesforce_cron_next_9am();
        update_option('pc_salesforce_cron_schedule_v', 4);
    }

    private function schedule_salesforce_cron_next_9am()
    {
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Sao_Paulo');
            $now = new DateTime('now', $tz);
            $run = new DateTime('today 09:00', $tz);
            if ($now >= $run) {
                $run->modify('+1 day');
            }
            wp_schedule_event($run->getTimestamp(), 'daily', 'pc_salesforce_import_cron');
        } catch (\Throwable $e) {
            error_log('🔴 [SF Cron] Falha ao agendar 09:00: ' . $e->getMessage());
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pc_salesforce_import_cron');
        }
    }

    /**
     * Última execução bem-sucedida do job de tracking/import Salesforce (WP-Cron).
     */
    public function handle_get_salesforce_sync_status()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error('Acesso negado');
            return;
        }
        $last = get_option('pc_last_salesforce_tracking_run', '');
        $last_str = is_string($last) ? $last : '';
        $next = wp_next_scheduled('pc_salesforce_import_cron');

        $stale_after_24h = true;
        if ($last_str !== '') {
            $ts = strtotime($last_str);
            if ($ts && (time() - $ts) < DAY_IN_SECONDS) {
                $stale_after_24h = false;
            }
        }

        $next_label = '';
        if ($next) {
            $next_ts = (int) $next;
            $next_label = wp_date('d/m', $next_ts) . ' às ' . wp_date('H:i', $next_ts);
        }

        wp_send_json_success([
            'lastRunMysql' => $last_str,
            'nextScheduledUnix' => $next ? (int) $next : null,
            'nextRunLabel' => $next_label,
            'staleAfter24h' => $stale_after_24h,
        ]);
    }

    /**
     * AJAX: remove o transient de colunas da base (filtros) — apenas administradores.
     */
    public function handle_clear_base_cache()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }
        $table_name = sanitize_text_field($_POST['table_name'] ?? '');
        if ($table_name === '') {
            wp_send_json_error('Informe o nome da base (table_name).');
            return;
        }
        if (!$this->validate_table_name($table_name)) {
            wp_send_json_error('Nome de tabela inválido ou não encontrado.');
            return;
        }
        $cache_key = 'pc_cols_' . md5($table_name);
        delete_transient($cache_key);
        wp_send_json_success(['message' => 'Cache de colunas limpo.', 'cache_key' => $cache_key]);
    }

    public function init()
    {
        PC_Validador_Historico::ensure_table();
        PC_Validador_Historico::maybe_schedule_cron();
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
        add_rewrite_rule('^painel/validador/?$', 'index.php?pc_page=validador', 'top');
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
                'painel/validador' => 'validador',
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

        // Painel operacional: capability mínima read (exceto login, tratado acima)
        if ($page !== 'login' && !current_user_can('read')) {
            wp_die(
                esc_html__('Você não tem permissão para acessar o painel.', 'painel-campanhas'),
                esc_html__('Acesso negado', 'painel-campanhas'),
                ['response' => 403]
            );
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
            'logoutUrl' => wp_logout_url( home_url( '/' ) ),
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
            'logoutUrl' => wp_logout_url( home_url( '/' ) ),
            'canManageOptions' => current_user_can('manage_options'),
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

    public function handle_save_master_api_key()
    {
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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

    /**
     * Config do webhook Robbu (sem expor o segredo).
     */
    public function handle_get_robbu_webhook_config()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $configured = trim((string) get_option('acm_robbu_webhook_secret', '')) !== '';

        wp_send_json_success([
            'robbu_webhook_secret_configured' => $configured,
        ]);
    }

    /**
     * Salva acm_robbu_webhook_secret. Corpo vazio mantém o segredo atual; robbu_webhook_secret_clear=1 remove.
     */
    public function handle_save_robbu_webhook_secret()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $clear = !empty($_POST['robbu_webhook_secret_clear']);
        if ($clear) {
            update_option('acm_robbu_webhook_secret', '');
            wp_send_json_success([
                'message' => 'Segredo removido. O webhook ficará indisponível até você definir um novo segredo.',
            ]);
            return;
        }

        $raw = isset($_POST['robbu_webhook_secret']) ? trim((string) wp_unslash($_POST['robbu_webhook_secret'])) : '';

        if ($raw === '') {
            $existing = trim((string) get_option('acm_robbu_webhook_secret', ''));
            if ($existing === '') {
                wp_send_json_error('Defina um segredo forte. Sem ele, a rota do webhook permanece bloqueada.');
                return;
            }
            wp_send_json_success(['message' => 'Segredo existente mantido (campo em branco).']);
            return;
        }

        update_option('acm_robbu_webhook_secret', $raw);
        wp_send_json_success(['message' => 'Segredo do webhook Robbu salvo com sucesso.']);
    }

    /**
     * Extrai token de entrada para o webhook Robbu (ordem: X-Robbu-Token → Bearer → query token).
     */
    private function extract_robbu_webhook_incoming_token(WP_REST_Request $request)
    {
        $h = $request->get_header('X-Robbu-Token');
        if (!is_string($h) || $h === '') {
            $h = $request->get_header('x-robbu-token');
        }
        if (is_string($h) && $h !== '') {
            return trim($h);
        }

        $auth = $request->get_header('authorization');
        if (!is_string($auth) || $auth === '') {
            $auth = $request->get_header('Authorization');
        }
        if (is_string($auth) && $auth !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return trim($m[1]);
        }

        $q = $request->get_param('token');
        if (is_string($q) && $q !== '') {
            return trim($q);
        }

        if (!empty($_GET['token']) && is_string($_GET['token'])) {
            return trim(wp_unslash($_GET['token']));
        }

        return '';
    }

    /**
     * permission_callback REST: exige acm_robbu_webhook_secret configurado e token válido (hash_equals).
     */
    public function check_robbu_webhook_permission($request)
    {
        if (!($request instanceof WP_REST_Request)) {
            return new WP_Error('invalid_request', 'Requisição inválida.', ['status' => 400]);
        }

        $secret = trim((string) get_option('acm_robbu_webhook_secret', ''));
        if ($secret === '') {
            error_log('🔴 [Robbu Webhook] CRÍTICO: acm_robbu_webhook_secret não configurado — rota bloqueada (API Manager).');
            return new WP_Error(
                'robbu_webhook_misconfigured',
                'Webhook Robbu: configure o segredo em API Manager (Painel → API Manager → Webhook Robbu).',
                ['status' => 503]
            );
        }

        $incoming = $this->extract_robbu_webhook_incoming_token($request);
        if ($incoming === '') {
            return new WP_Error('robbu_webhook_unauthorized', 'Token do webhook ausente.', ['status' => 401]);
        }

        if (!hash_equals($secret, $incoming)) {
            error_log('🔴 [Robbu Webhook] Token inválido — IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return new WP_Error('robbu_webhook_unauthorized', 'Token do webhook inválido.', ['status' => 401]);
        }

        return true;
    }

    public function handle_save_static_credentials()
    {
        $this->pc_forbid_subscriber_ajax();
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
                'dashboard_password' => 'sanitize_text_field',
                'making_jwt_token' => 'sanitize_text_field',
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
            // Making Oficial: espelho em options dedicadas (get_option) para REST/AJAX
            if (isset($static_credentials['making_jwt_token'])) {
                update_option('making_jwt_token', (string) $static_credentials['making_jwt_token']);
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
        $this->pc_forbid_subscriber_ajax();
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
            'dashboard_password',
            'making_jwt_token',
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
            if ($key === 'url' || $key === 'api_url') {
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
            if ($key === 'url' || $key === 'api_url') {
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        $this->pc_forbid_subscriber_ajax();
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
        if (is_string($content) && strlen($content) >= 3 && substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $raw_lines = preg_split("/\r\n|\n|\r/", (string) $content);
        $lines = [];
        foreach ($raw_lines as $ln) {
            $ln = trim((string) $ln);
            if ($ln !== '') {
                $lines[] = $ln;
            }
        }

        if (empty($lines)) {
            wp_send_json_error('Arquivo CSV vazio');
        }

        $first_line = $lines[0];
        $comma_count = substr_count($first_line, ',');
        $semi_count = substr_count($first_line, ';');
        $delimiter = ($semi_count > $comma_count) ? ';' : ',';

        $parse_csv_line = function ($line) use ($delimiter) {
            return str_getcsv((string) $line, $delimiter);
        };

        $header_upper = strtoupper($first_line);
        $has_header = (strpos($header_upper, 'NOME') !== false
            || strpos($header_upper, 'TELEFONE') !== false
            || strpos($header_upper, 'CPF') !== false
            || strpos($header_upper, 'CELULAR') !== false
            || strpos($header_upper, 'IDCOB') !== false
            || strpos($header_upper, 'EXTRA_') !== false);

        if ($has_header) {
            $headers = array_map(function ($h) {
                $h = trim((string) $h, " \t\n\r\0\x0B\"'");
                return $h !== '' ? $h : 'col';
            }, $parse_csv_line($first_line));
            array_shift($lines);
        } else {
            $ncol = count($parse_csv_line($first_line));
            $headers = [];
            for ($i = 0; $i < $ncol; $i++) {
                $headers[] = 'col_' . $i;
            }
        }

        $seen_h = [];
        foreach ($headers as $i => $h) {
            $base = $h;
            $n = 2;
            while (isset($seen_h[$h])) {
                $h = $base . '_' . $n;
                ++$n;
            }
            $seen_h[$h] = true;
            $headers[$i] = $h;
        }

        $values = [];
        $rows_by_match = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $columns = $parse_csv_line($line);
            $columns = array_map(function ($c) {
                return trim((string) $c, " \t\n\r\"'");
            }, $columns);
            while (count($columns) < count($headers)) {
                $columns[] = '';
            }
            if (count($columns) > count($headers)) {
                $columns = array_slice($columns, 0, count($headers));
            }

            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $columns[$i] ?? '';
            }

            $match_raw = null;
            if ('cpf' === $match_field) {
                foreach ($columns as $col) {
                    $value = preg_replace('/[^0-9]/', '', (string) $col);
                    if (strlen($value) === 11) {
                        $match_raw = $value;
                        break;
                    }
                }
            } else {
                foreach ($columns as $col) {
                    $value = preg_replace('/[^0-9]/', '', (string) $col);
                    $length = strlen($value);
                    if ($length >= 10 && $length <= 13) {
                        $match_raw = $value;
                        break;
                    }
                }
            }

            if ($match_raw === null || $match_raw === '') {
                continue;
            }

            $match_key = ('cpf' === $match_field)
                ? $match_raw
                : $this->normalize_phone_cpf_campaign_key($match_raw);

            if ($match_key === '') {
                continue;
            }

            $values[] = $match_raw;
            $row['_match'] = $match_key;
            $rows_by_match[$match_key] = $row;
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
            'values' => $values,
            'headers' => $headers,
            'rows_by_match' => $rows_by_match,
        ];
        file_put_contents($temp_file, wp_json_encode($payload));

        wp_send_json_success([
            'temp_id' => $temp_id,
            'count' => count($values),
            'preview' => array_slice($values, 0, 5),
            'match_field' => $match_field,
            'headers' => $headers,
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
            $column_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_name,
                $column
            ));

            if ($column_exists > 0) {
                // Sem SELECT DISTINCT em views milionárias — valor digitado livremente no fluxo CPF
                $filters[$column] = [
                    'type' => $type,
                    'values' => [],
                    'free_text' => true,
                ];
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
        $sem_consulta = $this->parse_boolish_post_flag($_POST['sem_consulta'] ?? '');

        if ($sem_consulta && !empty($temp_payload['rows_by_match']) && is_array($temp_payload['rows_by_match'])) {
            $count = count($temp_payload['rows_by_match']);
        } else {
            // Busca registros usando o método que remove duplicatas
            global $wpdb;
            $records = $this->get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent);
            $count = count($records);
        }

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

    /**
     * Normaliza telefone para chave de cruzamento CSV ↔ registro (igual ao IN em build_cpf_match_condition).
     */
    private function normalize_phone_cpf_campaign_key($phone)
    {
        $val = preg_replace('/[^0-9]/', '', (string) $phone);
        if (strlen($val) > 11 && substr($val, 0, 2) === '55') {
            $val = substr($val, 2);
        }
        return $val;
    }

    /**
     * Injeta colunas do CSV (por CPF/telefone normalizado) nos registros vindos da base.
     *
     * @param array  $records       Lista de registros (referência get_cpf_records).
     * @param array  $rows_by_match mapa string normalizada => array coluna => valor
     * @param string $match_field   cpf|telefone
     */
    private function merge_csv_rows_into_cpf_records(array $records, array $rows_by_match, $match_field)
    {
        foreach ($records as &$rec) {
            if ($match_field === 'cpf') {
                $k = preg_replace('/[^0-9]/', '', (string) ($rec['cpf_cnpj'] ?? ''));
            } else {
                $k = $this->normalize_phone_cpf_campaign_key($rec['telefone'] ?? '');
            }
            if ($k === '' || !isset($rows_by_match[$k]) || !is_array($rows_by_match[$k])) {
                continue;
            }
            foreach ($rows_by_match[$k] as $colName => $cellVal) {
                if ($colName === '_match') {
                    continue;
                }
                $rec[(string) $colName] = $cellVal;
            }
        }
        unset($rec);

        return $records;
    }

    /**
     * Flag vinda do React (checkbox "sem consulta"): 1, true, on, yes.
     */
    private function parse_boolish_post_flag($raw): bool
    {
        if ($raw === true || $raw === 1 || $raw === '1') {
            return true;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Campanha por arquivo em modo "envio direto": sem SELECT na base — só linhas do CSV.
     */
    private function build_cpf_records_from_csv_only(array $rows_by_match, string $match_field): array
    {
        if (!in_array($match_field, ['cpf', 'telefone'], true)) {
            $match_field = 'cpf';
        }
        $records = [];
        foreach ($rows_by_match as $match_key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $nome = $this->pick_csv_row_nome_for_direct_send($row);
            if ($match_field === 'cpf') {
                $cpf_cnpj = preg_replace('/\D/', '', (string) $match_key);
                $telefone = $this->pick_csv_row_telefone_for_direct_send($row);
                if ($telefone === '') {
                    $telefone = $this->pick_csv_row_telefone_for_direct_send($row, true);
                }
            } else {
                $telefone = preg_replace('/\D/', '', (string) $match_key);
                if (strlen($telefone) > 11 && substr($telefone, 0, 2) === '55') {
                    $telefone = substr($telefone, 2);
                }
                $cpf_cnpj = $this->pick_csv_row_cpf_for_direct_send($row);
            }
            $rec = [
                'nome' => $nome,
                'telefone' => $telefone,
                'cpf_cnpj' => $cpf_cnpj,
                'idgis_ambiente' => 0,
                'id_carteira' => '',
                'idcob_contrato' => 0,
            ];
            $idcob_raw = $this->get_cpf_record_field_ci($row, 'idcob_contrato');
            if ($idcob_raw === '' || $idcob_raw === null) {
                $idcob_raw = $this->get_cpf_record_field_ci($row, 'contrato');
            }
            if ($idcob_raw !== '' && $idcob_raw !== null && is_numeric($idcob_raw)) {
                $rec['idcob_contrato'] = intval($idcob_raw);
            }
            foreach ($row as $col => $val) {
                if ($col === '_match') {
                    continue;
                }
                $rec[(string) $col] = $val;
            }
            $records[] = $rec;
        }

        return $records;
    }

    private function pick_csv_row_nome_for_direct_send(array $row): string
    {
        foreach (['nome', 'NOME', 'CLIENTE', 'cliente', 'name', 'NAME'] as $k) {
            $v = $this->get_cpf_record_field_ci($row, $k);
            if ($v !== '' && $v !== null) {
                return trim((string) $v);
            }
        }

        return '';
    }

    private function pick_csv_row_telefone_for_direct_send(array $row, bool $lenient = false): string
    {
        foreach (['telefone', 'TELEFONE', 'CELULAR', 'celular', 'fone', 'PHONE', 'phone'] as $k) {
            $v = $this->get_cpf_record_field_ci($row, $k);
            if ($v === '' || $v === null) {
                continue;
            }
            $d = preg_replace('/\D/', '', (string) $v);
            if ($lenient && strlen($d) >= 8) {
                if (strlen($d) > 11 && substr($d, 0, 2) === '55') {
                    $d = substr($d, 2);
                }

                return $d;
            }
            if (strlen($d) >= 10) {
                if (strlen($d) > 11 && substr($d, 0, 2) === '55') {
                    $d = substr($d, 2);
                }

                return $d;
            }
        }

        return '';
    }

    private function pick_csv_row_cpf_for_direct_send(array $row): string
    {
        foreach (['cpf', 'CPF', 'cpf_cnpj', 'CPF_CNPJ', 'documento', 'DOCUMENTO'] as $k) {
            $v = $this->get_cpf_record_field_ci($row, $k);
            if ($v === '' || $v === null) {
                continue;
            }
            $d = preg_replace('/\D/', '', (string) $v);
            if (strlen($d) >= 11) {
                return $d;
            }
        }

        return '';
    }

    /**
     * Colunas opcionais de rastreio (CSV + merge base) gravadas dentro do JSON em mensagem.
     */
    private function build_envios_tracking_data_from_record(array $record): array
    {
        $idcob = $this->get_cpf_record_field_ci($record, 'idcob_contrato');
        if ($idcob === '' || $idcob === null) {
            $idcob = $this->get_cpf_record_field_ci($record, 'contrato');
        }
        $out = [
            'idcob_contrato' => ($idcob !== '' && $idcob !== null) ? (string) $idcob : null,
        ];
        foreach (['extra_1', 'extra_2', 'extra_3', 'extra_4'] as $ek) {
            $v = $this->get_cpf_record_field_ci($record, $ek);
            $out[$ek] = ($v !== '' && $v !== null) ? (string) $v : null;
        }

        return $out;
    }

    /**
     * Injeta tracking_data no JSON salvo em envios_pendentes.mensagem (mantém estrutura existente).
     */
    private function embed_tracking_data_in_envios_mensagem(string $mensagem_para_armazenar, array $tracking_data): string
    {
        $has = false;
        foreach ($tracking_data as $v) {
            if ($v !== null && $v !== '') {
                $has = true;
                break;
            }
        }
        if (!$has) {
            return $mensagem_para_armazenar;
        }
        $decoded = json_decode($mensagem_para_armazenar, true);
        if (is_array($decoded)) {
            $decoded['tracking_data'] = $tracking_data;

            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return wp_json_encode([
            'original_message' => (string) $mensagem_para_armazenar,
            'tracking_data' => $tracking_data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Valor de um campo do registro (CSV merge + base) com chave case-insensitive.
     */
    private function get_cpf_record_field_ci(array $record, $column_name)
    {
        if ($column_name === null || $column_name === '') {
            return '';
        }
        $col = (string) $column_name;
        if (array_key_exists($col, $record)) {
            return $record[$col];
        }
        foreach ($record as $k => $v) {
            if (strcasecmp((string) $k, $col) === 0) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Resolve o mapeamento TECHIA (chave API → coluna CSV/base) para uma linha.
     *
     * @param array $variables_map Formato do React: { "documento": { "type":"field", "value":"CPF" }, ... }
     */
    private function resolve_techia_variables_for_row(array $record, $variables_map)
    {
        $out = [];
        if (!is_array($variables_map)) {
            return $out;
        }
        foreach ($variables_map as $tech_key => $mapping) {
            $key = (string) $tech_key;
            $col = '';
            if (is_array($mapping) && isset($mapping['value'])) {
                $col = (string) $mapping['value'];
            } elseif (is_string($mapping)) {
                $col = $mapping;
            }
            $raw = $this->get_cpf_record_field_ci($record, $col);
            $out[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
        }

        return $out;
    }

    /**
     * Monta a lista de variáveis por contato para GOSAC Oficial (HSM), alinhada a variableComponents da API.
     * Suporta variables_map no formato do React: { "Var1": { "type":"field", "value":"NOME" }, ... }.
     *
     * @param array $variable_components Itens com componentId + variable (ex.: "{{1}}" ou "Var1")
     * @return array<int, array{componentId:int, variable:string, value:string}>
     */
    private function resolve_gosac_contact_variables_for_row(array $record, $variables_map, $variable_components)
    {
        $out = [];
        if (!is_array($variables_map)) {
            $variables_map = [];
        }
        $vcs = is_array($variable_components) ? $variable_components : [];
        foreach ($vcs as $vc) {
            if (!is_array($vc)) {
                continue;
            }
            $raw_var = isset($vc['variable']) ? (string) $vc['variable'] : '';
            $comp_id = 0;
            if (isset($vc['componentId'])) {
                $comp_id = intval($vc['componentId']);
            } elseif (isset($vc['component_id'])) {
                $comp_id = intval($vc['component_id']);
            }
            $map_key = $raw_var;
            if ($raw_var !== '' && preg_match('/^\{\{(.+)\}\}$/u', trim($raw_var), $mm)) {
                $map_key = trim($mm[1]);
            }
            $field = null;
            if ($raw_var !== '' && array_key_exists($raw_var, $variables_map)) {
                $field = $variables_map[$raw_var];
            } elseif ($map_key !== '' && array_key_exists($map_key, $variables_map)) {
                $field = $variables_map[$map_key];
            } else {
                foreach ($variables_map as $vk => $vv) {
                    if (!is_string($vk)) {
                        continue;
                    }
                    if (strcasecmp($vk, $raw_var) === 0 || strcasecmp($vk, $map_key) === 0) {
                        $field = $vv;
                        break;
                    }
                }
            }
            $val = '';
            if (is_array($field) && isset($field['type'], $field['value'])) {
                if ($field['type'] === 'field') {
                    $val = (string) $this->get_cpf_record_field_ci($record, (string) $field['value']);
                } else {
                    $val = (string) ($field['value'] ?? '');
                }
            } elseif (is_string($field) && $field !== '') {
                $val = (string) $this->get_cpf_record_field_ci($record, $field);
            }
            $out[] = [
                'componentId' => $comp_id,
                'variable' => $raw_var !== '' ? $raw_var : $map_key,
                'value' => $val,
            ];
        }

        return $out;
    }

    /**
     * Resolve variables_map (NOAH Oficial) por linha do CSV/base — chaves ex.: header_1, body_2 ou 1.
     * Usado no JSON da coluna mensagem para o Nest mesclar com variables_map estático.
     */
    private function resolve_noah_variables_row_for_csv(array $record, array $variables_map): array
    {
        $out = [];
        foreach ($variables_map as $var_key => $field) {
            $k = is_string($var_key) ? $var_key : (string) $var_key;
            if ($k === '') {
                continue;
            }
            $val = '';
            if (is_array($field) && isset($field['type'], $field['value'])) {
                if ($field['type'] === 'field') {
                    $val = (string) $this->get_cpf_record_field_ci($record, (string) $field['value']);
                } else {
                    $val = (string) ($field['value'] ?? '');
                }
            } elseif (is_string($field) && $field !== '') {
                $val = (string) $this->get_cpf_record_field_ci($record, $field);
            }
            $out[$k] = $val;
        }

        return $out;
    }

    /**
     * Variáveis TECHIA a partir das colunas padrão da base (recorrência / sem variables_map no CSV).
     */
    private function build_default_techia_variables_from_base_record(array $record)
    {
        $ci = function ($key) use ($record) {
            return $this->get_cpf_record_field_ci($record, $key);
        };
        $doc_raw = (string) ($ci('cpf_cnpj') ?: $ci('CPF_CNPJ') ?: $ci('documento') ?: $ci('CPF'));
        $doc = preg_replace('/\D/', '', $doc_raw);

        return [
            'documento' => $doc,
            'nome' => (string) ($ci('nome') ?: $ci('NOME')),
            'contrato' => (string) ($ci('idcob_contrato') ?: $ci('IDCOB_CONTRATO') ?: $ci('contrato') ?: $ci('CONTRATO')),
            'valor' => (string) ($ci('valor') ?: $ci('VALOR')),
            'atraso' => (string) ($ci('atraso') ?: $ci('ATRASO') ?: $ci('dias_atraso') ?: $ci('DIAS_ATRASO')),
            'COD_DEPARA' => (string) ($ci('COD_DEPARA') ?: $ci('cod_depara')),
            'campanha_origem' => (string) ($ci('campanha_origem') ?: $ci('CAMPANHA_ORIGEM') ?: $ci('campanha')),
        ];
    }

    /**
     * Colunas extras no SELECT da recorrência (variables_map + TECHIA).
     */
    private function recurring_resolve_extra_select_columns($table_name, array $variables_map, $template_source)
    {
        global $wpdb;
        if (empty($table_name)) {
            return [];
        }
        $raw_cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        if (empty($raw_cols)) {
            return [];
        }
        $upper_to_actual = [];
        foreach ($raw_cols as $cn) {
            $upper_to_actual[strtoupper(str_replace('`', '', $cn))] = str_replace('`', '', $cn);
        }
        $want = [];
        if (!empty($variables_map) && is_array($variables_map)) {
            foreach ($variables_map as $field) {
                if (is_array($field) && isset($field['type'], $field['value'])
                    && $field['type'] === 'field' && is_string($field['value']) && $field['value'] !== '') {
                    $want[] = strtoupper($field['value']);
                } elseif (is_string($field) && $field !== '') {
                    $want[] = strtoupper($field);
                }
            }
        }
        if ($template_source === 'techia_discador') {
            foreach (['VALOR', 'ATRASO', 'DIAS_ATRASO', 'COD_DEPARA', 'CAMPANHA_ORIGEM', 'SALDO'] as $d) {
                $want[] = $d;
            }
        }
        $out = [];
        foreach (array_unique($want) as $U) {
            if (isset($upper_to_actual[$U])) {
                $out[] = $upper_to_actual[$U];
            }
        }

        return $out;
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
            if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }

            $this->maybe_add_envios_cancel_columns();

            global $wpdb;
            $table_name = sanitize_text_field($_POST['table_name'] ?? '');
            $temp_id = sanitize_text_field($_POST['temp_id'] ?? '');
            $sem_consulta = $this->parse_boolish_post_flag($_POST['sem_consulta'] ?? '');
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
            $variables_map_json = isset($_POST['variables_map']) ? wp_unslash((string) $_POST['variables_map']) : '{}';
            $variables_map = json_decode($variables_map_json, true);
            if (!is_array($variables_map)) {
                $variables_map = [];
            }

            $noah_template_name_post = sanitize_text_field(wp_unslash($_POST['noah_template_name'] ?? ''));
            $noah_template_data_json = isset($_POST['noah_template_data']) ? wp_unslash((string) $_POST['noah_template_data']) : '';
            $noah_template_data = json_decode($noah_template_data_json, true);
            if (!is_array($noah_template_data)) {
                $noah_template_data = [];
            }
            $noah_components_for_file = [];
            if (!empty($noah_template_data['components']) && is_array($noah_template_data['components'])) {
                $noah_components_for_file = $noah_template_data['components'];
            }

            $carteira = sanitize_text_field($_POST['carteira'] ?? '');
            $nome_campanha_post = sanitize_text_field(wp_unslash($_POST['nome_campanha'] ?? ''));
            $nome_carteira_persist = sanitize_text_field(wp_unslash($_POST['nome_carteira'] ?? ''));
            if ($nome_carteira_persist === '' && !empty($carteira)) {
                $nome_carteira_persist = $this->get_carteira_nome_by_id((int) $carteira);
            }
            $making_team_id_cpf = intval($_POST['making_team_id'] ?? 0);
            $making_cost_center_id_cpf = intval($_POST['making_cost_center_id'] ?? 0);

            $include_baits = isset($_POST['include_baits']) ? intval($_POST['include_baits']) : 0;
            $test_only = isset($_POST['test_only']) ? intval($_POST['test_only']) : 0;
            if ($test_only && !$include_baits) {
                wp_send_json_error('Disparo de teste (apenas iscas) requer marcar "Incluir iscas".');
            }
            $baits_only_test = ($include_baits && $test_only);

            // id_carteira da carteira selecionada (herança para todos os registros da fila)
            $campaign_id_carteira = '';
            if (!empty($carteira)) {
                $campaign_id_carteira = $this->resolve_id_carteira_from_carteira_id($carteira);
            }

            $only_sf = $this->is_salesforce_only_providers($providers_config);
            $is_techia_discador = ($template_source === 'techia_discador' && is_array($variables_map) && count($variables_map) > 0);
            $is_template_ok = $only_sf
                || $is_techia_discador
                || ($template_source === 'local' && $template_id > 0)
                || (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code))
                || (($template_source === 'gosac_oficial' || $template_source === 'noah_oficial' || $template_source === 'robbu_oficial' || $template_source === 'making_oficial') && !empty($template_code));

            $temp_id_required = !$baits_only_test;
            $table_required = !$sem_consulta;
            if (($table_required && empty($table_name)) || ($temp_id_required && empty($temp_id)) || !$is_template_ok || empty($providers_config['providers'])) {
                error_log('🔴 [handle_create_cpf_campaign] Dados incompletos: table_name=' . $table_name . ', temp_id=' . $temp_id . ', sem_consulta=' . ($sem_consulta ? '1' : '0') . ', template_source=' . $template_source . ', template_code=' . $template_code . ', template_id=' . $template_id . ', providers_count=' . (is_array($providers_config['providers'] ?? null) ? count($providers_config['providers']) : 0));
                wp_send_json_error('Dados incompletos');
            }
            if ($template_source === 'making_oficial' && ($making_team_id_cpf <= 0 || $making_cost_center_id_cpf <= 0)) {
                wp_send_json_error('Making Oficial: selecione Equipe e Centro de Custo.');
            }

            // Carrega template
            if ($only_sf) {
                $message_content = 'Salesforce Marketing Cloud: conteúdo definido na automação.';
            } elseif ($template_source === 'techia_discador') {
                $message_content = 'TECHIA Discador: mailing sem template; variáveis por linha no JSON da mensagem.';
            } elseif ($template_source === 'local') {
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
            } elseif ($template_source === 'making_oficial') {
                $message_content = 'Template Making Oficial: ' . $template_code;
            } else {
                // Templates da Ótima usam template_code
                $message_content = 'Template da Ótima: ' . $template_code;
            }

            // Carrega arquivo temporário (dispensável em disparo de teste só com iscas, sem CSV)
            $uploads_dir = wp_upload_dir()['basedir'] . '/cpf-campaigns/';
            $temp_payload = [];
            $values = [];
            $temp_file = '';
            if ($baits_only_test && $temp_id === '') {
                // Sem upload: fila apenas com iscas
            } else {
                $temp_file = $uploads_dir . $temp_id . '.json';
                if (!file_exists($temp_file)) {
                    wp_send_json_error('Arquivo temporário não encontrado');
                }
                $temp_payload = json_decode(file_get_contents($temp_file), true);
                if (!is_array($temp_payload)) {
                    $temp_payload = [];
                }
                $values = $temp_payload['values'] ?? [];
            }

            if (!empty($temp_payload['match_field']) && in_array($temp_payload['match_field'], ['cpf', 'telefone'], true)) {
                $match_field = sanitize_text_field((string) $temp_payload['match_field']);
            } elseif (!in_array($match_field, ['cpf', 'telefone'], true)) {
                $match_field = 'cpf';
            }

            $show_already_sent = isset($_POST['show_already_sent']) ? intval($_POST['show_already_sent']) : 0;

            $rows_by_match = [];
            if (is_array($temp_payload) && !empty($temp_payload['rows_by_match']) && is_array($temp_payload['rows_by_match'])) {
                $rows_by_match = $temp_payload['rows_by_match'];
            }

            if ($sem_consulta && !empty($rows_by_match)) {
                $records = $this->build_cpf_records_from_csv_only($rows_by_match, $match_field);
            } else {
                // Busca registros na base e enriquece com colunas do CSV
                $records = $this->get_cpf_records($wpdb, $table_name, $values, $filters, $match_field, $show_already_sent);
                if (!empty($rows_by_match)) {
                    $records = $this->merge_csv_rows_into_cpf_records($records, $rows_by_match, $match_field);
                }
            }

            if (empty($records) && !($include_baits && $test_only)) {
                wp_send_json_error('Nenhum registro encontrado');
            }

            // Disparo de teste: ignora a base do arquivo/BD e usa só iscas (igual Nova Campanha)
            if ($include_baits && $test_only) {
                $records = [];
                error_log('🧪 [CPF Campaign] Disparo de teste: ignorando base; apenas iscas selecionadas.');
            }

            // 🎣 ISCAS - Adiciona iscas ativas se solicitado
            $baits_added = 0;
            $bait_ids_filter_cpf = null;
            if ($include_baits) {
                $bait_ids_filter_cpf = $this->parse_bait_ids_filter_from_post();
            }

            if ($include_baits) {
                $table_iscas = $wpdb->prefix . 'cm_baits';
                $iscas = $wpdb->get_results(
                    "SELECT * FROM $table_iscas WHERE ativo = 1",
                    ARRAY_A
                );
                $iscas = $this->filter_baits_rows_by_ids($iscas ? $iscas : [], $bait_ids_filter_cpf);

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

            if (empty($records)) {
                wp_send_json_error(
                    ($include_baits && $test_only)
                        ? 'Nenhuma isca ativa encontrada para o disparo de teste.'
                        : 'Nenhum registro encontrado'
                );
            }

            // Distribui entre provedores
            $distributed_records = $this->distribute_records($records, $providers_config);

            // Insere na tabela envios_pendentes
            $envios_table = $wpdb->prefix . 'envios_pendentes';
            $current_user_id = get_current_user_id();
            $agendamento_base_id = date('YmdHis', current_time('timestamp'));
            $total_inserted = 0;
            $nome_campanha_row = $nome_campanha_post !== ''
                ? $nome_campanha_post
                : ('Campanha por arquivo ' . $agendamento_base_id);

            if (empty($wpdb->get_results("SHOW COLUMNS FROM {$envios_table} LIKE 'carteira_id'"))) {
                $wpdb->query("ALTER TABLE {$envios_table} ADD COLUMN carteira_id bigint(20) DEFAULT NULL");
            }

            foreach ($distributed_records as $provider_data) {
                $provider = $provider_data['provider'];
                $provider_records = $provider_data['records'];
                $prefix = $this->resolve_envios_agendamento_id_prefix($provider, $template_source);
                $agendamento_id = $prefix . $agendamento_base_id;

                foreach ($provider_records as $record) {
                    $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
                    if (strlen($telefone) > 11 && substr($telefone, 0, 2) === '55') {
                        $telefone = substr($telefone, 2);
                    }

                    // Para templates Ótima/GOSAC/NOAH/Salesforce/TECHIA, não substitui placeholders (será resolvido no microserviço / SFMC)
                    if (in_array($template_source, ['otima_wpp', 'otima_rcs', 'gosac_oficial', 'noah_oficial', 'robbu_oficial', 'making_oficial', 'techia_discador']) || $only_sf) {
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
                    $carteira_id_insert = !empty($carteira) && $this->id_carteira_matches_campaign_selection($id_carteira, $campaign_id_carteira) ? intval($carteira) : 0;

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
                        $gosac_vc_list = is_array($gosac_variable_components) ? $gosac_variable_components : [];
                        $contact_vars = $this->resolve_gosac_contact_variables_for_row($record, $variables_map, $gosac_vc_list);
                        $gosac_body_parameters = [];
                        foreach ($contact_vars as $cv) {
                            $gosac_body_parameters[] = ['type' => 'text', 'text' => (string) ($cv['value'] ?? '')];
                        }
                        $gosac_components = [];
                        if (!empty($gosac_body_parameters)) {
                            $gosac_components[] = ['type' => 'body', 'parameters' => $gosac_body_parameters];
                        }
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'gosac_oficial',
                            'nome_campanha' => $nome_campanha_row,
                            'id' => $gosac_template_id,
                            'connectionId' => $gosac_connection_id,
                            'variables_map' => $variables_map,
                            'variableComponents' => $gosac_vc_list,
                            'contact_variables' => $contact_vars,
                            'components' => $gosac_components,
                            'original_message' => $mensagem_final,
                        ]);
                    } elseif ($template_source === 'noah_oficial' && !empty($template_code)) {
                        $noah_display_name = $noah_template_name_post !== '' ? $noah_template_name_post : $template_code;
                        $noah_flat_row = [
                            'nome' => (string) ($record['nome'] ?? ''),
                            'telefone' => (string) ($record['telefone'] ?? ''),
                            'cpf_cnpj' => (string) ($record['cpf_cnpj'] ?? ''),
                            'id_carteira' => (string) $id_carteira,
                            'idcob_contrato' => (string) ($record['idcob_contrato'] ?? ''),
                            'idgis_ambiente' => (string) (int) ($record['idgis_ambiente'] ?? 0),
                        ];
                        $noah_vars_row = count($variables_map) > 0
                            ? $this->resolve_noah_variables_row_for_csv($record, $variables_map)
                            : [];
                        $row_vars_merged = array_merge($noah_flat_row, $noah_vars_row);

                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'noah_oficial',
                            'channelId' => $noah_channel_id,
                            'templateId' => $noah_template_id,
                            'templateName' => $noah_display_name,
                            'language' => $noah_language,
                            'original_message' => $mensagem_final,
                            'variables_map' => (object) $variables_map,
                            'variables' => (object) $row_vars_merged,
                            'components' => $noah_components_for_file,
                            'templateData' => [
                                'components' => $noah_components_for_file,
                                'buttons' => $noah_template_data['buttons'] ?? null,
                                'textHeader' => $noah_template_data['textHeader'] ?? null,
                                'textBody' => $noah_template_data['textBody'] ?? null,
                                'textFooter' => $noah_template_data['textFooter'] ?? null,
                            ],
                            'buttons' => $noah_template_data['buttons'] ?? null,
                            'textHeader' => $noah_template_data['textHeader'] ?? null,
                            'textBody' => $noah_template_data['textBody'] ?? null,
                            'textFooter' => $noah_template_data['textFooter'] ?? null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    } elseif ($template_source === 'making_oficial' && !empty($template_code)) {
                        $making_vars = $this->resolve_noah_variables_row_for_csv($record, $variables_map);
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'making_oficial',
                            'send_meta_template' => $template_code,
                            'template_code' => $template_code,
                            'nome_campanha' => $nome_campanha_row,
                            'making_team_id' => $making_team_id_cpf,
                            'making_cost_center_id' => $making_cost_center_id_cpf,
                            'variables_map' => $variables_map,
                            'variables' => $making_vars,
                            'original_message' => $mensagem_final,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } elseif ($only_sf) {
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'salesforce',
                            'note' => 'Conteúdo definido na automação Salesforce/MC.',
                        ]);
                    } elseif ($template_source === 'techia_discador') {
                        $techia_vars = $this->resolve_techia_variables_for_row($record, $variables_map);
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'techia_discador',
                            'template_code' => '',
                            'variables_map' => $variables_map,
                            'variables' => $techia_vars,
                            'original_message' => $mensagem_final,
                        ]);
                    }

                    $tracking_data = $this->build_envios_tracking_data_from_record($record);
                    $mensagem_para_armazenar = $this->embed_tracking_data_in_envios_mensagem(
                        (string) $mensagem_para_armazenar,
                        $tracking_data
                    );

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
                        'nome_campanha' => $nome_campanha_row,
                        'nome_carteira' => $nome_carteira_persist,
                        'status' => 'pendente_aprovacao',
                        'current_user_id' => $current_user_id,
                        'valido' => 1,
                        'data_cadastro' => current_time('mysql')
                    ];

                    $wpdb->insert($envios_table, $insert_data, ['%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);
                    $total_inserted++;
                }
            }

            // Remove arquivo temporário
            if ($temp_file !== '' && is_string($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }

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

    /**
     * Fornecedor TECHIA / TECH_IA (discador) — prefixo de agendamento deve ser T no NestJS.
     */
    private function is_techia_provider($provider)
    {
        if (!is_string($provider) || $provider === '') {
            return false;
        }
        $norm = strtoupper(str_replace('_', '', $provider));

        return $norm === 'TECHIA';
    }

    /**
     * Primeira letra do `agendamento_id` em envios_pendentes — deve bater com identifyProvider no NestJS.
     * Não usar substr($provedor,0,1): OTIMA_WPP começa com "O" mas o canal WhatsApp Ótima é W (WHATSAPP_OTIMA).
     *
     * @param string $provider_slug Slug do POST (ex.: OTIMA_WPP, GOSAC_OFICIAL).
     * @param string $template_source Fallback quando o slug veio inconsistente (ex.: otima_wpp).
     */
    private function resolve_envios_agendamento_id_prefix($provider_slug, $template_source = '')
    {
        $p = strtoupper(trim(preg_replace('/[\s\-]+/', '_', (string) $provider_slug)));
        $p = preg_replace('/_+/', '_', $p);
        $ts = strtolower(trim((string) $template_source));

        // WhatsApp (Ótima WPP, Meta/WABA se unificado no futuro)
        if (in_array($p, ['OTIMA_WPP', 'OTIMAWPP', 'WHATSAPP_OTIMA', 'WPP_OTIMA', 'META_OFICIAL', 'META_WHATSAPP', 'WABA'], true)
            || $ts === 'otima_wpp') {
            return 'W';
        }

        // RCS Ótima — Nest: prefixo O → RCS_OTIMA
        if (in_array($p, ['OTIMA_RCS', 'OTIMARCS', 'RCS_OTIMA'], true) || $ts === 'otima_rcs') {
            return 'O';
        }

        if ($p === 'GOSAC_OFICIAL' || $ts === 'gosac_oficial') {
            return 'F';
        }
        if ($p === 'NOAH_OFICIAL' || $ts === 'noah_oficial' || $ts === 'noah') {
            return 'H';
        }
        if ($p === 'ROBBU_OFICIAL' || $ts === 'robbu_oficial') {
            return 'B';
        }
        if ($p === 'MAKING_OFICIAL' || $ts === 'making_oficial') {
            return 'M';
        }
        if ($p === 'CDA_RCS' || $ts === 'cda_rcs') {
            return 'R';
        }
        if ($p === 'CDA' && $ts !== 'otima_wpp') {
            return 'C';
        }

        if ($this->is_techia_provider($p) || $ts === 'techia_discador') {
            return 'T';
        }

        if (in_array($p, ['SALESFORCE', 'SF', 'SFMC'], true) || $ts === 'salesforce') {
            return 'S';
        }

        // Legado
        if ($p === 'GOSAC') {
            return 'G';
        }
        if ($p === 'NOAH') {
            return 'N';
        }

        error_log('⚠️ [resolve_envios_agendamento_id_prefix] Provedor não mapeado; fallback 1ª letra: slug=' . $p . ' template_source=' . $ts);
        $fallback = strtoupper(substr($p, 0, 1));

        return $fallback !== '' ? $fallback : 'X';
    }

    /**
     * Bloco Making por carteira: acm_provider_credentials['making_oficial'][id_carteira] (phone_number_id, url opcional).
     */
    private function resolve_making_oficial_acm_block(array $acm_all, $env_id): ?array
    {
        if (empty($acm_all['making_oficial']) || !is_array($acm_all['making_oficial'])) {
            return null;
        }
        $by_env = $acm_all['making_oficial'];
        $key_str = trim((string) $env_id);
        $key_int = is_numeric($env_id) ? (string) intval($env_id) : $key_str;

        if ($key_str !== '' && isset($by_env[$key_str]) && is_array($by_env[$key_str])) {
            return $by_env[$key_str];
        }
        if ($key_int !== $key_str && isset($by_env[$key_int]) && is_array($by_env[$key_int])) {
            return $by_env[$key_int];
        }

        return null;
    }

    /**
     * Credenciais TECHIA gravadas pelo API Manager (acm_provider_credentials → techia → env_id).
     */
    private function resolve_techia_acm_block(array $acm_all, $env_id)
    {
        if (empty($acm_all['techia']) || !is_array($acm_all['techia'])) {
            return null;
        }
        $by_env = $acm_all['techia'];
        $key_str = (string) $env_id;
        $key_int = is_numeric($env_id) ? (string) intval($env_id) : $key_str;

        if (isset($by_env[$key_str]) && is_array($by_env[$key_str])) {
            return $by_env[$key_str];
        }
        if ($key_int !== $key_str && isset($by_env[$key_int]) && is_array($by_env[$key_int])) {
            return $by_env[$key_int];
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
        $variables_map_stored = null;
        if (isset($_POST['variables_map'])) {
            $variables_map_json = stripslashes((string) ($_POST['variables_map'] ?? '{}'));
            $variables_map_dec = json_decode($variables_map_json, true);
            $variables_map_stored = (is_array($variables_map_dec) && count($variables_map_dec) > 0)
                ? wp_json_encode($variables_map_dec, JSON_UNESCAPED_UNICODE)
                : null;
        }

        $noah_channel_id = intval($_POST['noah_channel_id'] ?? 0);
        $noah_template_id = intval($_POST['noah_template_id'] ?? 0);
        $noah_language = sanitize_text_field($_POST['noah_language'] ?? 'pt_BR');
        $gosac_template_id = intval($_POST['gosac_template_id'] ?? 0);
        $gosac_connection_id = intval($_POST['gosac_connection_id'] ?? 0);
        $gosac_vc_raw = isset($_POST['gosac_variable_components']) ? json_decode(stripslashes($_POST['gosac_variable_components']), true) : [];
        $gosac_variable_components = is_array($gosac_vc_raw) ? $gosac_vc_raw : [];

        $template_meta_arr = [];
        if ($template_source === 'noah_oficial' || $template_source === 'noah') {
            $template_meta_arr['noah_channel_id'] = $noah_channel_id;
            $template_meta_arr['noah_template_id'] = $noah_template_id;
            $template_meta_arr['noah_language'] = $noah_language;
            $noah_td_save_raw = isset($_POST['noah_template_data']) ? wp_unslash((string) $_POST['noah_template_data']) : '';
            $noah_td_save = json_decode($noah_td_save_raw, true);
            if (is_array($noah_td_save) && count($noah_td_save) > 0) {
                $template_meta_arr['noah_template_data'] = $noah_td_save;
            }
            $noah_nm_save = sanitize_text_field(wp_unslash($_POST['noah_template_name'] ?? ''));
            if ($noah_nm_save !== '') {
                $template_meta_arr['noah_template_name'] = $noah_nm_save;
            }
        }
        if ($template_source === 'gosac_oficial') {
            $template_meta_arr['gosac_template_id'] = $gosac_template_id;
            $template_meta_arr['gosac_connection_id'] = $gosac_connection_id;
            $template_meta_arr['gosac_variable_components'] = $gosac_variable_components;
        }
        if ($template_source === 'robbu_oficial') {
            $template_meta_arr['robbu_channel'] = intval($_POST['robbu_channel'] ?? 3);
        }
        if ($template_source === 'making_oficial') {
            $template_meta_arr['making_team_id'] = intval($_POST['making_team_id'] ?? 0);
            $template_meta_arr['making_cost_center_id'] = intval($_POST['making_cost_center_id'] ?? 0);
        }
        $template_meta_json = !empty($template_meta_arr)
            ? wp_json_encode($template_meta_arr, JSON_UNESCAPED_UNICODE)
            : null;

        // Validation based on source
        if (empty($nome_campanha) || empty($table_name)) {
            wp_send_json_error('Dados incompletos para criar filtro salvo.');
        }

        $providers_cfg_pre = json_decode($providers_config_json, true);
        $only_sf_recurring = is_array($providers_cfg_pre) && $this->is_salesforce_only_providers($providers_cfg_pre);

        if ($only_sf_recurring) {
            // Sem template local obrigatório — conteúdo no Salesforce/MC
        } elseif ($template_source === 'techia_discador') {
            // Discador TECHIA: sem post de template local nem template_code
        } elseif ($template_source === 'local' && $template_id <= 0) {
            wp_send_json_error('Template_id inválido para template local.');
        } elseif (in_array($template_source, ['otima_wpp', 'otima_rcs', 'gosac_oficial', 'noah_oficial', 'noah', 'robbu_oficial', 'making_oficial'], true) && $template_code === '') {
            wp_send_json_error('Informe o template (código/nome) para este fornecedor.');
        }
        if ($template_source === 'making_oficial' && $template_code !== ''
            && (intval($_POST['making_team_id'] ?? 0) <= 0 || intval($_POST['making_cost_center_id'] ?? 0) <= 0)) {
            wp_send_json_error('Making Oficial: selecione Equipe e Centro de Custo.');
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
        if (empty($wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'variables_map'"))) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN variables_map longtext NULL AFTER carteira, ADD COLUMN template_meta longtext NULL AFTER variables_map");
        }

        if ($template_source === 'techia_discador') {
            $template_id = 0;
            $template_code = '';
        }

        // Adiciona exclusão ao config; bait_ids vêm no JSON do front (incl. edição em Filtros Salvos)
        $config_array = json_decode($providers_config_json, true);
        if (!is_array($config_array)) {
            $config_array = [];
        }
        $config_array['exclude_recent_phones'] = $exclude_recent_phones;
        $config_array['exclude_recent_hours'] = $exclude_recent_hours;
        if (!$include_baits) {
            unset($config_array['bait_ids']);
        }
        $providers_config_json = json_encode($config_array, JSON_UNESCAPED_UNICODE);

        $recurring_id = intval($_POST['id'] ?? 0);
        $current_user_id = get_current_user_id();

        $existing_row = null;
        if ($recurring_id > 0) {
            $existing_row = $wpdb->get_row($wpdb->prepare(
                "SELECT variables_map, template_meta FROM {$table} WHERE id = %d AND criado_por = %d",
                $recurring_id,
                $current_user_id
            ), ARRAY_A);
        }
        if ($recurring_id > 0 && $existing_row && !isset($_POST['variables_map'])) {
            $variables_map_stored = !empty($existing_row['variables_map']) ? $existing_row['variables_map'] : null;
        }
        if ($recurring_id > 0 && $existing_row && empty($template_meta_arr) && !empty($existing_row['template_meta'])) {
            $template_meta_json = $existing_row['template_meta'];
        }

        $row_data = [
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
            'variables_map' => $variables_map_stored,
            'template_meta' => $template_meta_json,
            'record_limit' => $record_limit,
            'throttling_type' => $throttling_type,
            'throttling_config' => $throttling_config_json,
            'include_baits' => $include_baits,
        ];
        $row_formats = ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d'];

        if ($recurring_id > 0) {
            $owner_ok = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id = %d AND criado_por = %d",
                $recurring_id,
                $current_user_id
            ));
            if (intval($owner_ok) !== 1) {
                wp_send_json_error('Filtro não encontrado ou sem permissão para editar.');
            }
            $result = $wpdb->update(
                $table,
                $row_data,
                ['id' => $recurring_id, 'criado_por' => $current_user_id],
                $row_formats,
                ['%d', '%d']
            );
            if ($result === false) {
                wp_send_json_error('Erro ao atualizar filtro: ' . $wpdb->last_error);
            }
            wp_send_json_success(['message' => 'Filtro atualizado com sucesso!', 'id' => $recurring_id]);
        }

        $row_data['ativo'] = 1;
        $row_data['criado_por'] = $current_user_id;
        $insert_formats = array_merge($row_formats, ['%d', '%d']);

        $result = $wpdb->insert(
            $table,
            $row_data,
            $insert_formats
        );

        if ($result === false) {
            wp_send_json_error('Erro ao salvar filtro: ' . $wpdb->last_error);
        }

        wp_send_json_success(['message' => 'Filtro salvo com sucesso!', 'id' => (int) $wpdb->insert_id]);
    }

    /**
     * True quando todos os provedores da campanha são Salesforce.
     */
    private function is_salesforce_only_providers($providers_config)
    {
        $providers = $providers_config['providers'] ?? null;
        if (!is_array($providers) || empty($providers)) {
            return false;
        }
        foreach ($providers as $p) {
            if (is_array($p)) {
                $p = $p['id'] ?? $p['name'] ?? '';
            }
            if (strtoupper((string) $p) !== 'SALESFORCE') {
                return false;
            }
        }
        return true;
    }

    /**
     * IDs de iscas enviados pelo front (JSON). null = legado (todas as iscas ativas).
     * array vazio = nenhuma isca.
     */
    private function parse_bait_ids_filter_from_post()
    {
        if (!isset($_POST['bait_ids'])) {
            return null;
        }
        $raw = wp_unslash($_POST['bait_ids']);
        $dec = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($dec)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $dec))));
    }

    private function filter_baits_rows_by_ids($baits_rows, $bait_ids_filter)
    {
        if ($bait_ids_filter === null) {
            return $baits_rows;
        }
        if (empty($bait_ids_filter)) {
            return [];
        }
        $allowed = array_flip($bait_ids_filter);
        $out = [];
        foreach ($baits_rows as $row) {
            $id = isset($row['id']) ? intval($row['id']) : 0;
            if ($id > 0 && isset($allowed[$id])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function handle_schedule_campaign()
    {
        // Bases muito grandes (100k–300k+ linhas): tempo ilimitado e mais RAM (o host pode ignorar ini_set)
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '2048M');

        // Captura erros fatais para retornar JSON em vez de 500
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo wp_json_encode([
                        'success' => false,
                        'data' => 'Erro fatal PHP: ' . $err['message'] . ' em ' . basename($err['file']) . ':' . $err['line'],
                    ]);
                }
            }
        });

        check_ajax_referer('campaign-manager-nonce', 'nonce');
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
        global $wpdb;

        try {
        $this->maybe_add_envios_cancel_columns();
        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) { wp_send_json_error('Tabela inválida.'); return; }
        $filters_json = stripslashes($_POST['filters'] ?? '[]');
        $filters = json_decode($filters_json, true);
        $filters = is_array($filters) ? $filters : [];
        $providers_config_json = stripslashes($_POST['providers_config'] ?? '{}');
        $providers_config = json_decode($providers_config_json, true);
        $providers_config = is_array($providers_config) ? $providers_config : [];
        $template_id = intval($_POST['template_id'] ?? 0);
        $template_code = sanitize_text_field($_POST['template_code'] ?? '');
        $template_source = sanitize_text_field($_POST['template_source'] ?? 'local');
        $broker_code = sanitize_text_field($_POST['broker_code'] ?? '');
        $customer_code = sanitize_text_field($_POST['customer_code'] ?? '');
        $variables_map_json = stripslashes($_POST['variables_map'] ?? '{}');
        $variables_map_raw = is_string($variables_map_json) ? json_decode($variables_map_json, true) : [];
        $variables_map = is_array($variables_map_raw) ? $variables_map_raw : [];
        $record_limit = intval($_POST['record_limit'] ?? 0);
        $exclude_recent_phones = isset($_POST['exclude_recent_phones']) ? intval($_POST['exclude_recent_phones']) : 1;
        $exclude_recent_hours = isset($_POST['exclude_recent_hours']) ? intval($_POST['exclude_recent_hours']) : 48;
        $midia_campanha = esc_url_raw($_POST['midia_campanha'] ?? '');
        $carteira = sanitize_text_field($_POST['carteira'] ?? '');
        $nome_campanha_camp = sanitize_text_field(wp_unslash($_POST['nome_campanha'] ?? ''));
        $nome_carteira_persist = sanitize_text_field(wp_unslash($_POST['nome_carteira'] ?? ''));
        if ($nome_carteira_persist === '' && !empty($carteira)) {
            $nome_carteira_persist = $this->get_carteira_nome_by_id((int) $carteira);
        }

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
            'exclude_recent_phones' => $exclude_recent_phones,
            'nome_campanha' => $nome_campanha_camp,
        ]));

        if (empty($table_name) || empty($providers_config)) {
            error_log('❌ Dados inválidos: table_name=' . $table_name . ', providers=' . json_encode($providers_config));
            wp_send_json_error('Dados da campanha inválidos.');
        }

        $only_salesforce = $this->is_salesforce_only_providers($providers_config);

        // Valida template baseado na origem
        $message_content = '';
        $template_info = [];
        if ($only_salesforce) {
            $message_content = 'Salesforce Marketing Cloud: conteúdo definido na automação.';
            $template_info = ['source' => 'salesforce'];
        } elseif ($template_source === 'local' && $template_id > 0) {
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
            $gosac_vc_raw = isset($_POST['gosac_variable_components']) ? json_decode(stripslashes($_POST['gosac_variable_components']), true) : [];
            $template_info = [
                'template_code' => $template_code,
                'source' => 'gosac_oficial',
                'template_id' => $gosac_tid,
                'connection_id' => $gosac_cid,
                'variable_components' => is_array($gosac_vc_raw) ? $gosac_vc_raw : [],
            ];
        } elseif ($template_source === 'noah_oficial' && !empty($template_code)) {
            $noah_channel_post = intval($_POST['noah_channel_id'] ?? 0);
            if ($noah_channel_post <= 0) {
                wp_send_json_error('NOAH Oficial: selecione o remetente (linha de disparo / channelId).');
            }
            $noah_snap_json = isset($_POST['noah_template_data']) ? wp_unslash((string) $_POST['noah_template_data']) : '';
            $noah_snap = json_decode($noah_snap_json, true);
            if (!is_array($noah_snap)) {
                $noah_snap = [];
            }
            $message_content = 'Template NOAH Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'noah_oficial',
                'channel_id' => $noah_channel_post,
                'template_id' => intval($_POST['noah_template_id'] ?? 0),
                'template_name' => $template_code,
                'language' => sanitize_text_field($_POST['noah_language'] ?? 'pt_BR'),
                'noah_template_data' => $noah_snap,
                'noah_template_name_post' => sanitize_text_field(wp_unslash($_POST['noah_template_name'] ?? '')),
            ];
        } elseif ($template_source === 'robbu_oficial' && !empty($template_code)) {
            $message_content = 'Template Robbu Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'robbu_oficial',
                'template_name' => $template_code,
                'channel' => 3,
            ];
        } elseif ($template_source === 'making_oficial' && !empty($template_code)) {
            $making_team_id = intval($_POST['making_team_id'] ?? 0);
            $making_cost_center_id = intval($_POST['making_cost_center_id'] ?? 0);
            if ($making_team_id <= 0 || $making_cost_center_id <= 0) {
                wp_send_json_error('Making Oficial: selecione a Equipe e o Centro de Custo.');
            }
            $message_content = 'Template Making Oficial: ' . $template_code;
            $template_info = [
                'template_code' => $template_code,
                'source' => 'making_oficial',
                'send_meta_template' => $template_code,
                'making_team_id' => $making_team_id,
                'making_cost_center_id' => $making_cost_center_id,
            ];
        } else {
            wp_send_json_error('Template inválido. Informe template_id para templates locais ou template_code para templates externos (Ótima, GOSAC Oficial, NOAH Oficial, Making Oficial).');
        }

        // Colunas extras do mapeamento de variáveis (Ótima/NOAH/Robbu etc.) — evita SELECT * em bases enormes
        // Front envia Ótima como { "1": { "type": "field", "value": "nome" }, ... }; extrair só campos BD do tipo "field"
        $variables_map_columns = [];
        if (!empty($variables_map) && is_array($variables_map)) {
            foreach ($variables_map as $var_key => $field) {
                if (is_array($field) && isset($field['type'], $field['value'])
                    && $field['type'] === 'field' && is_string($field['value']) && $field['value'] !== '') {
                    $variables_map_columns[] = $field['value'];
                } elseif (is_string($field) && $field !== '') {
                    $variables_map_columns[] = $field;
                }
            }
            $variables_map_columns = array_values(array_unique($variables_map_columns));
        }

        // Busca registros filtrados (SELECT enxuto quando possível)
        $records = PC_Campaign_Filters::get_filtered_records($table_name, $filters, $record_limit, $variables_map_columns);
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
        $bait_ids_filter = null;
        if ($include_baits) {
            $bait_ids_filter = $this->parse_bait_ids_filter_from_post();
        }

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
            $iscas = $this->filter_baits_rows_by_ids($iscas ? $iscas : [], $bait_ids_filter);

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
            $prefix = $this->resolve_envios_agendamento_id_prefix($provider, $template_source);
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

                // id_carteira: CSV por linha > carteira escolhida no painel > vínculo base (LIMIT 1 pode ser ambíguo se várias carteiras na mesma base)
                if (!empty($record['id_carteira'])) {
                    $id_carteira = $record['id_carteira'];
                } elseif (!empty($carteira) && $campaign_id_carteira !== '' && $campaign_id_carteira !== null) {
                    $id_carteira = $campaign_id_carteira;
                } else {
                    $id_carteira = $this->get_id_carteira_from_table_idgis($table_name, $idgis_ambiente) ?: $campaign_id_carteira;
                }

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
                    $noah_tid = intval($template_info['template_id'] ?? 0);
                    $noah_name_post = (string) ($template_info['noah_template_name_post'] ?? '');
                    $noah_display_name = $noah_name_post !== '' ? $noah_name_post : ($template_info['template_name'] ?? $template_code);
                    $noah_language = $template_info['language'] ?? 'pt_BR';
                    $noah_snap = $template_info['noah_template_data'] ?? [];
                    if (!is_array($noah_snap)) {
                        $noah_snap = [];
                    }
                    $noah_components_static = [];
                    if (!empty($noah_snap['components']) && is_array($noah_snap['components'])) {
                        $noah_components_static = $noah_snap['components'];
                    }

                    $noah_flat_row = [
                        'nome' => (string) ($record['nome'] ?? ''),
                        'telefone' => (string) ($record['telefone'] ?? ''),
                        'cpf_cnpj' => (string) ($record['cpf_cnpj'] ?? ''),
                        'id_carteira' => (string) $id_carteira,
                        'idcob_contrato' => (string) ($record['idcob_contrato'] ?? ''),
                        'idgis_ambiente' => (string) (int) ($record['idgis_ambiente'] ?? 0),
                    ];
                    $vm_arr = is_array($variables_map) ? $variables_map : [];
                    $noah_vars_row = count($vm_arr) > 0
                        ? $this->resolve_noah_variables_row_for_csv($record, $vm_arr)
                        : [];
                    $row_vars_merged = array_merge($noah_flat_row, $noah_vars_row);

                    $mensagem_para_armazenar = json_encode([
                        'template_code' => $template_code,
                        'template_source' => 'noah_oficial',
                        'channelId' => $channel_id,
                        'templateId' => $noah_tid,
                        'templateName' => $noah_display_name,
                        'language' => $noah_language,
                        'original_message' => $mensagem_final,
                        'variables_map' => (object) $vm_arr,
                        'variables' => (object) $row_vars_merged,
                        'components' => $noah_components_static,
                        'templateData' => [
                            'components' => $noah_components_static,
                            'buttons' => $noah_snap['buttons'] ?? null,
                            'textHeader' => $noah_snap['textHeader'] ?? null,
                            'textBody' => $noah_snap['textBody'] ?? null,
                            'textFooter' => $noah_snap['textFooter'] ?? null,
                        ],
                        'buttons' => $noah_snap['buttons'] ?? null,
                        'textHeader' => $noah_snap['textHeader'] ?? null,
                        'textBody' => $noah_snap['textBody'] ?? null,
                        'textFooter' => $noah_snap['textFooter'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    $contact_vars = $this->resolve_gosac_contact_variables_for_row($record, $variables_map, $variable_components);
                    $gosac_body_parameters = [];
                    foreach ($contact_vars as $cv) {
                        $gosac_body_parameters[] = ['type' => 'text', 'text' => (string) ($cv['value'] ?? '')];
                    }
                    $gosac_components = [];
                    if (!empty($gosac_body_parameters)) {
                        $gosac_components[] = ['type' => 'body', 'parameters' => $gosac_body_parameters];
                    }
                    $mensagem_para_armazenar = json_encode([
                        'template_source' => 'gosac_oficial',
                        'template_code' => $template_code,
                        'nome_campanha' => $nome_campanha_camp,
                        'id' => $gosac_template_id,
                        'connectionId' => $gosac_connection_id,
                        'variables_map' => $variables_map,
                        'variableComponents' => $variable_components,
                        'contact_variables' => $contact_vars,
                        'components' => $gosac_components,
                        'original_message' => $mensagem_final,
                    ]);
                } elseif ($template_source === 'making_oficial' && !empty($template_code)) {
                    $making_vars = $this->resolve_noah_variables_row_for_csv($record, is_array($variables_map) ? $variables_map : []);
                    $send_meta = (string) ($template_info['send_meta_template'] ?? $template_code);
                    $mensagem_para_armazenar = json_encode([
                        'template_source' => 'making_oficial',
                        'send_meta_template' => $send_meta,
                        'template_code' => $send_meta,
                        'nome_campanha' => $nome_campanha_camp,
                        'making_team_id' => intval($template_info['making_team_id'] ?? 0),
                        'making_cost_center_id' => intval($template_info['making_cost_center_id'] ?? 0),
                        'variables_map' => $variables_map,
                        'variables' => $making_vars,
                        'original_message' => $mensagem_final,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($only_salesforce) {
                    $mensagem_para_armazenar = json_encode([
                        'template_source' => 'salesforce',
                        'note' => 'Conteúdo definido na automação Salesforce/MC.',
                    ]);
                }

                // carteira_id: id interno da carteira selecionada (para GOSAC: lookup correto de id_ruler quando há múltiplas carteiras com mesmo id_carteira)
                $carteira_id_insert = !empty($carteira) && $this->id_carteira_matches_campaign_selection($id_carteira, $campaign_id_carteira) ? intval($carteira) : null;

                // nome_campanha: exclusivamente o nome digitado no painel (POST); nunca template_code, carteira ou mensagem.
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
                    'nome_campanha' => $nome_campanha_camp !== '' ? $nome_campanha_camp : null,
                    'nome_carteira' => $nome_carteira_persist,
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

        } catch (\Throwable $e) {
            error_log('🚨 [handle_schedule_campaign] Erro: ' . $e->getMessage());
            error_log('🚨 [handle_schedule_campaign] Trace: ' . $e->getTraceAsString());
            wp_send_json_error('Erro ao agendar campanha: ' . $e->getMessage());
        }
    }

    public function handle_get_filters()
    {
        check_ajax_referer('campaign-manager-nonce', 'nonce');

        $table_name = $this->get_safe_table_name();
        if (empty($table_name)) {
            wp_send_json_error('Nome da tabela não fornecido ou inválido');
            return;
        }

        $cache_key = 'pc_cols_' . md5($table_name);
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            wp_send_json_success($cached);
            return;
        }

        $filters = PC_Campaign_Filters::get_filterable_columns($table_name);

        if (is_wp_error($filters)) {
            wp_send_json_error($filters->get_error_message());
        }

        if (!is_array($filters)) {
            error_log('⚠️ [get_filters] Filtros não é array, convertendo. Tipo: ' . gettype($filters));
            $filters = [];
        }

        // 12–24 h: metadados mudam raramente; invalidação natural ao expirar
        set_transient($cache_key, $filters, 18 * HOUR_IN_SECONDS);

        error_log('🔍 [get_filters] Cache MISS — gravado transient; ' . count($filters) . ' filtros para: ' . $table_name);

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

    /**
     * Conta blocklist + exclusão recente para um lote (evita carregar a base inteira na RAM).
     */
    private function accumulate_count_detailed_batch(
        array $batch,
        $exclude_recent,
        array $recent_phones,
        &$blocked_count,
        &$recent_excluded_count,
        &$effective_count
    ) {
        global $wpdb;

        if (empty($batch)) {
            return;
        }

        $telefones = [];
        $cpfs = [];
        foreach ($batch as $record) {
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
        $blocked_telefones_map = [];
        $blocked_cpfs_map = [];

        if (!empty($telefones)) {
            foreach (array_chunk(array_values(array_unique($telefones)), 2500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $query = $wpdb->prepare(
                    "SELECT valor FROM $table_blocklist WHERE tipo = 'telefone' AND valor IN ($placeholders)",
                    $chunk
                );
                foreach ((array) $wpdb->get_col($query) as $v) {
                    $blocked_telefones_map[$v] = true;
                }
            }
        }

        if (!empty($cpfs)) {
            foreach (array_chunk(array_values(array_unique($cpfs)), 2500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $query = $wpdb->prepare(
                    "SELECT valor FROM $table_blocklist WHERE tipo = 'cpf' AND valor IN ($placeholders)",
                    $chunk
                );
                foreach ((array) $wpdb->get_col($query) as $v) {
                    $blocked_cpfs_map[$v] = true;
                }
            }
        }

        foreach ($batch as $record) {
            $telefone = preg_replace('/[^0-9]/', '', $record['telefone'] ?? '');
            $telefone_normalizado = $telefone;
            if (strlen($telefone_normalizado) > 11 && substr($telefone_normalizado, 0, 2) === '55') {
                $telefone_normalizado = substr($telefone_normalizado, 2);
            }

            $cpf = preg_replace('/[^0-9]/', '', $record['cpf_cnpj'] ?? '');

            $is_blocked = false;
            if (isset($blocked_telefones_map[$telefone])) {
                $is_blocked = true;
            } elseif (strlen($cpf) === 11 && isset($blocked_cpfs_map[$cpf])) {
                $is_blocked = true;
            }

            if ($is_blocked) {
                $blocked_count++;
                continue;
            }

            if ($exclude_recent && isset($recent_phones[$telefone_normalizado])) {
                $recent_excluded_count++;
                continue;
            }

            $effective_count++;
        }
    }

    public function handle_get_count_detailed()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

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

        $where_sql = PC_Campaign_Filters::build_where_clause($filters);
        $total_count = PC_Campaign_Filters::count_records($table_name, $filters);

        if ($total_count === 0) {
            wp_send_json_success([
                'total' => 0,
                'recent_excluded' => 0,
                'blocked' => 0,
                'effective' => 0,
                'partial' => false,
            ]);
            return;
        }

        $columns = array_map('strtoupper', (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`"));
        $select_fields = [];

        if (in_array('TELEFONE', $columns)) {
            $select_fields[] = 'TELEFONE as telefone';
        } elseif (in_array('CELULAR', $columns)) {
            $select_fields[] = 'CELULAR as telefone';
        } elseif (in_array('PHONE', $columns)) {
            $select_fields[] = 'PHONE as telefone';
        } else {
            $found_tel = false;
            foreach ($columns as $col) {
                if (strpos($col, 'TEL') !== false || strpos($col, 'CEL') !== false) {
                    $select_fields[] = "`$col` as telefone";
                    $found_tel = true;
                    break;
                }
            }
            if (!$found_tel) {
                $select_fields[] = 'NULL as telefone';
            }
        }

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

        $recent_phones = [];
        if ($exclude_recent) {
            $recent_phones = $this->get_recent_phones_batch($envios_table, $exclude_recent_hours);
        }

        $blocked_count = 0;
        $recent_excluded_count = 0;
        $effective_count = 0;

        $raw_col_names = (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        $pk_column = null;
        foreach ($raw_col_names as $cn) {
            if (strtoupper($cn) === 'ID') {
                $pk_column = $cn;
                break;
            }
        }

        $chunk_size = 2500;

        if ($pk_column !== null) {
            $pk_esc = '`' . str_replace('`', '', $pk_column) . '`';
            $last_id = 0;

            while (true) {
                $sql = "SELECT {$pk_esc} AS __pc_pk, {$select_clause} FROM `{$table_name}`"
                    . $where_sql . ' AND ' . $pk_esc . ' > ' . intval($last_id)
                    . ' ORDER BY ' . $pk_esc . ' ASC LIMIT ' . intval($chunk_size);

                $batch = $wpdb->get_results($sql, ARRAY_A);

                if ($wpdb->last_error) {
                    error_log('🔴 [count_detailed] Erro no lote (keyset ID): ' . $wpdb->last_error . ' | SQL: ' . $sql);
                    wp_send_json_error('Erro ao contar registros (lote). Tente novamente ou contate o suporte.');
                    return;
                }

                if (empty($batch)) {
                    break;
                }

                $max_in_batch = 0;
                foreach ($batch as &$row) {
                    $rid = isset($row['__pc_pk']) ? intval($row['__pc_pk']) : 0;
                    if ($rid > $max_in_batch) {
                        $max_in_batch = $rid;
                    }
                    unset($row['__pc_pk']);
                }
                unset($row);

                $this->accumulate_count_detailed_batch(
                    $batch,
                    $exclude_recent,
                    $recent_phones,
                    $blocked_count,
                    $recent_excluded_count,
                    $effective_count
                );

                unset($batch);

                if ($max_in_batch <= $last_id) {
                    break;
                }
                $last_id = $max_in_batch;
            }

            wp_send_json_success([
                'total' => $total_count,
                'recent_excluded' => $recent_excluded_count,
                'blocked' => $blocked_count,
                'effective' => $effective_count,
                'partial' => false,
            ]);
            return;
        }

        // Sem coluna ID: carregar tudo só em bases menores (evita 500 por memória)
        if ($total_count > 30000) {
            wp_send_json_success([
                'total' => $total_count,
                'recent_excluded' => 0,
                'blocked' => 0,
                'effective' => $total_count,
                'partial' => true,
                'partial_message' => 'Esta visão não tem coluna ID indexável para contagem em lotes. O total líquido exibido iguala o bruto; blocklist e exclusão por envio recente ainda serão aplicadas ao gerar a campanha.',
            ]);
            return;
        }

        $sql = "SELECT {$select_clause} FROM `{$table_name}`" . $where_sql;
        $suprime_erro = $wpdb->suppress_errors(true);
        $records = $wpdb->get_results($sql, ARRAY_A);
        $wpdb->suppress_errors($suprime_erro);

        if ($records === null || $wpdb->last_error) {
            $records = $this->get_filtered_records_optimized($table_name, $filters, 0, false);
        }

        if (!is_array($records) || empty($records)) {
            wp_send_json_success([
                'total' => 0,
                'recent_excluded' => 0,
                'blocked' => 0,
                'effective' => 0,
                'partial' => false,
            ]);
            return;
        }

        $this->accumulate_count_detailed_batch(
            $records,
            $exclude_recent,
            $recent_phones,
            $blocked_count,
            $recent_excluded_count,
            $effective_count
        );

        wp_send_json_success([
            'total' => $total_count,
            'recent_excluded' => $recent_excluded_count,
            'blocked' => $blocked_count,
            'effective' => $effective_count,
            'partial' => false,
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

        $items = [];
        if (function_exists('wp_is_numeric_array') && wp_is_numeric_array($data)) {
            $items = $data;
        } elseif ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
            $items = $data;
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $items = $data['data'];
        }

        $active_templates = array_filter(is_array($items) ? $items : [], static function ($template) {
            if (!is_array($template)) {
                return false;
            }

            return !isset($template['status']) || $template['status'] === 'A' || $template['status'] === 'ACTIVE';
        });

        $out = [];
        foreach ($active_templates as $template) {
            if (!is_array($template)) {
                continue;
            }
            $code = isset($template['code']) ? trim((string) $template['code']) : '';
            if ($code === '' && isset($template['template_id'])) {
                $code = trim((string) $template['template_id']);
            }
            if ($code === '') {
                continue;
            }
            $desc_raw = '';
            if (isset($template['rich_card']) && is_array($template['rich_card']) && isset($template['rich_card']['description'])) {
                $d = $template['rich_card']['description'];
                $desc_raw = is_string($d) ? trim($d) : '';
            }
            $name = 'RCS ' . $code;
            if ($desc_raw !== '') {
                $name .= ' - ' . wp_trim_words($desc_raw, 5, '...');
            }
            $content = '';
            if (isset($template['rich_card']) && is_array($template['rich_card'])) {
                if (!empty($template['rich_card']['title'])) {
                    $content .= $template['rich_card']['title'] . "\n";
                }
                if (!empty($template['rich_card']['description'])) {
                    $content .= $template['rich_card']['description'];
                }
            } elseif (isset($template['text']) && is_string($template['text'])) {
                $content = $template['text'];
            }

            $out[] = [
                'id' => $code,
                'name' => $name,
                'title' => $name,
                'send_meta_template' => $code,
                'content' => $content,
                'date' => date('Y-m-d H:i:s'),
                'source' => 'otima_rcs',
                'template_code' => $code,
                'template_id' => $code,
                'raw_data' => $template,
            ];
        }

        return $out;
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
        if (!current_user_can('read')) {
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
        if (!current_user_can('read')) {
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
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_campanha'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_campanha varchar(255) NULL DEFAULT NULL AFTER agendamento_id");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_carteira varchar(255) NULL DEFAULT NULL");
        }

        // Prepara valores para INSERT múltiplo
        $values = [];

        foreach ($data_array as $data) {
            $id_carteira = isset($data['id_carteira']) ? $data['id_carteira'] : '';
            $idcob_contrato = isset($data['idcob_contrato']) ? $data['idcob_contrato'] : 0;
            $midia_campanha = isset($data['midia_campanha']) ? $data['midia_campanha'] : '';
            $carteira_id = isset($data['carteira_id']) && $data['carteira_id'] !== null && $data['carteira_id'] !== '' ? intval($data['carteira_id']) : 0;
            $nome_campanha_ins = '';
            if (isset($data['nome_campanha']) && $data['nome_campanha'] !== null && $data['nome_campanha'] !== '') {
                $nome_campanha_ins = (string) $data['nome_campanha'];
            }
            $nome_carteira_ins = '';
            if (isset($data['nome_carteira']) && $data['nome_carteira'] !== null && $data['nome_carteira'] !== '') {
                $nome_carteira_ins = (string) $data['nome_carteira'];
            }

            $values[] = $wpdb->prepare(
                "(%s, %s, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s)",
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
                $nome_campanha_ins,
                $nome_carteira_ins,
                $data['status'],
                $data['current_user_id'],
                $data['valido'],
                $data['data_cadastro']
            );
        }

        $sql = "INSERT INTO {$table} 
                (telefone, nome, idgis_ambiente, id_carteira, carteira_id, idcob_contrato, cpf_cnpj, mensagem, midia_campanha, fornecedor, agendamento_id, nome_campanha, nome_carteira, status, current_user_id, valido, data_cadastro) 
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
            // Objeto já decodificado para o React (iscas: bait_ids, provedores, exclusão recente, etc.)
            $campaign['providers_config_parsed'] = $providers_config;

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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
    private function get_filtered_records_optimized($table_name, $filters, $limit = 0, $exclude_recent_phones = false, $exclude_recent_hours = 48, array $extra_select_columns = [])
    {
        global $wpdb;

        if (empty($table_name)) {
            return [];
        }

        $envios_table = $wpdb->prefix . 'envios_pendentes';

        // Mesmo parser de filtros que Nova Campanha / PC_Campaign_Filters (array de {column, operator, value})
        $filter_where = PC_Campaign_Filters::build_where_clause($filters);
        $filter_where = preg_replace('/`([A-Za-z0-9_]+)`/', 't.`$1`', $filter_where);

        $limit_sql = $limit > 0 ? $wpdb->prepare(" LIMIT %d", $limit) : '';

        // Dinamicamente monta o SELECT baseado nas colunas existentes para evitar erros de UNKNOWN COLUMN
        $raw_cols = (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        $columns = array_map('strtoupper', $raw_cols);
        $upper_to_actual = [];
        foreach ($raw_cols as $cn) {
            $act = str_replace('`', '', $cn);
            $upper_to_actual[strtoupper($act)] = $act;
        }

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

        foreach ($extra_select_columns as $col_req) {
            if (!is_string($col_req) || $col_req === '') {
                continue;
            }
            $U = strtoupper(str_replace('`', '', $col_req));
            if (!isset($upper_to_actual[$U])) {
                continue;
            }
            $act = $upper_to_actual[$U];
            $safe = esc_sql($act);
            $select_fields[] = "t.`{$safe}` AS `{$safe}`";
        }

        $select_clause = implode(', ', $select_fields);
        $where_sql = $filter_where;

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

            $template_source_row = $campaign['template_source'] ?? 'local';
            $variables_map = [];
            if (!empty($campaign['variables_map'])) {
                $vd = json_decode($campaign['variables_map'], true);
                $variables_map = is_array($vd) ? $vd : [];
            }
            $template_meta = [];
            if (!empty($campaign['template_meta'])) {
                $tm = json_decode($campaign['template_meta'], true);
                $template_meta = is_array($tm) ? $tm : [];
            }
            $extra_select_cols = $this->recurring_resolve_extra_select_columns(
                $campaign['tabela_origem'] ?? '',
                $variables_map,
                $template_source_row
            );

            // 2. 🚀 OTIMIZADO: Busca registros com SELECT direto + LEFT JOIN para excluir telefones recentes
            error_log('🔵 Buscando registros filtrados (SELECT direto com exclusão de telefones recentes)...');
            $step_start = microtime(true);
            $records = $this->get_filtered_records_optimized(
                $campaign['tabela_origem'],
                $filters,
                $campaign['record_limit'],
                $exclude_recent_phones, // Passa flag para fazer LEFT JOIN
                $exclude_recent_hours,
                $extra_select_cols
            );
            error_log('🔵 Registros encontrados: ' . count($records) . ' em ' . round(microtime(true) - $step_start, 2) . 's');

            if (empty($records)) {
                return [
                    'success' => false,
                    'message' => 'Nenhum registro encontrado com os filtros aplicados'
                ];
            }

            // 3. 🎣 ADICIONA ISCAS ATIVAS (apenas com IDGIS compatível), se include_baits na campanha
            $baits_count = 0;
            $include_baits_rc = intval($campaign['include_baits'] ?? 0);
            $bait_ids_cfg = null;
            if (is_array($providers_config) && array_key_exists('bait_ids', $providers_config)) {
                $raw_bids = $providers_config['bait_ids'];
                $bait_ids_cfg = is_array($raw_bids) ? array_values(array_unique(array_map('intval', $raw_bids))) : [];
            }

            $all_baits = [];
            if ($include_baits_rc) {
                $all_baits = PC_Campaign_Baits::get_active_baits();
                $all_baits = $this->filter_baits_rows_by_ids($all_baits ? $all_baits : [], $bait_ids_cfg);
            }
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

            // 5. Busca template (local WP, externo só com código, TECHIA sem post, SF só nota)
            $only_sf_exec = $this->is_salesforce_only_providers($providers_config);
            if ($only_sf_exec) {
                $mensagem_template = 'Salesforce Marketing Cloud: conteúdo definido na automação.';
            } elseif ($template_source_row === 'techia_discador') {
                $mensagem_template = '';
            } elseif (intval($campaign['template_id'] ?? 0) > 0) {
                $template_post = get_post(intval($campaign['template_id']));
                if (!$template_post || $template_post->post_type !== 'message_template') {
                    return [
                        'success' => false,
                        'message' => 'Template de mensagem não encontrado'
                    ];
                }
                $mensagem_template = $template_post->post_content;
            } elseif (!empty($campaign['template_code'])) {
                $mensagem_template = 'Template externo: ' . sanitize_text_field($campaign['template_code']);
            } else {
                return [
                    'success' => false,
                    'message' => 'Configuração de template inválida para execução do filtro salvo.'
                ];
            }

            $template_code_top = $campaign['template_code'] ?? '';
            $template_info_recurring = [
                'template_code' => $template_code_top,
                'source' => $template_source_row,
            ];
            if ($template_source_row === 'noah_oficial' || $template_source_row === 'noah') {
                $template_info_recurring['channel_id'] = intval($template_meta['noah_channel_id'] ?? 0);
                $template_info_recurring['template_id'] = intval($template_meta['noah_template_id'] ?? 0);
                $template_info_recurring['template_name'] = $template_code_top;
                $template_info_recurring['language'] = $template_meta['noah_language'] ?? 'pt_BR';
                $noah_td_meta = $template_meta['noah_template_data'] ?? [];
                $template_info_recurring['noah_template_data'] = is_array($noah_td_meta) ? $noah_td_meta : [];
                $template_info_recurring['noah_template_name_post'] = isset($template_meta['noah_template_name'])
                    ? sanitize_text_field((string) $template_meta['noah_template_name'])
                    : '';
            }
            if ($template_source_row === 'gosac_oficial') {
                $template_info_recurring['template_id'] = intval($template_meta['gosac_template_id'] ?? 0);
                $template_info_recurring['connection_id'] = intval($template_meta['gosac_connection_id'] ?? 0);
                $template_info_recurring['variable_components'] = isset($template_meta['gosac_variable_components']) && is_array($template_meta['gosac_variable_components'])
                    ? $template_meta['gosac_variable_components'] : [];
            }
            if ($template_source_row === 'robbu_oficial') {
                $template_info_recurring['channel'] = intval($template_meta['robbu_channel'] ?? 3);
                $template_info_recurring['template_name'] = $template_code_top;
            }
            if ($template_source_row === 'making_oficial') {
                $template_info_recurring['send_meta_template'] = $template_code_top;
                $template_info_recurring['making_team_id'] = intval($template_meta['making_team_id'] ?? 0);
                $template_info_recurring['making_cost_center_id'] = intval($template_meta['making_cost_center_id'] ?? 0);
            }

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
            $nome_carteira_recurring = '';
            if (!empty($campaign_carteira)) {
                $nome_carteira_recurring = $this->get_carteira_nome_by_id((int) $campaign_carteira);
            }

            foreach ($distribution as $provider => $provider_records) {
                error_log("🔵 Processando provedor {$provider}: " . count($provider_records) . " registros");
                $template_source_row = $campaign['template_source'] ?? 'local';
                $prefix = $this->resolve_envios_agendamento_id_prefix($provider, $template_source_row);
                $campaign_name_clean = preg_replace('/[^a-zA-Z0-9]/', '', $campaign['nome_campanha']);
                $campaign_name_short = substr($campaign_name_clean, 0, 30);
                $agendamento_id = $prefix . $agendamento_base_id . '_' . $campaign_name_short;

                $template_source = $template_source_row;
                $template_code = $campaign['template_code'] ?? '';
                $broker_code = $campaign['broker_code'] ?? '';
                $customer_code = $campaign['customer_code'] ?? '';
                $generated_campaign_ids = [];

                foreach ($provider_records as $record) {
                    // 🚀 Telefones recentes já foram excluídos no LEFT JOIN da query
                    // Não precisa mais verificar aqui!

                    $telefone_normalizado = $this->extract_phone_for_recurring($record);

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

                    // id_carteira: carteira salva na campanha recorrente > vínculo base (LIMIT 1 pode ser ambíguo)
                    if (!empty($campaign_carteira) && $recurring_campaign_id_carteira !== '') {
                        $id_carteira = $recurring_campaign_id_carteira;
                    } else {
                        $id_carteira = $this->get_id_carteira_from_table_idgis($campaign['tabela_origem'], $idgis_mapeado) ?: $recurring_campaign_id_carteira;
                    }

                    // Prepara mensagem
                    $mensagem_final = ($template_source === 'techia_discador')
                        ? ''
                        : $this->replace_placeholders($mensagem_template, $record);

                    $mensagem_para_armazenar = $mensagem_final;
                    if (($template_source === 'otima_wpp' || $template_source === 'otima_rcs') && !empty($template_code)) {
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => $template_source,
                            'broker_code' => $broker_code,
                            'customer_code' => (string) $id_carteira,
                            'original_message' => $mensagem_final,
                            'variables_map' => !empty($variables_map) ? $variables_map : null,
                        ]);
                    } elseif (($template_source === 'noah_oficial' || $template_source === 'noah') && !empty($template_code)) {
                        $channel_id = intval($template_info_recurring['channel_id'] ?? 0);
                        $noah_tid = intval($template_info_recurring['template_id'] ?? 0);
                        $noah_name_post = (string) ($template_info_recurring['noah_template_name_post'] ?? '');
                        $noah_display_name = $noah_name_post !== '' ? $noah_name_post : ($template_info_recurring['template_name'] ?? $template_code);
                        $noah_language = $template_info_recurring['language'] ?? 'pt_BR';
                        $noah_snap = $template_info_recurring['noah_template_data'] ?? [];
                        if (!is_array($noah_snap)) {
                            $noah_snap = [];
                        }
                        $noah_components_static = [];
                        if (!empty($noah_snap['components']) && is_array($noah_snap['components'])) {
                            $noah_components_static = $noah_snap['components'];
                        }
                        $vm_arr = is_array($variables_map) ? $variables_map : [];
                        $noah_flat_row = [
                            'nome' => (string) ($record['nome'] ?? ''),
                            'telefone' => (string) ($record['telefone'] ?? ''),
                            'cpf_cnpj' => (string) ($record['cpf_cnpj'] ?? ''),
                            'id_carteira' => (string) $id_carteira,
                            'idcob_contrato' => (string) ($record['idcob_contrato'] ?? ''),
                            'idgis_ambiente' => (string) (int) ($record['idgis_ambiente'] ?? 0),
                        ];
                        $noah_vars_row = count($vm_arr) > 0
                            ? $this->resolve_noah_variables_row_for_csv($record, $vm_arr)
                            : [];
                        $row_vars_merged = array_merge($noah_flat_row, $noah_vars_row);
                        $mensagem_para_armazenar = json_encode([
                            'template_code' => $template_code,
                            'template_source' => 'noah_oficial',
                            'channelId' => $channel_id,
                            'templateId' => $noah_tid,
                            'templateName' => $noah_display_name,
                            'language' => $noah_language,
                            'original_message' => $mensagem_final,
                            'variables_map' => (object) $vm_arr,
                            'variables' => (object) $row_vars_merged,
                            'components' => $noah_components_static,
                            'templateData' => [
                                'components' => $noah_components_static,
                                'buttons' => $noah_snap['buttons'] ?? null,
                                'textHeader' => $noah_snap['textHeader'] ?? null,
                                'textBody' => $noah_snap['textBody'] ?? null,
                                'textFooter' => $noah_snap['textFooter'] ?? null,
                            ],
                            'buttons' => $noah_snap['buttons'] ?? null,
                            'textHeader' => $noah_snap['textHeader'] ?? null,
                            'textBody' => $noah_snap['textBody'] ?? null,
                            'textFooter' => $noah_snap['textFooter'] ?? null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } elseif ($template_source === 'robbu_oficial' && !empty($template_code)) {
                        $robbu_params = [];
                        if (!empty($variables_map) && is_array($variables_map)) {
                            foreach ($variables_map as $param_name => $field) {
                                $val = '';
                                if (is_array($field) && isset($field['type'], $field['value'])) {
                                    if ($field['type'] === 'field') {
                                        $col = $field['value'];
                                        $val = $record[$col] ?? $record[strtoupper((string) $col)] ?? '';
                                    } else {
                                        $val = (string) ($field['value'] ?? '');
                                    }
                                } elseif (is_string($field) && $field !== '') {
                                    $val = $record[$field] ?? $record[strtoupper($field)] ?? '';
                                }
                                $robbu_params[] = [
                                    'parameterName' => (string) $param_name,
                                    'parameterValue' => (string) $val,
                                ];
                            }
                        }
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'robbu_oficial',
                            'templateName' => $template_code,
                            'channel' => intval($template_info_recurring['channel'] ?? 3),
                            'templateParameters' => $robbu_params,
                        ]);
                    } elseif ($template_source === 'gosac_oficial' && !empty($template_code)) {
                        $gosac_template_id = intval($template_info_recurring['template_id'] ?? 0);
                        $gosac_connection_id = intval($template_info_recurring['connection_id'] ?? 0);
                        $variable_components = $template_info_recurring['variable_components'] ?? [];
                        $nome_rec = sanitize_text_field($campaign['nome_campanha'] ?? '');
                        $contact_vars = $this->resolve_gosac_contact_variables_for_row($record, $variables_map, $variable_components);
                        $gosac_body_parameters = [];
                        foreach ($contact_vars as $cv) {
                            $gosac_body_parameters[] = ['type' => 'text', 'text' => (string) ($cv['value'] ?? '')];
                        }
                        $gosac_components = [];
                        if (!empty($gosac_body_parameters)) {
                            $gosac_components[] = ['type' => 'body', 'parameters' => $gosac_body_parameters];
                        }
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'gosac_oficial',
                            'template_code' => $template_code,
                            'nome_campanha' => $nome_rec,
                            'id' => $gosac_template_id,
                            'connectionId' => $gosac_connection_id,
                            'variables_map' => $variables_map,
                            'variableComponents' => $variable_components,
                            'contact_variables' => $contact_vars,
                            'components' => $gosac_components,
                            'original_message' => $mensagem_final,
                        ]);
                    } elseif ($template_source === 'making_oficial' && !empty($template_code)) {
                        $making_vars = $this->resolve_noah_variables_row_for_csv($record, is_array($variables_map) ? $variables_map : []);
                        $send_meta = (string) ($template_info_recurring['send_meta_template'] ?? $template_code);
                        $nome_rec = sanitize_text_field($campaign['nome_campanha'] ?? '');
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'making_oficial',
                            'send_meta_template' => $send_meta,
                            'template_code' => $send_meta,
                            'nome_campanha' => $nome_rec,
                            'making_team_id' => intval($template_info_recurring['making_team_id'] ?? 0),
                            'making_cost_center_id' => intval($template_info_recurring['making_cost_center_id'] ?? 0),
                            'variables_map' => $variables_map,
                            'variables' => $making_vars,
                            'original_message' => $mensagem_final,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } elseif ($template_source === 'techia_discador') {
                        $techia_vars = (!empty($variables_map))
                            ? $this->resolve_techia_variables_for_row($record, $variables_map)
                            : $this->build_default_techia_variables_from_base_record($record);
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'techia_discador',
                            'template_code' => '',
                            'variables_map' => !empty($variables_map) ? $variables_map : null,
                            'variables' => $techia_vars,
                            'original_message' => $mensagem_final,
                        ]);
                    } elseif ($only_sf_exec) {
                        $mensagem_para_armazenar = json_encode([
                            'template_source' => 'salesforce',
                            'note' => 'Conteúdo definido na automação Salesforce/MC.',
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
                        'nome_campanha' => !empty($campaign['nome_campanha']) ? sanitize_text_field($campaign['nome_campanha']) : null,
                        'nome_carteira' => $nome_carteira_recurring,
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
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_campanha'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_campanha varchar(255) NULL DEFAULT NULL AFTER agendamento_id");
        }
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'nome_carteira'"))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN nome_carteira varchar(255) NULL DEFAULT NULL");
        }

        $values = [];

        foreach ($data_array as $data) {
            $id_carteira = isset($data['id_carteira']) ? $data['id_carteira'] : '';
            $idcob_contrato = isset($data['idcob_contrato']) ? $data['idcob_contrato'] : 0;
            $nome_campanha_ins = '';
            if (isset($data['nome_campanha']) && $data['nome_campanha'] !== null && $data['nome_campanha'] !== '') {
                $nome_campanha_ins = (string) $data['nome_campanha'];
            }
            $nome_carteira_ins = '';
            if (isset($data['nome_carteira']) && $data['nome_carteira'] !== null && $data['nome_carteira'] !== '') {
                $nome_carteira_ins = (string) $data['nome_carteira'];
            }

            $values[] = $wpdb->prepare(
                "(%s, %s, %d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s)",
                $data['telefone'],
                $data['nome'],
                $data['idgis_ambiente'],
                $id_carteira,
                $idcob_contrato,
                $data['cpf_cnpj'],
                $data['mensagem'],
                $data['fornecedor'],
                $data['agendamento_id'],
                $nome_campanha_ins,
                $nome_carteira_ins,
                $data['status'],
                $data['current_user_id'],
                $data['valido'],
                $data['data_cadastro']
            );
        }

        $sql = "INSERT INTO {$table} 
                (telefone, nome, idgis_ambiente, id_carteira, idcob_contrato, cpf_cnpj, mensagem, fornecedor, agendamento_id, nome_campanha, nome_carteira, status, current_user_id, valido, data_cadastro) 
                VALUES " . implode(', ', $values);

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('🚨 [ERRO MySQL bulk_insert_recurring] ' . $wpdb->last_error);
        }
    }

    // ========== HANDLERS PARA APROVAR CAMPANHAS ==========

    public function handle_get_pending_campaigns()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');
        $this->maybe_add_envios_cancel_columns();

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
                COALESCE(MAX(u.display_name), 'Usuário Desconhecido') AS scheduled_by,
                MAX(t1.id_carteira) AS id_carteira,
                MAX(t1.nome_carteira) AS nome_carteira
            FROM `{$table}` AS t1
            LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
            WHERE {$where_sql}
            GROUP BY t1.agendamento_id, t1.fornecedor
            ORDER BY MIN(t1.data_cadastro) DESC
        ";

        error_log('🔵 [Aprovar Campanhas] Query: ' . $query);

        $results = $wpdb->get_results($query, ARRAY_A);

        if (is_array($results)) {
            foreach ($results as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $nome = trim((string) ($row['nome_carteira'] ?? ''));
                $row['nome_carteira'] = $nome;
                $row['carteira_nome'] = $nome;
                $row['wallet_name'] = $nome;
            }
            unset($row);
        }

        error_log('🔵 [Aprovar Campanhas] Resultados encontrados: ' . count($results ?: []));
        if (!empty($results)) {
            error_log('🔵 [Aprovar Campanhas] Primeiro resultado: ' . print_r($results[0], true));
        }

        wp_send_json_success($results);
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
        $this->pc_forbid_subscriber_ajax();
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

    /**
     * URL base e API key do NestJS para clientes autenticados (ex.: Validador WhatsApp no SPA).
     * Mesma origem de credenciais do microserviço; permissão read (painel operacional).
     */
    public function handle_get_nest_client_config()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('read')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $microservice_config = get_option('acm_microservice_config', []);
        $microservice_url = $microservice_config['url'] ?? '';
        $microservice_api_key = $microservice_config['api_key'] ?? '';
        $master_api_key = get_option('acm_master_api_key', '');
        $api_key = !empty($microservice_api_key) ? $microservice_api_key : $master_api_key;

        $base_url = rtrim((string) $microservice_url, '/');
        if (substr($base_url, -4) === '/api') {
            $base_url = substr($base_url, 0, -4);
        }

        wp_send_json_success([
            'url' => $base_url,
            'api_key' => $api_key,
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
        $this->pc_forbid_subscriber_ajax();
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
                $sf_creds = [
                    'client_id' => $static_credentials['sf_client_id'] ?? '',
                    'client_secret' => $static_credentials['sf_client_secret'] ?? '',
                    'username' => $static_credentials['sf_username'] ?? '',
                    'password' => $static_credentials['sf_password'] ?? '',
                    'token_url' => $static_credentials['sf_token_url'] ?? 'https://concilig.my.salesforce.com/services/oauth2/token',
                    'api_url' => $static_credentials['sf_api_url'] ?? 'https://concilig.my.salesforce.com/services/data/v59.0/composite/sobjects',
                ];
                $idgis_row = $wpdb->get_var($wpdb->prepare(
                    "SELECT idgis_ambiente FROM {$table} WHERE agendamento_id = %s LIMIT 1",
                    $agendamento_id
                ));
                if ($idgis_row !== null && $idgis_row !== '') {
                    $sf_creds = $this->merge_salesforce_dynamic_credentials($sf_creds, $idgis_row);
                }
                $payload['salesforce_credentials'] = $sf_creds;
                error_log('🔵 [Aprovar Campanha] Credenciais do Salesforce incluídas no payload (operacao/automation por idgis se configuradas)');
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
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');
        $this->maybe_add_envios_cancel_columns();

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';
        $agendamento_id = sanitize_text_field($_POST['agendamento_id'] ?? '');
        $fornecedor = sanitize_text_field($_POST['fornecedor'] ?? '');
        $motivo = sanitize_textarea_field($_POST['motivo'] ?? '');

        if (empty($agendamento_id)) {
            wp_send_json_error('Agendamento ID é obrigatório');
            return;
        }

        $uid = get_current_user_id();
        $update_row = [
            'status' => 'negado',
            'motivo_cancelamento' => $motivo !== '' ? $motivo : 'Negado pelo administrador (sem motivo informado).',
            'cancelado_por' => $uid,
        ];

        $where = [
            'agendamento_id' => $agendamento_id,
            'status' => 'pendente_aprovacao',
        ];
        $where_format = ['%s', '%s'];
        if ($fornecedor !== '') {
            $where['fornecedor'] = $fornecedor;
            $where_format[] = '%s';
        }

        $updated = $wpdb->update(
            $table,
            $update_row,
            $where,
            ['%s', '%s', '%d'],
            $where_format
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
            if ($provider === 'SALESFORCE') {
                error_log('🔵 [REST API] Salesforce: OAuth estático + operacao/automation_id por ambiente (envId=' . $env_id . ')');
            } else {
                error_log('🔵 [REST API] Provider estático detectado: ' . $provider . ' (envId ignorado para este provider)');
            }

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
                // Operação + automation_id ficam por ambiente no API Manager (acm_provider_credentials → salesforce → idgis)
                $credentials = $this->merge_salesforce_dynamic_credentials($credentials, $env_id);
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
            // TECHIA (API Manager → acm_provider_credentials['techia'][idgis_ambiente])
            if (strtoupper($provider) === 'TECHIA') {
                $acm_all = get_option('acm_provider_credentials', []);
                $block = $this->resolve_techia_acm_block(is_array($acm_all) ? $acm_all : [], $env_id);
                if (is_array($block) && $this->has_valid_credentials($block)) {
                    error_log('✅ [REST API] Credenciais TECHIA (acm_provider_credentials) env_id=' . $env_id);

                    return rest_ensure_response($block);
                }

                return new WP_Error(
                    'no_credentials',
                    'Credenciais TECHIA não encontradas para env_id ' . $env_id . '. Configure no API Manager (TECHIA) ou credenciais dinâmicas.',
                    ['status' => 404]
                );
            }

            // Making Oficial: JWT global + phone_number_id por carteira (acm_provider_credentials → making_oficial → id_carteira).
            if (strtoupper($provider) === 'MAKING_OFICIAL') {
                $mk = $this->get_making_global_config();
                $jwt = $mk['jwt'];
                if ($jwt === '') {
                    return new WP_Error(
                        'invalid_credentials',
                        'Making Oficial: configure o JWT global (API Manager → credenciais estáticas: Making Oficial).',
                        ['status' => 400]
                    );
                }
                $acm_all = get_option('acm_provider_credentials', []);
                $wallet_block = $this->resolve_making_oficial_acm_block(is_array($acm_all) ? $acm_all : [], $env_id);
                if (!is_array($wallet_block)) {
                    return new WP_Error(
                        'invalid_credentials',
                        'Making Oficial: cadastre o Phone Number ID para esta carteira (Credenciais Dinâmicas → making_oficial → Environment ID = id_carteira).',
                        ['status' => 400]
                    );
                }
                $phone_raw = $wallet_block['phone_number_id'] ?? $wallet_block['phoneNumberId'] ?? '';
                $phone_n = is_numeric($phone_raw) ? (int) $phone_raw : (int) preg_replace('/\D/', '', (string) $phone_raw);
                if ($phone_n <= 0) {
                    return new WP_Error(
                        'invalid_credentials',
                        'Making Oficial: phone_number_id inválido ou ausente para env_id ' . $env_id . '.',
                        ['status' => 400]
                    );
                }
                error_log('✅ [REST API] MAKING_OFICIAL JWT global + phone envId=' . $env_id);

                $out = [
                    'token' => $jwt,
                    'bearer_token' => $jwt,
                    'phone_number_id' => $phone_n,
                    'phoneNumberId' => $phone_n,
                ];
                $opt_url = isset($wallet_block['url']) ? trim((string) $wallet_block['url']) : '';
                if ($opt_url !== '') {
                    $out['url'] = $opt_url;
                    $out['api_url'] = $opt_url;
                    $out['making_api_url'] = $opt_url;
                }

                return rest_ensure_response($out);
            }

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

    /**
     * Injeta operacao, automation_id (e MKC por ambiente, se houver) nas credenciais Salesforce.
     * O NestJS busca GET .../credentials/salesforce/{idgis_ambiente}; os dados dinâmicos vivem em acm_provider_credentials.
     */
    private function merge_salesforce_dynamic_credentials(array $credentials, $env_id)
    {
        $all = get_option('acm_provider_credentials', []);
        if (!is_array($all) || empty($all['salesforce']) || !is_array($all['salesforce'])) {
            return $credentials;
        }
        $by_env = $all['salesforce'];
        $dyn = null;
        $key_str = (string) $env_id;
        $key_int = is_numeric($env_id) ? (string) intval($env_id) : $key_str;

        if (isset($by_env[$key_str]) && is_array($by_env[$key_str])) {
            $dyn = $by_env[$key_str];
        } elseif ($key_int !== $key_str && isset($by_env[$key_int]) && is_array($by_env[$key_int])) {
            $dyn = $by_env[$key_int];
        } elseif (count($by_env) === 1) {
            $dyn = reset($by_env);
            if (!is_array($dyn)) {
                $dyn = null;
            }
        } else {
            foreach ($by_env as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (!empty($block['operacao']) && !empty($block['automation_id'])) {
                    $dyn = $block;
                    break;
                }
            }
        }

        if (!is_array($dyn)) {
            return $credentials;
        }

        foreach (['operacao', 'automation_id'] as $f) {
            if (isset($dyn[$f]) && $dyn[$f] !== '' && $dyn[$f] !== null) {
                $credentials[$f] = $dyn[$f];
            }
        }
        // Opcional: credenciais Marketing Cloud por ambiente (disparo SF + job MKC)
        foreach (['mkc_client_id', 'mkc_client_secret', 'mkc_token_url', 'mkc_api_url'] as $mk) {
            if (!empty($dyn[$mk])) {
                $credentials[$mk] = $dyn[$mk];
            }
        }

        return $credentials;
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
     * Webhook Robbu/Invenio — eventos em tempo real.
     * Autenticação: X-Robbu-Token, Authorization: Bearer … ou ?token=… (mesmo valor salvo em acm_robbu_webhook_secret).
     * IPs Robbu (firewall): 104.41.15.44, 104.41.14.184, 104.41.13.132, etc.
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }

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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }

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

            // Não executar SELECT DISTINCT em tabelas/views grandes — filtro por texto livre (coluna + valor)
            $filter_options = [];

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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }

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
        if (!current_user_can('read')) {
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }
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
        $prefix = $this->resolve_envios_agendamento_id_prefix($provider, 'local');
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
     * Compara o código cliente id_carteira do registro com o da carteira escolhida na campanha.
     * Normaliza para string (trim) para não falhar com int do CSV vs string do banco em ===.
     */
    private function id_carteira_matches_campaign_selection($record_id_carteira, $campaign_id_carteira): bool
    {
        if ($campaign_id_carteira === '' || $campaign_id_carteira === null) {
            return false;
        }
        return trim((string) $record_id_carteira) === trim((string) $campaign_id_carteira);
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
        if (!current_user_can('read')) { wp_send_json_error('Permissão negada.'); return; }

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
                    'label' => $table_name,
                    'records' => $count_formatted,
                    'origem' => 'mysql',
                ];
            }
        }

        if (class_exists('PC_SqlServer_Connector') && PC_SqlServer_Connector::is_enabled()) {
            try {
                $seen = [];
                foreach ($bases as $b) {
                    $id = isset($b['id']) ? strtolower((string) $b['id']) : '';
                    if ($id !== '') {
                        $seen[$id] = true;
                    }
                }
                $mssql_views = PC_SqlServer_Connector::list_vw_base_view_names();
                if (!is_array($mssql_views)) {
                    $mssql_views = [];
                }
                foreach ($mssql_views as $vn) {
                    $key = strtolower($vn);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $bases[] = [
                        'id' => $vn,
                        'name' => $vn,
                        'label' => '[MSSQL] ' . $vn,
                        'records' => '—',
                        'origem' => 'mssql',
                    ];
                }
            } catch (Exception $e) {
                error_log('[Painel Campanhas] handle_get_available_bases MSSQL: ' . $e->getMessage());
            }
        }

        wp_send_json_success($bases);
    }

    /**
     * Telemetria: TB_SAUDE_LINHAS (SQL Server).
     */
    public function handle_get_line_health()
    {
        check_ajax_referer('pc_nonce', 'nonce');
        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
            return;
        }
        if (!class_exists('PC_SqlServer_Connector') || !PC_SqlServer_Connector::is_enabled()) {
            wp_send_json_success(['rows' => [], 'configured' => false]);
            return;
        }
        if (class_exists('PC_Wp_Mssql_Bridge')) {
            PC_Wp_Mssql_Bridge::on_operational_health_page_visit();
            $snap = PC_Wp_Mssql_Bridge::fetch_snapshot_rows_for_api(200);
            if (!empty($snap)) {
                wp_send_json_success([
                    'rows' => $snap,
                    'configured' => true,
                    'source' => 'pc_line_health_snapshot',
                ]);
                return;
            }
        }
        $rows = PC_SqlServer_Connector::fetch_line_health_rows(200);
        wp_send_json_success(['rows' => $rows, 'configured' => true, 'source' => 'tb_saude_linhas_legacy']);
    }

    /**
     * Opções MSSQL para administradores (senha nunca retornada em claro).
     */
    public function handle_get_mssql_settings()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado.']);
            return;
        }
        if (!check_ajax_referer('pc_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sessão expirada. Recarregue a página.']);
            return;
        }

        $pwd = (string) get_option('pc_mssql_password', '');
        $has_pwd = $pwd !== '';

        $en = get_option('pc_mssql_enabled', '0');
        $enabled_str = ($en === '1' || $en === 1 || $en === true) ? '1' : '0';

        wp_send_json_success([
            'pc_mssql_enabled' => $enabled_str,
            'pc_mssql_host' => (string) get_option('pc_mssql_host', ''),
            'pc_mssql_port' => (string) get_option('pc_mssql_port', '1433'),
            'pc_mssql_database' => (string) get_option('pc_mssql_database', ''),
            'pc_mssql_user' => (string) get_option('pc_mssql_user', ''),
            'pc_mssql_password_masked' => $has_pwd ? '********' : '',
            'has_saved_password' => $has_pwd,
            'pc_mssql_views_info_schema_catalog' => (string) get_option('pc_mssql_views_info_schema_catalog', ''),
            'pc_mssql_linked_four_part_prefix' => (string) get_option('pc_mssql_linked_four_part_prefix', ''),
            'wp_config_override' => [
                'enabled' => defined('PC_MSSQL_ENABLED'),
                'host' => defined('PC_MSSQL_HOST'),
                'port' => defined('PC_MSSQL_PORT'),
                'database' => defined('PC_MSSQL_DATABASE'),
                'user' => defined('PC_MSSQL_USER'),
                'password' => defined('PC_MSSQL_PASSWORD'),
                'views_catalog' => defined('PC_MSSQL_VIEWS_INFO_SCHEMA_CATALOG') || defined('PC_MSSQL_VIEWS_INFO_SCHEMA_PREFIX'),
                'linked_prefix' => defined('PC_MSSQL_LINKED_FOUR_PART_PREFIX'),
            ],
        ]);
    }

    /**
     * Salva opções MSSQL (apenas manage_options). Senha: só atualiza se enviar valor novo não vazio e diferente do mascaramento.
     */
    public function handle_save_mssql_settings()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado.']);
            return;
        }
        if (!check_ajax_referer('pc_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sessão expirada. Recarregue a página.']);
            return;
        }

        $enabled_raw = isset($_POST['pc_mssql_enabled']) ? sanitize_text_field(wp_unslash((string) $_POST['pc_mssql_enabled'])) : '0';
        $enabled = ($enabled_raw === '1' || $enabled_raw === 'true' || $enabled_raw === 'on') ? '1' : '0';
        update_option('pc_mssql_enabled', $enabled);

        update_option('pc_mssql_host', sanitize_text_field(wp_unslash((string) ($_POST['pc_mssql_host'] ?? ''))));
        update_option('pc_mssql_database', sanitize_text_field(wp_unslash((string) ($_POST['pc_mssql_database'] ?? ''))));
        update_option('pc_mssql_user', sanitize_text_field(wp_unslash((string) ($_POST['pc_mssql_user'] ?? ''))));

        $port = preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['pc_mssql_port'] ?? '1433'));
        if ($port === '') {
            $port = '1433';
        }
        if (strlen($port) > 5) {
            $port = substr($port, 0, 5);
        }
        update_option('pc_mssql_port', $port);

        $cat = trim((string) wp_unslash($_POST['pc_mssql_views_info_schema_catalog'] ?? ''));
        if ($cat !== '' && !preg_match('/^(\[[A-Za-z0-9_]+\])(\.\[[A-Za-z0-9_]+\])+$/', $cat)) {
            wp_send_json_error(['message' => 'Prefixo do catálogo (Info Schema) inválido. Use o formato [SERVIDOR].[BANCO].']);
            return;
        }
        update_option('pc_mssql_views_info_schema_catalog', $cat);

        $linkp = trim((string) wp_unslash($_POST['pc_mssql_linked_four_part_prefix'] ?? ''));
        if ($linkp !== '' && !preg_match('/^(\[[A-Za-z0-9_]+\])(\.\[[A-Za-z0-9_]+\])+$/', $linkp)) {
            wp_send_json_error(['message' => 'Prefixo four-part para leitura inválido. Ex.: [SRV27].[DB_DIGITAL].[dbo]']);
            return;
        }
        update_option('pc_mssql_linked_four_part_prefix', $linkp);

        $pwd_in = isset($_POST['pc_mssql_password']) ? (string) wp_unslash($_POST['pc_mssql_password']) : '';
        $pwd_in = trim($pwd_in);
        if ($pwd_in !== '' && $pwd_in !== '********') {
            update_option('pc_mssql_password', $pwd_in);
        }

        if (class_exists('PC_SqlServer_Connector')) {
            PC_SqlServer_Connector::reset_static_connections();
        }
        if (class_exists('PC_Wp_Mssql_Bridge')) {
            PC_Wp_Mssql_Bridge::reset_schema_cache();
        }

        wp_send_json_success(['message' => 'Configurações MSSQL salvas.']);
    }

    /**
     * Admin: cria tabelas de espelho no MSSQL (se faltarem) e roda espelho + snapshot na hora.
     */
    public function handle_mssql_operational_sync_now()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado.']);
            return;
        }
        if (!check_ajax_referer('pc_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sessão expirada. Recarregue a página.']);
            return;
        }
        if (!class_exists('PC_Wp_Mssql_Bridge')) {
            wp_send_json_error(['message' => 'Ponte MSSQL não está disponível.']);
            return;
        }
        if (class_exists('PC_SqlServer_Connector')) {
            PC_SqlServer_Connector::reset_static_connections();
        }
        PC_Wp_Mssql_Bridge::reset_schema_cache();
        $result = PC_Wp_Mssql_Bridge::run_daily_operational_job();
        if (empty($result['ok'])) {
            $reason = (string) ($result['reason'] ?? '');
            $msg = $reason === 'disabled'
                ? 'MSSQL está desativado nas opções ou o PHP não tem pdo_sqlsrv.'
                : 'Não foi possível conectar ao SQL Server ou executar o DDL das tabelas de espelho. Verifique host, banco, usuário, senha e se o login tem permissão CREATE TABLE no banco alvo.';
            wp_send_json_error(['message' => $msg]);
            return;
        }
        $payload = $result;
        $payload['message'] = 'Tabelas verificadas/criadas e sincronização concluída.';
        if (empty($result['snapshot_ok'])) {
            $payload['warning'] = 'O espelho foi atualizado, mas o snapshot de saúde não pôde ser recalculado (confira se existe wp_envios_pendentes com colunas fornecedor e status).';
        }
        wp_send_json_success($payload);
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

            if (!current_user_can('read')) {
                wp_send_json_error(['message' => 'Permissão negada.'], 403);
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

            $current_user_id = get_current_user_id();
            $is_admin = $this->is_pc_dashboard_admin();

            $scope_where = '';
            if (!$is_admin) {
                $scope_where = $wpdb->prepare(' AND current_user_id = %d', $current_user_id);
            }

            // Total de campanhas únicas (agrupadas por agendamento_id, fornecedor)
            $total_campanhas = $wpdb->get_var("
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE 1=1{$scope_where}
            ");
            $total_campanhas = $total_campanhas ? intval($total_campanhas) : 0;

            // Campanhas pendentes de aprovação
            $campanhas_pendentes = $wpdb->get_var(
                $wpdb->prepare(
                    "
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE status = %s{$scope_where}
            ",
                    'pendente_aprovacao'
                )
            );
            $campanhas_pendentes = $campanhas_pendentes ? intval($campanhas_pendentes) : 0;

            // Campanhas enviadas
            $campanhas_enviadas = $wpdb->get_var(
                $wpdb->prepare(
                    "
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE status = %s{$scope_where}
            ",
                    'enviado'
                )
            );
            $campanhas_enviadas = $campanhas_enviadas ? intval($campanhas_enviadas) : 0;

            // Campanhas criadas hoje
            $campanhas_hoje = $wpdb->get_var(
                $wpdb->prepare(
                    "
                SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', COALESCE(fornecedor, '')))
                FROM {$envios_table}
                WHERE DATE(data_cadastro) = %s{$scope_where}
            ",
                    current_time('Y-m-d')
                )
            );
            $campanhas_hoje = $campanhas_hoje ? intval($campanhas_hoje) : 0;

            // Últimas 5 campanhas — mesmo agregado que pc_get_campanhas (nome_carteira, progresso, status UI)
            $sql_waiting = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('pendente','pendente_aprovacao','agendado_mkc','processando')";
            $sql_error = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('negado','erro','erro_envio','erro_credenciais','erro_validacao','mkc_erro','erro_inicio')";
            $sql_sent = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('enviado','mkc_executado')";

            $user_where_recent = '';
            $recent_params = [];
            if (!$is_admin) {
                $user_where_recent = ' AND t1.current_user_id = %d';
                $recent_params[] = $current_user_id;
            }

            $recent_sql = "
                SELECT
                    t1.agendamento_id,
                    MAX(t1.idgis_ambiente) AS idgis_ambiente,
                    t1.fornecedor AS provider,
                    MAX(t1.status) AS status,
                    MIN(t1.data_cadastro) AS data_cadastro,
                    COUNT(t1.id) AS total_messages,
                    SUM(CASE WHEN {$sql_waiting} THEN 0 ELSE 1 END) AS processed_messages,
                    SUM(CASE WHEN {$sql_error} THEN 1 ELSE 0 END) AS error_messages,
                    SUM(CASE WHEN {$sql_sent} THEN 1 ELSE 0 END) AS cnt_enviado,
                    COALESCE(MAX(u.display_name), 'Usuário Desconhecido') AS scheduled_by,
                    MAX(t1.motivo_cancelamento) AS motivo_cancelamento,
                    MAX(t1.cancelado_por) AS cancelado_por_id,
                    MAX(t1.id_carteira) AS id_carteira,
                    MAX(t1.nome_carteira) AS nome_carteira_denorm,
                    MAX(t1.nome_campanha) AS nome_campanha
                FROM `{$envios_table}` AS t1
                LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
                WHERE (t1.fornecedor IS NOT NULL AND TRIM(t1.fornecedor) != ''){$user_where_recent}
                GROUP BY t1.agendamento_id, t1.fornecedor
                ORDER BY MIN(t1.data_cadastro) DESC
                LIMIT 5
            ";

            if (!empty($recent_params)) {
                $recent_sql = $wpdb->prepare($recent_sql, $recent_params);
            }

            $recent_rows = $wpdb->get_results($recent_sql, ARRAY_A);

            $formatted_campaigns = array_map(function ($camp) {
                return $this->format_campanha_envios_row($camp);
            }, $recent_rows ?: []);

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

        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error(['message' => 'Permissão negada.'], 403);
            return;
        }

        $this->maybe_add_envios_cancel_columns();

        global $wpdb;
        $envios_table = $wpdb->prefix . 'envios_pendentes';
        $users_table = $wpdb->users;

        // Filtros
        $status_filter = sanitize_text_field($_POST['status'] ?? $_GET['status'] ?? '');
        $fornecedor_filter = sanitize_text_field($_POST['fornecedor'] ?? $_GET['fornecedor'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? $_GET['search'] ?? '');
        $current_user_id = get_current_user_id();
        $is_admin = $this->is_pc_dashboard_admin();

        // Estados ainda na fila / aguardando worker (não contam como "processadas" para a barra)
        $sql_waiting = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('pendente','pendente_aprovacao','agendado_mkc','processando')";
        $sql_error = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('negado','erro','erro_envio','erro_credenciais','erro_validacao','mkc_erro','erro_inicio')";
        $sql_sent = "LOWER(TRIM(COALESCE(t1.status, ''))) IN ('enviado','mkc_executado')";

        // Query base — métricas por agendamento_id + fornecedor (uma linha = um destinatário)
        $user_where = '';
        $params = [];
        if (!$is_admin) {
            $user_where = ' AND t1.current_user_id = %d';
            $params[] = $current_user_id;
        }

        $query = "
            SELECT
                t1.agendamento_id,
                MAX(t1.idgis_ambiente) AS idgis_ambiente,
                t1.fornecedor AS provider,
                MAX(t1.status) AS status,
                MIN(t1.data_cadastro) AS data_cadastro,
                COUNT(t1.id) AS total_messages,
                SUM(CASE WHEN {$sql_waiting} THEN 0 ELSE 1 END) AS processed_messages,
                SUM(CASE WHEN {$sql_error} THEN 1 ELSE 0 END) AS error_messages,
                SUM(CASE WHEN {$sql_sent} THEN 1 ELSE 0 END) AS cnt_enviado,
                COALESCE(MAX(u.display_name), 'Usuário Desconhecido') AS scheduled_by,
                MAX(t1.motivo_cancelamento) AS motivo_cancelamento,
                MAX(t1.cancelado_por) AS cancelado_por_id,
                MAX(t1.id_carteira) AS id_carteira,
                MAX(t1.nome_carteira) AS nome_carteira_denorm,
                MAX(t1.nome_campanha) AS nome_campanha
            FROM `{$envios_table}` AS t1
            LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
            WHERE 1=1{$user_where}
        ";

        // Aplica filtros (status no front usa chaves UI: pending, scheduled, …)
        if ($status_filter) {
            $status_sql_map = [
                'pending' => ['pendente_aprovacao'],
                'scheduled' => ['pendente', 'agendado_mkc', 'processando'],
                'sent' => ['enviado'],
                'denied' => ['negado', 'erro', 'erro_envio', 'erro_credenciais', 'erro_validacao', 'mkc_erro'],
                'cancelled' => ['cancelada'],
            ];
            if (isset($status_sql_map[$status_filter])) {
                $placeholders = implode(',', array_fill(0, count($status_sql_map[$status_filter]), '%s'));
                $query .= " AND t1.status IN ($placeholders)";
                foreach ($status_sql_map[$status_filter] as $st) {
                    $params[] = $st;
                }
            } else {
                $query .= " AND t1.status = %s";
                $params[] = str_replace('-', '_', $status_filter);
            }
        }

        if ($fornecedor_filter) {
            $query .= " AND t1.fornecedor = %s";
            $params[] = $fornecedor_filter;
        }

        if ($search) {
            $query .= " AND (t1.agendamento_id LIKE %s OR COALESCE(t1.nome_campanha, '') LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $query = $wpdb->prepare($query, $params);

        $query .= "
            GROUP BY t1.agendamento_id, t1.fornecedor
            ORDER BY MIN(t1.data_cadastro) DESC
        ";

        $campanhas = $wpdb->get_results($query, ARRAY_A);

        $formatted = array_map(function ($camp) {
            return $this->format_campanha_envios_row($camp);
        }, $campanhas);

        wp_send_json_success($formatted);
    }

    /**
     * Cancela campanha (motivo obrigatório). Grava motivo_cancelamento e cancelado_por em envios_pendentes.
     * Admin (manage_options): qualquer campanha; pode cancelar também em processamento.
     * Demais usuários: só linhas com current_user_id = criador; não cancelam em status processando.
     * Aceita motivo via POST motivo ou motivo_cancelamento (pc_cancel_campaign).
     */
    public function handle_cancel_campanha()
    {
        check_ajax_referer('pc_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Sessão inválida'], 401);
            return;
        }

        $this->maybe_add_envios_cancel_columns();

        global $wpdb;
        $table = $wpdb->prefix . 'envios_pendentes';

        $agendamento_id = sanitize_text_field($_POST['agendamento_id'] ?? '');
        $fornecedor = sanitize_text_field($_POST['fornecedor'] ?? '');
        $motivo_raw = $_POST['motivo_cancelamento'] ?? $_POST['motivo'] ?? '';
        $motivo = sanitize_textarea_field(is_string($motivo_raw) ? wp_unslash($motivo_raw) : '');

        if ($agendamento_id === '' || $fornecedor === '') {
            wp_send_json_error(['message' => 'Informe o identificador da campanha e o fornecedor.'], 400);
            return;
        }

        if (strlen(trim($motivo)) < 3) {
            wp_send_json_error(['message' => 'Informe o motivo do cancelamento (mínimo 3 caracteres).'], 400);
            return;
        }

        $uid = get_current_user_id();
        $is_admin = current_user_can('manage_options');

        $allowed_owner = ['pendente_aprovacao', 'pendente', 'agendado_mkc'];
        $allowed_admin = array_merge($allowed_owner, ['processando']);

        $check_sql = "SELECT id, status, current_user_id FROM `{$table}` WHERE agendamento_id = %s AND fornecedor = %s";
        $check_params = [$agendamento_id, $fornecedor];
        if (!$is_admin) {
            $check_sql .= ' AND current_user_id = %d';
            $check_params[] = $uid;
        }
        $rows = $wpdb->get_results($wpdb->prepare($check_sql, $check_params), ARRAY_A);

        if (empty($rows)) {
            wp_send_json_error(
                ['message' => 'Campanha não encontrada ou você não tem permissão para cancelá-la (apenas o criador ou um administrador).'],
                403
            );
            return;
        }

        $allowed = $is_admin ? $allowed_admin : $allowed_owner;
        foreach ($rows as $row) {
            $st = strtolower(trim($row['status'] ?? ''));
            if (!in_array($st, $allowed, true)) {
                wp_send_json_error(
                    [
                        'message' => $is_admin
                            ? 'Não é possível cancelar no status atual: ' . esc_html($row['status'] ?? '')
                            : 'Só é possível cancelar campanhas pendentes, em aprovação ou agendadas (não em processamento ou finalizadas). Status atual: ' . esc_html($row['status'] ?? ''),
                    ],
                    400
                );
                return;
            }
        }

        $update = [
            'status' => 'cancelada',
            'motivo_cancelamento' => $motivo,
            'cancelado_por' => $uid,
        ];
        $where = ['agendamento_id' => $agendamento_id, 'fornecedor' => $fornecedor];
        $where_format = ['%s', '%s'];
        if (!$is_admin) {
            $where['current_user_id'] = $uid;
            $where_format[] = '%d';
        }

        $updated = $wpdb->update(
            $table,
            $update,
            $where,
            ['%s', '%s', '%d'],
            $where_format
        );

        if ($updated === false) {
            wp_send_json_error('Erro ao atualizar o banco: ' . $wpdb->last_error);
            return;
        }

        wp_send_json_success([
            'message' => 'Campanha cancelada com sucesso.',
            'agendamento_id' => $agendamento_id,
        ]);
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
                $raw_id = $tpl['templateId'] ?? $tpl['id'] ?? $tpl['name'] ?? '';
                $num_id = (is_numeric($raw_id) && (int) $raw_id > 0) ? (int) $raw_id : null;
                if ($num_id === null && is_string($raw_id) && preg_match('/\d+/', $raw_id, $m)) {
                    $num_id = (int) $m[0];
                }
                $body_text = isset($tpl['body']) && is_string($tpl['body']) ? $tpl['body'] : '';
                $content_text = isset($tpl['content']) && is_string($tpl['content']) ? $tpl['content'] : '';
                $text_for_ui = $content_text !== '' ? $content_text : $body_text;

                $vc_raw = $tpl['variableComponents'] ?? $tpl['variable_components'] ?? [];
                if (is_string($vc_raw)) {
                    $vc_dec = json_decode($vc_raw, true);
                    $vc_raw = is_array($vc_dec) ? $vc_dec : [];
                }
                if (!is_array($vc_raw)) {
                    $vc_raw = [];
                }

                $all_templates[] = [
                    'id' => $tpl['id'] ?? $tpl['name'] ?? '',
                    'templateId' => $num_id,
                    'name' => $tpl['name'] ?? $tpl['id'] ?? '',
                    'body' => $body_text,
                    'content' => $text_for_ui,
                    'status' => $tpl['status'] ?? '',
                    'category' => $tpl['category'] ?? '',
                    'language' => $tpl['language'] ?? 'pt_BR',
                    'components' => $tpl['components'] ?? [],
                    'provider' => 'Gosac Oficial',
                    'id_ambient' => $id_ambient,
                    'templateName' => $tpl['name'] ?? $tpl['id'] ?? '',
                    'idRuler' => $tpl['idRuler'] ?? $id_ruler,
                    'connectionId' => $conn_id,
                    'variableComponents' => $vc_raw,
                    'carteira_nome' => $pair['nome'] ?? '',
                ];
            }
        }

        wp_send_json_success($all_templates);
    }

    /**
     * Normaliza a resposta da API GOSAC Oficial (listagem de templates).
     * Formatos suportados:
     * - { "data": [ { "templates": [ {...} ] } ] }  (oficial)
     * - { "data": { "templates": [ {...} ] } }
     * - { "data": [ {...template...} ] } (lista direta)
     * - { "templates": [...] } na raiz
     */
    private function extract_gosac_templates($templates_data)
    {
        $out = [];
        if (!is_array($templates_data)) {
            return $out;
        }

        $root = isset($templates_data['data']) ? $templates_data['data'] : $templates_data;
        if (!is_array($root)) {
            return $out;
        }

        $candidates = [];

        // Objeto único: data = { "templates": [ ... ] }
        if (isset($root['templates']) && is_array($root['templates'])) {
            $candidates[] = $root;
        } else {
            // Lista de blocos ou lista direta de templates
            foreach ($root as $env) {
                if (is_array($env) && !isset($env['error'])) {
                    $candidates[] = $env;
                }
            }
        }

        foreach ($candidates as $env) {
            if (!is_array($env) || isset($env['error'])) {
                continue;
            }
            $tpls = [];
            if (isset($env['templates']) && is_array($env['templates'])) {
                $tpls = $env['templates'];
            } elseif ((isset($env['id']) || isset($env['name'])) && !isset($env['templates'])) {
                // Um único template no lugar de um wrapper
                $tpls = [$env];
            }
            $id_ruler_env = $env['idRuler'] ?? ($env['id_ruler'] ?? '');
            foreach ($tpls as $t) {
                if (!is_array($t) || (!isset($t['id']) && !isset($t['name']))) {
                    continue;
                }
                if (!isset($t['idRuler']) || $t['idRuler'] === '' || $t['idRuler'] === null) {
                    $t['idRuler'] = $id_ruler_env;
                }
                $out[] = $t;
            }
        }

        return $out;
    }

    public function handle_get_all_connections_health()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        if (class_exists('PC_Wp_Mssql_Bridge')) {
            PC_Wp_Mssql_Bridge::on_operational_health_page_visit();
        }

        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';
        $carteiras = $wpdb->get_results("SELECT id, nome, id_carteira, id_ruler FROM $table_carteiras WHERE ativo = 1", ARRAY_A);

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
                $id_amb = trim($wallet['id_carteira'] ?? '');
                $id_ruler = trim($wallet['id_ruler'] ?? '');
                if (empty($id_amb)) continue;
                $key = $id_amb . '|' . $id_ruler;
                if (!isset($credentials['gosac_oficial'][$key])) {
                    $credentials['gosac_oficial'][$key] = [
                        'url' => $gosac_url,
                        'token' => $gosac_token,
                        'id_ruler' => $id_ruler,
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
            foreach ($envs as $env_key => $data) {
                $env_key = trim($env_key);
                if (empty($env_key)) continue;

                // GOSAC usa chave "id_carteira|id_ruler"; outros usam só id_ambient
                $id_ambient = $provider === 'gosac_oficial' && strpos($env_key, '|') !== false
                    ? explode('|', $env_key, 2)[0] : $env_key;
                $id_ambient = trim($id_ambient);
                if (empty($id_ambient)) continue;

                $cache_key = $provider . '_' . $env_key;

                if (!isset($fetched_envs[$cache_key])) {
                    $provider_conns = [];
                    if ($provider === 'gosac_oficial') {
                        $id_ruler = isset($data['id_ruler']) ? trim($data['id_ruler']) : '';
                        if ($id_ruler === '' && strpos($env_key, '|') !== false) {
                            $parts = explode('|', $env_key, 2);
                            $id_ruler = trim($parts[1] ?? '');
                        }
                        $params = ['idgis' => $id_ambient, 'idAmbient' => $id_ambient];
                        if (!empty($id_ruler)) {
                            $params['ruler'] = $id_ruler;
                            $params['idRuler'] = $id_ruler;
                        }
                        $url = rtrim($data['url'], '/') . '/connections/official?' . http_build_query($params);
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

    /**
     * JWT global da Making (acm_static_credentials / making_jwt_token). Phone Number ID é por carteira em acm_provider_credentials.
     *
     * @return array{jwt: string}
     */
    private function get_making_global_config()
    {
        $st = get_option('acm_static_credentials', []);
        if (!is_array($st)) {
            $st = [];
        }
        $jwt = trim((string) get_option('making_jwt_token', ''));
        if ($jwt === '') {
            $jwt = trim((string) ($st['making_jwt_token'] ?? ''));
        }

        return ['jwt' => $jwt];
    }

    /**
     * Normaliza respostas da Making (list_api) para [{id, name}].
     *
     * @param mixed $decoded
     * @param string|null $list_kind 'team' | 'cost' | null (genérico)
     * @return array<int, array{id: string, name: string}>
     */
    private function normalize_making_id_name_list($decoded, $list_kind = null)
    {
        if (!is_array($decoded)) {
            return [];
        }
        $rows = [];
        foreach (['data', 'teams', 'team', 'costs', 'cost', 'cost_centers', 'items', 'list', 'models'] as $k) {
            if (isset($decoded[$k]) && is_array($decoded[$k])) {
                $rows = $decoded[$k];
                break;
            }
        }
        if ($rows === [] && function_exists('wp_is_numeric_array') && wp_is_numeric_array($decoded)) {
            $rows = $decoded;
        } elseif ($rows === [] && $decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            $rows = $decoded;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = $row['id'] ?? $row['team_id'] ?? $row['cost_center_id'] ?? $row['codigo'] ?? null;
            if ($rid === null || $rid === '') {
                continue;
            }
            $rid_str = (string) $rid;

            if ($list_kind === 'cost') {
                if (isset($row['company_name']) && trim((string) $row['company_name']) !== '') {
                    $name = trim((string) $row['company_name']) . ' (ID: ' . $rid_str . ')';
                } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
                    $name = trim((string) $row['name']);
                } else {
                    $name = 'CC ' . $rid_str;
                }
            } elseif ($list_kind === 'team') {
                if (isset($row['team_name']) && trim((string) $row['team_name']) !== '') {
                    $name = trim((string) $row['team_name']) . ' (ID: ' . $rid_str . ')';
                } else {
                    $name = 'Equipe ' . $rid_str;
                }
            } else {
                $name = trim((string) ($row['name'] ?? $row['nome'] ?? $row['title'] ?? $row['description'] ?? ''));
                if ($name === '') {
                    $name = 'ID ' . $rid_str;
                }
            }

            $out[] = [
                'id' => $rid_str,
                'name' => $name,
            ];
        }

        return $out;
    }

    /**
     * GET team/list_api ou cost/list_api na API Making.
     *
     * @param string $path ex.: team/list_api
     * @param string|null $list_kind 'team' | 'cost' — rótulos amigáveis (team_name / company_name)
     * @return array|WP_Error
     */
    private function fetch_making_oficial_list_api($path, $bearer_raw, $list_kind = null)
    {
        $raw = trim((string) $bearer_raw);
        if ($raw === '') {
            return new WP_Error('making_token_empty', 'Token JWT da Making ausente');
        }
        $auth = (stripos($raw, 'Bearer ') === 0) ? $raw : ('Bearer ' . $raw);
        $path = ltrim((string) $path, '/');
        $url = 'https://campanhas.makingpublicidade.com.br/' . $path;
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $auth,
                'Accept' => 'application/json',
            ],
            'timeout' => 25,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401) {
            return new WP_Error('making_unauthorized', 'Token JWT da Making inválido ou expirado');
        }
        if ($code < 200 || $code >= 300) {
            $snippet = substr((string) wp_remote_retrieve_body($response), 0, 400);

            return new WP_Error('making_http', 'HTTP ' . $code . ' — ' . $snippet);
        }
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $list = $this->normalize_making_id_name_list($decoded, $list_kind);
        if ($list === []) {
            error_log('🔴 [Making Oficial] ' . $path . ' resposta vazia ou formato inesperado: ' . substr((string) $body, 0, 500));

            return new WP_Error('making_parse', 'Resposta inválida ou lista vazia da API Making');
        }

        return $list;
    }

    public function handle_get_making_teams()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado']);
            return;
        }
        check_ajax_referer('pc_nonce', 'nonce');
        $cfg = $this->get_making_global_config();
        if ($cfg['jwt'] === '') {
            wp_send_json_error(['message' => 'Configure o JWT da Making nas credenciais estáticas (Making Oficial).']);
            return;
        }
        $list = $this->fetch_making_oficial_list_api('team/list_api', $cfg['jwt'], 'team');
        if (is_wp_error($list)) {
            wp_send_json_error(['message' => $list->get_error_message()]);
            return;
        }
        wp_send_json_success($list);
    }

    public function handle_get_making_cost_centers()
    {
        $this->pc_forbid_subscriber_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado']);
            return;
        }
        check_ajax_referer('pc_nonce', 'nonce');
        $cfg = $this->get_making_global_config();
        if ($cfg['jwt'] === '') {
            wp_send_json_error(['message' => 'Configure o JWT da Making nas credenciais estáticas (Making Oficial).']);
            return;
        }
        $list = $this->fetch_making_oficial_list_api('cost/list_api', $cfg['jwt'], 'cost');
        if (is_wp_error($list)) {
            wp_send_json_error(['message' => $list->get_error_message()]);
            return;
        }
        wp_send_json_success($list);
    }

    /**
     * Lista modelos Making (WhatsApp Oficial) — GET /models/list_api.
     * Usa JWT global (acm_static_credentials / making_jwt_token).
     *
     * @param string $bearer_raw Token (com ou sem prefixo Bearer)
     * @return array|WP_Error Lista de itens para o painel ou erro
     */
    private function fetch_making_oficial_models_list($bearer_raw)
    {
        $raw = trim((string) $bearer_raw);
        if ($raw === '') {
            return new WP_Error('making_token_empty', 'Token ausente');
        }
        $auth = (stripos($raw, 'Bearer ') === 0) ? $raw : ('Bearer ' . $raw);
        $url = 'https://campanhas.makingpublicidade.com.br/models/list_api';
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $auth,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401) {
            return new WP_Error('making_unauthorized', 'Token inválido');
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('making_http', 'HTTP ' . $code);
        }
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['success']) || !isset($decoded['models']) || !is_array($decoded['models'])) {
            error_log('🔴 [Making Oficial] list_api resposta inesperada: ' . substr((string) $body, 0, 400));

            return new WP_Error('making_parse', 'Resposta inválida da API Making');
        }
        $out = [];
        foreach ($decoded['models'] as $model) {
            if (!is_array($model)) {
                continue;
            }
            $mid = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
            $mname = isset($model['name']) ? trim(sanitize_text_field((string) $model['name'])) : '';
            if ($mname === '') {
                continue;
            }
            $slug = isset($model['slug']) ? trim(sanitize_text_field((string) $model['slug'])) : '';
            $send_meta = $slug !== '' ? $slug : $mname;
            $out[] = [
                'id' => ($mid !== '' ? $mid : $mname),
                'name' => $mname,
                'send_meta_template' => $send_meta,
                'templateName' => $mname,
                'provider' => 'Making Oficial',
            ];
        }

        return $out;
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
                            $body_text = isset($template['body']) && is_string($template['body']) ? $template['body'] : '';
                            $content_text = isset($template['content']) && is_string($template['content']) ? $template['content'] : '';
                            $text_for_ui = $content_text !== '' ? $content_text : $body_text;
                            $vc_raw = $template['variableComponents'] ?? $template['variable_components'] ?? [];
                            if (is_string($vc_raw)) {
                                $vc_dec = json_decode($vc_raw, true);
                                $vc_raw = is_array($vc_dec) ? $vc_dec : [];
                            }
                            if (!is_array($vc_raw)) {
                                $vc_raw = [];
                            }
                            $conn_id = $template['connectionId'] ?? null;
                            $raw_id = $template['templateId'] ?? $template['id'] ?? $template['name'] ?? '';
                            $num_id = (is_numeric($raw_id) && (int) $raw_id > 0) ? (int) $raw_id : null;
                            if ($num_id === null && is_string($raw_id) && preg_match('/\d+/', $raw_id, $m)) {
                                $num_id = (int) $m[0];
                            }
                            $all_templates[] = [
                                'id' => $template['id'] ?? $template['name'] ?? '',
                                'templateId' => $num_id,
                                'name' => $template['name'] ?? $template['id'] ?? '',
                                'body' => $body_text,
                                'content' => $text_for_ui,
                                'category' => $template['category'] ?? '',
                                'language' => $template['language'] ?? 'pt_BR',
                                'status' => $template['status'] ?? '',
                                'components' => $template['components'] ?? [],
                                'provider' => 'Gosac Oficial',
                                'id_ambient' => $id_ambient,
                                'idRuler' => $template['idRuler'] ?? '',
                                'connectionId' => $conn_id,
                                'variableComponents' => $vc_raw,
                            ];
                        }
                    } else {
                        error_log('🔴 [Gosac] getTemplatesByWallet falhou: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
                    }
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
                                if (!is_array($tpl)) {
                                    continue;
                                }
                                $row = $this->map_noah_message_template_api_to_panel($tpl);
                                $row['provider'] = 'Noah Oficial';
                                $row['id_ambient'] = $id_ambient;
                                $all_templates[] = $row;
                            }
                        }
                    } else {
                        error_log('🔴 [Noah] getTemplatesByWallet falhou: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
                    }
                }
            }
            // robbu_oficial: templates via pc_get_robbu_oficial_templates (não dependem da carteira)
        }

        // Making Oficial: JWT global — não depende de acm_provider_credentials[making_oficial][carteira].
        $mk_cfg_tpl = $this->get_making_global_config();
        if ($mk_cfg_tpl['jwt'] !== '') {
            $mk_list_global = $this->fetch_making_oficial_models_list($mk_cfg_tpl['jwt']);
            if (is_wp_error($mk_list_global)) {
                $ecg = $mk_list_global->get_error_code();
                if ($ecg === 'making_unauthorized' || $ecg === 'making_http') {
                    wp_send_json_error(['message' => 'Token da Making inválido ou expirado. Verifique o JWT global no API Manager.']);
                    return;
                }
                error_log('🔴 [Making Oficial] getTemplatesByWallet (global): ' . $mk_list_global->get_error_message());
            } elseif (is_array($mk_list_global)) {
                foreach ($mk_list_global as $tpl_mk) {
                    if (!is_array($tpl_mk)) {
                        continue;
                    }
                    $tpl_mk['id_ambient'] = $id_ambient;
                    $all_templates[] = $tpl_mk;
                }
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
        $this->pc_forbid_subscriber_ajax();
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

        $id_ambient = sanitize_text_field($_POST['id_ambient'] ?? $_GET['id_ambient'] ?? '');
        $id_ruler = sanitize_text_field($_POST['id_ruler'] ?? $_GET['id_ruler'] ?? '');
        $carteira_id = intval($_POST['carteira'] ?? $_GET['carteira'] ?? 0);

        if ($carteira_id > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'pc_carteiras_v2';
            $carteira = $wpdb->get_row($wpdb->prepare(
                "SELECT id_carteira, id_ruler FROM $table WHERE id = %d AND ativo = 1 LIMIT 1",
                $carteira_id
            ), ARRAY_A);
            if ($carteira) {
                $id_ambient = $id_ambient ?: trim($carteira['id_carteira'] ?? '');
                $id_ruler = $id_ruler ?: trim($carteira['id_ruler'] ?? '');
            }
        }

        if (empty($id_ambient)) {
            wp_send_json_success([]);
            return;
        }

        // API GOSAC: parâmetros idgis e ruler (ou idAmbient e idRuler - envia ambos para compatibilidade)
        $params = ['idgis' => $id_ambient, 'idAmbient' => $id_ambient];
        if (!empty($id_ruler)) {
            $params['ruler'] = $id_ruler;
            $params['idRuler'] = $id_ruler;
        }
        $url = rtrim($gosac_url, '/') . '/connections/official?' . http_build_query($params);

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
            error_log("🔴 [Gosac Oficial] erro ao buscar conexões: " . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
            wp_send_json_success([]);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $connections_data = json_decode($body, true);
        $all_connections = [];

        if (is_array($connections_data)) {
            $raw = isset($connections_data['data']) ? $connections_data['data'] : $connections_data;
            if (!is_array($raw)) {
                $raw = [];
            }
            foreach ($raw as $item) {
                if (!is_array($item)) continue;
                if (!empty($item['connections']) && is_array($item['connections'])) {
                    foreach ($item['connections'] as $conn) {
                        $all_connections[] = [
                            'id' => $conn['id'] ?? '',
                            'name' => $conn['name'] ?? ($conn['phoneNumber'] ?? ''),
                            'status' => $conn['status'] ?? '',
                            'messagingLimit' => $conn['messagingLimit'] ?? '',
                            'accountRestriction' => $conn['accountRestriction'] ?? '',
                        ];
                    }
                } elseif (isset($item['id'])) {
                    $all_connections[] = [
                        'id' => $item['id'] ?? '',
                        'name' => $item['name'] ?? ($item['phoneNumber'] ?? ''),
                        'status' => $item['status'] ?? '',
                        'messagingLimit' => $item['messagingLimit'] ?? '',
                        'accountRestriction' => $item['accountRestriction'] ?? '',
                    ];
                }
            }
        }

        wp_send_json_success($all_connections);
    }

    /**
     * Normaliza um item da API NOAH message-templates (textHeader/textBody/textFooter) para o painel React.
     *
     * @param array<string,mixed> $tpl
     * @return array<string,mixed>
     */
    private function map_noah_message_template_api_to_panel(array $tpl)
    {
        $th = isset($tpl['textHeader']) ? (string) $tpl['textHeader'] : '';
        $tb = isset($tpl['textBody']) ? (string) $tpl['textBody'] : '';
        $tf = isset($tpl['textFooter']) ? (string) $tpl['textFooter'] : '';
        $preview_parts = array_filter([$th, $tb, $tf], static function ($s) {
            return $s !== '';
        });
        $content = implode("\n\n", $preview_parts);

        return [
            'id' => $tpl['id'] ?? $tpl['templateId'] ?? '',
            'templateId' => $tpl['templateId'] ?? $tpl['id'] ?? '',
            'templateName' => $tpl['name'] ?? $tpl['templateName'] ?? '',
            'name' => $tpl['name'] ?? $tpl['templateName'] ?? '',
            'language' => $tpl['language'] ?? 'pt_BR',
            'status' => $tpl['status'] ?? '',
            'category' => $tpl['category'] ?? '',
            'format' => $tpl['format'] ?? null,
            'channelId' => $tpl['channelId'] ?? '',
            'textHeader' => $tpl['textHeader'] ?? null,
            'textBody' => $tpl['textBody'] ?? null,
            'textFooter' => $tpl['textFooter'] ?? null,
            'buttons' => $tpl['buttons'] ?? null,
            'components' => isset($tpl['components']) && is_array($tpl['components']) ? $tpl['components'] : [],
            'content' => $content,
        ];
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
                        if (!is_array($tpl)) {
                            continue;
                        }
                        $row = $this->map_noah_message_template_api_to_panel($tpl);
                        $row['env_id'] = $env_id;
                        $row['provider'] = 'Noah Oficial';
                        $all_templates[] = $row;
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
        if (!current_user_can('read')) {
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
        $token_clean = $token_raw;
        if ($token_clean !== '') {
            for ($d = 0; $d < 6; $d++) {
                $next = preg_replace('/^(Bearer|INTEGRATION)\s+/i', '', $token_clean);
                $next = trim((string) $next);
                if ($next === $token_clean) {
                    break;
                }
                $token_clean = $next;
            }
        }
        $auth_header = $token_clean !== '' ? 'Bearer ' . $token_clean : '';

        $url = $base_url . '/channels';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $auth_header,
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
        if (!current_user_can('read')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        check_ajax_referer('pc_nonce', 'nonce');

        $want_verbose_otima = isset($_POST['verbose_otima_debug']) && (string) wp_unslash($_POST['verbose_otima_debug']) === '1';

        global $wpdb;
        $table_carteiras = $wpdb->prefix . 'pc_carteiras_v2';

        // PK interna (pc_carteiras_v2.id): apenas metadados / resolução no banco — NUNCA vai na URL da Ótima.
        $carteira_db_pk = intval($_POST['carteira_id'] ?? $_GET['carteira_id'] ?? 0);
        // wallet_id (POST): ID da carteira no provedor Ótima — ÚNICO segmento válido em .../hsm/{wallet} e .../rcs/template/{wallet}
        $wallet_from_request = trim(sanitize_text_field($_POST['wallet_id'] ?? $_GET['wallet_id'] ?? ''));
        $single_carteira_mode = ($carteira_db_pk > 0) || ($wallet_from_request !== '');

        error_log('[DEBUG ÓTIMA] AJAX pc_get_otima_templates | POST wallet_id=' . ($_POST['wallet_id'] ?? '∅') . ' carteira_id(PK)=' . ($_POST['carteira_id'] ?? '∅') . ' → wallet_request=' . $wallet_from_request . ' pk=' . $carteira_db_pk . ' | URL Ótima usa somente wallet do provedor');

        $carteiras = [];

        if ($wallet_from_request !== '') {
            // Fonte de verdade para a API Ótima: sempre o wallet enviado pelo cliente (nunca a PK).
            $nome = '';
            if ($carteira_db_pk > 0) {
                $row_meta = $wpdb->get_row($wpdb->prepare(
                    "SELECT nome FROM $table_carteiras WHERE id = %d AND ativo = 1 LIMIT 1",
                    $carteira_db_pk
                ), ARRAY_A);
                if ($row_meta) {
                    $nome = $row_meta['nome'] ?? '';
                }
            }
            if ($nome === '') {
                $row_w = $wpdb->get_row($wpdb->prepare(
                    "SELECT nome FROM $table_carteiras WHERE id_carteira = %s AND ativo = 1 LIMIT 1",
                    $wallet_from_request
                ), ARRAY_A);
                if ($row_w) {
                    $nome = $row_w['nome'] ?? '';
                }
            }
            $carteiras = [['id_carteira' => $wallet_from_request, 'nome' => $nome]];
        } elseif ($carteira_db_pk > 0) {
            // Sem wallet no POST: resolve só pela coluna id_carteira (integração); a PK não substitui o ID do provedor.
            $carteira = $wpdb->get_row($wpdb->prepare(
                "SELECT id_carteira, nome FROM $table_carteiras WHERE id = %d AND ativo = 1 LIMIT 1",
                $carteira_db_pk
            ), ARRAY_A);
            $wallet_resolved = $carteira ? trim((string) ($carteira['id_carteira'] ?? '')) : '';
            if ($wallet_resolved === '') {
                wp_send_json_error('Esta carteira não possui ID de integração (wallet) Ótima configurado em id_carteira. Corrija o cadastro da carteira; a PK interna não pode ser usada na API da Ótima.');
                return;
            }
            $carteiras = [['id_carteira' => $wallet_resolved, 'nome' => $carteira['nome'] ?? '']];
        } else {
            // Fallback: todas as carteiras (ex.: página Mensagens sem filtro)
            $carteiras = $wpdb->get_results("SELECT id_carteira, nome FROM $table_carteiras WHERE ativo = 1", ARRAY_A);
        }

        if (empty($carteiras)) {
            $empty_payload = ['templates' => []];
            if ($want_verbose_otima) {
                $empty_payload['debug_otima'] = [
                    [
                        'note' => 'Nenhuma carteira ativa para consultar na Ótima.',
                        'wallet_id_post' => $wallet_from_request,
                        'carteira_id_pk_post' => $carteira_db_pk,
                    ],
                ];
            }
            wp_send_json_success($empty_payload);
            return;
        }

        // Busca tokens
        $static_credentials = get_option('acm_static_credentials', []);
        $token_rcs = trim($static_credentials['otima_rcs_token'] ?? '');
        $token_wpp = trim($static_credentials['otima_wpp_token'] ?? '');

        // Remove 'Bearer ' se existir e caracteres de controle (evita 400 na Ótima)
        $token_rcs = preg_replace('/[\r\n\t]/', '', trim(preg_replace('/^Bearer\s+/i', '', $token_rcs)));
        $token_wpp = preg_replace('/[\r\n\t]/', '', trim(preg_replace('/^Bearer\s+/i', '', $token_wpp)));

        // both | wpp | rcs — enviado pelo React em todo request (FormData `otima_channel`).
        $otima_channel_raw = isset($_POST['otima_channel']) ? wp_unslash((string) $_POST['otima_channel']) : '';
        if ($otima_channel_raw === '' && isset($_GET['otima_channel'])) {
            $otima_channel_raw = wp_unslash((string) $_GET['otima_channel']);
        }
        $otima_channel = sanitize_key($otima_channel_raw !== '' ? $otima_channel_raw : 'both');
        if (!in_array($otima_channel, ['both', 'wpp', 'rcs'], true)) {
            $otima_channel = 'both';
        }

        $templates = [];
        $debug_otima_log = [];

        $mask_otima_token = static function ($t) {
            $t = (string) $t;
            if ($t === '') {
                return '(vazio)';
            }

            return strlen($t) <= 5 ? '***' : substr($t, 0, 5) . '***';
        };

        $append_otima_debug = function (array $entry) use (&$debug_otima_log, $want_verbose_otima) {
            if (!$want_verbose_otima) {
                return;
            }
            $debug_otima_log[] = $entry;
        };

        /**
         * GET na API Ótima: token escolhido SOMENTE pelo path da URL (RCS ≠ WhatsApp).
         * Não aceita token passado pelo chamador — evita autenticar RCS com otima_wpp_token por engano.
         */
        $fetch_otima = function ($url) use ($token_rcs, $token_wpp, $append_otima_debug, $mask_otima_token) {
            $url = (string) $url;
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $is_rcs = (strpos($path, '/v1/rcs/') !== false);
            $is_wpp = (strpos($path, '/v1/whatsapp/') !== false);
            if (!$is_rcs && !$is_wpp) {
                error_log('🔴 [Otima Templates] URL fora do padrão RCS/WPP: ' . $url);
                $append_otima_debug([
                    'url_chamada' => $url,
                    'path_parseado' => $path,
                    'api_kind' => 'unknown',
                    'token_mascarado' => '(não aplicado)',
                    'http_status' => 0,
                    'raw_response_body' => '',
                    'erro' => 'url_nao_rcs_nem_whatsapp',
                ]);

                return ['ok' => false, 'http' => 0, 'data' => null, 'url' => $url, 'err' => 'bad_url', 'decoded' => null];
            }

            $token = $is_rcs ? $token_rcs : $token_wpp;
            $api_kind = $is_rcs ? 'rcs' : 'whatsapp';

            if ($token === '') {
                error_log('🟡 [Otima Templates] Token ' . $api_kind . ' vazio | ' . $url);
                $append_otima_debug([
                    'url_chamada' => $url,
                    'path_parseado' => $path,
                    'api_kind' => $api_kind,
                    'token_mascarado' => $mask_otima_token($token),
                    'http_status' => 0,
                    'raw_response_body' => '',
                    'erro' => 'token_empty_' . $api_kind,
                ]);

                return ['ok' => false, 'http' => 0, 'data' => null, 'url' => $url, 'err' => 'token_empty', 'decoded' => null];
            }

            error_log('🔵 [Otima Templates] GET ' . $url . ' | api=' . $api_kind . ' | token_prefix=' . substr($token, 0, 8) . '…');

            // RCS: a documentação/cURL da Ótima usa o token PURO no header Authorization (sem "Bearer ").
            // WhatsApp: mantém Bearer + fallback sem prefixo em 400/401.
            if ($is_rcs) {
                $response = wp_remote_get($url, [
                    'headers' => [
                        'Authorization' => $token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 15,
                ]);
            } else {
                $response = wp_remote_get($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 15,
                ]);

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
            }

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                error_log('🔴 [Otima Templates] WP_Error ' . $err . ' | ' . $url);
                $append_otima_debug([
                    'url_chamada' => $url,
                    'path_parseado' => $path,
                    'api_kind' => $api_kind,
                    'token_mascarado' => $mask_otima_token($token),
                    'http_status' => 0,
                    'raw_response_body' => '',
                    'wp_error' => $err,
                ]);

                return ['ok' => false, 'http' => 0, 'data' => null, 'url' => $url, 'err' => $err, 'decoded' => null];
            }

            $http = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $body_debug = $body;
            if (strlen($body_debug) > 12000) {
                $body_debug = substr($body_debug, 0, 12000) . '...[truncado]';
            }

            $append_otima_debug([
                'url_chamada' => $url,
                'path_parseado' => $path,
                'api_kind' => $api_kind,
                'token_mascarado' => $mask_otima_token($token),
                'http_status' => $http,
                'raw_response_body' => $body_debug,
            ]);

            if ($http !== 200) {
                error_log('🔴 [Otima Templates] HTTP ' . $http . ' | ' . $url . ' | body_snippet=' . substr($body, 0, 1200));

                return ['ok' => false, 'http' => $http, 'data' => null, 'url' => $url, 'body_snippet' => substr($body, 0, 400), 'decoded' => null];
            }

            $raw_body = $body;
            $decoded = json_decode($raw_body, true);

            if ($is_rcs) {
                // Resposta real: array JSON na raiz [ { "code": "...", "rich_card": {...} }, ... ] — não há envelope "data".
                if (is_array($decoded) && isset($decoded[0]['code'])) {
                    $normalized = $decoded;
                } else {
                    error_log('[OTIMA RCS RAW ERROR] ' . print_r($raw_body, true));
                    $normalized = [];
                }
            } else {
                $normalized = [];
                if (is_array($decoded) && $decoded !== [] && function_exists('wp_is_numeric_array') && wp_is_numeric_array($decoded)) {
                    $normalized = $decoded;
                } elseif (is_array($decoded) && $decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
                    $normalized = $decoded;
                } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
                    $normalized = $decoded['data'];
                } elseif (isset($decoded['templates']) && is_array($decoded['templates'])) {
                    $normalized = $decoded['templates'];
                } elseif (isset($decoded['hsm']) && is_array($decoded['hsm'])) {
                    $normalized = $decoded['hsm'];
                }
            }

            $keys_hint = '';
            if (isset($normalized[0]) && is_array($normalized[0])) {
                $keys_hint = implode(',', array_slice(array_keys($normalized[0]), 0, 12));
            }
            error_log('🟢 [Otima Templates] HTTP 200 | ' . $url . ' | itens=' . count($normalized) . ($keys_hint !== '' ? ' | keys_0=' . $keys_hint : '') . ' | raw_len=' . strlen($raw_body));

            return ['ok' => true, 'http' => 200, 'data' => $normalized, 'url' => $url, 'decoded' => is_array($decoded) ? $decoded : null];
        };

        /**
         * Extrai a lista HSM do JSON da Ótima.
         * A API documentada / curl devolve um array JSON na raiz: [ { "template_code": "...", ... }, ... ].
         * Quando o payload vem encapsulado, `data` como array sequencial tem prioridade sobre `templates`
         * (evita escolher um array legado maior só porque tinha mais linhas com template_code).
         */
        $normalize_otima_wpp_hsm_body = function ($data) {
            if (!is_array($data)) {
                return [];
            }
            $is_seq = function ($a) {
                return is_array($a) && $a !== [] && array_keys($a) === range(0, count($a) - 1);
            };
            $looks_hsm_row = function ($row) {
                return is_array($row) && (isset($row['template_code']) || isset($row['templateCode']));
            };
            $log_pick = function ($branch, $list) {
                $n = is_array($list) ? count($list) : 0;
                error_log('[Ótima HSM proxy] Lista HSM (prioridade fixa): branch=' . $branch . ' | itens=' . $n);
            };

            // 1) Raiz = array sequencial — igual ao retorno do curl direto
            if ($is_seq($data)) {
                if (isset($data[0]) && $looks_hsm_row($data[0])) {
                    $log_pick('root[]', $data);

                    return $data;
                }
                // Raiz é lista de objetos (sem checar template_code no [0], ex.: formato novo)
                if (isset($data[0]) && is_array($data[0])) {
                    $log_pick('root[](objetos)', $data);

                    return $data;
                }
            }

            // 2) data[] sequencial (wrapper comum)
            if (isset($data['data']) && is_array($data['data']) && $is_seq($data['data']) && !empty($data['data'])) {
                $inner = $data['data'];
                if ($looks_hsm_row($inner[0])) {
                    $log_pick('data[]', $inner);

                    return $inner;
                }
                if (is_array($inner[0])) {
                    $log_pick('data[](objetos)', $inner);

                    return $inner;
                }
            }

            // 3–4) hsm explícito
            $hsm_paths = [
                ['label' => 'data.hsm', 'node' => isset($data['data']['hsm']) ? $data['data']['hsm'] : null],
                ['label' => 'hsm', 'node' => isset($data['hsm']) ? $data['hsm'] : null],
            ];
            foreach ($hsm_paths as $hp) {
                $h = $hp['node'];
                if (is_array($h) && $is_seq($h) && !empty($h) && $looks_hsm_row($h[0])) {
                    $log_pick($hp['label'], $h);

                    return $h;
                }
            }

            // 5) result / items / content
            foreach (['result', 'items', 'content'] as $k) {
                if (!isset($data[$k]) || !is_array($data[$k]) || !$is_seq($data[$k]) || empty($data[$k])) {
                    continue;
                }
                $inner = $data[$k];
                if ($looks_hsm_row($inner[0])) {
                    $log_pick($k, $inner);

                    return $inner;
                }
            }

            // 6) Fallbacks legados (por último — costumam ser outro produto / lista antiga)
            if (isset($data['data']['templates']) && is_array($data['data']['templates']) && $is_seq($data['data']['templates']) && !empty($data['data']['templates'])) {
                $inner = $data['data']['templates'];
                if ($looks_hsm_row($inner[0])) {
                    error_log('[Ótima HSM proxy] AVISO: fallback data.templates (última prioridade) | n=' . count($inner));

                    return $inner;
                }
            }
            if (isset($data['templates']) && is_array($data['templates']) && $is_seq($data['templates']) && !empty($data['templates'])) {
                $inner = $data['templates'];
                if ($looks_hsm_row($inner[0])) {
                    error_log('[Ótima HSM proxy] AVISO: fallback templates raiz (última prioridade) | n=' . count($inner));

                    return $inner;
                }
            }

            if ($is_seq($data)) {
                return $data;
            }

            return [];
        };

        /** Proxy ao vivo HSM WhatsApp: usa exclusivamente o token WPP passado (já é otima_wpp_token no chamador). */
        $fetch_otima_wpp_hsm = function ($url, $token) use ($normalize_otima_wpp_hsm_body, $append_otima_debug, $mask_otima_token) {
            $url = (string) $url;
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            if (strpos($path, '/v1/whatsapp/') === false) {
                error_log('[Ótima HSM proxy] URL não é WhatsApp Ótima: ' . $url);
                $append_otima_debug([
                    'url_chamada' => $url,
                    'path_parseado' => $path,
                    'api_kind' => 'whatsapp_hsm',
                    'token_mascarado' => '(não enviado)',
                    'http_status' => 0,
                    'raw_response_body' => '',
                    'erro' => 'url_nao_whatsapp',
                ]);

                return ['ok' => false, 'http' => 0, 'data' => null, 'url' => $url, 'err' => 'bad_url'];
            }

            $token_clean = preg_replace('/[\r\n\t]/', '', trim($token));
            if ($token_clean === '') {
                error_log('[Ótima HSM proxy] Token WPP vazio — request não enviado | ' . $url);
                $append_otima_debug([
                    'url_chamada' => $url,
                    'path_parseado' => $path,
                    'api_kind' => 'whatsapp_hsm',
                    'token_mascarado' => $mask_otima_token($token_clean),
                    'http_status' => 0,
                    'raw_response_body' => '',
                    'erro' => 'token_wpp_vazio',
                ]);

                return ['ok' => false, 'http' => 0, 'data' => null, 'url' => $url, 'err' => 'token_empty'];
            }

            error_log('[Ótima HSM proxy] GET ao vivo (sem DB) | ' . $url);

            $header_sets = [
                ['authorization' => $token_clean, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                ['Authorization' => $token_clean, 'Accept' => 'application/json'],
                ['Authorization' => 'Bearer ' . $token_clean, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ];

            $last_http = 0;
            $last_body = '';
            foreach ($header_sets as $headers) {
                $response = wp_remote_get($url, [
                    'headers' => $headers,
                    'timeout' => 25,
                    'sslverify' => false,
                ]);
                if (is_wp_error($response)) {
                    error_log('[Ótima HSM proxy] WP_Error: ' . $response->get_error_message() . ' | ' . $url);
                    continue;
                }
                $last_http = (int) wp_remote_retrieve_response_code($response);
                $last_body = wp_remote_retrieve_body($response);

                error_log('[Ótima HSM proxy] Código HTTP retornado pela API Ótima: ' . $last_http . ' | ' . $url);

                if ($last_http === 200) {
                    $last_body = trim($last_body);
                    $decoded = json_decode($last_body, true);
                    $body_dbg = $last_body;
                    if (strlen($body_dbg) > 12000) {
                        $body_dbg = substr($body_dbg, 0, 12000) . '...[truncado]';
                    }
                    if (!is_array($decoded)) {
                        error_log('[Ótima HSM proxy] JSON inválido ou não-array após decode | len_body=' . strlen($last_body));
                        $append_otima_debug([
                            'url_chamada' => $url,
                            'path_parseado' => $path,
                            'api_kind' => 'whatsapp_hsm',
                            'token_mascarado' => $mask_otima_token($token_clean),
                            'http_status' => 200,
                            'raw_response_body' => $body_dbg,
                            'erro' => 'json_invalido_pos_200',
                        ]);

                        return ['ok' => false, 'http' => 200, 'data' => null, 'url' => $url, 'body_snippet' => substr($last_body, 0, 400)];
                    }

                    $normalized = $normalize_otima_wpp_hsm_body($decoded);
                    $keys_hint = '';
                    if (isset($normalized[0]) && is_array($normalized[0])) {
                        $keys_hint = implode(',', array_slice(array_keys($normalized[0]), 0, 12));
                    }
                    error_log('[Ótima HSM proxy] HTTP 200 — lista normalizada: itens=' . count($normalized) . ($keys_hint !== '' ? ' | keys_0=' . $keys_hint : ''));

                    $append_otima_debug([
                        'url_chamada' => $url,
                        'path_parseado' => $path,
                        'api_kind' => 'whatsapp_hsm',
                        'token_mascarado' => $mask_otima_token($token_clean),
                        'http_status' => 200,
                        'raw_response_body' => $body_dbg,
                    ]);

                    return ['ok' => true, 'http' => 200, 'data' => $normalized, 'url' => $url];
                }
            }

            error_log('[Ótima HSM proxy] Falha após tentativas de Authorization | último HTTP=' . $last_http . ' | ' . $url . ' | snippet=' . substr($last_body, 0, 600));

            $fail_body = (string) $last_body;
            if (strlen($fail_body) > 12000) {
                $fail_body = substr($fail_body, 0, 12000) . '...[truncado]';
            }
            $append_otima_debug([
                'url_chamada' => $url,
                'path_parseado' => $path,
                'api_kind' => 'whatsapp_hsm',
                'token_mascarado' => $mask_otima_token($token_clean),
                'http_status' => $last_http,
                'raw_response_body' => $fail_body,
            ]);

            return ['ok' => false, 'http' => $last_http, 'data' => null, 'url' => $url, 'body_snippet' => substr($last_body, 0, 400)];
        };

        foreach ($carteiras as $carteira) {
            // id_carteira neste array = wallet Ótima (provedor), nunca PK do WordPress
            $wallet_otima = (string) ($carteira['id_carteira'] ?? '');
            $carteira_nome = $carteira['nome'];

            // --- RCS Templates (endpoint /v1/rcs/template/{wallet} — não é HSM) ---
            if (($otima_channel === 'both' || $otima_channel === 'rcs') && !empty($token_rcs)) {
                $url_rcs = 'https://services.otima.digital/v1/rcs/template/' . rawurlencode($wallet_otima);
                $rcs_res = $fetch_otima($url_rcs);
                $rcs_data = ($rcs_res['ok'] && is_array($rcs_res['data'])) ? $rcs_res['data'] : [];

                if (!empty($rcs_data)) {
                    foreach ($rcs_data as $item) {
                        if (!is_array($item) || !isset($item['code'])) {
                            continue;
                        }
                        $code = (string) $item['code'];
                        if ($code === '') {
                            continue;
                        }
                        $desc = '';
                        if (isset($item['rich_card']['description']) && is_string($item['rich_card']['description'])) {
                            $desc = wp_trim_words(trim($item['rich_card']['description']), 5, '...');
                        }
                        $name = 'RCS ' . $code . ($desc !== '' ? ' - ' . $desc : '');

                        $content = '';
                        if (isset($item['rich_card']) && is_array($item['rich_card'])) {
                            if (!empty($item['rich_card']['title'])) {
                                $content .= $item['rich_card']['title'] . "\n";
                            }
                            if (!empty($item['rich_card']['description'])) {
                                $content .= $item['rich_card']['description'];
                            }
                        } elseif (isset($item['text']) && is_string($item['text'])) {
                            $content = $item['text'];
                        }
                        $image_url = null;
                        if (isset($item['rich_card']) && is_array($item['rich_card'])) {
                            $image_url = $item['rich_card']['image_url'] ?? null;
                        }

                        $templates[] = [
                            'id' => $code,
                            'name' => $name,
                            'send_meta_template' => $code,
                            'template_code' => $code,
                            'content' => $content,
                            'date' => date('Y-m-d H:i:s'),
                            'source' => 'otima_rcs',
                            'wallet_id' => $wallet_otima,
                            'wallet_name' => $carteira_nome,
                            'broker_code' => $item['broker_code'] ?? $item['brokerCode'] ?? '',
                            'customer_code' => $wallet_otima,
                            'image_url' => $image_url,
                            'raw_data' => $item,
                        ];
                    }
                }
            }

            // --- WhatsApp HSM: GET .../template/hsm/{wallet_otima} — só quando o fluxo pede WPP (não misturar com RCS-only) ---
            if (($otima_channel === 'both' || $otima_channel === 'wpp') && !empty($token_wpp)) {
                $url_wpp = 'https://services.otima.digital/v1/whatsapp/template/hsm/' . rawurlencode($wallet_otima);
                error_log('🔵 [Otima Templates] HSM wallet_otima=' . $wallet_otima . ' url=' . $url_wpp);
                $wpp_res = $fetch_otima_wpp_hsm($url_wpp, $token_wpp);

                // Isolamento RCS vs HSM: falha na API WhatsApp nunca derruba quem usa `both` ou `rcs`.
                // wp_send_json_error só quando o cliente pediu EXCLUSIVAMENTE WhatsApp (`wpp`).
                if (!$wpp_res['ok']) {
                    $snippet = isset($wpp_res['body_snippet']) ? substr((string) $wpp_res['body_snippet'], 0, 600) : '';
                    error_log(
                        '[Ótima HSM] Falha ignorada para o JSON de sucesso (canal=' . $otima_channel
                        . ') | wallet=' . $wallet_otima
                        . ' | http=' . (string) ($wpp_res['http'] ?? 0)
                        . ' | single_carteira=' . ($single_carteira_mode ? '1' : '0')
                        . ' | snippet=' . $snippet
                    );
                    if ($single_carteira_mode && $otima_channel === 'wpp') {
                        $hint = $snippet !== '' ? ' Resposta: ' . $snippet : '';
                        wp_send_json_error('Não foi possível sincronizar os templates HSM (WhatsApp) para esta carteira.' . $hint);
                        return;
                    }
                }

                $wpp_data = ($wpp_res['ok'] && is_array($wpp_res['data'])) ? $wpp_res['data'] : [];

                foreach ($wpp_data as $tpl) {
                    if (!is_array($tpl)) {
                        continue;
                    }
                    // Proxy: payload espelha a API Ótima; só acrescentamos o mínimo para o React filtrar por provedor.
                    $templates[] = array_merge($tpl, [
                        'source' => 'otima_wpp',
                        'wallet_id' => $wallet_otima,
                    ]);
                }
            }
        }

        $wpp_codes_debug = [];
        foreach ($templates as $titem) {
            if (is_array($titem) && ($titem['source'] ?? '') === 'otima_wpp') {
                $wpp_codes_debug[] = $titem['template_code'] ?? $titem['templateCode'] ?? '(sem template_code)';
            }
        }
        error_log('[Ótima HSM proxy] wp_send_json_success | total_itens=' . count($templates) . ' | template_code WPP (amostra 50): ' . implode(', ', array_slice($wpp_codes_debug, 0, 50)));

        $payload = ['templates' => $templates];
        if ($want_verbose_otima) {
            $payload['debug_otima'] = $debug_otima_log;
        }
        wp_send_json_success($payload);
    }

    public function handle_get_otima_brokers()
    {
        if (!current_user_can('read')) {
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

        /**
         * GET credenciais Ótima (lista de remetentes). Mesma tolerância de Authorization do bulk HSM.
         * @return array|null Lista de itens da API ou null em falha
         */
        $fetch_otima_credential_list = function ($url, $token, $channel_label) use (&$debug_log) {
            if (empty($token)) {
                $debug_log[] = "{$channel_label}: token vazio.";

                return null;
            }
            $token_clean = preg_replace('/[\r\n\t]/', '', trim($token));
            $header_sets = [
                ['authorization' => $token_clean, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                ['Authorization' => $token_clean, 'Accept' => 'application/json'],
                ['Authorization' => 'Bearer ' . $token_clean, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ];
            $last_status = 0;
            $last_body = '';
            foreach ($header_sets as $headers) {
                $response = wp_remote_get($url, [
                    'headers' => $headers,
                    'timeout' => 20,
                    'sslverify' => false,
                ]);
                if (is_wp_error($response)) {
                    $debug_log[] = "{$channel_label} WP_Error: " . $response->get_error_message();

                    continue;
                }
                $last_status = (int) wp_remote_retrieve_response_code($response);
                $last_body = wp_remote_retrieve_body($response);
                error_log('[Ótima credential proxy] ' . $channel_label . ' HTTP ' . $last_status . ' | ' . $url);
                if ($last_status !== 200) {
                    continue;
                }
                $data = json_decode(trim($last_body), true);
                if (!is_array($data)) {
                    $debug_log[] = "{$channel_label}: JSON inválido.";

                    return null;
                }
                if (isset($data['data']) && is_array($data['data'])) {
                    return $data['data'];
                }
                if ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
                    return $data;
                }

                return [];
            }
            $debug_log[] = "{$channel_label}: HTTP {$last_status} " . substr($last_body, 0, 120);

            return null;
        };

        // WhatsApp: GET /v1/whatsapp/credential — broker_code no bulk HSM = campo `code` (telefone remetente), NUNCA `credential` (nome).
        if (empty($token_wpp)) {
            $debug_log[] = 'Token WPP não configurado no painel.';
        } else {
            $url_wpp = 'https://services.otima.digital/v1/whatsapp/credential';
            $wpp_creds = $fetch_otima_credential_list($url_wpp, $token_wpp, 'WPP');
            if (is_array($wpp_creds)) {
                foreach ($wpp_creds as $cred) {
                    if (!is_array($cred)) {
                        continue;
                    }
                    $code = isset($cred['code']) ? trim((string) $cred['code']) : '';
                    if ($code === '') {
                        continue;
                    }
                    $dedupe = 'wpp:' . $code;
                    if (isset($seen[$dedupe])) {
                        continue;
                    }
                    $seen[$dedupe] = true;
                    $credential = isset($cred['credential']) ? trim((string) $cred['credential']) : '';
                    if ($credential === '') {
                        $credential = isset($cred['name']) ? trim((string) $cred['name']) : $code;
                    }
                    $brokers[] = [
                        'channel' => 'wpp',
                        'code' => $code,
                        'value' => $code,
                        'label' => $credential,
                        'name' => 'WPP — ' . $credential,
                        'raw' => $cred,
                    ];
                }
            }
        }

        // RCS: GET /v1/rcs/credential
        if (empty($token_rcs)) {
            $debug_log[] = 'Token RCS não configurado no painel.';
        } else {
            $url_rcs = 'https://services.otima.digital/v1/rcs/credential';
            $rcs_creds = $fetch_otima_credential_list($url_rcs, $token_rcs, 'RCS');
            if (is_array($rcs_creds)) {
                foreach ($rcs_creds as $cred) {
                    if (!is_array($cred)) {
                        continue;
                    }
                    $code = isset($cred['code']) ? trim((string) $cred['code']) : '';
                    if ($code === '') {
                        continue;
                    }
                    $dedupe = 'rcs:' . $code;
                    if (isset($seen[$dedupe])) {
                        continue;
                    }
                    $seen[$dedupe] = true;
                    $nome = isset($cred['name']) ? trim((string) $cred['name']) : (isset($cred['credential']) ? trim((string) $cred['credential']) : $code);
                    $brokers[] = [
                        'channel' => 'rcs',
                        'code' => $code,
                        'value' => $code,
                        'label' => $nome,
                        'name' => 'RCS — ' . $nome,
                        'raw' => $cred,
                    ];
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

        $this->pc_forbid_subscriber_ajax();

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

            if (empty($errors)) {
                update_option('pc_last_salesforce_tracking_run', current_time('mysql'));
            }

            wp_send_json_success(array(
                'rows_inserted' => $total_inserted,
                'pages_processed' => $pages_processed,
                'errors' => $errors,
                // Include diagnostic info as requested by user
                'diagnostic_info' => array(
                    'auth_url' => $sf_auth_url . '/v2/token',
                    'rest_base_url' => $sf_rest_url,
                    'tested_url' => $sf_rest_url . '/data/v1/customobjectdata/key/' . $sf_de_key . '/rowset?$page=1&$pageSize=200',
                    'token_obtained' => !empty($access_token),
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
            $telefones_unique = array_values(array_unique($telefones));
            $chunk = 2500;
            foreach (array_chunk($telefones_unique, $chunk) as $chunk_vals) {
                $placeholders = implode(',', array_fill(0, count($chunk_vals), '%s'));
                $query = $wpdb->prepare(
                    "SELECT valor FROM $table WHERE tipo = 'telefone' AND valor IN ($placeholders)",
                    $chunk_vals
                );
                $col = $wpdb->get_col($query);
                if (!empty($col)) {
                    $blocked_telefones = array_merge($blocked_telefones, $col);
                }
            }
            $blocked_telefones = array_values(array_unique($blocked_telefones));
        }

        // Busca CPFs bloqueados
        if (!empty($cpfs)) {
            $cpfs_unique = array_values(array_unique($cpfs));
            $chunk = 2500;
            foreach (array_chunk($cpfs_unique, $chunk) as $chunk_vals) {
                $placeholders = implode(',', array_fill(0, count($chunk_vals), '%s'));
                $query = $wpdb->prepare(
                    "SELECT valor FROM $table WHERE tipo = 'cpf' AND valor IN ($placeholders)",
                    $chunk_vals
                );
                $col = $wpdb->get_col($query);
                if (!empty($col)) {
                    $blocked_cpfs = array_merge($blocked_cpfs, $col);
                }
            }
            $blocked_cpfs = array_values(array_unique($blocked_cpfs));
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

    /**
     * Extrai DATA_TYPE a partir do campo Type do SHOW COLUMNS (ex.: varchar(100) → varchar).
     */
    private static function parse_data_type_from_show_column_type($type_field)
    {
        $t = strtolower(trim((string) $type_field));
        if (preg_match('/^([a-z]+)/', $t, $m)) {
            return $m[1];
        }
        return $t;
    }

    /**
     * Metadados de colunas sem ler linhas da tabela/view (compatível com bases milionárias).
     */
    private static function fetch_columns_metadata_only($table_name)
    {
        global $wpdb;

        $columns_info = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             ORDER BY ORDINAL_POSITION",
            DB_NAME,
            $table_name
        ), ARRAY_A);

        if (!empty($columns_info)) {
            return $columns_info;
        }

        // Fallback: SHOW COLUMNS (também só catálogo; não varre dados)
        $safe = str_replace('`', '', $table_name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $safe)) {
            return [];
        }

        $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . $safe . '`', ARRAY_A);
        if (empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            if (empty($r['Field'])) {
                continue;
            }
            $out[] = [
                'COLUMN_NAME' => $r['Field'],
                'DATA_TYPE' => self::parse_data_type_from_show_column_type($r['Type'] ?? ''),
            ];
        }

        return $out;
    }

    public static function get_filterable_columns($table_name)
    {
        if (empty($table_name)) {
            return new WP_Error('invalid_table', 'Nome de tabela inválido');
        }

        $columns_info = self::fetch_columns_metadata_only($table_name);

        if (empty($columns_info)) {
            return new WP_Error('no_columns', 'Não foi possível obter colunas da tabela (metadados)');
        }

        $numeric_types = ['int', 'integer', 'bigint', 'decimal', 'float', 'double', 'tinyint', 'smallint', 'mediumint', 'real', 'bit'];
        $binary_types = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'];
        $filters = [];

        foreach ($columns_info as $column) {
            $column_name = $column['COLUMN_NAME'];
            $data_type = strtolower($column['DATA_TYPE']);

            if (in_array(strtoupper($column_name), self::$excluded_columns, true)) {
                continue;
            }

            if (in_array($data_type, $binary_types, true)) {
                continue;
            }

            $label = ucwords(strtolower(str_replace('_', ' ', $column_name)));

            $is_numeric = in_array($data_type, $numeric_types, true);

            if ($is_numeric) {
                $filters[] = [
                    'column' => $column_name,
                    'label' => $label,
                    'type' => 'numeric',
                    'data_type' => $data_type,
                ];
            } else {
                // Texto/data/json/enum: operadores de texto + input livre (sem SELECT DISTINCT na view)
                $filters[] = [
                    'column' => $column_name,
                    'label' => $label,
                    'type' => 'text',
                    'data_type' => $data_type,
                ];
            }
        }

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
            'not_in',
            'is_null',
            'is_not_null',
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

                if (!in_array($operator, $allowed_operators, true)) {
                    continue;
                }

                $sanitized_column = esc_sql(str_replace('`', '', $column));

                if ($operator === 'is_null') {
                    $where_clauses[] = "`{$sanitized_column}` IS NULL";
                    continue;
                }
                if ($operator === 'is_not_null') {
                    $where_clauses[] = "`{$sanitized_column}` IS NOT NULL";
                    continue;
                }

                if ($value === '' || $value === null) {
                    continue;
                }

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

    /**
     * Monta lista SELECT só com colunas usadas na campanha (reduz memória em bases com muitas colunas / 100k+ linhas).
     *
     * @param string[] $extra_requested Nomes de colunas vindos do variables_map (case-insensitive)
     * @return string|null Lista "`col1`, `col2`" ou null se não for seguro (cai no SELECT *)
     */
    private static function build_lean_select_list($table_name, array $extra_requested)
    {
        global $wpdb;

        $raw_cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        if (empty($raw_cols)) {
            return null;
        }

        $upper_map = [];
        foreach ($raw_cols as $cn) {
            $upper_map[strtoupper($cn)] = $cn;
        }

        $quoted = [];
        $add_upper = function ($upper) use (&$quoted, $upper_map) {
            $U = strtoupper($upper);
            if (isset($upper_map[$U])) {
                $act = str_replace('`', '', $upper_map[$U]);
                $quoted[$U] = '`' . esc_sql($act) . '`';
            }
        };

        if (isset($upper_map['TELEFONE'])) {
            $add_upper('TELEFONE');
        } elseif (isset($upper_map['CELULAR'])) {
            $add_upper('CELULAR');
        }

        if (isset($upper_map['NOME'])) {
            $add_upper('NOME');
        } elseif (isset($upper_map['CLIENTE'])) {
            $add_upper('CLIENTE');
        }

        if (isset($upper_map['IDGIS_AMBIENTE'])) {
            $add_upper('IDGIS_AMBIENTE');
        } elseif (isset($upper_map['AMBIENTE'])) {
            $add_upper('AMBIENTE');
        }

        if (isset($upper_map['IDCOB_CONTRATO'])) {
            $add_upper('IDCOB_CONTRATO');
        } elseif (isset($upper_map['CONTRATO'])) {
            $add_upper('CONTRATO');
        }

        if (isset($upper_map['CPF']) && isset($upper_map['CPF_CNPJ'])) {
            $add_upper('CPF');
            $add_upper('CPF_CNPJ');
        } elseif (isset($upper_map['CPF'])) {
            $add_upper('CPF');
        } elseif (isset($upper_map['CPF_CNPJ'])) {
            $add_upper('CPF_CNPJ');
        } elseif (isset($upper_map['CNPJ'])) {
            $add_upper('CNPJ');
        }

        foreach ($extra_requested as $req) {
            if (!is_string($req) || $req === '') {
                continue;
            }
            $add_upper($req);
        }

        // Precisamos de ao menos uma coluna “de telefone” para a campanha fazer sentido
        $has_phone = isset($upper_map['TELEFONE']) && isset($quoted['TELEFONE'])
            || isset($upper_map['CELULAR']) && isset($quoted['CELULAR']);
        if (!$has_phone) {
            return null;
        }

        return implode(', ', $quoted);
    }

    /**
     * @param string[] $extra_columns Colunas extras (ex.: campos do variables_map) para incluir no SELECT
     */
    public static function get_filtered_records($table_name, $filters, $limit = 0, $extra_columns = [])
    {
        global $wpdb;

        if (empty($table_name)) {
            return [];
        }

        $extra_columns = is_array($extra_columns) ? $extra_columns : [];

        $where_sql = self::build_where_clause($filters);
        $limit_sql = '';
        if ($limit > 0) {
            $limit_sql = $wpdb->prepare(" LIMIT %d", $limit);
        }

        $select_list = self::build_lean_select_list($table_name, $extra_columns);
        if ($select_list === null) {
            error_log('PC Campaign Filters - SELECT enxuto indisponível; usando SELECT * (memória maior). Tabela: ' . $table_name);
            $sql = "SELECT * FROM `{$table_name}`" . $where_sql . $limit_sql;
        } else {
            $sql = "SELECT {$select_list} FROM `{$table_name}`" . $where_sql . $limit_sql;
        }

        $records = $wpdb->get_results($sql, ARRAY_A);

        if ($records === null || $wpdb->last_error) {
            error_log('PC Campaign Filters - Erro ao buscar registros: ' . $wpdb->last_error);
            return [];
        }

        // Helper function to get value from record case-insensitively
        $get_val = function ($record, $keys) {
            if (!is_array($keys)) {
                $keys = [$keys];
            }
            foreach ($keys as $key) {
                if (isset($record[$key])) {
                    return $record[$key];
                }
                if (isset($record[strtolower($key)])) {
                    return $record[strtolower($key)];
                }
                if (isset($record[strtoupper($key)])) {
                    return $record[strtoupper($key)];
                }
                foreach ($record as $k => $v) {
                    if (strcasecmp($k, $key) === 0) {
                        return $v;
                    }
                }
            }
            return '';
        };

        $normalized_records = [];
        foreach ($records as $record) {
            $telefone = $get_val($record, ['TELEFONE', 'celular', 'phone']);
            if (empty($telefone)) {
                foreach ($record as $k => $v) {
                    if (stripos($k, 'tel') !== false || stripos($k, 'cel') !== false) {
                        $telefone = $v;
                        break;
                    }
                }
            }

            $base = [
                'telefone' => $telefone,
                'nome' => $get_val($record, ['NOME', 'name', 'cliente']),
                'idgis_ambiente' => $get_val($record, ['IDGIS_AMBIENTE', 'id_gis', 'ambiente']),
                'idcob_contrato' => $get_val($record, ['IDCOB_CONTRATO', 'contrato', 'id_contrato']),
                'cpf_cnpj' => $get_val($record, ['CPF', 'CNPJ', 'cpf_cnpj', 'doc'])
            ];

            // Preserva colunas trazidas no SELECT (variáveis de template) com chaves originais + UPPER para provedores
            $normalized_records[] = array_merge($record, $base);
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

