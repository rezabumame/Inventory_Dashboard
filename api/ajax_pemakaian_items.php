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

// Config global "Izinkan Stok Minus" (lihat views/settings/integrasi.php, card "Izinkan Stok
// Minus") -- menentukan apakah barang yang stoknya 0/minus/belum pernah ada tetap ditampilkan.
// Default-nya ini CUMA berlaku di modal Tambah -- modal Edit selalu diperlakukan seolah mode
// 'all' (nampilin semua barang) supaya tidak ada item yang "kelewat" saat koreksi transaksi
// lama, kecuali admin sengaja aktifkan toggle "Terapkan juga ke modal Edit".
$neg_mode_raw = get_setting('bhp_negative_stock_mode', 'off');
$context = (string)($_POST['context'] ?? 'add'); // 'add' atau 'edit', dikirim dari frontend
$apply_to_edit = get_setting('bhp_negative_stock_apply_to_edit', '0') === '1';
$superadmin_bypass = get_setting('bhp_negative_stock_superadmin_bypass', '0') === '1';

if ($superadmin_bypass && ($_SESSION['role'] ?? '') === 'super_admin') {
    // Super Admin selalu lihat semua barang, apa pun mode/konteksnya -- role lain tetap
    // ikut aturan normal di atas.
    $neg_mode = 'all';
} else {
    $neg_mode = ($context === 'edit' && !$apply_to_edit) ? 'all' : $neg_mode_raw;
}

$neg_items = [];
if ($neg_mode === 'selected') {
    $neg_items = json_decode((string)get_setting('bhp_negative_stock_items', '[]'), true);
    if (!is_array($neg_items)) $neg_items = [];
    $neg_items = array_map('intval', $neg_items);
}
$neg_show_all = ($neg_mode === 'all');
$neg_ids_sql = !empty($neg_items) ? implode(',', $neg_items) : '';

$items = [];

