<?php
session_start();
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
            b.nama_barang, 
            b.kode_barang,
            SUM(pbd.qty) as total_qty,
            MAX(pbd.satuan) as satuan,
            GROUP_CONCAT(DISTINCT bp.nomor_booking SEPARATOR ', ') as referensi_booking
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
        JOIN inventory_barang b ON pbd.barang_id = b.id
        LEFT JOIN inventory_booking_pemeriksaan bp ON pb.booking_id = bp.id
        WHERE pb.klinik_id = ? 
          AND pb.is_auto = 1 
          AND DATE(pb.tanggal) = ?
          AND pb.jenis_pemakaian = ?
        GROUP BY pbd.barang_id, b.nama_barang, b.kode_barang
        HAVING total_qty > 0
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $klinik_id, $date_only, $jenis_pemakaian);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'barang_id' => (int)$row['barang_id'],
            'nama_barang' => $row['nama_barang'],
            'kode_barang' => $row['kode_barang'],
            'qty' => (float)$row['total_qty'],
            'satuan' => $row['satuan'],
            'referensi' => $row['referensi_booking'] ? 'Auto: ' . $row['referensi_booking'] : 'Auto Deduction'
        ];
    }

    echo json_encode(['success' => true, 'data' => $items]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
