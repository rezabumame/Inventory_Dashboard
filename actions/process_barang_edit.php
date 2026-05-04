<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');
require_csrf();

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang'], true)) {
    $_SESSION['error'] = 'Anda tidak memiliki akses.';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=barang');

$barang_id = (int)($_POST['barang_id'] ?? 0);
$stok_minimum = (int)($_POST['stok_minimum'] ?? 0);
$tipe = trim((string)($_POST['tipe'] ?? ''));

if (!in_array($tipe, ['Core', 'Support', ''], true)) {
    $tipe = null;
} elseif ($tipe === '') {
    $tipe = null;
}

if ($barang_id <= 0 || $stok_minimum < 0) {
    $_SESSION['error'] = 'Data tidak valid.';
    redirect('index.php?page=barang');
}

try {
    $stmt = $conn->prepare("UPDATE inventory_barang SET stok_minimum = ?, tipe = ? WHERE id = ?");
    $stmt->bind_param("isi", $stok_minimum, $tipe, $barang_id);
    $stmt->execute();
    $_SESSION['success'] = 'Data barang berhasil diperbarui.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Gagal update data barang: ' . $e->getMessage();
}

redirect('index.php?page=barang');
?>