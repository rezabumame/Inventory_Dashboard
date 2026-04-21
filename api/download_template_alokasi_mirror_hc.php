<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik', 'admin_gudang'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
$petugas_user_id = (int)($_GET['petugas_user_id'] ?? 0);
$petugas_ids_str = (string)($_GET['petugas_ids'] ?? '');

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($klinik_id <= 0) {
    http_response_code(400);
    echo 'Invalid klinik_id';
    exit;
}

$kl = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    http_response_code(404);
    echo 'Klinik not found';
    exit;
}
$kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
if ($kode_homecare === '') {
    http_response_code(400);
    echo 'Klinik belum memiliki kode_homecare';
    exit;
}

$petugas = [];
if ($petugas_user_id > 0) {
    $u = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE id = $petugas_user_id AND role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id LIMIT 1")->fetch_assoc();
    if (!$u) {
        http_response_code(400);
        echo 'Petugas tidak valid';
        exit;
    }
    $petugas[] = $u;
} elseif ($petugas_ids_str !== '') {
    $ids = array_filter(array_map('intval', explode(',', $petugas_ids_str)));
    if (!empty($ids)) {
        $ids_sql = implode(',', $ids);
        $r = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE id IN ($ids_sql) AND role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id ORDER BY nama_lengkap ASC");
        while ($r && ($row = $r->fetch_assoc())) $petugas[] = $row;
    }
} else {
    $r = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id ORDER BY nama_lengkap ASC");
    while ($r && ($row = $r->fetch_assoc())) $petugas[] = $row;
}

if (empty($petugas)) {
    http_response_code(400);
    echo 'Belum ada petugas HC aktif di klinik ini';
    exit;
}

$loc = $conn->real_escape_string($kode_homecare);
$barang_rows = [];
$r = $conn->query("
    SELECT
        b.id AS barang_id,
        TRIM(COALESCE(b.kode_barang, '')) AS kode_barang,
        TRIM(COALESCE(b.odoo_product_id, '')) AS odoo_product_id,
        COALESCE(b.nama_barang, '') AS nama_barang,
        COALESCE(b.satuan, '') AS b_satuan,
        COALESCE(b.uom, '') AS b_uom,
        COALESCE(uc.multiplier, 1) AS multiplier,
        COALESCE(NULLIF(uc.to_uom,''), '') AS uom_oper,
        COALESCE(NULLIF(uc.from_uom,''), '') AS uom_odoo,
        COALESCE(SUM(sm.qty), 0) AS mirror_qty_raw
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    LEFT JOIN inventory_stock_mirror sm ON TRIM(sm.location_code) = '$loc'
        AND (
            (TRIM(COALESCE(b.odoo_product_id, '')) <> '' AND TRIM(sm.odoo_product_id) = TRIM(b.odoo_product_id))
            OR
            (TRIM(COALESCE(b.kode_barang, '')) <> '' AND TRIM(sm.kode_barang) = TRIM(b.kode_barang))
        )
    GROUP BY b.id, b.kode_barang, b.odoo_product_id, b.nama_barang, b.satuan, b.uom, uc.multiplier, uc.to_uom, uc.from_uom
    ORDER BY (TRIM(COALESCE(b.kode_barang,'')) = '') ASC, TRIM(COALESCE(b.kode_barang,'')) ASC, b.nama_barang ASC
");
while ($r && ($row = $r->fetch_assoc())) $barang_rows[] = $row;

$existing_map = [];
if (!empty($petugas)) {
    $ids = implode(',', array_map('intval', array_column($petugas, 'id')));
    $r = $conn->query("
        SELECT user_id, barang_id, COALESCE(qty, 0) AS qty
        FROM inventory_stok_tas_hc
        WHERE klinik_id = $klinik_id AND user_id IN ($ids)
    ");
    while ($r && ($row = $r->fetch_assoc())) {
        $uid = (int)($row['user_id'] ?? 0);
        $bid = (int)($row['barang_id'] ?? 0);
        $q = (float)($row['qty'] ?? 0);
        if ($uid <= 0 || $bid <= 0) continue;
        if (!isset($existing_map[$uid])) $existing_map[$uid] = [];
        $existing_map[$uid][$bid] = $q;
    }
}

$headers = ['Kode Barang', 'Nama Barang', 'Stok Gudang', 'Stok Tas', 'Satuan (UoM)', 'Qty Alokasi'];

$xlsx = null;
foreach ($petugas as $idx => $p) {
    $uid = (int)($p['id'] ?? 0);
    $sheet_name = trim((string)($p['nama_lengkap'] ?? 'Petugas'));
    if ($sheet_name === '') $sheet_name = 'Petugas';
    if (mb_strlen($sheet_name) > 31) $sheet_name = mb_substr($sheet_name, 0, 31);

    $rows = [];
    $rows[] = $headers;

    foreach ($barang_rows as $b) {
        $bid = (int)($b['barang_id'] ?? 0);
        if ($bid <= 0) continue;
        $kode_barang = trim((string)($b['kode_barang'] ?? ''));
        $odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));
        $item_code = $kode_barang !== '' ? $kode_barang : $odoo_product_id;
        $nama_barang = (string)($b['nama_barang'] ?? '');
        $mult = (float)($b['multiplier'] ?? 1);
        if ($mult <= 0) $mult = 1;
        $mirror_raw = (float)($b['mirror_qty_raw'] ?? 0);
        $uom_oper = trim((string)($b['uom_oper'] ?? ''));
        $uom_odoo = trim((string)($b['uom_odoo'] ?? ''));
        $b_satuan = trim((string)($b['b_satuan'] ?? ''));
        $b_uom = trim((string)($b['b_uom'] ?? ''));
        $use_oper = ($uom_oper !== '');
        $mirror = $mirror_raw; // Removed division to prevent "strange" conversion results (e.g., 0.03)
        $existing = (float)($existing_map[$uid][$bid] ?? 0); // Already in operational units if conversion exists

        // Determine all available units for this item
        $uoms_available = [];
        if ($uom_oper !== '') $uoms_available[] = $uom_oper;
        if ($uom_odoo !== '' && !in_array($uom_odoo, $uoms_available)) $uoms_available[] = $uom_odoo;
        if ($b_satuan !== '' && !in_array($b_satuan, $uoms_available)) $uoms_available[] = $b_satuan;
        if ($b_uom !== '' && !in_array($b_uom, $uoms_available)) $uoms_available[] = $b_uom;
        
        $satuan_info = implode(' / ', $uoms_available);
        if ($use_oper && abs($mult - 1) > 0.000001) {
            $satuan_info .= " [Detail Konversi: 1 $uom_oper = " . (float)$mult . " $uom_odoo]";
        }

        $rows[] = [$item_code, $nama_barang, $mirror, $existing, $satuan_info, ''];
    }

    if ($xlsx === null) {
        $xlsx = SimpleXLSXGen::fromArray($rows, $sheet_name);
    } else {
        $xlsx->addSheet($rows, $sheet_name);
    }
}

$filename = 'template_alokasi_mirror_hc_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($kl['nama_klinik'] ?? 'klinik')) . '_' . date('Ymd_His') . '.xlsx';
$xlsx->downloadAs($filename);
exit;


