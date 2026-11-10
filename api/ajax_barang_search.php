<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$items = [];

if ($q !== '') {
    $q_esc = $conn->real_escape_string($q);
    $res = $conn->query("
        SELECT id, kode_barang, nama_barang
        FROM inventory_barang
        WHERE kode_barang LIKE '%$q_esc%' OR nama_barang LIKE '%$q_esc%'
        ORDER BY nama_barang ASC
        LIMIT 50
    ");
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'kode_barang' => (string)$row['kode_barang'],
            'nama_barang' => (string)$row['nama_barang'],
        ];
    }
}

echo json_encode(['success' => true, 'items' => $items]);
