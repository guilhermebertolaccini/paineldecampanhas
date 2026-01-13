<?php
/**
 * Página de Cadastro de Custos e Orçamentos
 */

if (!defined('ABSPATH')) exit;

$current_page = 'controle-custo-cadastro';
$page_title = 'Cadastro de Custos';

// Providers disponíveis
$providers = ['CDA', 'GOSAC', 'NOAH', 'RCS', 'SALESFORCE'];

// Busca carteiras disponíveis
global $wpdb;
$carteiras_table = $wpdb->prefix . 'pc_carteiras';
$carteiras = $wpdb->get_results(
    "SELECT id, nome, id_carteira FROM $carteiras_table WHERE ativo = 1 ORDER BY nome",
    ARRAY_A
);
if (!$carteiras) {
    $carteiras = [];
}

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Cadastro de Custos</h2>
                <p class="text-gray-600 dark:text-gray-400 mt-2">Configure custos por provider e orçamentos por carteira</p>
            </div>
            <a href="<?php echo esc_url(home_url('/painel/controle-custo')); ?>" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Voltar
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Cadastro de Custos por Provider -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 theme-transition">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-tag text-blue-500"></i>
                <span>Custos por Provider</span>
            </h3>
            
            <form id="form-custo-provider" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Provider <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="provider-select" 
                        name="provider"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                        <option value="">-- Selecione um provider --</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?php echo esc_attr($provider); ?>">
                                <?php echo esc_html($provider); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Custo por Disparo (R$) <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        id="custo-disparo" 
                        name="custo_por_disparo"
                        step="0.0001"
                        min="0"
                        placeholder="0.0000"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Ex: 0.0500 = R$ 0,05 por disparo</p>
                </div>
                
                <button type="submit" class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>Salvar Custo
                </button>
            </form>
            
            <!-- Lista de Custos Cadastrados -->
            <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Custos Cadastrados</h4>
                <div id="lista-custos" class="space-y-2">
                    <div class="text-center py-4 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Carregando...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cadastro de Orçamentos por Carteira -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 theme-transition">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i class="fas fa-wallet text-green-500"></i>
                <span>Orçamentos por Carteira</span>
            </h3>
            
            <form id="form-orcamento-carteira" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Carteira <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="carteira-select" 
                        name="carteira_id"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                        <option value="">-- Selecione uma carteira --</option>
                        <?php foreach ($carteiras as $carteira): ?>
                            <option value="<?php echo esc_attr($carteira['id']); ?>">
                                <?php echo esc_html($carteira['nome'] . ' (' . $carteira['id_carteira'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($carteiras)): ?>
                        <p class="text-xs text-orange-600 dark:text-orange-400 mt-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Nenhuma carteira cadastrada. <a href="<?php echo esc_url(home_url('/painel/configuracoes')); ?>" class="underline">Criar carteira</a>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Orçamento Total (R$) <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        id="orcamento-total" 
                        name="orcamento_total"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                        required
                    >
                </div>
                
                <button type="submit" class="w-full px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fas fa-save mr-2"></i>Salvar Orçamento
                </button>
            </form>
            
            <!-- Lista de Orçamentos Cadastrados -->
            <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Orçamentos Cadastrados</h4>
                <div id="lista-orcamentos" class="space-y-2">
                    <div class="text-center py-4 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Carregando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>'
    };

    // Carregar custos e orçamentos
    function loadCustos() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_custos_providers',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderCustos(response.data);
                } else {
                    $('#lista-custos').html('<p class="text-red-500 text-center py-4">Erro ao carregar: ' + (response.data || 'Erro desconhecido') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro AJAX:', status, error);
                $('#lista-custos').html('<p class="text-red-500 text-center py-4">Erro de conexão. Verifique o console.</p>');
            }
        });
    }

    function loadOrcamentos() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_orcamentos_bases',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderOrcamentos(response.data);
                } else {
                    $('#lista-orcamentos').html('<p class="text-red-500 text-center py-4">Erro ao carregar: ' + (response.data || 'Erro desconhecido') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro AJAX:', status, error);
                $('#lista-orcamentos').html('<p class="text-red-500 text-center py-4">Erro de conexão. Verifique o console.</p>');
            }
        });
    }

    function renderCustos(custos) {
        const container = $('#lista-custos');
        if (!custos || custos.length === 0) {
            container.html('<p class="text-gray-500 dark:text-gray-400 text-center py-4">Nenhum custo cadastrado</p>');
            return;
        }

        let html = '';
        custos.forEach(custo => {
            html += `
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">${custo.provider}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 ml-2">
                            R$ ${parseFloat(custo.custo_por_disparo).toFixed(4)} por disparo
                        </span>
                    </div>
                    <button onclick="deleteCusto(${custo.id})" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
        });
        container.html(html);
    }

    function renderOrcamentos(orcamentos) {
        const container = $('#lista-orcamentos');
        if (!orcamentos || orcamentos.length === 0) {
            container.html('<p class="text-gray-500 dark:text-gray-400 text-center py-4">Nenhum orçamento cadastrado</p>');
            return;
        }

        let html = '';
        orcamentos.forEach(orcamento => {
            html += `
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">${orcamento.nome_base || 'N/A'}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 ml-2">
                            R$ ${parseFloat(orcamento.orcamento_total || 0).toFixed(2)}
                        </span>
                    </div>
                    <button onclick="deleteOrcamento(${orcamento.id})" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
        });
        container.html(html);
    }

    // Salvar custo
    $('#form-custo-provider').on('submit', function(e) {
        e.preventDefault();
        const formData = {
            action: 'pc_save_custo_provider',
            nonce: pcAjax.nonce,
            provider: $('#provider-select').val(),
            custo_por_disparo: $('#custo-disparo').val()
        };

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert('Custo salvo com sucesso!');
                    $('#form-custo-provider')[0].reset();
                    loadCustos();
                } else {
                    alert('Erro: ' + response.data);
                }
            }
        });
    });

    // Salvar orçamento
    $('#form-orcamento-carteira').on('submit', function(e) {
        e.preventDefault();
        const carteiraId = $('#carteira-select').val();
        if (!carteiraId) {
            alert('Selecione uma carteira');
            return;
        }
        
        // Busca as bases vinculadas à carteira
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_bases_carteira',
                nonce: pcAjax.nonce,
                carteira_id: carteiraId
            },
            success: function(basesResponse) {
                if (!basesResponse.success || !basesResponse.data || basesResponse.data.length === 0) {
                    alert('Esta carteira não tem bases vinculadas. Vincule bases em Configurações primeiro.');
                    return;
                }
                
                const bases = basesResponse.data;
                const orcamentoTotal = parseFloat($('#orcamento-total').val());
                const orcamentoPorBase = orcamentoTotal / bases.length;
                
                // Salva orçamento para cada base vinculada
                let saved = 0;
                let errors = 0;
                
                bases.forEach(function(base) {
                    $.ajax({
                        url: pcAjax.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'pc_save_orcamento_base',
                            nonce: pcAjax.nonce,
                            nome_base: base.nome_base,
                            orcamento_total: orcamentoPorBase.toFixed(2)
                        },
                        success: function(response) {
                            if (response.success) {
                                saved++;
                            } else {
                                errors++;
                            }
                            
                            // Quando todas as requisições terminarem
                            if (saved + errors === bases.length) {
                                if (errors === 0) {
                                    alert('Orçamento salvo com sucesso para ' + saved + ' base(s)!');
                                    $('#form-orcamento-carteira')[0].reset();
                                    loadOrcamentos();
                                } else {
                                    alert('Orçamento salvo parcialmente: ' + saved + ' sucesso, ' + errors + ' erros');
                                    loadOrcamentos();
                                }
                            }
                        },
                        error: function() {
                            errors++;
                            if (saved + errors === bases.length) {
                                alert('Erro ao salvar orçamento. Tente novamente.');
                            }
                        }
                    });
                });
            },
            error: function() {
                alert('Erro ao buscar bases da carteira');
            }
        });
    });

    // Deletar custo
    window.deleteCusto = function(id) {
        if (!confirm('Tem certeza que deseja excluir este custo?')) return;
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_delete_custo_provider',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    loadCustos();
                } else {
                    alert('Erro: ' + response.data);
                }
            }
        });
    };

    // Deletar orçamento
    window.deleteOrcamento = function(id) {
        if (!confirm('Tem certeza que deseja excluir este orçamento?')) return;
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_delete_orcamento_base',
                nonce: pcAjax.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    loadOrcamentos();
                } else {
                    alert('Erro: ' + (response.data || 'Erro desconhecido'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro AJAX:', status, error);
                alert('Erro de conexão. Verifique o console.');
            }
        });
    };

    // Carregar dados iniciais
    loadCustos();
    loadOrcamentos();
});
</script>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

