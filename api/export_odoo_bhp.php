<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

// Check access
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'petugas_hc'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die('Akses ditolak.');
}

$user_klinik_id = $_SESSION['klinik_id'] ?? null;
$user_role = $_SESSION['role'];

// Get filters
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$klinik_id = isset($_GET['klinik_id']) ? $_GET['klinik_id'] : null;

// Build query
$where_clause = "1=1";
$params = [];
$types = "";

if ($user_role === 'admin_klinik' && $user_klinik_id) {
    $where_clause .= " AND pb.klinik_id = ?";
    $params[] = $user_klinik_id;
    $types .= "i";
} elseif ($klinik_id) {
    $where_clause .= " AND pb.klinik_id = ?";
    $params[] = $klinik_id;
    $types .= "i";
}

if (!empty($start_date)) {
    $where_clause .= " AND pb.created_at >= ?";
    $params[] = $start_date . ' 00:00:00';
    $types .= "s";
}
if (!empty($end_date)) {
    $where_clause .= " AND pb.created_at <= ?";
    $params[] = $end_date . ' 23:59:59';
    $types .= "s";
}

$stmt_exp = $conn->prepare("
    SELECT 
        b.kode_barang as ID,
        b.nama_barang as NAMA_BARANG,
        ofc.product_category as NAMA_BAGIAN,
        pbd.qty as PENYESUAIAN_OPER,
        COALESCE(uc.multiplier, 1) AS UOM_RATIO,
        COALESCE(NULLIF(uc.from_uom, ''), b.satuan) AS SATUAN_ODOO,
        COALESCE(NULLIF(pbd.satuan, ''), uc.to_uom, b.satuan) AS SATUAN_OPER, 
        CASE 
            WHEN pb.jenis_pemakaian = 'hc' THEN k.kode_homecare 
            ELSE k.kode_klinik 
        END as USER_CODE,
        pb.jenis_pemakaian as JENIS,
        pb.tanggal as TANGGAL_PEMAKAIAN,
        pb.created_at as TANGGAL_INPUT_FULL,
        osd.reason as REASON
    FROM inventory_pemakaian_bhp pb
    JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
    JOIN inventory_barang b ON pbd.barang_id = b.id
    LEFT JOIN inventory_odoo_format_config ofc ON b.kode_barang = ofc.internal_reference
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    JOIN inventory_klinik k ON pb.klinik_id = k.id
    LEFT JOIN inventory_odoo_support_data osd ON osd.key_name = TRIM(SUBSTRING_INDEX(REPLACE(ofc.product_category, '/', ' '), ' ', 1))
    WHERE $where_clause AND pb.status = 'active'
    ORDER BY TANGGAL_INPUT_FULL DESC, ID ASC
");

if (!empty($params)) $stmt_exp->bind_param($types, ...$params);
$stmt_exp->execute();
$res = $stmt_exp->get_result();

// Helper to format date range for KET PENYESUAIAN
$format_date_range = function($s, $e) {
    $start = strtotime($s);
    $end = strtotime($e);
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    if ($s === $e) {
        return date('d', $start) . ' ' . $months[(int)date('m', $start)] . ' ' . date('Y', $start);
    }
    if (date('m-Y', $start) === date('m-Y', $end)) {
        return date('d', $start) . '-' . date('d', $end) . ' ' . $months[(int)date('m', $start)] . ' ' . date('Y', $start);
    }
    return date('d', $start) . ' ' . $months[(int)date('m', $start)] . ' ' . date('Y', $start) . ' - ' . date('d', $end) . ' ' . $months[(int)date('m', $end)] . ' ' . date('Y', $end);
};
$ket_periode = $format_date_range($start_date, $end_date);

// Header exactly as requested (without Adiional)
$header = ['ID', 'NAMA BARANG', 'NAMA BAGIAN', 'PENYESUAIAN', 'KET PENYESUAIAN', 'SATUAN', 'NAMA AKUN', 'HRG BELI', 'TOTAL', 'USER', 'TANGGAL PENYESUAIAN', 'reason'];

// Grouping to prevent duplicates for unique ID & TANGGAL PENYESUAIAN (FULL DATETIME) & USER
$grouped_data = [];
$used_timestamps = []; // Track [user][timestamp] to ensure uniqueness

while ($row = $res->fetch_assoc()) {
    // Grouping to handle the case where multiple entries exist for the same item/category/user/date
    // But we must NOT group across different dates if they are meant to be separate adjustments
    $user = trim(explode('/', $row['USER_CODE'] ?? '')[0]);
    $tgl_input = date('d/m/Y H:i:s', strtotime($row['TANGGAL_INPUT_FULL']));
    
    // Task: Sederhanakan Jenis (Klinik/HC)
    $jenis_raw = trim((string)($row['JENIS'] ?? ''));
    $jenis_display = (stripos($jenis_raw, 'hc') !== false) ? 'HC' : 'Klinik';

    // Unique key includes timestamp to keep separate adjustments separate
    $key = $row['NAMA_BARANG'] . '|' . ($row['NAMA_BAGIAN'] ?? '') . '|' . $jenis_display . '|' . $user . '|' . $tgl_input;
    
    if (!isset($grouped_data[$key])) {
        $grouped_data[$key] = [
            'ID' => $row['ID'],
            'NAMA_BARANG' => $row['NAMA_BARANG'],
            'NAMA_BAGIAN' => $row['NAMA_BAGIAN'] ?? '',
            'PENYESUAIAN' => 0,
            'KET_PENYESUAIAN' => "Pemakaian BHP " . date('d/m/Y', strtotime($row['TANGGAL_PEMAKAIAN'])),
            'SATUAN' => $row['SATUAN_ODOO'],
            'NAMA_AKUN' => '',
            'HRG_BELI' => '',
            'TOTAL' => '',
            'USER' => $user,
            'TANGGAL_PENYESUAIAN' => $tgl_input,
            'reason' => $row['REASON'] ?? ''
        ];
    }
    
    // Convert to Odoo Quantity
    $qty_odoo = (float)$row['PENYESUAIAN_OPER'] * (float)$row['UOM_RATIO'];
    $grouped_data[$key]['PENYESUAIAN'] += $qty_odoo;
}

// Final data processing: absolute values for Odoo adjustments? 
// Actually, Odoo adjustments usually take negative values for reductions.
// The user said "yang minus ke tariknya - juga", meaning they WANT the negative values to be preserved.
// The current code already does += $qty_odoo, which preserves the sign.
// BUT, the grouping key was too broad ($row['NAMA_BARANG'] . '|' . ($row['NAMA_BAGIAN'] ?? '') . '|' . $jenis_display . '|' . $user),
// which might have merged a positive 2 and a negative 2 into 0 if they were in the same filter range.

$excel_data = [$header];
foreach ($grouped_data as $data) {
    // If the net adjustment is 0, we can optionally skip it, but let's keep it if the user wants to see the record.
    // However, usually Odoo doesn't need 0 adjustments.
    if (abs($data['PENYESUAIAN']) < 0.000001) continue; 

    $excel_data[] = [
        $data['ID'],
        $data['NAMA_BARANG'],
        $data['NAMA_BAGIAN'],
        $data['PENYESUAIAN'],
        $data['KET_PENYESUAIAN'],
        $data['SATUAN'],
        $data['NAMA_AKUN'],
        $data['HRG_BELI'],
        $data['TOTAL'],
        $data['USER'],
        $data['TANGGAL_PENYESUAIAN'],
        $data['reason']
    ];
}

$fn = 'pemakaian_bhp_odoo_export_' . date('Ymd_His') . '.xlsx';
SimpleXLSXGen::fromArray($excel_data)->downloadAs($fn);
exit;
