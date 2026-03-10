<?php
/**
 * Script to import Salesforce data directly to MySQL.
 * Designed to run as a cron job.
 */

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Increase memory limit and max execution time for large imports
ini_set('memory_limit', '512M');
set_time_limit(0);

// Attempt to load WordPress environment
$wp_load_paths = [
    __DIR__ . '/wp-load.php',
    __DIR__ . '/../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    // We cannot proceed without WordPress (wpdb/remote API)
    die("Error: wp-load.php not found. Please place this script inside the WordPress directory tree.\n");
}

global $wpdb;

// Table Name Strategy: specifically requested pure name
$table_name = 'salesforce_returns';

// Ensure table exists
$charset_collate = $wpdb->get_charset_collate();
$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    uniqueid text NOT NULL,
    uniqueid_hash varchar(64) NOT NULL,
    trackingtype varchar(100),
    sendtype varchar(100),
    mid varchar(100),
    eid varchar(200),
    contactkey varchar(200),
    mobilenumber varchar(50),
    eventdateutc datetime,
    appid varchar(100),
    channelid varchar(100),
    channeltype varchar(50),
    conversationtype varchar(50),
    activityname varchar(150),
    channelname varchar(150),
    status varchar(100),
    reason text,
    jbdefinitionid varchar(200),
    sendidentifier varchar(200),
    assetid varchar(100),
    messagetypeid varchar(100),
    operacao__c varchar(100),
    cpf_cnpj__c varchar(50),
    name varchar(255),
    criado_em datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uniqueid_hash (uniqueid_hash)
) $charset_collate;";

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
dbDelta($sql);

// Load Salesforce MC credentials from wp_options (same as plugin AJAX handler)
$mkc_creds = get_option('acm_static_credentials', []);
$sf_auth_url_val  = trim($mkc_creds['mkc_auth_url']  ?? '');
$sf_rest_url_val  = trim($mkc_creds['mkc_rest_url']  ?? '');
$sf_client_id_val     = trim($mkc_creds['mkc_client_id']     ?? '');
$sf_client_secret_val = trim($mkc_creds['mkc_client_secret'] ?? '');
$sf_account_id_val    = trim($mkc_creds['mkc_account_id']    ?? '');
$sf_de_key_val        = trim($mkc_creds['mkc_de_key']        ?? 'Tracking_WhatsApp_Importado_FINAL');

if (empty($sf_auth_url_val) || empty($sf_client_id_val) || empty($sf_client_secret_val) || empty($sf_account_id_val)) {
    die("[CONFIG ERROR] Salesforce MC credentials not configured in wp_options (acm_static_credentials). Set mkc_auth_url, mkc_client_id, mkc_client_secret, mkc_account_id via API Manager.\n");
}

define('SF_AUTH_URL', $sf_auth_url_val);
define('SF_REST_URL', $sf_rest_url_val);
define('SF_CLIENT_ID', $sf_client_id_val);
define('SF_CLIENT_SECRET', $sf_client_secret_val);
define('SF_ACCOUNT_ID', $sf_account_id_val);
define('SF_DE_KEY', $sf_de_key_val);
define('PAGE_SIZE', 500);

echo "[SF_IMPORT] Starting script...\n";

// Fetch Token
function get_sf_token()
{
    $url = SF_AUTH_URL . '/v2/token';
    $args = [
        'body' => json_encode([
            'grant_type' => 'client_credentials',
            'client_id' => SF_CLIENT_ID,
            'client_secret' => SF_CLIENT_SECRET,
            'account_id' => SF_ACCOUNT_ID
        ]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 30
    ];

    $response = wp_remote_post($url, $args);
    if (is_wp_error($response)) {
        die("[AUTH ERROR] " . $response->get_error_message() . "\n");
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) {
        die("[AUTH ERROR] Could not retrieve access token.\n");
    }

    echo "[SF_IMPORT] Token acquired.\n";
    return $body['access_token'];
}

// Normalize Date
function normalize_date($value)
{
    if (empty($value))
        return null;

    $str = trim(preg_replace('/\s+/', ' ', $value));

    // Fix ISO 8601 with Z
    if (strpos($str, 'T') !== false && strpos($str, 'Z') !== false) {
        $str = str_replace('Z', '+00:00', $str);
        $time = strtotime($str);
        if ($time !== false) {
            return date('Y-m-d H:i:s', $time);
        }
    }

    // Try common DateTime parses
    $time = strtotime($str);
    if ($time !== false) {
        return date('Y-m-d H:i:s', $time);
    }

    // Python fallback pattern mm/dd/yyyy hh:mm(am/pm) 
    $matches = [];
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(.+))?$/', $str, $matches)) {
        $a = (int) $matches[1];
        $b = (int) $matches[2];
        $y = (int) $matches[3];
        $tail = isset($matches[4]) ? trim($matches[4]) : '';

        // assume mdy if no overwhelming > 12 month
        $m = $a;
        $d = $b;
        if ($a > 12) {
            $m = $b;
            $d = $a;
        }

        $date_str = "$y-$m-$d $tail";
        $time = strtotime($date_str);
        if ($time)
            return date('Y-m-d H:i:s', $time);
    }

    return null;
}

$token = get_sf_token();
$page = 1;
$total_inserted = 0;

$max_failures = 10;
$retry_sleep = 5;

