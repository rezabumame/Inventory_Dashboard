<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$ref = trim((string)($_GET['ref'] ?? ''));
if ($ref === '') {
    echo json_encode(['success' => false, 'message' => 'Referensi kosong.']);
    exit;
}

try {
    $rpc_url  = trim((string)get_setting('odoo_rpc_url', ''));
    $rpc_db   = trim((string)get_setting('odoo_rpc_db', ''));
    $rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
    $rpc_pass = (string)get_setting('odoo_rpc_password', '');

    if ($rpc_url === '' || $rpc_db === '' || $rpc_user === '' || $rpc_pass === '') {
        echo json_encode(['success' => false, 'message' => 'Konfigurasi RPC Odoo belum lengkap. Cek Pengaturan > Integrasi.']);
        exit;
    }

    $uid = odoo_rpc_authenticate($rpc_url, $rpc_db, $rpc_user, $rpc_pass);
    if (!$uid) {
        echo json_encode(['success' => false, 'message' => 'Autentikasi RPC ke Odoo gagal.']);
        exit;
    }

    // Cari picking by name — exact match dulu, fallback ke ilike
    $pickingIds = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'stock.picking', 'search', [[['name', '=', $ref]]], ['limit' => 1]);
    if (empty($pickingIds)) {
        $pickingIds = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'stock.picking', 'search', [[['name', 'ilike', $ref]]], ['limit' => 1]);
    }
    if (empty($pickingIds)) {
        echo json_encode(['success' => false, 'message' => "Referensi \"$ref\" tidak ditemukan di Odoo."]);
        exit;
    }
    $pickingId = (int)$pickingIds[0];

    $pickings = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'stock.picking', 'read', [[$pickingId]], ['fields' => ['name', 'state']]);
    $picking  = $pickings[0] ?? ['name' => $ref, 'state' => ''];

    $moves = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'stock.move', 'search_read',
        [[['picking_id', '=', $pickingId]]],
        ['fields' => ['product_id', 'product_uom_qty', 'quantity', 'product_uom', 'name']]
    );

    $items = [];
    foreach ($moves as $m) {
        $productName = is_array($m['product_id'] ?? null) ? $m['product_id'][1] : ($m['name'] ?? '-');
        $uomName     = is_array($m['product_uom'] ?? null) ? $m['product_uom'][1] : '';
        $items[] = [
            'product_id' => is_array($m['product_id'] ?? null) ? $m['product_id'][0] : null,
            'nama'       => $productName,
            'demand'     => (float)($m['product_uom_qty'] ?? 0),
            'qty'        => (float)($m['quantity'] ?? $m['product_uom_qty'] ?? 0),
            'uom'        => $uomName,
        ];
    }

    // Cek item yang sudah diproses dari log lokal
    $processed_kodes = [];
    $safe_ref = $conn->real_escape_string($ref);
    $r_log = $conn->query("SHOW TABLES LIKE 'inventory_inbound_log'");
    if ($r_log && $r_log->num_rows > 0) {
        $qLog = $conn->query("SELECT DISTINCT kode_barang, action FROM inventory_inbound_log
            WHERE odoo_ref = '$safe_ref' AND kode_barang IS NOT NULL AND kode_barang != ''");
        while ($qLog && ($rLog = $qLog->fetch_assoc())) {
            $processed_kodes[$rLog['kode_barang']] = $rLog['action'];
        }
    }

    echo json_encode([
        'success' => true,
        'picking' => [
            'name'  => $picking['name'] ?? $ref,
            'state' => $picking['state'] ?? '',
        ],
        'items'           => $items,
        'processed_kodes' => $processed_kodes,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
