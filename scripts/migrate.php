<?php

require_once __DIR__ . '/../config/database.php';

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return (bool)($r && $r->num_rows > 0);
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return (bool)($r && $r->num_rows > 0);
}

function ensure_table(mysqli $conn, string $sql): void {
    $conn->query($sql);
}

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void {
    if (!column_exists($conn, $table, $column)) {
        $t = $conn->real_escape_string($table);
        $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
    }
}

function ensure_index(mysqli $conn, string $table, string $indexName, string $createSql): void {
    if (!table_exists($conn, $table)) return;
    $t = $conn->real_escape_string($table);
    $idx = $conn->real_escape_string($indexName);
    $r = $conn->query("SHOW INDEX FROM `$t` WHERE Key_name = '$idx'");
    if (!$r || $r->num_rows === 0) {
        $conn->query($createSql);
    }
}

function ensure_unique_if_clean(mysqli $conn, string $table, string $column, string $indexName): void {
    if (!table_exists($conn, $table)) return;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SELECT `$c` AS v FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c` HAVING COUNT(*) > 1 LIMIT 1");
    if ($r && $r->num_rows === 0) {
        ensure_index($conn, $table, $indexName, "ALTER TABLE `$t` ADD UNIQUE KEY `$indexName` (`$c`)");
    }
}

try {
    ensure_table($conn, "CREATE TABLE IF NOT EXISTS app_settings (k VARCHAR(100) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    ensure_table($conn, "CREATE TABLE IF NOT EXISTS app_counters (k VARCHAR(50) NOT NULL, d CHAR(8) NOT NULL, seq INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (k, d)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(100) NOT NULL,
        from_uom VARCHAR(50) NULL,
        to_uom VARCHAR(50) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kode_barang (kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS hc_petugas_transfer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        klinik_id INT NOT NULL,
        user_hc_id INT NOT NULL,
        barang_id INT NOT NULL,
        qty INT NOT NULL,
        catatan VARCHAR(255) NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_klinik (klinik_id),
        KEY idx_user (user_hc_id),
        KEY idx_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS hc_tas_allocation (
        id INT AUTO_INCREMENT PRIMARY KEY,
        klinik_id INT NOT NULL,
        user_hc_id INT NOT NULL,
        barang_id INT NOT NULL,
        qty INT NOT NULL,
        catatan VARCHAR(255) NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_klinik (klinik_id),
        KEY idx_user (user_hc_id),
        KEY idx_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS booking_request_dedup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_request_id VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        booking_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client (client_request_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_column($conn, 'barang', 'kode_barang', "VARCHAR(64) NULL");
    ensure_column($conn, 'barang', 'nama_barang', "VARCHAR(255) NULL");
    ensure_column($conn, 'barang', 'satuan', "VARCHAR(64) NULL");
    ensure_column($conn, 'barang', 'kategori', "VARCHAR(64) NULL");
    ensure_column($conn, 'barang', 'stok_minimum', "INT NOT NULL DEFAULT 0");
    ensure_column($conn, 'barang', 'odoo_product_id', "VARCHAR(64) NULL");
    ensure_column($conn, 'barang', 'uom', "VARCHAR(64) NULL");
    ensure_column($conn, 'barang', 'barcode', "VARCHAR(64) NULL");

    ensure_column($conn, 'booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL");
    ensure_column($conn, 'booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL");
    ensure_column($conn, 'booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($conn, 'booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL");
    ensure_column($conn, 'booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL");
    ensure_column($conn, 'booking_pemeriksaan', 'tanggal_lahir', "DATE NULL");
    ensure_column($conn, 'booking_pemeriksaan', 'butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0");

    ensure_column($conn, 'booking_pasien', 'nomor_tlp', "VARCHAR(30) NULL");
    ensure_column($conn, 'booking_pasien', 'tanggal_lahir', "DATE NULL");

    ensure_column($conn, 'request_barang', 'dokumen_path', "VARCHAR(255) NULL");
    ensure_column($conn, 'request_barang', 'dokumen_name', "VARCHAR(255) NULL");
    ensure_column($conn, 'request_barang', 'processed_by', "INT NULL");
    ensure_column($conn, 'request_barang', 'processed_at', "TIMESTAMP NULL");
    ensure_column($conn, 'request_barang_detail', 'qty_received', "INT NOT NULL DEFAULT 0");

    ensure_unique_if_clean($conn, 'barang', 'odoo_product_id', 'uniq_odoo_product_id');
    ensure_unique_if_clean($conn, 'barang', 'kode_barang', 'uniq_kode_barang');

    ensure_index($conn, 'booking_pemeriksaan', 'idx_bp_klinik_status_tgl', "CREATE INDEX idx_bp_klinik_status_tgl ON booking_pemeriksaan (klinik_id, status, tanggal_pemeriksaan)");
    ensure_index($conn, 'pemakaian_bhp', 'idx_pbh_klinik_jenis_created', "CREATE INDEX idx_pbh_klinik_jenis_created ON pemakaian_bhp (klinik_id, jenis_pemakaian, created_at)");
    ensure_index($conn, 'transaksi_stok', 'idx_ts_level_created_barang', "CREATE INDEX idx_ts_level_created_barang ON transaksi_stok (level, level_id, created_at, barang_id)");
    ensure_index($conn, 'stock_mirror', 'idx_loc_code', "CREATE INDEX idx_loc_code ON stock_mirror (location_code, kode_barang)");
    ensure_index($conn, 'booking_detail', 'idx_booking_id', "CREATE INDEX idx_booking_id ON booking_detail (booking_id)");
    ensure_index($conn, 'booking_detail', 'idx_booking_barang', "CREATE INDEX idx_booking_barang ON booking_detail (barang_id)");
    ensure_index($conn, 'request_barang_detail', 'idx_req_detail_req', "CREATE INDEX idx_req_detail_req ON request_barang_detail (request_barang_id)");
    ensure_index($conn, 'request_barang_detail', 'idx_req_detail_barang', "CREATE INDEX idx_req_detail_barang ON request_barang_detail (barang_id)");
    ensure_index($conn, 'transfer_barang_detail', 'idx_trf_detail_trf', "CREATE INDEX idx_trf_detail_trf ON transfer_barang_detail (transfer_barang_id)");
    ensure_index($conn, 'transfer_barang_detail', 'idx_trf_detail_barang', "CREATE INDEX idx_trf_detail_barang ON transfer_barang_detail (barang_id)");
    ensure_index($conn, 'stok_gudang_klinik', 'idx_sgk_klinik_barang', "CREATE INDEX idx_sgk_klinik_barang ON stok_gudang_klinik (klinik_id, barang_id)");
    ensure_index($conn, 'stok_tas_hc', 'idx_sth_user_barang', "CREATE INDEX idx_sth_user_barang ON stok_tas_hc (user_id, barang_id)");

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