while (true) {
    echo "[SF_IMPORT] Fetching page $page...\n";

    $url = SF_REST_URL . "/data/v1/customobjectdata/key/" . SF_DE_KEY . "/rowset?\$page=$page&\$pageSize=" . PAGE_SIZE;

    $data_fetched = false;
    $body = null;

    for ($attempt = 1; $attempt <= $max_failures; $attempt++) {
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => "Bearer $token"],
            'timeout' => 120
        ]);

        if (is_wp_error($response)) {
            echo "[WARN] Fetch failed (Attempt $attempt): " . $response->get_error_message() . "\n";
            sleep($retry_sleep * $attempt);
            continue;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401 || $status_code == 403) {
            echo "[AUTH EXPIRY] Token expired on page $page. Renewing...\n";
            $token = get_sf_token();
            continue;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data_fetched = true;
        break;
    }

    if (!$data_fetched || $body === null) {
        echo "[ERROR] Failed fetching page $page completely after $max_failures attempts.\n";
        break;
    }

    if (empty($body['items'])) {
        echo "[SF_IMPORT] Page $page empty. Done.\n";
        break;
    }

    $items = $body['items'];
    $batch_size = count($items);
    echo "[SF_IMPORT] Processing page $page ($batch_size records)...\n";

    // Prepare batch insert
    $query = "INSERT INTO {$table_name} (uniqueid, uniqueid_hash, trackingtype, sendtype, mid, eid, contactkey, mobilenumber, eventdateutc, appid, channelid, channeltype, conversationtype, activityname, channelname, status, reason, jbdefinitionid, sendidentifier, assetid, messagetypeid, operacao__c, cpf_cnpj__c, name) VALUES ";
    $values = [];
    $placeholders = [];

    foreach ($items as $item) {
        $keys = isset($item['keys']) ? (array) $item['keys'] : [];
        $vals = isset($item['values']) ? (array) $item['values'] : [];
        $row = array_merge($keys, $vals);

        $uniqueid = isset($row['uniqueid']) ? $row['uniqueid'] : '';
        if (empty($uniqueid))
            continue;

        // Since uniqueid in Salesforce can be huge string, index might fail on VARCHAR(255). We use a hashed key for uniqueness
        $uniqueid_hash = hash('sha256', $uniqueid);

        $eventdateutc = isset($row['eventdateutc']) ? normalize_date($row['eventdateutc']) : null;
        if (!$eventdateutc && isset($row['eventdateu'])) {
            $eventdateutc = normalize_date($row['eventdateu']);
        }

        $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)";
        $values[] = $uniqueid;
        $values[] = $uniqueid_hash;
        $values[] = isset($row['trackingtype']) ? $row['trackingtype'] : '';
        $values[] = isset($row['sendtype']) ? $row['sendtype'] : '';
        $values[] = isset($row['mid']) ? $row['mid'] : '';
        $values[] = isset($row['eid']) ? $row['eid'] : '';
        $values[] = isset($row['contactkey']) ? $row['contactkey'] : '';
        $values[] = isset($row['mobilenumber']) ? $row['mobilenumber'] : '';
        $values[] = $eventdateutc;
        $values[] = isset($row['appid']) ? $row['appid'] : '';
        $values[] = isset($row['channelid']) ? $row['channelid'] : '';
        $values[] = isset($row['channeltype']) ? $row['channeltype'] : '';
        $values[] = isset($row['conversationtype']) ? $row['conversationtype'] : '';
        $values[] = isset($row['activityname']) ? $row['activityname'] : '';
        $values[] = isset($row['channelname']) ? $row['channelname'] : '';
        $values[] = isset($row['status']) ? $row['status'] : '';
        $values[] = isset($row['reason']) ? $row['reason'] : '';
        $values[] = isset($row['jbdefinitionid']) ? $row['jbdefinitionid'] : '';
        $values[] = isset($row['sendidentifier']) ? $row['sendidentifier'] : '';
        $values[] = isset($row['assetid']) ? $row['assetid'] : '';
        $values[] = isset($row['messagetypeid']) ? $row['messagetypeid'] : '';
        $values[] = isset($row['operacao__c']) ? $row['operacao__c'] : '';
        $values[] = isset($row['cpf_cnpj__c']) ? $row['cpf_cnpj__c'] : '';
        $values[] = isset($row['name']) ? $row['name'] : '';
    }

    if (!empty($placeholders)) {
        $query .= implode(', ', $placeholders);
        $query .= " ON DUPLICATE KEY UPDATE 
            trackingtype = VALUES(trackingtype),
            sendtype = VALUES(sendtype),
            mid = VALUES(mid),
            eid = VALUES(eid),
            contactkey = VALUES(contactkey),
            mobilenumber = VALUES(mobilenumber),
            eventdateutc = VALUES(eventdateutc),
            appid = VALUES(appid),
            channelid = VALUES(channelid),
            channeltype = VALUES(channeltype),
            conversationtype = VALUES(conversationtype),
            activityname = VALUES(activityname),
            channelname = VALUES(channelname),
            status = VALUES(status),
            reason = VALUES(reason),
            jbdefinitionid = VALUES(jbdefinitionid),
            sendidentifier = VALUES(sendidentifier),
            assetid = VALUES(assetid),
            messagetypeid = VALUES(messagetypeid),
            operacao__c = VALUES(operacao__c),
            cpf_cnpj__c = VALUES(cpf_cnpj__c),
            name = VALUES(name)
        ";

        $prepared = $wpdb->prepare($query, $values);
        $result = $wpdb->query($prepared);

        if ($result === false) {
            echo "[DB ERROR] Failed inserting page $page: " . $wpdb->last_error . "\n";
        } else {
            $total_inserted += count($placeholders);
        }
    }

    $links = isset($body['links']) ? $body['links'] : [];
    if (empty($links['next'])) {
        echo "[SF_IMPORT] No 'next' link. Reached end of data.\n";
        break;
    }

    $page++;
}

echo "[DONE] Inserted/Updated $total_inserted records.\n";
