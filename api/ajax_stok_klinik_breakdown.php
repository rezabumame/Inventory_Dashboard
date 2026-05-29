<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/usage.php';

header('Content-Type: application/json');

$role = (string)($_SESSION['role'] ?? '');
$token = $_POST['token'] ?? '';
$is_public = false;

if (!isset($_SESSION['user_id'])) {
    if ($token !== '' && $token === get_setting('public_stok_token')) {
        $is_public = true;
        $role = 'super_admin'; // Treat as super_admin for logic compatibility
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!$is_public && !in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'cs', 'petugas_hc'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

$klinik_id_raw = $_POST['klinik_id'] ?? 0;
$is_all_klinik = ($klinik_id_raw === 'all' || (int)$klinik_id_raw === 0);
$klinik_id = $is_all_klinik ? 0 : (int)$klinik_id_raw;
$barang_id = (int)($_POST['barang_id'] ?? 0);
$tanggal = (string)($_POST['tanggal'] ?? '');
$include_gudang = !empty($_POST['include_gudang']);
$reserve_window = trim((string)($_POST['reserve_window'] ?? '30'));
if (!in_array($reserve_window, ['7', '30', 'month', 'all'], true)) $reserve_window = '30';

if ($barang_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'admin_klinik') {
    if ($is_all_klinik || (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
        echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$today = date('Y-m-d');
if (strtotime($tanggal) > strtotime($today)) $tanggal = $today;
$min_month_date = date('Y-m-01');
if (strtotime($tanggal) < strtotime($min_month_date)) $tanggal = $min_month_date;
$month_start = date('Y-m-01', strtotime($tanggal));
$month_end = date('Y-m-t', strtotime($tanggal));
$tanggal_end_ts = $tanggal . ' 23:59:59';
$month_start_ts = $month_start . ' 00:00:00';

$active_kliniks = [];
if ($is_all_klinik) {
    $res_k = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE status = 'active'");
    while($rk = $res_k->fetch_assoc()) $active_kliniks[] = $rk;
    $kl = ['id' => 0, 'nama_klinik' => 'Semua Klinik', 'kode_klinik' => '', 'kode_homecare' => ''];
} else {
    $kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    if (!$kl) {
        echo json_encode(['success' => false, 'message' => 'Klinik not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $active_kliniks[] = $kl;
}

$b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang, satuan FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
if (!$b) {
    echo json_encode(['success' => false, 'message' => 'Barang not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$conv = $conn->query("
    SELECT c.from_uom, c.to_uom, c.multiplier 
    FROM inventory_barang_uom_conversion c
    JOIN inventory_barang b ON b.kode_barang = c.kode_barang
    WHERE b.id = $barang_id 
    LIMIT 1
")->fetch_assoc();
$multiplier = (float)($conv['multiplier'] ?? 1);
if ($multiplier <= 0) $multiplier = 1;

$loc_k_list = [];
$loc_h_list = [];
$klinik_ids_list = [];
foreach ($active_kliniks as $ak) {
    $klinik_ids_list[] = (int)$ak['id'];
    if (!empty($ak['kode_klinik'])) $loc_k_list[] = "'" . $conn->real_escape_string(trim($ak['kode_klinik'])) . "'";
    if (!empty($ak['kode_homecare'])) $loc_h_list[] = "'" . $conn->real_escape_string(trim($ak['kode_homecare'])) . "'";
}

$klinik_ids_str = implode(',', $klinik_ids_list);
$loc_k_str = !empty($loc_k_list) ? implode(',', $loc_k_list) : "''";
$loc_h_str = !empty($loc_h_list) ? implode(',', $loc_h_list) : "''";

$last_update_klinik = '';
if ($loc_k_str !== "''") {
    $r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE location_code IN ($loc_k_str)");
    if ($r && $r->num_rows > 0) $last_update_klinik = (string)($r->fetch_assoc()['last_update'] ?? '');
}
$last_update_hc = '';
if ($loc_h_str !== "''") {
    $r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE location_code IN ($loc_h_str)");
    if ($r && $r->num_rows > 0) $last_update_hc = (string)($r->fetch_assoc()['last_update'] ?? '');
}

$last_update_text = '';
$max_u = $last_update_klinik;
if ($last_update_hc !== '' && ($max_u === '' || strtotime($last_update_hc) > strtotime($max_u))) $max_u = $last_update_hc;
if ($max_u !== '') $last_update_text = date('d M Y H:i', strtotime($max_u));

$kb_esc = $conn->real_escape_string(trim($b['kode_barang'] ?? ''));
$oid_esc = $conn->real_escape_string(trim($b['odoo_product_id'] ?? ''));
$match = [];
if ($kb_esc !== '') $match[] = "TRIM(kode_barang) = '$kb_esc'";
if ($oid_esc !== '') $match[] = "TRIM(odoo_product_id) = '$oid_esc'";
if (empty($match)) $match[] = "1=0";
$match_sql = '(' . implode(' OR ', $match) . ')';

$baseline_onsite = 0.0;
if ($loc_k_str !== "''") {
    $r = $conn->query("
        SELECT SUM(qty) as total_qty FROM (
            SELECT location_code, qty FROM inventory_stock_mirror sm1
            JOIN (
                SELECT TRIM(location_code) as loc, MAX(updated_at) as max_up 
                FROM inventory_stock_mirror 
                WHERE TRIM(location_code) IN ($loc_k_str) AND $match_sql
                GROUP BY TRIM(location_code)
            ) last_sm ON TRIM(sm1.location_code) = last_sm.loc AND sm1.updated_at = last_sm.max_up
            WHERE TRIM(sm1.location_code) IN ($loc_k_str) AND $match_sql
        ) combined
    ");
    if ($r && $r->num_rows > 0) $baseline_onsite = (float)($r->fetch_assoc()['total_qty'] ?? 0);
}
$baseline_hc = 0.0;
if ($loc_h_str !== "''") {
    $r = $conn->query("
        SELECT SUM(qty) as total_qty FROM (
            SELECT location_code, qty FROM inventory_stock_mirror sm1
            JOIN (
                SELECT TRIM(location_code) as loc, MAX(updated_at) as max_up 
                FROM inventory_stock_mirror 
                WHERE TRIM(location_code) IN ($loc_h_str) AND $match_sql
                GROUP BY TRIM(location_code)
            ) last_sm ON TRIM(sm1.location_code) = last_sm.loc AND sm1.updated_at = last_sm.max_up
            WHERE TRIM(sm1.location_code) IN ($loc_h_str) AND $match_sql
        ) combined
    ");
    if ($r && $r->num_rows > 0) $baseline_hc = (float)($r->fetch_assoc()['total_qty'] ?? 0);
}
$baseline_onsite = $baseline_onsite / $multiplier;
$baseline_hc = $baseline_hc / $multiplier;

$is_history = (strtotime($tanggal) < strtotime($today));

$rb = [
    'out_transfer' => 0.0,
    'in_transfer' => 0.0,
    'out_transfer_hc' => 0.0,
    'in_transfer_hc' => 0.0,
    'sellout_klinik' => 0.0,
    'sellout_hc' => 0.0,
    'range' => null,
    'events' => [
        'transfers' => [],
        'pemakaian' => []
    ]
];

if ($is_history && $max_u !== '' && strtotime($tanggal_end_ts) < strtotime($max_u)) {
    $range_start = $tanggal_end_ts;
    $range_end = $max_u;
    $rb['range'] = ['from' => $range_start, 'to' => $range_end];

    $rs = $conn->real_escape_string($range_start);
    $re = $conn->real_escape_string($range_end);
    $ms = $conn->real_escape_string($month_start_ts);

    // Rollback Onsite Transfers
    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'klinik'
          AND ts.level_id IN ($klinik_ids_str)
          AND ts.tipe_transaksi = 'out'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['out_transfer'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'klinik'
          AND ts.level_id IN ($klinik_ids_str)
          AND ts.tipe_transaksi = 'in'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['in_transfer'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    // Rollback HC Transfers
    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'hc'
          AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))
          AND ts.tipe_transaksi = 'out'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['out_transfer_hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'hc'
          AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))
          AND ts.tipe_transaksi = 'in'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['in_transfer_hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    $r = $conn->query("
        SELECT 
            pb.id,
            pb.nomor_pemakaian,
            pb.tanggal,
            pb.created_at,
            pb.jenis_pemakaian,
            SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) AS qty
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_transaksi_stok ts ON ts.referensi_id = pb.id
        WHERE pb.klinik_id IN ($klinik_ids_str)
          AND ts.barang_id = $barang_id
          AND ts.referensi_tipe = 'pemakaian_bhp'
          AND pb.tanggal > '$rs' AND pb.tanggal <= '$re'
          AND pb.tanggal >= '$ms'
          AND pb.status = 'active'
        GROUP BY pb.id, pb.nomor_pemakaian, pb.tanggal, pb.created_at, pb.jenis_pemakaian
        ORDER BY pb.created_at ASC, pb.id ASC
    ");
    while ($r && ($row = $r->fetch_assoc())) {
        $qty = (float)($row['qty'] ?? 0);
        $rb['events']['pemakaian'][] = $row;
        if ((string)($row['jenis_pemakaian'] ?? '') === 'hc') $rb['sellout_hc'] += $qty;
        else $rb['sellout_klinik'] += $qty;
    }

    $r = $conn->query("
        SELECT ts.referensi_id AS transfer_id, ts.tipe_transaksi, ts.level, SUM(ts.qty) AS qty, MIN(ts.created_at) AS first_at, MAX(ts.created_at) AS last_at
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ((ts.level = 'klinik' AND ts.level_id IN ($klinik_ids_str)) OR (ts.level = 'hc' AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))))
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
          AND ts.created_at >= '$ms'
        GROUP BY ts.referensi_id, ts.tipe_transaksi, ts.level
        ORDER BY last_at ASC
        LIMIT 50
    ");
    while ($r && ($row = $r->fetch_assoc())) $rb['events']['transfers'][] = $row;
}

if (!$is_history && $max_u !== '') {
    $sync_buffer_ts = $max_u;
    $rs = $conn->real_escape_string($sync_buffer_ts);
    $ms = $conn->real_escape_string($month_start_ts);

    // Today Onsite Transfers
    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'klinik'
          AND ts.level_id IN ($klinik_ids_str)
          AND ts.tipe_transaksi = 'out'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['out_transfer'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'klinik'
          AND ts.level_id IN ($klinik_ids_str)
          AND ts.tipe_transaksi = 'in'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['in_transfer'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    // Today HC Transfers
    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'hc'
          AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))
          AND ts.tipe_transaksi = 'out'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['out_transfer_hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    $r = $conn->query("
        SELECT COALESCE(SUM(ts.qty), 0) AS qty
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ts.level = 'hc'
          AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))
          AND ts.tipe_transaksi = 'in'
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs'
          AND ts.created_at >= '$ms'
    ");
    if ($r && $r->num_rows > 0) $rb['in_transfer_hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

    // Today Pemakaian (Sellout)
    $r = $conn->query("
        SELECT 
            pb.id,
            pb.nomor_pemakaian,
            pb.tanggal,
            pb.created_at,
            pb.jenis_pemakaian,
            SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) AS qty
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_transaksi_stok ts ON ts.referensi_id = pb.id
        WHERE pb.klinik_id IN ($klinik_ids_str)
          AND ts.barang_id = $barang_id
          AND ts.referensi_tipe = 'pemakaian_bhp'
          AND pb.created_at > '$rs'
          AND pb.tanggal >= '$ms'
          AND pb.status = 'active'
        GROUP BY pb.id, pb.nomor_pemakaian, pb.tanggal, pb.created_at, pb.jenis_pemakaian
        ORDER BY pb.created_at ASC, pb.id ASC
    ");
    while ($r && ($row = $r->fetch_assoc())) {
        $qty = (float)($row['qty'] ?? 0);
        $rb['events']['pemakaian'][] = $row;
        if ((string)($row['jenis_pemakaian'] ?? '') === 'hc') $rb['sellout_hc'] += $qty;
        else $rb['sellout_klinik'] += $qty;
    }

    // Today Transfers List
    $r = $conn->query("
        SELECT ts.referensi_id AS transfer_id, ts.tipe_transaksi, ts.level, SUM(ts.qty) AS qty, MIN(ts.created_at) AS first_at, MAX(ts.created_at) AS last_at
        FROM inventory_transaksi_stok ts
        WHERE ts.barang_id = $barang_id
          AND ((ts.level = 'klinik' AND ts.level_id IN ($klinik_ids_str)) OR (ts.level = 'hc' AND EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id IN ($klinik_ids_str))))
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs'
          AND ts.created_at >= '$ms'
        GROUP BY ts.referensi_id, ts.tipe_transaksi, ts.level
        ORDER BY last_at ASC
        LIMIT 50
    ");
    while ($r && ($row = $r->fetch_assoc())) $rb['events']['transfers'][] = $row;
}

$reserve = [
    'onsite' => 0.0,
    'hc' => 0.0,
    'range' => ['from' => $tanggal, 'to' => $month_end],
    'events' => []
];

if ($is_history) {
    $reserve_end_date = $month_end;
} else {
    switch ($reserve_window) {
        case '7':     $reserve_end_date = date('Y-m-d', strtotime('+7 days')); break;
        case 'month': $reserve_end_date = date('Y-m-t'); break;
        case 'all':   $reserve_end_date = ''; break;
        default:      $reserve_end_date = date('Y-m-d', strtotime('+30 days')); break;
    }
}
$_res_date_filter_upper = ($reserve_end_date !== '') ? " AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($reserve_end_date) . "'" : '';
$r = $conn->query("
    SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END), 0) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id IN ($klinik_ids_str)
      AND bp.status IN ('booked','rescheduled','pending_edit')
      AND bp.status_booking LIKE '%Clinic%'
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      $_res_date_filter_upper
");
if ($r && $r->num_rows > 0) $reserve['onsite'] = (float)($r->fetch_assoc()['qty'] ?? 0);

$r = $conn->query("
    SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END), 0) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id IN ($klinik_ids_str)
      AND bp.status IN ('booked','rescheduled','pending_edit')
      AND bp.status_booking LIKE '%HC%'
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      $_res_date_filter_upper
");
if ($r && $r->num_rows > 0) $reserve['hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

$r = $conn->query("
    SELECT bp.nomor_booking, bp.tanggal_pemeriksaan, bp.status_booking, SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id IN ($klinik_ids_str)
      AND bp.status IN ('booked','rescheduled','pending_edit')
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      $_res_date_filter_upper
    GROUP BY bp.nomor_booking, bp.tanggal_pemeriksaan, bp.status_booking
    ORDER BY bp.tanggal_pemeriksaan ASC
    LIMIT 50
");
while ($r && ($row = $r->fetch_assoc())) $reserve['events'][] = $row;

$stock_as_of_onsite = $baseline_onsite;
$stock_as_of_hc = $baseline_hc;
if ($is_history) {
    $stock_as_of_onsite = $baseline_onsite + $rb['out_transfer'] - $rb['in_transfer'] + $rb['sellout_klinik'];
    $stock_as_of_hc = $baseline_hc + $rb['out_transfer_hc'] - $rb['in_transfer_hc'] + $rb['sellout_hc'];
} else {
    $stock_as_of_onsite = $baseline_onsite - ($rb['out_transfer'] - $rb['in_transfer'] + $rb['sellout_klinik']);
    $stock_as_of_hc = $baseline_hc - ($rb['out_transfer_hc'] - $rb['in_transfer_hc'] + $rb['sellout_hc']);
}

// Daily usage (accumulated) — uses same logic as main table
$daily_usage_rate = 0.0;
if (!$is_history) {
    $daily_usage_rate = $is_all_klinik
        ? calculate_accumulated_usage('all', $barang_id)
        : calculate_accumulated_usage($klinik_id, $barang_id);
}

// Per-klinik breakdown (only when all klinik)
$per_klinik = [];
if ($is_all_klinik) {
    foreach ($active_kliniks as $ak) {
        $ak_id = (int)$ak['id'];
        $ak_loc_k = !empty($ak['kode_klinik']) ? "'" . $conn->real_escape_string(trim($ak['kode_klinik'])) . "'" : null;
        $ak_loc_h = !empty($ak['kode_homecare']) ? "'" . $conn->real_escape_string(trim($ak['kode_homecare'])) . "'" : null;

        // Baseline onsite dari stock_mirror
        $ak_on = 0.0;
        if ($ak_loc_k) {
            $r = $conn->query("SELECT SUM(qty) as q FROM (SELECT location_code, qty FROM inventory_stock_mirror sm1 JOIN (SELECT TRIM(location_code) as loc, MAX(updated_at) as max_up FROM inventory_stock_mirror WHERE TRIM(location_code) = $ak_loc_k AND $match_sql GROUP BY TRIM(location_code)) lsm ON TRIM(sm1.location_code) = lsm.loc AND sm1.updated_at = lsm.max_up WHERE TRIM(sm1.location_code) = $ak_loc_k AND $match_sql) c");
            if ($r && $r->num_rows > 0) $ak_on = (float)($r->fetch_assoc()['q'] ?? 0) / $multiplier;
        }
        // Baseline HC dari stock_mirror
        $ak_hc = 0.0;
        if ($ak_loc_h) {
            $r = $conn->query("SELECT SUM(qty) as q FROM (SELECT location_code, qty FROM inventory_stock_mirror sm1 JOIN (SELECT TRIM(location_code) as loc, MAX(updated_at) as max_up FROM inventory_stock_mirror WHERE TRIM(location_code) = $ak_loc_h AND $match_sql GROUP BY TRIM(location_code)) lsm ON TRIM(sm1.location_code) = lsm.loc AND sm1.updated_at = lsm.max_up WHERE TRIM(sm1.location_code) = $ak_loc_h AND $match_sql) c");
            if ($r && $r->num_rows > 0) $ak_hc = (float)($r->fetch_assoc()['q'] ?? 0) / $multiplier;
        }

        // Apply transfer adjustments (same logic as aggregate but per clinic)
        $ak_rb_out_on = 0.0; $ak_rb_in_on = 0.0;
        $ak_rb_out_hc = 0.0; $ak_rb_in_hc = 0.0;
        $ak_sell_on = 0.0; $ak_sell_hc = 0.0;

        if ($max_u !== '') {
            $ak_ms = $conn->real_escape_string($month_start_ts);
            $ak_mu = $conn->real_escape_string($max_u);
            if ($is_history && strtotime($tanggal_end_ts) < strtotime($max_u)) {
                $ak_rs = $conn->real_escape_string($tanggal_end_ts);
                $ak_re = $ak_mu;
                $range_cond     = "AND ts.created_at > '$ak_rs' AND ts.created_at <= '$ak_re' AND ts.created_at >= '$ak_ms'";
                $pem_date_cond  = "AND pb.tanggal > '$ak_rs' AND pb.tanggal <= '$ak_re' AND pb.tanggal >= '$ak_ms'";
            } elseif (!$is_history) {
                $range_cond     = "AND ts.created_at > '$ak_mu' AND ts.created_at >= '$ak_ms'";
                $pem_date_cond  = "AND pb.created_at > '$ak_mu' AND pb.tanggal >= '$ak_ms'";
            } else {
                $range_cond = $pem_date_cond = "AND 1=0"; // no adjustments needed
            }

            $r = $conn->query("SELECT COALESCE(SUM(ts.qty),0) AS q FROM inventory_transaksi_stok ts WHERE ts.barang_id=$barang_id AND ts.level='klinik' AND ts.level_id=$ak_id AND ts.tipe_transaksi='out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') $range_cond");
            if ($r) $ak_rb_out_on = (float)($r->fetch_assoc()['q'] ?? 0);
            $r = $conn->query("SELECT COALESCE(SUM(ts.qty),0) AS q FROM inventory_transaksi_stok ts WHERE ts.barang_id=$barang_id AND ts.level='klinik' AND ts.level_id=$ak_id AND ts.tipe_transaksi='in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') $range_cond");
            if ($r) $ak_rb_in_on = (float)($r->fetch_assoc()['q'] ?? 0);
            $r = $conn->query("SELECT COALESCE(SUM(ts.qty),0) AS q FROM inventory_transaksi_stok ts WHERE ts.barang_id=$barang_id AND ts.level='hc' AND EXISTS(SELECT 1 FROM inventory_users u WHERE u.id=ts.level_id AND u.klinik_id=$ak_id) AND ts.tipe_transaksi='out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') $range_cond");
            if ($r) $ak_rb_out_hc = (float)($r->fetch_assoc()['q'] ?? 0);
            $r = $conn->query("SELECT COALESCE(SUM(ts.qty),0) AS q FROM inventory_transaksi_stok ts WHERE ts.barang_id=$barang_id AND ts.level='hc' AND EXISTS(SELECT 1 FROM inventory_users u WHERE u.id=ts.level_id AND u.klinik_id=$ak_id) AND ts.tipe_transaksi='in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') $range_cond");
            if ($r) $ak_rb_in_hc = (float)($r->fetch_assoc()['q'] ?? 0);

            $r = $conn->query("SELECT pb.jenis_pemakaian, COALESCE(SUM(CASE WHEN ts.tipe_transaksi='out' THEN ts.qty ELSE -ts.qty END),0) AS qty FROM inventory_pemakaian_bhp pb JOIN inventory_transaksi_stok ts ON ts.referensi_id=pb.id WHERE pb.klinik_id=$ak_id AND ts.barang_id=$barang_id AND ts.referensi_tipe='pemakaian_bhp' $pem_date_cond AND pb.status='active' GROUP BY pb.jenis_pemakaian");
            while ($r && ($prow = $r->fetch_assoc())) {
                if ($prow['jenis_pemakaian'] === 'hc') $ak_sell_hc += (float)$prow['qty'];
                else $ak_sell_on += (float)$prow['qty'];
            }
        }

        if ($is_history) {
            $ak_stock_on = $ak_on + $ak_rb_out_on - $ak_rb_in_on + $ak_sell_on;
            $ak_stock_hc = $ak_hc + $ak_rb_out_hc - $ak_rb_in_hc + $ak_sell_hc;
        } else {
            $ak_stock_on = $ak_on - ($ak_rb_out_on - $ak_rb_in_on + $ak_sell_on);
            $ak_stock_hc = $ak_hc - ($ak_rb_out_hc - $ak_rb_in_hc + $ak_sell_hc);
        }

        // Accumulated daily usage per klinik (same logic as main table)
        $ak_daily = $is_history ? 0.0 : calculate_accumulated_usage($ak_id, $barang_id);

        // Reserve per klinik
        $ak_res_on = 0.0; $ak_res_hc = 0.0;
        $t_esc2 = $conn->real_escape_string($tanggal);
        $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_onsite>0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END),0) AS q FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id=bp.id WHERE bd.barang_id=$barang_id AND bp.klinik_id=$ak_id AND bp.status IN ('booked','rescheduled','pending_edit') AND bp.status_booking LIKE '%Clinic%' AND bp.tanggal_pemeriksaan>='$t_esc2' $_res_date_filter_upper");
        if ($r) $ak_res_on = (float)($r->fetch_assoc()['q'] ?? 0);
        $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_hc>0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END),0) AS q FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id=bp.id WHERE bd.barang_id=$barang_id AND bp.klinik_id=$ak_id AND bp.status IN ('booked','rescheduled','pending_edit') AND bp.status_booking LIKE '%HC%' AND bp.tanggal_pemeriksaan>='$t_esc2' $_res_date_filter_upper");
        if ($r) $ak_res_hc = (float)($r->fetch_assoc()['q'] ?? 0);

        $ak_on_hand = ($ak_stock_on + $ak_stock_hc) - $ak_daily;

        $per_klinik[] = [
            'id'          => $ak_id,
            'nama_klinik' => (string)$ak['nama_klinik'],
            'stock_on'    => $ak_stock_on + $ak_sell_on,
            'stock_hc'    => $ak_stock_hc + $ak_sell_hc,
            'sellout_on'  => $ak_sell_on,
            'sellout_hc'  => $ak_sell_hc,
            'daily_usage' => round($ak_daily, 0),
            'on_hand'     => $ak_on_hand,
            'reserve_on'  => $ak_res_on,
            'reserve_hc'  => $ak_res_hc,
            'available'   => $ak_on_hand - $ak_res_on - $ak_res_hc,
        ];
    }

    // Prepend Gudang Utama row if include_gudang (appears at top)
    if ($include_gudang) {
        $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
        $ak_gd_on = 0.0;
        if ($gudang_loc !== '') {
            $gloc_esc = "'" . $conn->real_escape_string($gudang_loc) . "'";
            $r = $conn->query("SELECT SUM(qty) as q FROM (SELECT location_code, qty FROM inventory_stock_mirror sm1 JOIN (SELECT TRIM(location_code) as loc, MAX(updated_at) as max_up FROM inventory_stock_mirror WHERE TRIM(location_code) = $gloc_esc AND $match_sql GROUP BY TRIM(location_code)) lsm ON TRIM(sm1.location_code) = lsm.loc AND sm1.updated_at = lsm.max_up WHERE TRIM(sm1.location_code) = $gloc_esc AND $match_sql) c");
            if ($r && $r->num_rows > 0) $ak_gd_on = (float)($r->fetch_assoc()['q'] ?? 0) / $multiplier;
        }

        // Sellout gudang (from transaksi_stok level=gudang_utama)
        $ak_gd_sell = 0.0;
        if ($max_u !== '') {
            $ak_mu_gd = $conn->real_escape_string($max_u);
            $ak_ms_gd = $conn->real_escape_string($month_start_ts);
            if ($is_history && strtotime($tanggal_end_ts) < strtotime($max_u)) {
                $ak_rs_gd = $conn->real_escape_string($tanggal_end_ts);
                $pem_cond_gd = "AND pb.tanggal > '$ak_rs_gd' AND pb.tanggal <= '$ak_mu_gd' AND pb.tanggal >= '$ak_ms_gd'";
            } elseif (!$is_history) {
                $pem_cond_gd = "AND pb.created_at > '$ak_mu_gd' AND pb.tanggal >= '$ak_ms_gd'";
            } else {
                $pem_cond_gd = "AND 1=0";
            }
            $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN ts.tipe_transaksi='out' THEN ts.qty ELSE -ts.qty END),0) AS qty FROM inventory_pemakaian_bhp pb JOIN inventory_transaksi_stok ts ON ts.referensi_id=pb.id WHERE ts.barang_id=$barang_id AND ts.referensi_tipe='pemakaian_bhp' AND ts.level='gudang_utama' $pem_cond_gd AND pb.status='active'");
            if ($r) $ak_gd_sell = (float)($r->fetch_assoc()['qty'] ?? 0);
        }

        array_unshift($per_klinik, [
            'id'          => 0,
            'nama_klinik' => 'Gudang Utama',
            'stock_on'    => $ak_gd_on,
            'stock_hc'    => 0.0,
            'sellout_on'  => $ak_gd_sell,
            'sellout_hc'  => 0.0,
            'daily_usage' => 0.0,
            'on_hand'     => $ak_gd_on,
            'is_gudang'   => true,
        ]);
    }
}

// Period usage (1st of month until selected date)
$ms_q = $conn->real_escape_string($month_start);
$t_q = $conn->real_escape_string($tanggal);
$p_res = $conn->query("
    SELECT 
        pb.id,
        pb.nomor_pemakaian,
        pb.tanggal,
        pb.created_at,
        pb.jenis_pemakaian,
        SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) AS qty
    FROM inventory_pemakaian_bhp pb
    JOIN inventory_transaksi_stok ts ON ts.referensi_id = pb.id
    WHERE pb.klinik_id IN ($klinik_ids_str)
      AND ts.barang_id = $barang_id
      AND ts.referensi_tipe = 'pemakaian_bhp'
      AND pb.tanggal >= '$ms_q 00:00:00' AND pb.created_at <= '$t_q 23:59:59'
      AND pb.status = 'active'
    GROUP BY pb.id, pb.nomor_pemakaian, pb.tanggal, pb.created_at, pb.jenis_pemakaian
    ORDER BY pb.created_at ASC, pb.id ASC
");
$period_usage = [];
while ($p_res && ($p_row = $p_res->fetch_assoc())) {
    $period_usage[] = $p_row;
}

echo json_encode([
    'success' => true,
    'period_usage' => $period_usage,
    'klinik' => [
        'id' => (int)$kl['id'],
        'nama_klinik' => (string)($kl['nama_klinik'] ?? ''),
        'kode_klinik' => (string)($kl['kode_klinik'] ?? ''),
        'kode_homecare' => (string)($kl['kode_homecare'] ?? '')
    ],
    'barang' => [
        'id' => (int)$b['id'],
        'kode_barang' => (string)($b['kode_barang'] ?? ''),
        'nama_barang' => (string)($b['nama_barang'] ?? ''),
        'satuan' => (string)($b['satuan'] ?? '')
    ],
    'tanggal' => $tanggal,
    'month_start' => $month_start,
    'month_end' => $month_end,
    'last_update' => [
        'text' => $last_update_text,
        'klinik' => $last_update_klinik,
        'hc' => $last_update_hc
    ],
    'baseline' => [
        'onsite' => $baseline_onsite,
        'hc' => $baseline_hc
    ],
    'conversion' => [
        'from_uom' => (string)($conv['from_uom'] ?? ''),
        'to_uom' => (string)($b['satuan'] ?? ($conv['to_uom'] ?? '')),
        'multiplier' => $multiplier
    ],
    'daily_usage' => round($daily_usage_rate, 2),
    'per_klinik' => $per_klinik,
    'rollback' => $rb,
    'reserve' => $reserve,
    'result' => [
        'stock_onsite' => $stock_as_of_onsite,
        'stock_hc' => $stock_as_of_hc,
        'stock_total' => $stock_as_of_onsite + $stock_as_of_hc,
        'tersedia' => ($stock_as_of_onsite + $stock_as_of_hc) - $reserve['onsite'] - $reserve['hc']
    ]
], JSON_UNESCAPED_UNICODE);
