<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

require_csrf();

$id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];

$dedup_key = sha1('process_pemakaian_bhp_delete|' . (int)($_SESSION['user_id'] ?? 0) . '|' . json_encode(array_diff_key($_POST, ['_csrf' => true])));
if (!isset($_SESSION['_dedup'])) $_SESSION['_dedup'] = [];
$now = time();
if (!empty($_SESSION['_dedup'][$dedup_key]) && ($now - (int)$_SESSION['_dedup'][$dedup_key]) < 8) {
    echo json_encode(['success' => false, 'message' => 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.']);
    exit;
}
$_SESSION['_dedup'][$dedup_key] = $now;

$lock_esc = '';

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

    // Use usage date (tanggal) to determine the grace period
    $usage_date = date('Y-m-d', strtotime($header['tanggal']));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // H-0 and H-1 of the USAGE DATE are considered within grace period (no approval needed)
    $is_today = ($usage_date === $today || $usage_date === $yesterday);
    $is_over_2_days = !$is_today;

    $is_creator = $header['created_by'] == $user_id;
    $is_admin_klinik = $_SESSION['role'] === 'admin_klinik';
    $is_super_admin = $_SESSION['role'] === 'super_admin';
    $reason = $_POST['reason'] ?? '';

    // Super Admin can delete anything
    if (!$is_super_admin && $is_over_2_days) {
        throw new Exception("Data yang sudah lewat 2 hari tidak dapat dihapus, hanya diperbolehkan untuk edit.");
    }
    
    if (!$is_super_admin) {
        if ($is_today) {
            // Same day: Creator or Admin Klinik of the same clinic can delete
            if (!$is_creator && !($is_admin_klinik && (int)$header['klinik_id'] === (int)$_SESSION['klinik_id'])) {
                throw new Exception("Anda tidak memiliki akses untuk menghapus data ini");
            }
        } else {
            // Past day: Admin Klinik can request delete
            if ($is_admin_klinik && (int)$header['klinik_id'] === (int)$_SESSION['klinik_id']) {
                if (empty($reason)) {
                    throw new Exception("Alasan wajib diisi untuk penghapusan lewat hari");
                }
                // Update status to pending_delete and record reason
                $stmt = $conn->prepare("UPDATE inventory_pemakaian_bhp SET status = 'pending_delete', approval_reason = ? WHERE id = ?");
                $stmt->bind_param("si", $reason, $id);
                $stmt->execute();
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Permintaan penghapusan telah dikirim ke SPV Klinik']);
                exit;
            } else {
                throw new Exception("Penghapusan lewat hari memerlukan approval SPV dan hanya dapat diajukan oleh Admin Klinik");
            }
        }
    }

    $jenis_pemakaian = $header['jenis_pemakaian'];
    $klinik_id = $header['klinik_id'];
    $user_hc_id = $header['user_hc_id'];

    // Lock per clinic+jenis to prevent concurrent stock mutation conflicts
    $lock_name = 'stock_pemakaian_bhp_' . (int)$klinik_id . '_' . preg_replace('/[^a-z]/', '', (string)$jenis_pemakaian);
    $lock_esc = $conn->real_escape_string($lock_name);
    $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got_lock !== 1) {
        throw new Exception('Sistem sedang memproses stok klinik ini. Coba lagi sebentar.');
    }

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
            $stmt_upd->bind_param("diiii", $qty, $user_id, $bid, $user_hc_id, $klinik_id);
            $stmt_upd->execute();
        } elseif ($jenis_pemakaian === 'klinik') {
            // Return to Clinic Stock
            $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
            $stmt_upd->bind_param("diii", $qty, $user_id, $bid, $klinik_id);
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
    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    echo json_encode(['success' => true, 'message' => 'Data pemakaian berhasil dihapus']);

} catch (Exception $e) {
    $conn->rollback();
    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
