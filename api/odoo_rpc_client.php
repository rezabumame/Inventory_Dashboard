<?php

function odoo_rpc_post($baseUrl, $payload) {
    $url = rtrim($baseUrl, '/') . '/jsonrpc';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("RPC error: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new Exception("RPC HTTP $code");
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new Exception("RPC invalid JSON");
    }
    if (isset($data['error'])) {
        $msg = $data['error']['data']['message'] ?? $data['error']['message'] ?? 'RPC error';
        throw new Exception($msg);
    }
    return $data['result'] ?? null;
}

function odoo_rpc_version($baseUrl) {
    $payload = [
        'jsonrpc' => '2.0',
        'method' => 'call',
        'params' => [
            'service' => 'common',
            'method' => 'version',
            'args' => []
        ],
        'id' => 1
    ];
    return odoo_rpc_post($baseUrl, $payload);
}

function odoo_rpc_authenticate($baseUrl, $db, $username, $password) {
    $payload = [
        'jsonrpc' => '2.0',
        'method' => 'call',
        'params' => [
            'service' => 'common',
            'method' => 'authenticate',
            'args' => [$db, $username, $password, new stdClass()]
        ],
        'id' => 2
    ];
    $uid = odoo_rpc_post($baseUrl, $payload);
    if (empty($uid)) return null;
    return (int)$uid;
}

function odoo_rpc_execute_kw($baseUrl, $db, $uid, $password, $model, $method, $args = [], $kwargs = []) {
    $payload = [
        'jsonrpc' => '2.0',
        'method' => 'call',
        'params' => [
            'service' => 'object',
            'method' => 'execute_kw',
            'args' => [$db, $uid, $password, $model, $method, $args, $kwargs]
        ],
        'id' => 3
    ];
    return odoo_rpc_post($baseUrl, $payload);
}

function odoo_find_location_id($baseUrl, $db, $uid, $password, $code) {
    $domain = ['|', '|', ['barcode', '=', $code], ['name', 'ilike', $code], ['complete_name', 'ilike', $code]];
    $ids = odoo_rpc_execute_kw($baseUrl, $db, $uid, $password, 'stock.location', 'search', [$domain], ['limit' => 1]);
    if (is_array($ids) && count($ids) > 0) return (int)$ids[0];
    return null;
}

?>
