<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Check access
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$klinik_id = isset($_GET['klinik_id']) ? (int)$_GET['klinik_id'] : 0;
$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$jenis_pemakaian = isset($_GET['jenis']) ? $_GET['jenis'] : 'klinik';

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Klinik tidak valid']);
    exit;
}

// Hanya mendukung auto deduction untuk klinik saat ini (karena booking CS umumnya memotong onsite)
// Tapi kita akan query berdasarkan jenis pemakaian jika ada
$date_only = date('Y-m-d', strtotime($tanggal));

try {
    $query = "
        SELECT 
            pbd.barang_id, 
            pbd.is_lokal,
            COALESCE(b.nama_barang, bl.nama_item) as nama_barang, 
            COALESCE(b.kode_barang, '') as kode_barang,
            SUM(pbd.qty) as total_qty,
            MAX(pbd.satuan) as satuan,
            GROUP_CONCAT(DISTINCT bp.nomor_booking SEPARATOR ', ') as referensi_booking,
            GROUP_CONCAT(DISTINCT pb.id SEPARATOR ',') as source_ids
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
        LEFT JOIN inventory_barang b ON pbd.barang_id = b.id AND pbd.is_lokal = 0
        LEFT JOIN inventory_barang_lokal bl ON pbd.barang_id = bl.id AND pbd.is_lokal = 1
        LEFT JOIN inventory_booking_pemeriksaan bp ON pb.booking_id = bp.id
        WHERE pb.klinik_id = ? 
          AND pb.is_auto = 1 
          AND DATE(pb.tanggal) = ?
          AND (pb.jenis_pemakaian = ? OR (pb.jenis_pemakaian = 'clinic' AND ? = 'klinik') OR (pb.jenis_pemakaian = 'klinik' AND ? = 'clinic'))
        GROUP BY pbd.barang_id, pbd.is_lokal
        HAVING total_qty > 0
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("issss", $klinik_id, $date_only, $jenis_pemakaian, $jenis_pemakaian, $jenis_pemakaian);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'barang_id' => (int)$row['barang_id'],
            'is_lokal' => (int)$row['is_lokal'],
            'nama_barang' => $row['nama_barang'] . ($row['is_lokal'] ? ' (Lokal)' : ''),
            'kode_barang' => $row['kode_barang'],
            'qty' => (float)$row['total_qty'],
            'satuan' => $row['satuan'],
            'referensi' => $row['referensi_booking'] ? 'Auto: ' . $row['referensi_booking'] : 'Auto Deduction',
            'source_ids' => $row['source_ids']
        ];
    }

    echo json_encode(['success' => true, 'data' => $items]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
