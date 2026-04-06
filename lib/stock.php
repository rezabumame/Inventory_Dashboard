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
    return (string)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['last_update'] ?? '') : '');
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
    if ($last_update === '') return 0.0;
    $lu = $conn->real_escape_string($last_update);
    $jenis_cond = $is_hc ? "pb.jenis_pemakaian = 'hc'" : "pb.jenis_pemakaian <> 'hc'";
    $r = $conn->query("
        SELECT COALESCE(SUM(pbd.qty),0) AS qty
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
        WHERE pb.klinik_id = $klinik_id
          AND $jenis_cond
          AND pb.created_at > '$lu'
          AND pbd.barang_id = $barang_id
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
          AND bp.status = 'booked'
          AND $reserve_cond
          AND bp.tanggal_pemeriksaan >= '$today'
          AND bd.barang_id = $barang_id
    ");
    return (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
}

function stock_effective(mysqli $conn, int $klinik_id, bool $is_hc, int $barang_id): array {
    $klinik_id = (int)$klinik_id;
    $barang_id = (int)$barang_id;
    $kl = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    $kode_klinik = trim((string)($kl['kode_klinik'] ?? ''));
    $kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
    $loc = $is_hc ? stock_resolve_location($conn, $kode_homecare) : stock_resolve_location($conn, $kode_klinik);
    if ($loc === '') return ['ok' => false, 'message' => 'Kode lokasi belum diisi', 'available' => 0];

    $b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
    if (!$b) return ['ok' => false, 'message' => 'Barang tidak ditemukan', 'available' => 0];

    $mult = stock_multiplier($conn, $barang_id);
    $match = stock_match_clause($conn, $b);
    $last_update = stock_last_update($conn, $loc);
    $baseline = stock_mirror_qty($conn, $loc, $match, $mult);

    $pending = stock_pending_transaksi(
        $conn,
        $is_hc ? 'hc' : 'klinik',
        $klinik_id,
        $barang_id,
        $last_update,
        $is_hc ? ['hc_petugas_transfer'] : ['transfer', 'hc_petugas_transfer']
    );
    $sellout = stock_sellout_qty($conn, $klinik_id, $barang_id, $last_update, $is_hc);
    $reserve = stock_reserve_qty($conn, $klinik_id, $barang_id, $last_update, $is_hc);

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
