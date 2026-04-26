<?php
require_once __DIR__ . '/../config/settings.php';

function lark_send_payload(string $url, string $payload, int $timeout_sec = 8): bool {
    if ($url === '') return false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_sec);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code < 200 || $code >= 300) return false;
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) return true;
    if (array_key_exists('code', $j)) return (int)$j['code'] === 0;
    if (array_key_exists('StatusCode', $j)) return (int)$j['StatusCode'] === 0;
    return true;
}

function lark_post_text(string $url, string $text) {
    $payload = json_encode([
        'msg_type' => 'text',
        'content' => ['text' => $text]
    ]);
    if (!is_string($payload) || $payload === '') return;
    lark_send_payload($url, $payload, 8);
}

function lark_post_card(string $url, string $title, array $lines, string $theme = 'blue') {
    $content = [
        [
            'tag' => 'div',
            'text' => [
                'content' => implode("\n", $lines),
                'tag' => 'lark_md'
            ]
        ]
    ];

    $payload = json_encode([
        'msg_type' => 'interactive',
        'card' => [
            'header' => [
                'title' => [
                    'content' => $title,
                    'tag' => 'plain_text'
                ],
                'template' => $theme
            ],
            'elements' => $content
        ]
    ]);

    lark_send_payload($url, $payload, 8);
}
