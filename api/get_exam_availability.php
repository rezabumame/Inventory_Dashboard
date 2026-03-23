<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        from_uom VARCHAR(20) NULL,
        to_uom VARCHAR(20) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$klinik_id = isset($_GET['klinik_id']) ? (int)$_GET['klinik_id'] : 0;
$status_booking = (string)($_GET['status_booking'] ?? '');

if ($klinik_id == 0) {
    echo json_encode([]);
    exit;
}

$klin = $conn->query("SELECT kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$kode_klinik = (string)($klin['kode_klinik'] ?? '');
$kode_homecare = (string)($klin['kode_homecare'] ?? '');

$is_hc = (stripos($status_booking, 'HC') !== false);
$location_code = $is_hc ? $kode_homecare : $kode_klinik;
if ($location_code === '') {
    echo json_encode([]);
    exit;
}

// Get stock data from Odoo mirror for this location (map to barang_id)
$stok_data = [];
$loc_esc = $conn->real_escape_string($location_code);
$res_stok = $conn->query("
    SELECT b.id AS barang_id, COALESCE(MAX(sm.qty), 0) * COALESCE(uc.multiplier, 1) AS qty
    FROM stock_mirror sm
    JOIN barang b ON b.odoo_product_id = sm.odoo_product_id
    LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
    WHERE sm.location_code = '$loc_esc'
    GROUP BY b.id
");
while ($res_stok && ($row = $res_stok->fetch_assoc())) {
    $stok_data[(int)$row['barang_id']] = (float)($row['qty'] ?? 0);
}

// Get all exams and their recipes
$recipes = [];
$exams = [];
$res_exams = $conn->query("SELECT * FROM pemeriksaan_grup ORDER BY nama_pemeriksaan");
while($ex = $res_exams->fetch_assoc()) {
    $exams[$ex['id']] = $ex['nama_pemeriksaan'];
    $res_det = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = " . $ex['id']);
    $recipes[$ex['id']] = [];
    while($d = $res_det->fetch_assoc()) {
        $recipes[$ex['id']][] = $d;
    }
}

// Calculate availability
$availability = [];
foreach ($exams as $eid => $ename) {
    $max_possible = 999999;
    $is_possible = true;
    
    if (!isset($recipes[$eid]) || empty($recipes[$eid])) {
        continue; // Skip if no recipe
    }
    
    foreach ($recipes[$eid] as $ing) {
        $bid = $ing['barang_id'];
        $req = $ing['qty_per_pemeriksaan'];
        
        $have = isset($stok_data[$bid]) ? $stok_data[$bid] : 0;
        
        if ($have < $req) {
            $is_possible = false;
            break;
        } else {
            $possible = floor($have / $req);
            if ($possible < $max_possible) $max_possible = $possible;
        }
    }
    
    // Only add if available
    if ($is_possible && $max_possible > 0) {
        $availability[] = [
            'id' => $eid,
            'name' => $ename,
            'qty' => $max_possible
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($availability);
$conn->close();
?>
