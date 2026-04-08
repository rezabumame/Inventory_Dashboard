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
    $cleared_grups = [];
    
    // Skip header row
    for ($i = 1; $i < count($rows); $i++) {
        $nama_pemeriksaan = trim((string)$rows[$i][0]);
        $kode_barang = trim((string)$rows[$i][1]);
        $qty = (float)($rows[$i][3] ?? 0);
        $kategori_raw = strtolower(trim((string)($rows[$i][4] ?? 'mandatory')));
        $is_mandatory = ($kategori_raw === 'optional' || $kategori_raw === '0') ? 0 : 1;
        
        if ($nama_pemeriksaan === '') continue;
        
        // 1. Get or Create Grup
        $stmt = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup WHERE nama_pemeriksaan = ?");
        $stmt->bind_param("s", $nama_pemeriksaan);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $grup_id = $res->fetch_assoc()['id'];
            
            // NEW: Clear existing mapping for this grup ONCE per import session
            if (!in_array($grup_id, $cleared_grups)) {
                $stmt_del = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
                $stmt_del->bind_param("i", $grup_id);
                $stmt_del->execute();
                $cleared_grups[] = $grup_id;
            }
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (nama_pemeriksaan, keterangan) VALUES (?, '')");
            $stmt_ins->bind_param("s", $nama_pemeriksaan);
            $stmt_ins->execute();
            $grup_id = $conn->insert_id;
            $inserted_count++;
            $cleared_grups[] = $grup_id; // Also mark as "cleared" so we don't try to delete again (though it's empty)
        }
        
        // 2. Mapping Item if kode_barang is provided
        if ($kode_barang !== '' && $qty > 0) {
            $stmt_b = $conn->prepare("SELECT id FROM inventory_barang WHERE kode_barang = ?");
            $stmt_b->bind_param("s", $kode_barang);
            $stmt_b->execute();
            $res_b = $stmt_b->get_result();
            
            if ($res_b->num_rows > 0) {
                $barang_id = $res_b->fetch_assoc()['id'];
                
                // Check if already mapped
                $stmt_check = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ? AND barang_id = ?");
                $stmt_check->bind_param("ii", $grup_id, $barang_id);
                $stmt_check->execute();
                
                if ($stmt_check->get_result()->num_rows === 0) {
                    $stmt_map = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan, is_mandatory) VALUES (?, ?, ?, ?)");
                    $stmt_map->bind_param("iidi", $grup_id, $barang_id, $qty, $is_mandatory);
                    $stmt_map->execute();
                    $mapping_count++;
                } else {
                    // Update existing mapping category/qty if re-imported? 
                    // User didn't explicitly ask for update, but it's good practice.
                    // For now, let's just stick to the request: ensure category exists.
                    $stmt_upd = $conn->prepare("UPDATE inventory_pemeriksaan_grup_detail SET qty_per_pemeriksaan = ?, is_mandatory = ? WHERE pemeriksaan_grup_id = ? AND barang_id = ?");
                    $stmt_upd->bind_param("diii", $qty, $is_mandatory, $grup_id, $barang_id);
                    $stmt_upd->execute();
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


