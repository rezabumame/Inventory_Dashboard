<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload     = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}
$barang_id   = (int)($payload['barang_id'] ?? 0);
$kode_barang = trim($payload['kode_barang'] ?? '');
$nama_barang = trim($payload['nama_barang'] ?? '');
$qty         = isset($payload['qty']) && $payload['qty'] !== null ? (float)$payload['qty'] : null;
$uom         = trim($payload['uom'] ?? '');
$odoo_ref    = trim($payload['odoo_ref'] ?? '');
$action      = in_array($payload['action'] ?? '', ['print_label','confirm','scan_manual','search_manual'])
               ? $payload['action'] : 'print_label';
$user_id     = (int)$_SESSION['user_id'];
$klinik_id   = (int)($_SESSION['klinik_id'] ?? 0);

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'barang_id wajib diisi.']); exit;
}

$r_tbl = $conn->query("SHOW TABLES LIKE 'inventory_inbound_log'");
if (!$r_tbl || $r_tbl->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Tabel belum ada, jalankan migrate.']); exit;
}

$safe_kode  = $conn->real_escape_string($kode_barang);
$safe_nama  = $conn->real_escape_string($nama_barang);
$safe_uom   = $conn->real_escape_string($uom);
$safe_ref   = $conn->real_escape_string($odoo_ref);
$safe_act   = $conn->real_escape_string($action);
$qty_val    = $qty !== null ? $qty : 'NULL';
$ref_val    = $odoo_ref !== '' ? "'$safe_ref'" : 'NULL';
$klinik_val = $klinik_id > 0 ? $klinik_id : 'NULL';

$conn->query("INSERT INTO inventory_inbound_log
    (odoo_ref, barang_id, kode_barang, nama_barang, qty, uom, action, processed_by, klinik_id)
    VALUES ($ref_val, $barang_id, '$safe_kode', '$safe_nama', $qty_val, '$safe_uom', '$safe_act', $user_id, $klinik_val)");

if ($conn->insert_id) {
    echo json_encode(['success' => true, 'id' => (int)$conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
