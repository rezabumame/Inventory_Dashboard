<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['cs', 'super_admin', 'admin_klinik', 'admin_gudang', 'spv_klinik'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

require_csrf();

$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$status_booking = trim((string)($_POST['status_booking'] ?? ''));
$exam_ids = $_POST['exam_ids'] ?? [];

if ($klinik_id <= 0) {
    echo json_encode(['success' => true, 'is_out_of_stock' => 0, 'items' => []]);
    exit;
}

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!is_array($exam_ids)) $exam_ids = [];
$exam_ids = array_values(array_unique(array_filter(array_map('trim', $exam_ids), fn($v) => $v !== '')));
if (empty($exam_ids)) {
    echo json_encode(['success' => true, 'is_out_of_stock' => 0, 'items' => []]);
    exit;
}

$is_hc = (stripos($status_booking, 'HC') !== false);
if (stripos($status_booking, 'Clinic') !== false) $is_hc = false;

$total_needed = [];
foreach ($exam_ids as $pid) {
    // Only check CORE items from Database Barang
    $res = $conn->query("
        SELECT d.barang_id, d.qty_per_pemeriksaan 
        FROM inventory_pemeriksaan_grup_detail d
        JOIN inventory_barang b ON d.barang_id = b.id
        WHERE d.pemeriksaan_grup_id = '" . $conn->real_escape_string($pid) . "' 
        AND b.tipe = 'Core'
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $bid = (int)($row['barang_id'] ?? 0);
        $qty = (float)($row['qty_per_pemeriksaan'] ?? 0);
        if ($bid <= 0 || $qty <= 0) continue;
        $total_needed[$bid] = ($total_needed[$bid] ?? 0) + $qty;
    }
}

$out_of_stock_items = [];
foreach ($total_needed as $bid => $qty_need) {
    $ef = stock_effective($conn, (int)$klinik_id, (bool)$is_hc, (int)$bid);
    if (!$ef['ok']) continue;
    $available = (float)($ef['available'] ?? 0);
    if ($available < (float)$qty_need) {
        $bname = (string)($ef['barang_name'] ?? ("ID:$bid"));
        $out_of_stock_items[] = "$bname (Sisa: $available, Butuh: $qty_need)";
    }
}

echo json_encode([
    'success' => true,
    'is_out_of_stock' => !empty($out_of_stock_items) ? 1 : 0,
    'items' => $out_of_stock_items
], JSON_UNESCAPED_UNICODE);

