<?php
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

$grup_id = trim((string)($_POST['grup_id'] ?? ''));
$barang_id = (int)($_POST['barang_id'] ?? 0);
$is_lokal = (int)($_POST['is_lokal'] ?? 0);
$qty = (float)($_POST['qty'] ?? 0);
$id_biosys = trim((string)($_POST['id_biosys'] ?? ''));
$layanan = trim((string)($_POST['layanan'] ?? ''));

if ($grup_id === '' || $barang_id <= 0 || $qty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ? AND barang_id = ? AND is_lokal = ? AND id_biosys = ? AND nama_layanan = ?");
$stmt->bind_param("siiss", $grup_id, $barang_id, $is_lokal, $id_biosys, $layanan);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    $stmt = $conn->prepare("UPDATE inventory_pemeriksaan_grup_detail SET qty_per_pemeriksaan = ? WHERE pemeriksaan_grup_id = ? AND barang_id = ? AND is_lokal = ? AND id_biosys = ? AND nama_layanan = ?");
    $stmt->bind_param("dsiiss", $qty, $grup_id, $barang_id, $is_lokal, $id_biosys, $layanan);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
        exit;
    }
} else {
    $stmt = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, is_lokal, qty_per_pemeriksaan) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssidi", $grup_id, $id_biosys, $layanan, $barang_id, $is_lokal, $qty);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
        exit;
    }
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);


