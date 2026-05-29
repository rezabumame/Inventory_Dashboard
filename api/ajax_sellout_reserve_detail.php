<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$type      = trim((string)($_GET['type']      ?? ''));
$barang_id = (int)($_GET['barang_id'] ?? 0);
$klinik_id = $_GET['klinik_id'] ?? '0';   // may be 'all' or int
$sync_after = trim((string)($_GET['sync_after'] ?? ''));  // Y-m-d H:i:s

$valid_types = ['sellout_clinic','sellout_hc','reserve_clinic','reserve_hc'];
if (!in_array($type, $valid_types, true) || $barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']); exit;
}

// ── Klinik filter ─────────────────────────────────────────────────────────────
if ($klinik_id === 'all' || (int)$klinik_id === 0) {
    $klinik_filter = "1=1";
} else {
    $kid = (int)$klinik_id;
    $klinik_filter = "bp.klinik_id = $kid";
    $pb_klinik_filter = "pb.klinik_id = $kid";
}

if ($klinik_id !== 'all' && (int)$klinik_id > 0) {
    $kid = (int)$klinik_id;
    $bp_filter  = "bp.klinik_id = $kid";
    $pb_filter  = "pb.klinik_id = $kid";
} else {
    $bp_filter  = "1=1";
    $pb_filter  = "1=1";
}

// ── Month start (same logic as stok_klinik.php) ───────────────────────────────
$month_start = date('Y-m-01');

// ── Build query per type ──────────────────────────────────────────────────────
$rows = [];

if ($type === 'sellout_clinic' || $type === 'sellout_hc') {
    $jenis_filter = ($type === 'sellout_hc') ? "pb.jenis_pemakaian = 'hc'" : "pb.jenis_pemakaian != 'hc'";

    $sync_filter = '';
    if ($sync_after !== '') {
        $sa = $conn->real_escape_string($sync_after);
        $ms = $conn->real_escape_string($month_start);
        $sync_filter = "AND pb.tanggal >= '$ms' AND pb.created_at > '$sa'";
    }

    $sql = "
        SELECT
            pb.id            AS bhp_id,
            pb.nomor_pemakaian,
            pb.tanggal,
            pb.jenis_pemakaian,
            pb.is_auto,
            pb.created_at,
            pb.change_actor_name,
            u.nama_lengkap   AS created_by_name,
            CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END AS qty
        FROM inventory_transaksi_stok ts
        JOIN inventory_pemakaian_bhp pb ON pb.id = ts.referensi_id
        LEFT JOIN inventory_users u ON u.id = pb.created_by
        WHERE ts.referensi_tipe = 'pemakaian_bhp'
          AND ts.barang_id = $barang_id
          AND $pb_filter
          AND $jenis_filter
          AND pb.status = 'active'
          $sync_filter
        ORDER BY pb.tanggal DESC, pb.created_at DESC
        LIMIT 200
    ";

    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = [
            'bhp_id'        => (int)$r['bhp_id'],
            'nomor'         => $r['nomor_pemakaian'] ?? '-',
            'tanggal'       => $r['tanggal'] ? date('d M Y', strtotime($r['tanggal'])) : '-',
            'jenis'         => $r['is_auto'] ? 'BHP-AUT' : strtoupper((string)$r['jenis_pemakaian']),
            'qty'           => (float)$r['qty'],
            'petugas'       => $r['change_actor_name'] ?: ($r['created_by_name'] ?: '-'),
        ];
    }

} elseif ($type === 'reserve_clinic' || $type === 'reserve_hc') {
    $status_filter  = "bp.status IN ('booked','rescheduled','pending_edit')";
    $booking_filter = ($type === 'reserve_hc')
        ? "bp.status_booking LIKE '%HC%'"
        : "bp.status_booking LIKE '%Clinic%'";
    $qty_col = ($type === 'reserve_hc')
        ? "CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END"
        : "CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END";

    $today = date('Y-m-d');
    $reserve_window = trim((string)($_GET['reserve_window'] ?? '30'));
    if (!in_array($reserve_window, ['7', '30', 'month', 'all'], true)) $reserve_window = '30';
    switch ($reserve_window) {
        case '7':     $reserve_end = date('Y-m-d', strtotime('+7 days')); break;
        case 'month': $reserve_end = date('Y-m-t'); break;
        case 'all':   $reserve_end = ''; break;
        default:      $reserve_end = date('Y-m-d', strtotime('+30 days')); break;
    }
    $date_filter = "AND bp.tanggal_pemeriksaan >= '$today'" . ($reserve_end !== '' ? " AND bp.tanggal_pemeriksaan <= '$reserve_end'" : '');

    $sql = "
        SELECT
            bp.nomor_booking,
            bp.nama_pemesan,
            bp.tanggal_pemeriksaan,
            bp.status_booking,
            bp.status,
            bp.nakes_hc,
            SUM($qty_col) AS qty
        FROM inventory_booking_detail bd
        JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
        WHERE $bp_filter
          AND $status_filter
          AND $booking_filter
          AND bd.barang_id = $barang_id
          $date_filter
        GROUP BY bp.nomor_booking, bp.nama_pemesan, bp.tanggal_pemeriksaan,
                 bp.status_booking, bp.status, bp.nakes_hc
        ORDER BY bp.tanggal_pemeriksaan ASC
        LIMIT 200
    ";

    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = [
            'nomor'          => $r['nomor_booking'] ?? '-',
            'nama_pemesan'   => $r['nama_pemesan'] ?? '-',
            'nakes'          => $r['nakes_hc'] ?: '-',
            'tanggal'        => $r['tanggal_pemeriksaan'] ? date('d M Y', strtotime($r['tanggal_pemeriksaan'])) : '-',
            'status_booking' => $r['status_booking'] ?? '-',
            'status'         => $r['status'] ?? '-',
            'qty'            => (float)$r['qty'],
        ];
    }
}

$total = array_sum(array_column($rows, 'qty'));

echo json_encode([
    'success' => true,
    'type'    => $type,
    'rows'    => $rows,
    'total'   => $total,
]);
