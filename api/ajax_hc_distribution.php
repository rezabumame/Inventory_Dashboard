<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/history_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Auth check
check_role(['super_admin', 'admin_hc']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_data':
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d', strtotime('+7 days'));
        
        $where = "b.status_booking LIKE '%HC%' AND b.status IN ('booked', 'rescheduled')";
        $where .= " AND b.tanggal_pemeriksaan BETWEEN '" . $conn->real_escape_string($start) . "' AND '" . $conn->real_escape_string($end) . "'";
        
        if (isset($_GET['q']) && $_GET['q'] !== '') {
            $q = $conn->real_escape_string($_GET['q']);
            $where .= " AND (b.nama_pemesan LIKE '%$q%' OR b.nomor_booking LIKE '%$q%' OR b.order_id LIKE '%$q%')";
        }

        $query = "SELECT b.*, k.nama_klinik 
                  FROM inventory_booking_pemeriksaan b
                  JOIN inventory_klinik k ON b.klinik_id = k.id
                  WHERE $where
                  ORDER BY b.tanggal_pemeriksaan ASC, b.jam_layanan ASC";
        $res = $conn->query($query);
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Fetch clinics for Kanban columns - ONLY those with kode_homecare
        $q_klinik = "SELECT id, nama_klinik FROM inventory_klinik 
                    WHERE status = 'active' 
                    AND kode_homecare IS NOT NULL 
                    AND kode_homecare != '' 
                    ORDER BY nama_klinik ASC";
        $res_k = $conn->query($q_klinik);
        $clinics = [];
        while ($rk = $res_k->fetch_assoc()) {
            $clinics[] = $rk;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'clinics' => $clinics
        ]);
        break;

    case 'move_booking':
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $target_klinik_id = (int)($_POST['target_klinik_id'] ?? 0);
        
        if ($booking_id <= 0 || $target_klinik_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        // Get current data for history
        $old = $conn->query("SELECT klinik_id, status_booking FROM inventory_booking_pemeriksaan WHERE id = $booking_id")->fetch_assoc();
        if (!$old) {
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET klinik_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $target_klinik_id, $booking_id);
            $stmt->execute();
            
            // Sync details if needed (HC bookings should have qty_reserved_hc)
            $conn->query("UPDATE inventory_booking_detail SET qty_reserved_hc = qty_gantung, qty_reserved_onsite = 0 WHERE booking_id = $booking_id");
            
            // Log history
            $res_old_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = " . (int)$old['klinik_id']);
            $res_new_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = $target_klinik_id");
            $old_k_name = $res_old_k->fetch_assoc()['nama_klinik'] ?? 'Unknown';
            $new_k_name = $res_new_k->fetch_assoc()['nama_klinik'] ?? 'Unknown';
            
            logBookingHistory($conn, $booking_id, 'move', 
                ['klinik_id' => ['old' => $old['klinik_id'], 'new' => $target_klinik_id]], 
                "Redistribusi HC: Dipindahkan dari klinik $old_k_name ke $new_k_name via HC Distribution"
            );
            
            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}
