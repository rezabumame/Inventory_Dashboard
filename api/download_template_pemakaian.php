<?php
/**
 * Template Excel Pemakaian BHP (10 kolom).
 * Mengisi baris dari pemakaian AUTO yang belum ada realisasi manual (sama logika dengan peringatan di halaman list),
 * untuk rentang tanggal filter (GET start_date, end_date) — default 7 hari terakhir.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied';
    exit;
}

$user_role = (string)($_SESSION['role'] ?? '');
$user_klinik_id = isset($_SESSION['klinik_id']) ? (int)$_SESSION['klinik_id'] : 0;

$end_date = isset($_GET['end_date']) ? (string)$_GET['end_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? (string)$_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}

$where_clause = '1=1';
$params = [];
$types = '';

if ($user_role === 'admin_klinik' && $user_klinik_id > 0) {
    $where_clause .= ' AND pb.klinik_id = ?';
    $params[] = $user_klinik_id;
    $types .= 'i';
} elseif ($user_role === 'spv_klinik' && $user_klinik_id > 0) {
    $where_clause .= ' AND pb.klinik_id = ?';
    $params[] = $user_klinik_id;
    $types .= 'i';
}

$where_clause .= ' AND pb.tanggal >= ?';
$params[] = $start_date . ' 00:00:00';
$types .= 's';
$where_clause .= ' AND pb.tanggal <= ?';
$params[] = $end_date . ' 23:59:59';
$types .= 's';

$missed_uploads = [];
$q_missed = "
    SELECT 
        DATE(pb.tanggal) AS tgl,
        pb.klinik_id,
        pb.jenis_pemakaian,
        k.nama_klinik
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    WHERE $where_clause
    GROUP BY DATE(pb.tanggal), pb.klinik_id, pb.jenis_pemakaian
    HAVING SUM(pb.is_auto = 1) > 0
    ORDER BY tgl DESC, k.nama_klinik ASC
    LIMIT 200
";
$stmt_missed = $conn->prepare($q_missed);
if (!empty($params)) {
    $stmt_missed->bind_param($types, ...$params);
}
$stmt_missed->execute();
$res_missed = $stmt_missed->get_result();
while ($rm = $res_missed->fetch_assoc()) {
    $missed_uploads[] = $rm;
}

function format_template_appointment(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    // Contoh upload: "04 March 2026, 16:00"
    return date('j', $ts) . ' ' . date('F', $ts) . ' ' . date('Y', $ts) . ', ' . date('H:i', $ts);
}

$headers = [
    'Tanggal Appointment',
    'Appointment Patient ID',
    'Nama Pasien',
    'Layanan',
    'Nama Item BHP',
    'Jumlah',
    'Satuan (UoM)',
    'Nama Nakes',
    'Nakes Branch',
    'Jenis Pemakaian',
    'Kode Barang',
];

$data = [$headers];

if (!empty($missed_uploads)) {
    $stmt_lines = $conn->prepare("
        SELECT
            pb.tanggal,
            pb.jenis_pemakaian,
            pb.nomor_pemakaian,
            pb.booking_id,
            pbd.qty,
            COALESCE(
                NULLIF(TRIM(pbd.satuan), ''),
                (SELECT c.to_uom FROM inventory_barang_uom_conversion c WHERE c.kode_barang = b.kode_barang LIMIT 1),
                b.satuan,
                ''
            ) AS satuan_row,
            b.kode_barang,
            b.nama_barang,
            k.nama_klinik,
            u.nama_lengkap AS nakes_user_name,
            bp.nomor_booking,
            bp.order_id,
            bp.nakes_hc,
            (SELECT GROUP_CONCAT(DISTINCT bpp.nama_pasien ORDER BY bpp.id SEPARATOR ', ')
             FROM inventory_booking_pasien bpp WHERE bpp.booking_id = pb.booking_id) AS nama_pasien_list,
            (SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ')
             FROM inventory_booking_pasien bpp2
             JOIN inventory_pemeriksaan_grup pg ON bpp2.pemeriksaan_grup_id = pg.id
             WHERE bpp2.booking_id = pb.booking_id) AS layanan_list
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_pemakaian_bhp_detail pbd ON pbd.pemakaian_bhp_id = pb.id
        JOIN inventory_barang b ON b.id = pbd.barang_id
        JOIN inventory_klinik k ON k.id = pb.klinik_id
        LEFT JOIN inventory_users u ON u.id = pb.user_hc_id
        LEFT JOIN inventory_booking_pemeriksaan bp ON bp.id = pb.booking_id
        WHERE pb.is_auto = 1
          AND DATE(pb.tanggal) = ?
          AND pb.klinik_id = ?
          AND pb.jenis_pemakaian = ?
        ORDER BY pb.id ASC, pbd.id ASC
    ");

    foreach ($missed_uploads as $mu) {
        $tgl = (string)($mu['tgl'] ?? '');
        $kid = (int)($mu['klinik_id'] ?? 0);
        $jenis = (string)($mu['jenis_pemakaian'] ?? 'klinik');
        if ($tgl === '' || $kid <= 0) {
            continue;
        }
        $stmt_lines->bind_param('sis', $tgl, $kid, $jenis);
        $stmt_lines->execute();
        $res_lines = $stmt_lines->get_result();
        while ($row = $res_lines->fetch_assoc()) {
            $branch = trim((string)($row['nama_klinik'] ?? ''));
            $patient_id = trim((string)($row['order_id'] ?? ''));
            if ($patient_id === '') {
                $patient_id = trim((string)($row['nomor_booking'] ?? ''));
            }
            if ($patient_id === '') {
                $patient_id = 'AUTO-' . (string)($row['nomor_pemakaian'] ?? '');
            }
            $nama_pasien = trim((string)($row['nama_pasien_list'] ?? ''));
            if ($nama_pasien === '') {
                $nama_pasien = 'Auto BHP';
            }
            $nakes = trim((string)($row['nakes_user_name'] ?? ''));
            if ($nakes === '' && ($row['jenis_pemakaian'] ?? '') === 'hc') {
                $nakes = trim((string)($row['nakes_hc'] ?? ''));
            }
            $qty = (float)($row['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $layanan = trim((string)($row['layanan_list'] ?? ''));
            if ($layanan === '') {
                $layanan = 'Auto (Booking / sistem)';
            }
            $data[] = [
                format_template_appointment((string)($row['tanggal'] ?? $tgl . ' 00:00:00')),
                $patient_id,
                $nama_pasien,
                $layanan,
                (string)($row['nama_barang'] ?? ''),
                (string)$qty,
                (string)($row['satuan_row'] ?? ''),
                $nakes,
                $branch,
                strtoupper((string)($row['jenis_pemakaian'] ?? 'KLINIK')),
                strtolower(trim((string)($row['kode_barang'] ?? ''))),
            ];
        }
    }
}

// Fallback sample rows jika tidak ada gap (template tetap bisa dipakai)
if (count($data) < 2) {
    $data[] = ['04 March 2026, 16:00', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Alcohol Swab 70% (new)', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng', 'bhp001'];
    $data[] = ['04 March 2026, 16:00', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Vaksin Influvac Tetra NH (Abbott)', '1', 'Vial', 'Dieriska Janurefa', 'Cideng', 'bhp002'];
}

$xlsx = SimpleXLSXGen::fromArray($data);
$xlsx->setColWidth(1, 25);
$xlsx->setColWidth(2, 20);
$xlsx->setColWidth(3, 25);
$xlsx->setColWidth(4, 35);
$xlsx->setColWidth(5, 35);
$xlsx->setColWidth(6, 10);
$xlsx->setColWidth(7, 15);
$xlsx->setColWidth(8, 25);
$xlsx->setColWidth(9, 28);
$xlsx->setColWidth(10, 18);
$xlsx->setColWidth(11, 15);

$suffix = !empty($missed_uploads) ? '_gap_auto_' . preg_replace('/[^0-9-]/', '', $start_date) . '_' . preg_replace('/[^0-9-]/', '', $end_date) : '';
$filename = 'Template_BHP_Baru' . $suffix . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$xlsx->downloadAs($filename);
exit;
