<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$booking_id = (int)($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

$sql = "SELECT * FROM inventory_booking_history WHERE booking_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Table history belum tersedia. Silakan jalankan migrate.php.']);
    exit;
}
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $row['changes'] = json_decode($row['changes'] ?? '{}', true);
    $history[] = $row;
}

echo json_encode(['success' => true, 'data' => $history]);
?>
