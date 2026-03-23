<?php
require_once __DIR__ . '/../config/database.php';

$argv = $argv ?? [];

function argv_value(array $argv, string $prefix): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $prefix . '=')) return substr($a, strlen($prefix) + 1);
    }
    return null;
}

$kode = (string)(argv_value($argv, '--kode') ?? '');
$odoo = (string)(argv_value($argv, '--odoo') ?? '');
$id = (int)(argv_value($argv, '--id') ?? 0);

$out = [
    'input' => ['kode' => $kode, 'odoo' => $odoo, 'id' => $id],
    'barang_by_kode_barang' => [],
    'barang_by_odoo_product_id' => [],
    'barang_by_id' => null,
    'dups_kode_barang' => [],
    'dups_odoo_product_id' => [],
    'stock_mirror_rows' => [],
    'barang_by_id_from_stock_mirror_kode' => []
];

if ($kode !== '') {
    $k = $conn->real_escape_string($kode);
    $r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan, kategori, barcode FROM barang WHERE kode_barang = '$k' ORDER BY id ASC");
    while ($r && ($row = $r->fetch_assoc())) $out['barang_by_kode_barang'][] = $row;
}

if ($odoo !== '') {
    $o = $conn->real_escape_string($odoo);
    $r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan, kategori, barcode FROM barang WHERE TRIM(odoo_product_id) = '$o' ORDER BY id ASC");
    while ($r && ($row = $r->fetch_assoc())) $out['barang_by_odoo_product_id'][] = $row;

    $r = $conn->query("SELECT location_code, kode_barang, qty, updated_at FROM stock_mirror WHERE TRIM(odoo_product_id) = '$o' ORDER BY location_code ASC");
    while ($r && ($row = $r->fetch_assoc())) $out['stock_mirror_rows'][] = $row;
}

if ($id > 0) {
    $r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan, kategori, barcode FROM barang WHERE id = $id LIMIT 1");
    if ($r && $r->num_rows > 0) $out['barang_by_id'] = $r->fetch_assoc();
}

if ($odoo !== '') {
    foreach ($out['stock_mirror_rows'] as $sm) {
        $kb = trim((string)($sm['kode_barang'] ?? ''));
        if ($kb === '' || !ctype_digit($kb)) continue;
        $bid = (int)$kb;
        $r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan, kategori FROM barang WHERE id = $bid LIMIT 1");
        if ($r && $r->num_rows > 0) {
            $out['barang_by_id_from_stock_mirror_kode'][$kb] = $r->fetch_assoc();
        }
    }
}

$r = $conn->query("SELECT kode_barang, COUNT(*) c FROM barang WHERE kode_barang IS NOT NULL AND kode_barang <> '' GROUP BY kode_barang HAVING c > 1 ORDER BY c DESC, kode_barang ASC LIMIT 50");
while ($r && ($row = $r->fetch_assoc())) $out['dups_kode_barang'][] = $row;

$r = $conn->query("SELECT odoo_product_id, COUNT(*) c FROM barang WHERE odoo_product_id IS NOT NULL AND TRIM(odoo_product_id) <> '' GROUP BY odoo_product_id HAVING c > 1 ORDER BY c DESC, odoo_product_id ASC LIMIT 50");
while ($r && ($row = $r->fetch_assoc())) $out['dups_odoo_product_id'][] = $row;

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
