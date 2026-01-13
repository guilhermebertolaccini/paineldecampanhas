<?php
// Debug script - remover após teste
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

echo "admin_url('admin-ajax.php'): " . admin_url('admin-ajax.php') . "\n";
echo "home_url(): " . home_url() . "\n";
echo "site_url(): " . site_url() . "\n";
echo "ABSPATH: " . ABSPATH . "\n";

