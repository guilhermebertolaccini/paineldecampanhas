<?php
/**
 * Página de Listagem de Campanhas
 */

if (!defined('ABSPATH')) exit;

$current_page = 'campanhas';
$page_title = 'Minhas Campanhas';

global $wpdb;
$envios_table = $wpdb->prefix . 'envios_pendentes';
$users_table = $wpdb->users;

// Filtros
$status_filter = $_GET['status'] ?? '';
$fornecedor_filter = $_GET['fornecedor'] ?? '';

// Busca ID do usuário logado
$current_user_id = get_current_user_id();

// Query base - agrupa por agendamento_id, idgis_ambiente, fornecedor, status
// Filtra apenas campanhas do usuário logado
$query = "
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
    WHERE t1.current_user_id = %d
";

// Aplica filtros
if ($status_filter) {
    $query .= $wpdb->prepare(" AND t1.status = %s", $status_filter);
}

if ($fornecedor_filter) {
    $query .= $wpdb->prepare(" AND t1.fornecedor = %s", $fornecedor_filter);
}

// Prepara a query com o user_id
$query = $wpdb->prepare($query, $current_user_id);

$query .= "
    GROUP BY t1.agendamento_id, t1.idgis_ambiente, t1.fornecedor, t1.status, scheduled_by
    ORDER BY MIN(t1.data_cadastro) DESC
";

$campanhas = $wpdb->get_results($query, ARRAY_A);

// Busca fornecedores únicos para o filtro (apenas do usuário logado)
$fornecedores = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT fornecedor FROM {$envios_table} WHERE current_user_id = %d AND fornecedor IS NOT NULL AND fornecedor != '' ORDER BY fornecedor",
    $current_user_id
));

ob_start();
?>
<div class="mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Todas as Campanhas</h2>
        <a href="<?php echo esc_url(home_url('/painel/nova-campanha')); ?>" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all transform hover:scale-105">
            <i class="fas fa-plus mr-2"></i>Nova Campanha
        </a>
    </div>
</div>

<!-- Filtros -->
<div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6 theme-transition">
    <form method="GET" action="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="flex flex-col md:flex-row gap-4">
        <select 
            name="status" 
            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
        >
            <option value="">Todos os status</option>
            <option value="pendente_aprovacao" <?php selected($status_filter, 'pendente_aprovacao'); ?>>Pendente Aprovação</option>
            <option value="enviado" <?php selected($status_filter, 'enviado'); ?>>Enviado</option>
            <option value="erro" <?php selected($status_filter, 'erro'); ?>>Erro</option>
        </select>
        <select 
            name="fornecedor" 
            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
        >
            <option value="">Todos os fornecedores</option>
            <?php foreach ($fornecedores as $fornecedor): ?>
                <option value="<?php echo esc_attr($fornecedor); ?>" <?php selected($fornecedor_filter, $fornecedor); ?>>
                    <?php echo esc_html(strtoupper($fornecedor)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
            <i class="fas fa-filter mr-2"></i>Filtrar
        </button>
        <?php if ($status_filter || $fornecedor_filter): ?>
            <a href="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors flex items-center">
                <i class="fas fa-times mr-2"></i>Limpar
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Lista de Campanhas -->
<div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 theme-transition">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Agendamento ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ambiente</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Fornecedor</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Clientes</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Data</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Criado por</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($campanhas)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-inbox text-4xl mb-4"></i>
                            <p>Nenhuma campanha encontrada</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($campanhas as $campanha): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    <?php echo esc_html($campanha['agendamento_id'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    <?php echo esc_html($campanha['idgis_ambiente'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    <?php echo esc_html(strtoupper($campanha['provider'] ?? 'N/A')); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold"><?php echo esc_html($campanha['total_clients'] ?? 0); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status_class = [
                                    'pendente_aprovacao' => 'bg-orange-100 text-orange-600 dark:bg-orange-900 dark:text-orange-300',
                                    'enviado' => 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
                                    'erro' => 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-300',
                                ];
                                $status = $campanha['status'] ?? 'pendente';
                                $class = $status_class[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo esc_attr($class); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                <?php echo esc_html(date('d/m/Y H:i', strtotime($campanha['data_cadastro']))); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                <?php echo esc_html($campanha['scheduled_by'] ?? 'N/A'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
global $pc_plugin_path;
include $pc_plugin_path . 'base.php';

