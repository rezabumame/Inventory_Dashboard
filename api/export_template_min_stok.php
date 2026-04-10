<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
    die('Unauthorized');
}

$sql = "SELECT id, kode_barang, nama_barang, stok_minimum FROM inventory_barang ORDER BY nama_barang ASC";
$res = $conn->query($sql);

$data = [
    ['ID (DO NOT EDIT)', 'Kode Barang', 'Nama Barang', 'Stok Minimum']
];

while ($row = $res->fetch_assoc()) {
    $data[] = [
        (int)$row['id'],
        (string)$row['kode_barang'],
        (string)$row['nama_barang'],
        (int)$row['stok_minimum']
    ];
}

$filename = 'template_min_stok_' . date('Ymd_His') . '.xlsx';
SimpleXLSXGen::fromArray($data)->downloadAs($filename);
exit;
