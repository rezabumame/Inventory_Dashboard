<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/odoo.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/lark.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

// Allow internal scheduler token
$internalToken = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
$dbToken = trim((string)get_setting('odoo_sync_token', ''));
$okInternal = false;
if ($internalToken !== '') {
    if (ODOO_SYNC_SYSTEM_TOKEN !== '' && hash_equals(ODOO_SYNC_SYSTEM_TOKEN, $internalToken)) $okInternal = true;
    if (!$okInternal && $dbToken !== '' && hash_equals($dbToken, $internalToken)) $okInternal = true;
}
$sync_trigger = $okInternal ? 'auto' : 'manual';
$sync_started_at = microtime(true);
if (!$okInternal) {
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
    require_csrf();
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
        throw new Exception("HTTP error: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
    return trim((string)get_setting('webhook_lark_url', ''));
}

function post_lark_text($text) {
    $url = lark_webhook_url();
    if ($url === '') return;
    lark_post_text($url, $text);
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
        FROM inventory_stock_mirror
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

function loc_candidates(string $code): array {
    $code = trim($code);
    if ($code === '') return [];
    $cand = [$code];
    if (!preg_match('/\/Stock$/i', $code)) $cand[] = $code . '/Stock';
    if (!preg_match('/-Stock$/i', $code)) $cand[] = $code . '-Stock';
    if (!preg_match('/ Stock$/i', $code)) $cand[] = $code . ' Stock';
    if (preg_match('/\/Stock$/i', $code)) $cand[] = preg_replace('/\/Stock$/i', '', $code);
    $seen = [];
    $out = [];
    foreach ($cand as $c) {
        $k = strtolower($c);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $c;
    }
    return $out;
}

function build_loc_group_map(mysqli $conn): array {
    $map = [];
    $res = $conn->query("SELECT nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE status = 'active'");
    while ($res && ($row = $res->fetch_assoc())) {
        $nm = trim((string)($row['nama_klinik'] ?? ''));
        if ($nm === '') $nm = 'Klinik';
        $kk = trim((string)($row['kode_klinik'] ?? ''));
        $kh = trim((string)($row['kode_homecare'] ?? ''));
        if ($kk !== '') {
            foreach (loc_candidates($kk) as $c) $map[$c] = $nm;
        }
        if ($kh !== '') {
            foreach (loc_candidates($kh) as $c) $map[$c] = $nm . " (HC)";
        }
    }
    $gudang = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang !== '') {
        foreach (loc_candidates($gudang) as $c) $map[$c] = 'Gudang Utama';
    }
    return $map;
}

function fmt_dec($n, $digits = 4): string {
    return number_format((float)$n, $digits, '.', '');
}

function fmt_id_qty($n): string {
    $n = (float)$n;
    if (abs($n - round($n)) < 0.00005) return number_format($n, 0, ',', '.');
    return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
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

function build_diff_lines_compact(array $before, array $after, int $limit = 8, float $threshold = 0.0001): array {
    $before_loc = $before['by_loc'] ?? [];
    $after_loc = $after['by_loc'] ?? [];
    $locs = array_values(array_unique(array_merge(array_keys($before_loc), array_keys($after_loc))));
    $deltas = [];
    foreach ($locs as $loc) {
        $b = $before_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $a = $after_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $dq = (float)$a['qty'] - (float)$b['qty'];
        $dr = (int)$a['rows'] - (int)$b['rows'];
        if (abs($dq) < $threshold && $dr === 0) continue;
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
        $bq = fmt_id_qty($d['b']['qty'] ?? 0);
        $aq = fmt_id_qty($d['a']['qty'] ?? 0);
        $dq = fmt_id_qty($d['dq'] ?? 0);
        $br = (int)($d['b']['rows'] ?? 0);
        $ar = (int)($d['a']['rows'] ?? 0);
        $dr = (int)($d['dr'] ?? 0);
        $dq_s = ($d['dq'] ?? 0) >= 0 ? ('+' . $dq) : $dq;
        $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
        $lines[] = "- {$d['loc']}: qty {$bq} → {$aq} ({$dq_s}), rows {$br} → {$ar} ({$dr_s})";
    }
    return $lines;
}

function build_diff_grouped_lines_compact(array $before, array $after, array $loc_group_map, int $limit = 8, float $threshold = 0.0001): array {
    $before_loc = $before['by_loc'] ?? [];
    $after_loc = $after['by_loc'] ?? [];
    $locs = array_values(array_unique(array_merge(array_keys($before_loc), array_keys($after_loc))));
    $deltas = [];
    foreach ($locs as $loc) {
        $b = $before_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $a = $after_loc[$loc] ?? ['rows' => 0, 'qty' => 0];
        $dq = (float)$a['qty'] - (float)$b['qty'];
        $dr = (int)$a['rows'] - (int)$b['rows'];
        if (abs($dq) < $threshold && $dr === 0) continue;
        $deltas[] = ['loc' => $loc, 'dq' => $dq, 'dr' => $dr, 'b' => $b, 'a' => $a];
    }
    usort($deltas, function ($x, $y) {
        $ax = abs((float)$x['dq']);
        $ay = abs((float)$y['dq']);
        if ($ax === $ay) return abs((int)$y['dr']) <=> abs((int)$x['dr']);
        return $ay <=> $ax;
    });

    $take = array_slice($deltas, 0, max(0, $limit));
    $groups = [];
    $order = [];
    foreach ($take as $d) {
        $loc = (string)($d['loc'] ?? '');
        $g = (string)($loc_group_map[$loc] ?? 'Lainnya');
        if (!isset($groups[$g])) {
            $groups[$g] = [];
            $order[] = $g;
        }
        $bq = fmt_id_qty($d['b']['qty'] ?? 0);
        $aq = fmt_id_qty($d['a']['qty'] ?? 0);
        $dq = fmt_id_qty($d['dq'] ?? 0);
        $br = (int)($d['b']['rows'] ?? 0);
        $ar = (int)($d['a']['rows'] ?? 0);
        $dr = (int)($d['dr'] ?? 0);
        $dq_s = ($d['dq'] ?? 0) >= 0 ? ('+' . $dq) : $dq;
        $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
        $groups[$g][] = "- {$loc}: qty {$bq} → {$aq} ({$dq_s}), rows {$br} → {$ar} ({$dr_s})";
    }

    $lines = [];
    $bold = [];
    foreach ($order as $g) {
        if (!empty($lines)) $lines[] = "";
        $bold[] = count($lines);
        $lines[] = $g;
        foreach ($groups[$g] as $ln) $lines[] = $ln;
    }
    return ['lines' => $lines, 'bold' => $bold];
}

function build_group_summary_lines(array $stats_after, array $loc_group_map, bool $hc, int $limit = 12): array {
    $by_loc = $stats_after['by_loc'] ?? [];
    $groups = [];
    foreach ($by_loc as $loc => $info) {
        $g = (string)($loc_group_map[$loc] ?? '');
        if ($g === '') $g = 'Lainnya';
        $is_hc = (stripos($g, '(HC)') !== false);
        if ($hc !== $is_hc) continue;
        if (!isset($groups[$g])) $groups[$g] = ['rows' => 0, 'qty' => 0.0, 'last' => ''];
        $groups[$g]['rows'] += (int)($info['rows'] ?? 0);
        $groups[$g]['qty'] += (float)($info['qty'] ?? 0);
        $lu = (string)($info['last_update'] ?? '');
        if ($lu !== '' && ($groups[$g]['last'] === '' || strtotime($lu) > strtotime($groups[$g]['last']))) {
            $groups[$g]['last'] = $lu;
        }
    }
    uasort($groups, function($a, $b) {
        $qa = abs((float)($a['qty'] ?? 0));
        $qb = abs((float)($b['qty'] ?? 0));
        if ($qa === $qb) return (int)($b['rows'] ?? 0) <=> (int)($a['rows'] ?? 0);
        return $qb <=> $qa;
    });
    $lines = [];
    $count = 0;
    foreach ($groups as $name => $agg) {
        $lines[] = "- {$name}: rows " . (int)$agg['rows'] . ", qty " . fmt_id_qty($agg['qty'] ?? 0) . ", last " . fmt_ts($agg['last'] ?? '');
        $count++;
        if ($count >= $limit) break;
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
    $mirror_before = mirror_stats($conn);

    // Upsert products into inventory_barang mirror
    $ins_prod = $conn->prepare("
        INSERT INTO inventory_barang (odoo_product_id, kode_barang, nama_barang, satuan, uom, barcode, stok_minimum, kategori)
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

    // Build locations from inventory_klinik: kode_klinik and kode_homecare
    $locations = [];
    $res = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE status = 'active'");
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['kode_klinik'])) $locations[] = $row['kode_klinik'];
        if (!empty($row['kode_homecare'])) $locations[] = $row['kode_homecare'];
    }
    $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc !== '') $locations[] = $gudang_loc;
    $locations = array_values(array_unique($locations));

    // Pull stock per location and refresh snapshot in mirror
    $ins_stock = $conn->prepare("
        INSERT INTO inventory_stock_mirror (odoo_product_id, kode_barang, location_code, qty)
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

        // 1. Pull ALL active products to populate Database Barang (especially from main warehouse categories)
        // This ensures the local 'barang' table is complete even for items with zero stock.
        $all_products = odoo_rpc_execute_kw($rpc_url, $rpc_db, $uid, $rpc_pass, 'product.product', 'search_read', 
            [[['active', '=', true], ['type', 'in', ['product', 'consu']]]], 
            ['fields' => ['id', 'default_code', 'name', 'barcode', 'uom_id']]
        );
        
        if (is_array($all_products)) {
            $conn->begin_transaction();
            foreach ($all_products as $p) {
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
            }
            $conn->commit();
        }

        // 2. Pull stock per location and refresh snapshot in mirror
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
                $conn->query("DELETE FROM inventory_stock_mirror WHERE location_code = '$loc_esc'");

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

        set_setting('odoo_sync_last_run', (string) time());
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
        $dq = fmt_id_qty($qty_a - $qty_b);
        $dr = $rows_a - $rows_b;
        $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
        $lines = [];
        $loc_group_map = build_loc_group_map($conn);
        $diff_pack = build_diff_grouped_lines_compact($mirror_before, $mirror_after, $loc_group_map, 12, 0.0001);
        $diff_lines = $diff_pack['lines'] ?? [];
        $sum_klinik = build_group_summary_lines($mirror_after, $loc_group_map, false, 12);
        $sum_hc = build_group_summary_lines($mirror_after, $loc_group_map, true, 12);

        $title = "🔄 Odoo Stock Sync Success";
        $card_lines = [
            "**Event:** Odoo Stock Sync (" . strtoupper($sync_trigger) . ")",
            "**Waktu:** " . date('d M Y H:i') . " | **Durasi:** {$dur}s",
            "**Statistik:** {$products_count} Produk | " . count($locations) . " Lokasi",
            "**Mirror:** {$rows_a} baris ({$dr_s}) | Qty: " . fmt_id_qty($qty_a) . " (" . (($qty_a - $qty_b) >= 0 ? '+' : '') . $dq . ")"
        ];
        if (!empty($errors)) $card_lines[] = "";
        if (!empty($errors)) $card_lines[] = "⚠️ **Error Lokasi:** " . count($errors);

        if (!empty($diff_lines)) {
            $card_lines[] = "";
            $card_lines[] = "📊 **Top Perubahan Lokasi:**";
            foreach ($diff_lines as $dl) {
                if ($dl === '') continue;
                if (substr(trim($dl), 0, 1) !== '-') {
                    $card_lines[] = "**" . trim($dl) . "**";
                } else {
                    $card_lines[] = trim($dl);
                }
            }
        }

        if (!empty($sum_klinik)) {
            $card_lines[] = "";
            $card_lines[] = "🏥 **Ringkasan per Klinik:**";
            foreach ($sum_klinik as $sk) $card_lines[] = "• " . ltrim($sk, '- ');
        }

        if (!empty($sum_hc)) {
            $card_lines[] = "";
            $card_lines[] = "🏠 **Ringkasan Homecare:**";
            foreach ($sum_hc as $sh) $card_lines[] = "• " . ltrim($sh, '- ');
        }

        lark_post_card(lark_webhook_url(), $title, $card_lines, "blue");
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
            $conn->query("DELETE FROM inventory_stock_mirror WHERE location_code = '$loc_esc'");

            foreach ($stock_rows as $s) {
                $odoo_id = (string)($s['odoo_product_id'] ?? $s['product_id'] ?? $s['id'] ?? '');
                $code = (string)($s['default_code'] ?? $s['product_code'] ?? '');
                $qty = (float)($s['qty'] ?? $s['quantity'] ?? 0);
                if ($odoo_id === '' && $code === '') continue;
                if ($code === '' && $odoo_id !== '') {
                    $r = $conn->query("SELECT kode_barang FROM inventory_barang WHERE odoo_product_id = '" . $conn->real_escape_string($odoo_id) . "' LIMIT 1");
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

    set_setting('odoo_sync_last_run', (string) time());
    echo json_encode(['success' => true, 'method' => 'api', 'message' => 'Sync selesai', 'products' => $products_count, 'locations' => count($locations), 'rows' => $updated_rows]);
    $mirror_after = mirror_stats($conn);
    $dur = round(microtime(true) - $sync_started_at, 1);
    $rows_b = (int)($mirror_before['total_rows'] ?? 0);
    $rows_a = (int)($mirror_after['total_rows'] ?? 0);
    $qty_b = (float)($mirror_before['total_qty'] ?? 0);
    $qty_a = (float)($mirror_after['total_qty'] ?? 0);
    $dq = fmt_id_qty($qty_a - $qty_b);
    $dr = $rows_a - $rows_b;
    $dr_s = $dr >= 0 ? ('+' . $dr) : (string)$dr;
    $title = "🔄 Odoo Stock Sync Success";
    $card_lines = [
        "**Event:** Odoo Stock Sync (" . strtoupper($sync_trigger) . ")",
        "**Waktu:** " . date('d M Y H:i') . " | **Durasi:** {$dur}s",
        "**Statistik:** {$products_count} Produk | " . count($locations) . " Lokasi",
        "**Mirror:** {$rows_a} baris ({$dr_s}) | Qty: " . fmt_id_qty($qty_a) . " (" . (($qty_a - $qty_b) >= 0 ? '+' : '') . $dq . ")"
    ];

    if (!empty($diff_lines)) {
        $card_lines[] = "";
        $card_lines[] = "📊 **Top Perubahan Lokasi:**";
        foreach ($diff_lines as $idx => $dl) {
            if ($dl === '') continue;
            // If line doesn't start with '-', it's a group header
            if (substr(trim($dl), 0, 1) !== '-') {
                $card_lines[] = "**" . trim($dl) . "**";
            } else {
                $card_lines[] = trim($dl);
            }
        }
    }

    if (!empty($sum_klinik)) {
        $card_lines[] = "";
        $card_lines[] = "🏥 **Ringkasan per Klinik:**";
        foreach ($sum_klinik as $sk) $card_lines[] = "• " . ltrim($sk, '- ');
    }

    if (!empty($sum_hc)) {
        $card_lines[] = "";
        $card_lines[] = "🏠 **Ringkasan Homecare:**";
        foreach ($sum_hc as $sh) $card_lines[] = "• " . ltrim($sh, '- ');
    }

    lark_post_card(lark_webhook_url(), $title, $card_lines, "blue");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    $dur = round(microtime(true) - $sync_started_at, 1);
    lark_post_card(lark_webhook_url(), "❌ Odoo Stock Sync Failed", [
        "**Trigger:** " . strtoupper($sync_trigger),
        "**Waktu:** " . date('d M Y H:i'),
        "**Durasi:** {$dur}s",
        "**Error:** " . $e->getMessage()
    ], "red");
}
?>
