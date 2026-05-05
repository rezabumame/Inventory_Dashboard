<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_csrf();

$user_id = $_SESSION['user_id'] ?? 0;
$id_paket = trim((string)($_POST['id_paket'] ?? ''));
$nama = trim((string)($_POST['nama_pemeriksaan'] ?? ''));
$barang_ids = $_POST['barang_ids'] ?? [];
$qtys = $_POST['qtys'] ?? [];
$id_biosys_list = $_POST['id_biosys_list'] ?? [];
$layanan_list = $_POST['layanan_list'] ?? [];

$old_id = trim((string)($_POST['old_id'] ?? ''));

if (empty($id_paket)) {
    echo json_encode(['success' => false, 'message' => 'ID Paket wajib diisi']);
    exit;
}
if (empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Nama pemeriksaan wajib diisi']);
    exit;
}

// Minimal items only required for NEW records or if barang_ids is provided
$is_new = empty($old_id);
if ($is_new && (empty($barang_ids) || (count($barang_ids) === 1 && empty($barang_ids[0])))) {
    echo json_encode(['success' => false, 'message' => 'Minimal pilih 1 item barang']);
    exit;
}

$conn->begin_transaction();
try {
    if (!$is_new) {
        // UPDATE existing
        // If ID changed, we need to update the ID itself
        if ($old_id !== $id_paket) {
            // Check if NEW ID already exists
            $stmt_check = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup WHERE id = ?");
            $stmt_check->bind_param("s", $id_paket);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                throw new Exception('ID Paket baru "' . $id_paket . '" sudah digunakan.');
            }

            // Update parent ID and name
            $stmt = $conn->prepare("UPDATE inventory_pemeriksaan_grup SET id = ?, nama_pemeriksaan = ? WHERE id = ?");
            $stmt->bind_param("sss", $id_paket, $nama, $old_id);
            if (!$stmt->execute()) {
                throw new Exception('Gagal memperbarui ID Paket: ' . $stmt->error);
            }

            // Update details foreign key (in case no ON UPDATE CASCADE)
            $stmt_upd_detail = $conn->prepare("UPDATE inventory_pemeriksaan_grup_detail SET pemeriksaan_grup_id = ? WHERE pemeriksaan_grup_id = ?");
            $stmt_upd_detail->bind_param("ss", $id_paket, $old_id);
            $stmt_upd_detail->execute();
        } else {
            // Only update name
            $stmt = $conn->prepare("UPDATE inventory_pemeriksaan_grup SET nama_pemeriksaan = ? WHERE id = ?");
            $stmt->bind_param("ss", $nama, $id_paket);
            if (!$stmt->execute()) {
                throw new Exception('Gagal memperbarui nama pemeriksaan: ' . $stmt->error);
            }
        }
        
        // If barang_ids is provided, refresh details
        if (!empty($barang_ids) && !(count($barang_ids) === 1 && empty($barang_ids[0]))) {
            $stmt_del = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
            $stmt_del->bind_param("s", $id_paket);
            $stmt_del->execute();
        }
    } else {
        // INSERT new
        $ket = '';
        $stmt = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (id, nama_pemeriksaan, keterangan) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $id_paket, $nama, $ket);
        if (!$stmt->execute()) {
            throw new Exception('Gagal menyimpan data pemeriksaan baru: ' . $stmt->error);
        }
    }

    if (!empty($barang_ids)) {
        $stmt_detail = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?, ?, ?)");
        foreach ($barang_ids as $index => $barang_id) {
            $barang_id = (int)$barang_id;
            $qty = (float)($qtys[$index] ?? 1);
            $id_biosys = trim((string)($id_biosys_list[$index] ?? ''));
            $layanan = trim((string)($layanan_list[$index] ?? ''));
            if ($barang_id > 0 && $qty > 0) {
                $stmt_detail->bind_param("sssid", $id_paket, $id_biosys, $layanan, $barang_id, $qty);
                $stmt_detail->execute();
            }
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Pemeriksaan berhasil disimpan', 'id' => $id_paket]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
