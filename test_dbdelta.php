<?php
require_once 'd:\paineldecampanhascerto\wp-load.php';
global $wpdb;
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

$table_name = 'salesforce_returns';
$charset_collate = $wpdb->get_charset_collate();
$sql_create = "CREATE TABLE IF NOT EXISTS {$table_name} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    uniqueid text NOT NULL,
    uniqueid_hash varchar(64) NOT NULL,
    trackingtype varchar(100) DEFAULT '',
    sendtype varchar(100) DEFAULT '',
    mid varchar(100) DEFAULT '',
    eid varchar(200) DEFAULT '',
    contactkey varchar(200) DEFAULT '',
    mobilenumber varchar(50) DEFAULT '',
    eventdateutc datetime DEFAULT NULL,
    appid varchar(100) DEFAULT '',
    channelid varchar(100) DEFAULT '',
    channeltype varchar(50) DEFAULT '',
    conversationtype varchar(50) DEFAULT '',
    activityname varchar(150) DEFAULT '',
    channelname varchar(150) DEFAULT '',
    status varchar(100) DEFAULT '',
    reason text,
    jbdefinitionid varchar(200) DEFAULT '',
    sendidentifier varchar(200) DEFAULT '',
    assetid varchar(100) DEFAULT '',
    messagetypeid varchar(100) DEFAULT '',
    operacao__c varchar(100) DEFAULT '',
    cpf_cnpj__c varchar(50) DEFAULT '',
    name varchar(255) DEFAULT '',
    PRIMARY KEY  (id),
    UNIQUE KEY uniqueid_hash (uniqueid_hash)
) {$charset_collate};";

echo "Running dbDelta...\n";
$result = dbDelta($sql_create);
print_r($result);

echo "Table exists check: ";
echo $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name ? 'YES' : 'NO';
echo "\n";
if ($wpdb->last_error) echo "Last error: " . $wpdb->last_error . "\n";
