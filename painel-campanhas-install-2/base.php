<?php
/**
 * Template base para todas as páginas do Painel de Campanhas
 */

if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$is_admin = current_user_can('manage_options');
global $pc_current_page;
$current_page = $pc_current_page ?? 'home';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($page_title ?? 'Painel de Campanhas'); ?></title>
    <?php wp_head(); ?>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            light: "#2563eb",
                            dark: "#1d4ed8"
                        },
                        secondary: {
                            light: "#64748b",
                            dark: "#475569"
                        },
                        accent: {
                            light: "#0ea5e9",
                            dark: "#0284c7"
                        },
                        background: {
                            light: "#ffffff",
                            dark: "#0f172a"
                        },
                        surface: {
                            light: "#f8fafc",
                            dark: "#1e293b"
                        }
                    },
                    fontFamily: {
                        'sans': ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-background-light dark:bg-background-dark theme-transition font-sans">
    <!-- Theme Toggle -->
    <button id="themeToggle" class="fixed top-4 right-4 z-50 w-10 h-10 rounded-full bg-surface-light dark:bg-surface-dark shadow-lg flex items-center justify-center theme-transition hover:scale-110 transition-transform">
        <i class="fas fa-moon text-gray-600 dark:text-yellow-300 theme-transition" id="themeIcon"></i>
    </button>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar-transition w-64 bg-surface-light dark:bg-surface-dark border-r border-gray-200 dark:border-gray-700 flex flex-col theme-transition">
            <!-- Logo Section -->
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-bullhorn text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Painel de Campanhas</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Workspace</p>
                    </div>
                </div>
            </div>

            <!-- Navigation Sections -->
            <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                <!-- Dashboard -->
                <div>
                    <h3 class="text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 tracking-wider mb-3 px-2">Principal</h3>
                    <ul class="space-y-1">
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/home')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'home') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-home w-5 mr-3"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Campanhas Section -->
                <div class="mt-6">
                    <h3 class="text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 tracking-wider mb-3 px-2">Campanhas</h3>
                    <ul class="space-y-1">
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/campanhas')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'campanhas') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-list-alt w-5 mr-3"></i>
                                <span>Minhas Campanhas</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/nova-campanha')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'nova-campanha') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-plus-circle w-5 mr-3"></i>
                                <span>Nova Campanha</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/campanha-arquivo')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'campanha-arquivo') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-file-upload w-5 mr-3"></i>
                                <span>Campanha via Arquivo</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/campanhas-recorrentes')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'campanhas-recorrentes') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-sync-alt w-5 mr-3"></i>
                                <span>Campanhas Recorrentes</span>
                            </a>
                        </li>
                        <?php if ($is_admin): ?>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/aprovar-campanhas')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'aprovar-campanhas') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-check-circle w-5 mr-3"></i>
                                <span>Aprovar Campanhas</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Mensagens Section -->
                <div class="mt-6">
                    <h3 class="text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 tracking-wider mb-3 px-2">Mensagens</h3>
                    <ul class="space-y-1">
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/mensagens')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'mensagens') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-comment-dots w-5 mr-3"></i>
                                <span>Templates de Mensagem</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Relatórios Section -->
                <div class="mt-6">
                    <h3 class="text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 tracking-wider mb-3 px-2">Relatórios</h3>
                    <ul class="space-y-1">
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/relatorios')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'relatorios') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-chart-bar w-5 mr-3"></i>
                                <span>Relatórios</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/controle-custo')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo (in_array($current_page, ['controle-custo', 'controle-custo-cadastro', 'controle-custo-relatorio'])) ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-dollar-sign w-5 mr-3"></i>
                                <span>Controle de Custo</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Administração Section (Apenas Admin) -->
                <?php if ($is_admin): ?>
                <div class="mt-6">
                    <h3 class="text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 tracking-wider mb-3 px-2">Administração</h3>
                    <ul class="space-y-1">
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/api-manager')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'api-manager') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-key w-5 mr-3"></i>
                                <span>API Manager</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('/painel/configuracoes')); ?>" class="flex items-center px-3 py-2 rounded-lg no-underline <?php echo ($current_page === 'configuracoes') ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> theme-transition">
                                <i class="fas fa-cog w-5 mr-3"></i>
                                <span>Configurações</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </nav>

            <!-- User Profile -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                        <?php echo strtoupper(substr($current_user->display_name, 0, 1)); ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate"><?php echo esc_html($current_user->display_name); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo esc_html($current_user->user_email); ?></p>
                    </div>
                    <button id="logoutBtn" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors" title="Sair">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="content-transition flex-1 bg-gray-50 dark:bg-gray-900 theme-transition overflow-x-hidden">
            <!-- Header -->
            <header class="bg-white dark:bg-surface-dark border-b border-gray-200 dark:border-gray-700 px-6 py-4 theme-transition sticky top-0 z-40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <button id="sidebarToggle" class="lg:hidden text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><?php echo esc_html($page_title ?? 'Dashboard'); ?></h2>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <div class="relative hidden md:block">
                            <input type="text" placeholder="Buscar..." class="w-64 px-4 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent theme-transition">
                            <i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-6">
                <?php echo $content ?? ''; ?>
            </div>
        </main>
    </div>

    <?php wp_footer(); ?>
</body>
</html>

