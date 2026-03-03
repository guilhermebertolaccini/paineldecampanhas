<?php
/**
 * Standalone Salesforce Test Runner - Full Loop Test
 * Upload to Hetzner /var/www/html/test_salesforce_loop.php and access via browser.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Salesforce Import Direct Test - Loop</h2><pre>\n";

require_once __DIR__ . '/wp-load.php';
global $wpdb;
$wpdb->show_errors();

@set_time_limit(120);
@ini_set('memory_limit', '256M');

$sf_auth_url      = 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.auth.marketingcloudapis.com';
$sf_rest_url      = 'https://mchdb47kwgw19dh5mmnsw0fvhv2m.rest.marketingcloudapis.com';
$sf_client_id     = 'bv53kgt3ocyggeua4synj2v0';
$sf_client_secret = 'VqfpNASD3Q8bEyD4ktXqQhKJ';
$sf_account_id    = '536007880';
$sf_de_key        = 'Tracking_WhatsApp_Importado_FINAL';
$page_size        = 200;
$table_name       = 'salesforce_returns';

echo "Requesting Token...\n";
$token_response = wp_remote_post($sf_auth_url . '/v2/token', [
    'body'    => wp_json_encode([
        'grant_type'    => 'client_credentials',
        'client_id'     => $sf_client_id,
        'client_secret' => $sf_client_secret,
        'account_id'    => $sf_account_id,
    ]),
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 30,
]);

if (is_wp_error($token_response)) die("FATAL: Token fetch failed");

$token_body = json_decode(wp_remote_retrieve_body($token_response), true);
$access_token = $token_body['access_token'] ?? '';
if (empty($access_token)) die("FATAL: Empty access token");

$normalize_date = function ($value) {
    if (empty($value)) return null;
    $str = trim(preg_replace('/\s+/', ' ', $value));
    if (strpos($str, 'T') !== false && strpos($str, 'Z') !== false) {
        $str = str_replace('Z', '+00:00', $str);
        $t = strtotime($str);
        if ($t !== false) return date('Y-m-d H:i:s', $t);
    }
    $t = strtotime($str);
    if ($t !== false) return date('Y-m-d H:i:s', $t);
    $pm = [];
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(.+))?$/', $str, $pm)) {
        $a = (int)$pm[1]; $b = (int)$pm[2]; $y = (int)$pm[3];
        $tail = isset($pm[4]) ? trim($pm[4]) : '';
        $m = $a > 12 ? $b : $a;
        $d = $a > 12 ? $a : $b;
        $t = strtotime($y . '-' . $m . '-' . $d . ' ' . $tail);
        if ($t) return date('Y-m-d H:i:s', $t);
    }
    return null;
};

$page = 1;
$total_inserted = 0;
$start_time = microtime(true);
$max_execution = 20;

echo "Starting pagination loop...\n";

while (true) {
    $url = $sf_rest_url . '/data/v1/customobjectdata/key/' . $sf_de_key . '/rowset?$page=' . $page . '&$pageSize=' . $page_size;
    echo "Fetching Page $page... ";
    
    $data_response = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
        'timeout' => 60,
    ]);

    if (is_wp_error($data_response)) die("Error: " . $data_response->get_error_message());
    $http_code = wp_remote_retrieve_response_code($data_response);
    if ($http_code !== 200) die("HTTP $http_code");

    $body = json_decode(wp_remote_retrieve_body($data_response), true);
    $items = $body['items'] ?? [];
    
    echo count($items) . " items found. Processing... ";

    if (empty($items)) break;

    foreach ($items as $index => $item) {
        $row = array_merge($item['keys'] ?? [], $item['values'] ?? []);
        $uniqueid = $row['uniqueid'] ?? '';
        if (empty($uniqueid)) continue;

        $uniqueid_hash = hash('sha256', $uniqueid);
        $eventdateutc = $normalize_date($row['eventdateutc'] ?? ($row['eventdateu'] ?? null));

        $query = $wpdb->prepare(
            "INSERT INTO {$table_name}
                (uniqueid, uniqueid_hash, trackingtype, sendtype, mid, eid, contactkey, mobilenumber,
                 eventdateutc, appid, channelid, channeltype, conversationtype, activityname,
                 channelname, status, reason, jbdefinitionid, sendidentifier, assetid,
                 messagetypeid, operacao__c, cpf_cnpj__c, name)
             VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE
                 trackingtype=VALUES(trackingtype), sendtype=VALUES(sendtype),
                 mid=VALUES(mid), eid=VALUES(eid), contactkey=VALUES(contactkey),
                 mobilenumber=VALUES(mobilenumber), eventdateutc=VALUES(eventdateutc),
                 appid=VALUES(appid), channelid=VALUES(channelid),
                 channeltype=VALUES(channeltype), conversationtype=VALUES(conversationtype),
                 activityname=VALUES(activityname), channelname=VALUES(channelname),
                 status=VALUES(status), reason=VALUES(reason),
                 jbdefinitionid=VALUES(jbdefinitionid), sendidentifier=VALUES(sendidentifier),
                 assetid=VALUES(assetid), messagetypeid=VALUES(messagetypeid),
                 operacao__c=VALUES(operacao__c), cpf_cnpj__c=VALUES(cpf_cnpj__c),
                 name=VALUES(name)",
            [
                $uniqueid, $uniqueid_hash,
                (string)($row['trackingtype'] ?? ''), (string)($row['sendtype'] ?? ''),
                (string)($row['mid'] ?? ''), (string)($row['eid'] ?? ''),
                (string)($row['contactkey'] ?? ''), (string)($row['mobilenumber'] ?? ''),
                $eventdateutc,
                (string)($row['appid'] ?? ''), (string)($row['channelid'] ?? ''),
                (string)($row['channeltype'] ?? ''), (string)($row['conversationtype'] ?? ''),
                (string)($row['activityname'] ?? ''), (string)($row['channelname'] ?? ''),
                (string)($row['status'] ?? ''), (string)($row['reason'] ?? ''),
                (string)($row['jbdefinitionid'] ?? ''), (string)($row['sendidentifier'] ?? ''),
                (string)($row['assetid'] ?? ''), (string)($row['messagetypeid'] ?? ''),
                (string)($row['operacao__c'] ?? ''), (string)($row['cpf_cnpj__c'] ?? ''),
                (string)($row['name'] ?? '')
            ]
        );

        $result = $wpdb->query($query);
        if ($result !== false) {
            $total_inserted++;
        } else {
            echo "\nDB Error on item $index: " . $wpdb->last_error;
        }
    }
    echo "Done.\n";

    $has_next = !empty($body['links']['next']);
    if (!$has_next) {
        echo "Finished. No more pages.\n";
        break;
    }

    $elapsed = microtime(true) - $start_time;
    if ($elapsed > $max_execution) {
        echo "Soft Timeout Reached! Elapsed: {$elapsed}s. Stopping gracefully.\n";
        break;
    }
    
    $page++;
}

echo "\nInserted/Updated: $total_inserted rows.\n";
echo "</pre>";
