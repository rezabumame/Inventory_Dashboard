<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Klinik tidak valid']);
    exit;
}
if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$klin = $conn->query("SELECT kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$loc = trim((string)($klin['kode_homecare'] ?? ''));
if ($loc === '') {
    echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki Kode HC']);
    exit;
}
$loc_esc = $conn->real_escape_string($loc);

$sql = "
SELECT 
    sm.odoo_product_id,
    TRIM(sm.kode_barang) AS kode_barang,
    sm.qty AS mirror_qty,
    b_odoo.id AS mapped_barang_id,
    b_odoo.kode_barang AS mapped_kode_barang,
    b_odoo.nama_barang AS mapped_nama_barang,
    b_odoo.satuan AS mapped_satuan,
    COALESCE(b_kode.id, b_id.id) AS suggested_barang_id,
    COALESCE(b_kode.kode_barang, b_id.kode_barang) AS suggested_kode_barang,
    COALESCE(b_kode.nama_barang, b_id.nama_barang) AS suggested_nama_barang,
    COALESCE(b_kode.satuan, b_id.satuan) AS suggested_satuan
FROM inventory_stock_mirror sm
LEFT JOIN inventory_barang b_odoo ON b_odoo.odoo_product_id = sm.odoo_product_id
LEFT JOIN inventory_barang b_kode ON TRIM(b_kode.kode_barang) = TRIM(sm.kode_barang)
LEFT JOIN inventory_barang b_id ON b_id.id = CAST(TRIM(sm.kode_barang) AS UNSIGNED)
WHERE TRIM(sm.location_code) = '$loc_esc'
";
$rows = [];
$r = $conn->query($sql);
while ($r && ($row = $r->fetch_assoc())) {
    $mapped_barang_id = (int)($row['mapped_barang_id'] ?? 0);
    $suggested_barang_id = (int)($row['suggested_barang_id'] ?? 0);
    $barang_id = $mapped_barang_id > 0 ? $mapped_barang_id : $suggested_barang_id;
    $mirror_qty = (float)($row['mirror_qty'] ?? 0);
    $allocated = 0.0;
    $uom_oper = '';
    $uom_odoo = '';
    $ratio = 1.0;
    if ($barang_id > 0) {
        $rc = $conn->query("
            SELECT
                COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom_oper,
                COALESCE(NULLIF(uc.from_uom,''), b.uom) AS uom_odoo,
                COALESCE(uc.multiplier, 1) AS uom_ratio
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE b.id = $barang_id
            LIMIT 1
        ");
        if ($rc && $rc->num_rows > 0) {
            $c = $rc->fetch_assoc();
            $uom_oper = trim((string)($c['uom_oper'] ?? ''));
            $uom_odoo = trim((string)($c['uom_odoo'] ?? ''));
            $ratio = (float)($c['uom_ratio'] ?? 1);
            if ($ratio <= 0) $ratio = 1.0;
        }
        $res_a = $conn->query("SELECT COALESCE(SUM(qty),0) AS total FROM inventory_stok_tas_hc WHERE klinik_id = $klinik_id AND barang_id = $barang_id");
        if ($res_a && $res_a->num_rows > 0) $allocated = (float)($res_a->fetch_assoc()['total'] ?? 0);
    }
    $mirror_oper = $barang_id > 0 ? ($mirror_qty / $ratio) : $mirror_qty;
    $unallocated = $barang_id > 0 ? ($mirror_oper - $allocated) : ($mirror_qty - $allocated);
    if ($unallocated < 0) $unallocated = 0;
    $needs_mapping = ($mapped_barang_id <= 0);
    if ($needs_mapping || $unallocated > 0.0001) {
        $rows[] = [
            'odoo_product_id' => (string)($row['odoo_product_id'] ?? ''),
            'kode_barang' => (string)($row['kode_barang'] ?? ''),
            'mirror_qty' => $mirror_oper,
            'mirror_qty_raw' => $mirror_qty,
            'allocated_qty' => $allocated,
            'unallocated_qty' => $unallocated,
            'uom_oper' => $uom_oper,
            'uom_odoo' => $uom_odoo,
            'uom_ratio' => $ratio,
            'mapped_barang_id' => $mapped_barang_id,
            'mapped_kode_barang' => (string)($row['mapped_kode_barang'] ?? ''),
            'mapped_nama_barang' => (string)($row['mapped_nama_barang'] ?? ''),
            'mapped_satuan' => (string)($row['mapped_satuan'] ?? ''),
            'suggested_barang_id' => $suggested_barang_id,
            'suggested_kode_barang' => (string)($row['suggested_kode_barang'] ?? ''),
            'suggested_nama_barang' => (string)($row['suggested_nama_barang'] ?? ''),
            'suggested_satuan' => (string)($row['suggested_satuan'] ?? ''),
            'needs_mapping' => $needs_mapping ? 1 : 0
        ];
    }
}

echo json_encode(['success' => true, 'items' => $rows]);


