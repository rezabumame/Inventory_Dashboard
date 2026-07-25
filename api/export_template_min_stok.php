<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
    die('Unauthorized');
}

$bis_check = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bis   = $bis_check && $bis_check->num_rows > 0;

// ── Sheet 1: Konfigurasi Barang ────────────────────────────────────────────────
if ($has_bis) {
    $sql = "SELECT id, kode_barang, nama_barang, stok_minimum, tipe,
                barcode_internal, track_ed, label_print, label_placement
            FROM inventory_barang ORDER BY nama_barang ASC";
} else {
    $sql = "SELECT id, kode_barang, nama_barang, stok_minimum, tipe
            FROM inventory_barang ORDER BY nama_barang ASC";
}
$res = $conn->query($sql);

$sheet1 = [[
    'ID (DO NOT EDIT)',
    'Kode Barang',
    'Nama Barang',
    'Stok Minimum',
    'Tipe (Core/Support)',
    'BIS Barcode (auto/kosongkan)',
    'Track ED (0/1)',
    'Label Print (none/physical, kosongkan=tetap, isi - untuk reset)',
    'Label Placement (item/box/outer/catalogue, kosongkan=tetap, isi - untuk reset)',
]];

while ($row = $res->fetch_assoc()) {
    $sheet1[] = [
        (int)$row['id'],
        (string)$row['kode_barang'],
        (string)$row['nama_barang'],
        (int)$row['stok_minimum'],
        (string)($row['tipe'] ?? ''),
        $has_bis ? (string)($row['barcode_internal'] ?? '') : '',
        $has_bis ? (int)($row['track_ed'] ?? 0)             : '',
        $has_bis ? (string)($row['label_print'] ?? '')       : '',
        $has_bis ? (string)($row['label_placement'] ?? '')   : '',
    ];
}

// ── Sheet 2: Barcode Vendor ────────────────────────────────────────────────────
$sheet2 = [[
    'ID Barang (DO NOT EDIT)',
    'Kode Barang',
    'Nama Barang',
    'Barcode Vendor',
    'Keterangan (opsional)',
    'Hapus (isi X untuk hapus baris ini)',
]];

// Pre-populate dengan barcode vendor yang sudah ada
$qv = $conn->query("
    SELECT bv.barang_id, b.kode_barang, b.nama_barang, bv.barcode, bv.keterangan
    FROM inventory_barcode_vendor bv
    JOIN inventory_barang b ON b.id = bv.barang_id
    ORDER BY b.nama_barang ASC, bv.barcode ASC
");
while ($rv = $qv->fetch_assoc()) {
    $sheet2[] = [
        (int)$rv['barang_id'],
        (string)$rv['kode_barang'],
        (string)$rv['nama_barang'],
        (string)$rv['barcode'],
        (string)($rv['keterangan'] ?? ''),
        '',
    ];
}

// Tambah baris kosong contoh jika belum ada data
if (count($sheet2) === 1) {
    $sheet2[] = ['', '', '', '', '', ''];
}

// ── Sheet 3: Batch ED ────────────────────────────────────────────────────────────
$sheet3 = [[
    'ID ED (DO NOT EDIT, kosongkan jika baris baru)',
    'ID Barang (DO NOT EDIT)',
    'Kode Barang',
    'Nama Barang',
    'Tanggal ED (YYYY-MM-DD)',
    'No. Batch/Lot (opsional)',
    'Hapus (isi X untuk hapus baris ini)',
]];

// Pre-populate dengan batch ED yang masih aktif (belum lewat tanggal)
$qe = $conn->query("
    SELECT e.id, e.barang_id, b.kode_barang, b.nama_barang,
           DATE_FORMAT(e.ed_month, '%Y-%m-%d') AS ed_date, e.keterangan
    FROM inventory_barang_ed e
    JOIN inventory_barang b ON b.id = e.barang_id
    WHERE e.ed_month >= CURDATE()
    ORDER BY b.nama_barang ASC, e.ed_month ASC
");
while ($re = $qe->fetch_assoc()) {
    $sheet3[] = [
        (int)$re['id'],
        (int)$re['barang_id'],
        (string)$re['kode_barang'],
        (string)$re['nama_barang'],
        (string)$re['ed_date'],
        (string)($re['keterangan'] ?? ''),
        '',
    ];
}

// Tambah baris kosong contoh jika belum ada data
if (count($sheet3) === 1) {
    $sheet3[] = ['', '', '', '', '', '', ''];
}

$filename = 'template_barang_' . date('Ymd_His') . '.xlsx';
SimpleXLSXGen::fromArray($sheet1, 'Konfigurasi Barang')
    ->addSheet($sheet2, 'Barcode Vendor')
    ->addSheet($sheet3, 'Batch ED')
    ->downloadAs($filename);
exit;
