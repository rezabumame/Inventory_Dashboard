<?php
require_once '../config/config.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$u_role = $_SESSION['role'];
$u_klinik_id = $_SESSION['klinik_id'] ?? 0;

$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!in_array($u_role, $allowed_roles)) {
    exit('Access Denied');
}

$f_klinik = (int)($_GET['klinik_id'] ?? 0);
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$tipe = $_GET['tipe'] ?? '';

$where = "1=1";
if (!in_array($u_role, ['super_admin', 'admin_gudang'])) {
    $where .= " AND hl.klinik_id = $u_klinik_id";
} elseif ($f_klinik > 0) {
    $where .= " AND hl.klinik_id = $f_klinik";
}

if ($start_date) {
    $where .= " AND DATE(hl.created_at) >= '$start_date'";
}
if ($end_date) {
    $where .= " AND DATE(hl.created_at) <= '$end_date'";
}
if ($tipe) {
    $t = strtoupper($tipe);
    if ($t === 'KURANG') {
        $where .= " AND (hl.tipe = 'KURANG' OR (hl.tipe = 'adjust' AND hl.qty_perubahan < 0))";
    } elseif ($t === 'TAMBAH') {
        $where .= " AND (hl.tipe = 'TAMBAH' OR hl.tipe = 'tambah' OR (hl.tipe = 'adjust' AND hl.qty_perubahan >= 0))";
    } elseif ($t === 'PAKAI') {
        $where .= " AND (hl.tipe = 'PAKAI' OR hl.tipe = 'pakai')";
    } else {
        $where .= " AND hl.tipe = '$t'";
    }
}

$query = "
    SELECT hl.*, bl.nama_item, k.nama_klinik 
    FROM inventory_history_lokal hl
    JOIN inventory_barang_lokal bl ON hl.barang_lokal_id = bl.id
    JOIN inventory_klinik k ON hl.klinik_id = k.id
    WHERE $where
    ORDER BY hl.created_at DESC
";

require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSXGen;

$header = ['Tanggal', 'Item', 'Klinik', 'Tipe', 'Stok Sebelum', 'Perubahan', 'Stok Sesudah', 'Status', 'Keterangan'];
$data = [$header];

$res = $conn->query($query);
if (!$res) {
    die("Query Error: " . $conn->error);
}

while ($row = $res->fetch_assoc()) {
    $label = strtoupper($row['tipe']);
    if ($label === 'ADJUST') {
        $label = ($row['qty_perubahan'] >= 0 ? 'IN' : 'OUT');
    } elseif ($label === 'TAMBAH' || $label === 'TAMBAH') {
        $label = 'IN';
    } elseif ($label === 'KURANG') {
        $label = 'OUT';
    } elseif ($label === 'PAKAI') {
        $label = 'USAGE';
    }
    
    $val = (float)$row['qty_perubahan'];
    $sign = $val > 0 ? '+' : ''; // Negative already has '-'
    
    $data[] = [
        $row['created_at'],
        $row['nama_item'],
        $row['nama_klinik'],
        $label,
        (float)$row['qty_sebelum'],
        $sign . $val,
        (float)$row['qty_sesudah'],
        strtoupper($row['status']),
        $row['keterangan']
    ];
}

$filename = "Riwayat_Stok_Lokal_" . date('Ymd_His') . ".xlsx";
SimpleXLSXGen::fromArray($data)->downloadAs($filename);
exit;
