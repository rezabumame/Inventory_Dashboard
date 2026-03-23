<?php
session_start();
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

$id = (int)($_POST['id'] ?? 0);
$nama = trim((string)($_POST['nama_pemeriksaan'] ?? ''));
if ($nama === '') {
    echo json_encode(['success' => false, 'message' => 'Nama pemeriksaan wajib diisi']);
    exit;
}

if ($id > 0) {
    $ket = '';
    $stmt = $conn->prepare("UPDATE pemeriksaan_grup SET nama_pemeriksaan = ?, keterangan = ? WHERE id = ?");
    $stmt->bind_param("ssi", $nama, $ket, $id);
    $stmt->execute();
    echo json_encode(['success' => true, 'id' => $id, 'nama_pemeriksaan' => $nama], JSON_UNESCAPED_UNICODE);
    exit;
}

$ket = '';
$stmt = $conn->prepare("INSERT INTO pemeriksaan_grup (nama_pemeriksaan, keterangan) VALUES (?, ?)");
$stmt->bind_param("ss", $nama, $ket);
$stmt->execute();
$new_id = (int)$conn->insert_id;

echo json_encode(['success' => true, 'id' => $new_id, 'nama_pemeriksaan' => $nama], JSON_UNESCAPED_UNICODE);

