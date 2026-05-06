<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'cs', 'admin_klinik', 'admin_gudang'], true)) {
    die("Access denied");
}

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$filter_today = isset($_GET['filter_today']) ? ($_GET['filter_today'] == '1') : false;
$filter_tujuan = (string)($_GET['tujuan'] ?? '');
$filter_status = (string)($_GET['status'] ?? '');
$filter_tipe = (string)($_GET['tipe'] ?? '');
$filter_fu = (string)($_GET['fu'] ?? '');
$filter_start = (string)($_GET['start_date'] ?? '');
$filter_end = (string)($_GET['end_date'] ?? '');
$filter_q = trim((string)($_GET['q'] ?? ''));
$has_filters = ($show_all || isset($_GET['filter_today']) || $filter_tujuan !== '' || $filter_status !== '' || $filter_tipe !== '' || $filter_fu !== '' || $filter_start !== '' || $filter_end !== '' || $filter_q !== '');

if (!$has_filters) {
    if ($role === 'admin_klinik') {
        $filter_today = true;
    } else {
        $show_all = true;
        $filter_today = false;
    }
}
if ($show_all) $filter_today = false;

// Build WHERE with prepared params (avoid SQL injection)
$whereParts = ["1=1"];
$types = "";
$params = [];

// Strict date format (Y-m-d). If invalid, ignore to preserve behavior without throwing.
if ($filter_start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_start)) $filter_start = '';
if ($filter_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_end)) $filter_end = '';

$is_input_date = isset($_GET['is_input_date']) && $_GET['is_input_date'] == '1';

if ($role === 'admin_klinik') {
    $whereParts[] = "b.klinik_id = ?";
    $types .= "i";
    $params[] = (int)($_SESSION['klinik_id'] ?? 0);
    // Removed strict 'booked' filter for input_date exports to allow broader reporting if needed, 
    // but preserving original behavior for normal filters.
    if (!$is_input_date) {
        $whereParts[] = "b.status = 'booked' AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
    }
}
if ($filter_today && !$is_input_date) {
    $whereParts[] = "b.tanggal_pemeriksaan = CURDATE()";
}

$dateField = $is_input_date ? "DATE(b.created_at)" : "b.tanggal_pemeriksaan";

if ($filter_start !== '') {
    $whereParts[] = "$dateField >= ?";
    $types .= "s";
    $params[] = $filter_start;
}
if ($filter_end !== '') {
    $whereParts[] = "$dateField <= ?";
    $types .= "s";
    $params[] = $filter_end;
}
if ($filter_tujuan === 'clinic') {
    $whereParts[] = "b.status_booking LIKE '%Clinic%'";
} elseif ($filter_tujuan === 'hc') {
    $whereParts[] = "b.status_booking LIKE '%HC%'";
}
if (in_array($filter_status, ['booked', 'completed', 'cancelled'], true)) {
    $whereParts[] = "b.status = ?";
    $types .= "s";
    $params[] = $filter_status;
}
if (in_array($filter_tipe, ['keep', 'fixed', 'cancel'], true)) {
    $whereParts[] = "LOWER(COALESCE(b.booking_type, 'keep')) = ?";
    $types .= "s";
    $params[] = $filter_tipe;
}
if ($filter_fu === '1') {
    $whereParts[] = "b.status = 'booked' AND b.butuh_fu = 1";
}
if ($filter_q !== '') {
    $whereParts[] = "(b.nama_pemesan LIKE ? OR b.nomor_booking LIKE ? OR u.nama_lengkap LIKE ?)";
    $types .= "sss";
    $q_param = "%$filter_q%";
    $params[] = $q_param;
    $params[] = $q_param;
    $params[] = $q_param;
}

$query = "SELECT b.*, k.nama_klinik, u.nama_lengkap as cs_name,
          (SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ')
           FROM inventory_booking_pasien bp
           JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
           WHERE bp.booking_id = b.id) as jenis_pemeriksaan
          FROM inventory_booking_pemeriksaan b 
          JOIN inventory_klinik k ON b.klinik_id = k.id 
          LEFT JOIN inventory_users u ON b.created_by = u.id
          WHERE " . implode(" AND ", $whereParts) . "
          ORDER BY b.tanggal_pemeriksaan ASC, b.id DESC";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Query prepare failed");
}
if ($types !== '') {
    // mysqli bind_param requires references
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
$stmt->execute();
$result = $stmt->get_result();

require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSXGen;

$header = [
    'No. Booking',
    'ID Order',
    'Status Tujuan',
    'Klinik',
    'Tanggal Pemeriksaan',
    'Jam Layanan',
    'Nama Pasien',
    'Flag Pasien',
    'Nomor Tlp',
    'Tgl Lahir',
    'Total Pax',
    'Pemeriksaan Pasien',
    'Jotform',
    'CS',
    'Status Data',
    'Tipe',
    'FU Jadwal',
    'Tanggal Dibuat'
];

$data = [$header];
$booking_ids = [];
$rows = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $booking_ids[] = (int)$row['id'];
        $rows[] = $row;
    }
}

