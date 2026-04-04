<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

// Headers (10 columns as requested)
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
    'Kode Barang'
];

// Sample data
$data = [
    $headers,
    ['04 March 2026, 16:00', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Alcohol Swab 70% (new)', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng', 'BHP001'],
    ['04 March 2026, 16:00', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Vaksin Influvac Tetra NH (Abbott)', '1', 'Vial', 'Dieriska Janurefa', 'Cideng', 'BHP002'],
    ['04 March 2026, 17:00', '21287', 'Agnes Theresia Djunaed', 'Vaksin Influenza Influvac Tetra', 'Alcohol Swab 70% (new)', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng', 'BHP001']
];

// Create XLSX
$xlsx = SimpleXLSXGen::fromArray($data);

// Set column widths
$xlsx->setColWidth(1, 25);  // Tanggal Appointment
$xlsx->setColWidth(2, 20);  // Appointment Patient ID
$xlsx->setColWidth(3, 25);  // Nama Pasien
$xlsx->setColWidth(4, 35);  // Layanan
$xlsx->setColWidth(5, 35);  // Nama Item BHP
$xlsx->setColWidth(6, 10);  // Jumlah
$xlsx->setColWidth(7, 15);  // Satuan (UoM)
$xlsx->setColWidth(8, 25);  // Nama Nakes
$xlsx->setColWidth(9, 20);  // Nakes Branch
$xlsx->setColWidth(10, 15); // Kode Barang

// Generate filename
$filename = 'Template_BHP_Baru_' . date('Ymd') . '.xlsx';

// Download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$xlsx->downloadAs($filename);
exit;
