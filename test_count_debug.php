<?php
require_once(dirname(__FILE__) . '/wp-load.php');

$table_name = 'wp_minha_base'; // Example, replace if we can find a real one
// Let's just do a direct call to check the error

global $wpdb;
$sql = "SELECT TELEFONE as telefone, COALESCE(CPF, CPF_CNPJ) as cpf_cnpj FROM `{$table_name}` WHERE 1=1 LIMIT 5";
$records = $wpdb->get_results($sql, ARRAY_A);
if ($wpdb->last_error) {
    echo "ERROR: " . $wpdb->last_error . "\n";
} else {
    echo "SUCCESS: " . count($records) . " records\n";
    print_r($records);
}
