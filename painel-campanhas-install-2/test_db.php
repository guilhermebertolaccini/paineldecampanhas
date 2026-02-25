<?php
// Usar o diretório do WordPress (paineldecampanhascerto é o diretório raiz do WP na web?)
// O script vai ser executado de d:\paineldecampanhascerto\painel-campanhas-install-2\
require_once 'D:\paineldecampanhascerto\wp-load.php';
global $wpdb;
$table = $wpdb->prefix . 'envios_pendentes';

echo "\n--- Teste de Banco de Dados ---\n";
echo "Tabela: $table\n";

$id = 'O20260223153735';

$query = $wpdb->prepare("
    SELECT *
    FROM {$table}
    WHERE agendamento_id = %s
", $id);

$results = $wpdb->get_results($query, ARRAY_A);
echo "Total encontrados para {$id}: " . count($results) . "\n";

if (count($results) > 0) {
    echo "Status do primeiro: " . $results[0]['status'] . "\n";
}

$all_ids = $wpdb->get_col("SELECT DISTINCT agendamento_id FROM {$table} ORDER BY id DESC LIMIT 10");
echo "\nÚltimos 10 IDs na tabela:\n";
print_r($all_ids);

$desc_tbl = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
$has_carteira = false;
foreach ($desc_tbl as $col) {
    if ($col['Field'] == 'id_carteira') {
        $has_carteira = true;
    }
}
echo "\nTem coluna id_carteira? " . ($has_carteira ? 'SIM' : 'NAO') . "\n";

?>