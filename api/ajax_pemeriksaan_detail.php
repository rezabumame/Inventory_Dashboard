<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$role = trim((string)($_SESSION['role'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik', 'cs'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access: ' . $role]);
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
        d.is_lokal,
        d.qty_per_pemeriksaan,
        d.id_biosys,
        d.nama_layanan,
        COALESCE(b.kode_barang, '-') AS kode_barang,
        b.odoo_product_id,
        COALESCE(b.nama_barang, bl.nama_item) AS nama_barang,
        COALESCE(NULLIF(uc.to_uom, ''), b.satuan, bl.uom) AS satuan,
        COALESCE(b.tipe, 'Support') AS tipe
    FROM inventory_pemeriksaan_grup_detail d
    LEFT JOIN inventory_barang b ON d.barang_id = b.id AND d.is_lokal = 0
    LEFT JOIN inventory_barang_lokal bl ON d.barang_id = bl.id AND d.is_lokal = 1
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE d.pemeriksaan_grup_id = ?
    ORDER BY d.nama_layanan ASC, COALESCE(b.nama_barang, bl.nama_item) ASC
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

