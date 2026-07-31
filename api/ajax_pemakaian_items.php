<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check role: admin_hc cannot access BHP usage
if (in_array($_SESSION['role'] ?? '', ['admin_hc'])) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
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
        WHERE st.user_id = $user_hc_id AND st.klinik_id = $klinik_id AND st.qty > 0
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
            WHERE sm.location_code = '$loc_esc'
            ORDER BY b.nama_barang ASC
        ");
        
        while ($row = $res->fetch_assoc()) {
            $bid = (int)$row['barang_id'];
            $ef = stock_effective($conn, $klinik_id, ($jenis === 'hc'), $bid);
            if ($ef['ok']) { // Tampilkan semua item termasuk stok 0/negatif (izinkan input seperti mode upload)
                $mult = (float)($row['uom_ratio'] ?? 1);
                $items[] = [
                    'barang_id' => $bid,
                    'nama_barang' => (string)$row['nama_barang'],
                    'kode_barang' => (string)$row['kode_barang'],
                    'satuan' => (string)$row['satuan'],
                    'uom_odoo' => (string)$row['uom_odoo'],
                    'uom_ratio' => $mult,
                    'qty' => (float)$ef['on_hand'] * $mult // Menggunakan on_hand
                ];
            }
        }

        // Item yang stoknya cuma pernah masuk lewat Request Barang/transfer internal (tidak pernah
        // ke-sync dari Odoo di lokasi ini) tidak akan pernah punya baris di inventory_stock_mirror,
        // jadi tidak ikut kena query di atas — walau stok efektifnya (via stock_effective(), yg sudah
        // dipakai juga di ringkasan stok) sebenarnya > 0. Cari tambahan dari riwayat transaksi supaya
        // tetap muncul di pencarian Input BHP.
        $existing_bids = array_map('intval', array_column($items, 'barang_id'));
        $level_for_tx  = ($jenis === 'hc') ? 'hc' : 'klinik';
        $ref_types     = ($jenis === 'hc')
            ? ['hc_petugas_transfer', 'pemakaian_bhp_revision']
            : ['transfer', 'hc_petugas_transfer', 'pemakaian_bhp_revision'];
        $ref_list      = implode(',', array_map(fn($t) => "'" . $conn->real_escape_string($t) . "'", $ref_types));
        $level_esc     = $conn->real_escape_string($level_for_tx);
        $exclude_cond  = $existing_bids ? ('AND b.id NOT IN (' . implode(',', $existing_bids) . ')') : '';

        $res_extra = $conn->query("
            SELECT DISTINCT b.id as barang_id, b.nama_barang, b.kode_barang,
                   COALESCE(uc.to_uom, b.satuan) as satuan,
                   COALESCE(uc.from_uom, '') as uom_odoo,
                   COALESCE(uc.multiplier, 1) as uom_ratio
            FROM inventory_transaksi_stok ts
            JOIN inventory_barang b ON b.id = ts.barang_id
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE ts.level = '$level_esc' AND ts.level_id = $klinik_id
              AND ts.referensi_tipe IN ($ref_list) $exclude_cond
        ");
        while ($row = $res_extra->fetch_assoc()) {
            $bid = (int)$row['barang_id'];
            $ef = stock_effective($conn, $klinik_id, ($jenis === 'hc'), $bid);
            if (!$ef['ok'] || $ef['on_hand'] <= 0) continue; // cuma munculin kalau memang ada stoknya
            $mult = (float)($row['uom_ratio'] ?? 1);
            if ($mult <= 0) $mult = 1;
            $items[] = [
                'barang_id' => $bid,
                'nama_barang' => (string)$row['nama_barang'],
                'kode_barang' => (string)$row['kode_barang'],
                'satuan' => (string)$row['satuan'],
                'uom_odoo' => (string)$row['uom_odoo'],
                'uom_ratio' => $mult,
                'qty' => (float)$ef['on_hand'] * $mult
            ];
        }
    }
}

// 4. BHP Non-Odoo (Lokal)
// Only include local items if we are in Klinik mode (as local items are usually per clinic)
if ($jenis !== 'hc' || $user_hc_id <= 0) {
    $res_lok = $conn->query("
        SELECT bl.id as barang_id, bl.nama_item as nama_barang, sl.qty, bl.uom as satuan
        FROM inventory_stok_lokal sl
        JOIN inventory_barang_lokal bl ON sl.barang_lokal_id = bl.id
        WHERE sl.klinik_id = $klinik_id AND sl.qty > 0
        ORDER BY bl.nama_item ASC
    ");
    while ($row = $res_lok->fetch_assoc()) {
        $items[] = [
            'barang_id' => (int)$row['barang_id'],
            'nama_barang' => (string)$row['nama_barang'],
            'kode_barang' => 'LOKAL-' . $row['barang_id'],
            'satuan' => (string)$row['satuan'],
            'uom_odoo' => '',
            'uom_ratio' => 1,
            'qty' => (float)$row['qty'],
            'is_lokal' => true
        ];
    }
}

echo json_encode(['success' => true, 'items' => $items]);
