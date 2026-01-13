<?php
/**
 * Script para forçar flush das rewrite rules
 * 
 * INSTRUÇÕES:
 * 1. Coloque este arquivo na raiz do WordPress (mesmo diretório do wp-config.php)
 * 2. Acesse: http://seusite.com/flush-routes.php
 * 3. O script irá forçar o flush das rewrite rules
 * 4. DELETE este arquivo após usar (por segurança)
 */

// Carrega o WordPress
require_once('wp-load.php');

// Verifica se o usuário está logado como admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Acesso negado. Você precisa estar logado como administrador.');
}

echo '<h1>Flush de Rewrite Rules</h1>';
echo '<p>Forçando atualização das rewrite rules...</p>';

// Registra as rotas do plugin
if (class_exists('Painel_Campanhas')) {
    $plugin = new Painel_Campanhas();
    $plugin->add_rewrite_rules();
    echo '<p>✅ Rotas do plugin registradas</p>';
} else {
    echo '<p>⚠️ Plugin Painel_Campanhas não encontrado. Certifique-se de que o plugin está ativo.</p>';
}

// Força o flush
flush_rewrite_rules(true);
echo '<p>✅ Flush de rewrite rules executado</p>';

// Limpa opções relacionadas
delete_option('pc_needs_flush');
delete_option('pc_force_flush_once');
update_option('pc_rewrite_rules_version', time());

echo '<h2>✅ Concluído!</h2>';
echo '<p>Agora tente acessar: <a href="' . home_url('/painel/login') . '">' . home_url('/painel/login') . '</a></p>';
echo '<hr>';
echo '<p><strong>⚠️ IMPORTANTE:</strong> Delete este arquivo (flush-routes.php) após usar por segurança!</p>';

