<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$barang_id = (int)($_GET['barang_id'] ?? 0);
if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'barang_id tidak valid.']); exit;
}

$today = date('Y-m-d');
$r = $conn->query("
    SELECT id, DATE_FORMAT(ed_month, '%Y-%m-%d') AS ed_date, keterangan, klinik_id,
           (ed_month >= '$today') AS is_active
    FROM inventory_barang_ed
    WHERE barang_id = $barang_id AND ed_month >= '$today'
    ORDER BY ed_month ASC
");

$batches = [];
while ($row = $r->fetch_assoc()) {
    $batches[] = [
        'id'         => (int)$row['id'],
        'ed_date'    => $row['ed_date'],
        'keterangan' => $row['keterangan'],
        'klinik_id'  => $row['klinik_id'] !== null ? (int)$row['klinik_id'] : null,
        'is_active'  => (bool)(int)$row['is_active'],
    ];
}

echo json_encode(['success' => true, 'batches' => $batches]);
