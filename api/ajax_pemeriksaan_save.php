<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_csrf();

$user_id = $_SESSION['user_id'] ?? 0;
$nama = $_POST['nama_pemeriksaan'] ?? '';
$barang_ids = $_POST['barang_ids'] ?? [];
$qtys = $_POST['qtys'] ?? [];
$is_mandatories = $_POST['is_mandatory_list'] ?? [];

if (empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Nama pemeriksaan wajib diisi']);
    exit;
}

$conn->begin_transaction();
try {
    $ket = '';
    $stmt = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (nama_pemeriksaan, keterangan) VALUES (?, ?)");
    $stmt->bind_param("ss", $nama, $ket);
    if (!$stmt->execute()) {
        throw new Exception('Gagal menyimpan data pemeriksaan: ' . $stmt->error);
    }
    $new_id = (int)$conn->insert_id;

    // Ensure is_mandatory column exists (add if missing)
    $col_check = $conn->query("SHOW COLUMNS FROM `inventory_pemeriksaan_grup_detail` LIKE 'is_mandatory'");
    if ($col_check->num_rows === 0) {
        $conn->query("ALTER TABLE `inventory_pemeriksaan_grup_detail` ADD COLUMN `is_mandatory` tinyint(1) NOT NULL DEFAULT 1");
    }

    if (!empty($barang_ids)) {
        $stmt_detail = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan, is_mandatory) VALUES (?, ?, ?, ?)");
        foreach ($barang_ids as $index => $barang_id) {
            $barang_id = (int)$barang_id;
            $qty = (int)($qtys[$index] ?? 1);
            $is_m = (int)($is_mandatories[$index] ?? 1);
            if ($barang_id > 0 && $qty > 0) {
                $stmt_detail->bind_param("iiii", $new_id, $barang_id, $qty, $is_m);
                $stmt_detail->execute();
            }
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Pemeriksaan berhasil disimpan', 'id' => $new_id]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
