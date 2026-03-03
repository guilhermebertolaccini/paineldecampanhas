<?php
require_once 'd:\paineldecampanhascerto\wp-load.php';
global $wpdb;
try {
    $sql = "INSERT INTO test (a,b,c) VALUES (%s, %s, %s)";
    $prepared = $wpdb->prepare($sql, '1', '2', '3');
    echo "Prepare OK: " . $prepared;
} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage();
}
