<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

require_csrf();

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT * FROM inventory_pemakaian_bhp WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();

    if (!$header) throw new Exception("Data tidak ditemukan");

    if (!in_array($role, ['spv_klinik', 'super_admin'], true)) {
        throw new Exception("Hanya SPV Klinik atau Super Admin yang dapat memberikan approval");
    }

    if ($action === 'approve') {
        if ($header['status'] === 'pending_delete') {
            // Re-use logic from process_pemakaian_bhp_delete.php (reverse stock)
            $jenis_pemakaian = $header['jenis_pemakaian'];
            $klinik_id = $header['klinik_id'];
            $user_hc_id = $header['user_hc_id'];

            $stmt_d = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_d->bind_param("i", $id);
            $stmt_d->execute();
            $details = $stmt_d->get_result();

            while ($item = $details->fetch_assoc()) {
                $bid = (int)$item['barang_id'];
                $qty = (float)$item['qty'];

                if ($jenis_pemakaian === 'hc' && !empty($user_hc_id)) {
                    $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $stmt_upd->bind_param("diiii", $qty, $user_id, $bid, $user_hc_id, $klinik_id);
                    $stmt_upd->execute();
                } elseif ($jenis_pemakaian === 'klinik') {
                    $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $stmt_upd->bind_param("diii", $qty, $user_id, $bid, $klinik_id);
                    $stmt_upd->execute();
                }
            }

            // Delete record
            $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $id");
            $conn->query("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = $id");
            $conn->query("DELETE FROM inventory_pemakaian_bhp WHERE id = $id");
            
            $msg = "Permintaan penghapusan disetujui. Data telah dihapus dan stok dikembalikan.";
        } elseif ($header['status'] === 'pending_edit') {
            // Apply pending data (JSON)
            $pending_data = json_decode($header['pending_data'], true);
            if (!$pending_data) throw new Exception("Data perubahan tidak valid");

            // 1. Reverse OLD stock
            $stmt_old = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_old->bind_param("i", $id);
            $stmt_old->execute();
            $old_details = $stmt_old->get_result();
            while ($item = $old_details->fetch_assoc()) {
                $bid = (int)$item['barang_id']; $qty = (float)$item['qty'];
                if ($header['jenis_pemakaian'] === 'hc' && !empty($header['user_hc_id'])) {
                    $conn->query("UPDATE inventory_stok_tas_hc SET qty = qty + $qty WHERE barang_id = $bid AND user_id = {$header['user_hc_id']} AND klinik_id = {$header['klinik_id']}");
                } else {
                    $conn->query("UPDATE inventory_stok_gudang_klinik SET qty = qty + $qty WHERE barang_id = $bid AND klinik_id = {$header['klinik_id']}");
                }
            }

            // 2. Update Header
            $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp SET tanggal = ?, jenis_pemakaian = ?, klinik_id = ?, user_hc_id = ?, catatan_transaksi = ?, status = 'active', pending_data = NULL, spv_approved_by = ?, spv_approved_at = NOW() WHERE id = ?");
            $stmt_u->bind_param("ssiisii", $pending_data['tanggal'], $pending_data['jenis_pemakaian'], $pending_data['klinik_id'], $pending_data['user_hc_id'], $pending_data['catatan_transaksi'], $user_id, $id);
            $stmt_u->execute();

            // 3. Replace details
            $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $id");
            $conn->query("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = $id");

            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) VALUES (?, ?, ?, ?, ?)");
            foreach ($pending_data['items'] as $it) {
                $bid = (int)$it['barang_id']; $qty = (float)$it['qty']; $satuan = $it['satuan']; $catatan = $it['catatan'] ?? '';
                $stmt_ins->bind_param("iidss", $id, $bid, $qty, $satuan, $catatan);
                $stmt_ins->execute();

                // Get Current Stock before update for logging
                $qty_sebelum = 0;
                if ($pending_data['jenis_pemakaian'] === 'hc' && !empty($pending_data['user_hc_id'])) {
                    $stk = $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $bid AND user_id = {$pending_data['user_hc_id']} AND klinik_id = {$pending_data['klinik_id']}")->fetch_assoc();
                    $qty_sebelum = (float)($stk['qty'] ?? 0);
                    
                    $conn->query("UPDATE inventory_stok_tas_hc SET qty = qty - $qty WHERE barang_id = $bid AND user_id = {$pending_data['user_hc_id']} AND klinik_id = {$pending_data['klinik_id']}");
                    $level = 'hc';
                    $level_id = $pending_data['user_hc_id'];
                } else {
                    $stk = $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $bid AND klinik_id = {$pending_data['klinik_id']}")->fetch_assoc();
                    $qty_sebelum = (float)($stk['qty'] ?? 0);

                    $conn->query("UPDATE inventory_stok_gudang_klinik SET qty = qty - $qty WHERE barang_id = $bid AND klinik_id = {$pending_data['klinik_id']}");
                    $level = 'klinik';
                    $level_id = $pending_data['klinik_id'];
                }
                
                $qty_sesudah = $qty_sebelum - $qty;

                // Log transaction
                $stmt_log = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, created_by) VALUES (?, ?, ?, 'keluar', ?, ?, ?, 'pemakaian_bhp', ?, ?)");
                $stmt_log->bind_param("isidddii", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $id, $user_id);
                $stmt_log->execute();
            }
            $msg = "Permintaan perubahan disetujui. Data pemakaian telah diperbarui.";
        } else {
            throw new Exception("Status tidak valid untuk approval");
        }
    } elseif ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (empty($reason)) throw new Exception("Alasan penolakan wajib diisi!");
        
        $conn->query("UPDATE inventory_pemakaian_bhp SET status = 'rejected', approval_reason = CONCAT(COALESCE(approval_reason, ''), ' | Ditolak: ', '" . $conn->real_escape_string($reason) . "') WHERE id = $id");
        $msg = "Permintaan telah ditolak.";
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
