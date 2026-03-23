<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['booking_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$booking_id = intval($_GET['booking_id']);

try {
    // Get unique exams from booking details
    $query = "SELECT DISTINCT 
                pgd.pemeriksaan_grup_id as pemeriksaan_id,
                pg.nama_pemeriksaan,
                COUNT(DISTINCT bd.barang_id) as item_count
              FROM booking_detail bd
              JOIN pemeriksaan_grup_detail pgd ON bd.barang_id = pgd.barang_id
              JOIN pemeriksaan_grup pg ON pgd.pemeriksaan_grup_id = pg.id
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
