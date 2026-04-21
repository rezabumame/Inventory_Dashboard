<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
$user_hc_id = (int)($_GET['user_hc_id'] ?? 0);

if ($klinik_id <= 0 || $user_hc_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// 1. Get clinic info for mirror location
$kl = $conn->query("SELECT kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$loc_hc = trim((string)($kl['kode_homecare'] ?? ''));

// 2. Collect all relevant barang IDs (from mirror for this location AND from tas for this petugas)
$all_barang_ids = [];

// From Mirror
if ($loc_hc !== '') {
    $loc_esc = $conn->real_escape_string($loc_hc);
    $res_m = $conn->query("
        SELECT b.id 
        FROM inventory_stock_mirror sm
        JOIN inventory_barang b ON (b.odoo_product_id = sm.odoo_product_id OR b.kode_barang = sm.kode_barang)
        WHERE sm.location_code = '$loc_esc'
    ");
    while ($res_m && ($rm = $res_m->fetch_assoc())) $all_barang_ids[(int)$rm['id']] = true;
}

// From Tas
$res_t = $conn->query("SELECT DISTINCT barang_id FROM inventory_stok_tas_hc WHERE user_id = $user_hc_id AND klinik_id = $klinik_id");
while ($res_t && ($rt = $res_t->fetch_assoc())) $all_barang_ids[(int)$rt['barang_id']] = true;

$items = [];
if (!empty($all_barang_ids)) {
    $ids_str = implode(',', array_keys($all_barang_ids));
    
    $res = $conn->query("
        SELECT
            b.id AS barang_id,
            b.kode_barang,
            b.nama_barang,
            COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom_oper,
            COALESCE(NULLIF(uc.from_uom,''), b.uom) AS uom_odoo,
            COALESCE(uc.multiplier, 1) AS uom_ratio,
            (SELECT COALESCE(SUM(qty), 0) FROM inventory_stok_tas_hc WHERE user_id = $user_hc_id AND klinik_id = $klinik_id AND barang_id = b.id) AS qty_lama
        FROM inventory_barang b
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE b.id IN ($ids_str)
        ORDER BY b.nama_barang ASC
    ");

    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = [
            'barang_id' => (int)$row['barang_id'],
            'kode_barang' => (string)$row['kode_barang'],
            'nama_barang' => (string)$row['nama_barang'],
            'uom_oper' => (string)$row['uom_oper'],
            'uom_odoo' => (string)$row['uom_odoo'],
            'uom_ratio' => (float)$row['uom_ratio'],
            'qty_lama' => (float)$row['qty_lama']
        ];
    }
}

echo json_encode(['success' => true, 'items' => $items]);