// Fetch all patients for these bookings to show individual rows
$patients_data = [];
if (!empty($booking_ids)) {
    $ids_str = implode(',', $booking_ids);
    $p_res = $conn->query("SELECT bp.*, pg.nama_pemeriksaan 
                          FROM inventory_booking_pasien bp
                          LEFT JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
                          WHERE bp.booking_id IN ($ids_str)
                          ORDER BY bp.id ASC");
    if ($p_res) {
        while ($p = $p_res->fetch_assoc()) {
            $patients_data[(int)$p['booking_id']][] = $p;
        }
    }
}

foreach ($rows as $row) {
    $bid = (int)$row['id'];
    $p_list = $patients_data[$bid] ?? [];
    $total_pax = count($p_list);
    
    $jf = (int)($row['jotform_submitted'] ?? 0) === 1 ? 'Sudah' : 'Belum';
    $fu = (int)($row['butuh_fu'] ?? 0) === 1 ? 'Ya' : 'Tidak';
    
    $st = (string)($row['status_booking'] ?? '');
    $st_short = (stripos($st, 'HC') !== false) ? 'HC' : ((stripos($st, 'Clinic') !== false) ? 'Clinic' : $st);

    if (empty($p_list)) {
        // Fallback to header info if no patients found in sub-table
        $data[] = [
            $row['nomor_booking'] ?? '-',
            $row['order_id'] ?? '-',
            $st_short,
            $row['nama_klinik'] ?? '-',
            $row['tanggal_pemeriksaan'] ?? '-',
            $row['jam_layanan'] ?? '-',
            $row['nama_pemesan'] ?? '-',
            '1 dari 1',
            $row['nomor_tlp'] ?? '',
            $row['tanggal_lahir'] ?? '-',
            (int)($row['jumlah_pax'] ?? 0),
            $row['jenis_pemeriksaan'] ?? '-',
            $jf,
            $row['cs_name'] ?? '-',
            ucfirst($row['status'] ?? ''),
            ucfirst(strtolower($row['booking_type'] ?? 'keep')),
            $fu,
            $row['created_at'] ?? '-'
        ];
    } else {
        foreach ($p_list as $i => $p) {
            $flag = ($i + 1) . " dari " . $total_pax;
            
            $data[] = [
                $row['nomor_booking'] ?? '-',
                $row['order_id'] ?? '-',
                $st_short,
                $row['nama_klinik'] ?? '-',
                $row['tanggal_pemeriksaan'] ?? '-',
                $row['jam_layanan'] ?? '-',
                $p['nama_pasien'] ?? '-',
                $flag,
                $p['nomor_tlp'] ?? '-',
                $p['tanggal_lahir'] ?? '-',
                $total_pax,
                $p['nama_pemeriksaan'] ?? '-',
                $jf,
                $row['cs_name'] ?? '-',
                ucfirst($row['status'] ?? ''),
                ucfirst(strtolower($row['booking_type'] ?? 'keep')),
                $fu,
                $row['created_at'] ?? '-'
            ];
        }
    }
}

// Fetch History Data
$history_header = [
    'No. Booking',
    'Tanggal Log',
    'User',
    'Aksi',
    'Catatan / Perubahan'
];
$history_data = [$history_header];

if (!empty($booking_ids)) {
    $ids_str = implode(',', $booking_ids);
    $h_query = "SELECT h.*, b.nomor_booking 
                FROM inventory_booking_history h
                JOIN inventory_booking_pemeriksaan b ON h.booking_id = b.id
                WHERE h.booking_id IN ($ids_str)
                ORDER BY h.created_at DESC, h.id DESC";
    $h_res = $conn->query($h_query);
    
    if ($h_res && $h_res->num_rows > 0) {
        while ($h_row = $h_res->fetch_assoc()) {
            $notes = (string)($h_row['notes'] ?? '');
            if (!empty($h_row['changes'])) {
                $changes = json_decode($h_row['changes'], true);
                if ($changes) {
                    $change_str = [];
                    foreach ($changes as $key => $val) {
                        $old = is_array($val['old'] ?? '') ? json_encode($val['old']) : ($val['old'] ?? '');
                        $new = is_array($val['new'] ?? '') ? json_encode($val['new']) : ($val['new'] ?? '');
                        $change_str[] = strtoupper($key) . ": $old -> $new";
                    }
                    $notes .= (!empty($notes) ? " | " : "") . implode("; ", $change_str);
                }
            }
            
            $history_data[] = [
                $h_row['nomor_booking'] ?? '-',
                $h_row['created_at'] ?? '-',
                $h_row['user_name'] ?? '-',
                strtoupper($h_row['action'] ?? '-'),
                $notes
            ];
        }
    }
}

$filename = "Export_Booking_" . date('YmdHis') . ".xlsx";
SimpleXLSXGen::fromArray($data, 'Data Booking')
    ->addSheet($history_data, 'Riwayat Transaksi')
    ->downloadAs($filename);
exit;
?>


