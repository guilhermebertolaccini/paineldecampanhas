<?php
/**
 * Standalone test script to debug Ótima API connection without WordPress dependencies.
 */

// CONFIGURATION - Put your real token here to test, or it will try to find it in the usual place
$customer_code = '311';
$token = '70aa331a9ea64b96b1cd40aae6918c6f'; // Use the token from the user's curl example

echo "--- Ótima API Standalone Debug ---" . PHP_EOL;
echo "Customer Code: $customer_code" . PHP_EOL;
echo "URL: https://services.otima.digital/v1/rcs/template/$customer_code" . PHP_EOL;

$url = "https://services.otima.digital/v1/rcs/template/$customer_code";

$options = [
    'http' => [
        'method' => 'GET',
        'header' => [
            "Authorization: $token",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);
$status_line = $http_response_header[0];

echo "Status Line: $status_line" . PHP_EOL;
echo "Response Body: " . PHP_EOL;
echo $response . PHP_EOL;
