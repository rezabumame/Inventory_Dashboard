<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$source = trim((string)($_GET['source'] ?? ''));
$klinik_id = isset($_GET['klinik_id']) ? (int)$_GET['klinik_id'] : 0;

$allowed = ['admin_logistik', 'nakes', 'sistem_integrasi'];
if (!in_array($source, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Sumber perubahan tidak valid']);
    exit;
}

$where = "status = 'active'";
if ($source === 'admin_logistik') {
    // Keputusan: admin logistik = admin_klinik.
    $where .= " AND role = 'admin_klinik'";
    if ($klinik_id > 0) {
        $where .= " AND klinik_id = $klinik_id";
    }
} elseif ($source === 'nakes') {
    // Keputusan: nakes = petugas_hc.
    $where .= " AND role = 'petugas_hc'";
    if ($klinik_id > 0) {
        $where .= " AND klinik_id = $klinik_id";
    }
}

$sql = "
    SELECT id, nama_lengkap, role
    FROM inventory_users
    WHERE $where
    ORDER BY nama_lengkap ASC
    LIMIT 2000
";

$res = $conn->query($sql);
$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'nama_lengkap' => (string)$row['nama_lengkap'],
            'role' => (string)$row['role'],
        ];
    }
}

echo json_encode(['success' => true, 'items' => $items]);
