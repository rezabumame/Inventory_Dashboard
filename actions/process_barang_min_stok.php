<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang'], true)) {
    $_SESSION['error'] = 'Anda tidak memiliki akses.';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=barang');

$barang_id = (int)($_POST['barang_id'] ?? 0);
$stok_minimum_raw = $_POST['stok_minimum'] ?? '0';
$stok_minimum = (int)$stok_minimum_raw;
if ($barang_id <= 0 || $stok_minimum < 0) {
    $_SESSION['error'] = 'Data tidak valid.';
    redirect('index.php?page=barang');
}

try {
    $res = $conn->query("SHOW COLUMNS FROM `barang` LIKE 'stok_minimum'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `barang` ADD COLUMN `stok_minimum` INT NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
}

try {
    $stmt = $conn->prepare("UPDATE barang SET stok_minimum = ? WHERE id = ?");
    $stmt->bind_param("ii", $stok_minimum, $barang_id);
    $stmt->execute();
    $_SESSION['success'] = 'Min stok berhasil diperbarui.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Gagal update min stok: ' . $e->getMessage();
}

redirect('index.php?page=barang');

