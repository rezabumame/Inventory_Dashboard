<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

function ensure_booking_col($column, $definition) {
    global $conn;
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `booking_pemeriksaan` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `booking_pemeriksaan` ADD COLUMN `$column` $definition");
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized';
    redirect('index.php');
}

if (!isset($_GET['action']) || !isset($_GET['id'])) {
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=booking');
}

$role = (string)($_SESSION['role'] ?? '');

$id = intval($_GET['id']);
$action = $_GET['action'];

ensure_booking_col('butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0");

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
            if (!in_array($role, ['cs', 'admin_klinik', 'super_admin'], true)) {
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
            $msg = "Booking ditandai Butuh FU.";
            break;
            
        case 'move':
            // Move booking
            $new_status = isset($_GET['new_status']) ? $_GET['new_status'] : '';
            if ($new_status) {
                $conn->query("UPDATE booking_pemeriksaan SET status_booking = '" . $conn->real_escape_string($new_status) . "' WHERE id = $id");
                $msg = "Booking berhasil dipindahkan ke $new_status.";
            } else {
                throw new Exception("Status baru tidak valid");
            }
            break;
            
        case 'adjust':
            // Adjust pax - add additional pax with all existing exams
            $additional_pax = isset($_GET['additional_pax']) ? intval($_GET['additional_pax']) : 0;
            
            if ($additional_pax <= 0) {
                throw new Exception("Jumlah pax tambahan tidak valid");
            }
            
            $old_pax = $booking['jumlah_pax'];
            $new_total_pax = $old_pax + $additional_pax;
            
            // Add all existing exams for additional pax
            foreach ($items_to_process as $item) {
                $barang_id = $item['barang_id'];
                $qty_per_pax = $item['qty_gantung'] / $old_pax; // Calculate qty per pax
                $additional_qty = round($qty_per_pax * $additional_pax);
                
                // Update booking detail
                $new_qty_gantung = $item['qty_gantung'] + $additional_qty;
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
            break;
            
        default:
            throw new Exception("Aksi tidak valid");
    }

    $conn->commit();
    $_SESSION['success'] = $msg;
    redirect('index.php?page=booking');

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
    redirect('index.php?page=booking');
}

$conn->close();
?>
