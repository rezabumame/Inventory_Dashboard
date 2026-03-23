<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$ke_level = (string)($_POST['ke_level'] ?? '');
$ke_id = (int)($_POST['ke_id'] ?? 0);

$stock = [];

function resolve_location_code(mysqli $conn, string $input): string {
    $input = trim($input);
    if ($input === '') return '';

    $candidates = [];
    $candidates[] = $input;

    if (stripos($input, '/stock') === false && strpos($input, '/') === false) {
        $candidates[] = $input . '/Stock';
    }
    if (preg_match('/\/Stock$/i', $input)) {
        $candidates[] = preg_replace('/\/Stock$/i', '', $input);
    }

    $seen = [];
    $uniq = [];
    foreach ($candidates as $c) {
        $k = strtolower($c);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $uniq[] = $c;
    }

    $prefer = [];
    foreach ($uniq as $c) {
        if (preg_match('/\/Stock$/i', $c)) $prefer[] = $c;
    }
    foreach ($uniq as $c) {
        if (!preg_match('/\/Stock$/i', $c)) $prefer[] = $c;
    }

    foreach ($prefer as $c) {
        $esc = $conn->real_escape_string($c);
        $r = $conn->query("SELECT 1 FROM stock_mirror WHERE location_code = '$esc' LIMIT 1");
        if ($r && $r->num_rows > 0) return $c;
    }

    return $input;
}

if ($ke_level === 'klinik') {
    if ($ke_id <= 0) {
        echo json_encode(['success' => true, 'stock' => $stock], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = $conn->query("SELECT kode_klinik FROM klinik WHERE id = $ke_id LIMIT 1");
    $kode_klinik = '';
    if ($r && $r->num_rows > 0) $kode_klinik = (string)($r->fetch_assoc()['kode_klinik'] ?? '');
    if ($kode_klinik === '') {
        echo json_encode(['success' => true, 'stock' => $stock, 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $resolved_loc = resolve_location_code($conn, $kode_klinik);
    $loc = $conn->real_escape_string($resolved_loc);
    $res = $conn->query("
        SELECT 
            b.id AS barang_id,
            COALESCE(MAX(sm.qty), 0) AS qty
        FROM barang b
        LEFT JOIN stock_mirror sm 
            ON sm.location_code = '$loc'
            AND (
                (b.odoo_product_id IS NOT NULL AND TRIM(b.odoo_product_id) <> '' AND TRIM(sm.odoo_product_id) = TRIM(b.odoo_product_id))
                OR (b.kode_barang IS NOT NULL AND b.kode_barang <> '' AND sm.kode_barang = b.kode_barang)
                OR (sm.kode_barang = CAST(b.id AS CHAR))
            )
        GROUP BY b.id
        HAVING qty > 0
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $stock[(int)$row['barang_id']] = (int)floor((float)$row['qty']);
    }
    echo json_encode(['success' => true, 'stock' => $stock, 'location_code' => $resolved_loc], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ke_level === 'gudang_utama') {
    $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc !== '') {
        $resolved_loc = resolve_location_code($conn, $gudang_loc);
        $loc = $conn->real_escape_string($resolved_loc);
        $res = $conn->query("
            SELECT 
                b.id AS barang_id,
                COALESCE(MAX(sm.qty), 0) AS qty
            FROM barang b
            LEFT JOIN stock_mirror sm 
                ON sm.location_code = '$loc'
                AND (
                    (b.odoo_product_id IS NOT NULL AND TRIM(b.odoo_product_id) <> '' AND TRIM(sm.odoo_product_id) = TRIM(b.odoo_product_id))
                    OR (b.kode_barang IS NOT NULL AND b.kode_barang <> '' AND sm.kode_barang = b.kode_barang)
                    OR (sm.kode_barang = CAST(b.id AS CHAR))
                )
            GROUP BY b.id
            HAVING qty > 0
        ");
        while ($res && ($row = $res->fetch_assoc())) {
            $stock[(int)$row['barang_id']] = (int)floor((float)$row['qty']);
        }
        echo json_encode(['success' => true, 'stock' => $stock, 'location_code' => $resolved_loc], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $res = $conn->query("SELECT barang_id, qty FROM stok_gudang_utama");
        while ($res && ($row = $res->fetch_assoc())) {
            $stock[(int)$row['barang_id']] = (int)$row['qty'];
        }
        echo json_encode(['success' => true, 'stock' => $stock, 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid ke_level'], JSON_UNESCAPED_UNICODE);
