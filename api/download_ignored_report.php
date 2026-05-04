<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    die('Unauthorized');
}

$data = $_SESSION['last_import_ignored'] ?? [];

if (empty($data)) {
    die('Tidak ada data baris yang dilewati.');
}

$rows = [];
// Header
$rows[] = ['ID Paket', 'Nama Paket', 'ID Biosys', 'Layanan', 'ID Barang (Excel)', 'Consumables', 'Qty', 'UoM', 'Alasan Dilewati'];

// Data
foreach ($data as $row) {
    $rows[] = [
        (string)$row['id_paket'],
        (string)$row['nama_paket'],
        (string)$row['id_biosys'],
        (string)$row['layanan'],
        (string)$row['barang_id'],
        (string)$row['consumables'],
        (float)$row['qty'],
        (string)$row['uom'],
        (string)$row['reason']
    ];
}

$filename = 'Laporan_Baris_Dilewati_' . date('Ymd_His') . '.xlsx';

SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
exit;
