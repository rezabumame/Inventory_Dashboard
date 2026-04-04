<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'cs'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
$status_booking = trim((string)($_GET['status_booking'] ?? ''));
if ($klinik_id <= 0) {
    echo json_encode([]);
    exit;
}
if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$dest = (stripos($status_booking, 'hc') !== false) ? 'hc' : 'klinik';

$kl = $conn->query("SELECT id, kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    echo json_encode([]);
    exit;
}

$kode_klinik = trim((string)($kl['kode_klinik'] ?? ''));
$kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
$loc_code = $dest === 'hc' ? $kode_homecare : $kode_klinik;
if ($loc_code === '') {
    echo json_encode([]);
    exit;
}
$loc_esc = $conn->real_escape_string($loc_code);

$last_update = '';
$r = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror WHERE TRIM(location_code) = '$loc_esc'");
if ($r && $r->num_rows > 0) $last_update = (string)($r->fetch_assoc()['last_update'] ?? '');

$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$today = date('Y-m-d');
$month_start_esc = $conn->real_escape_string($month_start);
$month_end_esc = $conn->real_escape_string($month_end);
$today_esc = $conn->real_escape_string($today);
$last_update_esc = $last_update !== '' ? $conn->real_escape_string($last_update) : '';

$exams = [];
$res_ex = $conn->query("SELECT id, nama_pemeriksaan FROM pemeriksaan_grup ORDER BY nama_pemeriksaan ASC");
while ($res_ex && ($row = $res_ex->fetch_assoc())) {
    $exams[(int)$row['id']] = [
        'id' => (int)$row['id'],
        'name' => (string)($row['nama_pemeriksaan'] ?? ''),
        'ingredients' => []
    ];
}

$res_det = $conn->query("SELECT pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail");
while ($res_det && ($d = $res_det->fetch_assoc())) {
    $gid = (int)($d['pemeriksaan_grup_id'] ?? 0);
    if (!isset($exams[$gid])) continue;
    $exams[$gid]['ingredients'][] = [
        'barang_id' => (int)($d['barang_id'] ?? 0),
        'req' => (float)($d['qty_per_pemeriksaan'] ?? 0)
    ];
}

$needed_barang_ids = [];
foreach ($exams as $ex) {
    foreach ($ex['ingredients'] as $ing) {
        $bid = (int)$ing['barang_id'];
        if ($bid > 0) $needed_barang_ids[$bid] = true;
    }
}
if (empty($needed_barang_ids)) {
    echo json_encode([]);
    exit;
}
$bid_list = implode(',', array_map('intval', array_keys($needed_barang_ids)));

$stock_map = [];
$q = "
SELECT
    b.id AS barang_id,
    COALESCE(sm.qty, 0) * COALESCE(uc.multiplier, 1) AS baseline_qty
FROM barang b
LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
LEFT JOIN stock_mirror sm
  ON TRIM(sm.location_code) = '$loc_esc'
 AND (
      (TRIM(b.odoo_product_id) <> '' AND sm.odoo_product_id = b.odoo_product_id)
      OR (TRIM(b.kode_barang) <> '' AND TRIM(sm.kode_barang) = TRIM(b.kode_barang))
 )
WHERE b.id IN ($bid_list)
GROUP BY b.id, uc.multiplier, sm.qty
";
$rs = $conn->query($q);
while ($rs && ($row = $rs->fetch_assoc())) {
    $stock_map[(int)$row['barang_id']] = (float)($row['baseline_qty'] ?? 0);
}

