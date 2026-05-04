<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');
require_csrf();

$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'super_admin') {
    $_SESSION['error'] = 'Anda tidak memiliki akses.';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=stok_petugas_hc');

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan = trim((string)($_POST['catatan'] ?? ''));

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

$barang_ids_raw = $_POST['barang_id'] ?? [];
$qtys_raw = $_POST['qty'] ?? [];
$uom_modes_raw = $_POST['uom_mode'] ?? [];
if (!is_array($barang_ids_raw)) $barang_ids_raw = [$barang_ids_raw];
if (!is_array($qtys_raw)) $qtys_raw = [$qtys_raw];
if (!is_array($uom_modes_raw)) $uom_modes_raw = [$uom_modes_raw];

$items = [];
$max = max(count($barang_ids_raw), count($qtys_raw), count($uom_modes_raw));
for ($i = 0; $i < $max; $i++) {
    $bid = (int)($barang_ids_raw[$i] ?? 0);
    $q = (float)($qtys_raw[$i] ?? 0);
    $mode = trim((string)($uom_modes_raw[$i] ?? 'oper'));
    if ($bid <= 0 || $q <= 0) continue;
    $conv = $conn->query("
        SELECT COALESCE(c.multiplier, 1) AS multiplier 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $bid 
        LIMIT 1
    ")->fetch_assoc();
    $ratio = (float)($conv['multiplier'] ?? 1);
    if ($ratio <= 0) $ratio = 1;
    $qty_oper = ($mode === 'odoo') ? ($q / $ratio) : $q;
    $qty_oper = (float)round($qty_oper, 4);
    if ($qty_oper <= 0) continue;
    if (!isset($items[$bid])) $items[$bid] = 0;
    $items[$bid] += $qty_oper;
}

if ($klinik_id <= 0 || $user_hc_id <= 0 || empty($items)) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Data allocasi tidak valid.']);
        exit;
    }
    $_SESSION['error'] = 'Data allocasi tidak valid.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$kl = $conn->query("SELECT id, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl || trim((string)($kl['kode_homecare'] ?? '')) === '') {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_homecare.']);
        exit;
    }
    $_SESSION['error'] = 'Klinik belum memiliki kode_homecare.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$u = $conn->query("SELECT id, klinik_id FROM inventory_users WHERE id = $user_hc_id AND role = 'petugas_hc' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$u || (int)($u['klinik_id'] ?? 0) !== $klinik_id) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Petugas HC tidak valid untuk klinik ini.']);
        exit;
    }
    $_SESSION['error'] = 'Petugas HC tidak valid untuk klinik ini.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$kode_homecare = $conn->real_escape_string(trim((string)$kl['kode_homecare']));

$conn->begin_transaction();
try {
    $count = 0;
    foreach ($items as $barang_id => $qty_int) {
        $barang_id = (int)$barang_id;
        $qty_oper = (float)$qty_int;
        if ($barang_id <= 0 || $qty_oper <= 0) continue;

        $b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
        if (!$b) throw new Exception('Barang tidak ditemukan.');

        $conv = $conn->query("
            SELECT c.multiplier 
            FROM inventory_barang_uom_conversion c
            JOIN inventory_barang b ON b.kode_barang = c.kode_barang
            WHERE b.id = $barang_id 
            LIMIT 1
        ")->fetch_assoc();
        $mult = (float)($conv['multiplier'] ?? 1);
        if ($mult <= 0) $mult = 1;

        $kode_barang = trim((string)($b['kode_barang'] ?? ''));
        $odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));

        $clauses = [];
        if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kode_barang) . "'";
        if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($odoo_product_id) . "'";
        if (empty($clauses)) $clauses[] = "1=0";
        $match = '(' . implode(' OR ', $clauses) . ')';

        $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '$kode_homecare' AND $match");
        $mirror_qty = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
        if ($mult <= 0) $mult = 1;
        $mirror_converted = (float)($mirror_qty / $mult);

        $r = $conn->query("SELECT COALESCE(SUM(qty), 0) AS total FROM inventory_stok_tas_hc WHERE klinik_id = $klinik_id AND barang_id = $barang_id");
        $allocated_item = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['total'] ?? 0) : 0);
        $unallocated = $mirror_converted - $allocated_item;
        if ($unallocated < 0) $unallocated = 0;

        if ($qty_oper > $unallocated + 0.00005) {
            $label = trim((string)($b['nama_barang'] ?? 'Barang'));
            if ($kode_barang !== '') $label = $kode_barang . ' - ' . $label;
            throw new Exception('Unallocated tidak cukup untuk item: ' . $label . '. Unallocated: ' . fmt_qty($unallocated) . ', Requested: ' . fmt_qty($qty_oper));
        }

        $stmt = $conn->prepare("INSERT INTO inventory_hc_tas_allocation (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $barang_id, $qty_oper, $catatan, $created_by);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_by = VALUES(updated_by)");
        $stmt->bind_param("iiidi", $barang_id, $user_hc_id, $klinik_id, $qty_oper, $created_by);
        $stmt->execute();
        $count++;
    }

    $conn->commit();
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Allocasi berhasil disimpan (' . $count . ' item).', 'redirect' => 'index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id]);
        exit;
    }
    $_SESSION['success'] = 'Allocasi berhasil disimpan (' . $count . ' item).';
} catch (Exception $e) {
    $conn->rollback();
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan allocasi: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['error'] = 'Gagal menyimpan allocasi: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id);


