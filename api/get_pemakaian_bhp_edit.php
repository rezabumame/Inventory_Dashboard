<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check role: admin_hc cannot access BHP usage
if (in_array($_SESSION['role'] ?? '', ['admin_hc'])) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

// Get header
$stmt = $conn->prepare("
    SELECT pb.*, k.nama_klinik
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    WHERE pb.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();

if (!$header) {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    exit;
}

// Permission check: 
// 1. Super Admin: Always
// 2. Admin Klinik: Always (for their own clinic)
// 3. Others (HC): Only creator on the same day
$is_today = date('Y-m-d', strtotime($header['created_at'])) === date('Y-m-d');
$is_creator = $header['created_by'] == $_SESSION['user_id'];
$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin_klinik = $_SESSION['role'] === 'admin_klinik';
$user_klinik_id = $_SESSION['klinik_id'] ?? null;

$has_access = false;
if ($is_super_admin) {
    $has_access = true;
} elseif ($is_admin_klinik) {
    // Admin klinik can edit any record in their clinic
    if ((int)$header['klinik_id'] === (int)$user_klinik_id) {
        $has_access = true;
    }
} else {
    // Other roles (HC) only their own data on the same day
    if ($is_today && $is_creator) {
        $has_access = true;
    }
}

if (!$has_access) {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengedit data ini']);
    exit;
}

// Format tanggal for HTML5 date input (YYYY-MM-DD)
if (isset($header['tanggal'])) {
    $header['tanggal'] = date('Y-m-d', strtotime($header['tanggal']));
}

// Get details
$stmt_d = $conn->prepare("
    SELECT 
        pbd.*, 
        COALESCE(bl.nama_item, b.nama_barang) as nama_barang,
        COALESCE(b.kode_barang, 'LOCAL') as kode_barang
    FROM inventory_pemakaian_bhp_detail pbd
    LEFT JOIN inventory_barang b ON pbd.barang_id = b.id AND pbd.is_lokal = 0
    LEFT JOIN inventory_barang_lokal bl ON pbd.barang_id = bl.id AND pbd.is_lokal = 1
    WHERE pbd.pemakaian_bhp_id = ?
");
$stmt_d->bind_param("i", $id);
$stmt_d->execute();
$details_result = $stmt_d->get_result();
$details = [];
while ($d = $details_result->fetch_assoc()) {
    $details[] = $d;
}

echo json_encode([
    'success' => true,
    'header' => $header,
    'details' => $details
]);
?>
