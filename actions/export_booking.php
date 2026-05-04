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

if ($role === 'admin_klinik') {
    $whereParts[] = "b.klinik_id = ?";
    $types .= "i";
    $params[] = (int)($_SESSION['klinik_id'] ?? 0);
    $whereParts[] = "b.status = 'booked' AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
}
if ($filter_today) {
    $whereParts[] = "b.tanggal_pemeriksaan = CURDATE()";
}
if ($filter_start !== '') {
    $whereParts[] = "b.tanggal_pemeriksaan >= ?";
    $types .= "s";
    $params[] = $filter_start;
}
if ($filter_end !== '') {
    $whereParts[] = "b.tanggal_pemeriksaan <= ?";
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

$filename = "Export_Booking_" . date('YmdHis') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Output UTF-8 BOM for Excel to open symbols properly
echo "\xEF\xBB\xBF";

echo '<table border="1">';
echo '<tr>';
echo '<th>No. Booking</th>';
echo '<th>Tanggal Pemeriksaan</th>';
echo '<th>Jam Layanan</th>';
echo '<th>Nama Pasien</th>';
echo '<th>Nomor Tlp</th>';
echo '<th>Tgl Lahir</th>';
echo '<th>Total Pax</th>';
echo '<th>Jenis Pemeriksaan</th>';
echo '<th>Klinik</th>';
echo '<th>Status Tujuan</th>';
echo '<th>Jotform</th>';
echo '<th>CS</th>';
echo '<th>Status Data</th>';
echo '<th>Tipe</th>';
echo '<th>FU Jadwal</th>';
echo '<th>Tanggal Dibuat</th>';
echo '</tr>';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $jf = (int)($row['jotform_submitted'] ?? 0) === 1 ? 'Sudah' : 'Belum';
        $fu = (int)($row['butuh_fu'] ?? 0) === 1 ? 'Ya' : 'Tidak';
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['nomor_booking'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['tanggal_pemeriksaan'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['jam_layanan'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['nama_pemesan'] ?? '-') . '</td>';
        echo '<td>="' . htmlspecialchars($row['nomor_tlp'] ?? '') . '"</td>';
        echo '<td>' . htmlspecialchars($row['tanggal_lahir'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['jumlah_pax'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars($row['jenis_pemeriksaan'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['nama_klinik'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['status_booking'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($jf) . '</td>';
        echo '<td>' . htmlspecialchars($row['cs_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($row['status'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst(strtolower($row['booking_type'] ?? 'keep'))) . '</td>';
        echo '<td>' . htmlspecialchars($fu) . '</td>';
        echo '<td>' . htmlspecialchars($row['created_at'] ?? '-') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="17">Tidak ada data</td></tr>';
}
echo '</table>';
?>


