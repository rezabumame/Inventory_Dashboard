<?php
header('Content-Type: text/plain');
$log_file = __DIR__ . '/upload_debug.log';

if (file_exists($log_file)) {
    echo "--- LAST 100 LINES OF upload_debug.log ---\n\n";
    $lines = file($log_file);
    $last_lines = array_slice($lines, -100);
    echo implode("", $last_lines);
} else {
    echo "Log file not found at: $log_file\n";
    echo "Make sure the web server has write permissions to the directory.";
}
