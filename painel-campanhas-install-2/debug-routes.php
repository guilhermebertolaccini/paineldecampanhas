<?php
/**
 * Script de Debug para Rotas
 * 
 * INSTRUÇÕES:
 * 1. Coloque este arquivo na raiz do WordPress (mesmo diretório do wp-config.php)
 * 2. Acesse: http://localhost/wordpress/debug-routes.php
 * 3. Veja as informações de debug
 * 4. DELETE este arquivo após usar (por segurança)
 */

// Carrega o WordPress
require_once('wp-load.php');

// Verifica se o usuário está logado como admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Acesso negado. Você precisa estar logado como administrador.');
}

echo '<h1>🔍 Debug de Rotas - Painel de Campanhas</h1>';
echo '<style>body{font-family:monospace;padding:20px;} .success{color:green;} .error{color:red;} .info{color:blue;} pre{background:#f5f5f5;padding:10px;border:1px solid #ddd;}</style>';

// 1. Verifica se o plugin está ativo
if (class_exists('Painel_Campanhas')) {
    echo '<p class="success">✅ Plugin Painel_Campanhas está ativo</p>';
} else {
    echo '<p class="error">❌ Plugin Painel_Campanhas NÃO está ativo</p>';
    die();
}

// 2. Verifica query vars
global $wp;
$query_vars = $wp->query_vars;
echo '<h2>Query Vars Registradas:</h2>';
echo '<pre>';
print_r($query_vars);
echo '</pre>';

// 3. Verifica rewrite rules
global $wp_rewrite;
echo '<h2>Rewrite Rules Registradas (últimas 50):</h2>';
$rules = $wp_rewrite->wp_rewrite_rules();
$painel_rules = [];
foreach ($rules as $pattern => $rewrite) {
    if (strpos($pattern, 'painel') !== false || strpos($rewrite, 'pc_page') !== false) {
        $painel_rules[$pattern] = $rewrite;
    }
}

if (!empty($painel_rules)) {
    echo '<p class="success">✅ Encontradas ' . count($painel_rules) . ' rotas do painel:</p>';
    echo '<pre>';
    foreach ($painel_rules as $pattern => $rewrite) {
        echo htmlspecialchars($pattern) . ' => ' . htmlspecialchars($rewrite) . "\n";
    }
    echo '</pre>';
} else {
    echo '<p class="error">❌ Nenhuma rota do painel encontrada nas rewrite rules!</p>';
}

// 4. Testa get_query_var
echo '<h2>Teste de Query Vars:</h2>';
$_GET['pc_page'] = 'home';
$test_page = get_query_var('pc_page');
if ($test_page === 'home') {
    echo '<p class="success">✅ get_query_var("pc_page") funciona</p>';
} else {
    echo '<p class="error">❌ get_query_var("pc_page") retornou: ' . var_export($test_page, true) . '</p>';
}

// 5. Informações do ambiente
echo '<h2>Informações do Ambiente:</h2>';
echo '<pre>';
echo 'Home URL: ' . home_url() . "\n";
echo 'Site URL: ' . site_url() . "\n";
echo 'REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
echo 'Permalink Structure: ' . get_option('permalink_structure') . "\n";
echo 'WordPress Version: ' . get_bloginfo('version') . "\n";
echo 'PHP Version: ' . PHP_VERSION . "\n";
echo '</pre>';

// 6. Testa URL específica
echo '<h2>Teste de URL:</h2>';
$test_url = home_url('/painel/home');
echo '<p>Teste acessando: <a href="' . $test_url . '" target="_blank">' . $test_url . '</a></p>';

// 7. Força flush
echo '<h2>Ações:</h2>';
if (isset($_GET['flush'])) {
    flush_rewrite_rules(true);
    echo '<p class="success">✅ Flush de rewrite rules executado!</p>';
    echo '<p><a href="?">Recarregar</a></p>';
} else {
    echo '<p><a href="?flush=1">🔄 Forçar Flush de Rewrite Rules</a></p>';
}

echo '<hr>';
echo '<p><strong>⚠️ IMPORTANTE:</strong> Delete este arquivo (debug-routes.php) após usar por segurança!</p>';

