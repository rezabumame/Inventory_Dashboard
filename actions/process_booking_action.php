<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';';
require_once __DIR__ . '/../lib/stock.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized';
    redirect('index.php');
}

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($action === '' || $id <= 0) {
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=booking');
}

require_csrf();

$role = (string)($_SESSION['role'] ?? '');

$booking = $conn->query("SELECT * FROM booking_pemeriksaan WHERE id = $id")->fetch_assoc();

if (!$booking || $booking['status'] != 'booked') {
    $_SESSION['error'] = 'Booking tidak ditemukan atau sudah diproses';
    redirect('index.php?page=booking');
}
if ($role === 'admin_klinik') {
    $userKlinik = (int)($_SESSION['klinik_id'] ?? 0);
    if ((int)($booking['klinik_id'] ?? 0) !== $userKlinik) {
        $_SESSION['error'] = 'Access denied';
        redirect('index.php?page=booking');
    }
}

$conn->begin_transaction();
try {
    // Get all items from details
    $sql_items = "SELECT bd.* FROM booking_detail bd WHERE bd.booking_id = $id";
    $details = $conn->query($sql_items);
    
    $items_to_process = [];
    while($d = $details->fetch_assoc()) {
        $items_to_process[] = $d;
    }

    $msg = "";

    switch ($action) {
        case 'cancel':
            if (!in_array($role, ['cs', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $setType = ($role === 'cs') ? ", booking_type = 'cancel'" : "";
            $conn->query("UPDATE booking_pemeriksaan SET status = 'cancelled', butuh_fu = 0 $setType WHERE id = $id");
            $msg = "Booking dibatalkan.";
            break;
        
        case 'done':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $conn->query("UPDATE booking_pemeriksaan SET status = 'completed', butuh_fu = 0 WHERE id = $id");
            $msg = "Booking selesai.";
            break;

        case 'fu':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $conn->query("UPDATE booking_pemeriksaan SET butuh_fu = 1 WHERE id = $id");
            $msg = "Booking ditandai FU jadwal kedatangan.";
            break;
            
        case 'move':
            // Move booking
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $new_status = (string)($_POST['new_status'] ?? ($_GET['new_status'] ?? ''));
            $new_status = trim($new_status);
            if (!in_array($new_status, ['Reserved - Clinic', 'Reserved - HC'], true)) {
                throw new Exception("Status baru tidak valid");
            }
            $target_is_hc = (stripos($new_status, 'HC') !== false);
            $lock_name = 'booking_action_' . (int)($booking['klinik_id'] ?? 0);
            $lock_esc = $conn->real_escape_string($lock_name);
            $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
            $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
            if ($got_lock !== 1) throw new Exception('Sistem sedang memproses booking lain. Coba lagi sebentar.');
            try {
                foreach ($items_to_process as $item) {
                    $barang_id = (int)($item['barang_id'] ?? 0);
                    $need = (int)($item['qty_gantung'] ?? 0);
                    if ($barang_id <= 0 || $need <= 0) continue;
                    $ef = stock_effective($conn, (int)($booking['klinik_id'] ?? 0), $target_is_hc, $barang_id);
                    if (!$ef['ok']) throw new Exception((string)$ef['message']);
                    $avail = (float)($ef['available'] ?? 0);
                    if ($avail < $need) {
                        $nm = (string)($ef['barang_name'] ?? ("ID:$barang_id"));
                        throw new Exception("Stok tidak cukup untuk pindah. $nm tersedia: $avail, dibutuhkan: $need");
                    }
                }

                $conn->query("UPDATE booking_pemeriksaan SET status_booking = '" . $conn->real_escape_string($new_status) . "' WHERE id = $id");
                if ($target_is_hc) {
                    $conn->query("UPDATE booking_detail SET qty_reserved_hc = qty_gantung, qty_reserved_onsite = 0 WHERE booking_id = $id");
                } else {
                    $conn->query("UPDATE booking_detail SET qty_reserved_onsite = qty_gantung, qty_reserved_hc = 0 WHERE booking_id = $id");
                }
                $msg = "Booking berhasil dipindahkan ke $new_status.";
            } finally {
                $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
            }
            break;
            
        case 'adjust':
            // Adjust pax - add additional pax with all existing exams
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $additional_pax = (int)($_POST['additional_pax'] ?? ($_GET['additional_pax'] ?? 0));
            
            if ($additional_pax <= 0) {
                throw new Exception("Jumlah pax tambahan tidak valid");
            }
            
            $old_pax = (int)($booking['jumlah_pax'] ?? 0);
            if ($old_pax <= 0) throw new Exception("Jumlah pax saat ini tidak valid");
            $new_total_pax = $old_pax + $additional_pax;

            $is_hc = (stripos((string)$booking['status_booking'], 'HC') !== false);
            $lock_name = 'booking_action_' . (int)($booking['klinik_id'] ?? 0);
            $lock_esc = $conn->real_escape_string($lock_name);
            $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
            $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
            if ($got_lock !== 1) throw new Exception('Sistem sedang memproses booking lain. Coba lagi sebentar.');

            try {
                foreach ($items_to_process as $item) {
                    $barang_id = (int)($item['barang_id'] ?? 0);
                    $cur_qty = (int)($item['qty_gantung'] ?? 0);
                    if ($barang_id <= 0 || $cur_qty <= 0) continue;
                    $additional_qty = (int)round(((float)$cur_qty / (float)$old_pax) * (float)$additional_pax);
                    if ($additional_qty <= 0) continue;

                    $ef = stock_effective($conn, (int)($booking['klinik_id'] ?? 0), $is_hc, $barang_id);
                    if (!$ef['ok']) throw new Exception((string)$ef['message']);
                    $avail = (float)($ef['available'] ?? 0);
                    if ($avail < $additional_qty) {
                        $nm = (string)($ef['barang_name'] ?? ("ID:$barang_id"));
                        throw new Exception("Stok tidak cukup untuk tambah pax. $nm tersedia: $avail, dibutuhkan: $additional_qty");
                    }

                    $new_qty_gantung = $cur_qty + $additional_qty;
                    $new_qty_reserved_onsite = (int)$item['qty_reserved_onsite'];
                    $new_qty_reserved_hc = (int)$item['qty_reserved_hc'];
                    if (stripos((string)$booking['status_booking'], 'Clinic') !== false) {
                        $new_qty_reserved_onsite += $additional_qty;
                    } elseif (stripos((string)$booking['status_booking'], 'HC') !== false) {
                        $new_qty_reserved_hc += $additional_qty;
                    }
                    $conn->query("UPDATE booking_detail SET qty_gantung = $new_qty_gantung, qty_reserved_onsite = $new_qty_reserved_onsite, qty_reserved_hc = $new_qty_reserved_hc WHERE id = {$item['id']}");
                }

                $conn->query("UPDATE booking_pemeriksaan SET jumlah_pax = $new_total_pax WHERE id = $id");
                $msg = "Berhasil menambah $additional_pax pax (total: $new_total_pax).";
            } finally {
                $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
            }
            break;
            
        default:
            throw new Exception("Aksi tidak valid");
    }

    $conn->commit();
    $_SESSION['success'] = $msg;
    $return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
    redirect($return_url);

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
    $return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
    redirect($return_url);
}

$conn->close();
?>

