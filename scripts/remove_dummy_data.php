<?php
require_once __DIR__ . '/../config/database.php';

$confirm = '';
if (PHP_SAPI === 'cli') {
    $argv = $argv ?? [];
    foreach ($argv as $a) {
        if ($a === '--confirm=YES' || $a === 'confirm=YES') {
            $confirm = 'YES';
            break;
        }
    }
} else {
    $confirm = isset($_GET['confirm']) ? (string)$_GET['confirm'] : '';
}
if ($confirm !== 'YES') {
    header('Content-Type: text/plain');
    echo "Dry-run. To remove dummy data, open:\n";
    echo "http://localhost/bumame_iventory2/scripts/remove_dummy_data.php?confirm=YES\n";
    echo "\nOr via CLI:\n";
    echo "php scripts/remove_dummy_data.php --confirm=YES\n";
    exit;
}

$conn->begin_transaction();
try {
    $r = $conn->query("SELECT id, kode_klinik, kode_homecare FROM klinik WHERE nama_klinik = 'Dummy Klinik (Odoo)' OR kode_klinik LIKE 'DMY%' OR kode_homecare LIKE 'DMY%'");
    $dummy_klinik_ids = [];
    $dummy_locs = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $dummy_klinik_ids[] = (int)$row['id'];
        if (!empty($row['kode_klinik'])) $dummy_locs[] = (string)$row['kode_klinik'];
        if (!empty($row['kode_homecare'])) $dummy_locs[] = (string)$row['kode_homecare'];
    }
    $dummy_locs = array_values(array_unique(array_filter($dummy_locs)));

    $conn->query("DELETE FROM stock_mirror WHERE odoo_product_id LIKE 'DUMMY-%' OR kode_barang IN ('BHP001','BHP002','BHP003','BHP999')");

    if (!empty($dummy_locs)) {
        $parts = [];
        foreach ($dummy_locs as $loc) $parts[] = "'" . $conn->real_escape_string($loc) . "'";
        $in = implode(',', $parts);
        $conn->query("DELETE FROM stock_mirror WHERE location_code IN ($in)");
    }

    $conn->query("DELETE FROM barang WHERE odoo_product_id LIKE 'DUMMY-%' OR kode_barang IN ('BHP001','BHP002','BHP003','BHP999')");

    if (!empty($dummy_klinik_ids)) {
        $ids = implode(',', array_map('intval', $dummy_klinik_ids));
        $conn->query("DELETE FROM klinik WHERE id IN ($ids)");
    }

    $conn->commit();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'deleted_klinik_ids' => $dummy_klinik_ids, 'deleted_locations' => $dummy_locs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
