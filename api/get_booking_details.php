<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['booking_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$booking_id = intval($_GET['booking_id']);

try {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin_klinik') {
        // Secure IDOR: admin klinik can only get details of bookings from their own clinic
        $userKlinik = (int)($_SESSION['klinik_id'] ?? 0);
        $check = $conn->query("SELECT klinik_id FROM inventory_booking_pemeriksaan WHERE id = $booking_id")->fetch_assoc();
        if (!$check || (int)$check['klinik_id'] !== $userKlinik) {
            echo json_encode(['success' => false, 'message' => 'Access denied to this booking']);
            exit;
        }
    }

    // Get unique exams from booking details
    $query = "SELECT DISTINCT 
                pgd.pemeriksaan_grup_id as pemeriksaan_id,
                pg.nama_pemeriksaan,
                COUNT(DISTINCT bd.barang_id) as item_count
              FROM inventory_booking_detail bd
              JOIN inventory_pemeriksaan_grup_detail pgd ON bd.barang_id = pgd.barang_id
              JOIN inventory_pemeriksaan_grup pg ON pgd.pemeriksaan_grup_id = pg.id
              WHERE bd.booking_id = ?
              GROUP BY pgd.pemeriksaan_grup_id, pg.nama_pemeriksaan
              ORDER BY pg.nama_pemeriksaan";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $exams = [];
    while ($row = $result->fetch_assoc()) {
        $exams[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'exams' => $exams
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
