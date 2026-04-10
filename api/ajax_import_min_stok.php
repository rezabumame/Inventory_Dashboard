<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
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
    $updated_count = 0;
    $skipped_count = 0;
    
    // Header should be: ID, Kode Barang, Nama Barang, Stok Minimum
    // We only care about ID and Stok Minimum
    
    for ($i = 1; $i < count($rows); $i++) {
        $id = (int)$rows[$i][0];
        $stok_min_input = $rows[$i][3]; // Column index 3 (0-indexed)
        
        if ($id <= 0) {
            $skipped_count++;
            continue;
        }

        // If blank, skip as requested
        if ($stok_min_input === '' || $stok_min_input === null) {
            $skipped_count++;
            continue;
        }

        $stok_min_input = (int)$stok_min_input;

        // Fetch current to compare
        $stmt_curr = $conn->prepare("SELECT stok_minimum FROM inventory_barang WHERE id = ?");
        $stmt_curr->bind_param("i", $id);
        $stmt_curr->execute();
        $curr_res = $stmt_curr->get_result()->fetch_assoc();
        
        if (!$curr_res) {
            $skipped_count++;
            continue;
        }

        $stok_min_db = (int)$curr_res['stok_minimum'];

        // If same, skip as requested
        if ($stok_min_input === $stok_min_db) {
            $skipped_count++;
            continue;
        }

        // Update
        $stmt_upd = $conn->prepare("UPDATE inventory_barang SET stok_minimum = ? WHERE id = ?");
        $stmt_upd->bind_param("ii", $stok_min_input, $id);
        $stmt_upd->execute();
        $updated_count++;
    }
    
    $conn->commit();
    echo json_encode([
        'success' => true, 
        'message' => "Update selesai. $updated_count item diperbarui, $skipped_count item diabaikan (karena kosong atau nilai sama)."
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
