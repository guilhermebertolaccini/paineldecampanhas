<?php
// Script para testar a busca de templates da ótima
require_once 'wp-load.php';

echo ">> Obtendo Token Estático...\n";
$static_credentials = get_option('acm_static_credentials', []);
$token = trim($static_credentials['otima_rcs_token'] ?? '');
echo "Token configurado: " . ($token ? "SIM (" . substr($token, 0, 5) . "...)" : "NAO") . "\n\n";

if (!$token) {
    die("Sem token. Configure no painel.\n");
}

echo ">> Buscando Carteiras ativas para iterar...\n";
global $wpdb;
$table_name = $wpdb->prefix . 'cm_credentials';
$carteiras = $wpdb->get_results("SELECT id, name, provedor, meta_data FROM $table_name WHERE provedor = 'otima_rcs' AND ativo = 1", ARRAY_A);

echo "Carteiras encontradas: " . count($carteiras) . "\n\n";

$all_templates = [];
$errors = [];

foreach ($carteiras as $carteira) {
    $meta = json_decode($carteira['meta_data'], true);
    $customer_code = $meta['customer_code'] ?? '';
    $carteira_nome = $carteira['name'];
    $carteira_id = $carteira['id'];

    echo ">> Processando carteira: {$carteira_nome} (ID: {$carteira_id}, Code: {$customer_code})\n";

    if (empty($customer_code)) {
        echo "   -> Sem customer_code, pulando.\n";
        continue;
    }

    $url = "https://services.otima.digital/v1/rcs/template/{$customer_code}";
    echo "   -> Chamando URL: {$url}\n";

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'curl/7.81.0'
        ],
        'sslverify' => false,
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        echo "   -> [ERRO LOCAL] " . $response->get_error_message() . "\n";
        $errors[] = "Carteira {$carteira_nome}: " . $response->get_error_message();
        continue;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    echo "   -> Código HTTP: {$code}\n";

    if ($code !== 200) {
        echo "   -> [ERRO OTIMA] Resposta falhou:\n      " . substr($body, 0, 200) . "...\n";
        $errors[] = "Carteira {$carteira_nome} (API Error {$code})";
        continue;
    }

    $data = json_decode($body, true);
    echo "   -> Decode JSON: " . (json_last_error() === JSON_ERROR_NONE ? "OK" : "FALHOU") . "\n";

    if (is_array($data) && count($data) > 0) {
        echo "   -> Templates recebidos: " . count($data) . "\n";
        foreach ($data as $tpl) {
            $all_templates[] = array_merge($tpl, [
                'wallet_id' => $customer_code,
                'wallet_name' => $carteira_nome,
                'broker_code' => $tpl['broker_code'] ?? $tpl['brokerCode'] ?? '',
                'customer_code' => $customer_code
            ]);
        }
    } else {
        echo "   -> Nenhum template no array. Formato da resposta:\n";
        print_r(substr($body, 0, 300));
        echo "\n";
    }
    echo "\n";
}

echo "====================\n";
echo "Total de Templates Carregados: " . count($all_templates) . "\n";
if (count($errors) > 0) {
    echo "Erros detectados: \n";
    print_r($errors);
} else {
    echo "Exemplo do primeiro template mapeado:\n";
    if (count($all_templates) > 0) {
        print_r($all_templates[0]);
    }
}
