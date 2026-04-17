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

if ($role === 'super_admin') {
    // Super admin: gabungkan gudang utama + seluruh lokasi mirror (klinik/homecare)
    $sql = "SELECT
                b.id,
                b.kode_barang,
                b.nama_barang,
                s.qty as stok_saat_ini,
                b.stok_minimum,
                'Gudang Utama' AS lokasi,
                'gudang' AS tipe_lokasi
            FROM inventory_stok_gudang_utama s
            JOIN inventory_barang b ON s.barang_id = b.id
            WHERE s.qty <= b.stok_minimum
            ORDER BY b.nama_barang ASC";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }

    $sql = "SELECT
                b.id,
                b.kode_barang,
                b.nama_barang,
                sm.qty as stok_saat_ini,
                b.stok_minimum,
                CASE
                    WHEN TRIM(sm.location_code) = 'WHS01/Stock' THEN 'Gudang Utama'
                    ELSE COALESCE(k1.nama_klinik, k2.nama_klinik, TRIM(sm.location_code), '-')
                END AS lokasi,
                CASE
                    WHEN TRIM(sm.location_code) = 'WHS01/Stock' THEN 'gudang'
                    WHEN k2.id IS NOT NULL THEN 'hc'
                    WHEN k1.id IS NOT NULL THEN 'onsite'
                    ELSE 'onsite'
                END AS tipe_lokasi
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang)
            LEFT JOIN inventory_klinik k1 ON TRIM(sm.location_code) = TRIM(k1.kode_klinik)
            LEFT JOIN inventory_klinik k2 ON TRIM(sm.location_code) = TRIM(k2.kode_homecare)
            WHERE sm.qty <= b.stok_minimum
            ORDER BY lokasi ASC, b.nama_barang ASC";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
} else if ($role === 'admin_gudang') {
    // Admin gudang: khusus gudang utama
    $sql = "SELECT
                b.id,
                b.kode_barang,
                b.nama_barang,
                s.qty as stok_saat_ini,
                b.stok_minimum,
                'Gudang Utama' AS lokasi,
                'gudang' AS tipe_lokasi
            FROM inventory_stok_gudang_utama s
            JOIN inventory_barang b ON s.barang_id = b.id
            WHERE s.qty <= b.stok_minimum
            ORDER BY b.nama_barang ASC";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
} else if (in_array($role, ['admin_klinik', 'cs', 'spv_klinik'])) {
    // Klinik
    // Get clinic + homecare codes first
    $stmt = $conn->prepare("SELECT kode_klinik, kode_homecare, nama_klinik FROM inventory_klinik WHERE id = ?");
    $stmt->bind_param("i", $klinik_id);
    $stmt->execute();
    $klinik_row = $stmt->get_result()->fetch_assoc();
    $kode_klinik = trim((string)($klinik_row['kode_klinik'] ?? ''));
    $kode_homecare = trim((string)($klinik_row['kode_homecare'] ?? ''));
    $nama_klinik = trim((string)($klinik_row['nama_klinik'] ?? 'Klinik'));
    
    if ($kode_klinik) {
        $mirror_where = "TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_klinik) . "'";
        if ($kode_homecare !== '') {
            $mirror_where = "(" . $mirror_where . " OR TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare) . "')";
        }

        $sql = "SELECT 
                    b.id,
                    b.kode_barang, 
                    b.nama_barang, 
                    sm.qty as stok_saat_ini, 
                    b.stok_minimum,
                    CASE
                        WHEN TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare) . "' AND '" . $conn->real_escape_string($kode_homecare) . "' <> ''
                            THEN CONCAT('" . $conn->real_escape_string($nama_klinik) . "', ' (HC)')
                        ELSE CONCAT('" . $conn->real_escape_string($nama_klinik) . "', ' (Klinik)')
                    END AS lokasi,
                    CASE
                        WHEN TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare) . "' AND '" . $conn->real_escape_string($kode_homecare) . "' <> ''
                            THEN 'hc'
                        ELSE 'onsite'
                    END AS tipe_lokasi
                FROM inventory_stock_mirror sm 
                JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) 
                WHERE $mirror_where
                AND sm.qty <= b.stok_minimum 
                ORDER BY b.nama_barang ASC";
        $res = $conn->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
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
