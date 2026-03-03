<?php
/**
 * Standalone Plugin Trigger
 * Bypasses admin-ajax.php completely to see if the WordPress AJAX router is the culprit.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Direct Plugin Function Trigger</h2><pre>\n";

// Load WordPress minimally
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

// We have to mock the nonce and POST data because the function expects them
$_POST['nonce'] = wp_create_nonce('pc_nonce');
$_SERVER['REQUEST_METHOD'] = 'POST';

// We must spoof the current user to pass the manage_options check
$admins = get_users(['role' => 'administrator']);
if (!empty($admins)) {
    wp_set_current_user($admins[0]->ID);
    echo "Spoofed User: " . $admins[0]->user_login . " (ID: " . $admins[0]->ID . ")\n";
} else {
    echo "WARNING: No administrator found to spoof permissions!\n";
}

echo "Calling handle_run_salesforce_import() via do_action hook...\n----------------------------------------------------\n";

// Buffer the output
ob_start();
try {
    do_action('wp_ajax_pc_run_salesforce_import');
} catch (Throwable $e) {
    echo "\n\nCRASH CAUGHT IN PHP:\n";
    echo $e->getMessage() . "\n" . $e->getTraceAsString();
}
$output = ob_get_clean();

echo "----------------------------------------------------\nRAW OUTPUT FROM FUNCTION:\n";
echo htmlspecialchars($output);
echo "\n\nDone.</pre>";
