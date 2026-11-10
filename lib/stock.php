<?php
function stock_resolve_location(mysqli $conn, string $code): string {
    $code = trim($code);
    if ($code === '') return '';
    $esc = $conn->real_escape_string($code);
    $r = $conn->query("SELECT 1 FROM inventory_stock_mirror WHERE TRIM(location_code) = '$esc' LIMIT 1");
    if ($r && $r->num_rows > 0) return $code;
    $cand = [$code . '/Stock', $code . '-Stock', $code . ' Stock'];
    foreach ($cand as $c) {
        $e = $conn->real_escape_string($c);
        $r = $conn->query("SELECT 1 FROM inventory_stock_mirror WHERE TRIM(location_code) = '$e' LIMIT 1");
        if ($r && $r->num_rows > 0) return $c;
    }
    return $code;
}

function stock_multiplier(mysqli $conn, int $barang_id): float {
    $barang_id = (int)$barang_id;
    $r = $conn->query("
        SELECT c.multiplier 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $barang_id 
        LIMIT 1
    ");
    $m = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['multiplier'] ?? 1) : 1);
    if ($m <= 0) $m = 1;
    return $m;
}

function stock_match_clause(mysqli $conn, array $barang_row): string {
    $clauses = [];
    $kode_barang = trim((string)($barang_row['kode_barang'] ?? ''));
    $odoo_product_id = trim((string)($barang_row['odoo_product_id'] ?? ''));
    if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kode_barang) . "'";
    if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($odoo_product_id) . "'";
    if (empty($clauses)) return "(1=0)";
    return '(' . implode(' OR ', $clauses) . ')';
}

function stock_last_update(mysqli $conn, string $location_code): string {
    $loc = $conn->real_escape_string(trim($location_code));
    $r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc'");
    $res = (string)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['last_update'] ?? '') : '');
    
    // If mirror is empty, try global sync setting
    if ($res === '') {
        $r2 = $conn->query("SELECT v FROM inventory_app_settings WHERE k = 'odoo_sync_last_run' LIMIT 1");
        if ($r2 && ($gs = $r2->fetch_assoc())) {
            $res = date('Y-m-d H:i:s', (int)$gs['v']);
        }
    }
    return $res;
}

function stock_mirror_qty(mysqli $conn, string $location_code, string $match_clause, float $multiplier): float {
    $loc = $conn->real_escape_string(trim($location_code));
    $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc' AND $match_clause");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    if ($multiplier <= 0) $multiplier = 1;
    return $q / $multiplier;
}

function stock_pending_transaksi(mysqli $conn, string $level, int $level_id, int $barang_id, string $last_update, array $referensi_tipe): array {
    $level_id = (int)$level_id;
    $barang_id = (int)$barang_id;
    if ($last_update === '') return ['in' => 0.0, 'out' => 0.0];
    $lu = $conn->real_escape_string($last_update);
    $level_esc = $conn->real_escape_string($level);
    $types = array_values(array_filter(array_map('strval', $referensi_tipe)));
    if (empty($types)) return ['in' => 0.0, 'out' => 0.0];
    $type_list = implode(',', array_map(function($t) use ($conn) { return "'" . $conn->real_escape_string($t) . "'"; }, $types));
    $r = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN tipe_transaksi='in' THEN qty ELSE 0 END),0) AS qty_in,
            COALESCE(SUM(CASE WHEN tipe_transaksi='out' THEN qty ELSE 0 END),0) AS qty_out
        FROM inventory_transaksi_stok
        WHERE level = '$level_esc'
          AND level_id = $level_id
          AND barang_id = $barang_id
          AND created_at > '$lu'
          AND referensi_tipe IN ($type_list)
    ");
    $row = $r && $r->num_rows > 0 ? $r->fetch_assoc() : [];
    return ['in' => (float)($row['qty_in'] ?? 0), 'out' => (float)($row['qty_out'] ?? 0)];
}

