<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'cs', 'petugas_hc'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$barang_id = (int)($_POST['barang_id'] ?? 0);
$tanggal = (string)($_POST['tanggal'] ?? '');

if ($klinik_id <= 0 || $barang_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

$today = date('Y-m-d');
if (strtotime($tanggal) > strtotime($today)) $tanggal = $today;
$month_start = date('Y-m-01', strtotime($tanggal));
$month_end = date('Y-m-t', strtotime($tanggal));
$tanggal_end_ts = $tanggal . ' 23:59:59';
$month_start_ts = $month_start . ' 00:00:00';

$kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    echo json_encode(['success' => false, 'message' => 'Klinik not found'], JSON_UNESCAPED_UNICODE);
    exit;
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

$kode_klinik = (string)($kl['kode_klinik'] ?? '');
$kode_homecare = (string)($kl['kode_homecare'] ?? '');
$kode_barang = trim((string)($b['kode_barang'] ?? ''));
$odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));

$loc_k = $conn->real_escape_string($kode_klinik);
$loc_h = $conn->real_escape_string($kode_homecare);

$last_update_klinik = '';
if ($kode_klinik !== '') {
    $r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE location_code = '$loc_k'");
    if ($r && $r->num_rows > 0) $last_update_klinik = (string)($r->fetch_assoc()['last_update'] ?? '');
}
$last_update_hc = '';
if ($kode_homecare !== '') {
    $r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE location_code = '$loc_h'");
    if ($r && $r->num_rows > 0) $last_update_hc = (string)($r->fetch_assoc()['last_update'] ?? '');
}

$last_update_text = '';
$max_u = $last_update_klinik;
if ($last_update_hc !== '' && ($max_u === '' || strtotime($last_update_hc) > strtotime($max_u))) $max_u = $last_update_hc;
if ($max_u !== '') $last_update_text = date('d M Y H:i', strtotime($max_u));

$kb_esc = $conn->real_escape_string($kode_barang);
$oid_esc = $conn->real_escape_string($odoo_product_id);
$match = [];
if ($kode_barang !== '') $match[] = "TRIM(kode_barang) = '$kb_esc'";
if ($odoo_product_id !== '') $match[] = "TRIM(odoo_product_id) = '$oid_esc'";
if (empty($match)) $match[] = "1=0";
$match_sql = '(' . implode(' OR ', $match) . ')';

$baseline_onsite = 0.0;
if ($kode_klinik !== '') {
    $r = $conn->query("SELECT COALESCE(MAX(qty),0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc_k' AND $match_sql");
    if ($r && $r->num_rows > 0) $baseline_onsite = (float)($r->fetch_assoc()['qty'] ?? 0);
}
$baseline_hc = 0.0;
if ($kode_homecare !== '') {
    $r = $conn->query("SELECT COALESCE(MAX(qty),0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc_h' AND $match_sql");
    if ($r && $r->num_rows > 0) $baseline_hc = (float)($r->fetch_assoc()['qty'] ?? 0);
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
          AND ts.level_id = $klinik_id
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
          AND ts.level_id = $klinik_id
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
          AND ts.level_id = $klinik_id
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
          AND ts.level_id = $klinik_id
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
            SUM(pbd.qty) AS qty
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_pemakaian_bhp_detail pbd ON pbd.pemakaian_bhp_id = pb.id
        WHERE pb.klinik_id = $klinik_id
          AND pbd.barang_id = $barang_id
          AND pb.created_at > '$rs' AND pb.created_at <= '$re'
          AND pb.created_at >= '$ms'
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
          AND ts.level_id = $klinik_id
          AND ts.referensi_tipe IN ('transfer', 'hc_petugas_transfer')
          AND ts.created_at > '$rs' AND ts.created_at <= '$re'
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

$r = $conn->query("
    SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END), 0) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id = $klinik_id
      AND bp.status = 'booked'
      AND bp.status_booking LIKE '%Clinic%'
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'
");
if ($r && $r->num_rows > 0) $reserve['onsite'] = (float)($r->fetch_assoc()['qty'] ?? 0);

$r = $conn->query("
    SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END), 0) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id = $klinik_id
      AND bp.status = 'booked'
      AND bp.status_booking LIKE '%HC%'
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'
");
if ($r && $r->num_rows > 0) $reserve['hc'] = (float)($r->fetch_assoc()['qty'] ?? 0);

$r = $conn->query("
    SELECT bp.nomor_booking, bp.tanggal_pemeriksaan, bp.status_booking, SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bd.barang_id = $barang_id
      AND bp.klinik_id = $klinik_id
      AND bp.status = 'booked'
      AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($tanggal) . "'
      AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'
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
        SUM(pbd.qty) AS qty
    FROM inventory_pemakaian_bhp pb
    JOIN inventory_pemakaian_bhp_detail pbd ON pbd.pemakaian_bhp_id = pb.id
    WHERE pb.klinik_id = $klinik_id
      AND pbd.barang_id = $barang_id
      AND pb.tanggal >= '$ms_q' AND pb.tanggal <= '$t_q'
    GROUP BY pb.id, pb.nomor_pemakaian, pb.tanggal, pb.created_at, pb.jenis_pemakaian
    ORDER BY pb.tanggal ASC, pb.id ASC
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
        'kode_klinik' => $kode_klinik,
        'kode_homecare' => $kode_homecare
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
    'rollback' => $rb,
    'reserve' => $reserve,
    'result' => [
        'stock_onsite' => $stock_as_of_onsite,
        'stock_hc' => $stock_as_of_hc,
        'stock_total' => $stock_as_of_onsite + $stock_as_of_hc,
        'tersedia' => ($stock_as_of_onsite + $stock_as_of_hc) - $reserve['onsite'] - $reserve['hc']
    ]
], JSON_UNESCAPED_UNICODE);
