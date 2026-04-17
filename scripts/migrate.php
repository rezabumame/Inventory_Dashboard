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
            --primary: #6366f1;
            --primary-2: #22c55e;
            --success: #22c55e;
            --warn: #f59e0b;
            --error: #ef4444;
            --bg: #060712;
            --card: rgba(255,255,255,.06);
            --card-2: rgba(255,255,255,.08);
            --text: rgba(255,255,255,.92);
            --text-muted: rgba(255,255,255,.62);
            --ring: rgba(99,102,241,.45);
            --border: rgba(255,255,255,.10);
            --shadow: 0 20px 60px rgba(0,0,0,.45);
        }
        body {
            font-family: "Inter", sans-serif;
            background: radial-gradient(1200px 800px at 20% 10%, rgba(99,102,241,.35), transparent 55%),
                        radial-gradient(900px 700px at 80% 30%, rgba(34,197,94,.22), transparent 55%),
                        radial-gradient(900px 700px at 60% 95%, rgba(245,158,11,.14), transparent 55%),
                        var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2.25rem 1.25rem;
            display: flex;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            width: 100%;
            max-width: 980px;
        }
        .header {
            margin-bottom: 1.25rem;
            text-align: center;
        }
        .header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.04em;
            background: linear-gradient(90deg, rgba(99,102,241,1), rgba(34,197,94,1));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .header p {
            color: var(--text-muted);
            margin-top: 0.65rem;
        }
        .card {
            background: var(--card);
            border-radius: 1.25rem;
            box-shadow: var(--shadow);
            padding: 1.1rem;
            overflow: hidden;
            border: 1px solid var(--border);
            backdrop-filter: blur(14px);
        }
        .topbar {
            background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03));
            border: 1px solid var(--border);
            border-radius: 1.1rem;
            padding: 0.95rem 1rem;
            margin-bottom: 0.9rem;
            display: grid;
            gap: 0.75rem;
        }
        .toprow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            min-width: 260px;
        }
        .pulse {
            width: 12px;
            height: 12px;
            border-radius: 999px;
            background: rgba(34,197,94,1);
            box-shadow: 0 0 0 0 rgba(34,197,94,.55);
            animation: pulse 1.2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34,197,94,.55); }
            70% { box-shadow: 0 0 0 12px rgba(34,197,94,0); }
            100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }
        .brand-title {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
        }
        .brand-title .t1 { font-weight: 700; letter-spacing: -0.02em; }
        .brand-title .t2 { font-size: 0.82rem; color: var(--text-muted); }
        .chips {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .chip {
            font-size: 0.78rem;
            padding: 0.28rem 0.55rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.05);
            color: var(--text);
        }
        .chip b { font-weight: 700; }
        .progress {
            height: 12px;
            border-radius: 999px;
            background: rgba(255,255,255,.06);
            border: 1px solid var(--border);
            overflow: hidden;
            position: relative;
        }
        .progress > .bar {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(99,102,241,1), rgba(34,197,94,1), rgba(245,158,11,1));
            transition: width .25s ease;
        }
        .progress > .shine {
            position: absolute;
            top: -50%;
            left: -30%;
            width: 35%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent);
            transform: rotate(18deg);
            animation: shine 1.25s linear infinite;
            pointer-events: none;
        }
        @keyframes shine {
            0% { left: -30%; }
            100% { left: 120%; }
        }
        .filters {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }
        .btn {
            appearance: none;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.04);
            color: var(--text);
            padding: 0.45rem 0.65rem;
            border-radius: 0.85rem;
            font-size: 0.82rem;
            cursor: pointer;
            transition: transform .12s ease, background .12s ease, border-color .12s ease;
        }
        .btn:hover { transform: translateY(-1px); border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.06); }
        .btn.active { outline: 2px solid var(--ring); border-color: rgba(99,102,241,.55); background: rgba(99,102,241,.14); }
        .task-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .task-item {
            display: flex;
            align-items: center;
            padding: 0.85rem 0.85rem;
            border-bottom: 1px solid rgba(255,255,255,.08);
            gap: 0.75rem;
            border-radius: 0.95rem;
            margin: 0.25rem 0;
            background: rgba(255,255,255,.02);
            animation: pop .22s ease;
        }
        @keyframes pop {
            from { transform: translateY(6px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .task-item:last-child {
            border-bottom: none;
        }
        .task-status {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 0.75rem;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.10);
            flex: 0 0 auto;
        }
        .task-status.success { color: var(--success); }
        .task-status.noop { color: rgba(199,210,254,1); }
        .task-status.error { color: var(--error); }
        .task-info {
            flex-grow: 1;
            min-width: 0;
        }
        .task-name {
            font-weight: 600;
            font-size: 0.92rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
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
            border: 1px solid rgba(255,255,255,.10);
        }
        .badge-success { background: rgba(34,197,94,.14); color: rgba(187,247,208,1); }
        .badge-info { background: rgba(99,102,241,.14); color: rgba(199,210,254,1); }
        .badge-error { background: rgba(239,68,68,.14); color: rgba(254,202,202,1); }
        .badge-warn { background: rgba(245,158,11,.14); color: rgba(253,230,138,1); }
        .muted { color: var(--text-muted); }
        .sr {
            position: absolute !important;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0);
            white-space: nowrap; border: 0;
        }
        .confetti {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 50;
        }
        .confetti i {
            position: absolute;
            width: 10px;
            height: 14px;
            background: rgba(99,102,241,1);
            top: -30px;
            border-radius: 2px;
            opacity: .9;
            animation: fall 1.4s linear forwards;
        }
        @keyframes fall {
            to { transform: translateY(calc(100vh + 60px)) rotate(260deg); opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .pulse, .progress > .shine, .task-item, .confetti i { animation: none !important; }
            .progress > .bar { transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="confetti" id="confetti" aria-hidden="true"></div>
    <div class="container">
        <div class="header">
            <h1>System Migration</h1>
            <p>Verifying and updating database schema...</p>
        </div>
        <div class="card">
            <div class="topbar">
                <div class="toprow">
                    <div class="brand">
                        <div class="pulse" title="Running"></div>
                        <div class="brand-title">
                            <div class="t1">Migration Live Console</div>
                            <div class="t2">Streaming progress in real-time</div>
                        </div>
                    </div>
                    <div class="chips" aria-label="Migration counters">
                        <div class="chip">Total: <b id="c-total">0</b></div>
                        <div class="chip">Changed: <b id="c-changed">0</b></div>
                        <div class="chip">No-op: <b id="c-noop">0</b></div>
                        <div class="chip">Errors: <b id="c-error">0</b></div>
                    </div>
                </div>
                <div class="progress" aria-label="Progress">
                    <div class="bar" id="pbar"></div>
                    <div class="shine" aria-hidden="true"></div>
                </div>
                <div class="toprow">
                    <div class="filters" aria-label="Filters">
                        <button class="btn active" data-filter="all" type="button">All</button>
                        <button class="btn" data-filter="changed" type="button">Changed</button>
                        <button class="btn" data-filter="noop" type="button">No-op</button>
                        <button class="btn" data-filter="error" type="button">Error</button>
                    </div>
                    <div class="muted" style="font-size:.82rem">
                        <span title="Ada perubahan di DB / data">✅ <b>CHANGED</b></span>
                        <span style="margin:0 .4rem;opacity:.55">•</span>
                        <span title="Tidak ada perubahan (sudah sesuai / dilewati)">⬜ <b>NO-OP</b></span>
                        <span style="margin:0 .4rem;opacity:.55">•</span>
                        <span title="Terjadi error saat task dijalankan">❌ <b>ERROR</b></span>
                        <span style="margin-left:.6rem;opacity:.65">— klik filter untuk fokus</span>
                    </div>
                </div>
            </div>
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
        $displayText = $message ?: $detail;
        $is_noop = ($status === 'success') && preg_match('/^(Already|Skipped|Table not found)/i', trim((string)$detail));
        $kind = ($status === 'success') ? ($is_noop ? 'noop' : 'changed') : 'error';
        $icon = ($kind === 'changed') ? '✅' : (($kind === 'noop') ? '⬜' : '❌');
        $statusClass = ($kind === 'changed') ? 'success' : (($kind === 'noop') ? 'noop' : 'error');
        $badgeClass = ($kind === 'changed') ? 'badge-success' : (($kind === 'noop') ? 'badge-info' : 'badge-error');
        $kindLabel = ($kind === 'changed') ? 'CHANGED' : (($kind === 'noop') ? 'NO-OP' : 'ERROR');
        $kindBadge = ($kind === 'changed') ? 'badge-success' : (($kind === 'noop') ? 'badge-info' : 'badge-error');
        
        echo '<li class="task-item" data-kind="' . $kind . '">
            <div class="task-status ' . $statusClass . '" title="' . htmlspecialchars($kindLabel) . '">' . $icon . '</div>
            <div class="task-info">
                <div class="task-name">
                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . htmlspecialchars($name) . '</span>
                    <span style="display:flex;align-items:center;gap:.45rem;flex:0 0 auto;">
                        <span class="badge ' . $kindBadge . '" style="letter-spacing:.08em;font-size:.68rem;padding:.15rem .45rem;">' . $kindLabel . '</span>
                        <span class="badge ' . $badgeClass . '">' . htmlspecialchars($displayText) . '</span>
                    </span>
                </div>
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

    run_migration_task("Table: inventory_booking_request_dedup", function() use ($conn) { return m_ensure_table($conn, "inventory_booking_request_dedup", "CREATE TABLE IF NOT EXISTS inventory_booking_request_dedup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_request_id VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        booking_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client (client_request_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); });

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
        ['inventory_pemakaian_bhp', 'status', "ENUM('active','pending_add','pending_edit','pending_delete','rejected') DEFAULT 'active'"],
        ['inventory_pemakaian_bhp', 'approval_reason', "TEXT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_by', "INT NULL"],
        ['inventory_pemakaian_bhp', 'spv_approved_at', "DATETIME NULL"],
        ['inventory_pemakaian_bhp', 'pending_data', "LONGTEXT NULL"],
        ['inventory_pemakaian_bhp', 'change_source', "ENUM('admin_logistik','nakes','sistem_integrasi') NULL"],
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
    ];

    foreach ($cols as $c) {
        run_migration_task("Column: {$c[0]}.{$c[1]}", function() use ($conn, $c) {
            return m_ensure_column($conn, $c[0], $c[1], $c[2]);
        });
    }

    run_migration_task("Update: inventory_pemakaian_bhp tanggal type", function() use ($conn) { return $conn->query("ALTER TABLE inventory_pemakaian_bhp MODIFY COLUMN tanggal DATETIME NOT NULL") ? "Updated" : "Failed"; });

    // Indexes
    run_migration_task("Index: inventory_barang uniq_odoo", function() use ($conn) { return m_ensure_unique_if_clean($conn, 'inventory_barang', 'odoo_product_id', 'uniq_odoo_product_id'); });
    run_migration_task("Index: inventory_barang uniq_kode", function() use ($conn) { return m_ensure_unique_if_clean($conn, 'inventory_barang', 'kode_barang', 'uniq_kode_barang'); });
    
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
  const list = document.querySelector(".task-list");
  const totalEl = document.getElementById("c-total");
  const changedEl = document.getElementById("c-changed");
  const noopEl = document.getElementById("c-noop");
  const errEl = document.getElementById("c-error");
  const pbar = document.getElementById("pbar");
  const confetti = document.getElementById("confetti");
  const btns = Array.from(document.querySelectorAll("[data-filter]"));

  function classify(li) {
    if (li.dataset && li.dataset.kind) return li.dataset.kind;
    const badge = li.querySelector(".badge");
    const status = li.querySelector(".task-status");
    const text = (badge ? badge.textContent : "").trim().toLowerCase();
    const icon = status ? status.textContent.trim() : "";
    const isError = li.querySelector(".task-status.error") || icon === "❌";
    const isNoop = icon === "⬜" || /^already|^skipped|^table not found/.test(text);
    li.dataset.kind = isError ? "error" : (isNoop ? "noop" : "changed");
    return li.dataset.kind;
  }

  function recount() {
    const items = Array.from(list.querySelectorAll("li.task-item"));
    let changed = 0, noop = 0, err = 0;
    for (const li of items) {
      const kind = li.dataset.kind || classify(li);
      if (kind === "changed") changed++;
      else if (kind === "noop") noop++;
      else if (kind === "error") err++;
    }
    const total = items.length;
    if (totalEl) totalEl.textContent = String(total);
    if (changedEl) changedEl.textContent = String(changed);
    if (noopEl) noopEl.textContent = String(noop);
    if (errEl) errEl.textContent = String(err);

    // Unknown total count: use a “soft” progress (approaches 100%).
    if (pbar) {
      const soft = 100 * (1 - Math.pow(0.93, total));
      pbar.style.width = Math.min(99, Math.max(6, soft)) + "%";
    }

    // Heuristic: last task name indicates end.
    const doneHint = total > 0 && items[items.length - 1].textContent.includes("Data: replace PBH in catatan");
    if (doneHint && pbar) {
      pbar.style.width = "100%";
      if (err === 0) fireConfetti();
      const pulse = document.querySelector(".pulse");
      if (pulse) pulse.style.background = err === 0 ? "rgba(34,197,94,1)" : "rgba(239,68,68,1)";
    }
  }

  let confettiFired = false;
  function fireConfetti() {
    if (confettiFired || !confetti) return;
    confettiFired = true;
    const colors = ["#6366f1", "#22c55e", "#f59e0b", "#ef4444", "#a78bfa"];
    for (let i = 0; i < 90; i++) {
      const p = document.createElement("i");
      const left = Math.random() * 100;
      const delay = Math.random() * 0.35;
      const dur = 1.05 + Math.random() * 0.85;
      const rot = Math.random() * 360;
      p.style.left = left + "vw";
      p.style.animationDelay = delay + "s";
      p.style.animationDuration = dur + "s";
      p.style.transform = `rotate(${rot}deg)`;
      p.style.background = colors[i % colors.length];
      p.style.opacity = String(0.7 + Math.random() * 0.3);
      p.style.width = (7 + Math.random() * 8) + "px";
      p.style.height = (10 + Math.random() * 10) + "px";
      confetti.appendChild(p);
      setTimeout(() => p.remove(), (delay + dur) * 1000 + 300);
    }
  }

  function setFilter(kind) {
    for (const li of list.querySelectorAll("li.task-item")) {
      const k = li.dataset.kind || classify(li);
      li.style.display = (kind === "all" || k === kind) ? "" : "none";
    }
    for (const b of btns) b.classList.toggle("active", b.dataset.filter === kind);
  }

  for (const b of btns) b.addEventListener("click", () => setFilter(b.dataset.filter));

  if (list) {
    const obs = new MutationObserver((muts) => {
      for (const m of muts) {
        for (const node of m.addedNodes) {
          if (node && node.nodeType === 1 && node.matches && node.matches("li.task-item")) classify(node);
        }
      }
      recount();
    });
    obs.observe(list, { childList: true });
  }

  recount();
})();
</script>
</html>';
}
