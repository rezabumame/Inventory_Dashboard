<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik']);

$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$can_all        = in_array($role, ['super_admin', 'admin_gudang']);
$csrf           = csrf_token();

// ── Locations ─────────────────────────────────────────────────────────────────
$q_lok_cond = $can_all ? "status='active'" : "status='active' AND id=$user_klinik_id";
$qLok = $conn->query("SELECT id, kode_klinik, COALESCE(kode_homecare,'') AS kode_homecare, nama_klinik, COALESCE(alamat,'') AS alamat FROM inventory_klinik WHERE $q_lok_cond ORDER BY nama_klinik ASC");
$locations = [];
while ($qLok && ($r = $qLok->fetch_assoc())) $locations[] = $r;

// Gudang Utama — dari settings, bukan dari inventory_klinik
$gudang_loc_code = '';
$gudang_klinik_id = 0; // tidak ada di inventory_klinik, cari by kode_klinik jika ada
if ($can_all) {
    require_once __DIR__ . '/../../config/settings.php';
    $gudang_loc_code = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc_code !== '') {
        // Cari apakah ada baris di inventory_klinik dengan kode_klinik = gudang_loc
        $g_esc = $conn->real_escape_string($gudang_loc_code);
        $rGd   = $conn->query("SELECT id FROM inventory_klinik WHERE kode_klinik = '$g_esc' LIMIT 1");
        if ($rGd && ($gRow = $rGd->fetch_assoc())) $gudang_klinik_id = (int)$gRow['id'];
    }
}

// ── Selected klinik ───────────────────────────────────────────────────────────
$lok_gudang = ($can_all && isset($_GET['lokasi']) && $_GET['lokasi'] === 'gudang_utama' && $gudang_loc_code !== '');
$lok_id   = (!$lok_gudang && isset($_GET['lokasi'])) ? (int)$_GET['lokasi'] : 0;
$lok_data = null;
if ($lok_gudang) {
    // Mode gudang utama: mock lok_data untuk display, tanpa klinik_id
    $lok_data = ['nama_klinik' => 'Gudang Utama', 'kode_klinik' => $gudang_loc_code, 'kode_homecare' => ''];
} else {
    foreach ($locations as $l) { if ((int)$l['id'] === $lok_id) { $lok_data = $l; break; } }
    if (!$lok_data && !$can_all && count($locations) === 1) { $lok_data = $locations[0]; $lok_id = (int)$lok_data['id']; }
}

// ── BIS columns check ─────────────────────────────────────────────────────────
$r_bis    = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'track_ed'");
$has_bis  = ($r_bis && $r_bis->num_rows > 0);
$bis_sel  = $has_bis ? "b.track_ed," : "0 AS track_ed,";
$r_bc     = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode'");
$has_bc   = ($r_bc && $r_bc->num_rows > 0);
$r_bci    = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bci  = ($r_bci && $r_bci->num_rows > 0);
$bc_sel   = ($has_bc ? "COALESCE(b.barcode,'') AS barcode," : "'0' AS barcode,")
           .($has_bci ? "COALESCE(b.barcode_internal,'') AS barcode_internal," : "'' AS barcode_internal,");

// ── Load data when klinik selected ───────────────────────────────────────────
$items_map      = []; // barang_id → item info
$klinik_stock   = []; // barang_id → qty (stok_gudang_klinik)
$odoo_klinik    = []; // kode_barang → qty (stock_mirror by kode_klinik)
$odoo_hc        = []; // kode_barang → qty (stock_mirror by kode_homecare)
$hc_users       = []; // [{id, nama}]
$hc_stocks      = []; // user_id → [barang_id → qty]
$odoo_last_sync = '';

