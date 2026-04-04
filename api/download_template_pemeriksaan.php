<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    die('Unauthorized');
}

$header = [
    'Nama Pemeriksaan',
    'Kode Barang (Local)',
    'Nama Barang (Local)',
    'Qty Consumable'
];

$data = [
    $header,
    ['Paket Hemat A', '101', 'Spuit 3cc', '1'],
    ['Paket Hemat A', '102', 'Alcohol Swab', '1'],
    ['Paket Hemat B', '101', 'Spuit 3cc', '1'],
    ['Paket Hemat B', '105', 'Nacl 100ml', '0.5']
];

SimpleXLSXGen::fromArray($data)->downloadAs('Template_Pemeriksaan.xlsx');
exit;
