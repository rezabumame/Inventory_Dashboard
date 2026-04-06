<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
require_csrf();

$grup_id = (int)($_POST['grup_id'] ?? 0);
$barang_id = (int)($_POST['barang_id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 0);
$is_mandatory = (int)($_POST['is_mandatory'] ?? 1);

if ($grup_id <= 0 || $barang_id <= 0 || $qty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ? AND barang_id = ?");
$stmt->bind_param("ii", $grup_id, $barang_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    $stmt = $conn->prepare("UPDATE inventory_pemeriksaan_grup_detail SET qty_per_pemeriksaan = ?, is_mandatory = ? WHERE pemeriksaan_grup_id = ? AND barang_id = ?");
    $stmt->bind_param("iiii", $qty, $is_mandatory, $grup_id, $barang_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
        exit;
    }
} else {
    $stmt = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan, is_mandatory) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiii", $grup_id, $barang_id, $qty, $is_mandatory);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
        exit;
    }
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);


