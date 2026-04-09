<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if user is super_admin
if ($_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Hanya Super Admin yang dapat menghapus data']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

require_csrf();

$id = intval($_POST['id']);
$created_by = $_SESSION['user_id'];

$conn->begin_transaction();

try {
    // 1. Get header data to know clinic/hc and type
    $stmt = $conn->prepare("SELECT * FROM inventory_pemakaian_bhp WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();

    if (!$header) {
        throw new Exception("Data pemakaian tidak ditemukan");
    }

    $jenis_pemakaian = $header['jenis_pemakaian'];
    $klinik_id = $header['klinik_id'];
    $user_hc_id = $header['user_hc_id'];

    // 2. Reverse stock before deleting details
    $stmt = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $details = $stmt->get_result();

    while ($item = $details->fetch_assoc()) {
        $bid = (int)$item['barang_id'];
        $qty = (float)$item['qty'];

        if ($jenis_pemakaian === 'hc' && !empty($user_hc_id)) {
            // Return to HC Bag
            $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt_upd->bind_param("diiii", $qty, $created_by, $bid, $user_hc_id, $klinik_id);
            $stmt_upd->execute();
        } elseif ($jenis_pemakaian === 'klinik') {
            // Return to Clinic Stock
            $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
            $stmt_upd->bind_param("diii", $qty, $created_by, $bid, $klinik_id);
            $stmt_upd->execute();
        }
    }

    // 3. Delete details
    $stmt = $conn->prepare("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // 4. Delete header
    $stmt = $conn->prepare("DELETE FROM inventory_pemakaian_bhp WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Data pemakaian berhasil dihapus']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}

