<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}
if (!csrf_validate($_POST['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$barang_id = (int)($_POST['barang_id'] ?? 0);
$value     = (int)($_POST['value'] ?? 1) ? 1 : 0;

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'barang_id tidak valid.']); exit;
}

if ($conn->query("UPDATE inventory_barang SET track_ed = $value WHERE id = $barang_id")) {
    echo json_encode(['success' => true, 'track_ed' => $value]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
