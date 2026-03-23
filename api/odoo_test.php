<?php
session_start();
require_once __DIR__ . '/../config/odoo.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

$rpc_url = trim((string)get_setting('odoo_rpc_url', ''));
$rpc_db = trim((string)get_setting('odoo_rpc_db', ''));
$rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
$rpc_pass = (string)get_setting('odoo_rpc_password', '');

if ($rpc_url !== '' && $rpc_db !== '' && $rpc_user !== '' && $rpc_pass !== '') {
    try {
        $version = odoo_rpc_version($rpc_url);
        $uid = odoo_rpc_authenticate($rpc_url, $rpc_db, $rpc_user, $rpc_pass);
        if (!$uid) {
            echo json_encode(['success' => false, 'message' => 'Gagal login (credential salah atau user tidak aktif)']);
            exit;
        }
        echo json_encode(['success' => true, 'method' => 'rpc', 'uid' => $uid, 'version' => $version]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'method' => 'rpc', 'message' => $e->getMessage()]);
        exit;
    }
}

if (empty(ODOO_API_BASE_URL) || empty(ODOO_API_TOKEN)) {
    echo json_encode(['success' => false, 'message' => 'Konfigurasi RPC kosong dan Base URL/Token juga kosong']);
    exit;
}

try {
    $url = rtrim(ODOO_API_BASE_URL, '/') . ODOO_PRODUCTS_ENDPOINT;
    if (strpos($url, '?') === false) {
        $url .= '?limit=1';
    } else {
        $url .= '&limit=1';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . ODOO_API_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        echo json_encode(['success' => true, 'method' => 'api']);
    } else {
        echo json_encode(['success' => false, 'method' => 'api', 'message' => 'HTTP ' . $code]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
