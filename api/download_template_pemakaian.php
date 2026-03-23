<?php
require_once 'vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

// Headers
$headers = [
    'Tanggal Appointment',
    'Order ID',
    'Parent ID',
    'Appointment Patient ID',
    'Nama Pasien',
    'Layanan',
    'Nama Item BHP',
    'Jumlah',
    'Satuan (UoM)',
    'Nama Nakes',
    'Nakes Branch'
];

// Sample data
$data = [
    $headers,
    ['04/03/2026', '21307', '', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Alcohol Swab 70% (new)', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng'],
    ['04/03/2026', '21307', '', '21307', 'Ariani', 'V-Drip Ultimate Shield', 'Vaksin Influvac Tetra NH (Abbott)', '1', 'Vial', 'Dieriska Janurefa', 'Cideng'],
    ['04/03/2026', '21287', '', '21287', 'Agnes Theresia Djunaed', 'Vaksin Influenza Influvac Tetra', 'Alcohol Swab 70% (new)', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng'],
    ['04/03/2026', '21287', '', '21287', 'Agnes Theresia Djunaed', 'Vaksin Influenza Influvac Tetra', 'Vaksin Influvac Tetra NH (Abbott)', '1', 'Vial', 'Dieriska Janurefa', 'Cideng'],
    ['04/03/2026', '21287', '', '21287', 'Agnes Theresia Djunaed', 'Vaksin Influenza Influvac Tetra', 'Kartu Vaksin', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng'],
    ['04/03/2026', '21287', '', '21287', 'Agnes Theresia Djunaed', 'Vaksin Influenza Influvac Tetra', 'Plester Medis', '1', 'Pcs', 'Dieriska Janurefa', 'Cideng']
];

// Create XLSX with styling
$xlsx = SimpleXLSXGen::fromArray($data);

// Set column widths (in characters)
$xlsx->setColWidth(1, 18);  // Tanggal Appointment
$xlsx->setColWidth(2, 12);  // Order ID
$xlsx->setColWidth(3, 12);  // Parent ID
$xlsx->setColWidth(4, 20);  // Appointment Patient ID
$xlsx->setColWidth(5, 25);  // Nama Pasien
$xlsx->setColWidth(6, 35);  // Layanan
$xlsx->setColWidth(7, 35);  // Nama Item BHP
$xlsx->setColWidth(8, 10);  // Jumlah
$xlsx->setColWidth(9, 15);  // Satuan (UoM)
$xlsx->setColWidth(10, 25); // Nama Nakes
$xlsx->setColWidth(11, 20); // Nakes Branch

// Generate filename
$filename = 'Template_Pemakaian_BHP_' . date('Ymd') . '.xlsx';

// Download
$xlsx->downloadAs($filename);
exit;
