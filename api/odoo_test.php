<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

try {
    $rpc_url = trim((string)get_setting('odoo_rpc_url', ''));
    $rpc_db = trim((string)get_setting('odoo_rpc_db', ''));
    $rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
    $rpc_pass = (string)get_setting('odoo_rpc_password', '');

    if ($rpc_url === '' || $rpc_db === '' || $rpc_user === '' || $rpc_pass === '') {
        echo json_encode(['success' => false, 'message' => 'Konfigurasi Odoo belum lengkap.']);
        exit;
    }

    $uid = odoo_rpc_authenticate($rpc_url, $rpc_db, $rpc_user, $rpc_pass);
    
    if ($uid) {
        echo json_encode(['success' => true, 'message' => 'Koneksi Berhasil!', 'uid' => $uid]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Autentikasi gagal. Periksa Username/Password.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