if ($jenis === 'hc' && $user_hc_id > 0) {
    // Stok Tas HC
    if ($neg_mode === 'off') {
        // Normal -- cuma barang dgn stok > 0
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
    } else {
        // Mode 'all' -> semua barang apa pun stoknya. Mode 'selected' -> CUMA barang yang
        // di-whitelist (restriktif, barang lain -- termasuk yang stoknya > 0 -- disembunyikan).
        // Basis query-nya inventory_barang (bukan inventory_stok_tas_hc) + LEFT JOIN supaya
        // barang yang belum pernah ada baris stok tas HC sama sekali tetap bisa muncul.
        if ($neg_show_all) {
            $extra_cond = "1=1";
        } else {
            $extra_cond = $neg_ids_sql !== '' ? "b.id IN ($neg_ids_sql)" : "1=0";
        }
        $res = $conn->query("
            SELECT b.id as barang_id, COALESCE(st.qty, 0) as qty, b.nama_barang, b.kode_barang, b.odoo_product_id,
                   COALESCE(uc.to_uom, b.satuan) as satuan,
                   COALESCE(uc.from_uom, '') as uom_odoo,
                   COALESCE(uc.multiplier, 1) as uom_ratio
            FROM inventory_barang b
            LEFT JOIN inventory_stok_tas_hc st ON st.barang_id = b.id AND st.user_id = $user_hc_id AND st.klinik_id = $klinik_id
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE $extra_cond
            ORDER BY b.nama_barang ASC
        ");
    }
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
        
        // Ambil semua baris dulu, lalu hitung stok efektif utk SEMUA barang sekaligus lewat
        // stock_effective_batch() (query agregat) — bukan panggil stock_effective() satu-satu per
        // baris (dulu bisa ribuan query utk 1 lokasi dgn ratusan barang). Hasilnya identik persis,
        // cuma jauh lebih sedikit query. Urutan tampil tetap ikuti urutan nama dari $res di atas.
        $mirror_rows = [];
        $mirror_bids = [];
        while ($row = $res->fetch_assoc()) {
            $mirror_rows[] = $row;
            $mirror_bids[] = (int)$row['barang_id'];
        }
        $ef_map = stock_effective_batch($conn, $klinik_id, ($jenis === 'hc'), $mirror_bids);
        foreach ($mirror_rows as $row) {
            $bid = (int)$row['barang_id'];
            $ef = $ef_map[$bid] ?? ['ok' => false];
            if (!$ef['ok']) continue;

            $show = false;
            if ($neg_mode === 'off') {
                $show = ((float)$ef['on_hand'] > 0); // Nonaktif -- cuma barang stok > 0
            } elseif ($neg_mode === 'all') {
                $show = true; // Semua Item -- apa pun stoknya
            } elseif ($neg_mode === 'selected') {
                $show = in_array($bid, $neg_items, true); // Item Tertentu -- restriktif
            }
            if (!$show) continue;

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
        $extra_rows = [];
        $extra_bids = [];
        while ($row = $res_extra->fetch_assoc()) {
            $extra_rows[] = $row;
            $extra_bids[] = (int)$row['barang_id'];
        }
        $ef_extra_map = stock_effective_batch($conn, $klinik_id, ($jenis === 'hc'), $extra_bids);
        foreach ($extra_rows as $row) {
            $bid = (int)$row['barang_id'];
            $ef = $ef_extra_map[$bid] ?? ['ok' => false];
            if (!$ef['ok']) continue;

            $show = false;
            if ($neg_mode === 'off') {
                $show = ((float)$ef['on_hand'] > 0);
            } elseif ($neg_mode === 'all') {
                $show = true;
            } elseif ($neg_mode === 'selected') {
                $show = in_array($bid, $neg_items, true);
            }
            if (!$show) continue;
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

        // Tambahan: barang yang di-config "Izinkan Stok Minus" (mode 'all' atau 'selected')
        // tapi BELUM PERNAH ada baris stok sama sekali di lokasi ini -- tidak ada di mirror
        // maupun histori transaksi (makanya 2 query di atas tidak pernah menangkapnya) --
        // tetap dimunculkan di sini, qty dianggap 0 kalau memang belum pernah ada.
        if ($neg_show_all || ($neg_mode === 'selected' && !empty($neg_items))) {
            $current_bids = array_map('intval', array_column($items, 'barang_id'));
            $res_missing = null;
            if ($neg_show_all) {
                $missing_cond = !empty($current_bids) ? ('AND b.id NOT IN (' . implode(',', $current_bids) . ')') : '';
                $res_missing = $conn->query("
                    SELECT b.id as barang_id, b.nama_barang, b.kode_barang,
                           COALESCE(uc.to_uom, b.satuan) as satuan,
                           COALESCE(uc.from_uom, '') as uom_odoo,
                           COALESCE(uc.multiplier, 1) as uom_ratio
                    FROM inventory_barang b
                    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
                    WHERE 1=1 $missing_cond
                ");
            } else {
                $to_check = array_diff($neg_items, $current_bids);
                if (!empty($to_check)) {
                    $ids_str = implode(',', array_map('intval', $to_check));
                    $res_missing = $conn->query("
                        SELECT b.id as barang_id, b.nama_barang, b.kode_barang,
                               COALESCE(uc.to_uom, b.satuan) as satuan,
                               COALESCE(uc.from_uom, '') as uom_odoo,
                               COALESCE(uc.multiplier, 1) as uom_ratio
                        FROM inventory_barang b
                        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
                        WHERE b.id IN ($ids_str)
                    ");
                }
            }
            if ($res_missing) {
                $missing_rows = [];
                $missing_bids = [];
                while ($row = $res_missing->fetch_assoc()) {
                    $missing_rows[] = $row;
                    $missing_bids[] = (int)$row['barang_id'];
                }
                $ef_missing_map = stock_effective_batch($conn, $klinik_id, ($jenis === 'hc'), $missing_bids);
                foreach ($missing_rows as $row) {
                    $bid = (int)$row['barang_id'];
                    $ef = $ef_missing_map[$bid] ?? ['ok' => true, 'on_hand' => 0];
                    $mult = (float)($row['uom_ratio'] ?? 1);
                    if ($mult <= 0) $mult = 1;
                    $items[] = [
                        'barang_id' => $bid,
                        'nama_barang' => (string)$row['nama_barang'],
                        'kode_barang' => (string)$row['kode_barang'],
                        'satuan' => (string)$row['satuan'],
                        'uom_odoo' => (string)$row['uom_odoo'],
                        'uom_ratio' => $mult,
                        'qty' => (float)($ef['on_hand'] ?? 0) * $mult
                    ];
                }
            }
        }
    }
}

// Fitur "BHP Non-Odoo (Lokal)" sudah dihapus total -- barang lokal tidak lagi bisa dipilih di
// sini, apa pun mode-nya.

echo json_encode(['success' => true, 'items' => $items]);
