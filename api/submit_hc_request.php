<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

check_role(['petugas_hc']);
require_csrf();

$user_id   = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$catatan   = trim((string)($_POST['catatan'] ?? ''));

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'klinik_id tidak valid.']);
    exit;
}

// Validasi nakes milik klinik ini
$u = $conn->query("SELECT id FROM inventory_users WHERE id=$user_id AND klinik_id=$klinik_id AND role='petugas_hc' AND status='active' LIMIT 1")->fetch_assoc();
if (!$u) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda bukan petugas HC klinik ini.']);
    exit;
}

// Cek tidak ada pending yang sedang aktif
$pending = $conn->query("SELECT id FROM inventory_hc_transfer_request WHERE user_hc_id=$user_id AND klinik_id=$klinik_id AND status='pending' LIMIT 1")->fetch_assoc();
if ($pending) {
    echo json_encode(['success' => false, 'message' => 'Anda masih memiliki request yang pending. Tunggu persetujuan admin terlebih dahulu.']);
    exit;
}

// Parse items
$items_raw = [];
$barang_ids = $_POST['barang_id'] ?? [];
$qtys       = $_POST['qty'] ?? [];
if (!is_array($barang_ids)) $barang_ids = [$barang_ids];
if (!is_array($qtys))       $qtys       = [$qtys];

for ($i = 0; $i < count($barang_ids); $i++) {
    $bid = (int)($barang_ids[$i] ?? 0);
    $qty = (float)($qtys[$i] ?? 0);
    if ($bid <= 0 || $qty <= 0) continue;
    if (!isset($items_raw[$bid])) $items_raw[$bid] = 0;
    $items_raw[$bid] += $qty;
}

if (empty($items_raw)) {
    echo json_encode(['success' => false, 'message' => 'Minimal harus ada 1 item.']);
    exit;
}

// Upload foto (opsional)
$foto_path = null;
if (!empty($_FILES['foto']['tmp_name'])) {
    $upload_dir = __DIR__ . '/../uploads/hc_request/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ftype   = mime_content_type($_FILES['foto']['tmp_name']);
    if (!in_array($ftype, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Foto harus berupa gambar (JPG/PNG/GIF/WEBP).']);
        exit;
    }
    if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Ukuran foto maksimal 5MB.']);
        exit;
    }
    $ext      = pathinfo((string)($_FILES['foto']['name'] ?? ''), PATHINFO_EXTENSION);
    $filename = 'hcreq_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto.']);
        exit;
    }
    $foto_path = 'uploads/hc_request/' . $filename;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO inventory_hc_transfer_request (klinik_id, user_hc_id, status, foto_path, catatan, created_at) VALUES (?, ?, 'pending', ?, ?, NOW())");
    $stmt->bind_param("iiss", $klinik_id, $user_id, $foto_path, $catatan);
    $stmt->execute();
    $request_id = (int)$conn->insert_id;

    $stmt_item = $conn->prepare("INSERT INTO inventory_hc_transfer_request_items (request_id, barang_id, qty_request) VALUES (?, ?, ?)");
    foreach ($items_raw as $bid => $qty) {
        $bid = (int)$bid;
        $stmt_item->bind_param("iid", $request_id, $bid, $qty);
        $stmt_item->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Request berhasil dikirim. Tunggu persetujuan admin.']);
} catch (Throwable $e) {
    $conn->rollback();
    // Hapus foto kalau transaksi gagal
    if ($foto_path && file_exists(__DIR__ . '/../' . $foto_path)) {
        @unlink(__DIR__ . '/../' . $foto_path);
    }
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan request: ' . $e->getMessage()]);
}
