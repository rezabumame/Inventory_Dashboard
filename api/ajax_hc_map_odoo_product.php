<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
require_csrf();

$barang_id = (int)($_POST['barang_id'] ?? 0);
$odoo_product_id = trim((string)($_POST['odoo_product_id'] ?? ''));
// Allow remap by clearing existing mapping when force=1
$force = (string)($_POST['force'] ?? '') === '1';
if ($barang_id <= 0 || $odoo_product_id === '') {
    echo json_encode(['success' => false, 'message' => 'Param tidak valid']);
    exit;
}

$odoo_esc = $conn->real_escape_string($odoo_product_id);
$existing = $conn->query("SELECT id, kode_barang, nama_barang FROM barang WHERE odoo_product_id = '$odoo_esc' LIMIT 1")->fetch_assoc();
if ($existing && (int)($existing['id'] ?? 0) !== $barang_id) {
    if (!$force) {
        $nm = (string)($existing['nama_barang'] ?? '');
        $kd = (string)($existing['kode_barang'] ?? '');
        echo json_encode(['success' => false, 'message' => 'Odoo product sudah dipakai oleh barang: ' . ($kd !== '' ? ($kd . ' - ') : '') . $nm . ' (ID ' . (int)$existing['id'] . ')']);
        exit;
    }
    $eid = (int)($existing['id'] ?? 0);
    if ($eid > 0) $conn->query("UPDATE barang SET odoo_product_id = NULL WHERE id = $eid");
}

$stmt = $conn->prepare("UPDATE barang SET odoo_product_id = ? WHERE id = ?");
$stmt->bind_param("si", $odoo_product_id, $barang_id);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Gagal update mapping']);
    exit;
}
echo json_encode(['success' => true]);

