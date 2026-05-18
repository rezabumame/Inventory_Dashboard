<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$klinik_id = (int)($_GET['klinik_id'] ?? 0);

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'klinik_id required']);
    exit;
}

$res = $conn->query("
    SELECT b.nama_barang,
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           sth.qty
    FROM inventory_stok_tas_hc sth
    JOIN inventory_barang b ON b.id = sth.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE sth.user_id = $user_id AND sth.klinik_id = $klinik_id AND sth.qty != 0
    ORDER BY b.nama_barang ASC
");

$items = [];
while ($res && ($row = $res->fetch_assoc())) {
    $items[] = [
        'nama_barang' => $row['nama_barang'],
        'uom'         => $row['uom'],
        'qty'         => (float)$row['qty'],
    ];
}

echo json_encode(['success' => true, 'items' => $items]);
