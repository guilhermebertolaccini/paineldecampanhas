<?php
/**
 * Página de Nova Campanha - Integrada com Campaign Manager e CPF Campaign Manager
 */

if (!defined('ABSPATH')) exit;

$current_page = 'nova-campanha';
$page_title = 'Nova Campanha';

global $wpdb;
$db_prefix = 'VW_BASE';

// Busca tabelas disponíveis
$tables = $wpdb->get_results("SHOW TABLES LIKE '{$db_prefix}%'", ARRAY_N);
if (!$tables) {
    $tables = [];
}

// Busca templates de mensagem
$message_templates = get_posts([
    'post_type' => 'message_template',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
]);
if (!$message_templates) {
    $message_templates = [];
}

// Providers disponíveis
$providers = [
    'CDA' => 'CDA',
    'GOSAC' => 'GOSAC',
    'NOAH' => 'NOAH',
    'RCS' => 'RCS',
    'SALESFORCE' => 'Salesforce'
];

$current_user_id = get_current_user_id();

ob_start();
?>
<div class="max-w-6xl mx-auto">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Criar Nova Campanha</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Escolha o tipo de campanha e configure os detalhes</p>
    </div>

    <!-- Seleção do Tipo de Campanha -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Tipo de Campanha</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="campaign-type-card cursor-pointer border-2 border-gray-200 dark:border-gray-700 rounded-lg p-6 hover:border-blue-500 dark:hover:border-blue-500 transition-all" data-type="normal">
                <input type="radio" name="campaign_type" value="normal" checked class="hidden campaign-type-radio">
                <div class="text-center">
                    <i class="fas fa-database text-4xl text-blue-500 mb-3"></i>
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Campanha Normal</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Selecione uma base de dados e aplique filtros</p>
                </div>
            </label>
            <label class="campaign-type-card cursor-pointer border-2 border-gray-200 dark:border-gray-700 rounded-lg p-6 hover:border-purple-500 dark:hover:border-purple-500 transition-all" data-type="cpf">
                <input type="radio" name="campaign_type" value="cpf" class="hidden campaign-type-radio">
                <div class="text-center">
                    <i class="fas fa-file-csv text-4xl text-purple-500 mb-3"></i>
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Campanha por CPF</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Faça upload de CSV com CPFs ou telefones</p>
                </div>
            </label>
        </div>
    </div>

    <!-- Formulário Campanha Normal -->
    <div id="normal-campaign-form" class="campaign-form">
        <!-- Step 1: Base de Dados -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📊 Passo 1: Selecione a Base de Dados</h3>
            <select id="data-source-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                <option value="">-- Escolha uma base --</option>
                <?php foreach ($tables as $table): ?>
                    <option value="<?php echo esc_attr($table[0]); ?>">
                        <?php echo esc_html($table[0]); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Step 2: Filtros -->
        <div id="filters-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">🔍 Passo 2: Filtros</h3>
            <div id="filters-container">
                <p class="text-gray-600 dark:text-gray-400">⏳ Carregando filtros...</p>
            </div>
            <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <strong class="text-gray-900 dark:text-white">👥 Audiência:</strong>
                <span id="audience-count" class="text-blue-600 dark:text-blue-400 font-semibold ml-2">0</span>
                <span class="text-gray-600 dark:text-gray-400">clientes</span>
            </div>
        </div>

        <!-- Step 3: Detalhes -->
        <div id="details-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">⚙️ Passo 3: Detalhes da Campanha</h3>
            
            <!-- Template -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    📄 Template da Mensagem *
                </label>
                <select id="template-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    <option value="">-- Escolha um template --</option>
                    <?php foreach ($message_templates as $template): ?>
                        <option value="<?php echo esc_attr($template->ID); ?>" data-content="<?php echo esc_attr($template->post_content); ?>">
                            <?php echo esc_html($template->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Preview da Mensagem -->
            <div id="message-preview-container" class="mb-6" style="display:none;">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    👁️ Preview da Mensagem
                </label>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div id="message-preview" class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap"></div>
                    <div class="text-right mt-2 text-sm text-gray-500 dark:text-gray-400">
                        <span id="char-count">0</span> / 160 caracteres
                    </div>
                </div>
            </div>

            <!-- Provedores -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    🌐 Provedores *
                </label>
                <div class="mb-3">
                    <label class="flex items-center space-x-2 mb-2">
                        <input type="radio" name="distribution_mode" value="split" checked class="distribution-mode">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Dividir entre provedores (por porcentagem)</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="radio" name="distribution_mode" value="all" class="distribution-mode">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Enviar para todos os provedores</span>
                    </label>
                </div>
                <div id="providers-container" class="space-y-3">
                    <?php foreach ($providers as $code => $name): ?>
                        <div class="provider-item flex items-center justify-between p-3 border border-gray-300 dark:border-gray-600 rounded-lg">
                            <label class="flex items-center space-x-2 cursor-pointer flex-1">
                                <input type="checkbox" class="provider-checkbox" value="<?php echo esc_attr($code); ?>">
                                <span class="text-sm font-medium text-gray-900 dark:text-white"><?php echo esc_html($name); ?></span>
                            </label>
                            <div class="provider-percent-container ml-4" style="display:none;">
                                <input type="number" 
                                       class="provider-percent w-20 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm" 
                                       data-provider="<?php echo esc_attr($code); ?>" 
                                       value="0" 
                                       min="0" 
                                       max="100" 
                                       placeholder="%">
                                <span class="text-sm text-gray-600 dark:text-gray-400 ml-1">%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="percent-total" class="mt-2 text-sm text-gray-600 dark:text-gray-400" style="display:none;">
                    Total: <span id="percent-sum">0</span>%
                </div>
            </div>

            <!-- Limite -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    📊 Limite de Registros (opcional)
                </label>
                <input type="number" id="record-limit" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white" placeholder="Ex: 1000" min="0">
            </div>

            <!-- Opção de Excluir Telefones Recentes -->
            <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" id="exclude-recent-phones" checked class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                        🚫 Excluir telefones que já receberam campanhas entre ontem e hoje
                    </span>
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-2 ml-6">
                    Esta opção evita enviar mensagens para telefones que já receberam campanhas nos últimos 2 dias
                </p>
            </div>

            <!-- Opção de Salvar como Template -->
            <div class="mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" id="save-as-template" class="w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500">
                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                        💾 Salvar como template recorrente
                    </span>
                </label>
                <div id="template-name-container" class="mt-3" style="display:none;">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Nome do Template *
                    </label>
                    <input type="text" id="template-name" placeholder="Ex: Campanha Semanal - Clientes VIP" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-yellow-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                </div>
            </div>

            <!-- Botão Criar -->
            <div class="flex gap-4">
                <button id="create-campaign-btn" class="px-6 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all transform hover:scale-105">
                    <i class="fas fa-paper-plane mr-2"></i>Criar Campanha
                </button>
                <a href="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancelar
                </a>
            </div>
        </div>
    </div>

    <!-- Formulário Campanha por CPF -->
    <div id="cpf-campaign-form" class="campaign-form" style="display:none;">
        <!-- Step 1: Upload CSV -->
        <div id="cpf-upload-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📁 Passo 1: Upload do Arquivo CSV</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Envie um CSV contendo um CPF ou telefone por linha</p>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Tipo de cruzamento *
                </label>
                <select id="matching-field" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white" required>
                    <option value="">-- Escolha o tipo de dado --</option>
                    <option value="cpf">CPF (11 dígitos)</option>
                    <option value="telefone">Telefone (DDD + número)</option>
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Informe se o arquivo está com CPFs ou telefones. Vamos cruzar com a base usando o tipo escolhido.
                </p>
            </div>
            
            <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center cursor-pointer hover:border-purple-500 transition-colors relative" id="cpf-upload-area">
                <i class="fas fa-file-csv text-4xl text-gray-400 mb-3"></i>
                <p class="text-gray-700 dark:text-gray-300"><strong>Clique para selecionar</strong> ou arraste o arquivo aqui</p>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Apenas arquivos .csv (máx 10MB)</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Formato aceito: NOME;TELEFONE;CPF (com ou sem cabeçalho)</p>
                <input type="file" id="cpf-csv-file-input" accept=".csv" style="position: absolute; opacity: 0; width: 100%; height: 100%; top: 0; left: 0; cursor: pointer; z-index: 10; pointer-events: none;">
            </div>
            
            <div id="cpf-upload-preview" class="mt-4" style="display:none;">
                <div class="p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                    <h4 class="font-semibold text-green-800 dark:text-green-300 mb-2">✅ Arquivo carregado</h4>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-2"><strong>Total de registros no CSV:</strong> <span id="cpf-count">0</span></p>
                    <div id="cpf-preview-list" class="text-xs text-gray-600 dark:text-gray-400 mb-3 font-mono bg-white dark:bg-gray-800 p-2 rounded"></div>
                    <button id="clear-cpf-upload" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors text-sm">
                        <i class="fas fa-trash mr-2"></i>Remover Arquivo
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Base de Dados -->
        <div id="cpf-table-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📊 Passo 2: Selecione a Base de Dados</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Escolha qual base (VW_BASE...) deseja consultar para cruzar com seu CSV</p>
            <select id="cpf-table-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                <option value="">-- Escolha uma base --</option>
                <?php foreach ($tables as $table): ?>
                    <option value="<?php echo esc_attr($table[0]); ?>">
                        <?php echo esc_html($table[0]); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Step 3: Filtros Adicionais (Opcional) -->
        <div id="cpf-filters-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">🔍 Passo 3: Filtros Adicionais (Opcional)</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Adicione filtros extras para refinar sua busca</p>
            <div id="cpf-filters-container">
                <p class="text-gray-600 dark:text-gray-400">⏳ Carregando filtros...</p>
            </div>
        </div>

        <!-- Step 4: Resultado e Opções -->
        <div id="cpf-download-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">✅ Passo 4: Resultado do Cruzamento</h3>
            
            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800 mb-4">
                <div class="text-center mb-3">
                    <strong class="text-gray-900 dark:text-white text-lg">👥 Registros encontrados:</strong>
                    <span id="cpf-records-count" class="text-blue-600 dark:text-blue-400 font-bold text-2xl ml-2">---</span>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 text-center">
                    O arquivo baixado terá: nome, telefone, CPF e idcob_contrato.
                </p>
            </div>

            <div class="flex gap-4 justify-center">
                <button id="download-cpf-csv-btn" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors font-semibold" disabled>
                    <i class="fas fa-download mr-2"></i>Baixar arquivo limpo
                </button>
                <button id="create-cpf-campaign-btn" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors font-semibold" disabled>
                    <i class="fas fa-paper-plane mr-2"></i>Criar campanha diretamente
                </button>
            </div>
        </div>

        <!-- Step 5: Template, Provedores e Criar Campanha -->
        <div id="cpf-campaign-step" class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6 theme-transition" style="display:none;">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">⚙️ Passo 5: Configurar e Criar Campanha</h3>
            
            <!-- Template -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    📄 Template da Mensagem *
                </label>
                <select id="cpf-template-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    <option value="">-- Escolha um template --</option>
                    <?php foreach ($message_templates as $template): ?>
                        <option value="<?php echo esc_attr($template->ID); ?>" data-content="<?php echo esc_attr($template->post_content); ?>">
                            <?php echo esc_html($template->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Preview da Mensagem -->
            <div id="cpf-message-preview-container" class="mb-6" style="display:none;">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    👁️ Preview da Mensagem
                </label>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div id="cpf-message-preview" class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap"></div>
                    <div class="text-right mt-2 text-sm text-gray-500 dark:text-gray-400">
                        <span id="cpf-char-count">0</span> / 160 caracteres
                    </div>
                </div>
            </div>

            <!-- Provedores -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    🌐 Provedores *
                </label>
                <div class="mb-3">
                    <label class="flex items-center space-x-2 mb-2">
                        <input type="radio" name="cpf-distribution_mode" value="split" checked class="cpf-distribution-mode">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Dividir entre provedores (por porcentagem)</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="radio" name="cpf-distribution_mode" value="all" class="cpf-distribution-mode">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Enviar para todos os provedores</span>
                    </label>
                </div>
                <div id="cpf-providers-container" class="space-y-3">
                    <?php foreach ($providers as $code => $name): ?>
                        <div class="cpf-provider-item flex items-center justify-between p-3 border border-gray-300 dark:border-gray-600 rounded-lg">
                            <label class="flex items-center space-x-2 cursor-pointer flex-1">
                                <input type="checkbox" class="cpf-provider-checkbox" value="<?php echo esc_attr($code); ?>">
                                <span class="text-sm font-medium text-gray-900 dark:text-white"><?php echo esc_html($name); ?></span>
                            </label>
                            <div class="cpf-provider-percent-container ml-4" style="display:none;">
                                <input type="number" 
                                       class="cpf-provider-percent w-20 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm" 
                                       data-provider="<?php echo esc_attr($code); ?>" 
                                       value="0" 
                                       min="0" 
                                       max="100" 
                                       placeholder="%">
                                <span class="text-sm text-gray-600 dark:text-gray-400 ml-1">%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="cpf-percent-total" class="mt-2 text-sm text-gray-600 dark:text-gray-400" style="display:none;">
                    Total: <span id="cpf-percent-sum">0</span>%
                </div>
            </div>

            <!-- Botão Criar -->
            <div class="flex gap-4">
                <button id="create-cpf-campaign-btn-final" class="px-6 py-2 bg-gradient-to-r from-purple-500 to-pink-600 text-white rounded-lg hover:from-purple-600 hover:to-pink-700 transition-all transform hover:scale-105" disabled>
                    <i class="fas fa-paper-plane mr-2"></i>Criar Campanha
                </button>
                <a href="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancelar
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.campaign-type-card input:checked ~ div,
.campaign-type-card:has(input:checked) {
    border-color: #3b82f6;
    background-color: #eff6ff;
}
.dark .campaign-type-card:has(input:checked) {
    background-color: #1e3a8a;
}
</style>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

