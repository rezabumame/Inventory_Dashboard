<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload  = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}
$barang_id = (int)($payload['barang_id'] ?? 0);
$barcode   = trim($payload['barcode_internal'] ?? '');

if ($barang_id <= 0 || !$barcode) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
}

// Cek kolom barcode_internal
$r_col = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
if (!$r_col || $r_col->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Kolom barcode_internal tidak ada.']); exit;
}

$safe = $conn->real_escape_string($barcode);
$conn->query("UPDATE inventory_barang SET barcode_internal = '$safe'
    WHERE id = $barang_id AND (barcode_internal IS NULL OR barcode_internal = '')");

echo json_encode(['success' => true, 'affected' => $conn->affected_rows]);
