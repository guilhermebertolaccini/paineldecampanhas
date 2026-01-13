<?php
/**
 * Página Home/Dashboard
 */

if (!defined('ABSPATH')) exit;

$pc = Painel_Campanhas::get_instance();
$current_page = 'home';
$page_title = 'Dashboard';

// Busca dados para o dashboard da tabela envios_pendentes
global $wpdb;
$envios_table = $wpdb->prefix . 'envios_pendentes';
$users_table = $wpdb->users;

// Total de campanhas únicas (agrupadas por agendamento_id, fornecedor)
$total_campanhas = $wpdb->get_var("
    SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', fornecedor))
    FROM {$envios_table}
");

// Campanhas pendentes de aprovação
$campanhas_pendentes = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', fornecedor))
    FROM {$envios_table}
    WHERE status = %s
", 'pendente_aprovacao'));

// Campanhas enviadas
$campanhas_enviadas = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', fornecedor))
    FROM {$envios_table}
    WHERE status = %s
", 'enviado'));

// Campanhas criadas hoje
$campanhas_hoje = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT CONCAT(agendamento_id, '-', fornecedor))
    FROM {$envios_table}
    WHERE DATE(data_cadastro) = %s
", current_time('Y-m-d')));

// Últimas campanhas (agrupadas por agendamento_id, idgis_ambiente, fornecedor, status)
$ultimas_campanhas = $wpdb->get_results("
    SELECT
        t1.agendamento_id,
        t1.idgis_ambiente,
        t1.fornecedor AS provider,
        t1.status,
        MIN(t1.data_cadastro) AS data_cadastro,
        COUNT(t1.id) AS total_clients,
        COALESCE(u.display_name, 'Usuário Desconhecido') AS scheduled_by
    FROM `{$envios_table}` AS t1
    LEFT JOIN `{$users_table}` AS u ON t1.current_user_id = u.ID
    GROUP BY t1.agendamento_id, t1.idgis_ambiente, t1.fornecedor, t1.status, scheduled_by
    ORDER BY MIN(t1.data_cadastro) DESC
    LIMIT 5
", ARRAY_A);

ob_start();
?>
<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700 theme-transition transform hover:scale-105 transition-transform">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total de Campanhas</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-2"><?php echo esc_html($total_campanhas); ?></p>
            </div>
            <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-bullhorn text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700 theme-transition transform hover:scale-105 transition-transform">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pendentes</p>
                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-2"><?php echo esc_html($campanhas_pendentes); ?></p>
            </div>
            <div class="w-14 h-14 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-orange-600 dark:text-orange-400 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700 theme-transition transform hover:scale-105 transition-transform">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Enviadas</p>
                <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2"><?php echo esc_html($campanhas_enviadas); ?></p>
            </div>
            <div class="w-14 h-14 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-surface-dark rounded-lg p-6 shadow-sm border border-gray-200 dark:border-gray-700 theme-transition transform hover:scale-105 transition-transform">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Hoje</p>
                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 mt-2"><?php echo esc_html($campanhas_hoje); ?></p>
            </div>
            <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-calendar-day text-purple-600 dark:text-purple-400 text-2xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Últimas Campanhas -->
    <div class="lg:col-span-2">
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 theme-transition">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Últimas Campanhas</h3>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($ultimas_campanhas)): ?>
                    <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-inbox text-4xl mb-4"></i>
                        <p>Nenhuma campanha ainda</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($ultimas_campanhas as $campanha): ?>
                        <div class="p-4 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer theme-transition">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo esc_html($campanha['agendamento_id'] ?? 'Sem ID'); ?>
                                    </h4>
                                    <div class="flex items-center gap-4 mt-2 text-sm text-gray-600 dark:text-gray-300">
                                        <span>
                                            <i class="fas fa-building mr-1"></i>
                                            <?php echo esc_html($campanha['idgis_ambiente'] ?? 'N/A'); ?>
                                        </span>
                                        <span>
                                            <i class="fas fa-tag mr-1"></i>
                                            <?php echo esc_html(strtoupper($campanha['provider'] ?? 'N/A')); ?>
                                        </span>
                                        <span>
                                            <i class="fas fa-users mr-1"></i>
                                            <?php echo esc_html($campanha['total_clients'] ?? 0); ?> clientes
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?php echo esc_html(date('d/m/Y H:i', strtotime($campanha['data_cadastro']))); ?>
                                        <?php if (!empty($campanha['scheduled_by'])): ?>
                                            <span class="ml-2">
                                                <i class="fas fa-user mr-1"></i>
                                                <?php echo esc_html($campanha['scheduled_by']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="ml-4">
                                    <?php
                                    $status_class = [
                                        'pendente_aprovacao' => 'bg-orange-100 text-orange-600 dark:bg-orange-900 dark:text-orange-300',
                                        'pendente' => 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-300',
                                        'enviado' => 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
                                        'negado' => 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-300',
                                        'erro' => 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-300',
                                    ];
                                    $status = $campanha['status'] ?? 'pendente';
                                    $class = $status_class[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo esc_attr($class); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                <a href="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="w-full text-center text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium theme-transition block">
                    Ver todas as campanhas →
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="space-y-6">
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 theme-transition">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Ações Rápidas</h3>
            <div class="grid grid-cols-1 gap-3">
                <a href="<?php echo esc_url(home_url('/painel/nova-campanha')); ?>" class="p-4 bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 hover:from-blue-100 hover:to-purple-100 dark:hover:from-blue-800/30 dark:hover:to-purple-800/30 rounded-lg text-blue-600 dark:text-blue-400 theme-transition transform hover:scale-105 transition-transform">
                    <i class="fas fa-plus-circle mb-2 text-2xl"></i>
                    <span class="text-sm font-medium block">Nova Campanha</span>
                </a>
                <a href="<?php echo esc_url(home_url('/painel/mensagens')); ?>" class="p-4 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 hover:from-green-100 hover:to-emerald-100 dark:hover:from-green-800/30 dark:hover:to-emerald-800/30 rounded-lg text-green-600 dark:text-green-400 theme-transition transform hover:scale-105 transition-transform">
                    <i class="fas fa-comment-dots mb-2 text-2xl"></i>
                    <span class="text-sm font-medium block">Templates de Mensagem</span>
                </a>
                <a href="<?php echo esc_url(home_url('/painel/relatorios')); ?>" class="p-4 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 hover:from-purple-100 hover:to-pink-100 dark:hover:from-purple-800/30 dark:hover:to-pink-800/30 rounded-lg text-purple-600 dark:text-purple-400 theme-transition transform hover:scale-105 transition-transform">
                    <i class="fas fa-chart-bar mb-2 text-2xl"></i>
                    <span class="text-sm font-medium block">Relatórios</span>
                </a>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

