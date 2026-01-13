<?php
/**
 * Página de API Manager
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$current_page = 'api-manager';
$page_title = 'API Manager';

$master_api_key = get_option('acm_master_api_key', '');
$provider_credentials = get_option('acm_provider_credentials', []);
$static_credentials = get_option('acm_static_credentials', []);
$microservice_config = get_option('acm_microservice_config', [
    'url' => '',
    'api_key' => ''
]);

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Gerenciamento de API</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Configure credenciais, URLs e integrações com o microserviço</p>
    </div>

    <!-- Tabs -->
    <div class="mb-6 border-b border-gray-200 dark:border-gray-700">
        <nav class="flex space-x-8">
            <button id="tab-view" class="tab-button active px-4 py-2 text-sm font-medium border-b-2 border-blue-500 text-blue-600 dark:text-blue-400">
                <i class="fas fa-list mr-2"></i>Ver Credenciais
            </button>
            <button id="tab-create" class="tab-button px-4 py-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                <i class="fas fa-plus mr-2"></i>Criar Nova Credencial
            </button>
        </nav>
    </div>

    <!-- Tab Content: Ver Credenciais -->
    <div id="view-tab-content" class="tab-content">
        <!-- Microserviço Config -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-server"></i>
                <span>Configuração do Microserviço</span>
            </h3>
            <form id="microservice-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        URL do Microserviço <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="url" 
                        id="microservice-url" 
                        name="microservice_url"
                        value="<?php echo esc_attr($microservice_config['url']); ?>"
                        placeholder="https://api.exemplo.com"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        API Key do Microserviço <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="microservice-api-key" 
                        name="microservice_api_key"
                        value="<?php echo esc_attr($microservice_config['api_key']); ?>"
                        placeholder="sua-api-key-aqui"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                </div>
                <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>Salvar Configuração
                </button>
            </form>
        </div>

        <!-- Master API Key -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-key"></i>
                <span>Master API Key</span>
            </h3>
            <form id="master-key-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Chave API Master <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="master-api-key" 
                        name="master_api_key"
                        value="<?php echo esc_attr($master_api_key); ?>"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                </div>
                <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>Salvar Master Key
                </button>
            </form>
        </div>

        <!-- Static Provider Credentials -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-building"></i>
                <span>Static Provider Credentials</span>
            </h3>
            
            <form id="static-credentials-form" class="space-y-6">
                <!-- CDA Provider -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">CDA Provider</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">CDA API URL</label>
                            <input type="url" name="cda_api_url" value="<?php echo esc_attr($static_credentials['cda_api_url'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">CDA API Key</label>
                            <input type="text" name="cda_api_key" value="<?php echo esc_attr($static_credentials['cda_api_key'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                    </div>
                </div>

                <!-- Salesforce -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Salesforce</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Client ID</label>
                            <input type="text" name="sf_client_id" value="<?php echo esc_attr($static_credentials['sf_client_id'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Client Secret</label>
                            <input type="password" name="sf_client_secret" value="<?php echo esc_attr($static_credentials['sf_client_secret'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Username</label>
                            <input type="text" name="sf_username" value="<?php echo esc_attr($static_credentials['sf_username'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password + Security Token</label>
                            <input type="password" name="sf_password" value="<?php echo esc_attr($static_credentials['sf_password'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Token URL</label>
                            <input type="url" name="sf_token_url" value="<?php echo esc_attr($static_credentials['sf_token_url'] ?? 'https://concilig.my.salesforce.com/services/oauth2/token'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">API URL</label>
                            <input type="url" name="sf_api_url" value="<?php echo esc_attr($static_credentials['sf_api_url'] ?? 'https://concilig.my.salesforce.com/services/data/v59.0/composite/sobjects'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                    </div>
                </div>

                <!-- Marketing Cloud -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Marketing Cloud</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Client ID</label>
                            <input type="text" name="mkc_client_id" value="<?php echo esc_attr($static_credentials['mkc_client_id'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Client Secret</label>
                            <input type="password" name="mkc_client_secret" value="<?php echo esc_attr($static_credentials['mkc_client_secret'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Token URL</label>
                            <input type="url" name="mkc_token_url" value="<?php echo esc_attr($static_credentials['mkc_token_url'] ?? 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.auth.marketingcloudapis.com/v2/token'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">API Base URL</label>
                            <input type="url" name="mkc_api_url" value="<?php echo esc_attr($static_credentials['mkc_api_url'] ?? 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.rest.marketingcloudapis.com/automation/v1/automations'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                    </div>
                </div>

                <!-- RCS CDA (CromosApp) -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">RCS CDA (CromosApp)</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        <i class="fas fa-info-circle mr-2"></i>
                        Funciona igual ao CDA: código_equipe = idgis_ambiente (vem dos dados), código_usuario = sempre '1'
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Chave API *</label>
                            <input type="text" name="rcs_chave_api" value="<?php echo esc_attr($static_credentials['rcs_chave_api'] ?? $static_credentials['rcs_token'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Base URL</label>
                            <input type="url" name="rcs_base_url" value="<?php echo esc_attr($static_credentials['rcs_base_url'] ?? 'https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        </div>
                    </div>
                </div>

                <!-- Ótima WhatsApp -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Ótima WhatsApp</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        <i class="fas fa-info-circle mr-2"></i>
                        API: https://services.otima.digital/v1/whatsapp/bulk/message/hsm
                    </p>
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Token de Autenticação *</label>
                            <input type="text" name="otima_wpp_token" value="<?php echo esc_attr($static_credentials['otima_wpp_token'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="Bearer token para autenticação">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        <i class="fas fa-lightbulb mr-1"></i>
                        Os códigos broker_code e customer_code são passados no JSON do disparo.
                    </p>
                </div>

                <!-- Ótima RCS -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Ótima RCS</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        <i class="fas fa-info-circle mr-2"></i>
                        API: https://services.otima.digital/v1/rcs/bulk/message/template
                    </p>
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Token de Autenticação *</label>
                            <input type="text" name="otima_rcs_token" value="<?php echo esc_attr($static_credentials['otima_rcs_token'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="Bearer token para autenticação">
                        </div>
                    </div>
                </div>

                <!-- Dashboard Security -->
                <div>
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Dashboard Security</h4>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dashboard Password</label>
                        <input type="password" name="dashboard_password" value="<?php echo esc_attr($static_credentials['dashboard_password'] ?? get_option('ga_dashboard_password', 'admin123')); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Password para acessar o Status Dashboard (get_agendamentos)</p>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-save mr-2"></i>Salvar Static Credentials
                    </button>
                </div>
            </form>
        </div>

        <!-- Credenciais Existentes -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-database"></i>
                <span>Credenciais Configuradas</span>
            </h3>
            
            <div id="credentials-list" class="space-y-4">
                <?php
                if (!is_array($provider_credentials)) {
                    $provider_credentials = [];
                }
                
                $has_credentials = false;
                foreach (['gosac', 'noah', 'cda', 'salesforce', 'rcs'] as $provider_name) {
                    if (isset($provider_credentials[$provider_name]) && is_array($provider_credentials[$provider_name])) {
                        foreach ($provider_credentials[$provider_name] as $env_id => $data) {
                            if (is_array($data) && !empty($data)) {
                                $has_credentials = true;
                                ?>
                                <div class="credential-card p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700" data-provider="<?php echo esc_attr($provider_name); ?>" data-env-id="<?php echo esc_attr($env_id); ?>">
                                    <div class="flex items-start justify-between mb-3">
                                        <div>
                                            <h4 class="font-semibold text-gray-900 dark:text-white uppercase"><?php echo esc_html($provider_name); ?></h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">Environment ID: <code class="px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded"><?php echo esc_html($env_id); ?></code></p>
                                        </div>
                                        <div class="flex gap-2">
                                            <button class="edit-credential px-3 py-1 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600 transition-colors">
                                                <i class="fas fa-edit mr-1"></i>Editar
                                            </button>
                                            <button class="delete-credential px-3 py-1 bg-red-500 text-white text-sm rounded-lg hover:bg-red-600 transition-colors">
                                                <i class="fas fa-trash mr-1"></i>Deletar
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-700 dark:text-gray-300 space-y-1">
                                        <?php if ($provider_name === 'salesforce'): ?>
                                            <p><strong>Operação:</strong> <?php echo esc_html($data['operacao'] ?? ''); ?></p>
                                            <p><strong>Automation ID:</strong> <code class="text-xs"><?php echo esc_html($data['automation_id'] ?? ''); ?></code></p>
                                        <?php elseif ($provider_name === 'rcs'): ?>
                                            <p><strong>Broker Code:</strong> <?php echo esc_html($data['broker_code'] ?? ''); ?></p>
                                            <p><strong>Customer Code:</strong> <?php echo esc_html($data['customer_code'] ?? ''); ?></p>
                                        <?php else: ?>
                                            <p><strong>URL:</strong> <code class="text-xs break-all"><?php echo esc_html($data['url'] ?? ''); ?></code></p>
                                            <p><strong>Token:</strong> <code class="text-xs"><?php echo esc_html(substr($data['token'] ?? '', 0, 30)) . '...'; ?></code></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php
                            }
                        }
                    }
                }
                
                if (!$has_credentials) {
                    echo '<div class="text-center py-8 text-gray-500 dark:text-gray-400"><i class="fas fa-inbox text-4xl mb-4"></i><p>Nenhuma credencial configurada ainda</p></div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Tab Content: Criar Nova Credencial -->
    <div id="create-tab-content" class="tab-content hidden">
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-plus-circle"></i>
                <span>Criar Nova Credencial</span>
            </h3>
            
            <form id="create-credential-form" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Provider <span class="text-red-500">*</span>
                    </label>
                    <select id="create-provider" name="provider" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        <option value="">Selecione um Provider</option>
                        <option value="gosac">GOSAC</option>
                        <option value="noah">Noah</option>
                        <option value="cda">CDA</option>
                        <option value="salesforce">Salesforce</option>
                        <option value="rcs">RCS CDA (CromosApp)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Environment ID <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="create-env-id" 
                        name="env_id" 
                        required
                        placeholder="Ex: 3641"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                    >
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">O valor idgis_ambiente usado nas campanhas</p>
                </div>

                <!-- Campos para URL/Token (GOSAC, Noah, CDA) -->
                <div id="url-token-fields" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API URL <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="url" 
                            id="create-url" 
                            name="url" 
                            placeholder="https://provider.api.com/endpoint"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Token/Key <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-token" 
                            name="token" 
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                </div>

                <!-- Campos para Salesforce -->
                <div id="salesforce-fields" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Operação Name <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-operacao" 
                            name="operacao" 
                            placeholder="BV_VEIC_ADM_Tradicional"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Automation ID <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-automation-id" 
                            name="automation_id" 
                            placeholder="0e309929-51ae-4e2a-b8d1-ee17c055f42e"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                </div>

                <!-- Campos para RCS (CromosApp) -->
                <div id="rcs-fields" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Chave API <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-chave-api" 
                            name="chave_api" 
                            placeholder="sua-chave-api"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Código da Equipe <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-codigo-equipe" 
                            name="codigo_equipe" 
                            placeholder="codigo-equipe"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Código do Usuário <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="create-codigo-usuario" 
                            name="codigo_usuario" 
                            placeholder="codigo-usuario"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        >
                    </div>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="submit" class="flex-1 px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-save mr-2"></i>Criar Credencial
                    </button>
                    <button type="button" id="reset-form" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Limpar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Credencial -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Editar Credencial</h3>
                <button id="close-edit-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <form id="edit-credential-form" class="p-6 space-y-4">
            <input type="hidden" id="edit-provider" name="provider">
            <input type="hidden" id="edit-env-id" name="env_id">
            
            <div id="edit-fields">
                <!-- Campos serão preenchidos dinamicamente -->
            </div>
            
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>Salvar Alterações
                </button>
                <button type="button" id="cancel-edit" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>',
        homeUrl: '<?php echo esc_js(home_url()); ?>'
    };

    // Tabs
    $('.tab-button').on('click', function() {
        const tabId = $(this).attr('id');
        $('.tab-button').removeClass('active border-b-2 border-blue-500 text-blue-600 dark:text-blue-400').addClass('text-gray-500 dark:text-gray-400');
        $(this).addClass('active border-b-2 border-blue-500 text-blue-600 dark:text-blue-400').removeClass('text-gray-500 dark:text-gray-400');
        
        $('.tab-content').addClass('hidden');
        if (tabId === 'tab-view') {
            $('#view-tab-content').removeClass('hidden');
        } else {
            $('#create-tab-content').removeClass('hidden');
        }
    });

    // Toggle fields based on provider
    $('#create-provider').on('change', function() {
        const provider = $(this).val();
        $('#url-token-fields, #salesforce-fields, #rcs-fields').addClass('hidden');
        
        if (provider === 'salesforce') {
            $('#salesforce-fields').removeClass('hidden');
        } else if (provider === 'rcs') {
            $('#rcs-fields').removeClass('hidden');
        } else if (provider && provider !== '') {
            $('#url-token-fields').removeClass('hidden');
        }
    });

    // Salvar Master API Key
    $('#master-key-form').on('submit', function(e) {
        e.preventDefault();
        const masterKey = $('#master-api-key').val();
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_save_master_api_key',
                nonce: pcAjax.nonce,
                master_api_key: masterKey
            },
            success: function(response) {
                if (response.success) {
                    showToast('Master API Key salva com sucesso!', 'success');
                } else {
                    showToast(response.data || 'Erro ao salvar', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Salvar Configuração do Microserviço
    $('#microservice-form').on('submit', function(e) {
        e.preventDefault();
        const url = $('#microservice-url').val();
        const apiKey = $('#microservice-api-key').val();
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_save_microservice_config',
                nonce: pcAjax.nonce,
                microservice_url: url,
                microservice_api_key: apiKey
            },
            success: function(response) {
                if (response.success) {
                    showToast('Configuração do microserviço salva com sucesso!', 'success');
                } else {
                    showToast(response.data || 'Erro ao salvar', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Salvar Static Credentials
    $('#static-credentials-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = {};
        $(this).serializeArray().forEach(item => {
            formData[item.name] = item.value;
        });
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_save_static_credentials',
                nonce: pcAjax.nonce,
                static_credentials: formData
            },
            success: function(response) {
                if (response.success) {
                    showToast('Static credentials salvas com sucesso!', 'success');
                } else {
                    showToast(response.data || 'Erro ao salvar', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Criar Nova Credencial
    $('#create-credential-form').on('submit', function(e) {
        e.preventDefault();
        
        const provider = $('#create-provider').val();
        const envId = $('#create-env-id').val();
        
        if (!provider || !envId) {
            showToast('Preencha todos os campos obrigatórios', 'error');
            return;
        }
        
        let credentialData = {};
        
        if (provider === 'salesforce') {
            credentialData = {
                operacao: $('#create-operacao').val(),
                automation_id: $('#create-automation-id').val()
            };
        } else if (provider === 'rcs') {
            credentialData = {
                chave_api: $('#create-chave-api').val(),
                codigo_equipe: $('#create-codigo-equipe').val(),
                codigo_usuario: $('#create-codigo-usuario').val()
            };
        } else {
            credentialData = {
                url: $('#create-url').val(),
                token: $('#create-token').val()
            };
        }
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_create_credential',
                nonce: pcAjax.nonce,
                provider: provider,
                env_id: envId,
                credential_data: credentialData
            },
            success: function(response) {
                if (response.success) {
                    showToast('Credencial criada com sucesso!', 'success');
                    $('#create-credential-form')[0].reset();
                    $('#url-token-fields, #salesforce-fields, #rcs-fields').addClass('hidden');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(response.data || 'Erro ao criar credencial', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Editar Credencial
    $(document).on('click', '.edit-credential', function() {
        const card = $(this).closest('.credential-card');
        const provider = card.data('provider');
        const envId = card.data('env-id');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_credential',
                nonce: pcAjax.nonce,
                provider: provider,
                env_id: envId
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#edit-provider').val(provider);
                    $('#edit-env-id').val(envId);
                    
                    let html = '';
                    if (provider === 'salesforce') {
                        html = `
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Operação Name</label>
                                <input type="text" name="operacao" value="${escapeHtml(data.operacao || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Automation ID</label>
                                <input type="text" name="automation_id" value="${escapeHtml(data.automation_id || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                        `;
                    } else if (provider === 'rcs') {
                        html = `
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Chave API</label>
                                <input type="text" name="chave_api" value="${escapeHtml(data.chave_api || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Código da Equipe</label>
                                <input type="text" name="codigo_equipe" value="${escapeHtml(data.codigo_equipe || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Código do Usuário</label>
                                <input type="text" name="codigo_usuario" value="${escapeHtml(data.codigo_usuario || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                        `;
                    } else {
                        html = `
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">API URL</label>
                                <input type="url" name="url" value="${escapeHtml(data.url || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Token/Key</label>
                                <input type="text" name="token" value="${escapeHtml(data.token || '')}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white" required>
                            </div>
                        `;
                    }
                    
                    $('#edit-fields').html(html);
                    $('#edit-modal').removeClass('hidden');
                } else {
                    showToast('Erro ao carregar credencial', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Salvar Edição
    $('#edit-credential-form').on('submit', function(e) {
        e.preventDefault();
        
        const provider = $('#edit-provider').val();
        const envId = $('#edit-env-id').val();
        const formData = {};
        
        $(this).serializeArray().forEach(item => {
            if (item.name !== 'provider' && item.name !== 'env_id') {
                formData[item.name] = item.value;
            }
        });
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_update_credential',
                nonce: pcAjax.nonce,
                provider: provider,
                env_id: envId,
                credential_data: formData
            },
            success: function(response) {
                if (response.success) {
                    showToast('Credencial atualizada com sucesso!', 'success');
                    $('#edit-modal').addClass('hidden');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(response.data || 'Erro ao atualizar', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Deletar Credencial
    $(document).on('click', '.delete-credential', function() {
        if (!confirm('Tem certeza que deseja deletar esta credencial?')) {
            return;
        }
        
        const card = $(this).closest('.credential-card');
        const provider = card.data('provider');
        const envId = card.data('env-id');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_delete_credential',
                nonce: pcAjax.nonce,
                provider: provider,
                env_id: envId
            },
            success: function(response) {
                if (response.success) {
                    showToast('Credencial deletada com sucesso!', 'success');
                    card.fadeOut(300, function() {
                        $(this).remove();
                        if ($('.credential-card').length === 0) {
                            $('#credentials-list').html('<div class="text-center py-8 text-gray-500 dark:text-gray-400"><i class="fas fa-inbox text-4xl mb-4"></i><p>Nenhuma credencial configurada ainda</p></div>');
                        }
                    });
                } else {
                    showToast(response.data || 'Erro ao deletar', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Fechar modal
    $('#close-edit-modal, #cancel-edit').on('click', function() {
        $('#edit-modal').addClass('hidden');
    });
    
    $('#edit-modal').on('click', function(e) {
        if ($(e.target).is('#edit-modal')) {
            $(this).addClass('hidden');
        }
    });

    // Reset form
    $('#reset-form').on('click', function() {
        $('#create-credential-form')[0].reset();
        $('#url-token-fields, #salesforce-fields, #rcs-fields').addClass('hidden');
    });

    // Funções auxiliares
    function escapeHtml(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text || '').replace(/[&<>"']/g, m => map[m]);
    }

    function showToast(message, type = 'info') {
        const bgColor = type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500';
        const toast = $(`
            <div class="fixed top-4 right-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span>${escapeHtml(message)}</span>
            </div>
        `);
        
        $('body').append(toast);
        setTimeout(() => {
            toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
});
</script>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
$plugin_path = $pc_plugin_path;
include $plugin_path . 'base.php';