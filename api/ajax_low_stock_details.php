<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$items = [];

if (in_array($role, ['super_admin', 'admin_gudang'])) {
    // Gudang Utama
    $sql = "SELECT 
                b.id,
                b.kode_barang, 
                b.nama_barang, 
                s.qty as stok_saat_ini, 
                b.stok_minimum 
            FROM inventory_stok_gudang_utama s 
            JOIN inventory_barang b ON s.barang_id = b.id 
            WHERE s.qty <= b.stok_minimum 
            ORDER BY b.nama_barang ASC";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
} else if (in_array($role, ['admin_klinik', 'cs', 'spv_klinik'])) {
    // Klinik
    // Get location code first
    $stmt = $conn->prepare("SELECT kode_klinik FROM inventory_klinik WHERE id = ?");
    $stmt->bind_param("i", $klinik_id);
    $stmt->execute();
    $kode_klinik = (string)($stmt->get_result()->fetch_assoc()['kode_klinik'] ?? '');
    
    if ($kode_klinik) {
        $sql = "SELECT 
                    b.id,
                    b.kode_barang, 
                    b.nama_barang, 
                    sm.qty as stok_saat_ini, 
                    b.stok_minimum 
                FROM inventory_stock_mirror sm 
                JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) 
                WHERE TRIM(sm.location_code) = '$kode_klinik' -- Direct injection safe since it's from DB
                AND sm.qty <= b.stok_minimum 
                ORDER BY b.nama_barang ASC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Role not authorized for this view']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $items
]);
