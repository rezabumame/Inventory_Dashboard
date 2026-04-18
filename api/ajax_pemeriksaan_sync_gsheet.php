<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

// Get configuration from settings
$gsheet_url = get_setting('gsheet_exam_url');
$gsheet_name = get_setting('gsheet_exam_sheet');
$mapping = json_decode(get_setting('gsheet_exam_mapping', '{}'), true);

if (empty($gsheet_url) || empty($gsheet_name) || empty($mapping)) {
    echo json_encode(['success' => false, 'message' => 'Konfigurasi Google Sheets belum lengkap. Silakan klik tombol Config GSheet terlebih dahulu.']);
    exit;
}

try {
    // Fetch data from GSheet via Apps Script
    $fetch_url = $gsheet_url . "?action=get_data&sheet=" . urlencode($gsheet_name);
    $ch = curl_init($fetch_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("GSheet Webhook returned HTTP $http_code");
    }

    $res_json = json_decode($response, true);
    if (!$res_json || !isset($res_json['success']) || !$res_json['success']) {
        throw new Exception("Gagal mengambil data dari GSheet: " . ($res_json['message'] ?? 'Format respon tidak valid'));
    }

    $rows = $res_json['data'] ?? [];
    if (empty($rows)) {
        echo json_encode(['success' => true, 'message' => 'Sinkronisasi selesai. Tidak ada data di GSheet.']);
        exit;
    }

    // Header row is first
    $headers = array_shift($rows);
    $col_map = [];
    foreach ($mapping as $db_key => $gs_header) {
        $idx = array_search($gs_header, $headers);
        if ($idx !== false) $col_map[$db_key] = $idx;
    }

    // Cache barangs for lookup
    $barangs_by_code = [];
    $barangs_by_odoo = [];
    $res_b = $conn->query("SELECT id, kode_barang, odoo_product_id FROM inventory_barang");
    while($b = $res_b->fetch_assoc()) {
        if ($b['kode_barang']) $barangs_by_code[trim($b['kode_barang'])] = (int)$b['id'];
        if ($b['odoo_product_id']) $barangs_by_odoo[trim($b['odoo_product_id'])] = (int)$b['id'];
    }

    $conn->begin_transaction();
    
    $inserted_exams = 0;
    $mapping_count = 0;
    $cleared_grups = [];

    foreach ($rows as $r) {
        $id_paket = isset($col_map['id_paket']) ? trim((string)($r[$col_map['id_paket']] ?? '')) : '';
        $nama_paket = isset($col_map['nama_paket']) ? trim((string)($r[$col_map['nama_paket']] ?? '')) : '';
        $id_biosys = isset($col_map['id_biosys']) ? trim((string)($r[$col_map['id_biosys']] ?? '')) : '';
        $layanan = isset($col_map['layanan']) ? trim((string)($r[$col_map['layanan']] ?? '')) : '';
        $raw_barang_id = isset($col_map['barang_id']) ? trim((string)($r[$col_map['barang_id']] ?? '')) : '';
        $qty = isset($col_map['qty']) ? (float)($r[$col_map['qty']] ?? 0) : 0;

        if ($id_paket === '' || $nama_paket === '') continue;

        // 1. Get or Create Grup
        $stmt = $conn->prepare("SELECT id FROM inventory_pemeriksaan_grup WHERE id = ?");
        $stmt->bind_param("s", $id_paket);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt_upd = $conn->prepare("UPDATE inventory_pemeriksaan_grup SET nama_pemeriksaan = ? WHERE id = ?");
            $stmt_upd->bind_param("ss", $nama_paket, $id_paket);
            $stmt_upd->execute();
            if (!in_array($id_paket, $cleared_grups)) {
                $stmt_del = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
                $stmt_del->bind_param("s", $id_paket);
                $stmt_del->execute();
                $cleared_grups[] = $id_paket;
            }
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (id, nama_pemeriksaan) VALUES (?, ?)");
            $stmt_ins->bind_param("ss", $id_paket, $nama_paket);
            $stmt_ins->execute();
            $inserted_exams++;
            $cleared_grups[] = $id_paket;
        }

        // 2. Mapping Item
        $bid = 0;
        if (isset($barangs_by_code[$raw_barang_id])) $bid = $barangs_by_code[$raw_barang_id];
        elseif (isset($barangs_by_odoo[$raw_barang_id])) $bid = $barangs_by_odoo[$raw_barang_id];

        if ($bid > 0 && $qty > 0) {
            $stmt_map = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?, ?, ?)");
            $stmt_map->bind_param("sssid", $id_paket, $id_biosys, $layanan, $bid, $qty);
            $stmt_map->execute();
            $mapping_count++;
        }
    }

    $conn->commit();
    echo json_encode([
        'success' => true, 
        'message' => "Sync Berhasil! $inserted_exams paket baru ditambahkan dan " . count($cleared_grups) . " paket diperbarui."
    ]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
