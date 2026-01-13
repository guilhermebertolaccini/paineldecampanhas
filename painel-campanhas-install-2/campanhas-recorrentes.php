<?php
/**
 * Página de Campanhas Recorrentes
 */

if (!defined('ABSPATH')) exit;

$current_page = 'campanhas-recorrentes';
$page_title = 'Campanhas Recorrentes';

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Campanhas Recorrentes</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Gerencie seus templates de campanha salvos</p>
    </div>

    <div id="recurring-campaigns-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="col-span-full">
            <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i>
                <p class="text-gray-600 dark:text-gray-400">Carregando campanhas...</p>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('campaign-manager-nonce')); ?>',
        homeUrl: '<?php echo esc_js(home_url()); ?>'
    };

    function loadTemplates() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_get_recurring',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderTemplates(response.data);
                } else {
                    $('#recurring-campaigns-list').html(
                        '<div class="col-span-full"><div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro ao carregar campanhas</p></div></div>'
                    );
                }
            },
            error: function() {
                $('#recurring-campaigns-list').html(
                    '<div class="col-span-full"><div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro de conexão</p></div></div>'
                );
            }
        });
    }

    function renderTemplates(templates) {
        const container = $('#recurring-campaigns-list');
        container.empty();

        if (templates.length === 0) {
            container.html(`
                <div class="col-span-full">
                    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
                        <i class="fas fa-inbox text-5xl text-gray-300 dark:text-gray-600 mb-4"></i>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Nenhuma campanha recorrente</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-6">Crie sua primeira campanha recorrente na página Nova Campanha</p>
                        <a href="${pcAjax.homeUrl}/painel/nova-campanha" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                            <i class="fas fa-plus mr-2"></i>Criar Campanha Recorrente
                        </a>
                    </div>
                </div>
            `);
            return;
        }

        templates.forEach(function(template) {
            const providersConfig = JSON.parse(template.providers_config || '{}');
            const providers = (providersConfig.providers || []).join(', ') || 'Nenhum';
            const isActive = template.ativo == 1;
            const statusClass = isActive 
                ? 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-300 border-green-200 dark:border-green-800' 
                : 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700';
            const statusIcon = isActive ? 'fa-check-circle' : 'fa-pause-circle';
            const statusText = isActive ? 'Ativo' : 'Inativo';
            
            const ultimaExecucao = template.ultima_execucao 
                ? new Date(template.ultima_execucao).toLocaleString('pt-BR')
                : 'Nunca executado';

            const card = $(`
                <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow">
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1 truncate" title="${escapeHtml(template.nome_campanha)}">${escapeHtml(template.nome_campanha)}</h3>
                            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <span class="flex items-center min-w-0" title="${escapeHtml(template.tabela_origem)}">
                                    <i class="fas fa-database mr-1 flex-shrink-0"></i>
                                    <span class="truncate">${escapeHtml(template.tabela_origem)}</span>
                                </span>
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold border ${statusClass} flex-shrink-0 ml-2">
                            <i class="fas ${statusIcon} mr-1"></i>${statusText}
                        </span>
                    </div>

                    <!-- Info -->
                    <div class="space-y-2 mb-4">
                        <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                            <i class="fas fa-globe w-4 mr-2 flex-shrink-0"></i>
                            <span class="truncate">${escapeHtml(providers)}</span>
                        </div>
                        <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                            <i class="fas fa-clock w-4 mr-2 flex-shrink-0"></i>
                            <span>Última execução: ${ultimaExecucao}</span>
                        </div>
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-center" data-count-id="${template.id}">
                            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 template-count-${template.id}">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">registros disponíveis</div>
                        </div>
                        <!-- Mensagem -->
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-comment-dots text-gray-400 dark:text-gray-500 mt-0.5 flex-shrink-0"></i>
                                <div class="flex-1 min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Mensagem:</div>
                                    <div class="text-sm text-gray-700 dark:text-gray-300 message-preview-${template.id}">
                                        <i class="fas fa-spinner fa-spin"></i> Carregando...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exclusão de telefones recentes -->
                    <div class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" class="exclude-recent-execute mt-1 w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500" data-template-id="${template.id}" checked>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    🚫 Excluir telefones que receberam mensagem entre ontem e hoje
                                </div>
                                <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    Evita enviar para telefones que já receberam campanhas recentemente
                                </div>
                            </div>
                        </label>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-2 flex-wrap">
                        <button class="execute-now flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors text-sm font-medium" data-id="${template.id}">
                            <i class="fas fa-play mr-2"></i>Executar Agora
                        </button>
                        <button class="toggle-status px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors text-sm font-medium" data-id="${template.id}" data-ativo="${template.ativo}">
                            <i class="fas ${isActive ? 'fa-pause' : 'fa-play'} mr-2"></i>${isActive ? 'Desativar' : 'Ativar'}
                        </button>
                        <button class="delete-template px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors text-sm font-medium" data-id="${template.id}">
                            <i class="fas fa-trash mr-2"></i>Deletar
                        </button>
                    </div>
                </div>
            `);

            container.append(card);
            loadCount(template.id);
            loadMessage(template.id, template.template_id);
        });
    }

    function loadMessage(campaignId, templateId) {
        if (!templateId) {
            $(`.message-preview-${campaignId}`).html('<span class="text-gray-400">Nenhuma mensagem configurada</span>');
            return;
        }

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_get_template_content',
                nonce: pcAjax.nonce,
                template_id: templateId
            },
            success: function(response) {
                if (response.success) {
                    // Garante que é uma string
                    let message = '';
                    if (typeof response.data === 'string') {
                        message = response.data;
                    } else if (typeof response.data === 'object' && response.data !== null) {
                        // Se for objeto, tenta extrair o conteúdo
                        message = response.data.content || response.data.message || JSON.stringify(response.data);
                    } else {
                        message = String(response.data || '');
                    }
                    
                    const truncated = message.length > 100 ? message.substring(0, 100) + '...' : message;
                    $(`.message-preview-${campaignId}`).html(escapeHtml(truncated));
                    if (message.length > 100) {
                        $(`.message-preview-${campaignId}`).attr('title', escapeHtml(message));
                    }
                } else {
                    $(`.message-preview-${campaignId}`).html('<span class="text-gray-400">Erro ao carregar mensagem</span>');
                }
            },
            error: function() {
                $(`.message-preview-${campaignId}`).html('<span class="text-gray-400">Erro ao carregar mensagem</span>');
            }
        });
    }

    function loadCount(id) {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_preview_recurring_count',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    const count = response.data.count.toLocaleString('pt-BR');
                    $(`.template-count-${id}`).html(count);
                } else {
                    $(`.template-count-${id}`).html('---');
                }
            },
            error: function() {
                $(`.template-count-${id}`).html('---');
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Execute Now
    $(document).on('click', '.execute-now', function() {
        if (!confirm('⚡ Executar esta campanha recorrente agora?')) return;

        const id = $(this).data('id');
        const btn = $(this);
        const excludeRecent = $(`.exclude-recent-execute[data-template-id="${id}"]`).is(':checked');
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Verificando base...');

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_execute_recurring_now',
                nonce: pcAjax.nonce,
                id: id,
                exclude_recent_phones: excludeRecent ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    btn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Executando...');
                    let message = response.data.message || response.data;
                    if (response.data && response.data.records_skipped > 0 && response.data.exclusion_enabled) {
                        message += ` | ⚠️ ${response.data.records_skipped} telefones excluídos`;
                    }
                    alert('✅ ' + message);
                    loadTemplates();
                } else {
                    // Verifica se é erro de base desatualizada
                    const errorMsg = response.data || 'Erro desconhecido';
                    if (errorMsg.includes('Base desatualizada') || errorMsg.includes('não foi atualizada hoje')) {
                        alert('⚠️ ' + errorMsg);
                    } else {
                        alert('❌ Erro: ' + errorMsg);
                    }
                    btn.prop('disabled', false).html('<i class="fas fa-play mr-2"></i>Executar Agora');
                }
            },
            error: function() {
                alert('❌ Erro de conexão');
                btn.prop('disabled', false).html('<i class="fas fa-play mr-2"></i>Executar Agora');
            }
        });
    });

    // Toggle Status
    $(document).on('click', '.toggle-status', function() {
        const id = $(this).data('id');
        const ativo = $(this).data('ativo') == 1 ? 0 : 1;

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_toggle_recurring',
                nonce: pcAjax.nonce,
                id: id,
                ativo: ativo
            },
            success: function(response) {
                if (response.success) {
                    loadTemplates();
                } else {
                    alert('❌ Erro: ' + (response.data || 'Erro desconhecido'));
                }
            },
            error: function() {
                alert('❌ Erro de conexão');
            }
        });
    });

    // Delete
    $(document).on('click', '.delete-template', function() {
        if (!confirm('⚠️ Deletar esta campanha recorrente? Esta ação não pode ser desfeita!')) return;

        const id = $(this).data('id');

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_delete_recurring',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    loadTemplates();
                } else {
                    alert('❌ Erro: ' + (response.data || 'Erro desconhecido'));
                }
            },
            error: function() {
                alert('❌ Erro de conexão');
            }
        });
    });

    // Load templates on page load
    loadTemplates();
});
</script>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

