<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'cs', 'admin_klinik', 'admin_gudang'], true)) {
    die("Access denied");
}

$filter_today = isset($_GET['filter_today']) ? ($_GET['filter_today'] == '1') : false;
$filter_tujuan = (string)($_GET['tujuan'] ?? '');
$filter_status = (string)($_GET['status'] ?? '');
$filter_tipe = (string)($_GET['tipe'] ?? '');
$filter_fu = (string)($_GET['fu'] ?? '');
$filter_start = (string)($_GET['start_date'] ?? '');
$filter_end = (string)($_GET['end_date'] ?? '');
$has_filters = (isset($_GET['filter_today']) || $filter_tujuan !== '' || $filter_status !== '' || $filter_tipe !== '' || $filter_fu !== '' || $filter_start !== '' || $filter_end !== '');
if (!$has_filters) $filter_today = true;

$where = "1=1";
if ($role === 'admin_klinik') {
    $where .= " AND b.klinik_id = " . $_SESSION['klinik_id'];
    $where .= " AND b.status = 'booked' AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
}
if ($filter_today) {
    $where .= " AND DATE(b.created_at) = CURDATE()";
}
if ($filter_start !== '') {
    $where .= " AND b.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_start) . "'";
}
if ($filter_end !== '') {
    $where .= " AND b.tanggal_pemeriksaan <= '" . $conn->real_escape_string($filter_end) . "'";
}
if ($filter_tujuan === 'clinic') {
    $where .= " AND b.status_booking LIKE '%Clinic%'";
} elseif ($filter_tujuan === 'hc') {
    $where .= " AND b.status_booking LIKE '%HC%'";
}
if (in_array($filter_status, ['booked', 'completed', 'cancelled'], true)) {
    $where .= " AND b.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if (in_array($filter_tipe, ['keep', 'fixed', 'cancel'], true)) {
    $where .= " AND LOWER(COALESCE(b.booking_type, 'keep')) = '" . $conn->real_escape_string($filter_tipe) . "'";
}
if ($filter_fu === '1') {
    $where .= " AND b.status = 'booked' AND b.butuh_fu = 1";
}

$query = "SELECT b.*, k.nama_klinik,
          (SELECT COUNT(DISTINCT bd.barang_id) FROM booking_detail bd WHERE bd.booking_id = b.id) as total_items,
          (SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ')
           FROM booking_pasien bp
           JOIN pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
           WHERE bp.booking_id = b.id) as jenis_pemeriksaan
          FROM booking_pemeriksaan b 
          JOIN klinik k ON b.klinik_id = k.id 
          WHERE $where
          ORDER BY b.tanggal_pemeriksaan ASC, b.id DESC";
$result = $conn->query($query);

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
echo '<th>Total Items</th>';
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
        echo '<td>' . htmlspecialchars($row['nomor_booking']) . '</td>';
        echo '<td>' . htmlspecialchars($row['tanggal_pemeriksaan']) . '</td>';
        echo '<td>' . htmlspecialchars($row['jam_layanan'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['nama_pemesan']) . '</td>';
        echo '<td>="' . htmlspecialchars($row['nomor_tlp']) . '"</td>';
        echo '<td>' . htmlspecialchars($row['tanggal_lahir'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['jumlah_pax']) . '</td>';
        echo '<td>' . htmlspecialchars($row['total_items']) . '</td>';
        echo '<td>' . htmlspecialchars($row['jenis_pemeriksaan']) . '</td>';
        echo '<td>' . htmlspecialchars($row['nama_klinik']) . '</td>';
        echo '<td>' . htmlspecialchars($row['status_booking']) . '</td>';
        echo '<td>' . htmlspecialchars($jf) . '</td>';
        echo '<td>' . htmlspecialchars($row['cs_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($row['status'])) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst(strtolower($row['booking_type'] ?? 'keep'))) . '</td>';
        echo '<td>' . htmlspecialchars($fu) . '</td>';
        echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="17">Tidak ada data</td></tr>';
}
echo '</table>';
?>

