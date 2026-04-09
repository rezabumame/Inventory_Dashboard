<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang'], true)) {
    die("Access denied");
}

// Default Dates: First and Last day of current month
$start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-t');
$barang_id = isset($_GET['barang_id']) ? $_GET['barang_id'] : '';
$tipe = isset($_GET['tipe']) ? $_GET['tipe'] : '';

// Build Query
$sql = "SELECT t.*, b.kode_barang, b.nama_barang, u.username as user_name
        FROM inventory_transaksi_stok t
        JOIN inventory_barang b ON t.barang_id = b.id
        LEFT JOIN inventory_users u ON t.created_by = u.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = "ss";

if (!empty($barang_id)) {
    $sql .= " AND t.barang_id = ?";
    $params[] = $barang_id;
    $types .= "i";
}
if (!empty($tipe)) {
    $sql .= " AND t.tipe_transaksi = ?";
    $params[] = $tipe;
    $types .= "s";
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$filename = "Export_Transaksi_Stok_" . date('YmdHis') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Output UTF-8 BOM for Excel to open symbols properly
echo "\xEF\xBB\xBF";

echo '<table border="1">';
echo '<tr>';
echo '<th>Tanggal & Waktu</th>';
echo '<th>Kode Barang</th>';
echo '<th>Nama Barang</th>';
echo '<th>Tipe</th>';
echo '<th>Qty</th>';
echo '<th>Stok Awal</th>';
echo '<th>Stok Akhir</th>';
echo '<th>Level</th>';
echo '<th>Ref Tipe</th>';
echo '<th>Ref ID</th>';
echo '<th>User</th>';
echo '</tr>';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tipe_label = ($row['tipe_transaksi'] == 'in') ? 'Masuk (IN)' : 'Keluar (OUT)';
        $qty_sign = ($row['tipe_transaksi'] == 'in') ? '+' : '-';
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['created_at'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['kode_barang'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['nama_barang'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($tipe_label) . '</td>';
        echo '<td>' . htmlspecialchars($qty_sign . number_format($row['qty'])) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($row['qty_sebelum'])) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($row['qty_sesudah'])) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['level'] ?? '-'))) . '</td>';
        echo '<td>' . htmlspecialchars($row['referensi_tipe'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['referensi_id'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['user_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="11">Tidak ada data</td></tr>';
}
echo '</table>';
?>
