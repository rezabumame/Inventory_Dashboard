<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_klinik', 'cs'])) {
    echo json_encode([
        "draw" => 0,
        "recordsTotal" => 0,
        "recordsFiltered" => 0,
        "data" => [],
        "error" => "Unauthorized access"
    ]);
    exit;
}

$draw = (int)($_POST['draw'] ?? 0);
$start = (int)($_POST['start'] ?? 0);
$length = (int)($_POST['length'] ?? 10);
$search = trim((string)($_POST['search']['value'] ?? ''));

// Column mapping for sorting (matches table header indexing)
$columns = [
    0 => 'id',                // Expanding icon (dummy sort by ID)
    1 => 'id',                // ID Paket
    2 => 'nama_pemeriksaan',  // Nama Paket
    3 => 'id',                // Total Items (sort by ID)
    4 => 'id',                // Aksi (sort by ID)
    5 => 'created_at'         // Hidden Created At
];

$order_col_idx = (int)($_POST['order'][0]['column'] ?? 5);
$order_dir = (strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_by = $columns[$order_col_idx] ?? 'created_at';

// Base counting
$res_total = $conn->query("SELECT COUNT(*) as total FROM inventory_pemeriksaan_grup");
$recordsTotal = (int)($res_total->fetch_assoc()['total'] ?? 0);

// Search filtering
$where_clause = "1=1";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where_clause .= " AND (id LIKE '%$s%' OR nama_pemeriksaan LIKE '%$s%')";
}

$res_filtered = $conn->query("SELECT COUNT(*) as total FROM inventory_pemeriksaan_grup WHERE $where_clause");
$recordsFiltered = (int)($res_filtered->fetch_assoc()['total'] ?? 0);

// Fetching data
$sql = "
    SELECT 
        id, 
        nama_pemeriksaan, 
        created_at,
        (SELECT COUNT(*) FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = inventory_pemeriksaan_grup.id) as total_items
    FROM inventory_pemeriksaan_grup
    WHERE $where_clause
    ORDER BY $order_by $order_dir
    LIMIT $start, $length
";

$result = $conn->query($sql);
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id' => $row['id'],
        'nama_pemeriksaan' => $row['nama_pemeriksaan'],
        'total_items' => (int)$row['total_items'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $recordsTotal,
    "recordsFiltered" => $recordsFiltered,
    "data" => $data
]);
