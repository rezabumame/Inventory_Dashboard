<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$u_role = $_SESSION['role'];
$u_id = $_SESSION['user_id'];
$u_klinik_id = $_SESSION['klinik_id'] ?? 0;

$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!in_array($u_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

switch ($action) {
    case 'list_master':
        $res = $conn->query("SELECT * FROM inventory_barang_lokal ORDER BY nama_item ASC");
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode(['data' => $data]);
        break;

    case 'list_stok':
        $where = "1=1";
        $f_klinik = (int)($_GET['klinik_id'] ?? 0);
        if (!in_array($u_role, ['super_admin', 'admin_gudang'])) {
            $where = "sl.klinik_id = $u_klinik_id";
        } elseif ($f_klinik > 0) {
            $where = "sl.klinik_id = $f_klinik";
        }
        $res = $conn->query("
            SELECT sl.*, bl.nama_item, bl.uom, k.nama_klinik 
            FROM inventory_stok_lokal sl
            JOIN inventory_barang_lokal bl ON sl.barang_lokal_id = bl.id
            JOIN inventory_klinik k ON sl.klinik_id = k.id
            WHERE $where
            ORDER BY bl.nama_item ASC, k.nama_klinik ASC
        ");
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode(['data' => $data]);
        break;

    case 'list_history':
        $where = "1=1";
        $f_klinik = (int)($_GET['klinik_id'] ?? 0);
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $tipe = $_GET['tipe'] ?? '';

        if (!in_array($u_role, ['super_admin', 'admin_gudang'])) {
            $where .= " AND hl.klinik_id = $u_klinik_id";
        } elseif ($f_klinik > 0) {
            $where .= " AND hl.klinik_id = $f_klinik";
        }

        if ($start_date) {
            $where .= " AND DATE(hl.created_at) >= '$start_date'";
        }
        if ($end_date) {
            $where .= " AND DATE(hl.created_at) <= '$end_date'";
        }
        if ($tipe) {
            $t = strtoupper($tipe);
            if ($t === 'KURANG') {
                $where .= " AND (hl.tipe = 'KURANG' OR (hl.tipe = 'adjust' AND hl.qty_perubahan < 0))";
            } elseif ($t === 'TAMBAH') {
                $where .= " AND (hl.tipe = 'TAMBAH' OR hl.tipe = 'tambah' OR (hl.tipe = 'adjust' AND hl.qty_perubahan >= 0))";
            } elseif ($t === 'PAKAI') {
                $where .= " AND (hl.tipe = 'PAKAI' OR hl.tipe = 'pakai')";
            } else {
                $where .= " AND hl.tipe = '$t'";
            }
        }

        $res = $conn->query("
            SELECT hl.*, bl.nama_item, k.nama_klinik 
            FROM inventory_history_lokal hl
            JOIN inventory_barang_lokal bl ON hl.barang_lokal_id = bl.id
            JOIN inventory_klinik k ON hl.klinik_id = k.id
            WHERE $where
            ORDER BY hl.created_at DESC
            LIMIT 1000
        ");
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode(['data' => $data]);
        break;

    case 'save_item':
        if (!in_array($u_role, ['super_admin', 'admin_klinik'])) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        $id = $_POST['id'] ?? '';
        $name = $conn->real_escape_string($_POST['nama_item'] ?? '');
        $uom = $conn->real_escape_string($_POST['uom'] ?? '');

        if ($id) {
            $conn->query("UPDATE inventory_barang_lokal SET nama_item = '$name', uom = '$uom' WHERE id = $id");
            echo json_encode(['success' => true, 'message' => 'Item updated']);
        } else {
            $conn->query("INSERT INTO inventory_barang_lokal (nama_item, uom) VALUES ('$name', '$uom')");
            $new_id = $conn->insert_id;
            $new_code = 'LOCAL-' . str_pad($new_id, 3, '0', STR_PAD_LEFT);
            $conn->query("UPDATE inventory_barang_lokal SET kode_item = '$new_code' WHERE id = $new_id");
            echo json_encode(['success' => true, 'message' => 'Item created with code ' . $new_code]);
        }
        break;

    case 'get_current_stok':
        $id = (int)($_GET['id'] ?? 0);
        $k_id = (int)($_GET['klinik_id'] ?? $u_klinik_id);
        $res = $conn->query("SELECT qty FROM inventory_stok_lokal WHERE barang_lokal_id = $id AND klinik_id = $k_id LIMIT 1");
        $qty = ($res && ($row = $res->fetch_assoc())) ? $row['qty'] : 0;
        echo json_encode(['qty' => $qty]);
        break;

    case 'process_stock':
        if (!in_array($u_role, ['super_admin', 'admin_klinik'])) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        
        $barang_id = (int)$_POST['barang_lokal_id'];
        $tipe = $_POST['tipe']; // tambah / adjust
        $qty_input = (float)$_POST['qty'];
        $keterangan = $conn->real_escape_string($_POST['keterangan'] ?? '');
        $target_klinik_id = (int)($_POST['klinik_id'] ?? $u_klinik_id);
        
        // Ensure clinic id
        if ($target_klinik_id == 0) {
            echo json_encode(['success' => false, 'message' => 'Silakan pilih klinik terlebih dahulu.']);
            exit;
        }

        // Get current stock
        $res_stok = $conn->query("SELECT qty FROM inventory_stok_lokal WHERE barang_lokal_id = $barang_id AND klinik_id = $target_klinik_id FOR UPDATE");
        $qty_sebelum = ($res_stok && ($row = $res_stok->fetch_assoc())) ? (float)$row['qty'] : 0;

        if ($tipe === 'tambah') {
            $qty_sesudah = $qty_sebelum + $qty_input;
            $status = 'completed';
            
            $conn->begin_transaction();
            try {
                // Update or Insert stock
                $conn->query("INSERT INTO inventory_stok_lokal (barang_lokal_id, klinik_id, qty) 
                             VALUES ($barang_id, $target_klinik_id, $qty_sesudah) 
                             ON DUPLICATE KEY UPDATE qty = $qty_sesudah");
                
                // History
                $conn->query("INSERT INTO inventory_history_lokal (barang_lokal_id, klinik_id, tipe, qty_sebelum, qty_perubahan, qty_sesudah, status, keterangan, created_by) 
                             VALUES ($barang_id, $target_klinik_id, 'TAMBAH', $qty_sebelum, $qty_input, $qty_sesudah, 'completed', '$keterangan', $u_id)");
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Stok berhasil ditambahkan']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
        } else if ($tipe === 'adjust') {
            // Adjust requires SPV approval
            // Change: Adjust is now a delta (+/-) instead of absolute final qty
            $qty_sesudah = $qty_sebelum + $qty_input;
            
            // Prevent negative stock
            if ($qty_sesudah < 0) {
                echo json_encode(['success' => false, 'message' => 'Stok akhir tidak boleh kurang dari 0.']);
                exit;
            }

            $new_tipe = ($qty_input >= 0) ? 'TAMBAH' : 'KURANG';
            $conn->query("INSERT INTO inventory_history_lokal (barang_lokal_id, klinik_id, tipe, qty_sebelum, qty_perubahan, qty_sesudah, status, keterangan, created_by) 
                         VALUES ($barang_id, $target_klinik_id, '$new_tipe', $qty_sebelum, $qty_input, $qty_sesudah, 'pending', '$keterangan', $u_id)");
            
            echo json_encode(['success' => true, 'message' => 'Permintaan penyesuaian stok dikirim (Menunggu SPV)']);
        }
        break;

    case 'approval':
        if (!in_array($u_role, ['super_admin', 'spv_klinik'])) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }

        $history_id = (int)$_POST['id'];
        $approve_action = $_POST['action']; // approve / reject

        $res_h = $conn->query("SELECT * FROM inventory_history_lokal WHERE id = $history_id AND status = 'pending'");
        if (!$res_h || $res_h->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Data not found or already processed']);
            exit;
        }
        $h = $res_h->fetch_assoc();

        if ($approve_action === 'reject') {
            $conn->query("UPDATE inventory_history_lokal SET status = 'rejected', approved_by = $u_id, approved_at = NOW() WHERE id = $history_id");
            echo json_encode(['success' => true, 'message' => 'Penyesuaian stok ditolak']);
        } else {
            $conn->begin_transaction();
            try {
                // Update final status
                $conn->query("UPDATE inventory_history_lokal SET status = 'approved', approved_by = $u_id, approved_at = NOW() WHERE id = $history_id");
                
                // Update actual stock
                $b_id = $h['barang_lokal_id'];
                $k_id = $h['klinik_id'];
                $new_qty = $h['qty_sesudah'];

                $conn->query("INSERT INTO inventory_stok_lokal (barang_lokal_id, klinik_id, qty) 
                             VALUES ($b_id, $k_id, $new_qty) 
                             ON DUPLICATE KEY UPDATE qty = $new_qty");
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Penyesuaian stok disetujui']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}
