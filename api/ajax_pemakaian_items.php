<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$jenis = (string)($_POST['jenis'] ?? 'klinik'); // klinik or hc
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Klinik ID required']);
    exit;
}

$items = [];

if ($jenis === 'hc' && $user_hc_id > 0) {
    // Stok Tas HC
    $res = $conn->query("
        SELECT st.barang_id, st.qty, b.nama_barang, b.kode_barang, b.odoo_product_id,
               COALESCE(uc.to_uom, b.satuan) as satuan,
               COALESCE(uc.from_uom, '') as uom_odoo,
               COALESCE(uc.multiplier, 1) as uom_ratio
        FROM inventory_stok_tas_hc st
        JOIN inventory_barang b ON st.barang_id = b.id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE st.user_id = $user_hc_id AND st.qty > 0
        ORDER BY b.nama_barang ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $mult = (float)($row['uom_ratio'] ?? 1);
        $items[] = [
            'barang_id' => (int)$row['barang_id'],
            'nama_barang' => (string)$row['nama_barang'],
            'kode_barang' => (string)$row['kode_barang'],
            'satuan' => (string)$row['satuan'],
            'uom_odoo' => (string)$row['uom_odoo'],
            'uom_ratio' => $mult,
            'qty' => (float)$row['qty'] * $mult // return in small unit for JS to handle
        ];
    }
} else {
    // Stok Klinik (Onsite)
    // We fetch all items from mirror for this clinic
    $kl = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    $kode = ($jenis === 'hc') ? trim((string)($kl['kode_homecare'] ?? '')) : trim((string)($kl['kode_klinik'] ?? ''));
    
    if ($kode !== '') {
        $loc = stock_resolve_location($conn, $kode);
        $loc_esc = $conn->real_escape_string($loc);
        
        $res = $conn->query("
            SELECT b.id as barang_id, b.nama_barang, b.kode_barang, sm.qty as mirror_qty,
                   COALESCE(uc.to_uom, b.satuan) as satuan,
                   COALESCE(uc.from_uom, '') as uom_odoo,
                   COALESCE(uc.multiplier, 1) as uom_ratio
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang)
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE sm.location_code = '$loc_esc' AND sm.qty > 0
            ORDER BY b.nama_barang ASC
        ");
        
        while ($row = $res->fetch_assoc()) {
            $bid = (int)$row['barang_id'];
            $ef = stock_effective($conn, $klinik_id, ($jenis === 'hc'), $bid);
            if ($ef['ok'] && $ef['available'] > 0) {
                $mult = (float)($row['uom_ratio'] ?? 1);
                $items[] = [
                    'barang_id' => $bid,
                    'nama_barang' => (string)$row['nama_barang'],
                    'kode_barang' => (string)$row['kode_barang'],
                    'satuan' => (string)$row['satuan'],
                    'uom_odoo' => (string)$row['uom_odoo'],
                    'uom_ratio' => $mult,
                    'qty' => (float)$ef['available'] * $mult
                ];
            }
        }
    }
}

echo json_encode(['success' => true, 'items' => $items]);
