<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$created_by = (int)($_SESSION['user_id'] ?? 0);
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$barang_id = (int)($_POST['barang_id'] ?? 0);
$odoo_product_id = trim((string)($_POST['odoo_product_id'] ?? ''));
$alloc_raw = (string)($_POST['allocations'] ?? '');
$uom_mode = strtolower(trim((string)($_POST['uom_mode'] ?? 'oper')));
$uom_mode = ($uom_mode === 'odoo' || $uom_mode === 'base') ? 'odoo' : 'oper';
$catatan = trim((string)($_POST['catatan'] ?? 'Distribusi Unallocated HC'));

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($klinik_id <= 0 || $barang_id <= 0 || $odoo_product_id === '') {
    echo json_encode(['success' => false, 'message' => 'Param tidak valid']);
    exit;
}

$alloc = json_decode($alloc_raw, true);
if (!is_array($alloc)) {
    echo json_encode(['success' => false, 'message' => 'Allocations tidak valid']);
    exit;
}

$items = [];
$total_req = 0;
foreach ($alloc as $uid => $qty) {
    $uid_i = (int)$uid;
    $qty_i = (float)$qty;
    if ($uid_i <= 0 || $qty_i <= 0) continue;
    if (!isset($items[$uid_i])) $items[$uid_i] = 0;
    $items[$uid_i] += $qty_i;
    $total_req += $qty_i;
}
if ($total_req <= 0) {
    echo json_encode(['success' => false, 'message' => 'Qty distribusi belum diisi']);
    exit;
}

$kl = $conn->query("SELECT id, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
if ($kode_homecare === '') {
    echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_homecare']);
    exit;
}

$odoo_esc = $conn->real_escape_string($odoo_product_id);
$loc_esc = $conn->real_escape_string($kode_homecare);
$r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE odoo_product_id = '$odoo_esc' AND TRIM(location_code) = '$loc_esc' LIMIT 1");
$mirror_qty = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);

$conv = $conn->query("SELECT COALESCE(multiplier, 1) AS multiplier FROM barang_uom_conversion WHERE barang_id = $barang_id LIMIT 1")->fetch_assoc();
$ratio = (float)($conv['multiplier'] ?? 1);
if ($ratio <= 0) $ratio = 1.0;
$mirror_oper = $mirror_qty / $ratio;

$r = $conn->query("SELECT COALESCE(SUM(qty), 0) AS total FROM stok_tas_hc WHERE klinik_id = $klinik_id AND barang_id = $barang_id");
$allocated_qty = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['total'] ?? 0) : 0);
$unallocated = $mirror_oper - $allocated_qty;
if ($unallocated < 0) $unallocated = 0;
$unalloc_avail = (float)round($unallocated, 4);

$total_req_oper = ($uom_mode === 'odoo') ? ($total_req / $ratio) : $total_req;
$total_req_oper = (float)round($total_req_oper, 4);

if ($total_req_oper > $unalloc_avail + 0.00005) {
    echo json_encode(['success' => false, 'message' => 'Unallocated tidak cukup. Unallocated: ' . $unalloc_avail . ', Request: ' . $total_req_oper]);
    exit;
}

$ids = implode(',', array_map('intval', array_keys($items)));
$ru = $conn->query("SELECT id FROM users WHERE role='petugas_hc' AND status='active' AND klinik_id=$klinik_id AND id IN ($ids)");
$valid = [];
while ($ru && ($row = $ru->fetch_assoc())) $valid[(int)$row['id']] = true;
foreach ($items as $uid => $qty) {
    if (!isset($valid[(int)$uid])) {
        echo json_encode(['success' => false, 'message' => 'Petugas tidak valid untuk klinik ini (ID ' . (int)$uid . ')']);
        exit;
    }
}

$conn->begin_transaction();
try {
    foreach ($items as $uid => $qty) {
        $uid = (int)$uid;
        $qty = (float)$qty;
        if ($qty <= 0) continue;

        $qty_oper = ($uom_mode === 'odoo') ? ($qty / $ratio) : $qty;
        $qty_oper = (float)round($qty_oper, 4);
        if ($qty_oper <= 0) continue;

        $stmt = $conn->prepare("INSERT INTO hc_tas_allocation (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsi", $klinik_id, $uid, $barang_id, $qty_oper, $catatan, $created_by);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE klinik_id=VALUES(klinik_id), qty = qty + VALUES(qty), updated_by = VALUES(updated_by), updated_at = NOW()");
        $stmt->bind_param("iiidi", $barang_id, $uid, $klinik_id, $qty_oper, $created_by);
        $stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