if ($lok_data) {
    // Items
    $qIt = $conn->query("SELECT b.id, b.kode_barang, b.nama_barang, b.satuan, $bis_sel $bc_sel
        COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
        uc.from_uom, uc.multiplier
        FROM inventory_barang b
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        ORDER BY b.nama_barang ASC LIMIT 3000");
    while ($qIt && ($r = $qIt->fetch_assoc())) {
        $r['track_ed']        = (int)($r['track_ed'] ?? 0);
        $r['vendor_barcodes'] = [];
        $items_map[(int)$r['id']] = $r;
    }

    // Vendor barcodes from inventory_barcode_vendor
    $r_vbt = $conn->query("SHOW TABLES LIKE 'inventory_barcode_vendor'");
    if ($r_vbt && $r_vbt->num_rows > 0 && !empty($items_map)) {
        $bid_list = implode(',', array_keys($items_map));
        $qVB = $conn->query("SELECT barang_id, barcode FROM inventory_barcode_vendor WHERE barang_id IN ($bid_list)");
        while ($qVB && ($r = $qVB->fetch_assoc())) {
            $bid = (int)$r['barang_id'];
            if (isset($items_map[$bid])) {
                $items_map[$bid]['vendor_barcodes'][] = $r['barcode'];
            }
        }
    }

    // Stock sistem lokal (gudang utama vs klinik)
    if ($lok_gudang) {
        $qKS = $conn->query("SELECT barang_id, qty FROM inventory_stok_gudang_utama");
    } else {
        $qKS = $conn->query("SELECT barang_id, qty FROM inventory_stok_gudang_klinik WHERE klinik_id = $lok_id");
    }
    while ($qKS && ($r = $qKS->fetch_assoc())) {
        $klinik_stock[(int)$r['barang_id']] = (float)$r['qty'];
    }

    // Odoo stock mirror — klinik/gudang location (dengan konversi UOM: qty Odoo / multiplier)
    $kode_k = $conn->real_escape_string(trim($lok_data['kode_klinik']));
    if ($kode_k !== '') {
        $candidates_k = ["'$kode_k'", "'" . addslashes($kode_k . '/Stock') . "'", "'" . addslashes(preg_replace('/\/Stock$/i','', $kode_k)) . "'"];
        $in_k = implode(',', $candidates_k);
        $qOM = $conn->query("
            SELECT sm.kode_barang,
                   SUM(sm.qty) / NULLIF(COALESCE(MAX(uc.multiplier), 1), 0) AS qty
            FROM inventory_stock_mirror sm
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = sm.kode_barang
            WHERE sm.location_code IN ($in_k)
            GROUP BY sm.kode_barang");
        while ($qOM && ($r = $qOM->fetch_assoc())) $odoo_klinik[$r['kode_barang']] = (float)$r['qty'];
    }

    // Odoo stock mirror — HC location (klinik only, dengan konversi UOM)
    if (!$lok_gudang) {
        $kode_hc = $conn->real_escape_string(trim($lok_data['kode_homecare']));
        if ($kode_hc !== '') {
            $candidates_hc = ["'$kode_hc'", "'" . addslashes($kode_hc . '/Stock') . "'", "'" . addslashes(preg_replace('/\/Stock$/i','', $kode_hc)) . "'"];
            $in_hc = implode(',', $candidates_hc);
            $qHM = $conn->query("
                SELECT sm.kode_barang,
                       SUM(sm.qty) / NULLIF(COALESCE(MAX(uc.multiplier), 1), 0) AS qty
                FROM inventory_stock_mirror sm
                LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = sm.kode_barang
                WHERE sm.location_code IN ($in_hc)
                GROUP BY sm.kode_barang");
            while ($qHM && ($r = $qHM->fetch_assoc())) $odoo_hc[$r['kode_barang']] = (float)$r['qty'];
        }
    }

    // HC users — gudang tidak punya HC
    if (!$lok_gudang) {
        $qHC = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE klinik_id = $lok_id AND role = 'petugas_hc' AND status = 'active' ORDER BY nama_lengkap ASC");
        while ($qHC && ($r = $qHC->fetch_assoc())) $hc_users[] = ['id' => (int)$r['id'], 'nama' => $r['nama_lengkap']];
    }

    // Cek kolom periode & is_locked
    $r_so_per = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'periode'");
    $so_has_periode = ($r_so_per && $r_so_per->num_rows > 0);
    $r_so_lck = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_locked'");
    $so_has_locked  = ($r_so_lck && $r_so_lck->num_rows > 0);

    // Cari SO periode bulan ini
    $so_periode     = date('Y-m');
    $so_periode_label = date('F Y'); // e.g. "July 2026"
    $opname_id_today = 0;
    $opname_status   = null; // null=belum ada, 'open'=aktif, 'locked'=terkunci
    $opname_locked_at = null;
    $opname_locked_by_name = '';
    $is_historical_view = false;
    $so_all_periods = []; // periode => is_locked, utk selector "Lihat Riwayat Periode"

    if ($so_has_periode) {
        $lck_sel = $so_has_locked ? ", is_locked, locked_at, locked_by" : "";
        $has_gu_col = true;
        if ($lok_gudang) {
            $r_gu_col = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_gudang_utama'");
            $has_gu_col = ($r_gu_col && $r_gu_col->num_rows > 0);
        }
        $loc_cond  = $lok_gudang ? "is_gudang_utama = 1" : "klinik_id = $lok_id";
        $can_query = !$lok_gudang || $has_gu_col;

        if ($can_query) {
            // Daftar semua periode yang pernah ada utk lokasi ini (utk selector riwayat)
            $rAll = $conn->query("SELECT periode, MAX(is_locked) AS is_locked FROM inventory_stok_opname
                WHERE $loc_cond AND periode IS NOT NULL GROUP BY periode ORDER BY periode DESC");
            while ($rAll && ($ar = $rAll->fetch_assoc())) {
                $so_all_periods[$ar['periode']] = (int)($ar['is_locked'] ?? 0);
            }

            // Sesi aktif = baris yang belum dikunci utk lokasi ini, apa pun periodenya — supaya
            // sesi yang berjalan lewat tengah malam (lintas bulan kalender) tidak terputus.
            $lock_cond = $so_has_locked ? "AND (is_locked = 0 OR is_locked IS NULL)" : '';
            $rActive = $conn->query("SELECT id, periode, status$lck_sel FROM inventory_stok_opname
                WHERE $loc_cond $lock_cond ORDER BY id DESC LIMIT 1");
            $activeRow = $rActive ? $rActive->fetch_assoc() : null;

            // Kalau tidak ada sesi yang masih terbuka, tampilan default TETAP harus menunjukkan
            // periode TERAKHIR milik lokasi ini apa adanya (meski terkunci) — bukan seolah-olah
            // belum pernah ada SO sama sekali. "Belum pernah dibuka" hanya kalau memang tidak ada
            // baris sama sekali utk lokasi ini.
            $defaultRow = $activeRow;
            if (!$defaultRow) {
                // Semua sesi sudah terkunci — tampilkan periode (bulan) TERBARU, bukan sekadar ID
                // terbesar (ID bisa lebih besar utk periode lama yang sempat dibuka-kunci ulang).
                $rLatest = $conn->query("SELECT id, periode, status$lck_sel FROM inventory_stok_opname
                    WHERE $loc_cond ORDER BY periode DESC, id DESC LIMIT 1");
                $defaultRow = $rLatest ? $rLatest->fetch_assoc() : null;
            }

            // Kalau user eksplisit minta lihat 1 periode historis (?periode=YYYY-MM) dan itu BUKAN periode default
            $view_periode_req = (isset($_GET['periode']) && preg_match('/^\d{4}-\d{2}$/', $_GET['periode']))
                ? $_GET['periode'] : null;
            $opRow = $defaultRow;
            if ($view_periode_req !== null && isset($so_all_periods[$view_periode_req])
                && (!$defaultRow || $defaultRow['periode'] !== $view_periode_req)) {
                $safe_view_per = $conn->real_escape_string($view_periode_req);
                $rView = $conn->query("SELECT id, periode, status$lck_sel FROM inventory_stok_opname
                    WHERE $loc_cond AND periode = '$safe_view_per' ORDER BY id DESC LIMIT 1");
                $viewRow = $rView ? $rView->fetch_assoc() : null;
                if ($viewRow) { $opRow = $viewRow; $is_historical_view = true; }
            }
        } else {
            $opRow = null;
        }

        if ($opRow) {
            $opname_id_today  = (int)$opRow['id'];
            $so_periode       = $opRow['periode'];
            $so_periode_label = date('F Y', strtotime($opRow['periode'] . '-01'));
            $opname_status    = $so_has_locked && (int)($opRow['is_locked'] ?? 0) ? 'locked' : 'open';
            if ($opname_status === 'locked') {
                $opname_locked_at = $opRow['locked_at'] ?? null;
                $lb = (int)($opRow['locked_by'] ?? 0);
                if ($lb) {
                    $rLb = $conn->query("SELECT nama_lengkap FROM inventory_users WHERE id = $lb LIMIT 1");
                    $opname_locked_by_name = $rLb ? ($rLb->fetch_assoc()['nama_lengkap'] ?? '') : '';
                }
            }
        }
        // else: belum ada sesi aktif utk lokasi ini, opname_status = null
    } else {
        // Fallback legacy: cari berdasarkan hari ini (gudang tidak support legacy mode)
        $today_so = date('Y-m-d');
        $rOpToday = $lok_gudang ? null : $conn->query("SELECT id FROM inventory_stok_opname
            WHERE klinik_id = $lok_id AND DATE(tanggal_mulai) = '$today_so'
            ORDER BY id DESC LIMIT 1");
        $opRow = $rOpToday ? $rOpToday->fetch_assoc() : null;
        if ($opRow) { $opname_id_today = (int)$opRow['id']; $opname_status = 'open'; }
    }
    $is_locked = ($opname_status === 'locked');
    $so_no_period = ($opname_status === null);
    // Mode lihat-saja: baik karena periode ini sudah dikunci, ATAUPUN karena user sengaja membuka
    // riwayat periode lain (bukan sesi yang sedang aktif) lewat selector "Lihat Riwayat Periode".
    $is_readonly = ($is_locked || $is_historical_view);

    $hc_detail = []; // user_id → barang_id → {qty, qty_aktual, catatan, stok_sistem}
    if ($opname_id_today > 0 && !empty($hc_users)) {
        $hc_ids = implode(',', array_column($hc_users, 'id'));
        $r_hc_col = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'hc_user_id'");
        $has_hc_detail_col = ($r_hc_col && $r_hc_col->num_rows > 0);
        if ($has_hc_detail_col) {
            $r_hc_cat = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'catatan'");
            $hc_has_cat = ($r_hc_cat && $r_hc_cat->num_rows > 0);
            $hc_cat_sel = $hc_has_cat ? "COALESCE(d.catatan,'') AS catatan," : "'' AS catatan,";
            $r_hc_akt = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'qty_aktual'");
            $hc_has_akt = ($r_hc_akt && $r_hc_akt->num_rows > 0);
            $hc_akt_sel = $hc_has_akt ? "d.qty_aktual," : "NULL AS qty_aktual,";
            $qHS = $conn->query("SELECT d.hc_user_id AS user_id, d.barang_id,
                COALESCE(d.qty_fisik,0) AS qty, $hc_akt_sel COALESCE(d.stok_sistem,0) AS stok_sistem,
                $hc_cat_sel d.status
                FROM inventory_stok_opname_detail d
                WHERE d.opname_id = $opname_id_today AND d.tipe = 'hc' AND d.hc_user_id IN ($hc_ids)");
            while ($qHS && ($r = $qHS->fetch_assoc())) {
                $uid = (int)$r['user_id'];
                $bid = (int)$r['barang_id'];
                $hc_stocks[$uid][$bid] = (float)$r['qty'];
                $hc_detail[$uid][$bid] = [
                    'qty'         => (float)$r['qty'],
                    'qty_aktual'  => $r['qty_aktual'] !== null ? (float)$r['qty_aktual'] : null,
                    'catatan'     => $r['catatan'],
                    'stok_sistem' => (float)$r['stok_sistem'],
                    'status'      => $r['status'] ?? 'pending',
                ];
            }
        }
    }

    // Bag stocks for HC nakes — pre-fill for nakes who haven't reported this period
    // inventory_stok_tas_hc.qty is already in to_uom (validated by admin last period)
    $hc_bag_stocks = []; // user_id → [barang_id → qty_to_uom]
    if (!$lok_gudang && !empty($hc_users)) {
        $r_bag_tbl = $conn->query("SHOW TABLES LIKE 'inventory_stok_tas_hc'");
        if ($r_bag_tbl && $r_bag_tbl->num_rows > 0) {
            $hc_ids_bag = implode(',', array_column($hc_users, 'id'));
            // WAJIB filter klinik_id juga — nakes yang pindah klinik bisa punya baris tas terpisah
            // per klinik (unique key barang_id+user_id+klinik_id), tanpa filter ini stok klinik lama
            // bisa ke-tarik/timpa stok klinik barunya kalau nama barangnya sama.
            $qBag = $conn->query("SELECT user_id, barang_id, qty FROM inventory_stok_tas_hc WHERE user_id IN ($hc_ids_bag) AND klinik_id = $lok_id AND qty > 0");
            while ($qBag && ($r = $qBag->fetch_assoc())) {
                $uid = (int)$r['user_id'];
                $bid = (int)$r['barang_id'];
                $hc_bag_stocks[$uid][$bid] = (float)$r['qty'];
            }
        }
    }

    // Build flat HC export rows (untuk Excel export, tidak bergantung JS state)
    $hc_export_rows = [];
    if (!empty($hc_detail)) {
        $nakes_map_by_id = [];
        foreach ($hc_users as $u) $nakes_map_by_id[(int)$u['id']] = $u['nama'];
        foreach ($hc_detail as $uid => $items) {
            $nakes_nama = $nakes_map_by_id[(int)$uid] ?? ('Nakes #' . $uid);
            foreach ($items as $bid => $d) {
                $it = $items_map[(int)$bid] ?? null;
                if (!$it) continue;
                $odoo_q = $odoo_hc[$it['kode_barang']] ?? null;
                $hc_export_rows[] = [
                    'nakes_nama'  => $nakes_nama,
                    'kode_barang' => $it['kode_barang'],
                    'nama_barang' => $it['nama_barang'],
                    'satuan'      => $it['satuan'] ?? '',
                    'odoo_qty'    => $odoo_q,
                    'nakes_qty'   => $d['qty'],
                    'qty_aktual'  => $d['qty_aktual'],
                    'catatan'     => $d['catatan'],
                ];
            }
        }
    }

    // Last Odoo SO sync — hanya dari key khusus SO, tidak fallback ke global
    $rSync = $conn->query("SELECT v FROM inventory_app_settings WHERE k = 'odoo_so_sync_last_run' LIMIT 1");
    if ($rSync && ($rs = $rSync->fetch_assoc()) && $rs['v'] !== '') {
        $odoo_last_sync = $rs['v'];
    }

    // Build kode_barang → id map
    $kode2id = [];
    foreach ($items_map as $id => $it) $kode2id[$it['kode_barang']] = $id;

    // ED records for this klinik (for SO klinik input popup)
    $ed_records = []; // barang_id → [{id, ed_month, ket}]
    $r_ed_tbl = $conn->query("SHOW TABLES LIKE 'inventory_barang_ed'");
    if ($r_ed_tbl && $r_ed_tbl->num_rows > 0) {
        $today_str = date('Y-m-d');
        $ed_klinik_cond = $lok_gudang ? "klinik_id IS NULL" : "(klinik_id = $lok_id OR klinik_id IS NULL)";
        $qED = $conn->query("SELECT barang_id, id, DATE_FORMAT(ed_month,'%Y-%m-%d') AS ed_month, COALESCE(keterangan,'') AS ket
            FROM inventory_barang_ed
            WHERE $ed_klinik_cond AND ed_month >= '$today_str'
            ORDER BY ed_month ASC");
        while ($qED && ($r = $qED->fetch_assoc())) {
            $ed_records[(int)$r['barang_id']][] = ['id' => (int)$r['id'], 'ed_month' => $r['ed_month'], 'ket' => $r['ket']];
        }
    }

    // Load saved klinik opname detail hari ini — per checker, support multi-user
    // barang_id → { qty_total, ed_lte3m_total, ed_gt3m_total, stok_sistem, checkers:[{user_id,nama,qty,ed_lte3m,ed_gt3m,catatan}] }
    $saved_klinik_rows = [];
    $current_user_id   = (int)($_SESSION['user_id'] ?? 0);
    $current_user_name = $_SESSION['nama_lengkap'] ?? ($_SESSION['nama'] ?? ($_SESSION['username'] ?? 'Saya'));
    if ($opname_id_today) {
        $cols_detail = [];
        $rCols = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail");
        while ($rCols && ($rc = $rCols->fetch_assoc())) $cols_detail[] = $rc['Field'];
        $has_hc_col2  = in_array('hc_user_id', $cols_detail);
        $has_tipe_col = in_array('tipe', $cols_detail);
        $has_ed_col2  = in_array('ed_lte3m', $cols_detail);
        $has_ed_exp_col = in_array('ed_expired', $cols_detail);
        $has_cat_col  = in_array('catatan', $cols_detail);

        $sel_ed  = $has_ed_col2 ? "COALESCE(d.ed_lte3m,0) AS ed_lte3m, COALESCE(d.ed_gt3m,0) AS ed_gt3m," : "0 AS ed_lte3m, 0 AS ed_gt3m,";
        $sel_ed_exp = $has_ed_exp_col ? "COALESCE(d.ed_expired,0) AS ed_expired," : "0 AS ed_expired,";
        $sel_cat = $has_cat_col ? "COALESCE(d.catatan,'') AS catatan," : "'' AS catatan,";
        $sel_uid = $has_hc_col2 ? "d.hc_user_id," : "NULL AS hc_user_id,";

        $where_tipe = $has_tipe_col ? "AND d.tipe = 'klinik'" : "";

        $qSaved = $conn->query("SELECT d.id AS detail_id, d.barang_id, $sel_uid
            COALESCE(d.qty_fisik,0) AS qty_fisik, COALESCE(d.stok_sistem,0) AS stok_sistem,
            $sel_ed_exp $sel_ed $sel_cat
            COALESCE(u.nama_lengkap,'Unknown') AS checker_nama
            FROM inventory_stok_opname_detail d
            LEFT JOIN inventory_users u ON u.id = d.hc_user_id
            WHERE d.opname_id = $opname_id_today $where_tipe
            ORDER BY d.id ASC");
        $detail_id_map = []; // detail_id → [bid, checker_idx]
        while ($qSaved && ($r = $qSaved->fetch_assoc())) {
            $bid = (int)$r['barang_id'];
            $did = (int)$r['detail_id'];
            if (!isset($saved_klinik_rows[$bid])) {
                $saved_klinik_rows[$bid] = [
                    'qty_total'     => 0,
                    'ed_expired'    => 0,
                    'ed_lte3m'      => 0,
                    'ed_gt3m'       => 0,
                    'stok_sistem'   => (float)$r['stok_sistem'],
                    'checkers'      => [],
                ];
            }
            $saved_klinik_rows[$bid]['qty_total']  += (float)$r['qty_fisik'];
            $saved_klinik_rows[$bid]['ed_expired']  += (float)$r['ed_expired'];
            $saved_klinik_rows[$bid]['ed_lte3m']   += (float)$r['ed_lte3m'];
            $saved_klinik_rows[$bid]['ed_gt3m']    += (float)$r['ed_gt3m'];
            $cidx = count($saved_klinik_rows[$bid]['checkers']);
            $saved_klinik_rows[$bid]['checkers'][]  = [
                'detail_id'=> (int)$r['detail_id'],
                'user_id'  => (int)($r['hc_user_id'] ?? 0),
                'nama'     => $r['checker_nama'],
                'qty'      => (float)$r['qty_fisik'],
                'ed_expired' => (float)$r['ed_expired'],
                'ed_lte3m' => (float)$r['ed_lte3m'],
                'ed_gt3m'  => (float)$r['ed_gt3m'],
                'catatan'  => $r['catatan'],
                'ed_detail'=> [],
            ];
            $detail_id_map[(int)$r['detail_id']] = ['bid' => $bid, 'cidx' => $cidx];
        }

        // Load per-ED breakdown from inventory_stok_opname_ed_detail
        if (!empty($detail_id_map)) {
            $r_ed_dtbl = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
            if ($r_ed_dtbl && $r_ed_dtbl->num_rows > 0) {
                $ids_str = implode(',', array_keys($detail_id_map));
                $qEdDet = $conn->query("SELECT opname_detail_id, DATE_FORMAT(ed_month,'%Y-%m-%d') AS ed_month, COALESCE(keterangan,'') AS keterangan, qty, kategori FROM inventory_stok_opname_ed_detail WHERE opname_detail_id IN ($ids_str) ORDER BY ed_month ASC");
                while ($qEdDet && ($er = $qEdDet->fetch_assoc())) {
                    $did = (int)$er['opname_detail_id'];
                    if (!isset($detail_id_map[$did])) continue;
                    $ref = $detail_id_map[$did];
                    $saved_klinik_rows[$ref['bid']]['checkers'][$ref['cidx']]['ed_detail'][] = [
                        'ed_month' => $er['ed_month'],
                        'ket'      => $er['keterangan'],
                        'kategori' => $er['kategori'],
                        'isLte'    => $er['kategori'] === 'lte3m',
                        'qty'      => (float)$er['qty'],
                    ];
                }
            }
        }
    }
}

// Helper: get qty from odoo_klinik by barang_id
function odoo_qty_k(array $odoo_klinik, array $kode2id, int $bid): float {
    foreach ($kode2id as $kode => $id) { if ($id === $bid) return (float)($odoo_klinik[$kode] ?? 0); }
    return 0;
}
function odoo_qty_hc(array $odoo_hc, array $kode2id, int $bid): float {
    foreach ($kode2id as $kode => $id) { if ($id === $bid) return (float)($odoo_hc[$kode] ?? 0); }
    return 0;
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
body,.so-wrap{font-family:'Inter',sans-serif;}

/* ── Klinik picker ────────────────────────────────────────────────────────── */
.klinik-card{background:#fff;border-radius:14px;border:2px solid #e2e8f0;padding:16px 20px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:14px;}
.klinik-card:hover{border-color:#204EAB;background:#f0f4ff;}
.klinik-card .kc-icon{width:42px;height:42px;border-radius:11px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;color:#204EAB;flex-shrink:0;}
.klinik-card .kc-kode{font-family:monospace;font-size:.72rem;background:#f1f5f9;border-radius:5px;padding:1px 8px;color:#475569;}

/* ── Top action bar ───────────────────────────────────────────────────────── */
.so-topbar{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:14px 20px;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.so-topbar .klinik-badge{font-weight:700;color:#204EAB;font-size:.95rem;}
.so-topbar .sync-badge{font-size:.75rem;color:#64748b;background:#f1f5f9;border-radius:8px;padding:4px 10px;}
.so-periode-picker{background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:4px 12px 4px 10px;gap:6px;transition:box-shadow .15s,border-color .15s;}
.so-periode-picker:hover{border-color:#a5b4fc;box-shadow:0 1px 4px rgba(99,102,241,.15);}
.so-periode-picker i{font-size:.72rem;color:#6366f1;}
.so-periode-picker select{border:none;background:transparent;font-size:.78rem;font-weight:600;color:#3730a3;outline:none;cursor:pointer;padding:0;appearance:none;-webkit-appearance:none;}
.so-periode-picker select:focus{outline:none;}

/* ── Section tabs ─────────────────────────────────────────────────────────── */
.so-tabs{display:flex;gap:2px;background:#f1f5f9;border-radius:12px;padding:4px;margin-bottom:1.5rem;}
.so-tab{flex:1;text-align:center;padding:10px 12px;border-radius:9px;font-size:.84rem;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;}
.so-tab.active{background:#fff;color:#204EAB;box-shadow:0 2px 8px rgba(0,0,0,.08);}

/* ── SO Table ─────────────────────────────────────────────────────────────── */
.so-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.06);overflow:hidden;margin-bottom:1rem;}
.so-card-header{background:#204EAB;color:#fff;padding:12px 18px;display:flex;align-items:center;gap:10px;font-weight:600;font-size:.9rem;}
.so-card-header.hc-header{background:#204EAB;}
.so-card-header.combined-header{background:#204EAB;}

table.so-table{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:.84rem;}
table.so-table thead th{background:#f8faff;color:#374151;font-weight:600;padding:8px 12px;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
table.so-table tbody tr.so-main-row > td{padding:10px 12px;border-bottom:1px solid #edf1f7;}
@media(max-width:768px){table.so-table thead{display:none;}}
/* Card-style rows */
.so-card-cell{padding:0 2px !important;border:none !important;background:transparent !important;}
.so-item-card{background:#fff;border-radius:11px;border:1.5px solid #e8edf5;padding:10px 13px;transition:box-shadow .15s;}
.so-item-card:hover{box-shadow:0 2px 10px rgba(32,78,171,.10);}
.so-item-card.so-card-ok{border-left:3px solid #22c55e;background:#f0fdf4;}
.so-item-card.so-card-sel{border-left:3px solid #f97316;background:#fff7ed;}
.so-item-card.so-card-pending{opacity:.72;}
.so-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.so-card-stats{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;}
.so-stat{display:flex;flex-direction:column;align-items:center;background:#f8faff;border:1px solid #e8edf5;border-radius:7px;padding:3px 9px;min-width:48px;}
.so-stat.so-stat-fisik{background:#e8edf8;border-color:#c7d2fe;}
.so-stat-lbl{font-size:.62rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.02em;}
.so-stat-val{font-size:.83rem;font-weight:700;color:#1e293b;}

.qty-cell{font-family:monospace;font-weight:700;text-align:right;font-size:.9rem;}
.so-input{width:80px;border:1.5px solid #e2e8f0;border-radius:7px;text-align:center;font-weight:700;font-size:.88rem;padding:5px 4px;outline:none;transition:border-color .2s;}
.so-input:focus{border-color:#204EAB;box-shadow:0 0 0 2px rgba(32,78,171,.1);}
.so-input.changed{border-color:#10b981;background:#f0fdf4;}
.ed-input{width:70px;border:1.5px solid #fde68a;border-radius:7px;text-align:center;font-size:.82rem;font-weight:600;padding:4px 3px;background:#fffbeb;outline:none;}
.ed-input:focus{border-color:#f59e0b;}
.catatan-input{border:1.5px solid #e2e8f0;border-radius:7px;font-size:.78rem;padding:4px 8px;width:100%;outline:none;resize:none;}
.catatan-input:focus{border-color:#204EAB;}

.badge-selisih-ok{background:#dcfce7;color:#166534;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.badge-selisih-minus{background:#fee2e2;color:#991b1b;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.badge-selisih-plus{background:#fef9c3;color:#92400e;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.badge-pending{background:#f1f5f9;color:#64748b;border-radius:5px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.so-filter-input::placeholder{color:rgba(255,255,255,.75);}

.hc-section{margin-bottom:1rem;}
.hc-section-title{background:#204EAB;color:#fff;border-radius:12px 12px 0 0;padding:10px 16px;font-weight:600;font-size:.88rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;}
.hc-section-body{border:2px solid #dce8fd;border-top:0;border-radius:0 0 12px 12px;overflow:hidden;}

.stat-strip{display:flex;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#f8faff;border-bottom:1px solid #e2e8f0;}
.stat-pill{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:4px 12px;font-size:.78rem;color:#374151;}
.stat-pill b{color:#204EAB;}
/* Scan mode segmented control (compact, inline with title) */
.so-scan-mode{border:none;background:#204EAB;color:#fff;width:44px;border-radius:10px;font-size:.95rem;cursor:pointer;transition:all .18s;box-shadow:0 2px 8px rgba(32,78,171,.3);}
.so-scan-mode:hover{background:#173a82;box-shadow:0 3px 10px rgba(32,78,171,.4);}
.so-scan-mode:active{background:#122c63;}
/* Action buttons: icon-only on mobile */
@media(max-width:768px){
    .so-action-btns .so-btn-txt{display:none;}
    .so-action-btns .btn{padding:6px 9px;}
}
/* E-Catalog label card */
.bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;display:flex;gap:14px;align-items:center;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.bc-card:hover{border-color:#c7d2fe;box-shadow:0 2px 10px rgba(32,78,171,.08);}
.bc-card.bc-has-barcode{border-left:3px solid #204EAB;}
.bc-qr-target{flex-shrink:0;width:100px;height:100px;overflow:hidden;border-radius:6px;}
.bc-qr-target canvas{display:block;width:100px!important;height:100px!important;border-radius:6px;}
.bc-label-code{font-family:monospace;font-weight:700;color:#204EAB;font-size:.98rem;letter-spacing:.04em;}
.bc-label-name{font-size:.88rem;color:#1e293b;margin:3px 0;}
.bc-label-meta{font-size:.75rem;color:#94a3b8;}
.bc-label-meta b{color:#374151;font-weight:600;}
.bc-copy-btn{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:.72rem;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;gap:4px;margin-top:6px;}
.bc-copy-btn:hover{background:#e0e7ff;border-color:#c7d2fe;color:#204EAB;}
#bcCatList{grid-template-columns:repeat(auto-fill,minmax(300px,1fr));}
@media(max-width:500px){#bcCatList{grid-template-columns:1fr;}}
</style>

<?php
// ── SO status per klinik untuk picker (bulk action) ──────────────────────────
// Ambil status dari sesi yang BENAR-BENAR aktif (baris unlocked terakhir), bukan
// baris periode bulan kalender berjalan — supaya periode lama yang belum dikunci
// tetap tampil "Buka" meski sudah ada periode baru yang lebih baru/terkunci.
$so_status_map    = []; // klinik_id → [id, is_locked, status, periode]
$so_gudang_status = null;
if ($can_all && !$lok_data) {
    $rSoSt = $conn->query("SELECT klinik_id, id, is_locked, periode, COALESCE(status,'open') AS status
        FROM inventory_stok_opname WHERE klinik_id IS NOT NULL
        ORDER BY id ASC");
    while ($rSoSt && ($r = $rSoSt->fetch_assoc())) {
        $kid = (int)$r['klinik_id'];
        $row = ['id' => (int)$r['id'], 'is_locked' => (int)$r['is_locked'], 'status' => $r['status'], 'periode' => $r['periode']];
        $cur = $so_status_map[$kid] ?? null;
        if (!$cur) { $so_status_map[$kid] = $row; continue; }
        $curUnlocked = !$cur['is_locked'];
        $rowUnlocked = !$row['is_locked'];
        if ($rowUnlocked && !$curUnlocked) { $so_status_map[$kid] = $row; }
        elseif ($rowUnlocked === $curUnlocked && ($row['periode'] > $cur['periode']
                || ($row['periode'] === $cur['periode'] && $row['id'] > $cur['id']))) { $so_status_map[$kid] = $row; }
    }
    if ($gudang_loc_code !== '') {
        $r_gu_p = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_gudang_utama'");
        if ($r_gu_p && $r_gu_p->num_rows > 0) {
            $rGuAll = $conn->query("SELECT id, is_locked, periode, COALESCE(status,'open') AS status
                FROM inventory_stok_opname WHERE is_gudang_utama = 1 ORDER BY id ASC");
            while ($rGuAll && ($r = $rGuAll->fetch_assoc())) {
                $row = ['id' => (int)$r['id'], 'is_locked' => (int)$r['is_locked'], 'status' => $r['status'], 'periode' => $r['periode']];
                if (!$so_gudang_status) { $so_gudang_status = $row; continue; }
                $curUnlocked = !$so_gudang_status['is_locked'];
                $rowUnlocked = !$row['is_locked'];
                if ($rowUnlocked && !$curUnlocked) { $so_gudang_status = $row; }
                elseif ($rowUnlocked === $curUnlocked && ($row['periode'] > $so_gudang_status['periode']
                        || ($row['periode'] === $so_gudang_status['periode'] && $row['id'] > $so_gudang_status['id']))) { $so_gudang_status = $row; }
            }
        }
    }
}
function so_periode_label_short($periode) {
    return $periode ? date('M Y', strtotime($periode . '-01')) : '';
}
// Count open/locked untuk info bulk
$cnt_open = 0; $cnt_locked = 0; $cnt_none = 0;
foreach ($locations as $l) {
    $kid = (int)$l['id'];
    if (!isset($so_status_map[$kid])) { $cnt_none++; }
    elseif ((int)$so_status_map[$kid]['is_locked']) { $cnt_locked++; }
    else { $cnt_open++; }
}
?>
<?php if (!$lok_data): ?>
<!-- ── Klinik Picker ────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="fw-bold mb-0" style="color:#204EAB;"><i class="fas fa-clipboard-check me-2"></i>Stock Opname</h4>
    <span class="text-muted" style="font-size:.82rem;"><?= date('F Y') ?></span>
</div>

<?php if ($can_all): ?>
<!-- Bulk Action Toolbar -->
<div style="background:#fff;border-radius:14px;border:2px solid #e2e8f0;padding:14px 18px;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <div style="flex:1;min-width:160px;">
        <div style="font-weight:600;font-size:.85rem;color:#374151;">Aksi Bulk — <?= date('F Y') ?></div>
        <div style="font-size:.75rem;color:#64748b;margin-top:2px;">
            <?= count($locations) ?> lokasi klinik<?= $gudang_loc_code !== '' ? ' + Gudang Utama' : '' ?>
            &nbsp;·&nbsp;<span style="color:#16a34a;"><?= $cnt_open ?> buka</span>
            &nbsp;·&nbsp;<span style="color:#dc2626;"><?= $cnt_locked ?> terkunci</span>
            &nbsp;·&nbsp;<span style="color:#94a3b8;"><?= $cnt_none ?> belum dibuka</span>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($role === 'super_admin'): ?>
        <button class="btn btn-sm fw-semibold" id="btnBukaBulk"
            style="background:#204EAB;color:#fff;border:none;border-radius:8px;"
            onclick="bulkSoPeriode('open', this)">
            <i class="fas fa-lock-open me-1"></i>Buka SO Semua
        </button>
        <button class="btn btn-sm fw-semibold" id="btnKunciBulk"
            style="background:#dc2626;color:#fff;border:none;border-radius:8px;"
            onclick="bulkSoPeriode('lock', this)">
            <i class="fas fa-lock me-1"></i>Kunci SO Semua
        </button>
        <button class="btn btn-sm fw-semibold" id="btnResetBulk"
            style="background:#7c3aed;color:#fff;border:none;border-radius:8px;"
            onclick="bulkResetSo(this)">
            <i class="fas fa-trash-restore me-1"></i>Reset SO Semua
        </button>
        <?php endif; ?>
        <button class="btn btn-sm fw-semibold" id="btnExportAll"
            style="background:#16a34a;color:#fff;border:none;border-radius:8px;"
            onclick="exportAllSoExcel(this)">
            <i class="fas fa-file-excel me-1"></i>Export Semua Excel
        </button>
        <button class="btn btn-sm btn-outline-secondary fw-semibold" style="border-radius:8px;" onclick="openBarcodeCatalog()">
            <i class="fas fa-barcode me-1"></i>E-Catalog
        </button>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
<?php if ($can_all && $gudang_loc_code !== ''): ?>
    <div class="col-12">
        <a href="index.php?page=qr_stock_opname&lokasi=gudang_utama" class="text-decoration-none">
            <div class="klinik-card" style="border-color:#e0e8ff;background:#f0f4ff;">
                <div class="kc-icon" style="background:#204EAB;color:#fff;"><i class="fas fa-warehouse"></i></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="color:#204EAB;">Gudang Utama</div>
                    <div class="text-muted" style="font-size:.8rem;">Stok Fisik Gudang Pusat (tanpa HC)</div>
                </div>
                <?php if ($so_gudang_status): ?>
                    <?php if ((int)$so_gudang_status['is_locked']): ?>
                        <span style="background:#fee2e2;color:#991b1b;border-radius:6px;font-size:.72rem;font-weight:700;padding:3px 9px;white-space:nowrap;"><i class="fas fa-lock me-1"></i>Terkunci (<?= htmlspecialchars(so_periode_label_short($so_gudang_status['periode'])) ?>)</span>
                    <?php else: ?>
                        <span style="background:#dcfce7;color:#166534;border-radius:6px;font-size:.72rem;font-weight:700;padding:3px 9px;white-space:nowrap;"><i class="fas fa-lock-open me-1"></i>Buka (<?= htmlspecialchars(so_periode_label_short($so_gudang_status['periode'])) ?>)</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="background:#f1f5f9;color:#94a3b8;border-radius:6px;font-size:.72rem;font-weight:700;padding:3px 9px;">Belum Dibuka</span>
                <?php endif; ?>
                <span class="kc-kode" style="background:#204EAB;color:#fff;"><?= htmlspecialchars($gudang_loc_code) ?></span>
                <i class="fas fa-chevron-right" style="font-size:.75rem;color:#204EAB;"></i>
            </div>
        </a>
    </div>
    <div class="col-12"><hr style="border-color:#e2e8f0;margin:4px 0;"></div>
<?php endif; ?>
<?php foreach ($locations as $l): ?>
    <?php
    $kid_l = (int)$l['id'];
    $so_st = $so_status_map[$kid_l] ?? null;
    ?>
    <div class="col-md-6">
        <a href="index.php?page=qr_stock_opname&lokasi=<?= $kid_l ?>" class="text-decoration-none">
            <div class="klinik-card">
                <div class="kc-icon"><i class="fas fa-hospital"></i></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="color:#1e293b;"><?= htmlspecialchars($l['nama_klinik']) ?></div>
                    <div style="margin-top:3px;">
                        <?php if ($so_st): ?>
                            <?php if ((int)$so_st['is_locked']): ?>
                                <span style="background:#fee2e2;color:#991b1b;border-radius:6px;font-size:.68rem;font-weight:700;padding:2px 8px;white-space:nowrap;"><i class="fas fa-lock me-1"></i>Terkunci (<?= htmlspecialchars(so_periode_label_short($so_st['periode'])) ?>)</span>
                            <?php else: ?>
                                <span style="background:#dcfce7;color:#166534;border-radius:6px;font-size:.68rem;font-weight:700;padding:2px 8px;white-space:nowrap;"><i class="fas fa-lock-open me-1"></i>Buka (<?= htmlspecialchars(so_periode_label_short($so_st['periode'])) ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="background:#f1f5f9;color:#94a3b8;border-radius:6px;font-size:.68rem;font-weight:700;padding:2px 8px;white-space:nowrap;">Belum Dibuka</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="kc-kode"><?= htmlspecialchars($l['kode_klinik']) ?></span>
                <?php if (!empty($l['kode_homecare'])): ?>
                    <span class="kc-kode" style="white-space:nowrap;" title="Kode lokasi HC (Odoo)">HC: <?= htmlspecialchars($l['kode_homecare']) ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-right text-muted" style="font-size:.75rem;"></i>
            </div>
        </a>
    </div>
<?php endforeach; ?>
</div>

<?php if ($can_all): ?>
<!-- Bulk SO Confirm Modal -->
<div id="bulkSoModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px 28px 22px;max-width:400px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
        <div style="font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:8px;" id="bulkSoModalTitle">Konfirmasi</div>
        <div style="font-size:.875rem;color:#475569;margin-bottom:22px;" id="bulkSoModalMsg"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button onclick="bulkSoModalClose(false)" style="border:1.5px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;padding:8px 20px;font-weight:600;font-size:.85rem;cursor:pointer;">Batal</button>
            <button id="bulkSoModalOk" onclick="bulkSoModalClose(true)" style="border:none;border-radius:8px;padding:8px 22px;font-weight:700;font-size:.85rem;color:#fff;cursor:pointer;">Ya, Lanjutkan</button>
        </div>
    </div>
</div>
<script>
const baseUrl   = '<?= base_url() ?>';
const csrfToken = '<?= $csrf ?>';
let _bulkSoResolve = null;

// Periode "bulan berjalan" dihitung dari jam KLIEN saat tombol diklik, bukan dibekukan dari
// tanggal server saat halaman dimuat — supaya aksi bulk/export tidak salah sasaran periode
// kalau admin membuka halaman sebelum tengah malam lalu baru klik tombolnya setelah lewat.
function currentPeriodeYm() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function currentPeriodeLabel() {
    const names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const d = new Date();
    return names[d.getMonth()] + ' ' + d.getFullYear();
}

function bulkSoModalClose(ok) {
    document.getElementById('bulkSoModal').style.display = 'none';
    if (_bulkSoResolve) { _bulkSoResolve(ok); _bulkSoResolve = null; }
}
function bulkSoConfirm(title, msg, color) {
    return new Promise(resolve => {
        _bulkSoResolve = resolve;
        document.getElementById('bulkSoModalTitle').textContent = title;
        document.getElementById('bulkSoModalMsg').textContent   = msg;
        document.getElementById('bulkSoModalOk').style.background = color;
        const modal = document.getElementById('bulkSoModal');
        modal.style.display = 'flex';
        modal.onclick = e => { if (e.target === modal) bulkSoModalClose(false); };
    });
}

async function bulkSoPeriode(action, btn) {
    const periodeLabel = currentPeriodeLabel();
    const labels = {open:'Buka SO Semua', lock:'Kunci SO Semua'};
    const colors = {open:'#204EAB', lock:'#dc2626'};
    const msgs   = {
        open: 'Semua SO periode ' + periodeLabel + ' yang belum dibuka akan dibuat, dan yang terkunci akan dibuka kembali.',
        lock: 'Semua SO periode ' + periodeLabel + ' yang sedang buka akan dikunci.'
    };
    const ok = await bulkSoConfirm(labels[action], msgs[action], colors[action]);
    if (!ok) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Memproses...';
    try {
        const res = await fetch(baseUrl + 'api/bulk_so_periode.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action, periode: currentPeriodeYm(), _csrf: csrfToken})
        });
        const d = await res.json();
        btn.disabled = false;
        btn.innerHTML = origHtml;
        if (d.summary) {
            const errs = (d.results || []).filter(r => r.status === 'error').map(r => `${r.klinik}: ${r.msg}`).join('\n');
            await bulkSoConfirm('Selesai', d.summary + (errs ? '\n\nError:\n' + errs : ''), '#16a34a');
        }
        if ((d.opened ?? 0) + (d.locked ?? 0) + (d.unlocked ?? 0) > 0) location.reload();
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        await bulkSoConfirm('Error', 'Gagal menghubungi server: ' + e.message, '#dc2626');
    }
}

async function bulkResetSo(btn) {
    const periodeLabel = currentPeriodeLabel();
    const periode      = currentPeriodeYm();
    const ok = await bulkSoConfirm(
        'Reset SO Semua — ' + periodeLabel,
        'SEMUA data item SO ' + periodeLabel + ' yang belum terkunci akan dihapus. Header SO tetap ada dan bisa diisi ulang dari nol.\n\nSO yang sudah dikunci tidak akan terpengaruh.',
        '#7c3aed'
    );
    if (!ok) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mereset...';
    try {
        const res = await fetch(baseUrl + 'api/reset_so_periode.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ _csrf: csrfToken, scope: 'all', periode })
        });
        const d = await res.json();
        btn.disabled = false;
        btn.innerHTML = origHtml;
        const errs = (d.results || []).filter(r => r.status === 'error').map(r => `${r.label}: ${r.msg}`).join('\n');
        await bulkSoConfirm('Selesai', d.summary + (errs ? '\n\nError:\n' + errs : ''), d.success ? '#16a34a' : '#dc2626');
        if ((d.reset ?? 0) > 0) location.reload();
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        await bulkSoConfirm('Error', 'Gagal menghubungi server: ' + e.message, '#dc2626');
    }
}

function fmtEd(dateStr) {
    if (!dateStr) return dateStr;
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

async function exportAllSoExcel(btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Memuat...';

    // Lazy-load SheetJS
    if (typeof XLSX === 'undefined') {
        await new Promise((res, rej) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js';
            s.onload = res; s.onerror = rej;
            document.head.appendChild(s);
        });
    }

    try {
        const periode = currentPeriodeYm();
        const res  = await fetch(baseUrl + 'api/export_all_so.php?periode=' + periode + '&_csrf=' + encodeURIComponent(csrfToken));
        const data = await res.json();
        if (!data.success) { alert('Gagal: ' + data.message); btn.disabled=false; btn.innerHTML=origHtml; return; }

        const today = new Date().toISOString().slice(0,10);
        const wb    = XLSX.utils.book_new();

        // Helper: konversi ke base UOM (from_uom). Semua qty di file export SUDAH dalam base UOM.
        const convOf = (mult, fromUom, toUom) => {
            const hasKonv = mult > 0 && mult !== 1 && fromUom && fromUom !== toUom;
            return { hasKonv, konv: hasKonv ? mult : '', toBase: v => (v === '' || v === null || v === undefined) ? '' : (hasKonv ? parseFloat((v * mult).toFixed(4)) : v) };
        };

        // ── 1 sheet per lokasi (klinik/gudang), + 1 sheet HC per klinik yang punya data HC ──
        const itemCols      = ['Kode Barang','Nama Barang','Satuan','Konversi UOM','Expired Tgl','Expired Qty','ED ≤3bln Tgl','ED ≤3bln Qty','ED >3bln Tgl','ED >3bln Qty','Qty Fisik','Catatan'];
        const itemColWidths = [{wch:16},{wch:32},{wch:8},{wch:12},{wch:18},{wch:10},{wch:18},{wch:10},{wch:18},{wch:10},{wch:12},{wch:36}];

        const usedSheetNames = new Set();
        function uniqueSheetName(base) {
            const clean = String(base).replace(/[\\\/\?\*\[\]:]/g, ' ').trim() || 'Sheet';
            let name = clean.substring(0, 31), i = 2;
            while (usedSheetNames.has(name)) { name = (clean.substring(0, 28) + ' ' + i).substring(0, 31); i++; }
            usedSheetNames.add(name);
            return name;
        }
        function appendItemSheet(title, rows) {
            if (!rows.length) return;
            const aoa = [[title], ['Periode: ' + periode + ' | Export: ' + today], [], itemCols, ...rows];
            const ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:11} }, { s:{r:1,c:0}, e:{r:1,c:11} }];
            ws['!cols']   = itemColWidths;
            XLSX.utils.book_append_sheet(wb, ws, uniqueSheetName(title));
        }
        // Sheet HC punya 1 kolom tambahan "Status Validasi" (bandingkan angka yg sudah divalidasi
        // admin vs laporan mentah nakes yg belum dicek — supaya tidak tercampur tanpa penanda).
        const hcCols      = [...itemCols.slice(0, 11), 'Status Validasi', 'Catatan'];
        const hcColWidths = [...itemColWidths.slice(0, 11), {wch:22}, {wch:36}];
        function appendHcSheet(title, rows) {
            if (!rows.length) return;
            const aoa = [[title], ['Periode: ' + periode + ' | Export: ' + today], [], hcCols, ...rows];
            const ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:12} }, { s:{r:1,c:0}, e:{r:1,c:12} }];
            ws['!cols']   = hcColWidths;
            XLSX.utils.book_append_sheet(wb, ws, uniqueSheetName(title));
        }
        function buildItemRows(items) {
            const tglOf = dates => (dates && dates.length) ? dates.map(fmtEd).join(', ') : '';
            return (items || []).map(r => {
                const mult  = parseFloat(r.multiplier || 0);
                const toUom = r.uom || r.satuan || '';
                const c     = convOf(mult, r.from_uom || '', toUom);
                return [r.kode_barang||'', r.nama_barang||'', toUom, c.konv,
                    tglOf(r.ed_expired_dates), c.toBase(r.ed_expired) || '',
                    tglOf(r.ed_lte3m_dates),   c.toBase(r.ed_lte3m)   || '',
                    tglOf(r.ed_gt3m_dates),    c.toBase(r.ed_gt3m)    || '',
                    c.toBase(r.qty_total) ?? '', r.catatan||''];
            });
        }

        // Kode klinik/HC disimpan dengan suffix "/Stock" (mis. "TBS01/Stock") — nama sheet cukup bagian kodenya saja.
        const shortKode = kode => (kode || '').split('/')[0].trim();

        data.kliniks.forEach(kl => {
            // ── Sheet SO per klinik ────────────────────────────────────────────
            const klKode = shortKode(kl.kode) || kl.nama || '';
            appendItemSheet('Klinik ' + klKode, buildItemRows(kl.items));

            // ── Sheet HC per klinik (aggregate semua nakes per item) ────────────
            // qty_aktual (sudah divalidasi admin) in to_uom; nakes_qty (self-report, belum divalidasi) in from_uom
            if (kl.hc && kl.hc.length) {
                const aggMap = new Map();
                kl.hc.forEach(r => {
                    const key = r.kode_barang || r.nama_barang;
                    if (!aggMap.has(key)) {
                        aggMap.set(key, {
                            kode: r.kode_barang||'', nama: r.nama_barang||'',
                            from_uom: r.from_uom||r.satuan||'', uom: r.uom||r.satuan||'',
                            mult: parseFloat(r.multiplier||0),
                            qty_total: 0, has_qty: false, catatan: [],
                            hasValidated: false, hasRaw: false,
                        });
                    }
                    const agg = aggMap.get(key);
                    if (r.qty_aktual !== null && r.qty_aktual !== undefined) {
                        // Sudah divalidasi admin/SPV — qty_aktual dalam to_uom, konversi ke base (from_uom) sekali
                        const akt = parseFloat(r.qty_aktual);
                        agg.qty_total += (agg.mult > 0 && agg.mult !== 1) ? akt * agg.mult : akt;
                        agg.has_qty      = true;
                        agg.hasValidated = true;
                    } else if (r.nakes_qty !== null && r.nakes_qty !== undefined) {
                        // Belum divalidasi — laporan self-report nakes SUDAH dalam base (from_uom), tambahkan langsung
                        agg.qty_total += parseFloat(r.nakes_qty);
                        agg.has_qty  = true;
                        agg.hasRaw   = true;
                    }
                    if (r.catatan) agg.catatan.push(r.nakes_nama + ': ' + r.catatan);
                });
                const hcRows = [];
                aggMap.forEach(agg => {
                    const c   = convOf(agg.mult, agg.from_uom, agg.uom);
                    const qty = agg.has_qty ? parseFloat(agg.qty_total.toFixed(4)) : '';
                    const statusValidasi = !agg.has_qty ? ''
                        : (agg.hasValidated && agg.hasRaw) ? 'Sebagian belum divalidasi'
                        : agg.hasValidated ? 'Tervalidasi'
                        : 'Belum divalidasi (laporan nakes)';
                    hcRows.push([agg.kode, agg.nama, agg.uom, c.konv, '', '', '', '', '', '', qty, statusValidasi, agg.catatan.join(' | ')]);
                });
                const hcKode = shortKode(kl.kode_hc) || klKode;
                appendHcSheet('HC ' + hcKode, hcRows);
            }
        });

        // ── Sheet SO Gudang ──────────────────────────────────────────────────
        appendItemSheet('SO Gudang', buildItemRows(data.gudang?.items));

        if (!wb.SheetNames.length) { alert('Tidak ada data SO untuk diekspor.'); btn.disabled=false; btn.innerHTML=origHtml; return; }
        XLSX.writeFile(wb, 'SO_Semua_Periode-' + periode + '.xlsx');
    } catch(e) {
        alert('Gagal export: ' + e.message);
    }
    btn.disabled = false;
    btn.innerHTML = origHtml;
}
</script>
<?php endif; ?>

<?php else: ?>
<!-- ── SO Dashboard ────────────────────────────────────────────────────────── -->

<!-- Top bar -->
<div class="so-topbar">
    <div>
        <div class="klinik-badge"><i class="fas <?= $lok_gudang ? 'fa-warehouse' : 'fa-hospital' ?> me-2"></i><?= htmlspecialchars($lok_data['nama_klinik']) ?></div>
        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
            <?php $sync_ts = $odoo_last_sync ? strtotime($odoo_last_sync) : 0; if ($sync_ts > 0): ?>
            <div class="sync-badge d-inline-block" id="soSyncBadge"><i class="fas fa-sync-alt me-1"></i>Sync Odoo SO: <?= date('d M Y H:i', $sync_ts) ?></div>
            <?php endif; ?>
            <!-- Periode badge -->
            <span class="sync-badge d-inline-block" style="background:#e0e8ff;color:#204EAB;font-weight:600;">
                <i class="fas fa-calendar-alt me-1"></i>SO <?= htmlspecialchars($so_periode_label) ?>
                <?php if ($is_historical_view): ?>
                <span class="ms-1 fw-semibold" style="color:#6366f1;"><i class="fas fa-history"></i> Riwayat</span>
                <?php elseif ($is_locked): ?>
                <span class="ms-1 text-danger fw-bold"><i class="fas fa-lock"></i> TERKUNCI</span>
                <?php elseif ($opname_status === 'open'): ?>
                <span class="ms-1 text-success fw-semibold"><i class="fas fa-lock-open"></i> Open</span>
                <?php endif; ?>
            </span>
            <?php if (!empty($so_all_periods)):
                $so_loc_param = $lok_gudang ? 'gudang_utama' : $lok_id; ?>
            <div class="d-inline-flex align-items-center so-periode-picker">
                <i class="fas fa-history"></i>
                <select onchange="location.href='?page=qr_stock_opname&lokasi=<?= htmlspecialchars($so_loc_param) ?>'+(this.value?'&periode='+this.value:'')"
                    title="Lihat Riwayat Periode">
                <option value="">Sesi Aktif</option>
                <?php foreach ($so_all_periods as $per => $per_locked): ?>
                <option value="<?= htmlspecialchars($per) ?>" <?= ($is_historical_view && $so_periode === $per) ? 'selected' : '' ?>>
                    <?= htmlspecialchars(date('F Y', strtotime($per . '-01'))) ?><?= $per_locked ? ' 🔒' : '' ?>
                </option>
                <?php endforeach; ?>
                </select>
                <i class="fas fa-chevron-down" style="font-size:.6rem;margin-left:2px;"></i>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap align-items-center so-action-btns">
        <?php // "Buka Kembali" selalu tersedia utk periode yang sedang dilihat kalau dia terkunci —
              // ini satu-satunya cara membawa periode lama kembali jadi sesi aktif, jadi tidak boleh
              // ikut disembunyikan oleh mode "lihat riwayat". ?>
        <?php if ($is_locked && ($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <button class="btn btn-sm fw-semibold" id="btnUnlockSo" title="Buka Kembali" style="border-radius:8px;background:#d97706;color:#fff;border:none;" onclick="lockSoPeriode('unlock')">
            <i class="fas fa-lock-open"></i><span class="so-btn-txt"> Buka Kembali</span>
        </button>
        <?php endif; ?>
        <?php if (!$is_historical_view): ?>
        <?php if ($so_no_period && in_array($_SESSION['role'] ?? '', ['super_admin','admin_gudang'])): ?>
        <button class="btn btn-sm fw-semibold" title="Buka SO <?= htmlspecialchars($so_periode_label) ?>" style="border-radius:8px;background:#204EAB;color:#fff;border:none;" onclick="openSoPeriode()">
            <i class="fas fa-folder-open"></i><span class="so-btn-txt"> Buka SO <?= htmlspecialchars($so_periode_label) ?></span>
        </button>
        <?php endif; ?>
        <?php if ($opname_status === 'open' && ($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <button class="btn btn-sm fw-semibold" id="btnLockSo" title="Kunci SO" style="border-radius:8px;background:#dc2626;color:#fff;border:none;" onclick="lockSoPeriode('lock')">
            <i class="fas fa-lock"></i><span class="so-btn-txt"> Kunci SO</span>
        </button>
        <?php endif; ?>
        <?php if ($opname_status === 'open' && ($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <button class="btn btn-sm fw-semibold" id="btnResetSo" title="Reset SO" style="border-radius:8px;background:#7c3aed;color:#fff;border:none;" onclick="resetSoPeriode()">
            <i class="fas fa-trash-restore"></i><span class="so-btn-txt"> Reset SO</span>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <button class="btn btn-sm fw-semibold" id="btnExportExcel" title="Export Excel" style="border-radius:8px;background:#16a34a;color:#fff;border:none;" onclick="soExportExcel()">
            <i class="fas fa-file-excel"></i><span class="so-btn-txt"> Export Excel</span>
        </button>
        <button class="btn btn-sm btn-outline-secondary fw-semibold" title="E-Catalog Barcode" style="border-radius:8px;" onclick="openBarcodeCatalog()">
            <i class="fas fa-barcode"></i><span class="so-btn-txt"> E-Catalog</span>
        </button>
        <a href="index.php?page=qr_stock_opname" class="btn btn-sm btn-outline-secondary fw-semibold" title="Ganti Klinik" style="border-radius:8px;">
            <i class="fas fa-arrow-left"></i><span class="so-btn-txt"> Ganti Klinik</span>
        </a>
    </div>
</div>

<?php if ($is_historical_view): ?>
<!-- Historical view banner -->
<div class="alert d-flex align-items-center gap-3 mb-3" style="border-radius:12px;border:2px solid #c7d2fe;background:#eef2ff;color:#3730a3;">
    <i class="fas fa-history fa-lg" style="color:#6366f1;"></i>
    <div>
        <strong>Melihat riwayat SO <?= htmlspecialchars($so_periode_label) ?></strong> — mode lihat saja, data tidak dapat diubah/dihapus.
        <br><small><a href="?page=qr_stock_opname&lokasi=<?= htmlspecialchars($lok_gudang ? 'gudang_utama' : $lok_id) ?>">Kembali ke sesi aktif</a></small>
    </div>
</div>
<?php elseif ($is_locked): ?>
<!-- Locked banner -->
<div class="alert alert-danger d-flex align-items-center gap-3 mb-3" style="border-radius:12px;border:2px solid #dc2626;">
    <i class="fas fa-lock fa-lg text-danger"></i>
    <div>
        <strong>SO <?= htmlspecialchars($so_periode_label) ?> Terkunci</strong> — Input data tidak dapat dilakukan.<br>
        <?php if ($opname_locked_at): ?>
        <small class="text-muted">Dikunci <?= date('d M Y H:i', strtotime($opname_locked_at)) ?><?= $opname_locked_by_name ? ' oleh ' . htmlspecialchars($opname_locked_by_name) : '' ?></small>
        <?php endif; ?>
        <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <small class="ms-2"><a href="#" onclick="lockSoPeriode('unlock');return false;">Buka Kembali</a></small>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($so_no_period): ?>
<!-- No period banner -->
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3" style="border-radius:12px;">
    <i class="fas fa-calendar-exclamation fa-lg text-warning"></i>
    <div>
        <strong>SO <?= htmlspecialchars($so_periode_label) ?> belum dibuka.</strong>
        <?php if (in_array($_SESSION['role'] ?? '', ['super_admin','admin_gudang'])): ?>
        Klik <strong>Buka SO</strong> di atas untuk memulai periode ini.
        <?php else: ?>
        Hubungi admin gudang untuk membuka SO bulan ini.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Section Tabs -->
<div class="so-tabs">
    <div class="so-tab" id="tab-btn-klinik" onclick="switchSoTab('klinik',this)">
        <i class="fas fa-<?= $lok_gudang ? 'warehouse' : 'store' ?> me-1"></i><?= $lok_gudang ? 'Stok Gudang' : 'Stok Klinik' ?>
    </div>
    <?php if (!$lok_gudang): ?>
    <div class="so-tab" id="tab-btn-hc" onclick="switchSoTab('hc',this)">
        <i class="fas fa-user-nurse me-1"></i>Stok HC
        <?php if (!empty($hc_users)): ?>
        <span style="background:#204EAB;color:#fff;border-radius:99px;font-size:10px;padding:1px 6px;margin-left:3px;"><?= count($hc_users) ?></span>
        <?php endif; ?>
    </div>
    <div class="so-tab" id="tab-btn-gabungan" onclick="switchSoTab('gabungan',this)">
        <i class="fas fa-layer-group me-1"></i>Gabungan HC
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════ STOK KLINIK ════ -->
<div id="so-tab-klinik">

<!-- Scan & Cari Zone — dua panel terpisah side by side -->
<div class="row g-3 mb-4">
  <!-- Panel Scan -->
  <div class="col-md-6">
    <div class="card h-100" style="border-radius:14px;box-shadow:0 4px 24px rgba(32,78,171,.10);border:2px solid #e0e8ff;">
      <div class="card-body px-4 py-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="fw-semibold" style="font-size:.82rem;color:#204EAB;letter-spacing:.03em;">
            <i class="fas fa-barcode me-1"></i>SCAN BARCODE
          </div>
        </div>
        <!-- Scanner (input manual / keyboard-wedge) -->
        <div id="soKlinikScanner">
          <div class="d-flex align-items-stretch" style="gap:8px;">
            <div class="input-group" style="flex:1;">
              <input type="text" id="soScanInput"
                class="form-control" placeholder="<?= ($is_locked ? 'SO terkunci' : ($is_historical_view ? 'Mode lihat riwayat' : ($so_no_period ? 'SO belum dibuka' : 'Arahkan scanner atau ketik barcode lalu Enter...'))) ?>"
                autocomplete="off"
                <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
                style="border-radius:10px 0 0 10px;border:2px solid #d1d9ef;font-size:.88rem;"
                onkeydown="if(event.key==='Enter'){soProcessScan(this.value);}">
              <button class="btn" onclick="soProcessScan(document.getElementById('soScanInput').value)"
                <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
                style="background:#204EAB;color:#fff;border-radius:0 10px 10px 0;border:2px solid #204EAB;">
                <i class="fas fa-<?= $is_readonly ? 'lock' : 'bolt' ?>"></i>
              </button>
            </div>
            <?php if (!$is_readonly && !$so_no_period): ?>
            <button type="button" class="so-scan-mode" onclick="soScanKamera('klinik')" title="Scan pakai kamera">
              <i class="fas fa-camera"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <div id="soScanStatus" style="font-size:.78rem;color:#94a3b8;text-align:center;padding:6px 0 0;">
          <i class="fas fa-circle-dot" style="font-size:.55rem;"></i> Menunggu scan...
        </div>
      </div>
    </div>
  </div>
  <!-- Panel Cari -->
  <div class="col-md-6">
    <div class="card h-100" style="border-radius:14px;box-shadow:0 4px 24px rgba(32,78,171,.10);border:2px solid #e0e8ff;">
      <div class="card-body px-4 py-3">
        <div class="fw-semibold mb-2" style="font-size:.82rem;color:#204EAB;letter-spacing:.03em;">
          <i class="fas fa-search me-1"></i>CARI ITEM
        </div>
        <div class="position-relative">
          <div class="input-group">
            <span class="input-group-text bg-white" style="border:2px solid #d1d9ef;border-right:none;border-radius:10px 0 0 10px;">
              <i class="fas fa-search text-muted" style="font-size:.8rem;"></i>
            </span>
            <input type="text" id="soSearchInput"
              class="form-control" placeholder="<?= ($is_locked ? 'SO terkunci' : ($is_historical_view ? 'Mode lihat riwayat' : ($so_no_period ? 'SO belum dibuka' : 'Nama atau kode barang...'))) ?>"
              autocomplete="off"
              <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
              style="border:2px solid #d1d9ef;border-left:none;border-radius:0 10px 10px 0;font-size:.88rem;"
              oninput="soFilterSearch(this.value)"
              onkeydown="soSearchKeydown(event)">
          </div>
          <div id="soSearchDrop"
            style="display:none;position:fixed;z-index:9999;background:#fff;border:2px solid #c7d8ff;border-radius:10px;max-height:220px;overflow-y:auto;box-shadow:0 6px 20px rgba(32,78,171,.18);">
          </div>
        </div>
        <div style="font-size:.78rem;color:#94a3b8;text-align:center;padding:5px 0 0;">
          <i class="fas fa-circle-dot" style="font-size:.55rem;"></i> Ketik nama atau kode barang
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Entered items table -->
<div class="so-card">
  <div class="so-card-header" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span><i class="fas fa-clipboard-list me-2"></i>Item yang Sudah Diinput</span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <button id="btnFilterBelumSO" onclick="soTogglePendingFilter()" title="Tampilkan hanya item yang belum di-SO atau qty fisik masih kosong"
        style="border:1.5px solid rgba(255,255,255,.6);border-radius:7px;padding:4px 10px;font-size:.75rem;font-weight:600;background:transparent;color:#fff;cursor:pointer;white-space:nowrap;">
        <i class="fas fa-hourglass-half me-1"></i>Belum Input
      </button>
      <div style="position:relative;">
        <input type="text" id="soKlinikFilter" placeholder="Cari item SO..."
          oninput="soFilterTable(this.value)"
          class="so-filter-input"
          style="border:1.5px solid rgba(255,255,255,.7);border-radius:7px;padding:4px 28px 4px 10px;font-size:.78rem;background:rgba(255,255,255,.25);color:#fff;outline:none;width:160px;"
          onfocus="this.style.background='rgba(255,255,255,.35)'" onblur="this.style.background='rgba(255,255,255,.25)'">
        <i class="fas fa-search" style="position:absolute;right:9px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.6);font-size:.7rem;pointer-events:none;"></i>
      </div>
      <span id="soKlinikCountBadge" style="background:rgba(255,255,255,.25);border-radius:99px;padding:2px 12px;font-size:.78rem;">0 item</span>
    </div>
  </div>
  <div id="soKlinikEmpty" class="text-center text-muted py-5" style="font-size:.88rem;">
    <i class="fas fa-inbox fa-2x mb-3 d-block" style="opacity:.2;"></i>
    Tidak ada item dengan stok Odoo aktif untuk klinik ini.
  </div>
  <div style="overflow-x:auto;display:none;" id="soKlinikTableWrap">
    <table class="so-table" id="tableKlinik">
      <thead>
        <tr>
          <th style="min-width:200px;">Nama Barang</th>
          <th class="text-end">Stok Odoo<?php if ($role !== 'super_admin'): ?> <i class="fas fa-lock" style="font-size:.65rem;color:#94a3b8;" title="Hanya super admin yang dapat melihat stok Odoo"></i><?php endif; ?></th>
          <th class="text-end">Qty Fisik</th>
          <th class="text-center">Expired</th>
          <th class="text-center">ED ≤3bln</th>
          <th class="text-center">ED >3bln</th>
          <?php if ($role === 'super_admin'): ?><th class="text-center">Selisih</th><?php endif; ?>
          <th style="min-width:130px;">Catatan</th>
          <th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody id="soKlinikTbody"></tbody>
    </table>
  </div>
</div>

</div><!-- /so-tab-klinik -->

<!-- ══════════════════════════════════════════════════════════ STOK HC ══════ -->
<div id="so-tab-hc" style="display:none;">
<?php if (empty($hc_users)): ?>
<div class="so-card">
  <div class="so-card-header hc-header"><i class="fas fa-user-nurse me-2"></i>Stok HC per Nakes</div>
  <div class="text-center text-muted py-5">
    <i class="fas fa-user-slash fa-2x mb-3 d-block" style="opacity:.3;"></i>
    Tidak ada petugas HC aktif untuk klinik ini.
  </div>
</div>
<?php else: ?>

<!-- Nakes Selector Pills — single scrollable row -->
<div style="display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;padding-bottom:4px;margin-bottom:12px;scrollbar-width:thin;">
<?php foreach ($hc_users as $hcu):
    $n_items = count($hc_stocks[$hcu['id']] ?? []);
    $n_bag   = count($hc_bag_stocks[$hcu['id']] ?? []);
?>
  <button onclick="hcSelectNakes(<?= $hcu['id'] ?>, <?= htmlspecialchars(json_encode($hcu['nama'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES) ?>)"
    id="hc-pill-<?= $hcu['id'] ?>"
    style="flex-shrink:0;border-radius:20px;border:2px solid #dce8fd;background:#fff;color:#204EAB;padding:5px 14px;font-weight:600;font-size:.82rem;cursor:pointer;transition:all .15s;white-space:nowrap;">
    <i class="fas fa-user-nurse me-1"></i><?= htmlspecialchars($hcu['nama']) ?>
    <span id="hc-pill-badge-<?= $hcu['id'] ?>" style="background:#dce8fd;border-radius:99px;padding:1px 8px;font-size:.7rem;margin-left:6px;font-weight:700;">
      <?php if ($n_items): ?><?= $n_items ?> item<?php elseif ($n_bag): ?><?= $n_bag ?> di tas<?php else: ?>belum lapor<?php endif; ?>
    </span>
  </button>
<?php endforeach; ?>
</div>

<!-- Per-nakes panel (hidden until nakes selected) -->
<div id="hcNakesPanel" style="display:none;">

  <!-- Banner: data from bag stock when nakes hasn't reported this period -->
  <div id="hcBagBanner" style="display:none;background:#fff7ed;border:1.5px solid #fde68a;border-radius:10px;padding:10px 16px;margin-bottom:12px;font-size:.83rem;color:#92400e;align-items:center;gap:10px;">
    <i class="fas fa-box-open" style="font-size:1rem;color:#d97706;flex-shrink:0;"></i>
    <div><b>Nakes belum Input Stok periode ini.</b> Data berikut diambil dari stok tas hasil validasi terakhir sebagai referensi — kolom <b>Stok Nakes</b> menampilkan stok tas, bukan laporan baru.</div>
  </div>

  <!-- Scan & Cari Zone — side by side -->
  <div class="row g-3 mb-3">
    <!-- Panel Scan -->
    <div class="col-md-6">
      <div class="card h-100" style="border-radius:14px;box-shadow:0 4px 24px rgba(32,78,171,.10);border:2px solid #e0e8ff;">
        <div class="card-body px-4 py-3">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-semibold" style="font-size:.82rem;color:#204EAB;letter-spacing:.03em;">
              <i class="fas fa-barcode me-1"></i>SCAN BARCODE
            </div>
          </div>
          <!-- Scanner (input manual / keyboard-wedge) -->
          <div id="soHcScanner">
            <div class="d-flex align-items-stretch" style="gap:8px;">
            <div class="input-group" style="flex:1;">
              <input type="text" id="hcScanInput" class="form-control"
                placeholder="<?= $is_locked ? 'SO terkunci' : ($is_historical_view ? 'Mode lihat riwayat' : ($so_no_period ? 'SO belum dibuka' : 'Arahkan scanner atau ketik barcode lalu Enter...')) ?>"
                autocomplete="off"
                <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
                style="border-radius:10px 0 0 10px;border:2px solid #d1d9ef;font-size:.88rem;"
                onkeydown="if(event.key==='Enter'){hcProcessScan(this.value);}">
              <button class="btn" onclick="hcProcessScan(document.getElementById('hcScanInput').value)"
                <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
                style="background:#204EAB;color:#fff;border-radius:0 10px 10px 0;border:2px solid #204EAB;">
                <i class="fas fa-<?= $is_readonly ? 'lock' : 'bolt' ?>"></i>
              </button>
            </div>
            <?php if (!$is_readonly && !$so_no_period): ?>
            <button type="button" class="so-scan-mode" onclick="soScanKamera('hc')" title="Scan pakai kamera">
              <i class="fas fa-camera"></i>
            </button>
            <?php endif; ?>
            </div>
          </div>
          <div id="hcScanStatus" style="font-size:.78rem;color:#94a3b8;text-align:center;padding:5px 0 0;">
            <i class="fas fa-circle-dot" style="font-size:.55rem;"></i> Menunggu scan...
          </div>
        </div>
      </div>
    </div>
    <!-- Panel Cari -->
    <div id="hcPanelCari" class="col-md-6">
      <div class="card h-100" style="border-radius:14px;box-shadow:0 4px 24px rgba(32,78,171,.10);border:2px solid #e0e8ff;">
        <div class="card-body px-4 py-3">
          <div class="fw-semibold mb-2" style="font-size:.82rem;color:#204EAB;letter-spacing:.03em;">
            <i class="fas fa-search me-1"></i>CARI ITEM
          </div>
          <div class="input-group">
            <span class="input-group-text bg-white" style="border:2px solid #d1d9ef;border-right:none;border-radius:10px 0 0 10px;">
              <i class="fas fa-search text-muted" style="font-size:.8rem;"></i>
            </span>
            <input type="text" id="hcSearchInput" class="form-control"
              placeholder="<?= $is_locked ? 'SO terkunci' : ($is_historical_view ? 'Mode lihat riwayat' : ($so_no_period ? 'SO belum dibuka' : 'Nama atau kode barang...')) ?>"
              autocomplete="off"
              <?= ($is_readonly || $so_no_period) ? 'disabled' : '' ?>
              style="border:2px solid #d1d9ef;border-left:none;border-radius:0 10px 10px 0;font-size:.88rem;"
              oninput="hcFilterSearch(this.value);hcPositionDrop();"
              onkeydown="hcSearchKeydown(event)"
              onfocus="hcPositionDrop()">
          </div>
          <div id="hcSearchDrop"
            style="display:none;position:fixed;z-index:9999;background:#fff;border:2px solid #c7d8ff;border-radius:10px;max-height:220px;overflow-y:auto;box-shadow:0 6px 20px rgba(32,78,171,.18);">
          </div>
          <div style="font-size:.78rem;color:#94a3b8;text-align:center;padding:5px 0 0;">
            <i class="fas fa-circle-dot" style="font-size:.55rem;"></i> Ketik nama atau kode barang
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Items table -->
  <div class="so-card">
    <div class="so-card-header hc-header" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <span id="hcPanelTitle"><i class="fas fa-user-nurse me-2"></i>—</span>
      <div class="d-flex align-items-center gap-2">
        <div style="position:relative;">
          <input type="text" id="hcTableFilter" placeholder="Filter item..."
            oninput="hcFilterTable(this.value)"
            class="so-filter-input"
            style="border:1.5px solid rgba(255,255,255,.7);border-radius:7px;padding:4px 28px 4px 10px;font-size:.78rem;background:rgba(255,255,255,.25);color:#fff;outline:none;width:140px;"
            onfocus="this.style.background='rgba(255,255,255,.35)'" onblur="this.style.background='rgba(255,255,255,.25)'">
          <i class="fas fa-search" style="position:absolute;right:9px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.85);font-size:.7rem;pointer-events:none;"></i>
        </div>
        <span id="hcItemBadge" style="background:rgba(255,255,255,.25);border-radius:99px;padding:2px 12px;font-size:.78rem;">0 item</span>
        <span id="hcAutoSaveStatus" style="font-size:.72rem;color:rgba(255,255,255,.75);display:none;">
          <i class="fas fa-check-circle me-1" style="color:#86efac;"></i>Tersimpan otomatis
        </span>
      </div>
    </div>
    <div id="hcTableEmpty" class="text-center text-muted py-5" style="font-size:.88rem;">
      <i class="fas fa-inbox fa-2x mb-3 d-block" style="opacity:.2;"></i>
      Nakes ini belum melaporkan stok hari ini.
    </div>
    <div style="overflow-x:auto;display:none;" id="hcTableWrap">
      <table class="so-table" id="hcItemTable">
        <thead>
          <tr>
            <th style="min-width:200px;">Nama Barang</th>
            <th class="text-center">Stok Nakes</th>
            <th class="text-center" style="min-width:130px;">Stok Aktual</th>
            <th style="min-width:180px;">Catatan</th>
            <th class="text-center" style="min-width:90px;">Aksi</th>
          </tr>
        </thead>
        <tbody id="hcItemTbody"></tbody>
      </table>
    </div>
  </div>

</div><!-- /hcNakesPanel -->
<?php endif; ?>
</div><!-- /so-tab-hc -->

<!-- ═══════════════════════════════════════════════════════ GABUNGAN HC ═════ -->
<div id="so-tab-gabungan" style="display:none;">
<?php
// Sum all HC stok per barang_id
$hc_combined = [];
foreach ($hc_stocks as $uid => $stok) {
    foreach ($stok as $bid => $qty) {
        $hc_combined[$bid] = ($hc_combined[$bid] ?? 0) + $qty;
    }
}
// Merge with odoo_hc
$gabungan_rows = [];
foreach ($items_map as $bid => $it) {
    $kode      = $it['kode_barang'];
    $qty_hc_raw = $hc_combined[$bid] ?? null;
    $qty_odoo  = $odoo_hc[$kode] ?? null;
    if ($qty_hc_raw === null && $qty_odoo === null) continue;
    // HC nakes enters qty in from_uom (satuan); odoo_hc is already / multiplier (to_uom)
    // Convert qty_hc to to_uom for correct comparison
    $mult_g  = (float)($it['multiplier'] ?? 0);
    $qty_hc  = ($qty_hc_raw !== null && $mult_g > 1) ? ((float)$qty_hc_raw / $mult_g) : (float)($qty_hc_raw ?? 0);
    $gabungan_rows[$bid] = ['item' => $it, 'qty_hc' => $qty_hc, 'qty_odoo' => (float)($qty_odoo ?? 0)];
}
?>
<div class="so-card">
    <div class="so-card-header combined-header">
        <i class="fas fa-layer-group"></i>
        Gabungan Stok HC &mdash; Total semua nakes vs Odoo HC (<?= htmlspecialchars($lok_data['kode_homecare'] ?: '-') ?>)
    </div>
    <?php if (empty($gabungan_rows) && empty($lok_data['kode_homecare'])): ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
        Kode HC belum diset untuk klinik ini. Atur di menu <b>Data Klinik</b>.
    </div>
    <?php elseif (empty($gabungan_rows)): ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-inbox me-2"></i>Belum ada data. Pastikan HC sudah input stok dan Odoo sudah di-sync.
    </div>
    <?php else: ?>
    <div class="stat-strip" style="gap:8px;align-items:center;">
        <div class="stat-pill">Total item: <b><?= count($gabungan_rows) ?></b></div>
        <div class="stat-pill">Total qty HC: <b><?= array_sum(array_column($gabungan_rows,'qty_hc')) ?></b></div>
        <div class="stat-pill">Total Odoo HC: <b><?= array_sum(array_column($gabungan_rows,'qty_odoo')) ?></b></div>
        <div style="margin-left:auto;position:relative;">
            <input type="text" id="searchGabungan" placeholder="Cari item..."
                oninput="filterTable('tableGabungan', this.value)"
                style="border:1.5px solid #a5f3fc;border-radius:8px;padding:5px 32px 5px 10px;font-size:.82rem;outline:none;width:180px;transition:border-color .2s;"
                onfocus="this.style.borderColor='#0891b2'" onblur="this.style.borderColor='#a5f3fc'">
            <i class="fas fa-search" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.75rem;pointer-events:none;"></i>
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="so-table" id="tableGabungan">
        <thead>
            <tr>
                <th style="min-width:180px;">Nama Barang</th>
                <th class="text-end">Total Stok HC</th>
                <th class="text-end">Stok Odoo HC<?php if ($role !== 'super_admin'): ?> <i class="fas fa-lock" style="font-size:.65rem;color:#94a3b8;"></i><?php endif; ?></th>
                <th class="text-center">Selisih</th>
                <th class="text-center">% Selisih</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($gabungan_rows as $bid => $row):
            $selisih  = $row['qty_hc'] - $row['qty_odoo'];
            $pct      = $row['qty_odoo'] != 0 ? round(abs($selisih) / $row['qty_odoo'] * 100, 1) : null;
            $cls      = $selisih == 0 ? 'row-ok' : 'row-selisih';
        ?>
        <tr class="<?= $cls ?>">
            <td>
                <div class="fw-semibold" style="font-size:.85rem;"><?= htmlspecialchars($row['item']['nama_barang']) ?></div>
                <div style="font-size:.72rem;color:#94a3b8;font-family:monospace;"><?= htmlspecialchars($row['item']['kode_barang']) ?></div>
            </td>
            <td class="qty-cell"><?= number_format($row['qty_hc'],0,',','.') ?></td>
            <td class="qty-cell"><?= ($role === 'super_admin') ? ($row['qty_odoo'] ? number_format($row['qty_odoo'],0,',','.') : '<span style="color:#aaa">—</span>') : '<span style="color:#94a3b8;font-style:italic;">*</span>' ?></td>
            <td class="text-center">
                <?php if ($role !== 'super_admin'): ?>
                <span style="color:#94a3b8;font-style:italic;">*</span>
                <?php elseif ($selisih == 0): ?>
                <span class="badge-selisih-ok">Sesuai</span>
                <?php elseif ($selisih < 0): ?>
                <span class="badge-selisih-minus"><?= number_format($selisih,0,',','.') ?></span>
                <?php else: ?>
                <span class="badge-selisih-plus">+<?= number_format($selisih,0,',','.') ?></span>
                <?php endif; ?>
            </td>
            <td class="text-center text-muted" style="font-size:.8rem;">
                <?= ($role === 'super_admin' && $pct !== null) ? $pct . '%' : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div><!-- /so-tab-gabungan -->

<!-- Camera Scanner Overlay -->
<div id="soQrModal" style="display:none;position:fixed;inset:0;z-index:9998;background:#000;flex-direction:column;align-items:center;justify-content:space-between;padding:0;">
  <!-- Header -->
  <div style="width:100%;padding:18px 20px 12px;background:linear-gradient(180deg,rgba(0,0,0,.7) 0%,transparent 100%);text-align:center;">
    <div style="color:#fff;font-size:1rem;font-weight:700;letter-spacing:.03em;">
      <i class="fas fa-barcode me-2" style="color:#60a5fa;"></i>Scan Barcode
    </div>
    <div style="color:rgba(255,255,255,.55);font-size:.75rem;margin-top:3px;">
      Arahkan kamera ke barcode atau QR code
    </div>
  </div>
  <!-- Camera preview -->
  <div style="flex:1;display:flex;align-items:center;justify-content:center;width:100%;padding:8px 20px;">
    <div id="soQrReader" style="width:min(92vw,380px);border-radius:16px;overflow:hidden;background:#111;box-shadow:0 0 0 3px rgba(96,165,250,.4);"></div>
  </div>
  <!-- Footer -->
  <div style="width:100%;padding:16px 20px 32px;background:linear-gradient(0deg,rgba(0,0,0,.75) 0%,transparent 100%);text-align:center;">
    <div style="color:rgba(255,255,255,.45);font-size:.72rem;margin-bottom:14px;">
      <i class="fas fa-lightbulb me-1" style="color:#fbbf24;"></i>Pastikan barcode terlihat jelas &amp; pencahayaan cukup
    </div>
    <button onclick="soStopCamera()"
        style="padding:13px 52px;background:#fff;color:#1e293b;border:none;border-radius:14px;font-size:.93rem;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.3);">
      <i class="fas fa-times me-2"></i>Tutup Kamera
    </button>
  </div>
</div>

<!-- Modal: Input Stok Item Klinik -->
<div class="modal fade" id="modalSoKlinik" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <div class="modal-content" style="border-radius:16px;border:0;">
      <div class="modal-header" style="background:#204EAB;color:#fff;border-radius:16px 16px 0 0;padding:14px 20px;">
        <div style="flex:1;min-width:0;">
          <div class="fw-bold" id="soModalTitle" style="font-size:1rem;line-height:1.3;word-break:break-word;"></div>
          <div id="soModalKode" style="font-size:.75rem;opacity:.8;font-family:monospace;margin-top:2px;"></div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <!-- Odoo reference -->
        <div id="soModalOdooRef" style="background:#f0f4ff;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;"></div>
        <!-- ED Section -->
        <div id="soModalEdSection">
          <div class="fw-semibold mb-2" style="font-size:.85rem;color:#374151;">
            <i class="fas fa-calendar-alt me-1" style="color:#f59e0b;"></i>Tambah ED:
          </div>
          <!-- Manual ED input -->
          <div class="d-flex align-items-center gap-2 mb-3" style="background:#f8faff;border-radius:8px;padding:7px 10px;border:1px dashed #c7d8ff;">
            <i class="fas fa-plus-circle" style="color:#6366f1;font-size:.8rem;flex-shrink:0;"></i>
            <input type="date" id="soManualEdMonth"
              class="form-control form-control-sm" style="width:155px;border-radius:6px;font-size:.82rem;">
            <input type="text" id="soManualEdKet"
              class="form-control form-control-sm flex-fill" style="border-radius:6px;font-size:.82rem;"
              placeholder="Keterangan (opsional)">
            <button type="button" onclick="soAddManualEd()" class="btn btn-sm fw-semibold"
              style="background:#6366f1;color:#fff;border:none;border-radius:6px;white-space:nowrap;font-size:.78rem;padding:4px 10px;">
              + Tambah
            </button>
          </div>
          <div id="soModalEdRows" class="d-flex flex-column gap-2 mb-2"></div>
          <div id="soModalEdTotals" style="display:none;background:#f8faff;border-radius:8px;padding:8px 12px;font-size:.82rem;border:1px solid #e2e8f0;margin-bottom:12px;">
            <span style="color:#dc2626;">Expired: <b id="soTotalExpired">0</b></span> &nbsp;|&nbsp;
            <span style="color:#064e3b;">ED ≤3bln: <b id="soTotalLte">0</b></span> &nbsp;|&nbsp;
            <span style="color:#1e40af;">ED >3bln: <b id="soTotalGt">0</b></span> &nbsp;|&nbsp;
            <span>Total: <b id="soTotalAll">0</b></span>
          </div>
        </div>
        <!-- Qty Fisik -->
        <div class="d-flex align-items-center gap-3 mb-3">
          <label class="fw-semibold" style="font-size:.85rem;color:#374151;white-space:nowrap;">Qty Fisik:</label>
          <input type="number" id="soModalQtyFisik" min="0" step="1"
            class="form-control text-center fw-bold"
            style="width:110px;border-radius:8px;font-size:1rem;border:2px solid #c7d8ff;">
          <span id="soModalUom" class="text-muted" style="font-size:.85rem;white-space:nowrap;"></span>
        </div>
        <!-- Catatan -->
        <div>
          <label class="fw-semibold" style="font-size:.85rem;color:#374151;">Catatan:</label>
          <textarea id="soModalCatatan" rows="2" class="form-control mt-1"
            placeholder="Catatan (opsional)..."
            style="border-radius:8px;font-size:.84rem;resize:none;"></textarea>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2">
        <button class="btn btn-outline-secondary fw-semibold flex-fill" data-bs-dismiss="modal" style="border-radius:10px;">Batal</button>
        <button class="btn fw-bold flex-fill text-white" onclick="soConfirmItem()"
          style="background:#204EAB;border:none;border-radius:10px;">
          <i class="fas fa-check me-2"></i>Simpan Item
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Input Stok Aktual HC -->
<div class="modal fade" id="modalHcInput" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
    <div class="modal-content" style="border-radius:16px;border:0;">
      <div class="modal-header" style="background:#204EAB;color:#fff;border-radius:16px 16px 0 0;padding:14px 20px;">
        <div style="flex:1;min-width:0;">
          <div class="fw-bold" id="hcModalTitle" style="font-size:1rem;line-height:1.3;word-break:break-word;"></div>
          <div id="hcModalKode" style="font-size:.75rem;opacity:.8;font-family:monospace;margin-top:2px;"></div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <!-- Info: Nakes + Odoo + Konversi -->
        <div style="background:#f0f4ff;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;color:#374151;display:flex;flex-direction:column;gap:5px;">
          <div><span id="hcModalNakesInfo"></span></div>
          <div id="hcModalOdooInfo" style="display:none;"></div>
          <div id="hcModalKonvInfo" style="display:none;font-size:.78rem;color:#6366f1;background:#e0e7ff;border-radius:6px;padding:3px 9px;align-self:flex-start;">
            <i class="fas fa-exchange-alt me-1"></i><span id="hcModalKonvText"></span>
          </div>
        </div>
        <!-- Qty Fisik input -->
        <div class="d-flex align-items-center gap-2 mb-3">
          <label class="fw-semibold" style="font-size:.85rem;color:#374151;white-space:nowrap;">Qty Fisik:</label>
          <input type="number" id="hcModalActual" min="0" step="1"
            class="form-control text-center fw-bold"
            style="width:100px;border-radius:8px;font-size:1rem;border:2px solid #c7d8ff;"
            onkeydown="if(event.key==='Enter'){hcModalSave();}">
          <span id="hcModalUom" class="text-muted" style="font-size:.85rem;white-space:nowrap;"></span>
        </div>
        <!-- Catatan -->
        <div>
          <label class="fw-semibold" style="font-size:.85rem;color:#374151;">Catatan:</label>
          <textarea id="hcModalCatatan" rows="2" class="form-control mt-1"
            placeholder="Catatan (opsional)..."
            style="border-radius:8px;font-size:.84rem;resize:none;"></textarea>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2">
        <button class="btn btn-outline-secondary fw-semibold flex-fill" data-bs-dismiss="modal" style="border-radius:10px;">Batal</button>
        <button class="btn fw-bold flex-fill text-white" onclick="hcModalSave()"
          style="background:#204EAB;border:none;border-radius:10px;">
          <i class="fas fa-check me-2"></i>Simpan
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const csrfToken  = '<?= $csrf ?>';
const baseUrl    = '<?= base_url() ?>';
const lokId      = <?= $lok_id ?>;
const isGudang   = <?= $lok_gudang ? 'true' : 'false' ?>;
const soIsLocked    = <?= $is_readonly ? 'true' : 'false' ?>; // true kalau dikunci ATAU sedang lihat riwayat periode lain
const soIsHistorical = <?= $is_historical_view ? 'true' : 'false' ?>;
const soNoPeriod    = <?= $so_no_period ? 'true' : 'false' ?>;
const soPeriode     = '<?= $so_periode ?>';
const soPeriodeLabel = '<?= addslashes($so_periode_label) ?>';
const isSuperAdmin  = <?= ($role === 'super_admin') ? 'true' : 'false' ?>;

// ── Custom modal helpers (menggantikan alert/confirm native) ──────────────────
function _soModal(title, msg, okLabel, okColor, showCancel) {
    return new Promise(resolve => {
        const el = document.createElement('div');
        el.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;';
        el.innerHTML = `<div style="background:#fff;border-radius:16px;padding:26px 26px 20px;max-width:400px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
            <div style="font-weight:700;font-size:.95rem;color:#1e293b;margin-bottom:8px;">${title}</div>
            <div style="font-size:.85rem;color:#475569;margin-bottom:22px;white-space:pre-line;">${msg}</div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                ${showCancel ? '<button data-action="cancel" style="border:1.5px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;padding:7px 18px;font-weight:600;font-size:.85rem;cursor:pointer;">Batal</button>' : ''}
                <button data-action="ok" style="border:none;background:${okColor};color:#fff;border-radius:8px;padding:7px 20px;font-weight:700;font-size:.85rem;cursor:pointer;">${okLabel}</button>
            </div></div>`;
        document.body.appendChild(el);
        const close = ok => { document.body.removeChild(el); resolve(ok); };
        el.querySelector('[data-action="ok"]').onclick = () => close(true);
        const c = el.querySelector('[data-action="cancel"]');
        if (c) c.onclick = () => close(false);
        el.onclick = e => { if (e.target === el) close(false); };
    });
}
const soAlert   = (msg, title='Info')               => _soModal(title, msg, 'OK', '#204EAB', false);
const soConfirm = (msg, title='Konfirmasi', okColor='#204EAB') => _soModal(title, msg, 'Ya, Lanjutkan', okColor, true);

// ── Utilities ─────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function fmtEd(dateStr) {
    if (!dateStr) return dateStr;
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function filterTable(tableId, query) {
    const tbl = document.getElementById(tableId);
    if (!tbl) return;
    const q = query.trim().toLowerCase();
    tbl.querySelectorAll('tbody tr').forEach(tr => {
        const cell = tr.querySelector('td:first-child');
        if (!cell) return;
        tr.style.display = (!q || cell.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
}

function switchSoTab(tab, btn) {
    document.querySelectorAll('.so-tab').forEach(b => b.classList.remove('active'));
    ['klinik','hc','gabungan'].forEach(t => {
        const el = document.getElementById('so-tab-' + t);
        if (el) el.style.display = t === tab ? '' : 'none';
    });
    const activeBtn = btn || document.getElementById('tab-btn-' + tab);
    if (activeBtn) activeBtn.classList.add('active');
    // Persist tab in URL without reload
    const url = new URL(location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url.toString());
    // Focus the correct scan input so barcode scanners hit the right handler
    setTimeout(() => {
        if (tab === 'klinik') {
            const el = document.getElementById('soScanInput');
            if (el) el.focus();
        } else if (tab === 'hc') {
            const el = document.getElementById('hcScanInput');
            if (el) el.focus();
        }
    }, 50);
}

// Init: restore tab from URL param
(function initTab() {
    const p = new URLSearchParams(location.search).get('tab') || 'klinik';
    switchSoTab(p);
})();

// ── SO Klinik: PHP data ───────────────────────────────────────────────────────
const soAllItems    = <?= json_encode(array_values($items_map), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const soOdooMap     = <?= json_encode($odoo_klinik, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// barang_id (string) → qty lokal (stok gudang klinik / gudang utama)
const soLocalStockMap = <?= json_encode(array_map('floatval', $klinik_stock ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const soSavedRows   = <?= json_encode($saved_klinik_rows ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const soCurrentUserId   = <?= (int)($current_user_id ?? 0) ?>;
const soCurrentUserName = <?= json_encode($current_user_name ?? 'Saya', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// Barcode lookup: any barcode variant → item
const soBarcodeMap = {};
soAllItems.forEach(it => {
    if (it.kode_barang)       soBarcodeMap[it.kode_barang.toLowerCase()]       = it;
    if (it.barcode)           soBarcodeMap[it.barcode.toLowerCase()]           = it;
    if (it.barcode_internal)  soBarcodeMap[it.barcode_internal.toLowerCase()]  = it;
    (it.vendor_barcodes || []).forEach(vb => { if (vb) soBarcodeMap[vb.toLowerCase()] = it; });
});

// 3-month cutoff from today
const so3mCutoff = new Date();
so3mCutoff.setMonth(so3mCutoff.getMonth() + 3);
const soTodayStart = new Date();
soTodayStart.setHours(0, 0, 0, 0);

// Kategori ED: 'expired' (sudah lewat hari ini), 'lte3m' (≤ 3 bulan lagi), 'gt3m' (> 3 bulan lagi)
function soEdKategori(edMonth) {
    const d = new Date(edMonth + 'T00:00:00');
    if (d < soTodayStart) return 'expired';
    if (d <= so3mCutoff) return 'lte3m';
    return 'gt3m';
}
const SO_ED_CAT_META = {
    expired: { label: '<span style="color:#dc2626;font-size:.7rem;font-weight:700;">Expired</span>', bg: '#fef2f2', border: '#fecaca' },
    lte3m:   { label: '<span style="color:#b45309;font-size:.7rem;font-weight:700;">≤ 3 bulan</span>', bg: '#fff7ed', border: '#fed7aa' },
    gt3m:    { label: '<span style="color:#1d4ed8;font-size:.7rem;font-weight:700;">> 3 bulan</span>', bg: '#f0fdf4', border: '#bbf7d0' },
};
const SO_ED_TOTAL_KEY = { expired: 'ed_expired', lte3m: 'ed_lte3m', gt3m: 'ed_gt3m' };

// Runtime state
const soKlinikEntries = {}; // barang_id → entry object
let soCurrentItem  = null;
let soModalBsInst  = null;
let soCurrentEditDetailId = null; // null = riwayat scan baru; >0 = sedang edit riwayat spesifik ini
let soModalEditMode = false; // true jika modal dibuka dari "Edit riwayat" — jangan lompat balik ke scan input
let soExpandedHistoryId = null; // barang_id yang panel riwayatnya sedang terbuka, supaya tetap terbuka setelah re-render

// Restore saved entries from today's opname (if any)
(function restoreSavedKlinik() {
    const itemById = {};
    soAllItems.forEach(it => { itemById[it.id] = it; });
    Object.entries(soSavedRows).forEach(([bid, row]) => {
        const item = itemById[parseInt(bid)];
        if (!item) return;
        // Live Odoo qty — fallback to stok_sistem saved in DB when not in mirror
        const liveQ  = soOdooMap[item.kode_barang];
        const odooQ  = liveQ !== undefined ? Number(liveQ) : (row.stok_sistem !== undefined ? Number(row.stok_sistem) : null);
        // Find my own checker entry for modal pre-fill
        const myChecker = (row.checkers || []).find(c => c.user_id === soCurrentUserId);
        soKlinikEntries[parseInt(bid)] = {
            item,
            qty_total:    row.qty_total,
            ed_expired:   row.ed_expired || 0,
            ed_lte3m:     row.ed_lte3m,
            ed_gt3m:      row.ed_gt3m,
            checkers:     row.checkers || [],
            stok_sistem:  Number(row.stok_sistem ?? 0),
            // my own inputs (for re-editing in modal)
            my_qty:       myChecker ? myChecker.qty      : null,
            my_ed_expired: myChecker ? (myChecker.ed_expired || 0) : 0,
            my_ed_lte3m:  myChecker ? myChecker.ed_lte3m : 0,
            my_ed_gt3m:   myChecker ? myChecker.ed_gt3m  : 0,
            my_catatan:   myChecker ? myChecker.catatan  : '',
            my_ed_detail: myChecker ? (myChecker.ed_detail || []) : [],
            odoo_qty:     odooQ,
            _insertedAt:  0, // restored entries go below newly scanned
        };
    });
    soRenderKlinikTable();
})();

// ── Panel Switch (legacy stub, panels now always visible side by side) ─────────
function soSwitchPanel(panel) {
    if (panel === 'scan') document.getElementById('soScanInput').focus();
    else                  document.getElementById('soSearchInput').focus();
}

// ── Scan ──────────────────────────────────────────────────────────────────────
function soProcessScan(val) {
    if (soIsLocked || soNoPeriod) {
        const msg = soIsHistorical ? `Sedang melihat riwayat SO ${soPeriodeLabel} (mode lihat saja). Input tidak dapat dilakukan.`
            : soIsLocked ? `SO ${soPeriodeLabel} terkunci. Input tidak dapat dilakukan.` : `SO ${soPeriodeLabel} belum dibuka.`;
        soAlert(msg, 'SO Tidak Aktif'); return;
    }
    val = (val || '').trim();
    const statusEl = document.getElementById('soScanStatus');
    if (!val) return;
    const found = soBarcodeMap[val.toLowerCase()];
    if (found) {
        statusEl.innerHTML = '<span style="color:#059669;"><i class="fas fa-check-circle me-1"></i>' + escHtml(found.nama_barang) + '</span>';
        document.getElementById('soScanInput').value = '';
        openSoModal(found);
    } else {
        statusEl.innerHTML = '<span style="color:#dc2626;"><i class="fas fa-times-circle me-1"></i>Barcode tidak dikenali: ' + escHtml(val) + '</span>';
    }
}

// ── Search ────────────────────────────────────────────────────────────────────
function soPositionDrop() {
    const inp  = document.getElementById('soSearchInput');
    const drop = document.getElementById('soSearchDrop');
    if (!inp || !drop) return;
    const r = inp.getBoundingClientRect();
    drop.style.top   = (r.bottom + 4) + 'px';
    drop.style.left  = r.left + 'px';
    drop.style.width = r.width + 'px';
}
function soFilterSearch(q) {
    const drop = document.getElementById('soSearchDrop');
    q = q.trim().toLowerCase();
    if (!q) { drop.style.display = 'none'; return; }
    soPositionDrop();
    const results = soAllItems.filter(it =>
        it.nama_barang.toLowerCase().includes(q) || (it.kode_barang || '').toLowerCase().includes(q)
    ).slice(0, 30);
    if (!results.length) {
        drop.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:.85rem;">Item tidak ditemukan</div>';
    } else {
        drop.innerHTML = results.map(it =>
            `<div class="so-search-opt"
                onclick="event.stopPropagation();soSelectItem(${it.id})"
                onmouseenter="this.style.background='#f0f4ff'"
                onmouseleave="this.style.background=''"
                style="padding:10px 14px;font-size:.84rem;cursor:pointer;border-bottom:1px solid #f0f4ff;display:flex;justify-content:space-between;align-items:center;">
                <span>${escHtml(it.nama_barang)}</span>
                <span style="font-size:.72rem;color:#94a3b8;font-family:monospace;">${escHtml(it.kode_barang || '')}</span>
            </div>`
        ).join('');
    }
    drop.style.display = '';
}

function soSearchKeydown(e) {
    if (e.key === 'Escape') { document.getElementById('soSearchDrop').style.display = 'none'; return; }
    if (e.key === 'Enter') {
        const q = e.target.value.trim().toLowerCase();
        const found = soAllItems.find(it => it.nama_barang.toLowerCase() === q || (it.kode_barang || '').toLowerCase() === q)
                   || soAllItems.find(it => it.nama_barang.toLowerCase().includes(q) || (it.kode_barang || '').toLowerCase().includes(q));
        if (found) soSelectItem(found.id);
    }
}

function soSelectItem(id) {
    document.getElementById('soSearchDrop').style.display = 'none';
    document.getElementById('soSearchInput').value = '';
    const item = soAllItems.find(it => it.id == id);
    if (item) openSoModal(item);
}

document.addEventListener('click', e => {
    if (!e.target.closest('#soPanelCari')) {
        const drop = document.getElementById('soSearchDrop');
        if (drop) drop.style.display = 'none';
    }
});

// ── Modal ─────────────────────────────────────────────────────────────────────
// editEntry = null  → riwayat scan BARU (form selalu kosong)
// editEntry = {...} → edit 1 riwayat spesifik (prefill persis dari riwayat itu)
function openSoModal(item, editEntry = null) {
    // Only open klinik modal when klinik tab is active
    if (document.getElementById('so-tab-klinik')?.style.display === 'none') return;
    if (soIsLocked || soNoPeriod) return;
    soCurrentItem = item;
    soCurrentEditDetailId = editEntry ? editEntry.detail_id : null;
    soModalEditMode = !!editEntry;
    // Always reset save button state in case previous save left it disabled
    const _saveBtn = document.querySelector('#modalSoKlinik .modal-footer .btn:not(.btn-outline-secondary)');
    if (_saveBtn) { _saveBtn.disabled = false; _saveBtn.innerHTML = '<i class="fas fa-check me-2"></i>Simpan Item'; }
    const id     = item.id;
    const kode   = item.kode_barang || '';
    const odooQ  = soOdooMap[kode] ?? null;
    const existing = soKlinikEntries[id];

    document.getElementById('soModalTitle').textContent = item.nama_barang;
    document.getElementById('soModalKode').textContent  = kode;
    document.getElementById('soModalUom').textContent   = item.uom || item.satuan || '';
    const odooRefEl = document.getElementById('soModalOdooRef');
    if (isSuperAdmin) {
        odooRefEl.style.display = '';
        odooRefEl.innerHTML = `<i class="fas fa-cloud me-1" style="color:#204EAB;"></i>Stok Odoo saat ini: <b>${odooQ !== null ? odooQ : '—'}</b> ${escHtml(item.uom || item.satuan || '')}`;
    } else {
        odooRefEl.style.display = 'none';
    }

    // Clear ED rows and totals
    document.getElementById('soModalEdRows').innerHTML = '';
    document.getElementById('soModalEdTotals').style.display = 'none';

    // Always show ED section with manual input option
    document.getElementById('soModalEdSection').style.display = '';

    // Info panel: ringkasan riwayat lain yang sudah ada untuk item ini (per checker, dijumlahkan)
    let otherInfoHtml = '';
    if (existing && existing.checkers && existing.checkers.length > 0) {
        const relevant = editEntry ? existing.checkers.filter(c => c.detail_id !== editEntry.detail_id) : existing.checkers;
        const byUser = {};
        relevant.forEach(c => {
            if (!byUser[c.user_id]) byUser[c.user_id] = { nama: c.nama, qty: 0 };
            byUser[c.user_id].qty += (c.qty || 0);
        });
        const rows = Object.values(byUser);
        if (rows.length > 0) {
            const label = editEntry ? 'Riwayat lain:' : 'Sudah diinput sebelumnya:';
            otherInfoHtml = `<div style="background:#f0f4ff;border:1px solid #dce8fd;border-radius:9px;padding:8px 12px;margin-bottom:12px;font-size:.8rem;">
                <div style="margin-bottom:6px;"><i class="fas fa-users me-1" style="color:#204EAB;"></i><b>${label}</b></div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                ${rows.map(r => `<div style="background:#ede9fe;border-radius:6px;padding:4px 9px;font-weight:600;display:flex;justify-content:space-between;">
                    <span>${escHtml(r.nama)}</span><span>${r.qty} ${escHtml(item.uom||item.satuan||'')}</span>
                </div>`).join('')}
                </div>
            </div>`;
        }
    }
    // Insert/update other-checkers info panel
    let ocPanel = document.getElementById('soModalOtherCheckers');
    if (!ocPanel) {
        ocPanel = document.createElement('div');
        ocPanel.id = 'soModalOtherCheckers';
        document.getElementById('soModalOdooRef').after(ocPanel);
    }
    ocPanel.innerHTML = otherInfoHtml;

    if (editEntry) {
        // Edit riwayat spesifik: prefill persis dari riwayat ini
        (editEntry.ed_detail || []).forEach(ed => soAddEdRow(ed.ed_month, ed.ket, ed.kategori || (ed.isLte ? 'lte3m' : 'gt3m'), ed.qty));
        document.getElementById('soModalQtyFisik').value = editEntry.qty ?? '';
        document.getElementById('soModalCatatan').value  = editEntry.catatan || '';
    } else {
        // Scan/cari baru: form SELALU kosong. Tanggal ED yang sudah pernah dipakai tetap
        // ditampilkan sebagai pilihan (qty kosong) supaya checker tinggal isi salah satunya.
        const seenMonths = new Set();
        (existing?.checkers || []).forEach(c => {
            (c.ed_detail || []).forEach(ed => {
                if (seenMonths.has(ed.ed_month)) return;
                seenMonths.add(ed.ed_month);
                soAddEdRow(ed.ed_month, ed.ket, ed.kategori || (ed.isLte ? 'lte3m' : 'gt3m'), '');
            });
        });
        document.getElementById('soModalQtyFisik').value = '';
        document.getElementById('soModalCatatan').value  = '';
    }

    if (!soModalBsInst) {
        soModalBsInst = new bootstrap.Modal(document.getElementById('modalSoKlinik'));
        // Enter on Qty field → save
        document.getElementById('soModalQtyFisik').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); soConfirmItem(); }
        });
        // After fully shown → auto-focus Qty field
        document.getElementById('modalSoKlinik').addEventListener('shown.bs.modal', function() {
            document.getElementById('soModalQtyFisik').focus();
        });
        // After modal closes (any reason) → refocus scan input, KECUALI saat edit riwayat
        // (supaya tidak lompat balik ke atas halaman & panel riwayat tetap kelihatan terbuka)
        document.getElementById('modalSoKlinik').addEventListener('hidden.bs.modal', function() {
            if (soModalEditMode) return;
            setTimeout(() => { const s = document.getElementById('soScanInput'); if (s) s.focus(); }, 100);
        });
    }
    soModalBsInst.show();
}

function soAddEdRow(edMonth, ket, kategori, initQty = '') {
    const rows = document.getElementById('soModalEdRows');
    if (rows.querySelector(`[data-ed-month="${edMonth}"]`)) return;
    if (!SO_ED_CAT_META[kategori]) kategori = 'gt3m';
    const label = fmtEd(edMonth);
    const meta  = SO_ED_CAT_META[kategori];

    const div = document.createElement('div');
    div.dataset.edMonth  = edMonth;
    div.dataset.kategori = kategori;
    div.style.cssText    = `display:flex;align-items:center;gap:10px;background:${meta.bg};border:1.5px solid ${meta.border};border-radius:9px;padding:8px 12px;`;
    div.dataset.ket = ket;
    div.innerHTML = `
        <div style="flex:1;">
            <div style="font-size:.83rem;font-weight:600;">${escHtml(label)}${ket ? ' — ' + escHtml(ket) : ''}</div>
            <div>${meta.label}</div>
        </div>
        <input type="number" class="so-ed-qty-input form-control text-center fw-bold" min="0"
            value="${initQty !== '' ? initQty : ''}" placeholder="0"
            style="width:82px;border-radius:7px;font-size:.9rem;border:2px solid ${meta.border};"
            oninput="soRecalcEdTotals()">
        <button type="button" onclick="soRemoveEdRow(this,'${edMonth}')"
            style="background:none;border:none;color:#ef4444;font-size:1rem;padding:2px;line-height:1;cursor:pointer;">
            <i class="fas fa-times"></i>
        </button>`;
    rows.appendChild(div);
    document.getElementById('soModalEdTotals').style.display = '';
    soRecalcEdTotals();

    div.querySelector('.so-ed-qty-input').focus();
}

function soRemoveEdRow(btn, edMonth) {
    btn.closest('[data-ed-month]').remove();
    soRecalcEdTotals();
    if (!document.getElementById('soModalEdRows').children.length) {
        document.getElementById('soModalEdTotals').style.display = 'none';
    }
}

function soAddManualEd() {
    const monthInput = document.getElementById('soManualEdMonth');
    const ketInput   = document.getElementById('soManualEdKet');
    const val = monthInput.value; // format YYYY-MM-DD
    if (!val) {
        monthInput.style.borderColor = '#ef4444';
        setTimeout(() => monthInput.style.borderColor = '', 1500);
        return;
    }
    const edMonth  = val; // already YYYY-MM-DD
    const kategori = soEdKategori(edMonth);
    const ket      = ketInput.value.trim();
    soAddEdRow(edMonth, ket, kategori);
    monthInput.value = '';
    ketInput.value   = '';
    monthInput.focus();
}

function soRecalcEdTotals() {
    let expired = 0, lte = 0, gt = 0;
    document.querySelectorAll('#soModalEdRows [data-ed-month]').forEach(row => {
        const qty = parseFloat(row.querySelector('.so-ed-qty-input')?.value) || 0;
        if (row.dataset.kategori === 'expired') expired += qty;
        else if (row.dataset.kategori === 'lte3m') lte += qty;
        else gt += qty;
    });
    document.getElementById('soTotalExpired').textContent = expired;
    document.getElementById('soTotalLte').textContent = lte;
    document.getElementById('soTotalGt').textContent  = gt;
    document.getElementById('soTotalAll').textContent  = expired + lte + gt;
    if (expired + lte + gt > 0) document.getElementById('soModalQtyFisik').value = expired + lte + gt;
}

async function soConfirmItem() {
    const item = soCurrentItem;
    if (!item) return;
    const qtyFisik = parseFloat(document.getElementById('soModalQtyFisik').value);
    const qtyInput = document.getElementById('soModalQtyFisik');
    if (isNaN(qtyFisik) || qtyFisik < 0) {
        qtyInput.style.borderColor = '#ef4444';
        setTimeout(() => qtyInput.style.borderColor = '', 1500);
        return;
    }
    const catatan = document.getElementById('soModalCatatan').value.trim();
    const edRows  = [];
    let edExpired = 0, edLte = 0, edGt = 0;
    document.querySelectorAll('#soModalEdRows [data-ed-month]').forEach(row => {
        const qty      = parseFloat(row.querySelector('.so-ed-qty-input')?.value) || 0;
        const kategori = row.dataset.kategori || 'gt3m';
        const ket      = row.dataset.ket || '';
        edRows.push({ ed_month: row.dataset.edMonth, ket, kategori, qty });
        if (kategori === 'expired') edExpired += qty;
        else if (kategori === 'lte3m') edLte += qty;
        else edGt += qty;
    });
    const odooQ = soOdooMap[item.kode_barang] ?? null;

    // Disable button & show saving state
    const saveBtn = document.querySelector('#modalSoKlinik .modal-footer .btn:not(.btn-outline-secondary)');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; }

    let data;
    try {
        const res = await fetch(baseUrl + 'api/save_opname.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                _csrf:     csrfToken,
                klinik_id: lokId,
                is_gudang: isGudang,
                periode:   soPeriode,
                details: [{
                    barang_id:   item.id,
                    tipe:        'klinik',
                    hc_user_id:  soCurrentUserId,
                    detail_id:   soCurrentEditDetailId || 0,
                    stok_sistem: odooQ ?? 0,
                    qty_fisik:   qtyFisik,
                    ed_expired:  edExpired,
                    ed_lte3m:    edLte,
                    ed_gt3m:     edGt,
                    catatan:     catatan,
                    ed_detail:   edRows,
                }]
            })
        });
        data = await res.json();
        if (!data.success) {
            soAlert('Gagal simpan: ' + (data.message || 'Error'), 'Error');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-check me-2"></i>Simpan Item'; }
            return;
        }
    } catch(e) {
        soAlert('Koneksi gagal saat menyimpan item.', 'Error');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-check me-2"></i>Simpan Item'; }
        return;
    }

    const savedDetailId = data.detail_ids?.[0] || soCurrentEditDetailId || 0;

    // Update JS state: setiap simpan = 1 riwayat (baru, atau edit riwayat spesifik via detail_id) —
    // TIDAK PERNAH menimpa riwayat lain milik checker yang sama.
    const prev     = soKlinikEntries[item.id];
    const checkers = prev ? [...prev.checkers] : [];
    const myRecord = {
        detail_id: savedDetailId, user_id: soCurrentUserId, nama: soCurrentUserName,
        qty: qtyFisik, ed_expired: edExpired, ed_lte3m: edLte, ed_gt3m: edGt, catatan,
        ed_detail: edRows.filter(r => r.qty > 0),
    };
    if (soCurrentEditDetailId) {
        const idx = checkers.findIndex(c => c.detail_id === soCurrentEditDetailId);
        if (idx >= 0) checkers[idx] = myRecord; else checkers.push(myRecord);
    } else {
        checkers.push(myRecord);
    }

    const qtyTotal     = checkers.reduce((s, c) => s + c.qty, 0);
    const expiredTotal = checkers.reduce((s, c) => s + (c.ed_expired || 0), 0);
    const lteTotal     = checkers.reduce((s, c) => s + c.ed_lte3m, 0);
    const gtTotal       = checkers.reduce((s, c) => s + c.ed_gt3m, 0);

    // odoo_qty: live value from mirror; if null (not in mirror), stok_sistem = odooQ ?? 0
    const resolvedOdoo = odooQ !== null ? odooQ : (prev ? prev.odoo_qty : 0);
    soKlinikEntries[item.id] = {
        item,
        qty_total:    qtyTotal,
        ed_expired:   expiredTotal,
        ed_lte3m:     lteTotal,
        ed_gt3m:      gtTotal,
        checkers,
        stok_sistem:  odooQ ?? (prev ? (prev.stok_sistem ?? 0) : 0),
        odoo_qty:     resolvedOdoo,
        _insertedAt:  Date.now(), // track recency for table ordering
    };
    soCurrentEditDetailId = null;
    if (soModalBsInst) soModalBsInst.hide();
    soRenderKlinikTable();
    // After save, return focus to scan field for next barcode
    setTimeout(() => { const s = document.getElementById('soScanInput'); if (s) s.focus(); }, 350);
}

function soEdColHtml(e, kategori) {
    // Agregasi dari semua checker, fallback ke my_ed_detail jika checker tidak ada detail
    const allDetail = [];
    (e.checkers || []).forEach(c => { (c.ed_detail || []).forEach(d => allDetail.push(d)); });
    const src = allDetail.length ? allDetail : (e.my_ed_detail || []);
    // Gabungkan tanggal yang sama
    const dateMap = {};
    src.filter(d => (d.kategori || (d.isLte ? 'lte3m' : 'gt3m')) === kategori && d.qty > 0)
       .forEach(d => { dateMap[d.ed_month] = (dateMap[d.ed_month] || 0) + d.qty; });
    const merged = Object.entries(dateMap).map(([m, q]) => ({ ed_month: m, qty: q }));
    const total  = e[SO_ED_TOTAL_KEY[kategori]] || 0;
    if (merged.length > 0) {
        return merged.map(d =>
            '<div style="line-height:1.3;margin-bottom:2px;text-align:center;">' +
            '<div style="font-size:.82rem;font-weight:700;">' + d.qty + '</div>' +
            '<div style="font-size:.65rem;color:#64748b;">' + fmtEd(d.ed_month) + '</div>' +
            '</div>'
        ).join('');
    }
    return total > 0 ? '<b>' + total + '</b>' : '<span style="color:#aaa">—</span>';
}

function soRenderKlinikTable() {
    const tbody = document.getElementById('soKlinikTbody');
    const empty = document.getElementById('soKlinikEmpty');
    const wrap  = document.getElementById('soKlinikTableWrap');
    const badge = document.getElementById('soKlinikCountBadge');

    // Items dengan qty non-zero (Odoo ATAU stok lokal), plus yang sudah di-SO
    const allVisible = soAllItems.filter(it => {
        if (soKlinikEntries[it.id]) return true; // sudah di-input, selalu tampil
        const odooQ  = soOdooMap[it.kode_barang];
        if (odooQ !== undefined && odooQ !== null && Number(odooQ) !== 0) return true;
        const localQ = soLocalStockMap[String(it.id)];
        return localQ !== undefined && localQ !== null && Number(localQ) !== 0;
    });

    if (!allVisible.length) {
        empty.style.display = '';
        wrap.style.display  = 'none';
        badge.textContent   = '0 item';
        return;
    }
    empty.style.display = 'none';
    wrap.style.display  = '';

    // SO'd items first (most recently saved at top), then pending sorted by name
    const soDone = allVisible.filter(it => soKlinikEntries[it.id])
                             .sort((a, b) => (soKlinikEntries[b.id]._insertedAt || 0) - (soKlinikEntries[a.id]._insertedAt || 0));
    const soTodo = allVisible.filter(it => !soKlinikEntries[it.id])
                             .sort((a, b) => a.nama_barang.localeCompare(b.nama_barang, 'id'));

    badge.textContent = soDone.length + ' / ' + allVisible.length + ' item';

    // Re-apply current filter after re-render
    const filterQ = (document.getElementById('soKlinikFilter')?.value || '').trim().toLowerCase();

    const isMobile = window.innerWidth <= 768;
    const rows = [...soDone, ...soTodo].map(item => {
        const id    = item.id;
        const e     = soKlinikEntries[id]; // undefined if not yet SO'd
        // For SO'd items use e.odoo_qty (includes stok_sistem fallback); for pending use live map
        const liveQ   = soOdooMap[item.kode_barang];
        const odooRaw = e ? e.odoo_qty : (liveQ !== undefined ? Number(liveQ) : null);
        const odoo    = odooRaw; // nilai asli untuk kalkulasi selisih
        const odooDisplay = isSuperAdmin ? odoo : '<span style="color:#94a3b8;font-style:italic;">*</span>';
        const uom   = escHtml(item.uom || item.satuan || '');
        const nameL = item.nama_barang.toLowerCase();
        // Filter: teks pencarian
        const matchQ = !filterQ || nameL.includes(filterQ) || (item.kode_barang || '').toLowerCase().includes(filterQ);
        // Filter: belum input = belum SO atau qty_total null/0
        const isPending  = !e || (e.qty_total === null || e.qty_total === undefined);
        const matchPending = !soPendingFilterActive || isPending;
        const hidden = (!matchQ || !matchPending) ? 'display:none;' : '';

        if (e) {
            // ── SO'd row ───────────────────────────────────────────────────
            const qtyTotal = e.qty_total ?? 0;
            const selisih  = odoo !== null ? (qtyTotal - odoo) : null;
            let selHtml = '<span class="badge-pending">—</span>';
            let rowCls  = '';
            if (isSuperAdmin && selisih !== null) {
                if (selisih === 0)    { selHtml = '<span class="badge-selisih-ok">Sesuai</span>';        rowCls = 'row-ok'; }
                else if (selisih < 0) { selHtml = '<span class="badge-selisih-minus">' + selisih + '</span>'; rowCls = 'row-selisih'; }
                else                  { selHtml = '<span class="badge-selisih-plus">+' + selisih + '</span>'; rowCls = 'row-selisih'; }
            }
            const checkers  = e.checkers || [];
            const allCat   = checkers.map(c => c.catatan).filter(Boolean).join('; ');
            const checkerBadge = checkers.length > 0
                ? '<button onclick="soToggleHistory(' + id + ')" style="background:none;border:none;padding:0;cursor:pointer;" title="Lihat & edit riwayat">' +
                  '<span style="background:#e0e7ff;border:1px solid #c7d2fe;border-radius:6px;padding:1px 7px;font-size:.7rem;font-weight:600;color:#4338ca;">' +
                  '<i class="fas fa-history" style="font-size:.6rem;"></i> ' + checkers.length + ' riwayat</span></button>'
                : '';

            let historyInner = '';
            checkers.forEach(c => {
                const isMe   = c.user_id === soCurrentUserId;
                const bg     = isMe ? '#ede9fe' : '#f1f5f9';
                const border = isMe ? '#c4b5fd' : '#e2e8f0';
                const color  = isMe ? '#6d28d9' : '#374151';
                const label  = escHtml(c.nama) + (isMe ? ' <span style="opacity:.6;">(Saya)</span>' : '');
                const catHtml = c.catatan ? '<span style="margin-left:6px;color:#94a3b8;">· ' + escHtml(c.catatan) + '</span>' : '';
                const edHtml = (c.ed_detail && c.ed_detail.length)
                    ? '<div style="margin-top:3px;display:flex;flex-wrap:wrap;gap:4px;">' +
                      c.ed_detail.map(ed => '<span style="background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:1px 6px;font-size:.68rem;color:#64748b;">' + fmtEd(ed.ed_month) + ': <b>' + ed.qty + '</b></span>').join('') +
                      '</div>' : '';
                historyInner +=
                    '<div style="background:' + bg + ';border:1px solid ' + border + ';border-radius:8px;padding:6px 10px;font-size:.78rem;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">' +
                    '<div style="flex:1;min-width:0;">' +
                    '<span style="font-weight:700;color:' + color + ';"><i class="fas fa-user me-1" style="font-size:.65rem;"></i>' + label + '</span>' +
                    '<span style="margin-left:6px;">&rarr; <b>' + c.qty + '</b> ' + uom + '</span>' + catHtml + edHtml +
                    '</div>' +
                    '<div style="display:flex;gap:3px;flex-shrink:0;">' +
                    '<button onclick="soEditHistoryEntry(' + id + ',' + c.detail_id + ')" title="Edit riwayat ini" style="background:#fff;border:1px solid #cbd5e1;border-radius:5px;width:22px;height:22px;line-height:1;cursor:pointer;color:#204EAB;padding:0;"><i class="fas fa-pen" style="font-size:.62rem;"></i></button>' +
                    '<button onclick="soDeleteHistoryEntry(' + id + ',' + c.detail_id + ')" title="Hapus riwayat ini" style="background:#fff;border:1px solid #cbd5e1;border-radius:5px;width:22px;height:22px;line-height:1;cursor:pointer;color:#dc2626;padding:0;"><i class="fas fa-trash" style="font-size:.62rem;"></i></button>' +
                    '</div></div>';
            });
            const histDisplay = (soExpandedHistoryId != null && soExpandedHistoryId == id) ? '' : 'display:none;';
            const historyRow = '<tr id="so-hist-' + id + '" style="' + histDisplay + '">' +
                '<td colspan="99" class="so-card-cell" style="padding-bottom:4px !important;">' +
                '<div style="background:#f8faff;border-radius:0 0 10px 10px;border:1.5px solid #e8edf5;border-top:none;padding:8px 13px;">' +
                '<div style="font-size:.73rem;font-weight:600;color:#6366f1;margin-bottom:6px;"><i class="fas fa-history me-1"></i>Riwayat Input</div>' +
                '<div style="display:flex;flex-direction:column;gap:6px;">' + historyInner + '</div></div></td></tr>';

            const edExpiredHtml = soEdColHtml(e, 'expired');
            const edLteHtml = soEdColHtml(e, 'lte3m');
            const edGtHtml  = soEdColHtml(e, 'gt3m');
            const hasEd     = ((e.ed_expired||0) > 0 || (e.ed_lte3m||0) > 0 || (e.ed_gt3m||0) > 0);
            const cardCls   = rowCls === 'row-ok' ? 'so-card-ok' : rowCls === 'row-selisih' ? 'so-card-sel' : '';
            const actionBtns =
                '<button onclick="soToggleHistory(' + id + ')" class="btn btn-sm btn-outline-primary" title="Lihat Detail" style="border-radius:6px;padding:4px 8px;line-height:1;"><i class="fas fa-chevron-down" style="font-size:.75rem;"></i></button>';

            if (isMobile) {
                return '<tr class="so-main-row ' + rowCls + '" data-id="' + id + '" data-name="' + nameL + '" style="' + hidden + '">' +
                    '<td colspan="99" class="so-card-cell">' +
                    '<div class="so-item-card ' + cardCls + '">' +
                      '<div class="fw-semibold" style="font-size:.87rem;line-height:1.35;">' + escHtml(item.nama_barang) + '</div>' +
                      '<div style="font-size:.7rem;color:#94a3b8;font-family:monospace;margin-bottom:7px;">' + escHtml(item.kode_barang || '') + '</div>' +
                      '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">' +
                        '<div style="font-size:.8rem;color:#64748b;line-height:1.5;">' +
                          'Odoo: <b style="color:#1e293b;">' + (odoo !== null ? odooDisplay : '—') + '</b>' +
                          ' &nbsp;·&nbsp; Fisik: <b style="color:#204EAB;">' + qtyTotal + '</b> <span style="font-size:.72rem;">' + uom + '</span>' +
                          (isSuperAdmin ? ' &nbsp;·&nbsp; ' + selHtml : '') +
                          ((e.ed_expired||0) > 0 ? ' &nbsp;·&nbsp; <span style="color:#dc2626;">Expired: <b>' + e.ed_expired + '</b></span>' : '') +
                          (hasEd ? ' &nbsp;·&nbsp; ED≤3: <b>' + (e.ed_lte3m||0) + '</b>' : '') +
                          (hasEd && (e.ed_gt3m||0) > 0 ? '  ED>3: <b>' + e.ed_gt3m + '</b>' : '') +
                          (allCat ? '<br><span style="font-size:.72rem;color:#94a3b8;"><i class="fas fa-comment me-1"></i>' + escHtml(allCat) + '</span>' : '') +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">' +
                          checkerBadge +
                          '<div style="display:flex;gap:4px;">' + actionBtns + '</div>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                    '</td></tr>' + historyRow;
            } else {
                return '<tr class="so-main-row ' + rowCls + '" data-id="' + id + '" data-name="' + nameL + '" style="' + hidden + '">' +
                    '<td><div class="fw-semibold" style="font-size:.85rem;line-height:1.3;">' + escHtml(item.nama_barang) + '</div>' +
                    '<div style="font-size:.7rem;color:#94a3b8;font-family:monospace;">' + escHtml(item.kode_barang || '') + '</div></td>' +
                    '<td class="text-end">' + (odoo !== null ? odooDisplay : '<span style="color:#aaa">—</span>') + '</td>' +
                    '<td class="text-end"><b style="color:#204EAB;">' + qtyTotal + '</b> <span style="font-size:.72rem;color:#94a3b8;">' + uom + '</span></td>' +
                    '<td class="text-center">' + edExpiredHtml + '</td>' +
                    '<td class="text-center">' + edLteHtml + '</td>' +
                    '<td class="text-center">' + edGtHtml + '</td>' +
                    (isSuperAdmin ? '<td class="text-center">' + selHtml + '</td>' : '') +
                    '<td>' + (allCat ? '<span style="font-size:.78rem;color:#64748b;">' + escHtml(allCat) + '</span>' : '<span style="color:#aaa">—</span>') + '</td>' +
                    '<td class="text-center"><div style="display:flex;flex-direction:column;align-items:center;gap:4px;">' +
                      '<div style="display:flex;gap:4px;">' + actionBtns + '</div>' +
                      (checkerBadge ? checkerBadge : '') +
                    '</div></td>' +
                    '</tr>' + historyRow;
            }

        } else {
            // ── Pending row (belum di-SO) ──────────────────────────────────
            const inputBtn = '<button onclick="soEditEntry(' + id + ')" class="btn btn-sm fw-semibold" style="border-radius:7px;font-size:.78rem;padding:5px 14px;background:#204EAB;color:#fff;border:none;flex-shrink:0;">' +
                '<i class="fas fa-plus me-1"></i>Input</button>';

            if (isMobile) {
                return '<tr class="so-main-row" data-id="' + id + '" data-name="' + nameL + '" style="' + hidden + '">' +
                    '<td colspan="99" class="so-card-cell">' +
                    '<div class="so-item-card so-card-pending">' +
                      '<div style="font-size:.87rem;color:#374151;line-height:1.35;">' + escHtml(item.nama_barang) + '</div>' +
                      '<div style="font-size:.7rem;color:#94a3b8;font-family:monospace;margin-bottom:6px;">' + escHtml(item.kode_barang || '') + '</div>' +
                      '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                        '<span style="font-size:.8rem;color:#64748b;">Odoo: <b style="color:' + (isSuperAdmin && odoo < 0 ? '#ef4444' : '#1e293b') + ';">' + (odoo !== null ? odooDisplay : '—') + '</b></span>' +
                        inputBtn +
                      '</div>' +
                    '</div>' +
                    '</td></tr>';
            } else {
                return '<tr class="so-main-row" style="opacity:.7;' + hidden + '" data-id="' + id + '" data-name="' + nameL + '">' +
                    '<td><div style="font-size:.85rem;color:#374151;line-height:1.3;">' + escHtml(item.nama_barang) + '</div>' +
                    '<div style="font-size:.7rem;color:#94a3b8;font-family:monospace;">' + escHtml(item.kode_barang || '') + '</div></td>' +
                    '<td class="text-end">' + (odoo !== null ? odooDisplay : '<span style="color:#aaa">—</span>') + '</td>' +
                    '<td colspan="' + (isSuperAdmin ? 6 : 5) + '" class="text-center"><span style="color:#cbd5e1;font-size:.8rem;font-style:italic;">belum input</span></td>' +
                    '<td class="text-center">' + inputBtn + '</td>' +
                    '</tr>';
            }
        }
    });

    tbody.innerHTML = rows.join('');
}

// Re-render tables when screen size crosses mobile/desktop boundary
let _soResizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(_soResizeTimer);
    _soResizeTimer = setTimeout(() => {
        soRenderKlinikTable();
        if (typeof hcRenderTable === 'function' && hcSelectedUid) hcRenderTable();
    }, 150);
});

function soEditEntry(id) {
    const item = soAllItems.find(it => it.id == id);
    if (item) openSoModal(item);
}

function soToggleHistory(id) {
    const row = document.getElementById('so-hist-' + id);
    if (!row) return;
    const nowOpen = row.style.display === 'none';
    row.style.display = nowOpen ? '' : 'none';
    soExpandedHistoryId = nowOpen ? id : null;
}

function soEditHistoryEntry(id, detailId) {
    const item  = soAllItems.find(it => it.id == id);
    const entry = soKlinikEntries[id];
    const rec   = entry ? (entry.checkers || []).find(c => c.detail_id === detailId) : null;
    if (item && rec) openSoModal(item, rec);
}

async function soDeleteHistoryEntry(id, detailId) {
    if (soIsLocked || soNoPeriod) return;
    const item  = soAllItems.find(it => it.id == id);
    const entry = soKlinikEntries[id];
    const rec   = entry ? (entry.checkers || []).find(c => c.detail_id === detailId) : null;
    if (!item || !entry || !rec) return;
    const uom = item.uom || item.satuan || '';
    const ok = await soConfirm(
        `Hapus riwayat <b>${escHtml(rec.nama)}</b> (${rec.qty} ${escHtml(uom)}) dari <b>${escHtml(item.nama_barang)}</b>?`,
        'Konfirmasi Hapus', '#dc2626'
    );
    if (!ok) return;

    try {
        const res = await fetch(baseUrl + 'api/delete_so_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                barang_id: id,
                klinik_id: lokId,
                is_gudang: isGudang,
                periode:   soPeriode,
                scope:     'entry',
                detail_id: detailId,
                _csrf:     csrfToken
            })
        });
        const d = await res.json();
        if (!d.success) { soAlert('Gagal hapus: ' + d.message, 'Error'); return; }

        const newCheckers = (soKlinikEntries[id]?.checkers || []).filter(c => c.detail_id !== detailId);
        if (newCheckers.length === 0) {
            delete soKlinikEntries[id];
        } else {
            const qTotal = newCheckers.reduce((s, c) => s + (c.qty        || 0), 0);
            const eTotal = newCheckers.reduce((s, c) => s + (c.ed_expired || 0), 0);
            const lTotal = newCheckers.reduce((s, c) => s + (c.ed_lte3m   || 0), 0);
            const gTotal = newCheckers.reduce((s, c) => s + (c.ed_gt3m    || 0), 0);
            soKlinikEntries[id] = { ...soKlinikEntries[id], checkers: newCheckers,
                qty_total: qTotal, ed_expired: eTotal, ed_lte3m: lTotal, ed_gt3m: gTotal };
        }
        soRenderKlinikTable();
    } catch(e) {
        soAlert('Error: ' + e.message, 'Error');
    }
}

// ── SO Periode / Lock ──────────────────────────────────────────────────────────
async function openSoPeriode() {
    if (!await soConfirm(`Buka SO periode ${soPeriodeLabel}? Data belum bisa diinput sebelum SO dibuka.`, 'Buka SO')) return;
    const btn = document.querySelector('[onclick="openSoPeriode()"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Membuka...'; }
    try {
        const r = await fetch(baseUrl + 'api/open_so_periode.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({klinik_id: lokId, is_gudang: isGudang, periode: soPeriode, _csrf: csrfToken})
        });
        const d = await r.json();
        if (d.success) {
            await soAlert(`SO ${soPeriodeLabel} berhasil dibuka.`, 'Selesai');
            location.reload();
        } else {
            await soAlert('Gagal: ' + d.message, 'Error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-folder-open me-1"></i>Buka SO ' + soPeriodeLabel; }
        }
    } catch(e) {
        await soAlert('Error: ' + e.message, 'Error');
        if (btn) { btn.disabled = false; }
    }
}

async function lockSoPeriode(action) {
    const isLock = action === 'lock';
    const msg = isLock
        ? `Kunci SO ${soPeriodeLabel}? Setelah dikunci, tidak ada input yang bisa dilakukan kecuali dibuka kembali.`
        : `Buka kembali SO ${soPeriodeLabel} yang sudah terkunci?`;
    if (!await soConfirm(msg, isLock ? 'Kunci SO' : 'Buka Kembali SO', isLock ? '#dc2626' : '#204EAB')) return;
    const btnId = isLock ? 'btnLockSo' : 'btnUnlockSo';
    const btn = document.getElementById(btnId);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Proses...'; }
    try {
        const r = await fetch(baseUrl + 'api/lock_so_periode.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({klinik_id: lokId, is_gudang: isGudang, periode: soPeriode, action, _csrf: csrfToken})
        });
        const d = await r.json();
        if (d.success) {
            await soAlert(isLock ? `SO ${soPeriodeLabel} berhasil dikunci.` : `SO ${soPeriodeLabel} berhasil dibuka kembali.`, 'Selesai');
            location.reload();
        } else {
            await soAlert('Gagal: ' + d.message, 'Error');
            if (btn) { btn.disabled = false; }
        }
    } catch(e) {
        await soAlert('Error: ' + e.message, 'Error');
        if (btn) { btn.disabled = false; }
    }
}

async function resetSoPeriode() {
    if (!await soConfirm(
        `Reset semua data item SO ${soPeriodeLabel} untuk lokasi ini?\n\nSemua item yang sudah diinput akan dihapus, SO tetap terbuka dan bisa diisi ulang dari nol.\n\nAksi ini tidak dapat dibatalkan.`,
        'Reset SO', '#7c3aed'
    )) return;
    const btn = document.getElementById('btnResetSo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mereset...'; }
    try {
        const r = await fetch(baseUrl + 'api/reset_so_periode.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ _csrf: csrfToken, scope: 'single', klinik_id: lokId, is_gudang: isGudang, periode: soPeriode })
        });
        const d = await r.json();
        if (d.success) {
            await soAlert(`SO ${soPeriodeLabel} berhasil direset. ${d.deleted ?? 0} baris data dihapus.`, 'Selesai');
            location.reload();
        } else {
            await soAlert('Gagal: ' + (d.message || 'Error'), 'Error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash-restore me-1"></i>Reset SO'; }
        }
    } catch(e) {
        await soAlert('Error: ' + e.message, 'Error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash-restore me-1"></i>Reset SO'; }
    }
}


// ── Filter: Belum Input ───────────────────────────────────────────────────────
var soPendingFilterActive = false;
function soTogglePendingFilter() {
    soPendingFilterActive = !soPendingFilterActive;
    const btn = document.getElementById('btnFilterBelumSO');
    if (soPendingFilterActive) {
        btn.style.background = 'rgba(255,255,255,.9)';
        btn.style.color      = '#204EAB';
        btn.innerHTML        = '<i class="fas fa-times me-1"></i>Belum Input';
    } else {
        btn.style.background = 'transparent';
        btn.style.color      = '#fff';
        btn.innerHTML        = '<i class="fas fa-hourglass-half me-1"></i>Belum Input';
    }
    soRenderKlinikTable();
}

// ── Save Opname ───────────────────────────────────────────────────────────────
function soFilterTable(q) {
    const q2 = q.trim().toLowerCase();
    document.querySelectorAll('#soKlinikTbody tr.so-main-row').forEach(tr => {
        const show = !q2 || (tr.dataset.name || '').includes(q2);
        tr.style.display = show ? '' : 'none';
        const hist = document.getElementById('so-hist-' + tr.dataset.id);
        if (hist && !show) hist.style.display = 'none';
    });
}

function soExportExcel() {
    const entries = Object.values(soKlinikEntries);
    if (!entries.length) { alert('Belum ada item SO untuk di-export.'); return; }

    if (typeof XLSX === 'undefined') {
        alert('Library Excel belum siap, coba lagi sebentar.');
        return;
    }

    const today  = new Date().toISOString().slice(0, 10);
    const klinik = <?= json_encode($lok_data['nama_klinik'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

    const aoa = [
        ['Stock Opname Klinik — ' + klinik],
        ['Periode SO: ' + soPeriodeLabel + ' | Tanggal Export: ' + today],
        [],
        ['Kode Barang', 'Nama Barang', 'Satuan', 'Konversi UOM', 'Expired Tgl', 'Expired Qty', 'ED ≤3bln Tgl', 'ED ≤3bln Qty', 'ED >3bln Tgl', 'ED >3bln Qty', 'Qty Fisik', 'Catatan'],
    ];

    entries.forEach((e, i) => {
        // Agregasi ED detail dari SEMUA checker (bukan hanya current user)
        const allEdDetail = [];
        (e.checkers || []).forEach(c => { (c.ed_detail || []).forEach(d => allEdDetail.push(d)); });
        // Fallback ke my_ed_detail jika checker tidak punya ed_detail (data lama)
        const detail    = allEdDetail.length ? allEdDetail : (e.my_ed_detail || []);
        // Gabungkan entri dengan tanggal yang sama, per kategori
        const expMap = {}, lteMap = {}, gtMap = {};
        detail.filter(d => d.qty > 0).forEach(d => {
            const kat = d.kategori || (d.isLte ? 'lte3m' : 'gt3m');
            const map = kat === 'expired' ? expMap : (kat === 'lte3m' ? lteMap : gtMap);
            map[d.ed_month] = (map[d.ed_month] || 0) + d.qty;
        });
        const mult          = parseFloat(e.item.multiplier || 0);
        const fromUom       = e.item.from_uom || '';
        const toUom         = e.item.uom || e.item.satuan || '';
        const hasKonv       = mult > 0 && mult !== 1 && fromUom;
        const toBase        = v => hasKonv ? parseFloat((v * mult).toFixed(4)) : v;
        const konversi      = hasKonv ? mult : '';
        const expDet = Object.keys(expMap);
        const lteDet = Object.keys(lteMap);
        const gtDet  = Object.keys(gtMap);
        // Gunakan total dari semua checker/riwayat untuk qty (per-tanggal cuma dipakai utk list tanggal)
        const totalExp = toBase(e.ed_expired || 0);
        const totalLte = toBase(e.ed_lte3m || 0);
        const totalGt  = toBase(e.ed_gt3m  || 0);
        // Qty ED selalu ditampilkan sebagai 1 angka total (tidak dipecah per tanggal) — tanggalnya
        // tetap dilist semua sebagai info, tapi qty-nya sudah dijumlahkan semua riwayat & semua tanggal.
        const expTgl    = expDet.length ? expDet.map(m => fmtEd(m)).join(', ') : '';
        const expQty    = totalExp > 0 ? totalExp : '';
        const lteTgl    = lteDet.length ? lteDet.map(m => fmtEd(m)).join(', ') : '';
        const lteQty    = totalLte > 0 ? totalLte : '';
        const gtTgl     = gtDet.length  ? gtDet.map(m => fmtEd(m)).join(', ')  : '';
        const gtQty     = totalGt  > 0 ? totalGt  : '';
        const qtyFisikKonv = e.qty_total != null ? toBase(e.qty_total) : '';
        aoa.push([
            e.item.kode_barang || '',
            e.item.nama_barang || '',
            toUom,
            konversi,
            expTgl, expQty,
            lteTgl, lteQty,
            gtTgl,  gtQty,
            qtyFisikKonv,
            (e.checkers || []).map(c => c.catatan).filter(Boolean).join('; '),
        ]);
    });

    const ws = XLSX.utils.aoa_to_sheet(aoa);

    // Column widths (12 columns: Kode,Nama,Satuan,KonversiUOM,ExpTgl,ExpQty,ED≤Tgl,ED≤Qty,ED>Tgl,ED>Qty,QtyFisik,Catatan)
    ws['!cols'] = [
        {wch:16}, {wch:36}, {wch:8},
        {wch:12}, {wch:18}, {wch:10}, {wch:18}, {wch:10}, {wch:18}, {wch:10}, {wch:12}, {wch:30}
    ];
    // Merge title rows (12 columns, index 0–11)
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:11} }, { s:{r:1,c:0}, e:{r:1,c:11} }];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'SO Klinik');

    // ── Kumpulkan data HC: DB rows + in-session unsaved ──────────────────────
    const hcRowsForExport = [...hcExportRowsDb];
    const dbKeys = new Set(hcExportRowsDb.map(r => r.nakes_nama + '|' + r.kode_barang));
    const nakesMapEx = {};
    hcUsersData.forEach(u => { nakesMapEx[u.id] = u.nama; });
    Object.entries(hcValidations).forEach(([uid, items]) => {
        const nakesNama = nakesMapEx[parseInt(uid)] || ('Nakes #' + uid);
        Object.values(items).forEach(entry => {
            if (!entry.item) return;
            const key = nakesNama + '|' + (entry.item.kode_barang || '');
            if (dbKeys.has(key)) return;
            if (entry.actual_qty === '' && entry.nakes_qty === null) return;
            hcRowsForExport.push({
                nakes_nama:  nakesNama,
                kode_barang: entry.item.kode_barang || '',
                nama_barang: entry.item.nama_barang || '',
                satuan:      entry.item.uom || entry.item.satuan || '',
                odoo_qty:    entry.odoo_qty,
                nakes_qty:   entry.nakes_qty,
                qty_aktual:  entry.actual_qty !== '' && entry.actual_qty !== null ? entry.actual_qty : null,
                catatan:     entry.catatan || '',
            });
        });
    });

    // ── Helper: get item info from soAllItems by kode_barang ──────────────────
    const _itemByKode = {};
    soAllItems.forEach(it => { if (it.kode_barang) _itemByKode[it.kode_barang] = it; });

    // ── Helper: build SO HC sheet (1 baris per item, qty di-sum semua nakes) ──
    // qty_aktual (sudah divalidasi admin) in to_uom; nakes_qty (self-report, belum divalidasi) in from_uom
    function buildHcGabunganSheet(rows) {
        const hdr = ['Kode Barang','Nama Barang','Satuan','Konversi UOM','Expired Tgl','Expired Qty','ED ≤3bln Tgl','ED ≤3bln Qty','ED >3bln Tgl','ED >3bln Qty','Qty Fisik','Status Validasi','Catatan'];
        const aoa = [['Stock Opname HC — ' + klinik], ['Periode SO: ' + soPeriodeLabel + ' | Tanggal Export: ' + today], [], hdr];
        const aggMap = new Map();
        rows.forEach(r => {
            const key = r.kode_barang || r.nama_barang;
            if (!aggMap.has(key)) {
                const it = _itemByKode[r.kode_barang] || {};
                aggMap.set(key, {
                    kode: r.kode_barang||'', nama: r.nama_barang||'',
                    qty_total: 0, has_qty: false, catatan: [],
                    hasValidated: false, hasRaw: false,
                    mult: parseFloat(it.multiplier || 0),
                    fromUom: it.from_uom || r.satuan || '', toUom: it.uom || r.satuan || '',
                });
            }
            const agg = aggMap.get(key);
            if (r.qty_aktual !== null && r.qty_aktual !== undefined) {
                // Sudah divalidasi admin/SPV — qty_aktual dalam to_uom, konversi ke base (from_uom) sekali
                const akt = parseFloat(r.qty_aktual);
                agg.qty_total += (agg.mult > 0 && agg.mult !== 1) ? akt * agg.mult : akt;
                agg.has_qty      = true;
                agg.hasValidated = true;
            } else if (r.nakes_qty !== null && r.nakes_qty !== undefined) {
                // Belum divalidasi — laporan self-report nakes SUDAH dalam base (from_uom), tambahkan langsung
                agg.qty_total += parseFloat(r.nakes_qty);
                agg.has_qty  = true;
                agg.hasRaw   = true;
            }
            if (r.catatan) agg.catatan.push(r.nakes_nama + ': ' + r.catatan);
        });
        aggMap.forEach(agg => {
            const hasKonv  = agg.mult > 0 && agg.mult !== 1 && agg.fromUom && agg.fromUom !== agg.toUom;
            const konversi = hasKonv ? agg.mult : '';
            const qty = agg.has_qty ? parseFloat(agg.qty_total.toFixed(4)) : '';
            // Tandai kalau totalnya campuran validasi admin & laporan mentah nakes yg belum dicek,
            // supaya pembaca laporan tahu sebagian angka ini belum resmi divalidasi.
            const statusValidasi = !agg.has_qty ? ''
                : (agg.hasValidated && agg.hasRaw) ? 'Sebagian belum divalidasi'
                : agg.hasValidated ? 'Tervalidasi'
                : 'Belum divalidasi (laporan nakes)';
            aoa.push([agg.kode, agg.nama, agg.toUom, konversi, '', '', '', '', '', '', qty, statusValidasi, agg.catatan.join(' | ')]);
        });
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:12} }, { s:{r:1,c:0}, e:{r:1,c:12} }];
        ws['!cols']   = [{wch:16},{wch:34},{wch:8},{wch:12},{wch:18},{wch:10},{wch:18},{wch:10},{wch:18},{wch:10},{wch:12},{wch:22},{wch:36}];
        return ws;
    }

    if (hcRowsForExport.length > 0) {
        XLSX.utils.book_append_sheet(wb, buildHcGabunganSheet(hcRowsForExport), 'SO HC');
    }

    const klinikSlug = klinik.replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    XLSX.writeFile(wb, 'SO_' + klinikSlug + '_Periode-' + soPeriode + '.xlsx');
}

// ── Camera state variables (declared before any code that calls soOpenCamera) ──
let _soQrScanner  = null;
let _soQrCallback = null;
let _soQrReaderId = null;

// ── Scan via Kamera (popup) ───────────────────────────────────────────────────
// Setiap mau scan pakai kamera, user klik tombol ini dulu → popup kamera muncul (#soQrModal),
// otomatis tertutup setelah 1x scan berhasil. Input scanner/keyboard-wedge tetap selalu tampil.
function soScanKamera(panel) {
    const isKlinik = (panel === 'klinik');
    const cb = isKlinik
        ? (code => { soProcessScan(code); })
        : (code => { hcProcessScan(code); });
    soOpenCamera(cb, null);
}

// ── Camera Barcode Scanner (Mobile) ───────────────────────────────────────────
async function soOpenCamera(callback, readerId) {
    _soQrCallback = callback;
    // Jika ada kamera sebelumnya, stop dulu
    await soStopCamera();

    if (typeof Html5Qrcode === 'undefined') {
        try {
            await new Promise((res, rej) => {
                const s = document.createElement('script');
                s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
                s.onload = res; s.onerror = rej;
                document.head.appendChild(s);
            });
        } catch(e) {
            alert('Gagal memuat library kamera. Pastikan ada koneksi internet.'); return;
        }
    }

    const isInline = !!readerId;
    if (!isInline) {
        // Fullscreen modal (fallback)
        const modal = document.getElementById('soQrModal');
        if (modal) modal.style.display = 'flex';
        readerId = 'soQrReader';
    }
    const container = document.getElementById(readerId);
    if (container) container.innerHTML = '';

    const formats = [
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.QR_CODE,
    ];
    _soQrScanner = new Html5Qrcode(readerId);
    _soQrReaderId = readerId;
    try {
        await _soQrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 120 }, formatsToSupport: formats },
            (decodedText) => {
                if (isInline) {
                    // Inline: kamera tetap hidup, callback dipanggil terus
                    if (_soQrCallback) _soQrCallback(decodedText);
                } else {
                    // Fullscreen modal: stop setelah 1 scan
                    soStopCamera();
                    if (_soQrCallback) { _soQrCallback(decodedText); _soQrCallback = null; }
                }
            },
            () => {}
        );
    } catch(err) {
        if (!isInline) soStopCamera();
        alert('Tidak bisa akses kamera: ' + (err.message || err));
    }
}

function soStopCamera() {
    const modal = document.getElementById('soQrModal');
    if (modal) modal.style.display = 'none';
    if (_soQrScanner) {
        const s = _soQrScanner; _soQrScanner = null;
        return s.stop().catch(() => {}).then(() => { try { s.clear(); } catch(e){} });
    }
    return Promise.resolve();
}

// ══════════════════════════════════════════════════════════════ STOK HC JS ════

const hcDetailData    = <?= json_encode($hc_detail ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const hcOdooData      = <?= json_encode($odoo_hc ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const hcUsersData     = <?= json_encode($hc_users ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const hcExportRowsDb  = <?= json_encode($hc_export_rows ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// uid → {barang_id → qty_to_uom} — last validated bag stock per nakes (pre-fill reference)
const hcBagStocks     = <?= json_encode($hc_bag_stocks ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// uid → barang_id → {nakes_qty, actual_qty, catatan, odoo_qty, stok_sistem, item, dirty}
const hcValidations     = {};
const hcBagDeletedBids  = {}; // uid → Set(bidInt) — bag items intentionally deleted by admin
let hcSelectedUid   = null;
let hcSelectedNama  = '';

// Pre-populate hcValidations from PHP-loaded data
(function initHcValidations() {
    const itemById = {};
    soAllItems.forEach(it => { itemById[it.id] = it; });
    Object.entries(hcDetailData).forEach(([uid, items]) => {
        hcValidations[uid] = {};
        Object.entries(items).forEach(([bid, d]) => {
            const item = itemById[parseInt(bid)];
            if (!item) return;
            const odooQ = hcOdooData[item.kode_barang] ?? null;
            hcValidations[uid][parseInt(bid)] = {
                item,
                nakes_qty:   d.qty,
                actual_qty:  d.qty_aktual !== null && d.qty_aktual !== undefined ? d.qty_aktual : '',
                catatan:     d.catatan,
                odoo_qty:    odooQ !== undefined ? Number(odooQ) : (d.stok_sistem ?? null),
                dirty:       false,
            };
        });
    });
})();

// Restore selected nakes from URL on HC tab load
(function initHcNakes() {
    const params  = new URLSearchParams(location.search);
    if ((params.get('tab') || 'klinik') !== 'hc') return;
    const nakesId = parseInt(params.get('nakes') || '0');
    if (!nakesId) return;
    const u = hcUsersData.find(u => u.id === nakesId);
    if (u) setTimeout(() => hcSelectNakes(nakesId, u.nama), 150);
})();

function hcSelectNakes(uid, nama) {
    // Update pill styles
    hcUsersData.forEach(u => {
        const btn = document.getElementById('hc-pill-' + u.id);
        if (!btn) return;
        const badge = document.getElementById('hc-pill-badge-' + u.id);
        if (u.id === uid) {
            btn.style.background   = '#204EAB';
            btn.style.color        = '#fff';
            btn.style.borderColor  = '#204EAB';
            if (badge) { badge.style.background = '#fff'; badge.style.color = '#204EAB'; }
        } else {
            btn.style.background   = '#fff';
            btn.style.color        = '#204EAB';
            btn.style.borderColor  = '#dce8fd';
            if (badge) { badge.style.background = '#dce8fd'; badge.style.color = '#204EAB'; }
        }
    });
    hcSelectedUid  = uid;
    hcSelectedNama = nama;
    if (!hcValidations[uid]) hcValidations[uid] = {};

    // Pre-populate from bag stock if nakes has no entries for this period yet
    if (Object.keys(hcValidations[uid]).length === 0) {
        const bagItems = hcBagStocks[String(uid)] || hcBagStocks[uid] || {};
        const deleted  = hcBagDeletedBids[uid] || new Set();
        Object.entries(bagItems).forEach(([bid, bagQty]) => {
            const bidInt = parseInt(bid);
            if (deleted.has(bidInt)) return; // sengaja dihapus admin, jangan re-populate
            const item   = soAllItems.find(it => it.id == bidInt);
            if (!item) return;
            const odooQ = hcOdooData[item.kode_barang] ?? null;
            hcValidations[uid][bidInt] = {
                item,
                nakes_qty:  null,   // belum lapor periode ini
                actual_qty: '',
                catatan:    '',
                odoo_qty:   odooQ !== null ? Number(odooQ) : null,
                dirty:      false,
                _from_bag:  true,   // pre-populated, belum ada baris di DB
                _bag_qty:   bagQty, // referensi stok tas (to_uom)
            };
        });
    }

    // Show/hide bag banner
    const bagBanner = document.getElementById('hcBagBanner');
    if (bagBanner) {
        const hasBagRef = Object.values(hcValidations[uid]).some(e => e._from_bag);
        bagBanner.style.display = hasBagRef ? 'flex' : 'none';
    }

    document.getElementById('hcPanelTitle').innerHTML = '<i class="fas fa-user-nurse me-2"></i>' + escHtml(nama);
    document.getElementById('hcNakesPanel').style.display = '';
    document.getElementById('hcTableFilter').value = '';
    hcRenderTable();
    document.getElementById('hcScanInput').focus();
    // Lock nakes in URL so refresh restores selection
    const url = new URL(location.href);
    url.searchParams.set('nakes', uid);
    history.replaceState(null, '', url.toString());
}

function hcSwitchPanel(panel) {
    // panels are now always visible side-by-side — just focus the right input
    if (panel === 'scan') document.getElementById('hcScanInput').focus();
    else                  document.getElementById('hcSearchInput').focus();
}

function hcProcessScan(val) {
    if (soIsLocked || soNoPeriod) return;
    val = (val || '').trim();
    const st = document.getElementById('hcScanStatus');
    if (!val) return;
    const found = soBarcodeMap[val.toLowerCase()];
    document.getElementById('hcScanInput').value = '';
    if (!found) {
        st.innerHTML = '<span style="color:#dc2626;"><i class="fas fa-times-circle me-1"></i>Barcode tidak dikenali: ' + escHtml(val) + '</span>';
        return;
    }
    st.innerHTML = '<span style="color:#059669;"><i class="fas fa-check-circle me-1"></i>' + escHtml(found.nama_barang) + '</span>';
    hcFocusItem(found.id);
}

let hcModalBid = null;

function hcFocusItem(bid) {
    const uid = hcSelectedUid;
    if (!uid) return;
    // Add to nakes list if not already there
    if (!hcValidations[uid][bid]) {
        const item = soAllItems.find(it => it.id == bid);
        if (!item) return;
        const odooQ = hcOdooData[item.kode_barang] ?? null;
        hcValidations[uid][bid] = { item, nakes_qty: null, actual_qty: '', catatan: '', odoo_qty: odooQ !== null ? Number(odooQ) : null, dirty: true };
        hcRenderTable();
    }
    openHcModal(bid);
}

function openHcModal(bid) {
    if (soIsLocked || soNoPeriod) return;
    const uid = hcSelectedUid;
    if (!uid) return;
    const entry = hcValidations[uid]?.[bid];
    if (!entry) return;
    const item = entry.item;
    if (!item) return;
    hcModalBid = bid;

    document.getElementById('hcModalTitle').textContent = item.nama_barang;
    document.getElementById('hcModalKode').textContent  = item.kode_barang || '';
    const fromUom = item.satuan || '';
    const toUom   = item.uom || item.satuan || '';
    const mult    = parseFloat(item.multiplier || 0);
    // Admin inputs in to_uom (Btl) — same as klinik modal
    document.getElementById('hcModalUom').textContent = toUom;

    // Nakes reported in from_uom → convert to to_uom for display
    const nakesRaw = entry.nakes_qty !== null && entry.nakes_qty !== undefined ? parseFloat(entry.nakes_qty) : null;
    const nakesDisplay = nakesRaw !== null ? (mult > 1 ? parseFloat((nakesRaw / mult).toFixed(4)) : nakesRaw) : null;
    window._hcModalNakesQ = nakesDisplay;
    document.getElementById('hcModalNakesInfo').innerHTML =
        '<i class="fas fa-user-nurse me-2" style="color:#204EAB;"></i>Stok Nakes: <b>' + (nakesDisplay !== null ? nakesDisplay : '—') + '</b>' +
        (toUom ? ' ' + escHtml(toUom) : '');

    // Odoo HC not shown in modal

    // Konversi info (informational only)
    const konvEl = document.getElementById('hcModalKonvInfo');
    if (mult > 1 && fromUom && toUom && fromUom !== toUom) {
        document.getElementById('hcModalKonvText').textContent = '1 ' + toUom + ' = ' + mult + ' ' + fromUom;
        konvEl.style.display = '';
    } else {
        konvEl.style.display = 'none';
    }

    const actualInp = document.getElementById('hcModalActual');
    actualInp.value = entry.actual_qty !== '' && entry.actual_qty !== null ? entry.actual_qty : '';
    document.getElementById('hcModalCatatan').value = entry.catatan || '';

    const modalEl = document.getElementById('modalHcInput');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    modalEl.addEventListener('shown.bs.modal', () => actualInp.focus(), { once: true });
}

function hcModalSave() {
    const uid = hcSelectedUid;
    const bid = hcModalBid;
    if (!uid || !bid || !hcValidations[uid]?.[bid]) return;

    const val     = document.getElementById('hcModalActual').value;
    const catatan = document.getElementById('hcModalCatatan').value.trim();

    hcValidations[uid][bid].actual_qty = val === '' ? '' : parseFloat(val);
    hcValidations[uid][bid].catatan    = catatan;
    hcValidations[uid][bid].dirty      = true;
    hcUpdateRow(bid);

    bootstrap.Modal.getInstance(document.getElementById('modalHcInput'))?.hide();

    // Cancel pending debounce then auto-save immediately
    clearTimeout(_hcSaveTimer[bid]);
    hcAutoSaveItem(uid, bid);
}

async function hcAutoSaveItem(uid, bid) {
    const entry = hcValidations[uid]?.[bid];
    if (!entry) return;
    const hasActual  = entry.actual_qty !== '' && entry.actual_qty !== null;
    const hasCatatan = (entry.catatan || '').trim() !== '';
    if (!hasActual && !hasCatatan) return;
    try {
        await fetch(baseUrl + 'api/save_opname.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                _csrf:     csrfToken,
                klinik_id: lokId,
                is_gudang: isGudang,
                periode:   soPeriode,
                details: [{
                    barang_id:   entry.item.id,
                    tipe:        'hc',
                    hc_user_id:  uid,
                    stok_sistem: entry.odoo_qty ?? 0,
                    qty_aktual:  hasActual ? parseFloat(entry.actual_qty) : null,
                    ed_expired:  0,
                    ed_lte3m:    0,
                    ed_gt3m:     0,
                    catatan:     entry.catatan || '',
                }]
            })
        });
        // Item now persisted in DB — clear bag-reference flag
        if (hcValidations[uid]?.[bid]) {
            hcValidations[uid][bid]._from_bag = false;
        }
        // Hide banner if no more bag-pre-populated items
        const bagBanner = document.getElementById('hcBagBanner');
        if (bagBanner && uid === hcSelectedUid) {
            const hasBagRef = Object.values(hcValidations[uid] || {}).some(e => e._from_bag);
            bagBanner.style.display = hasBagRef ? 'flex' : 'none';
        }
        const st = document.getElementById('hcAutoSaveStatus');
        if (st) { st.style.display = ''; clearTimeout(st._t); st._t = setTimeout(() => { st.style.display = 'none'; }, 3000); }
    } catch(e) { /* silent */ }
}

function hcPositionDrop() {
    const inp  = document.getElementById('hcSearchInput');
    const drop = document.getElementById('hcSearchDrop');
    if (!inp || !drop) return;
    const r = inp.getBoundingClientRect();
    drop.style.top   = (r.bottom + 4) + 'px';
    drop.style.left  = r.left + 'px';
    drop.style.width = r.width + 'px';
}

function hcFilterSearch(q) {
    const drop = document.getElementById('hcSearchDrop');
    q = q.trim().toLowerCase();
    if (!q) { drop.style.display = 'none'; return; }
    const results = soAllItems.filter(it =>
        it.nama_barang.toLowerCase().includes(q) || (it.kode_barang || '').toLowerCase().includes(q)
    ).slice(0, 30);
    if (!results.length) {
        drop.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:.85rem;">Item tidak ditemukan</div>';
    } else {
        drop.innerHTML = results.map(it =>
            '<div onclick="event.stopPropagation();hcFocusItem(' + it.id + ');document.getElementById(\'hcSearchInput\').value=\'\';document.getElementById(\'hcSearchDrop\').style.display=\'none\';"' +
            ' onmouseenter="this.style.background=\'#f0f4ff\'" onmouseleave="this.style.background=\'\'"' +
            ' style="padding:10px 14px;font-size:.84rem;cursor:pointer;border-bottom:1px solid #f0f4ff;display:flex;justify-content:space-between;align-items:center;">' +
            '<span>' + escHtml(it.nama_barang) + '</span>' +
            '<span style="font-size:.72rem;color:#94a3b8;font-family:monospace;">' + escHtml(it.kode_barang || '') + '</span></div>'
        ).join('');
    }
    hcPositionDrop();
    drop.style.display = '';
}

function hcSearchKeydown(e) {
    if (e.key === 'Escape') { document.getElementById('hcSearchDrop').style.display = 'none'; return; }
    if (e.key === 'Enter') {
        const q = e.target.value.trim().toLowerCase();
        const found = soAllItems.find(it => it.nama_barang.toLowerCase() === q || (it.kode_barang || '').toLowerCase() === q)
                   || soAllItems.find(it => it.nama_barang.toLowerCase().includes(q));
        if (found) { hcFocusItem(found.id); e.target.value = ''; document.getElementById('hcSearchDrop').style.display = 'none'; }
    }
}

document.addEventListener('click', e => {
    if (!e.target.closest('#hcSearchInput') && !e.target.closest('#hcSearchDrop')) {
        const d = document.getElementById('hcSearchDrop');
        if (d) d.style.display = 'none';
    }
});

function hcFilterTable(q) {
    const q2 = q.trim().toLowerCase();
    document.querySelectorAll('#hcItemTbody tr').forEach(tr => {
        tr.style.display = !q2 || (tr.dataset.name || '').includes(q2) ? '' : 'none';
    });
}

const _hcSaveTimer = {};
function hcOnActualChange(bid, val) {
    const uid = hcSelectedUid;
    if (!uid || !hcValidations[uid][bid]) return;
    hcValidations[uid][bid].actual_qty = val === '' ? '' : parseFloat(val);
    hcValidations[uid][bid].dirty = true;
    hcUpdateRow(bid);
    // Debounced auto-save 1.5 detik setelah berhenti mengetik
    clearTimeout(_hcSaveTimer[bid]);
    _hcSaveTimer[bid] = setTimeout(() => hcAutoSaveItem(uid, bid), 1500);
}

function hcOnCatatanChange(bid, val) {
    const uid = hcSelectedUid;
    if (!uid || !hcValidations[uid][bid]) return;
    hcValidations[uid][bid].catatan = val;
    hcValidations[uid][bid].dirty = true;
    clearTimeout(_hcSaveTimer[bid]);
    _hcSaveTimer[bid] = setTimeout(() => hcAutoSaveItem(uid, bid), 1500);
}

async function hcDeleteItem(bid) {
    if (soIsLocked || soNoPeriod) return;
    const uid   = hcSelectedUid;
    const entry = hcValidations[uid]?.[bid];
    if (!entry) return;
    const nama = entry.item.nama_barang || '';
    const ok = await soConfirm(
        'Hapus <b>' + escHtml(nama) + '</b> dari daftar <b>' + escHtml(hcSelectedNama) + '</b>?',
        'Konfirmasi Hapus', '#dc2626'
    );
    if (!ok) return;

    // If item was only pre-populated from bag and never saved to DB, skip API call
    const neverPersisted = entry._from_bag && (entry.actual_qty === '' || entry.actual_qty === null);
    if (!neverPersisted) {
        try {
            const r = await fetch(baseUrl + 'api/delete_so_item.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken, klinik_id: lokId, is_gudang: isGudang,
                    barang_id: bid, periode: soPeriode,
                    scope: 'mine', hc_user_id: uid,
                }),
            });
            const d = await r.json();
            if (!d.success) { alert(d.message || 'Gagal menghapus item.'); return; }
        } catch(e) { alert('Error: ' + e.message); return; }
    }

    // Tandai sebagai sengaja dihapus agar self-heal di hcRenderTable tidak re-populate
    const bidInt = parseInt(bid);
    if (!hcBagDeletedBids[uid]) hcBagDeletedBids[uid] = new Set();
    hcBagDeletedBids[uid].add(bidInt);

    delete hcValidations[uid][bid];
    hcRenderTable();

    // Update bag banner
    const bagBanner = document.getElementById('hcBagBanner');
    if (bagBanner) {
        const hasBagRef = Object.values(hcValidations[uid] || {}).some(e => e._from_bag);
        bagBanner.style.display = hasBagRef ? 'flex' : 'none';
    }
    // Update pill badge
    const pill = document.getElementById('hc-pill-badge-' + uid);
    if (pill) {
        const cnt = Object.keys(hcValidations[uid] || {}).length;
        pill.textContent = cnt > 0 ? cnt + ' item' : 'belum lapor';
    }
}

function hcUpdateRow(bid) {
    const uid   = hcSelectedUid;
    const entry = hcValidations[uid]?.[bid];
    if (!entry) return;
    const actualQ = entry.actual_qty === '' || entry.actual_qty === null ? null : parseFloat(entry.actual_qty);
    // Sync table input values
    const actualInp = document.getElementById('hc-actual-' + bid);
    if (actualInp) actualInp.value = actualQ !== null ? actualQ : '';
    const catatanInp = document.getElementById('hc-catatan-' + bid);
    if (catatanInp) catatanInp.value = entry.catatan || '';
    // actualQ and odooQ are both in to_uom — direct comparison
    const odooQ   = entry.odoo_qty !== null && entry.odoo_qty !== undefined ? parseFloat(entry.odoo_qty) : null;
    const selOdoo = (actualQ !== null && odooQ !== null) ? parseFloat((actualQ - odooQ).toFixed(4)) : null;
    const selEl   = document.getElementById('hc-selisih-' + bid);
    const rowEl   = document.getElementById('hc-row-' + bid);
    if (rowEl) {
        let rowCls = '';
        if (actualQ !== null) rowCls = (selOdoo === null || selOdoo === 0) ? ' row-ok' : ' row-selisih';
        rowEl.className = 'so-main-row' + rowCls;
    }
}

// Bangun map: bid → [{uid, nama, nakes_qty, qty_aktual, catatan}] dari SEMUA nakes
function hcBuildCheckersByBid() {
    const nakesNameMap = {};
    hcUsersData.forEach(u => { nakesNameMap[u.id] = u.nama; });
    const map = {};
    Object.entries(hcValidations).forEach(([uid, items]) => {
        const nama = nakesNameMap[parseInt(uid)] || ('Nakes #' + uid);
        Object.entries(items).forEach(([bid, entry]) => {
            if (!map[bid]) map[bid] = [];
            map[bid].push({ uid: parseInt(uid), nama, nakes_qty: entry.nakes_qty, qty_aktual: entry.actual_qty, catatan: entry.catatan });
        });
    });
    return map;
}

function hcToggleHistory(bid) {
    const tr = document.getElementById('hc-hist-' + bid);
    if (tr) tr.style.display = tr.style.display === 'none' ? '' : 'none';
}

function hcRenderTable() {
    const uid    = hcSelectedUid;
    const tbody  = document.getElementById('hcItemTbody');
    const empty  = document.getElementById('hcTableEmpty');
    const wrap   = document.getElementById('hcTableWrap');
    const badge  = document.getElementById('hcItemBadge');
    const items  = hcValidations[uid] || {};
    let entries = Object.values(items);

    // Self-heal: tambahkan item dari tas yang belum ada di hcValidations (termasuk saat sebagian sudah disimpan)
    {
        const bagData = hcBagStocks[String(uid)] || hcBagStocks[uid] || {};
        const deleted = hcBagDeletedBids[uid] || new Set();
        const toRepop = Object.entries(bagData).filter(([bid]) =>
            !deleted.has(parseInt(bid)) && hcValidations[uid]?.[parseInt(bid)] === undefined
        );
        if (toRepop.length) {
            if (!hcValidations[uid]) hcValidations[uid] = {};
            toRepop.forEach(([bid, bagQty]) => {
                const bidInt = parseInt(bid);
                const item = soAllItems.find(it => it.id == bidInt);
                if (!item) return;
                const odooQ = hcOdooData[item.kode_barang] ?? null;
                hcValidations[uid][bidInt] = { item, nakes_qty: null, actual_qty: '', catatan: '',
                    odoo_qty: odooQ !== null ? Number(odooQ) : null, dirty: false,
                    _from_bag: true, _bag_qty: bagQty };
            });
            entries = Object.values(hcValidations[uid]);
        }
    }

    badge.textContent = entries.length + ' item';
    if (!entries.length) { empty.style.display=''; wrap.style.display='none'; return; }
    empty.style.display = 'none';
    wrap.style.display  = '';

    const filterQ = (document.getElementById('hcTableFilter')?.value || '').trim().toLowerCase();

    tbody.innerHTML = entries.map(entry => {
        const bid     = entry.item.id;
        // Display all quantities in to_uom (Btl) — nakes raw is in from_uom (mL), convert by /mult
        const uom     = escHtml(entry.item.uom || entry.item.satuan || '');
        const mult    = parseFloat(entry.item.multiplier || 0);
        const nakesRaw = entry.nakes_qty !== null && entry.nakes_qty !== undefined ? parseFloat(entry.nakes_qty) : null;
        const nakesQ  = (nakesRaw !== null && mult > 1) ? parseFloat((nakesRaw / mult).toFixed(4)) : nakesRaw;
        const actualQ = entry.actual_qty === '' ? null : (entry.actual_qty !== null ? parseFloat(entry.actual_qty) : null);
        // actualQ is already in to_uom (admin enters in to_uom)
        const nameL   = entry.item.nama_barang.toLowerCase();
        const hidden  = filterQ && !nameL.includes(filterQ) ? 'display:none;' : '';

        const odooQ   = entry.odoo_qty !== null && entry.odoo_qty !== undefined ? parseFloat(entry.odoo_qty) : null;
        const selOdoo2 = (actualQ !== null && odooQ !== null) ? parseFloat((actualQ - odooQ).toFixed(4)) : null;
        const rowCls  = actualQ !== null ? ((selOdoo2 === null || selOdoo2 === 0) ? 'row-ok' : 'row-selisih') : '';
        const sesuaiBg  = '#f1f5f9';
        const sesuaiClr = '#64748b';
        const sesuaiBrd = '1px solid #e2e8f0';
        const nakesDisplay = (nakesQ !== null
            ? '<b>' + nakesQ + '</b> <span style="font-size:.7rem;color:#94a3b8;">' + uom + '</span>'
            : '<span style="color:#cbd5e1;">belum lapor</span>');

        // Checker = current logged-in user (admin verifier), only when actual_qty is set
        let checkerBadge = '';
        let historyRow   = '';
        if (actualQ !== null) {
            checkerBadge =
                '<button onclick="hcToggleHistory(' + bid + ')" style="background:none;border:none;padding:0;cursor:pointer;" title="Lihat checker">' +
                '<span style="background:#e0e7ff;border:1px solid #c7d2fe;border-radius:6px;padding:1px 7px;font-size:.7rem;font-weight:600;color:#4338ca;">' +
                '<i class="fas fa-user-check" style="font-size:.6rem;"></i> 1 checker</span></button>';
            historyRow =
                '<tr id="hc-hist-' + bid + '" style="display:none;background:#f8faff;">' +
                '<td colspan="5" style="padding:6px 16px 10px;">' +
                '<div style="font-size:.75rem;font-weight:600;color:#6366f1;margin-bottom:5px;"><i class="fas fa-history me-1"></i>History Checker</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:6px;">' +
                '<div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:5px 10px;font-size:.78rem;">' +
                '<span style="font-weight:700;color:#6d28d9;"><i class="fas fa-user me-1" style="font-size:.65rem;"></i>' +
                escHtml(soCurrentUserName) + ' <span style="opacity:.6;">(Saya)</span></span>' +
                '<span style="margin-left:6px;color:#374151;">&rarr; <b>' + actualQ + '</b> ' + uom + '</span>' +
                (entry.catatan ? '<span style="margin-left:6px;color:#94a3b8;">· ' + escHtml(entry.catatan) + '</span>' : '') +
                '</div></div></td></tr>';
        }

        return '<tr id="hc-row-' + bid + '" class="so-main-row ' + rowCls + '" data-name="' + nameL + '" style="' + hidden + '">' +
            '<td><div class="fw-semibold" style="font-size:.85rem;">' + escHtml(entry.item.nama_barang) + '</div>' +
            '<div style="font-size:.7rem;color:#94a3b8;font-family:monospace;">' + escHtml(entry.item.kode_barang || '') + '</div>' +
            '</td>' +
            '<td class="text-center">' + nakesDisplay + '</td>' +
            '<td class="text-center">' +
              '<div class="d-flex align-items-center justify-content-center gap-1">' +
              '<input type="number" id="hc-actual-' + bid + '" min="0" step="1"' +
              ' value="' + (actualQ !== null ? actualQ : '') + '"' +
              ((soIsLocked || soNoPeriod) ? ' disabled' : ' oninput="hcOnActualChange(' + bid + ',this.value)"') +
              ' class="form-control text-center fw-bold"' +
              ' style="width:80px;border-radius:7px;font-size:.88rem;border:2px solid #c7d8ff;padding:4px;' + ((soIsLocked || soNoPeriod) ? 'opacity:.6;' : '') + '">' +
              '<span style="font-size:.7rem;color:#94a3b8;">' + uom + '</span>' +
              '</div></td>' +
            '<td><input type="text" id="hc-catatan-' + bid + '" value="' + escHtml(entry.catatan || '') + '"' +
              ((soIsLocked || soNoPeriod) ? ' disabled' : ' oninput="hcOnCatatanChange(' + bid + ',this.value)"') +
              ' placeholder="Catatan..."' +
              ' style="border:1.5px solid #e2e8f0;border-radius:7px;font-size:.78rem;padding:4px 8px;width:100%;outline:none;' + ((soIsLocked || soNoPeriod) ? 'opacity:.6;' : '') + '"' +
              ((soIsLocked || soNoPeriod) ? '' : ' onfocus="this.style.borderColor=\'#204EAB\'" onblur="this.style.borderColor=\'#e2e8f0\'"') + '></td>' +
            '<td class="text-center"><div style="display:flex;flex-direction:column;align-items:center;gap:5px;">' +
              checkerBadge +
              ((soIsLocked || soNoPeriod)
                ? '<span style="font-size:.72rem;color:#94a3b8;"><i class="fas fa-lock me-1"></i>Terkunci</span>'
                : '<div style="display:flex;gap:5px;margin-top:2px;">' +
                  '<button onclick="openHcModal(' + bid + ')" class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:6px;padding:4px 8px;line-height:1;"><i class="fas fa-pen" style="font-size:.75rem;"></i></button>' +
                  '<button onclick="hcDeleteItem(' + bid + ')" class="btn btn-sm btn-outline-danger" title="Hapus" style="border-radius:6px;padding:4px 8px;line-height:1;"><i class="fas fa-trash" style="font-size:.75rem;"></i></button>' +
                  '</div>') +
            '</div></td></tr>' + historyRow;
    }).join('');
}

async function hcSaveAll() {
    if (soIsHistorical) { await soAlert(`Sedang melihat riwayat SO ${soPeriodeLabel} (mode lihat saja). Validasi tidak dapat dilakukan.`); return; }
    if (soIsLocked) { await soAlert(`SO ${soPeriodeLabel} terkunci. Validasi tidak dapat dilakukan.`); return; }
    if (soNoPeriod) { await soAlert(`SO ${soPeriodeLabel} belum dibuka.`); return; }
    const uid = hcSelectedUid;
    if (!uid) return;
    const entries = Object.values(hcValidations[uid] || {});
    if (!entries.length) { await soAlert('Tidak ada item untuk disimpan.'); return; }

    const details = entries.map(entry => {
        const adminQty = entry.actual_qty !== '' && entry.actual_qty !== null ? parseFloat(entry.actual_qty) : null;
        return {
            barang_id:   entry.item.id,
            tipe:        'hc',
            hc_user_id:  uid,
            stok_sistem: entry.odoo_qty ?? 0,
            qty_aktual:  adminQty,
            // qty_fisik sengaja tidak dikirim agar laporan nakes tidak tertimpa
            ed_expired:  0,
            ed_lte3m:    0,
            ed_gt3m:     0,
            catatan:     entry.catatan || '',
        };
    });

    const btn = document.getElementById('hcSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menyimpan...';

    try {
        const res  = await fetch(baseUrl + 'api/save_opname.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf: csrfToken, klinik_id: lokId, is_gudang: isGudang, periode: soPeriode, details })
        });
        const data = await res.json();
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Tersimpan!';
            btn.style.background = 'rgba(5,150,105,.4)';
            // Mark all as clean & update pill badge
            Object.values(hcValidations[uid]).forEach(e => { e.dirty = false; });
            const pillBadge = document.getElementById('hc-pill-badge-' + uid);
            if (pillBadge) pillBadge.textContent = details.length + ' item';
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Validasi';
                btn.style.background = 'rgba(255,255,255,.2)';
            }, 2500);
        } else {
            await soAlert('Gagal: ' + (data.message || 'Error'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Validasi';
        }
    } catch(e) {
        await soAlert('Koneksi gagal.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Validasi';
    }
}

</script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<?php endif; ?>

<!-- ── E-Catalog Barcode Modal (tersedia di halaman picker & dashboard) ────── -->
<div id="barcodeCatalogModal" style="display:none;position:fixed;inset:0;z-index:9990;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding:20px 12px;overflow-y:auto;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:780px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.18);display:flex;flex-direction:column;max-height:90vh;">
    <!-- Header -->
    <div style="background:#204EAB;color:#fff;padding:16px 20px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:1rem;">
        <i class="fas fa-barcode"></i> E-Catalog Barcode
        <span id="bcCatCount" style="font-size:.8rem;font-weight:400;opacity:.8;"></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button onclick="openBcCatPrintOpt()" title="Cetak sebagai buku katalog"
          style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:.8rem;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-book"></i><span>Cetak Katalog</span>
        </button>
        <button onclick="closeBarcodeCatalog()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:.85rem;"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <!-- Search -->
    <div style="padding:14px 16px;border-bottom:1px solid #e8edf5;flex-shrink:0;">
      <div id="bcCatSearchWrap" style="position:relative;">
        <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;"></i>
        <input type="text" id="bcCatSearch" placeholder="Cari nama atau kode barang..." autocomplete="off"
          oninput="bcCatFilter(this.value); bcCatSuggest(this.value)"
          onfocus="this.style.borderColor='#204EAB'; bcCatSuggest(this.value)"
          onblur="this.style.borderColor='#e2e8f0'"
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:8px 12px 8px 34px;font-size:.88rem;outline:none;">
        <div id="bcCatSuggest" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:9px;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:20;"></div>
      </div>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:#64748b;cursor:pointer;">
          <input type="checkbox" id="bcCatOnlyBarcode" onchange="bcCatFilter(document.getElementById('bcCatSearch').value)"> Hanya yang punya barcode
        </label>
      </div>
    </div>
    <!-- List -->
    <div id="bcCatList" style="flex:1;overflow-y:auto;padding:12px 16px;display:grid;grid-template-columns:1fr;gap:10px;">
      <div style="text-align:center;color:#94a3b8;padding:40px;"><i class="fas fa-spinner fa-spin fa-2x"></i><br><span style="font-size:.85rem;margin-top:8px;display:block;">Memuat katalog...</span></div>
    </div>
  </div>
</div>

<!-- ── Pilih Kategori untuk Cetak Katalog ─────────────────────────────────── -->
<div id="bcCatPrintOptModal" style="display:none;position:fixed;inset:0;z-index:9995;background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:420px;box-shadow:0 8px 40px rgba(0,0,0,.2);overflow:hidden;">
    <div style="background:#204EAB;color:#fff;padding:14px 18px;font-weight:700;display:flex;align-items:center;justify-content:space-between;">
      <span><i class="fas fa-book me-2"></i>Cetak Katalog Barcode</span>
      <button onclick="closeBcCatPrintOpt()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:4px 9px;cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:18px;">
      <div style="font-size:.82rem;color:#64748b;margin-bottom:10px;">Pilih kategori label yang ingin dicetak (bisa lebih dari satu):</div>
      <div id="bcCatPrintOptList" style="display:flex;flex-direction:column;gap:8px;"></div>
      <div style="font-size:.72rem;color:#94a3b8;margin-top:10px;">Hanya item yang punya barcode internal yang bisa dicetak. Pencarian yang sedang aktif di list juga ikut jadi filter.</div>
    </div>
    <div style="padding:14px 18px;border-top:1px solid #eef2f7;display:flex;justify-content:flex-end;gap:8px;">
      <button onclick="closeBcCatPrintOpt()" style="background:#f1f5f9;border:none;color:#475569;border-radius:8px;padding:8px 16px;font-size:.85rem;font-weight:600;cursor:pointer;">Batal</button>
      <button onclick="printBarcodeCatalog()" style="background:#204EAB;border:none;color:#fff;border-radius:8px;padding:8px 18px;font-size:.85rem;font-weight:700;cursor:pointer;"><i class="fas fa-print me-1"></i>Cetak Sekarang</button>
    </div>
  </div>
</div>

<script>
// escHtml sudah dideklarasikan di script block di atas (baris ~1490) — function declaration
// otomatis jadi global, jadi tinggal reuse langsung di sini tanpa perlu didefinisikan ulang.

// ── E-Catalog Barcode ─────────────────────────────────────────────────────────
let _bcCatData = null;

async function openBarcodeCatalog() {
    const modal = document.getElementById('barcodeCatalogModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Load qrcode-generator jika belum ada
    if (typeof qrcode !== 'function') {
        try {
            await new Promise((res, rej) => {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js';
                s.onload = res; s.onerror = rej;
                document.head.appendChild(s);
            });
        } catch(e) {
            console.warn('QR library gagal dimuat, catalog tetap tampil tanpa QR.');
        }
    }
    if (_bcCatData) { bcCatRender(_bcCatData, document.getElementById('bcCatSearch').value); return; }
    try {
        const res  = await fetch(baseUrl + 'api/get_barcode_catalog.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.message);
        _bcCatData = data.items;
        document.getElementById('bcCatCount').textContent = '(' + _bcCatData.length + ' item)';
        bcCatRender(_bcCatData, '');
    } catch(e) {
        document.getElementById('bcCatList').innerHTML =
            '<div style="text-align:center;color:#ef4444;padding:30px;">Gagal memuat: ' + escHtml(e.message) + '</div>';
    }
}

function closeBarcodeCatalog() {
    document.getElementById('barcodeCatalogModal').style.display = 'none';
    document.body.style.overflow = '';
}

function bcCatFilter(q) {
    if (!_bcCatData) return;
    bcCatRender(_bcCatData, q);
}

function bcCatSuggest(q) {
    const box = document.getElementById('bcCatSuggest');
    const val = (q || '').trim().toLowerCase();
    if (!_bcCatData || !val) { box.style.display = 'none'; box.innerHTML = ''; return; }
    const matches = _bcCatData.filter(it =>
        it.nama_barang.toLowerCase().includes(val)
        || it.kode_barang.toLowerCase().includes(val)
        || (it.barcode_internal || '').toLowerCase().includes(val)
    ).slice(0, 8);
    if (!matches.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = matches.map(it => {
        const nameEsc = escHtml(it.nama_barang);
        return '<div onclick="bcCatPickSuggest(\'' + nameEsc.replace(/'/g, "\\'") + '\')" ' +
            'style="padding:8px 12px;cursor:pointer;font-size:.85rem;color:#1e293b;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:8px;" ' +
            'onmouseover="this.style.background=\'#f0f4ff\'" onmouseout="this.style.background=\'\'">' +
            '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + nameEsc + '</span>' +
            '<span style="color:#94a3b8;font-size:.75rem;flex-shrink:0;">' + escHtml(it.kode_barang) + '</span>' +
            '</div>';
    }).join('');
    box.style.display = 'block';
}

function bcCatPickSuggest(name) {
    const input = document.getElementById('bcCatSearch');
    input.value = name;
    bcCatFilter(name);
    document.getElementById('bcCatSuggest').style.display = 'none';
}

document.addEventListener('click', e => {
    if (!e.target.closest('#bcCatSearchWrap')) {
        const d = document.getElementById('bcCatSuggest');
        if (d) d.style.display = 'none';
    }
});

document.getElementById('bcCatPrintOptModal').addEventListener('click', function(e) {
    if (e.target === this) closeBcCatPrintOpt();
});

function bcCatRender(items, q) {
    const search   = (q || '').trim().toLowerCase();
    const onlyBC   = document.getElementById('bcCatOnlyBarcode')?.checked;
    const filtered = items.filter(it => {
        if (onlyBC && !it.barcode_internal) return false;
        if (!search) return true;
        return it.nama_barang.toLowerCase().includes(search)
            || it.kode_barang.toLowerCase().includes(search)
            || (it.barcode_internal || '').toLowerCase().includes(search);
    });

    const list = document.getElementById('bcCatList');
    if (!filtered.length) {
        list.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;font-size:.88rem;grid-column:1/-1;">Tidak ada item ditemukan</div>';
        return;
    }

    list.innerHTML = filtered.map(it => {
        const bc = it.barcode_internal;
        if (bc) {
            return '<div class="bc-card bc-has-barcode">' +
                '<div class="bc-qr-target" id="bcqr-' + it.id + '" data-value="' + escHtml(bc) + '"></div>' +
                '<div style="flex:1;min-width:0;">' +
                  '<div class="bc-label-code">' + escHtml(bc) + '</div>' +
                  '<div class="bc-label-name">' + escHtml(it.nama_barang) + '</div>' +
                  '<div class="bc-label-meta">kode : <span style="color:#374151;">' + escHtml(it.kode_barang) + '</span></div>' +
                  (it.satuan ? '<div class="bc-label-meta"><b>UoM : ' + escHtml(it.satuan) + '</b></div>' : '') +
                  '<button class="bc-copy-btn" onclick="bcCopyBarcode(this,\'' + escHtml(bc) + '\')">' +
                  '<i class="fas fa-copy"></i> Copy</button>' +
                '</div>' +
                '</div>';
        } else {
            return '<div class="bc-card" style="opacity:.55;">' +
                '<div style="flex-shrink:0;width:100px;height:100px;background:#f8faff;border-radius:6px;display:flex;align-items:center;justify-content:center;">' +
                  '<i class="fas fa-barcode" style="font-size:2rem;color:#cbd5e1;"></i></div>' +
                '<div style="flex:1;min-width:0;">' +
                  '<div class="bc-label-name">' + escHtml(it.nama_barang) + '</div>' +
                  '<div class="bc-label-meta">kode : ' + escHtml(it.kode_barang) + '</div>' +
                  (it.satuan ? '<div class="bc-label-meta"><b>UoM : ' + escHtml(it.satuan) + '</b></div>' : '') +
                  '<div style="font-size:.72rem;color:#cbd5e1;font-style:italic;margin-top:4px;">Belum ada barcode internal</div>' +
                '</div>' +
                '</div>';
        }
    }).join('');

    // Render QR codes sebagai SVG inline
    if (typeof qrcode === 'function') {
        list.querySelectorAll('.bc-qr-target[data-value]').forEach(el => {
            try {
                const qr = qrcode(0, 'M');
                qr.addData(el.dataset.value);
                qr.make();
                el.innerHTML = qr.createSvgTag(3, 0);
                const svg = el.querySelector('svg');
                if (svg) { svg.style.cssText = 'width:100px;height:100px;border-radius:6px;display:block;'; }
            } catch(e) {
                el.innerHTML = '<div style="width:100px;height:100px;background:#f8faff;border-radius:6px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-barcode" style="color:#cbd5e1;font-size:2rem;"></i></div>';
            }
        });
    }
}

function bcCopyBarcode(el, code) {
    navigator.clipboard.writeText(code).then(() => {
        const orig = el.innerHTML;
        el.innerHTML = '<i class="fas fa-check"></i> Copied!';
        el.style.background = '#d1fae5'; el.style.color = '#065f46';
        setTimeout(() => { el.innerHTML = orig; el.style.background = ''; el.style.color = ''; }, 1200);
    }).catch(() => { prompt('Copy barcode:', code); });
}

const BC_CAT_LABELS = {
    A: 'Category A — Sistem Saja',
    B: 'Category B — Cetak Fisik (Item)',
    C: 'Category C — Cetak Fisik (Box)',
    D: 'Category D — Cetak Fisik (Katalog)',
    '': 'Belum Dikategorikan'
};

function openBcCatPrintOpt() {
    if (!_bcCatData) { alert('Data katalog belum dimuat.'); return; }
    const counts = {};
    _bcCatData.forEach(it => {
        if (!it.barcode_internal) return;
        const key = it.kategori_label || '';
        counts[key] = (counts[key] || 0) + 1;
    });
    const list = document.getElementById('bcCatPrintOptList');
    list.innerHTML = Object.keys(BC_CAT_LABELS).map(key => {
        const n = counts[key] || 0;
        const id = 'bcCatPrintOpt_' + (key || 'none');
        return '<label for="' + id + '" style="display:flex;align-items:center;gap:9px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;font-size:.85rem;' + (n === 0 ? 'opacity:.5;' : '') + '">' +
            '<input type="checkbox" id="' + id + '" value="' + key + '" ' + (n > 0 ? 'checked' : '') + ' ' + (n === 0 ? 'disabled' : '') + ' style="width:16px;height:16px;">' +
            '<span style="flex:1;">' + BC_CAT_LABELS[key] + '</span>' +
            '<span style="color:#94a3b8;font-size:.75rem;">' + n + ' item</span>' +
            '</label>';
    }).join('');
    document.getElementById('bcCatPrintOptModal').style.display = 'flex';
}

function closeBcCatPrintOpt() {
    document.getElementById('bcCatPrintOptModal').style.display = 'none';
}

function printBarcodeCatalog() {
    if (!_bcCatData) { alert('Data katalog belum dimuat.'); return; }
    const checkedCats = Array.from(document.querySelectorAll('#bcCatPrintOptList input[type=checkbox]:checked')).map(el => el.value);
    if (!checkedCats.length) { alert('Pilih minimal satu kategori untuk dicetak.'); return; }
    const search = (document.getElementById('bcCatSearch')?.value || '').trim().toLowerCase();
    const items = _bcCatData.filter(it => {
        if (!it.barcode_internal) return false;
        if (!checkedCats.includes(it.kategori_label || '')) return false;
        if (!search) return true;
        return it.nama_barang.toLowerCase().includes(search)
            || it.kode_barang.toLowerCase().includes(search)
            || it.barcode_internal.toLowerCase().includes(search);
    });
    if (!items.length) { alert('Tidak ada item dengan barcode untuk dicetak.'); return; }
    closeBcCatPrintOpt();

    const PER_PAGE = 6;
    const pages = [];
    for (let i = 0; i < items.length; i += PER_PAGE) pages.push(items.slice(i, i + PER_PAGE));
    const todayStr = new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

    const pagesHtml = pages.map((chunk, pi) => {
        const cards = chunk.map(it => `
            <div class="cat-card">
                <div class="code">${escHtml(it.barcode_internal)}</div>
                <div class="qr" data-value="${escHtml(it.barcode_internal)}"></div>
                <div class="name">${escHtml(it.nama_barang)}</div>
                <div class="meta">kode : ${escHtml(it.kode_barang)}</div>
                ${it.satuan ? `<div class="meta"><b>UoM : ${escHtml(it.satuan)}</b></div>` : ''}
            </div>`).join('');
        const fillerCount = PER_PAGE - chunk.length;
        const fillers = fillerCount > 0 ? '<div class="cat-card cat-card-empty"></div>'.repeat(fillerCount) : '';
        return `
        <div class="catalog-page">
            <div class="cat-header">
                <div>
                    <div class="cat-title">KATALOG BARCODE</div>
                    <div class="cat-sub">Update : ${todayStr}</div>
                </div>
                <div class="cat-brand-box">
                    <div class="cat-brand">BUMAME</div>
                    <div class="cat-brand-sub">Inventory System</div>
                </div>
            </div>
            <div class="cat-grid">${cards}${fillers}</div>
            <div class="cat-footer">${pi + 1}</div>
        </div>`;
    }).join('');

    const html = `<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>Katalog Barcode - Bumame</title>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"><\/script>
<style>
@page { size: A5 portrait; margin: 9mm 8mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body { font-family: Arial, sans-serif; color:#1e293b; }
.catalog-page { page-break-after: always; display:flex; flex-direction:column; height: 100vh; }
.catalog-page:last-child { page-break-after: auto; }
.cat-header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:3mm; border-bottom:1.2px solid #204EAB; }
.cat-title { font-size:10pt; font-weight:800; color:#204EAB; letter-spacing:.3px; }
.cat-sub { font-size:6.5pt; color:#94a3b8; margin-top:.5mm; }
.cat-brand-box { text-align:right; }
.cat-brand { font-size:10pt; font-weight:800; color:#204EAB; letter-spacing:.6px; }
.cat-brand-sub { font-size:6.5pt; color:#94a3b8; margin-top:.5mm; }
.cat-grid { flex:1; display:grid; grid-template-columns:1fr 1fr; grid-template-rows:repeat(3,1fr); gap:4mm; padding:4mm 1mm; }
.cat-card { border:1px dashed #c7d8ff; border-radius:8px; padding:3mm 2mm; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
.cat-card-empty { border-color:transparent; }
.cat-card .code { color:#204EAB; font-weight:800; font-size:8pt; margin-bottom:1.5mm; letter-spacing:.3px; }
.cat-card .qr { width:22mm; height:22mm; margin-bottom:1.5mm; }
.cat-card .qr svg { width:100%; height:100%; display:block; }
.cat-card .name { font-size:6.8pt; font-weight:600; margin-bottom:1mm; line-height:1.25; }
.cat-card .meta { font-size:6.2pt; color:#94a3b8; margin-top:.3mm; }
.cat-card .meta b { color:#374151; }
.cat-footer { text-align:center; padding-top:2.5mm; border-top:1px solid #e2e8f0; font-size:7.5pt; color:#94a3b8; font-weight:600; }
</style>
</head><body>
${pagesHtml}
<script>
window.addEventListener('load', function() {
    document.querySelectorAll('.qr[data-value]').forEach(function(el) {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(el.dataset.value);
            qr.make();
            el.innerHTML = qr.createSvgTag(3, 0);
        } catch (e) {}
    });
    setTimeout(function() { window.print(); }, 300);
});
<\/script>
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) { alert('Popup diblokir browser. Izinkan popup untuk mencetak katalog.'); return; }
    win.document.write(html);
    win.document.close();
}

// Tutup modal saat klik backdrop
document.getElementById('barcodeCatalogModal').addEventListener('click', function(e) {
    if (e.target === this) closeBarcodeCatalog();
});
</script>
