<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
$role    = $_SESSION['role'] ?? '';
if (!in_array($role, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}
if (!csrf_validate($_POST['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$barang_id  = (int)($_POST['barang_id']  ?? 0);
$ed_date    = trim((string)($_POST['ed_date'] ?? $_POST['ed_month'] ?? ''));
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$klinik_id  = ($_POST['klinik_id'] ?? '') !== '' ? (int)$_POST['klinik_id'] : null;
$user_id    = (int)$_SESSION['user_id'];

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'barang_id tidak valid.']); exit;
}
// Validate format YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed_date)) {
    echo json_encode(['success' => false, 'message' => 'Format ED tidak valid (harus YYYY-MM-DD).']); exit;
}

$klinik_cond = $klinik_id !== null ? "klinik_id = $klinik_id" : "klinik_id IS NULL";
$ed_esc      = $conn->real_escape_string($ed_date);
$ket_esc     = $conn->real_escape_string($keterangan);

$exists = $conn->query("SELECT id FROM inventory_barang_ed
    WHERE barang_id = $barang_id AND $klinik_cond AND ed_month = '$ed_esc' LIMIT 1");
if ($exists && $exists->num_rows > 0) {
    $row = $exists->fetch_assoc();
    echo json_encode(['success' => true, 'id' => (int)$row['id'], 'already_exists' => true,
        'message' => 'ED sudah terdaftar untuk batch ini.']); exit;
}

$klinik_val = $klinik_id !== null ? $klinik_id : 'NULL';
$ok = $conn->query("INSERT INTO inventory_barang_ed
    (barang_id, klinik_id, ed_month, keterangan, created_by)
    VALUES ($barang_id, $klinik_val, '$ed_esc', " .
    ($ket_esc !== '' ? "'$ket_esc'" : "NULL") .
    ", $user_id)");

if ($ok) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id,
        'message' => "ED $ed_date berhasil disimpan."]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal simpan: ' . $conn->error]);
}
