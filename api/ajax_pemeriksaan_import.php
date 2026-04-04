<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File tidak terupload dengan benar.']);
    exit;
}

$xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
if (!$xlsx) {
    echo json_encode(['success' => false, 'message' => SimpleXLSX::parseError()]);
    exit;
}

$rows = $xlsx->rows();
if (count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'File Excel kosong atau tidak sesuai format.']);
    exit;
}

$conn->begin_transaction();
try {
    $inserted_count = 0;
    $mapping_count = 0;
    
    // Skip header row
    for ($i = 1; $i < count($rows); $i++) {
        $nama_pemeriksaan = trim((string)$rows[$i][0]);
        $kode_barang = trim((string)$rows[$i][1]);
        $qty = (float)($rows[$i][3] ?? 0);
        
        if ($nama_pemeriksaan === '') continue;
        
        // 1. Get or Create Grup
        $stmt = $conn->prepare("SELECT id FROM pemeriksaan_grup WHERE nama_pemeriksaan = ?");
        $stmt->bind_param("s", $nama_pemeriksaan);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $grup_id = $res->fetch_assoc()['id'];
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO pemeriksaan_grup (nama_pemeriksaan, keterangan) VALUES (?, '')");
            $stmt_ins->bind_param("s", $nama_pemeriksaan);
            $stmt_ins->execute();
            $grup_id = $conn->insert_id;
            $inserted_count++;
        }
        
        // 2. Mapping Item if kode_barang is provided
        if ($kode_barang !== '' && $qty > 0) {
            $stmt_b = $conn->prepare("SELECT id FROM barang WHERE kode_barang = ?");
            $stmt_b->bind_param("s", $kode_barang);
            $stmt_b->execute();
            $res_b = $stmt_b->get_result();
            
            if ($res_b->num_rows > 0) {
                $barang_id = $res_b->fetch_assoc()['id'];
                
                // Check if already mapped
                $stmt_check = $conn->prepare("SELECT id FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ? AND barang_id = ?");
                $stmt_check->bind_param("ii", $grup_id, $barang_id);
                $stmt_check->execute();
                
                if ($stmt_check->get_result()->num_rows === 0) {
                    $stmt_map = $conn->prepare("INSERT INTO pemeriksaan_grup_detail (pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?)");
                    $stmt_map->bind_param("iid", $grup_id, $barang_id, $qty);
                    $stmt_map->execute();
                    $mapping_count++;
                }
            }
        }
    }
    
    $conn->commit();
    echo json_encode([
        'success' => true, 
        'message' => "Berhasil mengimport $inserted_count pemeriksaan baru dan $mapping_count mapping item."
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}


