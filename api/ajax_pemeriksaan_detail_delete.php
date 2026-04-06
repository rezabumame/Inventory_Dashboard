<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
require_csrf();

$detail_id = (int)($_POST['detail_id'] ?? 0);
if ($detail_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid detail_id']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE id = ?");
$stmt->bind_param("i", $detail_id);
$stmt->execute();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);


