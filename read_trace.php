<?php
$plugin_dir = __DIR__ . '/wp-content/plugins/painel-campanhas-install-2';
$trace_file = $plugin_dir . '/sf_ajax_trace.log';

echo "<h2>Salesforce AJAX Tracer Log</h2><pre>\n";

if (file_exists($trace_file)) {
    echo file_get_contents($trace_file);
} else {
    // Try current dir just in case
    $trace_file2 = __DIR__ . '/sf_ajax_trace.log';
    if (file_exists($trace_file2)) {
        echo file_get_contents($trace_file2);
    } else {
        echo "Trace file not found! Looked in:\n1. $trace_file\n2. $trace_file2";
    }
}
echo "</pre>";
