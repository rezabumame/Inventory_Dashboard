<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/odoo.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

// Allow internal scheduler token
$internalToken = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
$sync_trigger = ($internalToken !== '' && $internalToken === ODOO_SYNC_SYSTEM_TOKEN) ? 'auto' : 'manual';
$sync_started_at = microtime(true);
if (empty($internalToken) || $internalToken !== ODOO_SYNC_SYSTEM_TOKEN) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    if (!in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

function get_rpc_config() {
    $rpc_url = trim((string)get_setting('odoo_rpc_url', ''));
    $rpc_db = trim((string)get_setting('odoo_rpc_db', ''));
    $rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
    $rpc_pass = (string)get_setting('odoo_rpc_password', '');
    $ok = ($rpc_url !== '' && $rpc_db !== '' && $rpc_user !== '' && $rpc_pass !== '');
    return [$ok, $rpc_url, $rpc_db, $rpc_user, $rpc_pass];
}

function http_get_json($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("HTTP error: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new Exception("HTTP status $code for $url");
    }
    $data = json_decode($resp, true);
    if ($data === null) {
        throw new Exception("Invalid JSON from $url");
    }
    return $data;
}

function lark_webhook_url() {
    $url = trim((string)get_setting('webhook_lark_url', ''));
    if ($url === '') {
        $url = 'https://open.larksuite.com/open-apis/bot/v2/hook/b5fb86a4-f554-4deb-b60b-e03d550847bc';
    }
    return $url;
}

function post_lark_text($text) {
    $url = lark_webhook_url();
    if ($url === '') return;
    $payload = json_encode([
        'msg_type' => 'text',
        'content' => ['text' => $text]
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    curl_close($ch);
}

function mirror_stats(mysqli $conn): array {
    $stats = [
        'total_rows' => 0,
        'total_qty' => 0.0,
        'last_update' => '',
        'by_loc' => []
    ];
    $res = $conn->query("
        SELECT
            TRIM(location_code) AS loc,
            COUNT(*) AS rows_cnt,
            COALESCE(SUM(qty), 0) AS qty_sum,
            MAX(updated_at) AS last_update
        FROM stock_mirror
        GROUP BY TRIM(location_code)
    ");
    if (!$res) return $stats;

    while ($row = $res->fetch_assoc()) {
        $loc = (string)($row['loc'] ?? '');
        if ($loc === '') continue;
        $rows_cnt = (int)($row['rows_cnt'] ?? 0);
        $qty_sum = (float)($row['qty_sum'] ?? 0);
        $stats['total_rows'] += $rows_cnt;
        $stats['total_qty'] += $qty_sum;
        $lu = (string)($row['last_update'] ?? '');
        if ($lu !== '' && ($stats['last_update'] === '' || strtotime($lu) > strtotime($stats['last_update']))) {
            $stats['last_update'] = $lu;
        }
        $stats['by_loc'][$loc] = ['rows' => $rows_cnt, 'qty' => $qty_sum, 'last_update' => $lu];
    }
    return $stats;
}

function fmt_dec($n, $digits = 4): string {
    return number_format((float)$n, $digits, '.', '');
}

function fmt_ts($ts): string {
    $ts = trim((string)$ts);
    if ($ts === '') return '-';
    return date('d M Y H:i', strtotime($ts));
}

function build_diff_lines(array $before, array $after, int $limit = 8): array {
    $before_loc = $before['by_loc'] ?? [];
    $after_loc = $after['by_loc'] ?? [];
    $locs = array_values(array_unique(array_merge(array_keys($before_loc), array_keys($after_loc))));

    $deltas = [];
    foreach ($locs as $loc) {
        $b = $before_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $a = $after_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $dq = (float)$a['qty'] - (float)$b['qty'];
        $dr = (int)$a['rows'] - (int)$b['rows'];
        if (abs($dq) < 0.0001 && $dr === 0) continue;
        $deltas[] = ['loc' => $loc, 'dq' => $dq, 'dr' => $dr, 'b' => $b, 'a' => $a];
    }

    usort($deltas, function ($x, $y) {
        $ax = abs((float)$x['dq']);
        $ay = abs((float)$y['dq']);
        if ($ax === $ay) return abs((int)$y['dr']) <=> abs((int)$x['dr']);
        return $ay <=> $ax;
    });

    $lines = [];
    $take = array_slice($deltas, 0, max(0, $limit));
    foreach ($take as $d) {
        $bq = fmt_dec($d['b']['qty'] ?? 0);
        $aq = fmt_dec($d['a']['qty'] ?? 0);
        $dq = fmt_dec($d['dq'] ?? 0);
        $br = (int)($d['b']['rows'] ?? 0);
        $ar = (int)($d['a']['rows'] ?? 0);
        $dr = (int)($d['dr'] ?? 0);
        $dq_s = ($d['dq'] ?? 0) >= 0 ? ('+' . $dq) : $dq;
        $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
        $lines[] = "- " . $d['loc'] . ": qty $bq → $aq ($dq_s), rows $br → $ar ($dr_s)";
    }
    return $lines;
}

function ensure_column_exists($table, $column, $definition) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
    }
}

function ensure_index_exists($table, $indexName, $createSql) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $idx = $conn->real_escape_string($indexName);
    $res = $conn->query("SHOW INDEX FROM `$t` WHERE Key_name = '$idx'");
    if (!$res || $res->num_rows === 0) {
        $conn->query($createSql);
    }
}

try {
    ensure_column_exists('barang', 'odoo_product_id', 'VARCHAR(64) NULL');
    ensure_column_exists('barang', 'barcode', 'VARCHAR(64) NULL');
    ensure_column_exists('barang', 'uom', 'VARCHAR(64) NULL');

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
    $mirror_before = mirror_stats($conn);

    // Ensure stable upsert keys when possible
    $dup_odoo = $conn->query("SELECT odoo_product_id FROM barang WHERE odoo_product_id IS NOT NULL AND odoo_product_id <> '' GROUP BY odoo_product_id HAVING COUNT(*) > 1 LIMIT 1");
    if ($dup_odoo && $dup_odoo->num_rows === 0) {
        ensure_index_exists('barang', 'uniq_odoo_product_id', "ALTER TABLE barang ADD UNIQUE KEY uniq_odoo_product_id (odoo_product_id)");
    }
    $dup_kode = $conn->query("SELECT kode_barang FROM barang WHERE kode_barang IS NOT NULL AND kode_barang <> '' GROUP BY kode_barang HAVING COUNT(*) > 1 LIMIT 1");
    if ($dup_kode && $dup_kode->num_rows === 0) {
        ensure_index_exists('barang', 'uniq_kode_barang', "ALTER TABLE barang ADD UNIQUE KEY uniq_kode_barang (kode_barang)");
    }

    // Upsert products into barang mirror
    $ins_prod = $conn->prepare("
        INSERT INTO barang (odoo_product_id, kode_barang, nama_barang, satuan, uom, barcode, stok_minimum, kategori)
        VALUES (?, ?, ?, ?, ?, ?, 0, 'Odoo')
        ON DUPLICATE KEY UPDATE 
            odoo_product_id = VALUES(odoo_product_id),
            kode_barang = VALUES(kode_barang),
            nama_barang = VALUES(nama_barang),
            satuan = VALUES(satuan),
            uom = VALUES(uom),
            barcode = VALUES(barcode),
            kategori = 'Odoo'
    ");

    // Build locations from klinik: kode_klinik and kode_homecare
    $locations = [];
    $res = $conn->query("SELECT kode_klinik, kode_homecare FROM klinik WHERE status = 'active'");
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['kode_klinik'])) $locations[] = $row['kode_klinik'];
        if (!empty($row['kode_homecare'])) $locations[] = $row['kode_homecare'];
    }
    $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc !== '') $locations[] = $gudang_loc;
    $locations = array_values(array_unique($locations));

    // Pull stock per location and refresh snapshot in mirror
    $ins_stock = $conn->prepare("
        INSERT INTO stock_mirror (odoo_product_id, kode_barang, location_code, qty)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = VALUES(qty), kode_barang = VALUES(kode_barang)
    ");
    $updated_rows = 0;
    $products_count = 0;
    $skipped_locations = [];
    $errors = [];

    [$rpc_ok, $rpc_url, $rpc_db, $rpc_user, $rpc_pass] = get_rpc_config();
    if ($rpc_ok) {
        $uid = odoo_rpc_authenticate($rpc_url, $rpc_db, $rpc_user, $rpc_pass);
        if (!$uid) {
            echo json_encode(['success' => false, 'message' => 'Gagal login RPC (credential salah atau user tidak aktif)']);
            exit;
        }

        foreach ($locations as $loc) {
            $loc_id = odoo_find_location_id($rpc_url, $rpc_db, $uid, $rpc_pass, $loc);
            if (!$loc_id) {
                $skipped_locations[] = $loc;
                continue;
            }

            $domain = [['location_id', 'child_of', $loc_id], ['quantity', '!=', 0]];
            $groups = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'stock.quant', 'read_group', [$domain, ['product_id', 'quantity:sum'], ['product_id']], ['lazy' => false]);
            if (!is_array($groups)) $groups = [];

            $qty_by_pid = [];
            $product_ids = [];
            foreach ($groups as $g) {
                $pid = $g['product_id'][0] ?? null;
                if (!$pid) continue;
                $product_ids[] = (int)$pid;
                $qty_by_pid[(int)$pid] = (float)($g['quantity'] ?? 0);
            }
            $product_ids = array_values(array_unique($product_ids));

            $conn->begin_transaction();
            try {
                $loc_esc = $conn->real_escape_string($loc);
                $conn->query("DELETE FROM stock_mirror WHERE location_code = '$loc_esc'");

                if (!empty($product_ids)) {
                    $products = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'product.product', 'read', [$product_ids], ['fields' => ['id', 'default_code', 'name', 'barcode', 'uom_id']]);
                    if (!is_array($products)) $products = [];
                    foreach ($products as $p) {
                        $odoo_id = (string)($p['id'] ?? '');
                        if ($odoo_id === '') continue;
                        $code = (string)($p['default_code'] ?? '');
                        $name = (string)($p['name'] ?? '');
                        $barcode = (string)($p['barcode'] ?? '');
                        $uom = '';
                        if (isset($p['uom_id']) && is_array($p['uom_id']) && count($p['uom_id']) >= 2) {
                            $uom = (string)$p['uom_id'][1];
                        }
                        if ($code === '') $code = $barcode !== '' ? $barcode : ($name !== '' ? $name : $odoo_id);
                        $satuan = $uom !== '' ? $uom : 'Unit';

                        $ins_prod->bind_param("ssssss", $odoo_id, $code, $name, $satuan, $uom, $barcode);
                        $ins_prod->execute();
                        $products_count++;

                        $pid = (int)$p['id'];
                        $qty = (float)($qty_by_pid[$pid] ?? 0);
                        $ins_stock->bind_param("sssd", $odoo_id, $code, $loc, $qty);
                        $ins_stock->execute();
                        $updated_rows += $ins_stock->affected_rows;
                    }
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = $loc . ': ' . $e->getMessage();
            }
        }

        echo json_encode([
            'success' => true,
            'method' => 'rpc',
            'message' => 'Sync selesai',
            'products' => $products_count,
            'locations' => count($locations),
            'skipped_locations' => $skipped_locations,
            'rows' => $updated_rows,
            'errors' => $errors
        ]);
        $mirror_after = mirror_stats($conn);
        $dur = round(microtime(true) - $sync_started_at, 1);
        $rows_b = (int)($mirror_before['total_rows'] ?? 0);
        $rows_a = (int)($mirror_after['total_rows'] ?? 0);
        $qty_b = (float)($mirror_before['total_qty'] ?? 0);
        $qty_a = (float)($mirror_after['total_qty'] ?? 0);
        $dq = fmt_dec($qty_a - $qty_b);
        $dr = $rows_a - $rows_b;
        $dq_s = ($qty_a - $qty_b) >= 0 ? ('+' . $dq) : $dq;
        $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
        $lines = [];
        $lines[] = "[SYNC ODOO][RPC][" . strtoupper($sync_trigger) . "] Selesai " . date('d M Y H:i');
        $lines[] = "Durasi: {$dur}s";
        $lines[] = "Produk: $products_count";
        $lines[] = "Lokasi target: " . count($locations) . " (skip: " . count($skipped_locations) . ")";
        $lines[] = "Rows mirror: $rows_b → $rows_a ($dr_s)";
        $lines[] = "Total qty mirror: " . fmt_dec($qty_b) . " → " . fmt_dec($qty_a) . " ($dq_s)";
        $lines[] = "Last update mirror: " . fmt_ts($mirror_before['last_update'] ?? '') . " → " . fmt_ts($mirror_after['last_update'] ?? '');
        if (!empty($errors)) $lines[] = "Error lokasi: " . count($errors);
        $diff_lines = build_diff_lines($mirror_before, $mirror_after, 8);
        if (!empty($diff_lines)) {
            $lines[] = "Top perubahan lokasi:";
            $lines = array_merge($lines, $diff_lines);
        }
        post_lark_text(implode("\n", $lines));
        exit;
    }

    if (empty(ODOO_API_BASE_URL) || empty(ODOO_API_TOKEN)) {
        echo json_encode(['success' => false, 'message' => 'Konfigurasi RPC kosong dan Base URL/Token juga kosong']);
        exit;
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . ODOO_API_TOKEN
    ];

    $products_url = rtrim(ODOO_API_BASE_URL, '/') . ODOO_PRODUCTS_ENDPOINT;
    $products = http_get_json($products_url, $headers);
    if (!is_array($products)) $products = [];
    foreach ($products as $p) {
        $odoo_id = (string)($p['id'] ?? $p['odoo_id'] ?? '');
        if ($odoo_id === '') continue;
        $default_code = (string)($p['default_code'] ?? $p['code'] ?? $odoo_id);
        $name = (string)($p['name'] ?? 'Unknown');
        $uom = (string)($p['uom'] ?? $p['uom_name'] ?? '');
        $barcode = (string)($p['barcode'] ?? '');
        $satuan = $uom ?: 'Unit';

        $ins_prod->bind_param("ssssss", $odoo_id, $default_code, $name, $satuan, $uom, $barcode);
        $ins_prod->execute();
        $products_count++;
    }

    foreach ($locations as $loc) {
        $stock_url = rtrim(ODOO_API_BASE_URL, '/') . ODOO_STOCK_ENDPOINT . '?location_code=' . urlencode($loc);
        $stock_rows = http_get_json($stock_url, $headers);
        if (!is_array($stock_rows)) $stock_rows = [];

        $conn->begin_transaction();
        try {
            $loc_esc = $conn->real_escape_string($loc);
            $conn->query("DELETE FROM stock_mirror WHERE location_code = '$loc_esc'");

            foreach ($stock_rows as $s) {
                $odoo_id = (string)($s['odoo_product_id'] ?? $s['product_id'] ?? $s['id'] ?? '');
                $code = (string)($s['default_code'] ?? $s['product_code'] ?? '');
                $qty = (float)($s['qty'] ?? $s['quantity'] ?? 0);
                if ($odoo_id === '' && $code === '') continue;
                if ($code === '' && $odoo_id !== '') {
                    $r = $conn->query("SELECT kode_barang FROM barang WHERE odoo_product_id = '" . $conn->real_escape_string($odoo_id) . "' LIMIT 1");
                    if ($r && $r->num_rows > 0) {
                        $code = $r->fetch_assoc()['kode_barang'];
                    } else {
                        $code = $odoo_id;
                    }
                }
                $ins_stock->bind_param("sssd", $odoo_id, $code, $loc, $qty);
                $ins_stock->execute();
                $updated_rows += $ins_stock->affected_rows;
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    echo json_encode(['success' => true, 'method' => 'api', 'message' => 'Sync selesai', 'products' => $products_count, 'locations' => count($locations), 'rows' => $updated_rows]);
    $mirror_after = mirror_stats($conn);
    $dur = round(microtime(true) - $sync_started_at, 1);
    $rows_b = (int)($mirror_before['total_rows'] ?? 0);
    $rows_a = (int)($mirror_after['total_rows'] ?? 0);
    $qty_b = (float)($mirror_before['total_qty'] ?? 0);
    $qty_a = (float)($mirror_after['total_qty'] ?? 0);
    $dq = fmt_dec($qty_a - $qty_b);
    $dr = $rows_a - $rows_b;
    $dq_s = ($qty_a - $qty_b) >= 0 ? ('+' . $dq) : $dq;
    $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
    $lines = [];
    $lines[] = "[SYNC ODOO][API][" . strtoupper($sync_trigger) . "] Selesai " . date('d M Y H:i');
    $lines[] = "Durasi: {$dur}s";
    $lines[] = "Produk: $products_count";
    $lines[] = "Lokasi target: " . count($locations);
    $lines[] = "Rows mirror: $rows_b → $rows_a ($dr_s)";
    $lines[] = "Total qty mirror: " . fmt_dec($qty_b) . " → " . fmt_dec($qty_a) . " ($dq_s)";
    $lines[] = "Last update mirror: " . fmt_ts($mirror_before['last_update'] ?? '') . " → " . fmt_ts($mirror_after['last_update'] ?? '');
    $diff_lines = build_diff_lines($mirror_before, $mirror_after, 8);
    if (!empty($diff_lines)) {
        $lines[] = "Top perubahan lokasi:";
        $lines = array_merge($lines, $diff_lines);
    }
    post_lark_text(implode("\n", $lines));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    $dur = round(microtime(true) - $sync_started_at, 1);
    post_lark_text("[SYNC ODOO][" . strtoupper($sync_trigger) . "] Gagal " . date('d M Y H:i') . "\nDurasi: {$dur}s\n" . $e->getMessage());
}
?>
