<?php
/**
 * Página de Configurações - Gerenciamento de Carteiras
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$current_page = 'configuracoes';
$page_title = 'Configurações';

// Busca bases disponíveis
global $wpdb;
$db_prefix = 'VW_BASE';
$tables = $wpdb->get_results("SHOW TABLES LIKE '{$db_prefix}%'", ARRAY_N);
$bases = [];
if ($tables) {
    foreach ($tables as $table) {
        $bases[] = $table[0];
    }
}

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Configurações</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Gerencie carteiras e vincule bases de dados</p>
    </div>

    <!-- Tabs -->
    <div class="mb-6 border-b border-gray-200 dark:border-gray-700">
        <nav class="flex space-x-8">
            <button id="tab-carteiras" class="tab-button active px-4 py-2 text-sm font-medium border-b-2 border-blue-500 text-blue-600 dark:text-blue-400">
                <i class="fas fa-wallet mr-2"></i>Carteiras
            </button>
        </nav>
    </div>

    <!-- Tab Content: Carteiras -->
    <div id="carteiras-tab-content" class="tab-content">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Formulário de Carteira -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-plus-circle text-blue-500 mr-2"></i>
                        <span id="form-title">Nova Carteira</span>
                    </h3>
                    
                    <form id="form-carteira" class="space-y-4">
                        <input type="hidden" id="carteira-id" name="id" value="">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Nome da Carteira <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="carteira-nome" 
                                name="nome"
                                placeholder="Ex: BRADESCO"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                                required
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                ID da Carteira <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="carteira-id-carteira" 
                                name="id_carteira"
                                placeholder="Ex: BRD001"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                                required
                            >
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Este ID será enviado ao provider no lugar de idgis_ambiente</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Descrição
                            </label>
                            <textarea 
                                id="carteira-descricao" 
                                name="descricao"
                                rows="3"
                                placeholder="Descrição opcional da carteira"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                            ></textarea>
                        </div>
                        
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                <i class="fas fa-save mr-2"></i>Salvar
                            </button>
                            <button type="button" id="btn-cancelar" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors" style="display:none;">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Carteiras -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Carteiras Cadastradas</h3>
                    </div>
                    <div id="lista-carteiras" class="p-6">
                        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-spinner fa-spin text-3xl mb-4"></i>
                            <p>Carregando carteiras...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal para Vincular Bases -->
        <div id="modal-vincular-bases" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
            <div class="bg-white dark:bg-surface-dark rounded-lg shadow-xl max-w-2xl w-full m-4 max-h-[80vh] overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <i class="fas fa-link mr-2"></i>
                        Vincular Bases à Carteira: <span id="modal-carteira-nome"></span>
                    </h3>
                    <button id="btn-fechar-modal" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1">
                    <div id="lista-bases-vincular" class="space-y-2">
                        <!-- Bases serão carregadas aqui -->
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button id="btn-salvar-vinculos" class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-save mr-2"></i>Salvar Vínculos
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>',
        bases: <?php echo json_encode($bases); ?>
    };

    let carteiraEditando = null;
    let carteiraVinculando = null;

    // Carregar carteiras
    function loadCarteiras() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_carteiras',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderCarteiras(response.data);
                }
            }
        });
    }

    function renderCarteiras(carteiras) {
        const container = $('#lista-carteiras');
        if (!carteiras || carteiras.length === 0) {
            container.html('<p class="text-gray-500 dark:text-gray-400 text-center py-8">Nenhuma carteira cadastrada</p>');
            return;
        }

        let html = '';
        carteiras.forEach(carteira => {
            html += `
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1">
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white">
                                ${carteira.nome}
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                <i class="fas fa-hashtag mr-1"></i>ID: ${carteira.id_carteira}
                            </p>
                            ${carteira.descricao ? `<p class="text-sm text-gray-500 dark:text-gray-400 mt-2">${carteira.descricao}</p>` : ''}
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editarCarteira(${carteira.id})" class="px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 rounded hover:bg-blue-200 dark:hover:bg-blue-800 transition-colors">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="vincularBases(${carteira.id}, '${carteira.nome}')" class="px-3 py-1 bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 rounded hover:bg-green-200 dark:hover:bg-green-800 transition-colors">
                                <i class="fas fa-link"></i>
                            </button>
                            <button onclick="deletarCarteira(${carteira.id})" class="px-3 py-1 bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 rounded hover:bg-red-200 dark:hover:bg-red-800 transition-colors">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Bases vinculadas:</p>
                        <div id="bases-carteira-${carteira.id}" class="flex flex-wrap gap-2">
                            <span class="text-xs text-gray-400">Carregando...</span>
                        </div>
                    </div>
                </div>
            `;
            container.html(html);
            
            // Carregar bases vinculadas
            loadBasesCarteira(carteira.id);
        });
    }

    function loadBasesCarteira(carteiraId) {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_bases_carteira',
                nonce: pcAjax.nonce,
                carteira_id: carteiraId
            },
            success: function(response) {
                if (response.success) {
                    const container = $(`#bases-carteira-${carteiraId}`);
                    if (response.data && response.data.length > 0) {
                        let html = '';
                        response.data.forEach(base => {
                            html += `<span class="px-2 py-1 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded text-xs">${base.nome_base}</span>`;
                        });
                        container.html(html);
                    } else {
                        container.html('<span class="text-xs text-gray-400">Nenhuma base vinculada</span>');
                    }
                }
            }
        });
    }

    // Salvar carteira
    $('#form-carteira').on('submit', function(e) {
        e.preventDefault();
        const formData = {
            action: carteiraEditando ? 'pc_update_carteira' : 'pc_create_carteira',
            nonce: pcAjax.nonce,
            id: $('#carteira-id').val(),
            nome: $('#carteira-nome').val(),
            id_carteira: $('#carteira-id-carteira').val(),
            descricao: $('#carteira-descricao').val()
        };

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert('Carteira salva com sucesso!');
                    resetForm();
                    loadCarteiras();
                } else {
                    alert('Erro: ' + (response.data || 'Erro desconhecido'));
                    console.error('Erro ao criar carteira:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro AJAX:', status, error);
                console.error('Resposta:', xhr.responseText);
                alert('Erro ao comunicar com o servidor. Verifique o console para mais detalhes.');
            }
        });
    });

    function resetForm() {
        $('#form-carteira')[0].reset();
        $('#carteira-id').val('');
        $('#form-title').text('Nova Carteira');
        $('#btn-cancelar').hide();
        carteiraEditando = null;
    }

    $('#btn-cancelar').on('click', resetForm);

    // Editar carteira
    window.editarCarteira = function(id) {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_carteira',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    const c = response.data;
                    $('#carteira-id').val(c.id);
                    $('#carteira-nome').val(c.nome);
                    $('#carteira-id-carteira').val(c.id_carteira);
                    $('#carteira-descricao').val(c.descricao || '');
                    $('#form-title').text('Editar Carteira');
                    $('#btn-cancelar').show();
                    carteiraEditando = id;
                    $('html, body').animate({ scrollTop: 0 }, 300);
                }
            }
        });
    };

    // Deletar carteira
    window.deletarCarteira = function(id) {
        if (!confirm('Tem certeza que deseja excluir esta carteira? As bases vinculadas serão desvinculadas.')) return;
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_delete_carteira',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    loadCarteiras();
                } else {
                    alert('Erro: ' + (response.data || 'Erro desconhecido'));
                }
            }
        });
    };

    // Vincular bases
    window.vincularBases = function(carteiraId, carteiraNome) {
        carteiraVinculando = carteiraId;
        $('#modal-carteira-nome').text(carteiraNome);
        
        // Carregar bases já vinculadas
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_bases_carteira',
                nonce: pcAjax.nonce,
                carteira_id: carteiraId
            },
            success: function(response) {
                const basesVinculadas = response.success && response.data ? response.data.map(b => b.nome_base) : [];
                
                let html = '';
                pcAjax.bases.forEach(base => {
                    const checked = basesVinculadas.includes(base) ? 'checked' : '';
                    html += `
                        <label class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                            <input type="checkbox" value="${base}" ${checked} class="mr-3">
                            <span class="text-gray-900 dark:text-white">${base}</span>
                        </label>
                    `;
                });
                $('#lista-bases-vincular').html(html);
                $('#modal-vincular-bases').fadeIn().css('display', 'flex');
            }
        });
    };

    $('#btn-fechar-modal').on('click', function() {
        $('#modal-vincular-bases').fadeOut();
    });

    $('#btn-salvar-vinculos').on('click', function() {
        const basesSelecionadas = [];
        $('#lista-bases-vincular input[type="checkbox"]:checked').each(function() {
            basesSelecionadas.push($(this).val());
        });

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_vincular_base_carteira',
                nonce: pcAjax.nonce,
                carteira_id: carteiraVinculando,
                bases: basesSelecionadas
            },
            success: function(response) {
                if (response.success) {
                    alert('Bases vinculadas com sucesso!');
                    $('#modal-vincular-bases').fadeOut();
                    loadCarteiras();
                } else {
                    alert('Erro: ' + (response.data || 'Erro desconhecido'));
                }
            }
        });
    });

    // Carregar dados iniciais
    loadCarteiras();
});
</script>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';
