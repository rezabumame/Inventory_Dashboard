<?php
require_once __DIR__ . '/../config/database.php';

$conn->query("
    CREATE TABLE IF NOT EXISTS stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code),
        KEY idx_loc_code (location_code, kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

function ensure_column_exists($table, $column, $definition) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
    }
}

ensure_column_exists('barang', 'odoo_product_id', 'VARCHAR(64) NULL');
ensure_column_exists('barang', 'barcode', 'VARCHAR(64) NULL');
ensure_column_exists('barang', 'uom', 'VARCHAR(64) NULL');

function find_or_create_dummy_klinik() {
    global $conn;
    $baseKode = 'DMY01';
    $baseHC = 'DMY01-HC';
    $kode = $baseKode;
    $hc = $baseHC;
    $i = 1;
    while (true) {
        $kodeEsc = $conn->real_escape_string($kode);
        $hcEsc = $conn->real_escape_string($hc);
        $check = $conn->query("SELECT id FROM klinik WHERE kode_klinik = '$kodeEsc' OR kode_homecare = '$hcEsc' LIMIT 1");
        if ($check && $check->num_rows === 0) break;
        $i++;
        $kode = $baseKode . '-' . $i;
        $hc = $baseHC . '-' . $i;
    }

    $stmt = $conn->prepare("INSERT INTO klinik (kode_klinik, kode_homecare, nama_klinik, alamat, status) VALUES (?, ?, ?, ?, 'active')");
    $nama = 'Dummy Klinik (Odoo)';
    $alamat = 'Dummy';
    $stmt->bind_param("ssss", $kode, $hc, $nama, $alamat);
    $stmt->execute();
    $id = $conn->insert_id;
    return ['id' => $id, 'kode_klinik' => $kode, 'kode_homecare' => $hc];
}

function upsert_barang($odooId, $kode, $nama, $satuan) {
    global $conn;
    $kodeEsc = $conn->real_escape_string($kode);
    $r = $conn->query("SELECT id FROM barang WHERE kode_barang = '$kodeEsc' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $id = (int) $r->fetch_assoc()['id'];
        $stmt = $conn->prepare("UPDATE barang SET odoo_product_id = ?, nama_barang = ?, satuan = ?, uom = ?, kategori = 'Odoo' WHERE id = ?");
        $uom = $satuan;
        $stmt->bind_param("ssssi", $odooId, $nama, $satuan, $uom, $id);
        $stmt->execute();
        return $id;
    }

    $stmt = $conn->prepare("INSERT INTO barang (odoo_product_id, kode_barang, nama_barang, satuan, uom, stok_minimum, kategori) VALUES (?, ?, ?, ?, ?, 0, 'Odoo')");
    $uom = $satuan;
    $stmt->bind_param("sssss", $odooId, $kode, $nama, $satuan, $uom);
    $stmt->execute();
    return (int) $conn->insert_id;
}

function upsert_stock($odooId, $kode, $loc, $qty) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO stock_mirror (odoo_product_id, kode_barang, location_code, qty)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE kode_barang = VALUES(kode_barang), qty = VALUES(qty)
    ");
    $stmt->bind_param("sssd", $odooId, $kode, $loc, $qty);
    $stmt->execute();
}

$klinik = find_or_create_dummy_klinik();

upsert_barang('DUMMY-101', 'BHP001', 'Swab Kit', 'pcs');
upsert_barang('DUMMY-102', 'BHP002', 'Tube Sample', 'pcs');
upsert_barang('DUMMY-103', 'BHP003', 'Sarung Tangan', 'box');

$conn->begin_transaction();
try {
    $locK = $conn->real_escape_string($klinik['kode_klinik']);
    $locH = $conn->real_escape_string($klinik['kode_homecare']);
    $conn->query("DELETE FROM stock_mirror WHERE location_code IN ('$locK', '$locH')");

    upsert_stock('DUMMY-101', 'BHP001', $klinik['kode_klinik'], 120);
    upsert_stock('DUMMY-102', 'BHP002', $klinik['kode_klinik'], 55);

    upsert_stock('DUMMY-102', 'BHP002', $klinik['kode_homecare'], 9);
    upsert_stock('DUMMY-103', 'BHP003', $klinik['kode_homecare'], 25);
    upsert_stock('DUMMY-999', 'BHP999', $klinik['kode_homecare'], 5);

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    throw $e;
}

echo json_encode([
    'ok' => true,
    'dummy_klinik_id' => $klinik['id'],
    'kode_klinik' => $klinik['kode_klinik'],
    'kode_homecare' => $klinik['kode_homecare']
], JSON_PRETTY_PRINT);
?>
