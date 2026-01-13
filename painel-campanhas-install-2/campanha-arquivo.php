<?php
/**
 * Página de Campanha via Arquivo
 */

if (!defined('ABSPATH')) exit;

$current_page = 'campanha-arquivo';
$page_title = 'Campanha via Arquivo';

// Providers disponíveis
$providers = [
    'CDA' => 'CDA',
    'GOSAC' => 'GOSAC',
    'NOAH' => 'NOAH',
    'RCS' => 'RCS',
    'SALESFORCE' => 'Salesforce'
];

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

ob_start();
?>
<div class="max-w-6xl mx-auto">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Campanha via Arquivo</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Faça upload de um arquivo CSV com os dados da campanha</p>
    </div>

    <!-- Instruções -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
        <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-2">
            <i class="fas fa-info-circle mr-2"></i>Formato do Arquivo
        </h3>
        <p class="text-sm text-blue-800 dark:text-blue-400 mb-2">
            O arquivo CSV deve conter as seguintes colunas (na primeira linha):
        </p>
        <ul class="text-sm text-blue-800 dark:text-blue-400 list-disc list-inside space-y-1">
            <li><strong>nome</strong> - Nome do cliente <span class="text-red-500">(obrigatório)</span></li>
            <li><strong>telefone</strong> - Telefone com código do país + DDD + número <span class="text-red-500">(obrigatório)</span> - Formato: 5511999999999 (55 + DDD + número)</li>
            <li><strong>cpf</strong> - CPF/CNPJ <span class="text-red-500">(obrigatório)</span></li>
            <li><strong>carteira</strong> - Nome da carteira (opcional)</li>
            <li><strong>contrato</strong> - Número do contrato (opcional)</li>
            <li><strong>id_carteira</strong> - ID da carteira para envio ao provider (opcional, se não informado será usado o id_carteira da carteira vinculada)</li>
        </ul>
        <p class="text-sm text-red-600 dark:text-red-400 mt-2 font-semibold">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            Atenção: Telefone deve incluir código do país (55) + DDD (2 dígitos) + número (9 ou 10 dígitos). Exemplo: 5511999999999
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Upload do Arquivo -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <i class="fas fa-upload text-blue-500 mr-2"></i>
                Upload do Arquivo
            </h3>
            
            <form id="form-upload-file" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Arquivo CSV <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="file" 
                        id="file-upload" 
                        name="file"
                        accept=".csv"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                </div>
                
                <button type="submit" class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-upload mr-2"></i>Fazer Upload e Validar
                </button>
            </form>
            
            <div id="upload-status" class="mt-4 hidden"></div>
        </div>

        <!-- Configuração da Campanha -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <i class="fas fa-cog text-green-500 mr-2"></i>
                Configuração
            </h3>
            
            <form id="form-campaign-config" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Template de Mensagem <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="template-select" 
                        name="template_id"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                        <option value="">-- Selecione um template --</option>
                        <?php foreach ($message_templates as $template): ?>
                            <option value="<?php echo esc_attr($template->ID); ?>">
                                <?php echo esc_html($template->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Provider <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="provider-select" 
                        name="provider"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                        <option value="">-- Selecione um provider --</option>
                        <?php foreach ($providers as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="preview-section" class="hidden">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <p class="text-sm font-medium text-gray-900 dark:text-white mb-2">Preview:</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            <span id="preview-count">0</span> registros válidos encontrados
                        </p>
                    </div>
                </div>
                
                <button type="submit" id="btn-create-campaign" class="w-full px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors" disabled>
                    <i class="fas fa-paper-plane mr-2"></i>Criar Campanha
                </button>
            </form>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>'
    };

    let uploadedFileData = null;

    // Upload do arquivo
    $('#form-upload-file').on('submit', function(e) {
        e.preventDefault();
        
        const fileInput = $('#file-upload')[0];
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Por favor, selecione um arquivo');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'pc_upload_campaign_file');
        formData.append('nonce', pcAjax.nonce);
        formData.append('file', fileInput.files[0]);

        $('#upload-status').removeClass('hidden').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-gray-600 dark:text-gray-400">Processando arquivo...</p></div>');

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    uploadedFileData = response.data;
                    $('#upload-status').html(`
                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                            <p class="text-green-800 dark:text-green-300 text-sm">
                                <i class="fas fa-check-circle mr-2"></i>
                                Arquivo processado com sucesso! ${response.data.total_records} registros encontrados.
                            </p>
                        </div>
                    `);
                    $('#preview-section').removeClass('hidden');
                    $('#preview-count').text(response.data.valid_records || 0);
                    $('#btn-create-campaign').prop('disabled', false);
                } else {
                    $('#upload-status').html(`
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                            <p class="text-red-800 dark:text-red-300 text-sm">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                ${response.data || 'Erro ao processar arquivo'}
                            </p>
                        </div>
                    `);
                }
            },
            error: function() {
                $('#upload-status').html(`
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <p class="text-red-800 dark:text-red-300 text-sm">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            Erro de conexão ao fazer upload
                        </p>
                    </div>
                `);
            }
        });
    });

    // Criar campanha
    $('#form-campaign-config').on('submit', function(e) {
        e.preventDefault();
        
        if (!uploadedFileData) {
            alert('Por favor, faça upload do arquivo primeiro');
            return;
        }

        const formData = {
            action: 'pc_create_campaign_from_file',
            nonce: pcAjax.nonce,
            file_data: uploadedFileData,
            template_id: $('#template-select').val(),
            provider: $('#provider-select').val()
        };

        $('#btn-create-campaign').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Criando campanha...');

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert('Campanha criada com sucesso! ' + response.data.message);
                    // Resetar formulários
                    $('#form-upload-file')[0].reset();
                    $('#form-campaign-config')[0].reset();
                    $('#upload-status').addClass('hidden');
                    $('#preview-section').addClass('hidden');
                    uploadedFileData = null;
                    $('#btn-create-campaign').prop('disabled', true).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                } else {
                    alert('Erro: ' + (response.data || 'Erro desconhecido'));
                    $('#btn-create-campaign').prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                }
            },
            error: function() {
                alert('Erro de conexão');
                $('#btn-create-campaign').prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
            }
        });
    });
});
</script>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

