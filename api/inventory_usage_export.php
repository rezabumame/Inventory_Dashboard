<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

check_role(['super_admin', 'admin_gudang']);

$klinik_ids = isset($_GET['klinik_ids']) ? $_GET['klinik_ids'] : [];
if (empty($klinik_ids)) {
    die("Pilih minimal satu klinik");
}

// Handle 'all' option
if (in_array('all', $klinik_ids)) {
    $res_all = $conn->query("SELECT id FROM inventory_klinik WHERE status='active'");
    $klinik_ids = [];
    while($k = $res_all->fetch_assoc()) $klinik_ids[] = (int)$k['id'];
} else {
    $klinik_ids = array_map('intval', $klinik_ids);
}

$ids_str = implode(',', $klinik_ids);

// Fetch All Items and their current Daily Usage Config for selected clinics
$query = "
    SELECT 
        k.id as klinik_id,
        k.nama_klinik,
        b.id as barang_id, 
        b.kode_barang, 
        b.nama_barang,
        c.id as config_id,
        c.mode,
        c.manual_value
    FROM inventory_klinik k
    CROSS JOIN inventory_barang b
    LEFT JOIN inventory_daily_usage_config c ON b.id = c.barang_id AND c.klinik_id = k.id
    WHERE k.id IN ($ids_str)
    ORDER BY k.nama_klinik ASC, b.nama_barang ASC
";

$res = $conn->query($query);
if (!$res) {
    die("Query Error: " . $conn->error);
}

$data = [
    ['Klinik ID', 'Nama Klinik', 'Config ID', 'Kode Barang', 'Nama Barang', 'Mode (auto/manual)', 'Manual Daily Usage']
];

while($row = $res->fetch_assoc()) {
    $data[] = [
        (int)$row['klinik_id'],
        $row['nama_klinik'],
        (int)($row['config_id'] ?? 0),
        $row['kode_barang'],
        $row['nama_barang'],
        $row['mode'] ?? 'auto',
        (float)($row['manual_value'] ?? 0)
    ];
}

$filename = "Daily_Usage_Template_Multi_" . date('Ymd_His') . ".xlsx";

// Output Excel
SimpleXLSXGen::fromArray($data)->downloadAs($filename);
exit;
