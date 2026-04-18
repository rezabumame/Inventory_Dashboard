<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    die('Unauthorized');
}

$header = [
    'ID Paket (Wajib)',
    'Paket (Nama Paket)',
    'ID Biosys (Opsional)',
    'Layanan (Nama Layanan)',
    'ID Barang (Wajib)',
    'Consumables (Nama Barang)',
    'Qty',
    'UoM'
];

$data = [$header];

// Fetch all existing exam groups and their details
$sql = "SELECT 
            g.id AS id_paket,
            g.nama_pemeriksaan AS paket,
            d.id_biosys,
            d.nama_layanan,
            COALESCE(NULLIF(b.kode_barang, ''), b.odoo_product_id) AS code_barang,
            b.nama_barang AS consumables,
            d.qty_per_pemeriksaan AS qty,
            COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS uom
        FROM inventory_pemeriksaan_grup g
        LEFT JOIN inventory_pemeriksaan_grup_detail d ON g.id = d.pemeriksaan_grup_id
        LEFT JOIN inventory_barang b ON d.barang_id = b.id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        ORDER BY g.id ASC, d.id_biosys ASC, b.nama_barang ASC";

$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            (string)$row['id_paket'],
            (string)$row['paket'],
            (string)($row['id_biosys'] ?? ''),
            (string)($row['nama_layanan'] ?? ''),
            (string)($row['code_barang'] ?? ''),
            (string)($row['consumables'] ?? ''),
            (string)($row['qty'] ?? ''),
            (string)($row['uom'] ?? '')
        ];
    }
} else {
    // If no data, add examples using Codes
    $data[] = ['PKT450', 'Paket Anemia', '191001', 'Hematologi Lengkap', '506', 'Plester Medis', '1', 'Pcs'];
    $data[] = ['PKT450', 'Paket Anemia', '191001', 'Hematologi Lengkap', '491', 'Tabung Vaccutainer Ungu', '1', 'Tube'];
}

SimpleXLSXGen::fromArray($data)->downloadAs('Template_Pemeriksaan.xlsx');
exit;


