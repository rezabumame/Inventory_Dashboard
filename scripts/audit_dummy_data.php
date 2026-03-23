<?php
require_once __DIR__ . '/../config/database.php';

function fetch_all(mysqli_result $res): array {
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
    return $rows;
}

$out = [
    'dummy_klinik' => [],
    'dummy_barang' => [],
    'dummy_stock_mirror' => [],
    'counts' => [
        'dummy_klinik' => 0,
        'dummy_barang' => 0,
        'dummy_stock_mirror' => 0
    ]
];

$r = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare, status FROM klinik WHERE nama_klinik = 'Dummy Klinik (Odoo)' OR kode_klinik LIKE 'DMY%' OR kode_homecare LIKE 'DMY%' ORDER BY id ASC");
$out['dummy_klinik'] = fetch_all($r);
$out['counts']['dummy_klinik'] = count($out['dummy_klinik']);

$r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan, kategori FROM barang WHERE odoo_product_id LIKE 'DUMMY-%' OR kode_barang IN ('BHP001','BHP002','BHP003','BHP999') ORDER BY id ASC");
$out['dummy_barang'] = fetch_all($r);
$out['counts']['dummy_barang'] = count($out['dummy_barang']);

$r = $conn->query("SELECT location_code, odoo_product_id, kode_barang, qty, updated_at FROM stock_mirror WHERE odoo_product_id LIKE 'DUMMY-%' OR location_code LIKE 'DMY%' OR location_code LIKE 'DMY%/Stock' OR location_code LIKE 'DMY%-HC%' OR kode_barang IN ('BHP001','BHP002','BHP003','BHP999') ORDER BY location_code ASC, odoo_product_id ASC");
$out['dummy_stock_mirror'] = fetch_all($r);
$out['counts']['dummy_stock_mirror'] = count($out['dummy_stock_mirror']);

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

