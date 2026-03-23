<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowed = ['cs', 'super_admin', 'admin_klinik'];
if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

$where = "b.id = ?";
if (($_SESSION['role'] ?? '') === 'admin_klinik') {
    $where .= " AND b.klinik_id = " . (int)($_SESSION['klinik_id'] ?? 0);
}

$stmt = $conn->prepare("
    SELECT 
        b.*,
        k.nama_klinik
    FROM booking_pemeriksaan b
    JOIN klinik k ON b.klinik_id = k.id
    WHERE $where
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();
if (!$header) {
    echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan']);
    exit;
}

$stmt = $conn->prepare("
    SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ') AS jenis_pemeriksaan
    FROM booking_pasien bp
    JOIN pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
    WHERE bp.booking_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$jenis = (string)($stmt->get_result()->fetch_assoc()['jenis_pemeriksaan'] ?? '');

$stmt = $conn->prepare("
    SELECT 
        b.kode_barang,
        b.nama_barang,
        b.satuan,
        SUM(bd.qty_gantung) AS qty
    FROM booking_detail bd
    JOIN barang b ON bd.barang_id = b.id
    WHERE bd.booking_id = ?
    GROUP BY b.kode_barang, b.nama_barang, b.satuan
    ORDER BY b.nama_barang ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $items[] = $row;

echo json_encode([
    'success' => true,
    'header' => $header,
    'jenis_pemeriksaan' => $jenis,
    'items' => $items
], JSON_UNESCAPED_UNICODE);
