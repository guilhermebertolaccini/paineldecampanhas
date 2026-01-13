<?php
/**
 * Página de Relatório de Custos
 */

if (!defined('ABSPATH')) exit;

$current_page = 'controle-custo-relatorio';
$page_title = 'Relatório de Custos';

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
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Relatório de Custos</h2>
                <p class="text-gray-600 dark:text-gray-400 mt-2">Acompanhe gastos por provider e orçamentos por carteira</p>
            </div>
            <a href="<?php echo esc_url(home_url('/painel/controle-custo')); ?>" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Voltar
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
        <form id="filtros-relatorio" class="flex flex-col md:flex-row gap-4">
            <select 
                id="filter-carteira" 
                name="carteira_id"
                class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
            >
                <option value="">Todas as carteiras</option>
                <?php foreach ($carteiras as $carteira): ?>
                    <option value="<?php echo esc_attr($carteira['id']); ?>">
                        <?php echo esc_html($carteira['nome'] . ' (' . $carteira['id_carteira'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input 
                type="date" 
                id="filter-data-inicio" 
                name="data_inicio"
                class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
            >
            
            <input 
                type="date" 
                id="filter-data-fim" 
                name="data_fim"
                class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
            >
            
            <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                <i class="fas fa-filter mr-2"></i>Filtrar
            </button>
        </form>
    </div>

    <!-- Cards de Resumo -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Orçamento Total</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2" id="total-orcamento">R$ 0,00</p>
                </div>
                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-wallet text-blue-600 dark:text-blue-400"></i>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Gasto</p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-2" id="total-gasto">R$ 0,00</p>
                </div>
                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-money-bill-wave text-red-600 dark:text-red-400"></i>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Saldo Disponível</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2" id="saldo-disponivel">R$ 0,00</p>
                </div>
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400"></i>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total de Disparos</p>
                    <p class="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-2" id="total-disparos">0</p>
                </div>
                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-paper-plane text-purple-600 dark:text-purple-400"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela de Gastos por Provider -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Gastos por Provider</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Provider</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Disparos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Custo Unitário</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Gasto</th>
                    </tr>
                </thead>
                <tbody id="tbody-providers" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-spinner fa-spin text-3xl mb-4"></i>
                            <p>Carregando dados...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabela de Gastos por Carteira -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Gastos por Carteira</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Carteira</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Orçamento</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Gasto</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Saldo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">% Utilizado</th>
                    </tr>
                </thead>
                <tbody id="tbody-bases" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-spinner fa-spin text-3xl mb-4"></i>
                            <p>Carregando dados...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>'
    };

    function loadRelatorio() {
        const formData = {
            action: 'pc_get_relatorio_custos',
            nonce: pcAjax.nonce,
            carteira_id: $('#filter-carteira').val() || '',
            data_inicio: $('#filter-data-inicio').val() || '',
            data_fim: $('#filter-data-fim').val() || ''
        };

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    renderRelatorio(response.data);
                } else {
                    alert('Erro ao carregar relatório: ' + response.data);
                }
            }
        });
    }

    function renderRelatorio(data) {
        // Atualizar cards de resumo
        $('#total-orcamento').text('R$ ' + parseFloat(data.total_orcamento || 0).toFixed(2).replace('.', ','));
        $('#total-gasto').text('R$ ' + parseFloat(data.total_gasto || 0).toFixed(2).replace('.', ','));
        $('#saldo-disponivel').text('R$ ' + parseFloat(data.saldo_disponivel || 0).toFixed(2).replace('.', ','));
        $('#total-disparos').text(data.total_disparos || 0);

        // Renderizar gastos por provider
        const tbodyProviders = $('#tbody-providers');
        if (data.gastos_providers && data.gastos_providers.length > 0) {
            let html = '';
            data.gastos_providers.forEach(item => {
                html += `
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                            ${item.provider}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                            ${item.total_disparos}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                            R$ ${parseFloat(item.custo_unitario || 0).toFixed(4).replace('.', ',')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600 dark:text-red-400">
                            R$ ${parseFloat(item.total_gasto || 0).toFixed(2).replace('.', ',')}
                        </td>
                    </tr>
                `;
            });
            tbodyProviders.html(html);
        } else {
            tbodyProviders.html('<tr><td colspan="4" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">Nenhum dado encontrado</td></tr>');
        }

        // Renderizar gastos por carteira
        const tbodyBases = $('#tbody-bases');
        if (data.gastos_carteiras && data.gastos_carteiras.length > 0) {
            let html = '';
            data.gastos_carteiras.forEach(item => {
                const percentual = item.orcamento > 0 ? ((item.gasto / item.orcamento) * 100).toFixed(2) : 0;
                const percentualClass = percentual > 80 ? 'text-red-600 dark:text-red-400' : percentual > 50 ? 'text-orange-600 dark:text-orange-400' : 'text-green-600 dark:text-green-400';
                
                html += `
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                            ${item.carteira_nome}
                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">(${item.id_carteira})</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                            R$ ${parseFloat(item.orcamento || 0).toFixed(2).replace('.', ',')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 dark:text-red-400">
                            R$ ${parseFloat(item.gasto || 0).toFixed(2).replace('.', ',')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400">
                            R$ ${parseFloat(item.saldo || 0).toFixed(2).replace('.', ',')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-semibold ${percentualClass}">${percentual}%</span>
                        </td>
                    </tr>
                `;
            });
            tbodyBases.html(html);
        } else {
            tbodyBases.html('<tr><td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">Nenhum dado encontrado</td></tr>');
        }
    }

    $('#filtros-relatorio').on('submit', function(e) {
        e.preventDefault();
        loadRelatorio();
    });

    // Carregar relatório inicial
    loadRelatorio();
});
</script>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

