<?php
/**
 * Robust Server Error Log Extractor for Hetzner Docker Container
 * Upload this file to the root of your WordPress installation and open it in the browser.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Server Diagnostics & Error Extractor</h2>";
echo "<pre style='background:#111; color:#0f0; padding:20px; border-radius:5px;'>\n";

echo "1. Checking PHP Environment Limits:\n";
echo "   - Memory Limit: " . ini_get('memory_limit') . "\n";
echo "   - Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "   - cURL Enabled: " . (function_exists('curl_init') ? 'YES' : 'NO') . "\n\n";

// Emulate wp-load just to get the constants safely
$wp_loaded = false;
$wp_load_path = __DIR__ . '/wp-load.php';
if (file_exists($wp_load_path)) {
    require_once $wp_load_path;
    $wp_loaded = true;
    echo "2. WordPress Environment Loaded.\n";
    echo "   - Current Time: " . date('Y-m-d H:i:s') . "\n";
    echo "   - WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'ON' : 'OFF') . "\n";
} else {
    echo "2. WordPress wp-load.php NOT found at $wp_load_path\n";
}

echo "\n------------------------------------------------\n\n";

// Check the custom fatal interceptor log we added in the previous zip
echo "3. Checking custom sf_fatal.log:\n";
$custom_log = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/sf_fatal.log' : __DIR__ . '/wp-content/sf_fatal.log';
if (file_exists($custom_log)) {
    echo "   => FOUND! Contents:\n";
    echo htmlspecialchars(file_get_contents($custom_log));
} else {
    echo "   => sf_fatal.log not found. The shutdown function may not have triggered.\n";
}

echo "\n------------------------------------------------\n\n";

echo "4. Checking WP_DEBUG_LOG:\n";
$wp_debug_log_path = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : __DIR__ . '/wp-content/debug.log';
if (file_exists($wp_debug_log_path)) {
    echo "   => FOUND! Last 30 lines:\n";
    $lines = @file($wp_debug_log_path);
    if ($lines !== false) {
        $last_lines = array_slice($lines, -30);
        foreach ($last_lines as $line) {
            echo htmlspecialchars($line);
        }
    } else {
        echo "   => Error reading debug.log\n";
    }
} else {
    echo "   => debug.log not found at: $wp_debug_log_path\n";
}

echo "\n------------------------------------------------\n\n";

echo "5. Checking PHP System error_log:\n";
$php_error_log = ini_get('error_log');
if ($php_error_log) {
    echo "   => Configured path: $php_error_log\n";
    if (file_exists($php_error_log)) {
        if ($php_error_log === '/dev/stderr' || $php_error_log === '/dev/stdout') {
             echo "   => Log is a system pipe. Cannot be read directly by PHP scripts in Docker.\n";
        } else {
            $lines = @file($php_error_log);
            if ($lines !== false) {
                echo "   => Last 20 lines:\n";
                $last_lines = array_slice($lines, -20);
                foreach ($last_lines as $line) {
                    echo htmlspecialchars($line);
                }
            } else {
                 echo "   => Exists but cannot be read (permission issue).\n";
            }
        }
    } else {
        echo "   => File does not exist on disk.\n";
    }
} else {
    echo "   => Not explicitly defined in php.ini\n";
}

echo "\n</pre>";
