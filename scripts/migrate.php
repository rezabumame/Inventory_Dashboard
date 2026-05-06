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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #204EAB;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --error: #ef4444;
            --info: #3b82f6;
            --noop-bg: #f1f5f9;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Poppins", sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 1.5rem 1rem;
            line-height: 1.5;
        }
        .container {
            width: 100%;
            max-width: 850px;
            margin: 0 auto;
        }
        .header {
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-title h1 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.5rem;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .header-title p {
            color: var(--text-muted);
            margin: 4px 0 0 0;
            font-size: 0.875rem;
        }
        .card {
            background: var(--card);
            border-radius: 16px;
            box-shadow: 0 4px 20px -5px rgba(0,0,0,0.07);
            padding: 2rem;
            border: 1px solid var(--border);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            padding: 1rem;
            background: var(--bg);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-val { display: block; font-weight: 700; color: var(--text); font-size: 1.25rem; line-height: 1.2; }
        .stat-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; }
        
        .progress-container {
            margin-bottom: 2.5rem;
        }
        .progress-bar-bg {
            height: 8px;
            background: var(--border);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        #pbar { 
            height: 100%; 
            background: linear-gradient(90deg, var(--primary), #3b82f6); 
            width: 0%; 
            transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1); 
        }

        .tabs-nav {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .tab-btn {
            padding: 0.75rem 0.25rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-muted);
            border-bottom: 3px solid transparent;
            position: relative;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-btn .count-badge {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 6px;
            background: var(--border);
            color: var(--text-muted);
            font-weight: 700;
        }
        .tab-btn.active .count-badge { background: var(--primary); color: #fff; }

        .task-list { list-style: none; padding: 0; margin: 0; }
        .task-item {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 0.5rem;
            background: #fff;
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        .task-item:hover { border-color: var(--border); background: var(--bg); }
        .task-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            flex-shrink: 0;
            font-size: 1.1rem;
        }
        .task-content { flex: 1; min-width: 0; }
        .task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px; }
        .task-name { font-weight: 600; font-size: 0.95rem; color: var(--text); }
        .task-msg { font-size: 0.8rem; color: var(--text-muted); }
        
        .status-badge {
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        
        .bg-changed { background: rgba(16, 185, 129, 0.1); color: #059669; }
        .bg-noop { background: rgba(100, 116, 139, 0.1); color: #475569; }
        .bg-error { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
        
        .icon-changed { background: #dcfce7; color: #166534; }
        .icon-noop { background: #f1f5f9; color: #475569; }
        .icon-error { background: #fee2e2; color: #991b1b; }

        #history-tasks-list { opacity: 0.8; }
        #history-tasks-list .task-item { background: #fcfcfc; }

        .footer { text-align: center; margin-top: 2rem; font-size: 0.8rem; color: var(--text-muted); }
        
        #confetti { position: fixed; inset: 0; pointer-events: none; z-index: 100; overflow: hidden; }
        #confetti i { position: absolute; top: -20px; animation: fall linear forwards; }
        @keyframes fall {
            to { transform: translateY(105vh) rotate(360deg); }
        }

        /* Hide items being echoed by PHP initially */
        #php-buffer { display: none; }
    </style>
</head>
<body>
    <div id="confetti"></div>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>Database Migration</h1>
                <p>Ensuring system schema & data integrity</p>
            </div>
            <div class="header-action">
                <span class="status-badge bg-changed" id="sync-status">Live Sync</span>
            </div>
        </div>
        
        <div class="card">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Tasks</span>
                    <span class="stat-val" id="c-total">0</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label" style="color:#059669">Changed</span>
                    <span class="stat-val" id="c-changed">0</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label" style="color:#475569">Already Fixed</span>
                    <span class="stat-val" id="c-noop">0</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label" style="color:#dc2626">Errors</span>
                    <span class="stat-val" id="c-error">0</span>
                </div>
            </div>

            <div class="progress-container">
                <div class="progress-bar-bg">
                    <div id="pbar"></div>
                </div>
            </div>

            <div class="tabs-nav">
                <div class="tab-btn active" data-tab="new">
                    New Updates <span id="c-new-badge" class="count-badge">0</span>
                </div>
                <div class="tab-btn" data-tab="history">
                    Already Fixed <span id="c-history-badge" class="count-badge">0</span>
                </div>
            </div>

            <div id="new-tasks-container">
                <ul class="task-list" id="new-tasks-list"></ul>
            </div>
            <div id="history-tasks-container" style="display:none">
                <ul class="task-list" id="history-tasks-list"></ul>
            </div>
            
            <!-- PHP Output Buffer -->
            <div id="php-buffer"></div>
        </div>
        
        <div class="footer">
            Bumame Inventory System &bull; &copy; 2026 &bull; Stable Release 2.1
        </div>
    </div>
</body>';
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
        $displayText = $message ?: $detail;
        $is_noop = ($status === 'success') && preg_match('/^(Already|Skipped|Table not found)/i', trim((string)$detail));
        $kind = ($status === 'success') ? ($is_noop ? 'noop' : 'changed') : 'error';
        
        $icon = ($kind === 'changed') ? 'check' : (($kind === 'noop') ? 'minus' : 'times');
        $statusLabel = ($kind === 'changed') ? 'CHANGED' : (($kind === 'noop') ? 'NO-OP' : 'ERROR');
        
        // Output into the hidden buffer
        echo '<script>
            (function() {
                const li = document.createElement("li");
                li.className = "task-item";
                li.dataset.kind = "' . $kind . '";
                li.innerHTML = `
                    <div class="task-icon-box icon-' . $kind . '">
                        <i class="fas fa-' . $icon . '"></i>
                    </div>
                    <div class="task-content">
                        <div class="task-header">
                            <span class="task-name">' . htmlspecialchars($name) . '</span>
                            <span class="status-badge bg-' . $kind . '">' . $statusLabel . '</span>
                        </div>
                        <div class="task-msg">' . htmlspecialchars($displayText) . '</div>
                    </div>
                `;
                document.getElementById("php-buffer").appendChild(li);
            })();
        </script>';
        
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
    global $conn;
    run_migration_task("Table: inventory_app_settings", function() use ($conn) { return m_ensure_table($conn, "inventory_app_settings", "CREATE TABLE IF NOT EXISTS inventory_app_settings (k VARCHAR(100) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });
    run_migration_task("Table: inventory_app_counters", function() use ($conn) { return m_ensure_table($conn, "inventory_app_counters", "CREATE TABLE IF NOT EXISTS inventory_app_counters (k VARCHAR(50) NOT NULL, d CHAR(8) NOT NULL, seq INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (k, d)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });
    run_migration_task("Table: inventory_barang_uom_conversion", function() use ($conn) { return m_ensure_table($conn, "inventory_barang_uom_conversion", "CREATE TABLE IF NOT EXISTS inventory_barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(100) NOT NULL,
        from_uom VARCHAR(50) NULL,
        to_uom VARCHAR(50) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kode_barang (kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    run_migration_task("Table: inventory_stock_mirror", function() use ($conn) { return m_ensure_table($conn, "inventory_stock_mirror", "CREATE TABLE IF NOT EXISTS inventory_stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    run_migration_task("Table: inventory_stok_tas_hc", function() use ($conn) { return m_ensure_table($conn, "inventory_stok_tas_hc", "CREATE TABLE IF NOT EXISTS inventory_stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    run_migration_task("Table: inventory_hc_petugas_transfer", function() use ($conn) { return m_ensure_table($conn, "inventory_hc_petugas_transfer", "CREATE TABLE IF NOT EXISTS inventory_hc_petugas_transfer (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    run_migration_task("Table: inventory_hc_tas_allocation", function() use ($conn) { return m_ensure_table($conn, "inventory_hc_tas_allocation", "CREATE TABLE IF NOT EXISTS inventory_hc_tas_allocation (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    // Mass Allocation SO: Ensure qty fields support 0 and defaults are correct
    run_migration_task("Mass SO: inventory_stok_tas_hc qty (ensure default 0)", function() use ($conn) { 
        return $conn->query("ALTER TABLE inventory_stok_tas_hc MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Ensured" : "Failed"; 
    });
    run_migration_task("Mass SO: inventory_hc_tas_allocation qty (ensure default 0)", function() use ($conn) { 
        return $conn->query("ALTER TABLE inventory_hc_tas_allocation MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Ensured" : "Failed"; 
    });

    run_migration_task("Update: inventory_hc_petugas_transfer qty", function() use ($conn) { return $conn->query("ALTER TABLE inventory_hc_petugas_transfer MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_hc_tas_allocation qty", function() use ($conn) { return $conn->query("ALTER TABLE inventory_hc_tas_allocation MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_stok_tas_hc qty", function() use ($conn) { return $conn->query("ALTER TABLE inventory_stok_tas_hc MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_transaksi_stok qty", function() use ($conn) { return $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_transaksi_stok qty_sebelum", function() use ($conn) { return $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sebelum DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_transaksi_stok qty_sesudah", function() use ($conn) { return $conn->query("ALTER TABLE inventory_transaksi_stok MODIFY COLUMN qty_sesudah DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed"; });
    
    run_migration_task("Update: inventory_booking_detail qty_fields", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_onsite DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_reserved_hc DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_done_onsite DECIMAL(18,4) DEFAULT 0.0000");
        $conn->query("ALTER TABLE inventory_booking_detail MODIFY COLUMN qty_adjust DECIMAL(18,4) DEFAULT 0.0000");
        return "Updated 5 columns";
    });

    run_migration_task("Update: inventory_pemeriksaan_grup_detail qty", function() use ($conn) {
        return $conn->query("ALTER TABLE inventory_pemeriksaan_grup_detail MODIFY COLUMN qty_per_pemeriksaan DECIMAL(18,4) NOT NULL DEFAULT 1") ? "Updated" : "Failed";
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
    
    run_migration_task("Update: inventory_transfer_barang_detail qty", function() use ($conn) { return $conn->query("ALTER TABLE inventory_transfer_barang_detail MODIFY COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_pemakaian_bhp status enum", function() use ($conn) { return $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN status ENUM('active','pending_add','pending_edit','pending_delete','rejected','revised','pending_approval_spv') DEFAULT 'active'") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_booking_pemeriksaan status enum", function() use ($conn) { return $conn->query("ALTER TABLE inventory_booking_pemeriksaan MODIFY COLUMN status ENUM('booked','completed','cancelled','pending_edit','pending_delete','rejected','rescheduled') DEFAULT 'booked'") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_booking_pasien status enum", function() use ($conn) { return $conn->query("ALTER TABLE inventory_booking_pasien MODIFY COLUMN status ENUM('booked','done','rescheduled','cancelled') DEFAULT 'booked'") ? "Updated" : "Failed"; });
    run_migration_task("Update: inventory_pemakaian_bhp change_source to VARCHAR(64)", function() use ($conn) { return $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN change_source VARCHAR(64) NULL") ? "Updated" : "Failed"; });

    run_migration_task("Table: inventory_booking_request_dedup", function() use ($conn) { return m_ensure_table($conn, "inventory_booking_request_dedup", "CREATE TABLE IF NOT EXISTS inventory_booking_request_dedup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_request_id VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        booking_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client (client_request_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

    run_migration_task("Table: inventory_booking_history", function() use ($conn) { 
        return m_ensure_table($conn, "inventory_booking_history", "CREATE TABLE IF NOT EXISTS inventory_booking_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            user_id INT NOT NULL,
            user_name VARCHAR(255),
            action VARCHAR(100),
            changes LONGTEXT,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (booking_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    });

    // Columns
    run_migration_task("Update: inventory_pemeriksaan_grup id to VARCHAR", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_pemeriksaan_grup MODIFY id VARCHAR(50) NOT NULL");
        return "Updated";
    });

    run_migration_task("Update: inventory_pemeriksaan_grup_detail grup_id to VARCHAR", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_pemeriksaan_grup_detail MODIFY pemeriksaan_grup_id VARCHAR(50) NOT NULL");
        return "Updated";
    });

    run_migration_task("Update: inventory_booking_pasien grup_id to VARCHAR", function() use ($conn) {
        $conn->query("ALTER TABLE inventory_booking_pasien MODIFY pemeriksaan_grup_id VARCHAR(50) NOT NULL");
        return "Updated";
    });

    $cols = [
        ['inventory_barang', 'kode_barang', "VARCHAR(64) NULL"],
        ['inventory_barang', 'nama_barang', "VARCHAR(255) NULL"],
        ['inventory_barang', 'satuan', "VARCHAR(64) NULL"],
        ['inventory_barang', 'kategori', "VARCHAR(64) NULL"],
        ['inventory_barang', 'stok_minimum', "INT NOT NULL DEFAULT 0"],
        ['inventory_barang', 'odoo_product_id', "VARCHAR(64) NULL"],
        ['inventory_barang', 'uom', "VARCHAR(64) NULL"],
        ['inventory_barang', 'barcode', "VARCHAR(64) NULL"],
        ['inventory_barang', 'tipe', "ENUM('Core', 'Support') DEFAULT NULL AFTER kategori"],
        ['inventory_booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL"],
        ['inventory_booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL"],
        ['inventory_booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL"],
        ['inventory_booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL"],
        ['inventory_booking_pemeriksaan', 'tanggal_lahir', "DATE NULL"],
        ['inventory_booking_pemeriksaan', 'butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'is_out_of_stock', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_booking_pemeriksaan', 'out_of_stock_items', "TEXT NULL"],
        ['inventory_pemakaian_bhp', 'status', "ENUM('active','pending_add','pending_edit','pending_delete','rejected','revised','pending_approval_spv') DEFAULT 'active'"],
        ['inventory_pemakaian_bhp', 'revision', "INT DEFAULT 0 AFTER parent_id"],
        ['inventory_pemakaian_bhp', 'no_bhp_parent', "VARCHAR(50) NULL AFTER revision"],
        ['inventory_pemakaian_bhp', 'approval_reason', "TEXT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_by', "INT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_at', "DATETIME NULL"],
        ['inventory_pemakaian_bhp', 'pending_data', "LONGTEXT NULL"],
        ['inventory_pemakaian_bhp', 'change_source', "VARCHAR(64) NULL"],
        ['inventory_pemakaian_bhp', 'change_actor_user_id', "INT NULL"],
        ['inventory_pemakaian_bhp', 'change_actor_name', "VARCHAR(255) NULL"],
        ['inventory_pemakaian_bhp', 'change_reason_code', "VARCHAR(64) NULL"],
        ['inventory_pemakaian_bhp', 'is_auto', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['inventory_pemakaian_bhp', 'booking_id', "INT NULL"],
        ['inventory_booking_pasien', 'nomor_tlp', "VARCHAR(30) NULL"],
        ['inventory_booking_pasien', 'tanggal_lahir', "DATE NULL"],
        ['inventory_request_barang', 'dokumen_path', "VARCHAR(255) NULL"],
        ['inventory_request_barang', 'dokumen_name', "VARCHAR(255) NULL"],
        ['inventory_request_barang', 'processed_by', "INT NULL"],
        ['inventory_request_barang', 'processed_at', "TIMESTAMP NULL"],
        ['inventory_request_barang_detail', 'qty_received', "INT NOT NULL DEFAULT 0"],
        ['inventory_pemeriksaan_grup_detail', 'is_mandatory', "TINYINT(1) NOT NULL DEFAULT 1"],
        ['inventory_pemeriksaan_grup_detail', 'id_biosys', "VARCHAR(50) DEFAULT NULL AFTER pemeriksaan_grup_id"],
        ['inventory_pemeriksaan_grup_detail', 'nama_layanan', "VARCHAR(255) DEFAULT NULL AFTER id_biosys"],
        ['inventory_pemeriksaan_grup', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"],
        ['inventory_pemakaian_bhp', 'user_hc_id', "INT NULL AFTER klinik_id"],
        ['inventory_booking_pemeriksaan', 'reschedule_reason', "TEXT NULL AFTER out_of_stock_items"],
        ['inventory_booking_pasien', 'status', "ENUM('booked', 'done', 'rescheduled', 'cancelled') DEFAULT 'booked' AFTER tanggal_lahir"],
        ['inventory_booking_pasien', 'remark', "TEXT NULL AFTER status"],
        ['inventory_booking_pasien', 'done_at', "DATETIME NULL AFTER remark"],
        ['inventory_barang_lokal', 'kode_item', "VARCHAR(50) NULL AFTER id"],
        ['inventory_barang_lokal', 'kategori', "VARCHAR(50) NULL AFTER uom"],
        ['inventory_barang_lokal', 'odoo_id', "INT NULL AFTER kategori COMMENT 'ID dari inventory_barang jika di-import'"],
        ['inventory_stok_lokal', 'qty_gantung', "DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty"],
        ['inventory_history_lokal', 'reference_id', "INT NULL AFTER keterangan COMMENT 'ID dari inventory_pemakaian_bhp jika tipe=pakai'"],
    ];

    foreach ($cols as $c) {
        run_migration_task("Column: {$c[0]}.{$c[1]}", function() use ($conn, $c) {
            return m_ensure_column($conn, $c[0], $c[1], $c[2]);
        });
    }

    // Initial settings for GSheet & Lark
    run_migration_task("Data: GSheet Sync Settings", function() use ($conn) {
        if (!table_exists($conn, 'inventory_app_settings')) return "Table not found";
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('gsheet_exam_url', '')");
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('gsheet_exam_sheet', '')");
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('gsheet_exam_mapping', '{}')");
        return "Settings Initialized";
    });

    run_migration_task("Data: Lark Webhook Settings", function() use ($conn) {
        if (!table_exists($conn, 'inventory_app_settings')) return "Table not found";
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('webhook_lark_url', '')");
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('webhook_lark_booking_url', '')");
        $conn->query("INSERT IGNORE INTO inventory_app_settings (k, v) VALUES ('webhook_lark_booking_at_id', '')");
        return "Settings Initialized";
    });

    // Indexes
    run_migration_task("Index: inventory_barang uniq_odoo", function() use ($conn) { return m_ensure_unique_if_clean($conn, 'inventory_barang', 'odoo_product_id', 'uniq_odoo_product_id'); });
    run_migration_task("Index: inventory_barang uniq_kode", function() use ($conn) { return m_ensure_unique_if_clean($conn, 'inventory_barang', 'kode_barang', 'uniq_kode_barang'); });
    run_migration_task("Index: inventory_booking_pemeriksaan uniq_nomor", function() use ($conn) { return m_ensure_unique_if_clean($conn, 'inventory_booking_pemeriksaan', 'nomor_booking', 'nomor_booking'); });
    
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
        ['inventory_pemakaian_bhp', 'idx_pbh_change_actor', "CREATE INDEX idx_pbh_change_actor ON inventory_pemakaian_bhp (change_actor_user_id)"],
    ];

    foreach ($indices as $idx) {
        run_migration_task("Index: {$idx[0]}.{$idx[1]}", function() use ($conn, $idx) {
            return m_ensure_index($conn, $idx[0], $idx[1], $idx[2]);
        });
    }

    // Data migrations
    run_migration_task("Data: counters PBH -> BHP", function() use ($conn) {
        if (!table_exists($conn, 'inventory_app_counters')) return "Table not found";
        $ok = $conn->query("
            INSERT INTO inventory_app_counters (k, d, seq)
            SELECT 'BHP' AS k, src.d, src.seq
            FROM inventory_app_counters src
            WHERE src.k = 'PBH'
            ON DUPLICATE KEY UPDATE seq = GREATEST(inventory_app_counters.seq, VALUES(seq))
        ");
        return $ok ? "Merged" : "Failed";
    });

    run_migration_task("Data: nomor pemakaian PBH -> BHP", function() use ($conn) {
        if (!table_exists($conn, 'inventory_pemakaian_bhp')) return "Table not found";

        $r_total = $conn->query("SELECT COUNT(*) AS c FROM inventory_pemakaian_bhp WHERE nomor_pemakaian LIKE 'PBH-%'");
        $total = (int)(($r_total && ($row = $r_total->fetch_assoc())) ? ($row['c'] ?? 0) : 0);
        if ($total <= 0) return "Already Up-to-date";

        // Detect potential collisions with existing BHP numbers
        $r_conf = $conn->query("
            SELECT COUNT(*) AS c
            FROM inventory_pemakaian_bhp pb
            JOIN inventory_pemakaian_bhp bhp
              ON bhp.nomor_pemakaian = CONCAT('BHP-', SUBSTRING(pb.nomor_pemakaian, 5))
            WHERE pb.nomor_pemakaian LIKE 'PBH-%'
        ");
        $conflicts = (int)(($r_conf && ($crow = $r_conf->fetch_assoc())) ? ($crow['c'] ?? 0) : 0);

        // Update only rows that won't violate UNIQUE(nomor_pemakaian)
        $ok = $conn->query("
            UPDATE inventory_pemakaian_bhp pb
            LEFT JOIN inventory_pemakaian_bhp bhp
              ON bhp.nomor_pemakaian = CONCAT('BHP-', SUBSTRING(pb.nomor_pemakaian, 5))
            SET pb.nomor_pemakaian = CONCAT('BHP-', SUBSTRING(pb.nomor_pemakaian, 5))
            WHERE pb.nomor_pemakaian LIKE 'PBH-%'
              AND bhp.id IS NULL
        ");
        if (!$ok) return "Failed";

        $updated = (int)($conn->affected_rows ?? 0);
        if ($conflicts > 0) return "Updated $updated, skipped $conflicts (conflict)";
        return "Updated $updated";
    });

    run_migration_task("Data: replace PBH in catatan", function() use ($conn) {
        $changed = 0;

        if (table_exists($conn, 'inventory_transaksi_stok')) {
            $ok1 = $conn->query("
                UPDATE inventory_transaksi_stok
                SET catatan = REPLACE(REPLACE(catatan, 'PBH-', 'BHP-'), 'PBH ', 'BHP ')
                WHERE catatan LIKE '%PBH%'
            ");
            if ($ok1) $changed += (int)($conn->affected_rows ?? 0);
        }

        if (table_exists($conn, 'inventory_pemakaian_bhp')) {
            $ok2 = $conn->query("
                UPDATE inventory_pemakaian_bhp
                SET catatan_transaksi = REPLACE(REPLACE(catatan_transaksi, 'PBH-', 'BHP-'), 'PBH ', 'BHP ')
                WHERE catatan_transaksi LIKE '%PBH%'
            ");
            if ($ok2) $changed += (int)($conn->affected_rows ?? 0);
        }

        return $changed > 0 ? "Updated $changed row(s)" : "Already Up-to-date";
    });

    run_migration_task("Data: normalize tipe_transaksi (in/out)", function() use ($conn) {
        if (!table_exists($conn, 'inventory_transaksi_stok')) return "Table not found";

        $changed = 0;
        // Normalize common variants to in/out
        $ok1 = $conn->query("UPDATE inventory_transaksi_stok SET tipe_transaksi = 'in' WHERE LOWER(TRIM(tipe_transaksi)) IN ('masuk','in')");
        if ($ok1) $changed += (int)($conn->affected_rows ?? 0);

        $ok2 = $conn->query("UPDATE inventory_transaksi_stok SET tipe_transaksi = 'out' WHERE LOWER(TRIM(tipe_transaksi)) IN ('keluar','out')");
        if ($ok2) $changed += (int)($conn->affected_rows ?? 0);

        // Uppercase variants
        $ok3 = $conn->query("UPDATE inventory_transaksi_stok SET tipe_transaksi = 'in' WHERE TRIM(tipe_transaksi) = 'IN'");
        if ($ok3) $changed += (int)($conn->affected_rows ?? 0);
        $ok4 = $conn->query("UPDATE inventory_transaksi_stok SET tipe_transaksi = 'out' WHERE TRIM(tipe_transaksi) = 'OUT'");
        if ($ok4) $changed += (int)($conn->affected_rows ?? 0);

        return $changed > 0 ? "Updated $changed row(s)" : "Already Up-to-date";
    });

    run_migration_task("Constraint: fk_pemakaian_change_actor", function() use ($conn) {
        if (!table_exists($conn, 'inventory_pemakaian_bhp') || !table_exists($conn, 'inventory_users')) return "Table not found";
        $q = "
            SELECT COUNT(*) AS c
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inventory_pemakaian_bhp'
              AND CONSTRAINT_NAME = 'fk_pemakaian_change_actor'
        ";
        $r = $conn->query($q);
        $exists = (int)(($r && ($row = $r->fetch_assoc())) ? ($row['c'] ?? 0) : 0);
        if ($exists > 0) return "Already exists";
        $ok = $conn->query("ALTER TABLE inventory_pemakaian_bhp ADD CONSTRAINT fk_pemakaian_change_actor FOREIGN KEY (change_actor_user_id) REFERENCES inventory_users(id)");
        return $ok ? "Created" : "Failed";
    });

    run_migration_task("Data: Update BHP No Format (2026 -> 26)", function() use ($conn) {
        if (!table_exists($conn, 'inventory_pemakaian_bhp')) return "Table not found";

        // 1. Update BHP-20... -> BHP-26...
        $res = $conn->query("SELECT id, nomor_pemakaian FROM inventory_pemakaian_bhp WHERE nomor_pemakaian LIKE 'BHP-20%'");
        $updated_count = 0;
        while ($row = $res->fetch_assoc()) {
            $old_no = $row['nomor_pemakaian'];
            $new_no = 'BHP-' . substr($old_no, 6);
            $stmt = $conn->prepare("UPDATE inventory_pemakaian_bhp SET nomor_pemakaian = ? WHERE id = ?");
            $stmt->bind_param("si", $new_no, $row['id']);
            $stmt->execute();
            $updated_count++;
        }

        // 2. Update REQ-ADD-20... -> REQ-ADD-26...
        $res_req = $conn->query("SELECT id, nomor_pemakaian FROM inventory_pemakaian_bhp WHERE nomor_pemakaian LIKE 'REQ-ADD-20%'");
        while ($row = $res_req->fetch_assoc()) {
            $old_no = $row['nomor_pemakaian'];
            // REQ-ADD-20260413-0001 -> REQ-ADD-260413-0001
            $new_no = 'REQ-ADD-' . substr($old_no, 10);
            $stmt = $conn->prepare("UPDATE inventory_pemakaian_bhp SET nomor_pemakaian = ? WHERE id = ?");
            $stmt->bind_param("si", $new_no, $row['id']);
            $stmt->execute();
            $updated_count++;
        }

        return $updated_count > 0 ? "Updated $updated_count numbers" : "Already Up-to-date";
    });

    run_migration_task("Schema: BHP parent_id & status revised", function() use ($conn) {
        $msg = [];
        $check_parent = $conn->query("SHOW COLUMNS FROM inventory_pemakaian_bhp LIKE 'parent_id'");
        if ($check_parent->num_rows == 0) {
            $conn->query("ALTER TABLE inventory_pemakaian_bhp ADD COLUMN parent_id INT NULL AFTER id");
            $conn->query("ALTER TABLE inventory_pemakaian_bhp ADD CONSTRAINT fk_pemakaian_parent FOREIGN KEY (parent_id) REFERENCES inventory_pemakaian_bhp(id) ON DELETE SET NULL");
            $msg[] = "Added parent_id";
        }

        $check_rev = $conn->query("SHOW COLUMNS FROM inventory_pemakaian_bhp LIKE 'revision'");
        if ($check_rev->num_rows == 0) {
            $conn->query("ALTER TABLE inventory_pemakaian_bhp ADD COLUMN revision INT DEFAULT 0 AFTER parent_id");
            $msg[] = "Added revision column";
        }

        $check_parent_no = $conn->query("SHOW COLUMNS FROM inventory_pemakaian_bhp LIKE 'no_bhp_parent'");
        if ($check_parent_no->num_rows == 0) {
            $conn->query("ALTER TABLE inventory_pemakaian_bhp ADD COLUMN no_bhp_parent VARCHAR(50) NULL AFTER revision");
            $msg[] = "Added no_bhp_parent column";
        }
        
        $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN status ENUM('active','pending_add','pending_edit','pending_delete','rejected','revised','pending_approval_spv') DEFAULT 'active'");
        $msg[] = "Updated status enum";
        
        return count($msg) > 0 ? implode(", ", $msg) : "Already exists";
    });

    run_migration_task("Schema: BHP tanggal to DATE", function() use ($conn) {
        $r = $conn->query("SHOW COLUMNS FROM inventory_pemakaian_bhp LIKE 'tanggal'");
        $row = $r->fetch_assoc();
        if (strtoupper($row['Type']) === 'DATE') return "Already DATE";
        
        $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN tanggal DATE NOT NULL");
        return "Changed to DATE";
    });

    run_migration_task("Schema: Add role admin_hc to inventory_users", function() use ($conn) {
        $res = $conn->query("SHOW COLUMNS FROM inventory_users LIKE 'role'");
        $row = $res->fetch_assoc();
        $type = $row['Type'];
        if (strpos($type, 'admin_hc') !== false) return "Already exists";
        
        $new_type = str_replace(")", ",'admin_hc')", $type);
        $conn->query("ALTER TABLE inventory_users MODIFY COLUMN role $new_type");
        return "Updated";
    });

    // BHP Non-Odoo (Local Items)
    run_migration_task("Table: inventory_barang_lokal", function() use ($conn) { 
        return m_ensure_table($conn, "inventory_barang_lokal", "CREATE TABLE IF NOT EXISTS inventory_barang_lokal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_item VARCHAR(50) NULL AFTER id,
            nama_item VARCHAR(255) NOT NULL,
            uom VARCHAR(50) NOT NULL,
            kategori VARCHAR(50) NULL AFTER uom,
            odoo_id INT NULL AFTER kategori COMMENT 'ID dari inventory_barang jika di-import',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    });

    run_migration_task("Table: inventory_stok_lokal", function() use ($conn) {
        return m_ensure_table($conn, "inventory_stok_lokal", "CREATE TABLE IF NOT EXISTS inventory_stok_lokal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barang_lokal_id INT NOT NULL,
            klinik_id INT NOT NULL,
            qty DECIMAL(18,4) NOT NULL DEFAULT 0,
            qty_gantung DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_barang_klinik (barang_lokal_id, klinik_id),
            KEY idx_barang (barang_lokal_id),
            KEY idx_klinik (klinik_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    });

    run_migration_task("Table: inventory_history_lokal", function() use ($conn) {
        return m_ensure_table($conn, "inventory_history_lokal", "CREATE TABLE IF NOT EXISTS inventory_history_lokal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barang_lokal_id INT NOT NULL,
            klinik_id INT NOT NULL,
            tipe ENUM('TAMBAH', 'KURANG', 'PAKAI') NOT NULL,
            qty_sebelum DECIMAL(18,4) NOT NULL DEFAULT 0,
            qty_perubahan DECIMAL(18,4) NOT NULL DEFAULT 0,
            qty_sesudah DECIMAL(18,4) NOT NULL DEFAULT 0,
            status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'completed',
            keterangan TEXT NULL,
            reference_id INT NULL AFTER keterangan COMMENT 'ID dari inventory_pemakaian_bhp jika tipe=pakai',
            created_by INT NOT NULL,
            approved_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME NULL,
            KEY idx_barang (barang_lokal_id),
            KEY idx_klinik (klinik_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    });

    run_migration_task("Schema: Update inventory_history_lokal tipe ENUM", function() use ($conn) {
        if (!table_exists($conn, "inventory_history_lokal")) return "Skipped";
        $res = $conn->query("SHOW COLUMNS FROM inventory_history_lokal LIKE 'tipe'");
        if (!$res) return "Error";
        $row = $res->fetch_assoc();
        $type = $row['Type'];
        if (strpos($type, 'TAMBAH') !== false) return "Already exists";
        
        $conn->query("ALTER TABLE inventory_history_lokal MODIFY COLUMN tipe ENUM('TAMBAH', 'KURANG', 'PAKAI') NOT NULL");
        return "Updated";
    });

    run_migration_task("Schema: Add is_lokal to inventory_pemakaian_bhp_detail", function() use ($conn) {
        $res = $conn->query("SHOW COLUMNS FROM inventory_pemakaian_bhp_detail LIKE 'is_lokal'");
        if ($res && $res->num_rows > 0) return "Already exists";
        
        $conn->query("ALTER TABLE inventory_pemakaian_bhp_detail ADD COLUMN is_lokal TINYINT(1) NOT NULL DEFAULT 0 AFTER barang_id");
        return "Added";
    });

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
<script>
(function () {
  const phpBuffer = document.getElementById("php-buffer");
  const newTasksList = document.getElementById("new-tasks-list");
  const historyTasksList = document.getElementById("history-tasks-list");
  const newTasksContainer = document.getElementById("new-tasks-container");
  const historyTasksContainer = document.getElementById("history-tasks-container");
  
  const totalEl = document.getElementById("c-total");
  const changedEl = document.getElementById("c-changed");
  const noopEl = document.getElementById("c-noop");
  const errEl = document.getElementById("c-error");
  const newBadge = document.getElementById("c-new-badge");
  const historyBadge = document.getElementById("c-history-badge");
  const pbar = document.getElementById("pbar");
  const confetti = document.getElementById("confetti");
  const tabs = Array.from(document.querySelectorAll(".tab-btn"));

  function moveTask(li) {
    const kind = li.dataset.kind;
    if (kind === "noop") {
        historyTasksList.appendChild(li);
    } else {
        newTasksList.appendChild(li);
    }
    recount();
  }

  function recount() {
    const allItems = Array.from(document.querySelectorAll("li.task-item"));
    let changed = 0, noop = 0, err = 0;
    
    for (const li of allItems) {
      const kind = li.dataset.kind;
      if (kind === "changed") changed++;
      else if (kind === "noop") noop++;
      else if (kind === "error") err++;
    }
    
    if (totalEl) totalEl.textContent = String(allItems.length);
    if (changedEl) changedEl.textContent = String(changed);
    if (noopEl) noopEl.textContent = String(noop);
    if (errEl) errEl.textContent = String(err);
    
    if (newBadge) newBadge.textContent = String(changed + err);
    if (historyBadge) historyBadge.textContent = String(noop);

    if (pbar) {
      const total = allItems.length || 1;
      const progress = ((changed + noop + err) / total) * 100;
      pbar.style.width = Math.min(100, progress) + "%";
      
      if (progress >= 100 && err === 0) {
          fireConfetti();
          document.getElementById("sync-status").textContent = "Completed";
          document.getElementById("sync-status").className = "status-badge bg-changed";
      } else if (progress >= 100 && err > 0) {
          document.getElementById("sync-status").textContent = "Done with Errors";
          document.getElementById("sync-status").className = "status-badge bg-error";
      }
    }
  }

  let confettiFired = false;
  function fireConfetti() {
    if (confettiFired || !confetti) return;
    confettiFired = true;
    const colors = ["#6366f1", "#22c55e", "#f59e0b", "#ef4444", "#a78bfa"];
    for (let i = 0; i < 60; i++) {
      const p = document.createElement("i");
      const left = Math.random() * 100;
      const delay = Math.random() * 0.5;
      const dur = 1.5 + Math.random() * 1.5;
      const rot = Math.random() * 360;
      const size = 6 + Math.random() * 6;
      p.style.left = left + "vw";
      p.style.animationDelay = delay + "s";
      p.style.animationDuration = dur + "s";
      p.style.transform = `rotate(${rot}deg)`;
      p.style.background = colors[i % colors.length];
      p.style.width = size + "px";
      p.style.height = (size * 1.5) + "px";
      p.style.borderRadius = "2px";
      confetti.appendChild(p);
      setTimeout(() => p.remove(), (delay + dur) * 1000 + 500);
    }
  }

  function setTab(tab) {
    if (tab === "new") {
        newTasksContainer.style.display = "";
        historyTasksContainer.style.display = "none";
    } else {
        newTasksContainer.style.display = "none";
        historyTasksContainer.style.display = "";
    }
    for (const t of tabs) t.classList.toggle("active", t.dataset.tab === tab);
  }

  for (const t of tabs) t.addEventListener("click", () => setTab(t.dataset.tab));

  const obs = new MutationObserver((muts) => {
    for (const m of muts) {
      for (const node of m.addedNodes) {
        if (node && node.nodeType === 1 && node.matches && node.matches("li.task-item")) {
            moveTask(node);
        }
      }
    }
  });
  
  obs.observe(phpBuffer, { childList: true });
  
  // Initial check for any existing items
  Array.from(phpBuffer.children).forEach(moveTask);
  recount();
})();
</script>
</html>';
}
