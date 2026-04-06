<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$barang_id = (int)($_POST['barang_id'] ?? 0);
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$tipe = (string)($_POST['tipe'] ?? 'in'); // 'in' or 'out'
$tanggal = (string)($_POST['tanggal'] ?? date('Y-m-d'));

if ($barang_id <= 0 || $klinik_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Determine date range (current month)
$month_start = date('Y-m-01', strtotime($tanggal)) . ' 00:00:00';
$month_end = date('Y-m-t', strtotime($tanggal)) . ' 23:59:59';

$tipe_esc = $conn->real_escape_string($tipe);
$sql = "SELECT ts.*, u.nama_lengkap as creator_name
        FROM inventory_transaksi_stok ts
        LEFT JOIN inventory_users u ON ts.created_by = u.id
        WHERE ts.barang_id = $barang_id 
          AND ts.level = 'klinik' 
          AND ts.level_id = $klinik_id 
          AND ts.tipe_transaksi = '$tipe_esc'
          AND ts.created_at >= '$month_start' 
          AND ts.created_at <= '$month_end'
        ORDER BY ts.created_at DESC";

$res = $conn->query($sql);
$data = [];

while ($row = $res->fetch_assoc()) {
    $ref_label = $row['referensi_tipe'];
    $ref_id = $row['referensi_id'];
    $detail_info = '';
    
    if ($row['referensi_tipe'] == 'transfer') {
        $r_trf = $conn->query("SELECT nomor_transfer, dari_level, dari_id, ke_level, ke_id FROM inventory_transfer_barang WHERE id = $ref_id LIMIT 1");
        if ($r_trf && ($trf = $r_trf->fetch_assoc())) {
            $ref_label = $trf['nomor_transfer'];
            if ($tipe == 'in') {
                $source = $trf['dari_level'] == 'klinik' ? 'Klinik' : 'Gudang';
                if ($trf['dari_level'] == 'klinik') {
                    $r_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = " . (int)$trf['dari_id']);
                    if ($r_k && ($k = $r_k->fetch_assoc())) $source = $k['nama_klinik'];
                }
                $detail_info = "Dari: " . $source;
            } else {
                $dest = $trf['ke_level'] == 'klinik' ? 'Klinik' : 'Gudang';
                if ($trf['ke_level'] == 'klinik') {
                    $r_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = " . (int)$trf['ke_id']);
                    if ($r_k && ($k = $r_k->fetch_assoc())) $dest = $k['nama_klinik'];
                }
                $detail_info = "Ke: " . $dest;
            }
        }
    } elseif ($row['referensi_tipe'] == 'pemakaian_bhp') {
        $r_pb = $conn->query("SELECT nomor_pemakaian, jenis_pemakaian FROM inventory_pemakaian_bhp WHERE id = $ref_id LIMIT 1");
        if ($r_pb && ($pb = $r_pb->fetch_assoc())) {
            $ref_label = $pb['nomor_pemakaian'];
            $detail_info = "Pemakaian " . ($pb['jenis_pemakaian'] == 'hc' ? 'HC' : 'Onsite');
        }
    } elseif ($row['referensi_tipe'] == 'hc_petugas_transfer') {
        $r_hc = $conn->query("SELECT u.nama_lengkap FROM inventory_hc_petugas_transfer hpt JOIN inventory_users u ON hpt.user_hc_id = u.id WHERE hpt.id = $ref_id LIMIT 1");
        if ($r_hc && ($hc = $r_hc->fetch_assoc())) {
            $ref_label = "Transfer HC";
            if ($tipe == 'in') $detail_info = "Dari: " . $hc['nama_lengkap'];
            else $detail_info = "Ke: " . $hc['nama_lengkap'];
        }
    }
    
    $data[] = [
        'tanggal' => date('d M Y H:i', strtotime($row['created_at'])),
        'qty' => (float)$row['qty'],
        'referensi' => $ref_label,
        'detail' => $detail_info,
        'petugas' => $row['creator_name'] ?? '-'
    ];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => $data]);
