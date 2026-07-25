<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$r_col = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bi = ($r_col && $r_col->num_rows > 0);
$bi_sel = $has_bi
    ? ", COALESCE(b.barcode_internal,'') AS barcode_internal,
        COALESCE(b.label_config_set,0) AS label_config_set, COALESCE(b.final_category,'') AS final_category,
        COALESCE(b.label_print,'none') AS label_print, b.label_placement"
    : ", '' AS barcode_internal, 0 AS label_config_set, '' AS final_category, 'none' AS label_print, NULL AS label_placement";

$res = $conn->query("
    SELECT b.id, b.kode_barang, b.nama_barang, COALESCE(b.satuan,'') AS satuan $bi_sel
    FROM inventory_barang b
    ORDER BY b.nama_barang ASC
");
$items = [];
while ($res && ($row = $res->fetch_assoc())) {
    // Kategori label (A-D): pakai final_category kalau sudah ada (hasil wizard/override),
    // else simpulkan dari label_print/label_placement; kosong jika belum dikonfigurasi sama sekali.
    $kategori = '';
    if (!empty($row['label_config_set'])) {
        $kategori = $row['final_category'] ?: '';
        if ($kategori === '') {
            $lp = $row['label_print'] ?: 'none';
            $pl = $row['label_placement'];
            if ($lp === 'none') $kategori = 'A';
            elseif ($lp === 'physical' && $pl === 'unit')      $kategori = 'B';
            elseif ($lp === 'physical' && $pl === 'box')       $kategori = 'C';
            elseif ($lp === 'physical' && $pl === 'catalogue') $kategori = 'D';
        }
    }
    $row['kategori_label'] = $kategori;
    unset($row['label_config_set'], $row['final_category'], $row['label_print'], $row['label_placement']);
    $row['vendor_barcodes'] = [];
    $items[(int)$row['id']] = $row;
}

if (!empty($items)) {
    $ids = implode(',', array_keys($items));
    $rv = $conn->query("
        SELECT barang_id, barcode, COALESCE(keterangan,'') AS keterangan
        FROM inventory_barcode_vendor
        WHERE barang_id IN ($ids)
        ORDER BY created_at ASC
    ");
    while ($rv && ($vrow = $rv->fetch_assoc())) {
        $bid = (int)$vrow['barang_id'];
        if (isset($items[$bid])) {
            $items[$bid]['vendor_barcodes'][] = [
                'barcode'     => $vrow['barcode'],
                'keterangan'  => $vrow['keterangan'],
            ];
        }
    }
}

echo json_encode(['success' => true, 'items' => array_values($items)]);
