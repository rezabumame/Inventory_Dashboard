<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_klinik', 'cs'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$grup_id = trim((string)($_POST['grup_id'] ?? ''));
if ($grup_id === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid grup_id']);
    exit;
}

$stmt = $conn->prepare("SELECT id, nama_pemeriksaan FROM inventory_pemeriksaan_grup WHERE id = ?");
$stmt->bind_param("s", $grup_id);
$stmt->execute();
$grup = $stmt->get_result()->fetch_assoc();
if (!$grup) {
    echo json_encode(['success' => false, 'message' => 'Pemeriksaan tidak ditemukan']);
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        d.id,
        d.barang_id,
        d.qty_per_pemeriksaan,
        d.id_biosys,
        d.nama_layanan,
        b.kode_barang,
        b.odoo_product_id,
        b.nama_barang,
        COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS satuan,
        b.tipe
    FROM inventory_pemeriksaan_grup_detail d
    JOIN inventory_barang b ON d.barang_id = b.id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE d.pemeriksaan_grup_id = ?
    ORDER BY d.nama_layanan ASC, b.nama_barang ASC
");
$stmt->bind_param("s", $grup_id);
$stmt->execute();
$res = $stmt->get_result();
$details = [];
while ($row = $res->fetch_assoc()) {
    // Fallback logic for Type: if empty/null, assume Support
    if (empty($row['tipe'])) {
        $row['tipe'] = 'Support';
    }
    $details[] = $row;
}

$cnt_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
$cnt_stmt->bind_param("s", $grup_id);
$cnt_stmt->execute();
$cnt = (int)($cnt_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

echo json_encode([
    'success' => true,
    'grup' => $grup,
    'details' => $details,
    'total_items' => $cnt
], JSON_UNESCAPED_UNICODE);