function stock_sellout_qty(mysqli $conn, int $klinik_id, int $barang_id, string $last_update, bool $is_hc): float {
    $klinik_id = (int)$klinik_id;
    $barang_id = (int)$barang_id;
    
    // Per user feedback: If mirror was updated (e.g., 19 Apr), any pemakaian created BEFORE that date
    // (e.g., 16 Apr) should be ignored because they are already accounted for in the Odoo baseline stock.
    // We only count "pending" sellout created AFTER the last mirror sync.
    if ($last_update === '') {
        $lu = '2000-01-01 00:00:00';
    } else {
        $lu = $conn->real_escape_string($last_update);
    }
    
    $jenis_cond = $is_hc ? "pb.jenis_pemakaian = 'hc'" : "pb.jenis_pemakaian <> 'hc'";
    
    // Sum deltas from active records created AFTER the last mirror update.
    $r = $conn->query("
        SELECT COALESCE(SUM(pbd.qty),0) AS qty
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
        WHERE pb.klinik_id = $klinik_id
          AND $jenis_cond
          AND pb.created_at > '$lu'
          AND pbd.barang_id = $barang_id
          AND pb.status = 'active'
    ");
    return (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
}

function stock_reserve_qty(mysqli $conn, int $klinik_id, int $barang_id, string $last_update, bool $is_hc): float {
    $klinik_id = (int)$klinik_id;
    $barang_id = (int)$barang_id;
    $today = $conn->real_escape_string(date('Y-m-d'));
    $reserve_cond = $is_hc ? "bp.status_booking LIKE '%HC%'" : "bp.status_booking LIKE '%Clinic%'";
    $field = $is_hc ? "CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END" : "CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END";
    $r = $conn->query("
        SELECT COALESCE(SUM($field), 0) AS qty
        FROM inventory_booking_detail bd
        JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
        WHERE bp.klinik_id = $klinik_id
          AND bp.status IN ('booked', 'rescheduled', 'pending_edit')
          AND $reserve_cond
          AND bp.tanggal_pemeriksaan >= '$today'
          AND bd.barang_id = $barang_id
    ");
    return (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
}

/**
 * Versi batch dari stock_effective() — hitung stok efektif untuk BANYAK barang sekaligus
 * di 1 klinik/HC, lewat query agregat (GROUP BY barang_id) alih-alih memanggil
 * stock_effective() satu-per-satu per barang (yang tiap panggilannya ~9 query).
 *
 * Formula & urutan logikanya HARUS identik dengan stock_effective() — ini cuma versi
 * "banyak sekaligus" dari fungsi yang sama, dipakai di tempat yang butuh hitung banyak
 * barang dalam 1 request (mis. pencarian item Input BHP), BUKAN pengganti stock_effective()
 * itu sendiri (yang tetap dipakai apa adanya di tempat-tempat single-item lain).
 *
 * @return array barang_id => hasil sama persis dgn stock_effective()
 */
function stock_effective_batch(mysqli $conn, int $klinik_id, bool $is_hc, array $barang_ids): array {
    $klinik_id = (int)$klinik_id;
    $barang_ids = array_values(array_unique(array_map('intval', $barang_ids)));
    $barang_ids = array_filter($barang_ids, fn($v) => $v > 0);
    if (empty($barang_ids)) return [];
    $ids_str = implode(',', $barang_ids);

    $loc = '';
    $level = 'klinik';
    if ($klinik_id === 0 && !$is_hc) {
        $level = 'gudang_utama';
        if (function_exists('get_setting')) {
            $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
            if ($gudang_loc !== '') $loc = stock_resolve_location($conn, $gudang_loc);
        }
    } else {
        $kl = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
        $kode_klinik = trim((string)($kl['kode_klinik'] ?? ''));
        $kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
        $loc = $is_hc ? stock_resolve_location($conn, $kode_homecare) : stock_resolve_location($conn, $kode_klinik);
        $level = $is_hc ? 'hc' : 'klinik';
    }

    if ($loc === '') {
        $out = [];
        foreach ($barang_ids as $bid) $out[$bid] = ['ok' => false, 'message' => 'Kode lokasi belum diisi', 'available' => 0, 'on_hand' => 0];
        return $out;
    }

    $res_b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang WHERE id IN ($ids_str)");
    $barang_map = [];
    while ($row = $res_b->fetch_assoc()) $barang_map[(int)$row['id']] = $row;

    // kode_barang dijamin unik di inventory_barang_uom_conversion (constraint uniq_kode_barang),
    // jadi tidak ada ambiguitas "baris mana yang kepakai" seperti kalau ada duplikat.
    $mult_map = [];
    $res_mult = $conn->query("
        SELECT b.id AS barang_id, c.multiplier
        FROM inventory_barang b
        JOIN inventory_barang_uom_conversion c ON c.kode_barang = b.kode_barang
        WHERE b.id IN ($ids_str)
    ");
    while ($row = $res_mult->fetch_assoc()) {
        $m = (float)($row['multiplier'] ?? 1);
        if ($m <= 0) $m = 1;
        $mult_map[(int)$row['barang_id']] = $m;
    }

    $last_update = stock_last_update($conn, $loc);
    $loc_esc = $conn->real_escape_string(trim($loc));

    // Baseline (mirror qty) per barang — replikasi persis stock_match_clause(): cocokkan
    // by kode_barang ATAU odoo_product_id (skip kalau kolom itu kosong di barang-nya).
    $baseline_map = [];
    $res_baseline = $conn->query("
        SELECT b.id AS barang_id, MAX(sm.qty) AS qty
        FROM inventory_barang b
        JOIN inventory_stock_mirror sm
          ON TRIM(sm.location_code) = '$loc_esc'
         AND (
               (b.kode_barang <> '' AND TRIM(sm.kode_barang) = TRIM(b.kode_barang))
            OR (b.odoo_product_id <> '' AND TRIM(sm.odoo_product_id) = TRIM(b.odoo_product_id))
         )
        WHERE b.id IN ($ids_str)
        GROUP BY b.id
    ");
    while ($row = $res_baseline->fetch_assoc()) $baseline_map[(int)$row['barang_id']] = (float)($row['qty'] ?? 0);

    $pending_map = [];
    if ($last_update !== '') {
        $lu_esc = $conn->real_escape_string($last_update);
        $level_esc = $conn->real_escape_string($level);
        $types = $level === 'hc' ? ['hc_petugas_transfer', 'pemakaian_bhp_revision'] : ['transfer', 'hc_petugas_transfer', 'pemakaian_bhp_revision'];
        $type_list = implode(',', array_map(fn($t) => "'" . $conn->real_escape_string($t) . "'", $types));
        $res_pending = $conn->query("
            SELECT barang_id,
                COALESCE(SUM(CASE WHEN tipe_transaksi='in' THEN qty ELSE 0 END),0) AS qty_in,
                COALESCE(SUM(CASE WHEN tipe_transaksi='out' THEN qty ELSE 0 END),0) AS qty_out
            FROM inventory_transaksi_stok
            WHERE level = '$level_esc' AND level_id = $klinik_id AND barang_id IN ($ids_str)
              AND created_at > '$lu_esc' AND referensi_tipe IN ($type_list)
            GROUP BY barang_id
        ");
        while ($row = $res_pending->fetch_assoc()) {
            $pending_map[(int)$row['barang_id']] = ['in' => (float)$row['qty_in'], 'out' => (float)$row['qty_out']];
        }
    }

    $sellout_map = [];
    $reserve_map = [];
    if ($level !== 'gudang_utama') {
        $lu_sell = $last_update !== '' ? $conn->real_escape_string($last_update) : '2000-01-01 00:00:00';
        $jenis_cond = $is_hc ? "pb.jenis_pemakaian = 'hc'" : "pb.jenis_pemakaian <> 'hc'";
        $res_sell = $conn->query("
            SELECT pbd.barang_id, COALESCE(SUM(pbd.qty),0) AS qty
            FROM inventory_pemakaian_bhp_detail pbd
            JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
            WHERE pb.klinik_id = $klinik_id AND $jenis_cond AND pb.created_at > '$lu_sell'
              AND pbd.barang_id IN ($ids_str) AND pb.status = 'active'
            GROUP BY pbd.barang_id
        ");
        while ($row = $res_sell->fetch_assoc()) $sellout_map[(int)$row['barang_id']] = (float)$row['qty'];

        $today = $conn->real_escape_string(date('Y-m-d'));
        $reserve_cond = $is_hc ? "bp.status_booking LIKE '%HC%'" : "bp.status_booking LIKE '%Clinic%'";
        $field = $is_hc ? "CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END" : "CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END";
        $res_res = $conn->query("
            SELECT bd.barang_id, COALESCE(SUM($field),0) AS qty
            FROM inventory_booking_detail bd
            JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
            WHERE bp.klinik_id = $klinik_id AND bp.status IN ('booked','rescheduled','pending_edit')
              AND $reserve_cond AND bp.tanggal_pemeriksaan >= '$today' AND bd.barang_id IN ($ids_str)
            GROUP BY bd.barang_id
        ");
        while ($row = $res_res->fetch_assoc()) $reserve_map[(int)$row['barang_id']] = (float)$row['qty'];
    }

    $out = [];
    foreach ($barang_ids as $bid) {
        if (!isset($barang_map[$bid])) {
            $out[$bid] = ['ok' => false, 'message' => 'Barang tidak ditemukan', 'available' => 0, 'on_hand' => 0];
            continue;
        }
        $mult = $mult_map[$bid] ?? 1;
        if ($mult <= 0) $mult = 1;
        $baseline_raw = $baseline_map[$bid] ?? 0;
        $baseline = $baseline_raw / $mult;
        $pending = $pending_map[$bid] ?? ['in' => 0.0, 'out' => 0.0];
        $sellout = ($level === 'gudang_utama') ? 0 : ($sellout_map[$bid] ?? 0);
        $reserve = ($level === 'gudang_utama') ? 0 : ($reserve_map[$bid] ?? 0);

        $avail = (float)$baseline + (float)$pending['in'] - (float)$pending['out'] - (float)$sellout - (float)$reserve;
        if ($avail < 0) $avail = 0;
        $avail = (float)round($avail, 4);

        $on_hand = (float)$baseline + (float)$pending['in'] - (float)$pending['out'];
        if ($on_hand < 0) $on_hand = 0;
        $on_hand = (float)round($on_hand, 4);

        $out[$bid] = [
            'ok' => true,
            'location' => $loc,
            'last_update' => $last_update,
            'baseline' => $baseline,
            'pending_in' => (float)$pending['in'],
            'pending_out' => (float)$pending['out'],
            'sellout' => (float)$sellout,
            'reserve' => (float)$reserve,
            'available' => $avail,
            'on_hand' => $on_hand,
            'barang_name' => (string)($barang_map[$bid]['nama_barang'] ?? '')
        ];
    }
    return $out;
}

function fmt_qty($v) {
    $n = (float)($v ?? 0);
    if (abs($n - round($n)) < 0.00005) return number_format((int)round($n), 0, ',', '.');
    $s = rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}

function stock_effective(mysqli $conn, int $klinik_id, bool $is_hc, int $barang_id): array {
    $klinik_id = (int)$klinik_id;
    $barang_id = (int)$barang_id;
    
    $loc = '';
    $level = 'klinik';
    
    if ($klinik_id === 0 && !$is_hc) {
        $level = 'gudang_utama';
        if (function_exists('get_setting')) {
            $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
            if ($gudang_loc !== '') {
                $loc = stock_resolve_location($conn, $gudang_loc);
            }
        }
    } else {
        $kl = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
        $kode_klinik = trim((string)($kl['kode_klinik'] ?? ''));
        $kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
        $loc = $is_hc ? stock_resolve_location($conn, $kode_homecare) : stock_resolve_location($conn, $kode_klinik);
        $level = $is_hc ? 'hc' : 'klinik';
    }

    if ($loc === '') return ['ok' => false, 'message' => 'Kode lokasi belum diisi', 'available' => 0, 'on_hand' => 0];

    $b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
    if (!$b) return ['ok' => false, 'message' => 'Barang tidak ditemukan', 'available' => 0, 'on_hand' => 0];

    $mult = stock_multiplier($conn, $barang_id);
    $match = stock_match_clause($conn, $b);
    $last_update = stock_last_update($conn, $loc);
    $baseline = stock_mirror_qty($conn, $loc, $match, $mult);

    $pending = stock_pending_transaksi(
        $conn,
        $level,
        $klinik_id,
        $barang_id,
        $last_update,
        $level === 'hc' ? ['hc_petugas_transfer', 'pemakaian_bhp_revision'] : ['transfer', 'hc_petugas_transfer', 'pemakaian_bhp_revision']
    );
    
    $sellout = ($level === 'gudang_utama') ? 0 : stock_sellout_qty($conn, $klinik_id, $barang_id, $last_update, $is_hc);
    $reserve = ($level === 'gudang_utama') ? 0 : stock_reserve_qty($conn, $klinik_id, $barang_id, $last_update, $is_hc);

    $avail = (float)$baseline + (float)$pending['in'] - (float)$pending['out'] - (float)$sellout - (float)$reserve;
    if ($avail < 0) $avail = 0;
    $avail = (float)round($avail, 4);

    $on_hand = (float)$baseline + (float)$pending['in'] - (float)$pending['out'];
    if ($on_hand < 0) $on_hand = 0;
    $on_hand = (float)round($on_hand, 4);

    return [
        'ok' => true,
        'location' => $loc,
        'last_update' => $last_update,
        'baseline' => $baseline,
        'pending_in' => (float)$pending['in'],
        'pending_out' => (float)$pending['out'],
        'sellout' => (float)$sellout,
        'reserve' => (float)$reserve,
        'available' => $avail,
        'on_hand' => $on_hand,
        'barang_name' => (string)($b['nama_barang'] ?? '')
    ];
}

/**
 * Kembalikan SEMUA barang di tas HC seorang petugas (di satu klinik tertentu) ke stok
 * onsite klinik itu -- "full return" otomatis. Dipakai saat nakes pindah klinik terdaftar,
 * supaya stok yang masih nyangkut di tasnya tidak ketinggalan di klinik lama.
 *
 * Logic & pola transaksinya SAMA PERSIS dengan actions/process_hc_return.php (return manual
 * per-item) -- bedanya di sini daftar barang+qty-nya diambil otomatis dari semua baris
 * inventory_stok_tas_hc yang masih > 0, bukan dari input form manual.
 */
function hc_full_return(mysqli $conn, int $user_hc_id, int $old_klinik_id, int $created_by, string $catatan = ''): array {
    if ($user_hc_id <= 0 || $old_klinik_id <= 0) {
        return ['ok' => false, 'message' => 'Parameter tidak valid.', 'items_returned' => 0];
    }

    $lock_name = 'stock_hc_transfer_' . $old_klinik_id;
    $lock_esc = $conn->real_escape_string($lock_name);
    $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got_lock !== 1) {
        return ['ok' => false, 'message' => 'Sistem sedang memproses stok klinik lama, coba lagi sebentar.', 'items_returned' => 0];
    }

    $items_returned = 0;
    $conn->begin_transaction();
    try {
        $res = $conn->query("SELECT barang_id, qty FROM inventory_stok_tas_hc WHERE user_id = $user_hc_id AND klinik_id = $old_klinik_id AND qty > 0 FOR UPDATE");
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;

        foreach ($rows as $row) {
            $barang_id = (int)$row['barang_id'];
            $qty_oper = (float)$row['qty'];
            if ($barang_id <= 0 || $qty_oper <= 0) continue;

            $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($barang_id, $old_klinik_id, 0)");
            $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $barang_id AND klinik_id = $old_klinik_id FOR UPDATE");

            $ef_on = stock_effective($conn, $old_klinik_id, false, $barang_id);
            $onsite_before = $ef_on['ok'] ? (float)($ef_on['on_hand'] ?? 0) : 0.0;
            $ef_hc = stock_effective($conn, $old_klinik_id, true, $barang_id);
            $hc_before = $ef_hc['ok'] ? (float)($ef_hc['on_hand'] ?? 0) : 0.0;

            $cat_hist = 'Pengembalian otomatis (Nakes pindah klinik)';
            if ($catatan !== '') $cat_hist .= ' - ' . $catatan;
            $neg_qty = 0 - $qty_oper;
            $stmt = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiidsi", $old_klinik_id, $user_hc_id, $barang_id, $neg_qty, $cat_hist, $created_by);
            $stmt->execute();
            $return_id = (int)$conn->insert_id;

            $stmt_upd_bag = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt_upd_bag->bind_param("diiii", $qty_oper, $created_by, $barang_id, $user_hc_id, $old_klinik_id);
            $stmt_upd_bag->execute();

            $stmt_upd_klinik = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
            $stmt_upd_klinik->bind_param("diii", $qty_oper, $created_by, $barang_id, $old_klinik_id);
            $stmt_upd_klinik->execute();

            $cat_trans = 'Pengembalian otomatis HC Petugas #' . $return_id;
            $qty_after_onsite = $onsite_before + $qty_oper;
            $stmt_trans_on = $conn->prepare("
                INSERT INTO inventory_transaksi_stok
                (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                VALUES (?, 'klinik', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
            ");
            $stmt_trans_on->bind_param("iidddisi", $barang_id, $old_klinik_id, $qty_oper, $onsite_before, $qty_after_onsite, $return_id, $cat_trans, $created_by);
            $stmt_trans_on->execute();

            $qty_after_hc = max(0.0, $hc_before - $qty_oper);
            $stmt_trans_hc = $conn->prepare("
                INSERT INTO inventory_transaksi_stok
                (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                VALUES (?, 'hc', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
            ");
            $stmt_trans_hc->bind_param("iidddisi", $barang_id, $user_hc_id, $qty_oper, $hc_before, $qty_after_hc, $return_id, $cat_trans, $created_by);
            $stmt_trans_hc->execute();

            $items_returned++;
        }

        $conn->commit();
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        return ['ok' => true, 'message' => "$items_returned barang berhasil dikembalikan ke stok onsite klinik lama.", 'items_returned' => $items_returned];
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        return ['ok' => false, 'message' => $e->getMessage(), 'items_returned' => 0];
    }
}
