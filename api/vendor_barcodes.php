<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role      = $_SESSION['role'] ?? '';
// Tambah barcode vendor: dibutuhkan juga saat penerimaan barang (Inbound) oleh admin_klinik/spv_klinik.
// Hapus barcode vendor tetap dibatasi super_admin/admin_gudang (aksi cleanup master data, bukan bagian alur terima barang).
$can_add   = in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik']);
$can_edit  = in_array($role, ['super_admin', 'admin_gudang']);
$action    = trim((string)($_REQUEST['action'] ?? 'list'));
$barang_id = (int)($_REQUEST['barang_id'] ?? 0);

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID barang tidak valid.']);
    exit;
}

if ($action === 'list') {
    $res = $conn->query("SELECT id, barcode, keterangan, created_at FROM inventory_barcode_vendor WHERE barang_id = $barang_id ORDER BY created_at ASC");
    $items = [];
    while ($res && ($row = $res->fetch_assoc())) $items[] = $row;
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

if ($action === 'add') {
    if (!$can_add) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
    if (!csrf_validate($_POST['_csrf'] ?? '')) { echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit; }
    $barcode    = trim((string)($_POST['barcode'] ?? ''));
    $keterangan = trim((string)($_POST['keterangan'] ?? ''));
    if ($barcode === '') { echo json_encode(['success' => false, 'message' => 'Barcode tidak boleh kosong.']); exit; }

    $b_esc = $conn->real_escape_string($barcode);
    $k_esc = $conn->real_escape_string($keterangan);
    $ok = $conn->query("INSERT INTO inventory_barcode_vendor (barang_id, barcode, keterangan) VALUES ($barang_id, '$b_esc', " . ($k_esc !== '' ? "'$k_esc'" : "NULL") . ")");
    if ($ok) {
        $new_id = $conn->insert_id;
        echo json_encode(['success' => true, 'id' => $new_id, 'barcode' => $barcode, 'keterangan' => $keterangan]);
    } else {
        if (str_contains($conn->error, 'Duplicate')) {
            $owner = $conn->query("
                SELECT b.nama_barang FROM inventory_barcode_vendor bv
                JOIN inventory_barang b ON b.id = bv.barang_id
                WHERE bv.barcode = '$b_esc' LIMIT 1
            ")->fetch_assoc();
            $owner_name = $owner['nama_barang'] ?? null;
            $msg = $owner_name
                ? "Barcode '$barcode' sudah terdaftar untuk item lain: $owner_name."
                : "Barcode '$barcode' sudah terdaftar.";
        } else {
            $msg = $conn->error;
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

if ($action === 'delete') {
    if (!$can_edit) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
    if (!csrf_validate($_POST['_csrf'] ?? '')) { echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit; }
    $vendor_id = (int)($_POST['id'] ?? 0);
    if ($vendor_id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); exit; }
    $ok = $conn->query("DELETE FROM inventory_barcode_vendor WHERE id = $vendor_id AND barang_id = $barang_id");
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Deleted' : $conn->error]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
