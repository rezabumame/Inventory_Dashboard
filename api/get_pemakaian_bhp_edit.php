<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

// Get header
$stmt = $conn->prepare("
    SELECT pb.*, k.nama_klinik
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    WHERE pb.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();

if (!$header) {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    exit;
}

// Permission check: only creator of today can edit
$is_today = date('Y-m-d', strtotime($header['created_at'])) === date('Y-m-d');
$is_creator = $header['created_by'] == $_SESSION['user_id'];

if (!$is_today || !$is_creator) {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengedit data ini']);
    exit;
}

// Format tanggal for HTML5 date input (YYYY-MM-DD)
if (isset($header['tanggal'])) {
    $header['tanggal'] = date('Y-m-d', strtotime($header['tanggal']));
}

// Get details
$stmt_d = $conn->prepare("
    SELECT pbd.*, b.nama_barang, b.kode_barang
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_barang b ON pbd.barang_id = b.id
    WHERE pbd.pemakaian_bhp_id = ?
");
$stmt_d->bind_param("i", $id);
$stmt_d->execute();
$details_result = $stmt_d->get_result();
$details = [];
while ($d = $details_result->fetch_assoc()) {
    $details[] = $d;
}

echo json_encode([
    'success' => true,
    'header' => $header,
    'details' => $details
]);
?>
