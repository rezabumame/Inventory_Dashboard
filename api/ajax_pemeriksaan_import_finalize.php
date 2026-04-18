<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['mappings'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
    exit;
}

// Manual CSRF validation for JSON payload
$csrf_token = $data['_csrf'] ?? '';
if (!csrf_validate((string)$csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

$mappings = $data['mappings'];
$delete_all = isset($data['delete_all']) && $data['delete_all'] === true;
$total_excel_rows = (int)($data['total_excel_rows'] ?? 0);

$conn->begin_transaction();
try {
    if ($delete_all) {
        $conn->query("DELETE FROM inventory_pemeriksaan_grup_detail");
        $conn->query("DELETE FROM inventory_pemeriksaan_grup");
    }

    $inserted_count = 0;
    $mapping_count = 0;
    $ignored_count = 0;
    $ignored_details = []; // Store rows that are not processed
    $cleared_grups = [];

    // Identify which rows from original Excel are NOT in $mappings (because they were ignored in UI)
    // But for simplicity and accuracy, let's track anything that doesn't result in a successful mapping
    
    // We need the original total rows data to know exactly what was skipped
    // For now, let's track what's explicitly ignored in the loop
    
    foreach ($mappings as $row) {
        $id_paket = trim((string)($row['id_paket'] ?? ''));
        $nama_pemeriksaan = trim((string)($row['nama_paket'] ?? ''));
        $id_biosys = trim((string)($row['id_biosys'] ?? ''));
        $layanan = trim((string)($row['layanan'] ?? ''));
        $barang_id = (int)($row['barang_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        $consumables = $row['consumables'] ?? '';
        $uom_excel = $row['uom_excel'] ?? '';

        // Case: Completely invalid row (No ID Paket or No Name)
        if ($id_paket === '' || $nama_pemeriksaan === '') {
            $ignored_count++;
            $ignored_details[] = [
                'id_paket' => $id_paket,
                'nama_paket' => $nama_pemeriksaan,
                'id_biosys' => $id_biosys,
                'layanan' => $layanan,
                'barang_id' => $row['barang_id'] ?? '',
                'consumables' => $consumables,
                'qty' => $qty,
                'uom' => $uom_excel,
                'reason' => 'ID Paket atau Nama Pemeriksaan Kosong (Gagal Total)'
            ];
            continue;
        }

        // 1. Get or Create Grup (Always do this if ID Paket is valid)
        $stmt = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup WHERE id = ?");
        $stmt->bind_param("s", $id_paket);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $grup_id = $id_paket;
            // Update name
            $stmt_upd_name = $conn->prepare("UPDATE inventory_pemeriksaan_grup SET nama_pemeriksaan = ? WHERE id = ?");
            $stmt_upd_name->bind_param("ss", $nama_pemeriksaan, $id_paket);
            $stmt_upd_name->execute();

            // Clear existing mapping for this grup ONCE per import session
            if (!in_array($grup_id, $cleared_grups)) {
                $stmt_del = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
                $stmt_del->bind_param("s", $grup_id);
                $stmt_del->execute();
                $cleared_grups[] = $grup_id;
            }
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (id, nama_pemeriksaan, keterangan) VALUES (?, ?, '')");
            $stmt_ins->bind_param("ss", $id_paket, $nama_pemeriksaan);
            $stmt_ins->execute();
            $grup_id = $id_paket;
            $inserted_count++;
            $cleared_grups[] = $grup_id;
        }

        // 2. Mapping Item (Only if valid)
        if ($barang_id > 0 && $qty > 0) {
            // Check if already mapped
            $stmt_check = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ? AND barang_id = ? AND id_biosys = ? AND nama_layanan = ?");
            $stmt_check->bind_param("siss", $grup_id, $barang_id, $id_biosys, $layanan);
            $stmt_check->execute();

            if ($stmt_check->get_result()->num_rows === 0) {
                $stmt_map = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?, ?, ?)");
                $stmt_map->bind_param("sssid", $grup_id, $id_biosys, $layanan, $barang_id, $qty);
                $stmt_map->execute();
                $mapping_count++;
            } else {
                $stmt_upd = $conn->prepare("UPDATE inventory_pemeriksaan_grup_detail SET qty_per_pemeriksaan = ? WHERE pemeriksaan_grup_id = ? AND barang_id = ? AND id_biosys = ? AND nama_layanan = ?");
                $stmt_upd->bind_param("dsiss", $qty, $grup_id, $barang_id, $id_biosys, $layanan);
                $stmt_upd->execute();
                $mapping_count++;
            }
        } else {
            // Group created but item skipped
            $ignored_count++;
            $ignored_details[] = [
                'id_paket' => $id_paket,
                'nama_paket' => $nama_pemeriksaan,
                'id_biosys' => $id_biosys,
                'layanan' => $layanan,
                'barang_id' => $row['barang_id'] ?? '',
                'consumables' => $consumables,
                'qty' => $qty,
                'uom' => $uom_excel,
                'reason' => 'Paket Terbuat, tapi Item Mapping Dilewati (ID Barang Tidak Ditemukan / Qty 0)'
            ];
        }
    }

    $conn->commit();
    
    // Store ignored details in session for report download
    $_SESSION['last_import_ignored'] = $ignored_details;
    
    $total_processed = $mapping_count + $ignored_count;
    $diff = $total_excel_rows - $total_processed;
    
    $msg = "Berhasil mengimport $inserted_count pemeriksaan baru dan $mapping_count mapping item.";
    if ($total_excel_rows > 0) {
        $msg .= "\n\nDetail Baris Excel:";
        $msg .= "\n- Total Baris: " . $total_excel_rows;
        $msg .= "\n- Berhasil: " . $mapping_count;
        if ($diff > 0) {
            $msg .= "\n- Dilewati/Ditolak: " . $diff;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $msg
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>