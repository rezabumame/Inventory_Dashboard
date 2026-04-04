<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

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
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengubah data: ' . $stmt->error]);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $id, 'nama_pemeriksaan' => $nama], JSON_UNESCAPED_UNICODE);
    exit;
}

$barang_ids = $_POST['barang_ids'] ?? [];
$qtys = $_POST['qtys'] ?? [];

$conn->begin_transaction();
try {
    $ket = '';
    $stmt = $conn->prepare("INSERT INTO pemeriksaan_grup (nama_pemeriksaan, keterangan) VALUES (?, ?)");
    $stmt->bind_param("ss", $nama, $ket);
    if (!$stmt->execute()) {
        throw new Exception('Gagal menyimpan data pemeriksaan: ' . $stmt->error);
    }
    $new_id = (int)$conn->insert_id;

    if (!empty($barang_ids)) {
        $stmt_detail = $conn->prepare("INSERT INTO pemeriksaan_grup_detail (pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?)");
        foreach ($barang_ids as $index => $barang_id) {
            $barang_id = (int)$barang_id;
            $qty = (float)($qtys[$index] ?? 1);
            if ($barang_id > 0 && $qty > 0) {
                $stmt_detail->bind_param("iid", $new_id, $barang_id, $qty);
                $stmt_detail->execute();
            }
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $new_id, 'nama_pemeriksaan' => $nama], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
