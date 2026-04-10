<?php

require_once __DIR__ . '/../config/database.php';

$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));

if (!$is_cli) {
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - Bumame Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --error: #ef4444;
            --bg: #f9fafb;
            --card: #ffffff;
            --text: #111827;
            --text-muted: #6b7280;
        }
        body {
            font-family: "Inter", sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2rem;
            display: flex;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 800px;
        }
        .header {
            margin-bottom: 2rem;
            text-align: center;
        }
        .header h1 {
            font-size: 1.875rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
        }
        .header p {
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        .card {
            background: var(--card);
            border-radius: 0.75rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 1.5rem;
            overflow: hidden;
        }
        .task-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .task-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .task-item:last-child {
            border-bottom: none;
        }
        .task-status {
            margin-right: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
        }
        .task-status.success {
            color: var(--success);
        }
        .task-status.error {
            color: var(--error);
        }
        .task-info {
            flex-grow: 1;
        }
        .task-name {
            font-weight: 500;
            font-size: 0.9375rem;
        }
        .task-desc {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }
        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .badge {
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>System Migration</h1>
            <p>Verifying and updating database schema...</p>
        </div>
        <div class="card">
            <ul class="task-list">';
}

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

function run_migration_task(string $name, callable $callback) {
    global $is_cli;
    $status = 'success';
    $message = '';
    $detail = '';

    try {
        $detail = $callback();
        if ($detail === true) $detail = 'Done';
    } catch (Throwable $e) {
        $status = 'error';
        $message = $e->getMessage();
    }

    if ($is_cli) {
        if ($status === 'success') {
            echo "[OK] $name" . ($detail ? " ($detail)" : "") . "\n";
        } else {
            echo "[ERROR] $name: $message\n";
        }
    } else {
        $icon = ($status === 'success') ? '✅' : '❌';
        $badgeClass = ($status === 'success') ? ($detail === 'Already exists' || $detail === 'Already Up-to-date' ? 'badge-info' : 'badge-success') : 'badge-error';
        $displayText = $message ?: $detail;
        
        echo '<li class="task-item">
            <div class="task-status ' . $status . '">' . $icon . '</div>
            <div class="task-info">
                <div class="task-name">' . htmlspecialchars($name) . ' <span class="badge ' . $badgeClass . '">' . htmlspecialchars($displayText) . '</span></div>
            </div>
        </li>';
        // Flush output buffer to show progress
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
}

// Helper migration functions
function m_ensure_table(mysqli $conn, string $tableName, string $sql): string {
    if (table_exists($conn, $tableName)) return "Already exists";
    $conn->query($sql);
    return "Created";
}

function m_ensure_column(mysqli $conn, string $table, string $column, string $definition): string {
    if (column_exists($conn, $table, $column)) return "Already exists";
    $t = $conn->real_escape_string($table);
    $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
    return "Added";
}

function m_ensure_index(mysqli $conn, string $table, string $indexName, string $createSql): string {
    if (!table_exists($conn, $table)) return "Table not found";
    $t = $conn->real_escape_string($table);
    $idx = $conn->real_escape_string($indexName);
    $r = $conn->query("SHOW INDEX FROM `$t` WHERE Key_name = '$idx'");
    if ($r && $r->num_rows > 0) return "Already exists";
    $conn->query($createSql);
    return "Created";
}

function m_ensure_unique_if_clean(mysqli $conn, string $table, string $column, string $indexName): string {
    if (!table_exists($conn, $table)) return "Table not found";
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    
    // Check if unique index exists
    $idx = $conn->real_escape_string($indexName);
    $r = $conn->query("SHOW INDEX FROM `$t` WHERE Key_name = '$idx'");
    if ($r && $r->num_rows > 0) return "Already exists";

    // Check for duplicates
    $r = $conn->query("SELECT `$c` AS v FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c` HAVING COUNT(*) > 1 LIMIT 1");
    if ($r && $r->num_rows > 0) return "Skipped (duplicates found)";
    
    $conn->query("ALTER TABLE `$t` ADD UNIQUE KEY `$indexName` (`$c`)");
    return "Created";
}

// Start Migrations
try {
    run_migration_task("Table: inventory_app_settings", fn() => m_ensure_table($conn, "inventory_app_settings", "CREATE TABLE IF NOT EXISTS inventory_app_settings (k VARCHAR(100) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));
    run_migration_task("Table: inventory_app_counters", fn() => m_ensure_table($conn, "inventory_app_counters", "CREATE TABLE IF NOT EXISTS inventory_app_counters (k VARCHAR(50) NOT NULL, d CHAR(8) NOT NULL, seq INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (k, d)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));
    run_migration_task("Table: inventory_barang_uom_conversion", fn() => m_ensure_table($conn, "inventory_barang_uom_conversion", "CREATE TABLE IF NOT EXISTS inventory_barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(100) NOT NULL,
        from_uom VARCHAR(50) NULL,
        to_uom VARCHAR(50) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kode_barang (kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    run_migration_task("Table: inventory_stock_mirror", fn() => m_ensure_table($conn, "inventory_stock_mirror", "CREATE TABLE IF NOT EXISTS inventory_stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    run_migration_task("Table: inventory_stok_tas_hc", fn() => m_ensure_table($conn, "inventory_stok_tas_hc", "CREATE TABLE IF NOT EXISTS inventory_stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    run_migration_task("Table: inventory_hc_petugas_transfer", fn() => m_ensure_table($conn, "inventory_hc_petugas_transfer", "CREATE TABLE IF NOT EXISTS inventory_hc_petugas_transfer (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    run_migration_task("Table: inventory_hc_tas_allocation", fn() => m_ensure_table($conn, "inventory_hc_tas_allocation", "CREATE TABLE IF NOT EXISTS inventory_hc_tas_allocation (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    run_migration_task("Update: inventory_hc_petugas_transfer qty", fn() => $conn->query("ALTER TABLE inventory_hc_petugas_transfer MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0") ? "Updated" : "Failed");
    run_migration_task("Update: inventory_hc_tas_allocation qty", fn() => $conn->query("ALTER TABLE inventory_hc_tas_allocation MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0") ? "Updated" : "Failed");
    run_migration_task("Update: inventory_stok_tas_hc qty", fn() => $conn->query("ALTER TABLE inventory_stok_tas_hc MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");
    run_migration_task("Update: inventory_transaksi_stok qty", fn() => $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");
    run_migration_task("Update: inventory_transaksi_stok qty_sebelum", fn() => $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sebelum DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");
    run_migration_task("Update: inventory_transaksi_stok qty_sesudah", fn() => $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sesudah DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");
    
    run_migration_task("Update: inventory_booking_detail qty_fields", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_onsite DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_hc DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_done_onsite DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_adjust DECIMAL(18,4) DEFAULT 0.0000");
        return "Updated 5 columns";
    });
    
    run_migration_task("Update: inventory_pemakaian_bhp_detail qty", fn() => $conn->query("ALTER TABLE inventory_pemakaian_bhp_detail MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");
    
    run_migration_task("Update: inventory_stok_gudang_klinik qty", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_stok_gudang_klinik MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_stok_gudang_klinik MODIFY COLUMN qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        return "Updated 2 columns";
    });
    
    run_migration_task("Update: inventory_stok_gudang_utama qty", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_stok_gudang_utama MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_stok_gudang_utama MODIFY COLUMN reserved_qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        return "Updated 2 columns";
    });
    
    run_migration_task("Update: inventory_transfer_barang_detail qty", fn() => $conn->query("ALTER TABLE inventory_transfer_barang_detail MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed");

    run_migration_task("Table: inventory_booking_request_dedup", fn() => m_ensure_table($conn, "inventory_booking_request_dedup", "CREATE TABLE IF NOT EXISTS inventory_booking_request_dedup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_request_id VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        booking_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client (client_request_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"));

    // Columns
    $cols = [
        ['inventory_barang', 'kode_barang', "VARCHAR(64) NULL"],
        ['inventory_barang', 'nama_barang', "VARCHAR(255) NULL"],
        ['inventory_barang', 'satuan', "VARCHAR(64) NULL"],
        ['inventory_barang', 'kategori', "VARCHAR(64) NULL"],
        ['inventory_barang', 'stok_minimum', "INT NOT NULL DEFAULT 0"],
        ['inventory_barang', 'odoo_product_id', "VARCHAR(64) NULL"],
        ['inventory_barang', 'uom', "VARCHAR(64) NULL"],
        ['inventory_barang', 'barcode', "VARCHAR(64) NULL"],
        ['inventory_booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL"],
        ['inventory_booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL"],
        ['inventory_booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL"],
        ['inventory_booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL"],
        ['inventory_booking_pemeriksaan', 'tanggal_lahir', "DATE NULL"],
        ['inventory_booking_pemeriksaan', 'butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'is_out_of_stock', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'out_of_stock_items', "TEXT NULL"],
        ['inventory_pemakaian_bhp', 'status', "ENUM('active','pending_edit','pending_delete','rejected') DEFAULT 'active'"],
        ['inventory_pemakaian_bhp', 'approval_reason', "TEXT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_by', "INT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_at', "DATETIME NULL"],
        ['inventory_pemakaian_bhp', 'pending_data', "LONGTEXT NULL"],
        ['inventory_booking_pasien', 'nomor_tlp', "VARCHAR(30) NULL"],
        ['inventory_booking_pasien', 'tanggal_lahir', "DATE NULL"],
        ['inventory_request_barang', 'dokumen_path', "VARCHAR(255) NULL"],
        ['inventory_request_barang', 'dokumen_name', "VARCHAR(255) NULL"],
        ['inventory_request_barang', 'processed_by', "INT NULL"],
        ['inventory_request_barang', 'processed_at', "TIMESTAMP NULL"],
        ['inventory_request_barang_detail', 'qty_received', "INT NOT NULL DEFAULT 0"],
        ['inventory_pemeriksaan_grup_detail', 'is_mandatory', "TINYINT(1) NOT NULL DEFAULT 1"],
    ];

    foreach ($cols as $c) {
        run_migration_task("Column: {$c[0]}.{$c[1]}", fn() => m_ensure_column($conn, $c[0], $c[1], $c[2]));
    }

    run_migration_task("Update: inventory_pemakaian_bhp tanggal type", fn() => $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN tanggal DATETIME NOT NULL") ? "Updated" : "Failed");

    // Indexes
    run_migration_task("Index: inventory_barang uniq_odoo", fn() => m_ensure_unique_if_clean($conn, 'inventory_barang', 'odoo_product_id', 'uniq_odoo_product_id'));
    run_migration_task("Index: inventory_barang uniq_kode", fn() => m_ensure_unique_if_clean($conn, 'inventory_barang', 'kode_barang', 'uniq_kode_barang'));
    
    $indices = [
        ['inventory_booking_pemeriksaan', 'idx_bp_klinik_status_tgl', "CREATE INDEX idx_bp_klinik_status_tgl ON inventory_booking_pemeriksaan (klinik_id, status, tanggal_pemeriksaan)"],
        ['inventory_pemakaian_bhp', 'idx_pbh_klinik_jenis_created', "CREATE INDEX idx_pbh_klinik_jenis_created ON inventory_pemakaian_bhp (klinik_id, jenis_pemakaian, created_at)"],
        ['inventory_transaksi_stok', 'idx_ts_level_created_barang', "CREATE INDEX idx_ts_level_created_barang ON inventory_transaksi_stok (level, level_id, created_at, barang_id)"],
        ['inventory_stock_mirror', 'idx_loc_code', "CREATE INDEX idx_loc_code ON inventory_stock_mirror (location_code, kode_barang)"],
        ['inventory_booking_detail', 'idx_booking_id', "CREATE INDEX idx_booking_id ON inventory_booking_detail (booking_id)"],
        ['inventory_booking_detail', 'idx_booking_barang', "CREATE INDEX idx_booking_barang ON inventory_booking_detail (barang_id)"],
        ['inventory_request_barang_detail', 'idx_req_detail_req', "CREATE INDEX idx_req_detail_req ON inventory_request_barang_detail (request_barang_id)"],
        ['inventory_request_barang_detail', 'idx_req_detail_barang', "CREATE INDEX idx_req_detail_barang ON inventory_request_barang_detail (barang_id)"],
        ['inventory_transfer_barang_detail', 'idx_trf_detail_trf', "CREATE INDEX idx_trf_detail_trf ON inventory_transfer_barang_detail (transfer_barang_id)"],
        ['inventory_transfer_barang_detail', 'idx_trf_detail_barang', "CREATE INDEX idx_trf_detail_barang ON inventory_transfer_barang_detail (barang_id)"],
        ['inventory_stok_gudang_klinik', 'idx_sgk_klinik_barang', "CREATE INDEX idx_sgk_klinik_barang ON inventory_stok_gudang_klinik (klinik_id, barang_id)"],
        ['inventory_stok_tas_hc', 'idx_sth_user_barang', "CREATE INDEX idx_sth_user_barang ON inventory_stok_tas_hc (user_id, barang_id)"],
    ];

    foreach ($indices as $idx) {
        run_migration_task("Index: {$idx[0]}.{$idx[1]}", fn() => m_ensure_index($conn, $idx[0], $idx[1], $idx[2]));
    }

} catch (Throwable $e) {
    if ($is_cli) {
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($stderr, "CRITICAL ERROR: " . $e->getMessage() . "\n");
    } else {
        echo '<li class="task-item">
            <div class="task-status error">❌</div>
            <div class="task-info">
                <div class="task-name">CRITICAL ERROR <span class="badge badge-error">' . htmlspecialchars($e->getMessage()) . '</span></div>
            </div>
        </li>';
    }
}

if (!$is_cli) {
    echo '            </ul>
        </div>
        <div class="footer">
            &copy; ' . date('Y') . ' Bumame Inventory System &bull; Version 2.1
        </div>
    </div>
</body>
</html>';
}
