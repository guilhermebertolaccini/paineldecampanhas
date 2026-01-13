<?php
/**
 * Página de Mensagens
 */

if (!defined('ABSPATH')) exit;

$current_page = 'mensagens';
$page_title = 'Mensagens';

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Mensagens</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">Gerencie seus templates de mensagem</p>
        </div>
        <button id="create-message-btn" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors flex items-center gap-2">
            <i class="fas fa-plus"></i>
            <span>Nova Mensagem</span>
        </button>
    </div>

    <!-- Messages Grid -->
    <div id="messages-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="col-span-full">
            <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i>
                <p class="text-gray-600 dark:text-gray-400">Carregando mensagens...</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal Criar/Editar Mensagem -->
<div id="message-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white" id="modal-title">Nova Mensagem</h3>
                <button id="close-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <form id="message-form" class="p-6 space-y-4">
            <input type="hidden" id="message-id" name="message_id" value="">
            
            <div>
                <label for="message-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Nome da Mensagem <span class="text-red-500">*</span>
                </label>
                <input type="text" id="message-title" name="title" required
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                       placeholder="Ex: Mensagem de Cobrança">
            </div>
            
            <div>
                <label for="message-content" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Conteúdo da Mensagem <span class="text-red-500">*</span>
                </label>
                <textarea id="message-content" name="content" rows="8" required
                          class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                          placeholder="Digite sua mensagem aqui. Use [[NOME]], [[TELEFONE]], [[CPF]], [[CONTRATO]] como placeholders."></textarea>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Placeholders disponíveis: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">[[NOME]]</code>, 
                    <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">[[TELEFONE]]</code>, 
                    <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">[[CPF]]</code>, 
                    <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">[[CONTRATO]]</code>
                </p>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>
                    Salvar Mensagem
                </button>
                <button type="button" id="cancel-modal" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
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

    // Carregar mensagens
    function loadMessages() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_messages',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderMessages(response.data);
                } else {
                    $('#messages-list').html(
                        '<div class="col-span-full"><div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro ao carregar mensagens</p></div></div>'
                    );
                }
            },
            error: function() {
                $('#messages-list').html(
                    '<div class="col-span-full"><div class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-6 text-center"><p class="text-red-600 dark:text-red-400">Erro de conexão</p></div></div>'
                );
            }
        });
    }

    // Renderizar mensagens
    function renderMessages(messages) {
        if (!messages || messages.length === 0) {
            $('#messages-list').html(`
                <div class="col-span-full">
                    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
                        <i class="fas fa-inbox text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400 mb-4">Nenhuma mensagem criada ainda</p>
                        <button class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors" onclick="openCreateModal()">
                            <i class="fas fa-plus mr-2"></i>Criar Primeira Mensagem
                        </button>
                    </div>
                </div>
            `);
            return;
        }

        const html = messages.map(message => {
            const content = message.content || '';
            const truncated = content.length > 150 ? content.substring(0, 150) + '...' : content;
            const date = new Date(message.date).toLocaleDateString('pt-BR');
            
            return `<div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow">
<div class="flex items-start justify-between mb-4">
<div class="flex-1 min-w-0">
<h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1 truncate" title="${escapeHtml(message.title)}">${escapeHtml(message.title)}</h3>
<p class="text-xs text-gray-500 dark:text-gray-400">Criado em ${date}</p>
</div>
</div>
<div class="mb-4">
<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
<p class="text-sm text-gray-700 dark:text-gray-300 text-left leading-relaxed break-words" title="${escapeHtml(content)}" style="text-align: left; margin: 0; padding: 0; white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(truncated)}</p>
</div>
</div>
<div class="flex gap-2">
<button class="edit-message flex-1 px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors text-sm" data-id="${message.id}">
<i class="fas fa-edit mr-1"></i>Editar
</button>
<button class="delete-message px-3 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors text-sm" data-id="${message.id}">
<i class="fas fa-trash"></i>
</button>
</div>
</div>`;
        }).join('');

        $('#messages-list').html(html);
    }

    // Abrir modal de criação
    function openCreateModal() {
        $('#message-id').val('');
        $('#message-title').val('');
        $('#message-content').val('');
        $('#modal-title').text('Nova Mensagem');
        $('#message-modal').removeClass('hidden');
    }

    // Abrir modal de edição
    function openEditModal(id) {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_message',
                nonce: pcAjax.nonce,
                message_id: id
            },
            success: function(response) {
                if (response.success) {
                    const message = response.data;
                    $('#message-id').val(message.id);
                    $('#message-title').val(message.title);
                    $('#message-content').val(message.content);
                    $('#modal-title').text('Editar Mensagem');
                    $('#message-modal').removeClass('hidden');
                } else {
                    showToast('Erro ao carregar mensagem', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    }

    // Salvar mensagem
    $('#message-form').on('submit', function(e) {
        e.preventDefault();
        
        const messageId = $('#message-id').val();
        const title = $('#message-title').val().trim();
        const content = $('#message-content').val().trim();
        
        if (!title || !content) {
            showToast('Preencha todos os campos', 'error');
            return;
        }
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: messageId ? 'pc_update_message' : 'pc_create_message',
                nonce: pcAjax.nonce,
                message_id: messageId,
                title: title,
                content: content
            },
            success: function(response) {
                if (response.success) {
                    showToast(response.data.message || 'Mensagem salva com sucesso!', 'success');
                    $('#message-modal').addClass('hidden');
                    loadMessages();
                } else {
                    showToast(response.data || 'Erro ao salvar mensagem', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Deletar mensagem
    $(document).on('click', '.delete-message', function() {
        const id = $(this).data('id');
        
        if (!confirm('Tem certeza que deseja deletar esta mensagem?')) {
            return;
        }
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_delete_message',
                nonce: pcAjax.nonce,
                message_id: id
            },
            success: function(response) {
                if (response.success) {
                    showToast('Mensagem deletada com sucesso!', 'success');
                    loadMessages();
                } else {
                    showToast(response.data || 'Erro ao deletar mensagem', 'error');
                }
            },
            error: function() {
                showToast('Erro de conexão', 'error');
            }
        });
    });

    // Event listeners
    $('#create-message-btn').on('click', openCreateModal);
    $(document).on('click', '.edit-message', function() {
        openEditModal($(this).data('id'));
    });
    $('#close-modal, #cancel-modal').on('click', function() {
        $('#message-modal').addClass('hidden');
    });
    
    // Fechar modal ao clicar fora
    $('#message-modal').on('click', function(e) {
        if ($(e.target).is('#message-modal')) {
            $(this).addClass('hidden');
        }
    });

    // Funções auxiliares
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
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

    // Carregar mensagens ao iniciar
    loadMessages();
    
    // Expor função global
    window.openCreateModal = openCreateModal;
});
</script>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
$plugin_path = $pc_plugin_path;
include $plugin_path . 'base.php';