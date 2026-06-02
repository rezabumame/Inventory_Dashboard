<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Klinik tidak valid.']); exit;
}

$res = $conn->query("
    SELECT pp.id, pp.barang_id, b.kode_barang, b.nama_barang,
           COALESCE(uc.to_uom, b.satuan) AS satuan,
           pp.qty,
           COALESCE(sg.qty, 0) AS stok_klinik,
           pp.catatan,
           pp.created_at
    FROM inventory_hc_pending_pull pp
    JOIN inventory_barang b ON b.id = pp.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    LEFT JOIN inventory_stok_gudang_klinik sg ON sg.barang_id = pp.barang_id AND sg.klinik_id = pp.klinik_id
    WHERE pp.klinik_id = $klinik_id
    ORDER BY pp.created_at ASC
");

$items = [];
while ($res && ($row = $res->fetch_assoc())) {
    $items[] = [
        'id'          => (int)$row['id'],
        'barang_id'   => (int)$row['barang_id'],
        'kode_barang' => $row['kode_barang'],
        'nama_barang' => $row['nama_barang'],
        'satuan'      => $row['satuan'],
        'qty'         => (float)$row['qty'],
        'stok_klinik' => (float)$row['stok_klinik'],
        'catatan'     => $row['catatan'],
        'created_at'  => $row['created_at'],
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'total' => count($items)]);
