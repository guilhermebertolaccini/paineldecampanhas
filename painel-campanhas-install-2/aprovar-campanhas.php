<?php
/**
 * Página de Aprovar Campanhas (Apenas Admin)
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$current_page = 'aprovar-campanhas';
$page_title = 'Aprovar Campanhas';

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Aprovar Campanhas</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Aprove ou negue campanhas pendentes de aprovação</p>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <input type="text" id="filter-agendamento" placeholder="🔍 Filtrar por Agendamento ID" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                <input type="text" id="filter-fornecedor" placeholder="🔍 Filtrar por Fornecedor" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            </div>
            <button id="refresh-campaigns" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors flex items-center gap-2">
                <i class="fas fa-sync-alt"></i>
                <span>Atualizar</span>
            </button>
        </div>
    </div>

    <!-- Lista de Campanhas -->
    <div id="campaigns-list" class="space-y-4">
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i>
            <p class="text-gray-600 dark:text-gray-400">Carregando campanhas...</p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>',
        homeUrl: '<?php echo esc_js(home_url()); ?>'
    };

    // Carregar campanhas
    function loadCampaigns() {
        const filterAgendamento = $('#filter-agendamento').val().trim();
        const filterFornecedor = $('#filter-fornecedor').val().trim();
        
        $('#campaigns-list').html('<div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center"><i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i><p class="text-gray-600 dark:text-gray-400">Carregando campanhas...</p></div>');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_pending_campaigns',
                nonce: pcAjax.nonce,
                filter_agendamento: filterAgendamento,
                filter_fornecedor: filterFornecedor
            },
            success: function(response) {
                if (response.success) {
                    renderCampaigns(response.data);
                } else {
                    $('#campaigns-list').html('<div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro ao carregar campanhas</p></div>');
                }
            },
            error: function() {
                $('#campaigns-list').html('<div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro de conexão</p></div>');
            }
        });
    }

    // Renderizar campanhas
    function renderCampaigns(campaigns) {
        if (!campaigns || campaigns.length === 0) {
            $('#campaigns-list').html(`
                <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <i class="fas fa-inbox text-4xl text-gray-400 mb-4"></i>
                    <p class="text-gray-600 dark:text-gray-400">Nenhuma campanha pendente de aprovação</p>
                </div>
            `);
            return;
        }

        let html = '';
        campaigns.forEach(campaign => {
            const createdDate = new Date(campaign.created_at).toLocaleDateString('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            html += `
                <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow campaign-card" data-agendamento="${escapeHtml(campaign.agendamento_id)}" data-fornecedor="${escapeHtml(campaign.provider)}">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <code class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-sm font-mono">${escapeHtml(campaign.agendamento_id)}</code>
                                </h3>
                                <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-300 rounded-full text-xs font-semibold">
                                    ⏳ Pendente Aprovação
                                </span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-gray-600 dark:text-gray-400">
                                <div>
                                    <i class="fas fa-globe mr-2"></i>
                                    <strong>Fornecedor:</strong> ${escapeHtml(campaign.provider.toUpperCase())}
                                </div>
                                <div>
                                    <i class="fas fa-users mr-2"></i>
                                    <strong>Clientes:</strong> ${formatNumber(campaign.total_clients)}
                                </div>
                                <div>
                                    <i class="fas fa-calendar mr-2"></i>
                                    <strong>Criado em:</strong> ${createdDate}
                                </div>
                                <div>
                                    <i class="fas fa-id-card mr-2"></i>
                                    <strong>IDGIS:</strong> ${campaign.idgis_ambiente}
                                </div>
                                <div>
                                    <i class="fas fa-user mr-2"></i>
                                    <strong>Criado por:</strong> ${escapeHtml(campaign.scheduled_by)}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button class="approve-campaign flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors flex items-center justify-center gap-2" data-agendamento="${escapeHtml(campaign.agendamento_id)}" data-fornecedor="${escapeHtml(campaign.provider)}">
                            <i class="fas fa-check"></i>
                            <span>Aprovar</span>
                        </button>
                        <button class="deny-campaign px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors flex items-center justify-center gap-2" data-agendamento="${escapeHtml(campaign.agendamento_id)}">
                            <i class="fas fa-times"></i>
                            <span>Negar</span>
                        </button>
                    </div>
                </div>
            `;
        });
        
        $('#campaigns-list').html(html);
    }

    // Aprovar campanha
    $(document).on('click', '.approve-campaign', function() {
        const button = $(this);
        const agendamentoId = button.data('agendamento');
        const fornecedor = button.data('fornecedor');
        
        if (!confirm(`Tem certeza que deseja aprovar a campanha ${agendamentoId}?`)) {
            return;
        }
        
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...');
        
        // Buscar configuração do microserviço antes de enviar
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_microservice_config',
                nonce: pcAjax.nonce
            },
            success: function(configResponse) {
                if (!configResponse.success || !configResponse.data) {
                    console.error('❌ [Aprovar Campanha] Erro ao buscar configuração:', configResponse);
                    showToast('Erro ao buscar configuração do microserviço. Configure em API Manager.', 'error');
                    button.prop('disabled', false).html('<i class="fas fa-check mr-2"></i><span>Aprovar</span>');
                    return;
                }
                
                const microserviceUrl = configResponse.data.url || '';
                const apiKey = configResponse.data.api_key || '';
                const dispatchUrl = configResponse.data.dispatch_url || '';
                
                if (!microserviceUrl || !apiKey) {
                    console.error('❌ [Aprovar Campanha] URL ou API Key não configuradas');
                    showToast('URL do microserviço ou API Key não configuradas. Configure em API Manager.', 'error');
                    button.prop('disabled', false).html('<i class="fas fa-check mr-2"></i><span>Aprovar</span>');
                    return;
                }
                
                const payload = {
                    agendamento_id: agendamentoId
                };
                
                // Logs no console
                console.log('🚀 [Aprovar Campanha] ========================================');
                console.log('🔵 URL do Microserviço:', dispatchUrl || microserviceUrl);
                console.log('🔑 API Key:', apiKey ? (apiKey.substring(0, 10) + '...' + apiKey.substring(apiKey.length - 4)) : 'Não configurada');
                console.log('📦 Payload sendo enviado:', JSON.stringify(payload, null, 2));
                console.log('📋 Agendamento ID:', agendamentoId);
                console.log('🏢 Fornecedor:', fornecedor);
                console.log('========================================================');
                
                // Enviar via WordPress backend (evita problemas de CORS)
                $.ajax({
                    url: pcAjax.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pc_approve_campaign',
                        nonce: pcAjax.nonce,
                        agendamento_id: agendamentoId,
                        fornecedor: fornecedor
                    },
                    timeout: 30000,
                    success: function(response) {
                        console.log('✅ [Aprovar Campanha] Resposta do WordPress:', response);
                        if (response.success) {
                            showToast('Campanha aprovada e enviada ao microserviço com sucesso!', 'success');
                            button.closest('.campaign-card').fadeOut(300, function() {
                                $(this).remove();
                                if ($('.campaign-card').length === 0) {
                                    loadCampaigns();
                                }
                            });
                        } else {
                            console.error('❌ [Aprovar Campanha] Erro:', response.data);
                            showToast(response.data || 'Erro ao aprovar campanha. Ela permanecerá pendente para nova tentativa.', 'error');
                            button.prop('disabled', false).html('<i class="fas fa-check mr-2"></i><span>Aprovar</span>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ [Aprovar Campanha] Erro de conexão:', status, error);
                        console.error('❌ [Aprovar Campanha] Status HTTP:', xhr.status);
                        console.error('❌ [Aprovar Campanha] Resposta:', xhr.responseText);
                        showToast('Erro de conexão. A campanha permanecerá pendente.', 'error');
                        button.prop('disabled', false).html('<i class="fas fa-check mr-2"></i><span>Aprovar</span>');
                    }
                });
            },
            error: function(xhr, status, error) {
                console.error('❌ [Aprovar Campanha] Erro ao buscar configuração:', status, error);
                showToast('Erro ao buscar configuração do microserviço. Configure em API Manager.', 'error');
                button.prop('disabled', false).html('<i class="fas fa-check mr-2"></i><span>Aprovar</span>');
            }
        });
    });
    
    // Função auxiliar para remover trailing slash
    function rtrim(str, char) {
        if (!str) return str;
        while (str.endsWith(char)) {
            str = str.slice(0, -1);
        }
        return str;
    }

    // Negar campanha
    $(document).on('click', '.deny-campaign', function() {
        const button = $(this);
        const agendamentoId = button.data('agendamento');
        
        if (!confirm(`Tem certeza que deseja negar a campanha ${agendamentoId}?`)) {
            return;
        }
        
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Negando...');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_deny_campaign',
                nonce: pcAjax.nonce,
                agendamento_id: agendamentoId
            },
            success: function(response) {
                if (response.success) {
                    showToast('Campanha negada com sucesso!', 'success');
                    button.closest('.campaign-card').fadeOut(300, function() {
                        $(this).remove();
                        if ($('.campaign-card').length === 0) {
                            loadCampaigns();
                        }
                    });
                } else {
                    showToast(response.data || 'Erro ao negar campanha', 'error');
                    button.prop('disabled', false).html('<i class="fas fa-times mr-2"></i><span>Negar</span>');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
                button.prop('disabled', false).html('<i class="fas fa-times mr-2"></i><span>Negar</span>');
            }
        });
    });

    // Filtros
    $('#filter-agendamento, #filter-fornecedor').on('keypress', function(e) {
        if (e.which === 13) {
            loadCampaigns();
        }
    });

    $('#refresh-campaigns').on('click', function() {
        loadCampaigns();
    });

    // Funções auxiliares
    function formatNumber(num) {
        return new Intl.NumberFormat('pt-BR').format(Number.isFinite(num) ? num : 0);
    }

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

    // Carregar campanhas ao iniciar
    loadCampaigns();
});
</script>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
$plugin_path = $pc_plugin_path;
include $plugin_path . 'base.php';