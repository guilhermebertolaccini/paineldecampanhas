<?php
/**
 * Script para forçar criação das tabelas do plugin
 * 
 * INSTRUÇÕES:
 * 1. Acesse: http://seusite.com/wp-content/plugins/painel-campanhas-install-2/create-tables.php
 * 2. O script irá criar todas as tabelas necessárias
 * 3. DELETE este arquivo após usar (por segurança)
 */

// Carrega o WordPress
require_once('../../../wp-load.php');

// Verifica se o usuário está logado como admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Acesso negado. Você precisa estar logado como administrador.');
}

echo '<h1>Criação de Tabelas - Painel de Campanhas</h1>';
echo '<p>Criando tabelas do plugin...</p>';

global $wpdb;
$charset_collate = $wpdb->get_charset_collate();
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

// Tabela de custos por provider
$table_custos = $wpdb->prefix . 'pc_custos_providers';
$sql_custos = "CREATE TABLE IF NOT EXISTS $table_custos (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    provider varchar(50) NOT NULL,
    custo_por_disparo decimal(10,4) NOT NULL DEFAULT 0.0000,
    ativo tinyint(1) DEFAULT 1,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_provider (provider)
) $charset_collate;";
dbDelta($sql_custos);
echo '<p>✅ Tabela ' . $table_custos . ' criada/verificada</p>';

// Tabela de orçamentos por base (VW_BASE*)
$table_orcamentos = $wpdb->prefix . 'pc_orcamentos_bases';
$sql_orcamentos = "CREATE TABLE IF NOT EXISTS $table_orcamentos (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    nome_base varchar(150) NOT NULL,
    orcamento_total decimal(10,2) NOT NULL DEFAULT 0.00,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_base (nome_base)
) $charset_collate;";
dbDelta($sql_orcamentos);
echo '<p>✅ Tabela ' . $table_orcamentos . ' criada/verificada</p>';

// Tabela de carteiras
$table_carteiras = $wpdb->prefix . 'pc_carteiras';
$sql_carteiras = "CREATE TABLE IF NOT EXISTS $table_carteiras (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    nome varchar(255) NOT NULL,
    id_carteira varchar(100) NOT NULL,
    descricao text,
    ativo tinyint(1) DEFAULT 1,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_id_carteira (id_carteira)
) $charset_collate;";
dbDelta($sql_carteiras);
echo '<p>✅ Tabela ' . $table_carteiras . ' criada/verificada</p>';

// Tabela de vínculo entre carteiras e bases (VW_BASE*)
$table_carteiras_bases = $wpdb->prefix . 'pc_carteiras_bases';
$sql_carteiras_bases = "CREATE TABLE IF NOT EXISTS $table_carteiras_bases (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    carteira_id bigint(20) NOT NULL,
    nome_base varchar(150) NOT NULL,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_carteira_base (carteira_id, nome_base),
    KEY idx_carteira (carteira_id),
    KEY idx_base (nome_base)
) $charset_collate;";
dbDelta($sql_carteiras_bases);
echo '<p>✅ Tabela ' . $table_carteiras_bases . ' criada/verificada</p>';

// Tabela de iscas (baits)
$table_baits = $wpdb->prefix . 'cm_baits';
$sql_baits = "CREATE TABLE IF NOT EXISTS $table_baits (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    telefone varchar(20) NOT NULL,
    nome varchar(255) NOT NULL,
    id_carteira bigint(20),
    cpf varchar(14),
    idgis_ambiente int(11),
    ativo tinyint(1) DEFAULT 1,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_carteira (id_carteira)
) $charset_collate;";
dbDelta($sql_baits);
echo '<p>✅ Tabela ' . $table_baits . ' criada/verificada</p>';

// Tabela de mapeamento IDGIS
$table_idgis_mappings = $wpdb->prefix . 'cm_idgis_mappings';
$sql_idgis_mappings = "CREATE TABLE IF NOT EXISTS $table_idgis_mappings (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    tabela_origem varchar(150) NOT NULL,
    provedor_destino varchar(100) NOT NULL,
    idgis_ambiente_original int(11) NOT NULL,
    idgis_ambiente_mapeado int(11) NOT NULL,
    ativo tinyint(1) DEFAULT 1,
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    atualizado_em datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_mapping (tabela_origem, provedor_destino, idgis_ambiente_original)
) $charset_collate;";
dbDelta($sql_idgis_mappings);
echo '<p>✅ Tabela ' . $table_idgis_mappings . ' criada/verificada</p>';

// Verifica se houve erros
if ($wpdb->last_error) {
    echo '<p style="color: red;">❌ Erro: ' . $wpdb->last_error . '</p>';
} else {
    echo '<h2>✅ Todas as tabelas foram criadas/verificadas com sucesso!</h2>';
}

echo '<hr>';
echo '<p><strong>⚠️ IMPORTANTE:</strong> Delete este arquivo (create-tables.php) após usar por segurança!</p>';

