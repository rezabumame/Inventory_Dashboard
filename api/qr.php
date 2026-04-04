<?php
$text = (string)($_GET['text'] ?? '');
if ($text === '') {
    http_response_code(400);
    echo 'missing text';
    exit;
}
if (strlen($text) > 1200) {
    http_response_code(400);
    echo 'text too long';
    exit;
}

$url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($text);

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'follow_location' => 1,
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

$resp = @file_get_contents($url, false, $context);

// Fallback to CURL if file_get_contents fails
if ($resp === false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $resp = curl_exec($ch);
    curl_close($ch);
}

if ($resp === false) {
    http_response_code(502);
    echo 'qr fetch failed';
    exit;
}

header('Content-Type: image/png');
echo $resp;
exit;
