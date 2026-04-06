<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
require_csrf();

$ke_level = (string)($_POST['ke_level'] ?? '');
$ke_id = (int)($_POST['ke_id'] ?? 0);

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
    foreach ($uniq as $c) if (preg_match('/\/Stock$/i', $c)) $prefer[] = $c;
    foreach ($uniq as $c) if (!preg_match('/\/Stock$/i', $c)) $prefer[] = $c;

    foreach ($prefer as $c) {
        $esc = $conn->real_escape_string($c);
        $r = $conn->query("SELECT 1 FROM inventory_stock_mirror WHERE location_code = '$esc' LIMIT 1");
        if ($r && $r->num_rows > 0) return $c;
    }

    return $input;
}

function find_or_create_barang_by_kode(mysqli $conn, string $kode_barang): array {
    $kode_barang = trim($kode_barang);
    if ($kode_barang === '') return ['id' => 0, 'nama_barang' => '-', 'satuan' => '', 'kode_barang' => null, 'odoo_product_id' => null];
    $kb = $conn->real_escape_string($kode_barang);
    $r = $conn->query("SELECT id, nama_barang, satuan, odoo_product_id, kode_barang FROM inventory_barang WHERE kode_barang = '$kb' ORDER BY id ASC LIMIT 1");
    if ($r && $r->num_rows > 0) return $r->fetch_assoc();
    $nama = $kode_barang;
    $satuan = 'Unit';
    $kategori = 'Odoo';
    $stmt = $conn->prepare("INSERT INTO inventory_barang (kode_barang, nama_barang, satuan, stok_minimum, kategori) VALUES (?, ?, ?, 0, ?)");
    $stmt->bind_param("ssss", $kode_barang, $nama, $satuan, $kategori);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    return ['id' => $id, 'nama_barang' => $nama, 'satuan' => $satuan, 'kode_barang' => $kode_barang, 'odoo_product_id' => null];
}

function conv_multiplier(mysqli $conn, int $barang_id): float {
    static $cache = [];
    if ($barang_id <= 0) return 1.0;
    if (isset($cache[$barang_id])) return (float)$cache[$barang_id];
    $r = $conn->query("
        SELECT c.multiplier 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $barang_id 
        LIMIT 1
    ");
    $m = 1.0;
    if ($r && $r->num_rows > 0) $m = (float)($r->fetch_assoc()['multiplier'] ?? 1);
    if ($m <= 0) $m = 1.0;
    $cache[$barang_id] = $m;
    return $m;
}

function conv_to_uom(mysqli $conn, int $barang_id, string $fallback): string {
    static $cache = [];
    if ($barang_id <= 0) return $fallback;
    if (isset($cache[$barang_id])) return (string)$cache[$barang_id];
    $r = $conn->query("
        SELECT COALESCE(c.to_uom, '') AS u 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $barang_id 
        LIMIT 1
    ");
    $u = '';
    if ($r && $r->num_rows > 0) $u = trim((string)($r->fetch_assoc()['u'] ?? ''));
    if ($u === '') $u = $fallback;
    $cache[$barang_id] = $u;
    return $u;
}

function conv_from_uom(mysqli $conn, int $barang_id): string {
    static $cache = [];
    if ($barang_id <= 0) return '';
    if (isset($cache[$barang_id])) return (string)$cache[$barang_id];
    $r = $conn->query("
        SELECT COALESCE(c.from_uom, '') AS u 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $barang_id 
        LIMIT 1
    ");
    $u = '';
    if ($r && $r->num_rows > 0) $u = trim((string)($r->fetch_assoc()['u'] ?? ''));
    $cache[$barang_id] = $u;
    return $u;
}

if ($ke_level === 'klinik') {
    if ($ke_id <= 0) {
        echo json_encode(['success' => true, 'items' => [], 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = $conn->query("SELECT kode_klinik FROM inventory_klinik WHERE id = $ke_id LIMIT 1");
    $kode_klinik = '';
    if ($r && $r->num_rows > 0) $kode_klinik = (string)($r->fetch_assoc()['kode_klinik'] ?? '');
    if ($kode_klinik === '') {
        echo json_encode(['success' => true, 'items' => [], 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $resolved_loc = resolve_location_code($conn, $kode_klinik);
    $loc = $conn->real_escape_string($resolved_loc);
    $res = $conn->query("SELECT kode_barang, qty FROM inventory_stock_mirror WHERE location_code = '$loc' AND qty > 0 AND TRIM(kode_barang) <> '' ORDER BY qty DESC");
    $items = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $b = find_or_create_barang_by_kode($conn, (string)($row['kode_barang'] ?? ''));
        $m = conv_multiplier($conn, (int)($b['id'] ?? 0));
        if ($m <= 0) $m = 1;
        $q = (float)($row['qty'] ?? 0) / $m;
        $uom = conv_to_uom($conn, (int)($b['id'] ?? 0), (string)($b['satuan'] ?? ''));
        $uom_odoo = conv_from_uom($conn, (int)($b['id'] ?? 0));
        $items[] = [
            'barang_id' => (int)($b['id'] ?? 0),
            'kode_barang' => (string)($row['kode_barang'] ?? ''),
            'nama_barang' => (string)($b['nama_barang'] ?? ''),
            'satuan' => (string)$uom,
            'uom_odoo' => (string)$uom_odoo,
            'uom_ratio' => (float)$m,
            'qty' => (float)round($q, 4)
        ];
    }
    echo json_encode(['success' => true, 'items' => $items, 'location_code' => $resolved_loc], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ke_level === 'gudang_utama') {
    $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc === '') {
        echo json_encode(['success' => true, 'items' => [], 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $resolved_loc = resolve_location_code($conn, $gudang_loc);
    $loc = $conn->real_escape_string($resolved_loc);
    $res = $conn->query("SELECT kode_barang, qty FROM inventory_stock_mirror WHERE location_code = '$loc' AND qty > 0 AND TRIM(kode_barang) <> '' ORDER BY qty DESC");
    $items = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $b = find_or_create_barang_by_kode($conn, (string)($row['kode_barang'] ?? ''));
        $m = conv_multiplier($conn, (int)($b['id'] ?? 0));
        if ($m <= 0) $m = 1;
        $q = (float)($row['qty'] ?? 0) / $m;
        $uom = conv_to_uom($conn, (int)($b['id'] ?? 0), (string)($b['satuan'] ?? ''));
        $uom_odoo = conv_from_uom($conn, (int)($b['id'] ?? 0));
        $items[] = [
            'barang_id' => (int)($b['id'] ?? 0),
            'kode_barang' => (string)($row['kode_barang'] ?? ''),
            'nama_barang' => (string)($b['nama_barang'] ?? ''),
            'satuan' => (string)$uom,
            'uom_odoo' => (string)$uom_odoo,
            'uom_ratio' => (float)$m,
            'qty' => (float)round($q, 4)
        ];
    }
    echo json_encode(['success' => true, 'items' => $items, 'location_code' => $resolved_loc], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid ke_level'], JSON_UNESCAPED_UNICODE);


