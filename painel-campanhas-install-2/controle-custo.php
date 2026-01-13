<?php
/**
 * Página Principal de Controle de Custo
 */

if (!defined('ABSPATH')) exit;

$current_page = 'controle-custo';
$page_title = 'Controle de Custo';

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Controle de Custo</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Gerencie custos por provider e orçamentos por base de dados</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Cadastro -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 theme-transition transform hover:scale-105 transition-transform">
            <div class="flex items-center justify-between mb-4">
                <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-cog text-blue-600 dark:text-blue-400 text-2xl"></i>
                </div>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Cadastro</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Configure custos por provider e orçamentos por base de dados</p>
            <a href="<?php echo esc_url(home_url('/painel/controle-custo/cadastro')); ?>" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                <i class="fas fa-arrow-right mr-2"></i>
                Acessar Cadastro
            </a>
        </div>

        <!-- Relatório -->
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 theme-transition transform hover:scale-105 transition-transform">
            <div class="flex items-center justify-between mb-4">
                <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-chart-line text-green-600 dark:text-green-400 text-2xl"></i>
                </div>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Relatório</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Visualize gastos por provider e acompanhe orçamentos por base</p>
            <a href="<?php echo esc_url(home_url('/painel/controle-custo/relatorio')); ?>" class="inline-flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                <i class="fas fa-arrow-right mr-2"></i>
                Ver Relatório
            </a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

