<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized';
    redirect('index.php?page=stok_petugas_hc');
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik'], true)) {
    $_SESSION['error'] = 'Access denied';
    redirect('index.php?page=stok_petugas_hc');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=stok_petugas_hc');
}

require_csrf();

$pending = $_SESSION['hc_bulk_pending'] ?? null;
if (!is_array($pending)) {
    $_SESSION['error'] = 'Tidak ada upload yang menunggu konfirmasi';
    redirect('index.php?page=stok_petugas_hc');
}

$klinik_id = (int)($pending['klinik_id'] ?? 0);
$petugas_user_id = (int)($pending['petugas_user_id'] ?? 0);
$created_by = (int)($_SESSION['user_id'] ?? 0);

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    $_SESSION['error'] = 'Access denied';
    redirect('index.php?page=stok_petugas_hc');
}

$allocations = $pending['allocations'] ?? [];
if (!is_array($allocations)) $allocations = [];

function apply_bulk_alloc(mysqli $conn, int $klinik_id, int $created_by, array $allocations): int {
    $count = 0;
    $stmt_get = $conn->prepare("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ? LIMIT 1");
    $stmt_up = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(updated_by), updated_at = NOW()");
    $stmt_del = $conn->prepare("DELETE FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ? LIMIT 1");
    $stmt_hist = $conn->prepare("INSERT INTO inventory_hc_tas_allocation (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($allocations as $uid => $items) {
        $uid = (int)$uid;
        if ($uid <= 0 || !is_array($items)) continue;
        foreach ($items as $bid => $qty_new) {
            $bid = (int)$bid;
            $qty_new = (float)$qty_new;
            if ($bid <= 0) continue;

            // Get current stock to calculate delta for history
            $stmt_get->bind_param("iii", $bid, $uid, $klinik_id);
            $stmt_get->execute();
            $res = $stmt_get->get_result();
            $qty_before = (float)($res && $res->num_rows > 0 ? ($res->fetch_assoc()['qty'] ?? 0) : 0);
            $delta = (float)round($qty_new - $qty_before, 4);

            if (abs($delta) > 0.0000001) {
                $cat = 'Upload Excel Alokasi';
                $stmt_hist->bind_param("iiidsi", $klinik_id, $uid, $bid, $delta, $cat, $created_by);
                $stmt_hist->execute();

                if ($qty_new <= 0.0000001) {
                    $stmt_del->bind_param("iii", $bid, $uid, $klinik_id);
                    $stmt_del->execute();
                    $count++;
                } else {
                    $stmt_up->bind_param("iiidi", $bid, $uid, $klinik_id, $qty_new, $created_by);
                    $stmt_up->execute();
                    $count++;
                }
            }
        }
    }
    return $count;
}

$conn->begin_transaction();
try {
    $affected = apply_bulk_alloc($conn, $klinik_id, $created_by, $allocations);
    $conn->commit();
    unset($_SESSION['hc_bulk_pending']);
    $_SESSION['success'] = 'Upload alokasi berhasil disimpan (' . (int)$affected . ' baris).';
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Gagal menyimpan alokasi: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id);



