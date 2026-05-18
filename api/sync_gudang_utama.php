<?php
/**
 * Sync Gudang Utama
 * Tarik stok gudang utama dari Odoo, update inventory_stock_mirror.
 * Akses: GET /api/sync_gudang_utama.php?token=YOUR_TOKEN
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/odoo.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/odoo_rpc_client.php';

header('Content-Type: application/json');

// ── Auth ─────────────────────────────────────────────────────────────────────
$provided = trim((string)($_GET['token'] ?? ''));
$stored   = trim((string)get_setting('gudang_sync_token', ''));

$token_ok = ($provided !== '' && $stored !== '' && hash_equals($stored, $provided));

if (!$token_ok) {
    // Also allow super_admin session as fallback
    $session_ok = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'super_admin';
    if (!$session_ok) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: token tidak valid']);
        exit;
    }
}

// ── Config ────────────────────────────────────────────────────────────────────
$rpc_url  = trim((string)get_setting('odoo_rpc_url', ''));
$rpc_db   = trim((string)get_setting('odoo_rpc_db', ''));
$rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
$rpc_pass = (string)get_setting('odoo_rpc_password', '');
$gudang   = trim((string)get_setting('odoo_location_gudang_utama', ''));

if ($rpc_url === '' || $rpc_db === '' || $rpc_user === '' || $rpc_pass === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Konfigurasi Odoo RPC belum lengkap']);
    exit;
}
if ($gudang === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Setting odoo_location_gudang_utama belum diisi']);
    exit;
}

// ── Sync ──────────────────────────────────────────────────────────────────────
$started = microtime(true);

try {
    $uid = odoo_rpc_authenticate($rpc_url, $rpc_db, $rpc_user, $rpc_pass);
    if (!$uid) {
        echo json_encode(['success' => false, 'message' => 'Login Odoo RPC gagal']);
        exit;
    }

    $loc_id = odoo_find_location_id($rpc_url, $rpc_db, $uid, $rpc_pass, $gudang);
    if (!$loc_id) {
        echo json_encode(['success' => false, 'message' => "Lokasi gudang '{$gudang}' tidak ditemukan di Odoo"]);
        exit;
    }

    $domain = [['location_id', 'child_of', $loc_id], ['quantity', '!=', 0]];
    $groups = odoo_rpc_execute_kw(
        $rpc_url, $rpc_db, $uid, $rpc_pass,
        'stock.quant', 'read_group',
        [$domain, ['product_id', 'quantity:sum'], ['product_id']],
        ['lazy' => false]
    );
    if (!is_array($groups)) $groups = [];

    $qty_by_pid  = [];
    $product_ids = [];
    foreach ($groups as $g) {
        $pid = $g['product_id'][0] ?? null;
        if (!$pid) continue;
        $product_ids[]       = (int)$pid;
        $qty_by_pid[(int)$pid] = (float)($g['quantity'] ?? 0);
    }
    $product_ids = array_values(array_unique($product_ids));

    $ins_prod = $conn->prepare("
        INSERT INTO inventory_barang (odoo_product_id, kode_barang, nama_barang, satuan, uom, barcode, stok_minimum, kategori)
        VALUES (?, ?, ?, ?, ?, ?, 0, 'Odoo')
        ON DUPLICATE KEY UPDATE
            odoo_product_id = VALUES(odoo_product_id),
            kode_barang     = VALUES(kode_barang),
            nama_barang     = VALUES(nama_barang),
            satuan          = VALUES(satuan),
            uom             = VALUES(uom),
            barcode         = VALUES(barcode),
            kategori        = 'Odoo'
    ");

    $ins_stock = $conn->prepare("
        INSERT INTO inventory_stock_mirror (odoo_product_id, kode_barang, location_code, qty)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = VALUES(qty), kode_barang = VALUES(kode_barang)
    ");

    // Snapshot before: kode_barang => qty
    $before = [];
    $gudang_esc = $conn->real_escape_string($gudang);
    $snap = $conn->query("
        SELECT sm.kode_barang, COALESCE(b.nama_barang, sm.kode_barang) AS nama_barang, sm.qty
        FROM inventory_stock_mirror sm
        LEFT JOIN inventory_barang b ON b.kode_barang = sm.kode_barang
        WHERE sm.location_code = '$gudang_esc'
    ");
    while ($snap && ($sr = $snap->fetch_assoc())) {
        $before[$sr['kode_barang']] = ['qty' => (float)$sr['qty'], 'nama' => $sr['nama_barang']];
    }

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM inventory_stock_mirror WHERE location_code = '$gudang_esc'");

        $updated_rows = 0;
        $after = [];
        if (!empty($product_ids)) {
            $products = odoo_rpc_execute_kw(
                $rpc_url, $rpc_db, $uid, $rpc_pass,
                'product.product', 'read',
                [$product_ids],
                ['fields' => ['id', 'default_code', 'name', 'barcode', 'uom_id']]
            );
            if (!is_array($products)) $products = [];

            foreach ($products as $p) {
                $odoo_id = (string)($p['id'] ?? '');
                if ($odoo_id === '') continue;
                $code    = (string)($p['default_code'] ?? '');
                $name    = (string)($p['name'] ?? '');
                $barcode = (string)($p['barcode'] ?? '');
                $uom     = '';
                if (isset($p['uom_id']) && is_array($p['uom_id']) && count($p['uom_id']) >= 2) {
                    $uom = (string)$p['uom_id'][1];
                }
                if ($code === '') $code = $barcode !== '' ? $barcode : ($name !== '' ? $name : $odoo_id);
                $satuan = $uom !== '' ? $uom : 'Unit';

                $ins_prod->bind_param('ssssss', $odoo_id, $code, $name, $satuan, $uom, $barcode);
                $ins_prod->execute();

                $pid = (int)$p['id'];
                $qty = (float)($qty_by_pid[$pid] ?? 0);
                $ins_stock->bind_param('sssd', $odoo_id, $code, $gudang, $qty);
                $ins_stock->execute();
                $after[$code] = ['qty' => $qty, 'nama' => $name];
                $updated_rows++;
            }
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    // Diff: new, removed, qty changed
    $diff_added   = [];
    $diff_removed = [];
    $diff_changed = [];

    foreach ($after as $code => $info) {
        if (!isset($before[$code])) {
            $diff_added[] = ['kode' => $code, 'nama' => $info['nama'], 'qty' => $info['qty']];
        } elseif (abs($info['qty'] - $before[$code]['qty']) >= 0.0001) {
            $diff_changed[] = [
                'kode'    => $code,
                'nama'    => $info['nama'],
                'qty_lama'=> $before[$code]['qty'],
                'qty_baru'=> $info['qty'],
                'delta'   => $info['qty'] - $before[$code]['qty'],
            ];
        }
    }
    foreach ($before as $code => $info) {
        if (!isset($after[$code])) {
            $diff_removed[] = ['kode' => $code, 'nama' => $info['nama'], 'qty_lama' => $info['qty']];
        }
    }

    // Sort changed by abs delta desc
    usort($diff_changed, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

    set_setting('gudang_sync_last_run', (string)time());

    $is_first_sync = empty($before);
    $elapsed = round(microtime(true) - $started, 2);
    echo json_encode([
        'success'       => true,
        'message'       => 'Sync gudang utama selesai',
        'lokasi'        => $gudang,
        'produk_count'  => $updated_rows,
        'elapsed_s'     => $elapsed,
        'synced_at'     => date('d M Y H:i:s'),
        'first_sync'    => $is_first_sync,
        'diff' => [
            'added'   => $diff_added,
            'removed' => $diff_removed,
            'changed' => $diff_changed,
        ],
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
