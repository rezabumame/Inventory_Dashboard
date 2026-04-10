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
    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_app_settings (k VARCHAR(100) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_app_counters (k VARCHAR(50) NOT NULL, d CHAR(8) NOT NULL, seq INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (k, d)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(100) NOT NULL,
        from_uom VARCHAR(50) NULL,
        to_uom VARCHAR(50) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kode_barang (kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_hc_petugas_transfer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        klinik_id INT NOT NULL,
        user_hc_id INT NOT NULL,
        barang_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        catatan VARCHAR(255) NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_klinik (klinik_id),
        KEY idx_user (user_hc_id),
        KEY idx_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_hc_tas_allocation (
        id INT AUTO_INCREMENT PRIMARY KEY,
        klinik_id INT NOT NULL,
        user_hc_id INT NOT NULL,
        barang_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        catatan VARCHAR(255) NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_klinik (klinik_id),
        KEY idx_user (user_hc_id),
        KEY idx_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("ALTER TABLE inventory_hc_petugas_transfer MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE inventory_hc_tas_allocation MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE inventory_stok_tas_hc MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sebelum DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sesudah DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_onsite DECIMAL(18,4) DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_hc DECIMAL(18,4) DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_done_onsite DECIMAL(18,4) DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_adjust DECIMAL(18,4) DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_pemakaian_bhp_detail MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_stok_gudang_klinik MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_stok_gudang_klinik MODIFY COLUMN qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_stok_gudang_utama MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_stok_gudang_utama MODIFY COLUMN reserved_qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
    $conn->query("ALTER TABLE inventory_transfer_barang_detail MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");

    ensure_table($conn, "CREATE TABLE IF NOT EXISTS inventory_booking_request_dedup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_request_id VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        booking_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client (client_request_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ensure_column($conn, 'inventory_barang', 'kode_barang', "VARCHAR(64) NULL");
    ensure_column($conn, 'inventory_barang', 'nama_barang', "VARCHAR(255) NULL");
    ensure_column($conn, 'inventory_barang', 'satuan', "VARCHAR(64) NULL");
    ensure_column($conn, 'inventory_barang', 'kategori', "VARCHAR(64) NULL");
    ensure_column($conn, 'inventory_barang', 'stok_minimum', "INT NOT NULL DEFAULT 0");
    ensure_column($conn, 'inventory_barang', 'odoo_product_id', "VARCHAR(64) NULL");
    ensure_column($conn, 'inventory_barang', 'uom', "VARCHAR(64) NULL");
    ensure_column($conn, 'inventory_barang', 'barcode', "VARCHAR(64) NULL");

    ensure_column($conn, 'inventory_booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'tanggal_lahir', "DATE NULL");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'is_out_of_stock', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($conn, 'inventory_booking_pemeriksaan', 'out_of_stock_items', "TEXT NULL");

    ensure_column($conn, 'inventory_booking_pasien', 'nomor_tlp', "VARCHAR(30) NULL");
    ensure_column($conn, 'inventory_booking_pasien', 'tanggal_lahir', "DATE NULL");

    ensure_column($conn, 'inventory_request_barang', 'dokumen_path', "VARCHAR(255) NULL");
    ensure_column($conn, 'inventory_request_barang', 'dokumen_name', "VARCHAR(255) NULL");
    ensure_column($conn, 'inventory_request_barang', 'processed_by', "INT NULL");
    ensure_column($conn, 'inventory_request_barang', 'processed_at', "TIMESTAMP NULL");
    ensure_column($conn, 'inventory_request_barang_detail', 'qty_received', "INT NOT NULL DEFAULT 0");
    ensure_column($conn, 'inventory_pemeriksaan_grup_detail', 'is_mandatory', "TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN tanggal DATETIME NOT NULL");

    ensure_unique_if_clean($conn, 'inventory_barang', 'odoo_product_id', 'uniq_odoo_product_id');
    ensure_unique_if_clean($conn, 'inventory_barang', 'kode_barang', 'uniq_kode_barang');

    ensure_index($conn, 'inventory_booking_pemeriksaan', 'idx_bp_klinik_status_tgl', "CREATE INDEX idx_bp_klinik_status_tgl ON inventory_booking_pemeriksaan (klinik_id, status, tanggal_pemeriksaan)");
    ensure_index($conn, 'inventory_pemakaian_bhp', 'idx_pbh_klinik_jenis_created', "CREATE INDEX idx_pbh_klinik_jenis_created ON inventory_pemakaian_bhp (klinik_id, jenis_pemakaian, created_at)");
    ensure_index($conn, 'inventory_transaksi_stok', 'idx_ts_level_created_barang', "CREATE INDEX idx_ts_level_created_barang ON inventory_transaksi_stok (level, level_id, created_at, barang_id)");
    ensure_index($conn, 'inventory_stock_mirror', 'idx_loc_code', "CREATE INDEX idx_loc_code ON inventory_stock_mirror (location_code, kode_barang)");
    ensure_index($conn, 'inventory_booking_detail', 'idx_booking_id', "CREATE INDEX idx_booking_id ON inventory_booking_detail (booking_id)");
    ensure_index($conn, 'inventory_booking_detail', 'idx_booking_barang', "CREATE INDEX idx_booking_barang ON inventory_booking_detail (barang_id)");
    ensure_index($conn, 'inventory_request_barang_detail', 'idx_req_detail_req', "CREATE INDEX idx_req_detail_req ON inventory_request_barang_detail (request_barang_id)");
    ensure_index($conn, 'inventory_request_barang_detail', 'idx_req_detail_barang', "CREATE INDEX idx_req_detail_barang ON inventory_request_barang_detail (barang_id)");
    ensure_index($conn, 'inventory_transfer_barang_detail', 'idx_trf_detail_trf', "CREATE INDEX idx_trf_detail_trf ON inventory_transfer_barang_detail (transfer_barang_id)");
    ensure_index($conn, 'inventory_transfer_barang_detail', 'idx_trf_detail_barang', "CREATE INDEX idx_trf_detail_barang ON inventory_transfer_barang_detail (barang_id)");
    ensure_index($conn, 'inventory_stok_gudang_klinik', 'idx_sgk_klinik_barang', "CREATE INDEX idx_sgk_klinik_barang ON inventory_stok_gudang_klinik (klinik_id, barang_id)");
    ensure_index($conn, 'inventory_stok_tas_hc', 'idx_sth_user_barang', "CREATE INDEX idx_sth_user_barang ON inventory_stok_tas_hc (user_id, barang_id)");

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
