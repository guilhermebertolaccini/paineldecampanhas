<?php
require_once('wp-load.php');
global $wpdb;

$envios_table = $wpdb->prefix . 'envios_pendentes';

echo "Tabela de envios: " . $envios_table . "\n";

$count = $wpdb->get_var("SELECT COUNT(*) FROM {$envios_table}");
echo "Total de registros em {$envios_table}: " . $count . "\n";

$agrupado = $wpdb->get_results("SELECT agendamento_id, COUNT(*) as qtd, MIN(data_cadastro) as data_cadastro FROM {$envios_table} GROUP BY agendamento_id ORDER BY data_cadastro DESC LIMIT 10");

echo "Últimas 10 campanhas (agendamento_id):\n";
foreach ($agrupado as $row) {
    echo "- " . $row->agendamento_id . " com " . $row->qtd . " registros (criado em " . $row->data_cadastro . ")\n";
}
