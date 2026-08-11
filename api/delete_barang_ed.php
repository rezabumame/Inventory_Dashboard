<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Hanya super_admin yang bisa menghapus batch ED.']); exit;
}
if (!csrf_validate($_POST['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); exit;
}

$stmt = $conn->prepare("DELETE FROM inventory_barang_ed WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Batch ED berhasil dihapus.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Batch ED tidak ditemukan.']);
}