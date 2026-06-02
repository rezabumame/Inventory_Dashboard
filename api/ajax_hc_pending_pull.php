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

// Ambil kode_klinik untuk query Odoo mirror
$kl_row = $conn->query("SELECT kode_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$kode_klinik_esc = $conn->real_escape_string(trim((string)($kl_row['kode_klinik'] ?? '')));

$res = $conn->query("
    SELECT pp.id, pp.barang_id, b.kode_barang, b.nama_barang,
           COALESCE(uc.to_uom, b.satuan) AS satuan,
           COALESCE(uc.multiplier, 1) AS ratio,
           pp.qty, pp.catatan, pp.created_at,
           COALESCE((
               SELECT SUM(sm.qty)
               FROM inventory_stock_mirror sm
               WHERE TRIM(sm.location_code) = '$kode_klinik_esc'
               AND (TRIM(sm.kode_barang) = b.kode_barang OR TRIM(sm.odoo_product_id) = b.odoo_product_id)
           ), 0) AS stok_klinik_odoo
    FROM inventory_hc_pending_pull pp
    JOIN inventory_barang b ON b.id = pp.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE pp.klinik_id = $klinik_id
    ORDER BY pp.created_at ASC
");

$items = [];
while ($res && ($row = $res->fetch_assoc())) {
    $ratio = max((float)($row['ratio'] ?? 1), 0.000001);
    $items[] = [
        'id'          => (int)$row['id'],
        'barang_id'   => (int)$row['barang_id'],
        'kode_barang' => $row['kode_barang'],
        'nama_barang' => $row['nama_barang'],
        'satuan'      => $row['satuan'],
        'qty'         => (float)$row['qty'],
        'stok_klinik' => round((float)$row['stok_klinik_odoo'] / $ratio, 4),
        'catatan'     => $row['catatan'],
        'created_at'  => $row['created_at'],
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'total' => count($items)]);
