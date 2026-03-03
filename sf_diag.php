<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Salesforce Raw API Diagnostic</h2>";
echo "<pre>";

try {
    $sf_auth_url      = 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.auth.marketingcloudapis.com';
    $sf_rest_url      = 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.rest.marketingcloudapis.com';
    $sf_client_id     = 'bv53kgt3ocyggeua4synj2v0';
    $sf_client_secret = 'VqfpNASD3Q8bEyD4ktXqQhKJ';
    $sf_account_id    = '536007880';
    $sf_de_key        = 'Tracking_WhatsApp_Importado_FINAL';

    echo "[1] Requesting OAuth Token...\n";
    $ch = curl_init($sf_auth_url . '/v2/token');
    $payload = json_encode([
        'grant_type'    => 'client_credentials',
        'client_id'     => $sf_client_id,
        'client_secret' => $sf_client_secret,
        'account_id'    => $sf_account_id,
    ]);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $token_response = curl_exec($ch);
    $token_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        die("[FATAL] cURL error on token request: " . curl_error($ch));
    }
    curl_close($ch);

    echo "[2] Token HTTP Status: $token_http\n";
    
    $token_body = json_decode($token_response, true);
    $access_token = $token_body['access_token'] ?? '';
    
    if (empty($access_token)) {
        die("[FATAL] Token not returned. Response: " . $token_response);
    }

    echo "[3] Token retrieved successfully. " . substr($access_token, 0, 15) . "...\n";

    echo "[4] Requesting data page 1...\n";
    $url = $sf_rest_url . '/data/v1/customobjectdata/key/' . $sf_de_key . '/rowset?$page=1&$pageSize=5';
    
    $ch2 = curl_init($url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    
    $data_response = curl_exec($ch2);
    $data_http = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch2)) {
        die("[FATAL] cURL error on data request: " . curl_error($ch2));
    }
    curl_close($ch2);

    echo "[5] Data HTTP Status: $data_http\n";
    
    $body = json_decode($data_response, true);
    if (!is_array($body)) {
         die("[FATAL] Response is not JSON. Raw body:\n" . substr($data_response, 0, 500));
    }

    $items = $body['items'] ?? [];
    echo "[6] Items retrieved on page 1: " . count($items) . "\n";
    
    if (count($items) > 0) {
        echo "[7] First Item Keys:\n";
        print_r($items[0]['keys'] ?? []);
        echo "    First Item Values (first 3 fields):\n";
        $vals = $items[0]['values'] ?? [];
        print_r(array_slice($vals, 0, 3));
    }

    echo "\n[SUCCESS] Networking and Extractor logic works perfectly.\n";

} catch (\Throwable $e) {
    echo "\n[FATAL PHP EXCEPTION CAUGHT]\n";
    echo $e->getMessage() . "\n";
    echo "Line " . $e->getLine() . " in " . $e->getFile() . "\n";
}

echo "</pre>";
