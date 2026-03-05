<?php
/**
 * Test script to debug Ótima API connection.
 * Run this from the root of the painel-campanhas-install-2 directory.
 */

// Load WordPress minimally
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

$customer_code = '311';
// Get token from options
$static_credentials = get_option('acm_static_credentials', []);
$token = trim($static_credentials['otima_rcs_token'] ?? '');
$token = trim(preg_replace('/^Bearer\s+/i', '', $token));

echo "--- Ótima API Debug ---" . PHP_EOL;
echo "Customer Code: $customer_code" . PHP_EOL;
echo "Token (masked): " . substr($token, 0, 4) . "..." . substr($token, -4) . PHP_EOL;

$url = "https://services.otima.digital/v1/rcs/template/{$customer_code}";
echo "URL: $url" . PHP_EOL;

$args = [
    'headers' => [
        'Authorization' => $token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ],
    'timeout' => 15,
];

$response = wp_remote_get($url, $args);

if (is_wp_error($response)) {
    echo "WP_Error: " . $response->get_error_message() . PHP_EOL;
    print_r($response->get_error_codes());
} else {
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    echo "HTTP Status: $code" . PHP_EOL;
    echo "Response Body: " . PHP_EOL;
    echo $body . PHP_EOL;

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Parse Error: " . json_last_error_msg() . PHP_EOL;
    } else {
        echo "Parsed Data count: " . (is_array($data) ? count($data) : "not an array") . PHP_EOL;
        // Check for 'data' wrapper
        if (isset($data['data'])) {
            echo "Unwrapped 'data' count: " . count($data['data']) . PHP_EOL;
        }
    }
}