$sellout_map = [];
$sellout_filter = "pb.tanggal >= '$month_start_esc' AND pb.tanggal <= '$month_end_esc'";
if ($last_update_esc !== '') $sellout_filter .= " AND pb.created_at > '$last_update_esc'";
$jenis_cond = $dest === 'hc' ? "pb.jenis_pemakaian = 'hc'" : "pb.jenis_pemakaian <> 'hc'";
$r = $conn->query("
    SELECT pbd.barang_id, COALESCE(SUM(pbd.qty), 0) AS qty
    FROM pemakaian_bhp_detail pbd
    JOIN pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
    WHERE pb.klinik_id = $klinik_id
      AND $jenis_cond
      AND $sellout_filter
      AND pbd.barang_id IN ($bid_list)
    GROUP BY pbd.barang_id
");
while ($r && ($row = $r->fetch_assoc())) $sellout_map[(int)$row['barang_id']] = (float)($row['qty'] ?? 0);

$reserve_map = [];
$reserve_cond = $dest === 'hc' ? "bp.status_booking LIKE '%HC%'" : "bp.status_booking LIKE '%Clinic%'";
$reserve_field = $dest === 'hc' ? "CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END" : "CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END";
$r = $conn->query("
    SELECT bd.barang_id, COALESCE(SUM($reserve_field), 0) AS qty
    FROM booking_detail bd
    JOIN booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE bp.klinik_id = $klinik_id
      AND bp.status = 'booked'
      AND $reserve_cond
      AND bp.tanggal_pemeriksaan >= '$today_esc'
      AND bp.tanggal_pemeriksaan <= '$month_end_esc'
      AND bd.barang_id IN ($bid_list)
    GROUP BY bd.barang_id
");
while ($r && ($row = $r->fetch_assoc())) $reserve_map[(int)$row['barang_id']] = (float)($row['qty'] ?? 0);

$transfer_in_map = [];
$transfer_out_map = [];
if ($last_update_esc !== '') {
    if ($dest === 'hc') {
        $r = $conn->query("
            SELECT ts.barang_id,
                   COALESCE(SUM(CASE WHEN ts.tipe_transaksi='in' THEN ts.qty ELSE 0 END), 0) AS qty_in,
                   COALESCE(SUM(CASE WHEN ts.tipe_transaksi='out' THEN ts.qty ELSE 0 END), 0) AS qty_out
            FROM transaksi_stok ts
            WHERE ts.level = 'hc'
              AND ts.level_id = $klinik_id
              AND ts.referensi_tipe = 'hc_petugas_transfer'
              AND ts.created_at > '$last_update_esc'
              AND ts.created_at >= '" . $conn->real_escape_string($month_start . " 00:00:00") . "'
              AND ts.barang_id IN ($bid_list)
            GROUP BY ts.barang_id
        ");
    } else {
        $r = $conn->query("
            SELECT ts.barang_id,
                   COALESCE(SUM(CASE WHEN ts.tipe_transaksi='in' THEN ts.qty ELSE 0 END), 0) AS qty_in,
                   COALESCE(SUM(CASE WHEN ts.tipe_transaksi='out' THEN ts.qty ELSE 0 END), 0) AS qty_out
            FROM transaksi_stok ts
            WHERE ts.level = 'klinik'
              AND ts.level_id = $klinik_id
              AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')
              AND ts.created_at > '$last_update_esc'
              AND ts.created_at >= '" . $conn->real_escape_string($month_start . " 00:00:00") . "'
              AND ts.barang_id IN ($bid_list)
            GROUP BY ts.barang_id
        ");
    }
    while ($r && ($row = $r->fetch_assoc())) {
        $bid = (int)($row['barang_id'] ?? 0);
        $transfer_in_map[$bid] = (float)($row['qty_in'] ?? 0);
        $transfer_out_map[$bid] = (float)($row['qty_out'] ?? 0);
    }
}

$available_barang = [];
foreach ($needed_barang_ids as $bid => $_t) {
    $base = (float)($stock_map[$bid] ?? 0);
    $sell = (float)($sellout_map[$bid] ?? 0);
    $resv = (float)($reserve_map[$bid] ?? 0);
    $tin = (float)($transfer_in_map[$bid] ?? 0);
    $tout = (float)($transfer_out_map[$bid] ?? 0);
    $avail = $base + $tin - $tout - $sell - $resv;
    if ($avail < 0) $avail = 0;
    $available_barang[$bid] = $avail;
}

$out = [];
foreach ($exams as $ex) {
    $ings = $ex['ingredients'];
    if (empty($ings)) continue;
    $max_possible = 999999;
    foreach ($ings as $ing) {
        $bid = (int)$ing['barang_id'];
        $req = (float)$ing['req'];
        if ($bid <= 0 || $req <= 0) {
            $max_possible = 0;
            break;
        }
        $avail = (float)($available_barang[$bid] ?? 0);
        if ($avail < $req) {
            $max_possible = 0;
            break;
        }
        $possible = (int)floor($avail / $req);
        if ($possible < $max_possible) $max_possible = $possible;
    }
    if ($max_possible > 0 && $max_possible < 999999) {
        $out[] = ['id' => $ex['id'], 'name' => $ex['name'], 'qty' => $max_possible];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

