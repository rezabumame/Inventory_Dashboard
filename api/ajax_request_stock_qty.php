<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
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
require_csrf();

$ke_level = (string)($_POST['ke_level'] ?? '');
$ke_id = (int)($_POST['ke_id'] ?? 0);
$barang_id = (int)($_POST['barang_id'] ?? 0);

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid barang_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

function location_candidates(string $input): array {
    $input = trim($input);
    if ($input === '') return [];

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
    return $prefer;
}

$location_candidates = [];
if ($ke_level === 'klinik') {
    if ($ke_id <= 0) {
        echo json_encode(['success' => true, 'qty' => 0, 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = $conn->query("SELECT kode_klinik FROM klinik WHERE id = $ke_id LIMIT 1");
    $kode_klinik = '';
    if ($r && $r->num_rows > 0) $kode_klinik = (string)($r->fetch_assoc()['kode_klinik'] ?? '');
    if ($kode_klinik === '') {
        echo json_encode(['success' => true, 'qty' => 0, 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $location_candidates = location_candidates($kode_klinik);
} elseif ($ke_level === 'gudang_utama') {
    $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    if ($gudang_loc === '') {
        $stmt = $conn->prepare("SELECT qty FROM stok_gudang_utama WHERE barang_id = ? LIMIT 1");
        $stmt->bind_param("i", $barang_id);
        $stmt->execute();
        $qty = (int)($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
        echo json_encode(['success' => true, 'qty' => $qty, 'location_code' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $location_candidates = location_candidates($gudang_loc);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid ke_level'], JSON_UNESCAPED_UNICODE);
    exit;
}

$b = $conn->query("SELECT kode_barang, odoo_product_id FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
$kode_barang = trim((string)($b['kode_barang'] ?? ''));
$odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));

$conv = $conn->query("SELECT multiplier FROM barang_uom_conversion WHERE barang_id = $barang_id LIMIT 1")->fetch_assoc();
$multiplier = (float)($conv['multiplier'] ?? 1);
if ($multiplier <= 0) $multiplier = 1;

$uom = '';
$r_uom = $conn->query("SELECT COALESCE(uc.to_uom, b.satuan) AS satuan FROM barang b LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id WHERE b.id = $barang_id LIMIT 1");
if ($r_uom && $r_uom->num_rows > 0) $uom = (string)($r_uom->fetch_assoc()['satuan'] ?? '');

$kb_esc = $conn->real_escape_string($kode_barang);
$oid_esc = $conn->real_escape_string($odoo_product_id);

$clauses = [];
if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '$kb_esc'";
if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '$oid_esc'";

$best_qty = 0;
$best_loc = '';
foreach ($location_candidates as $loc) {
    $loc_esc = $conn->real_escape_string(trim($loc));
    $where = "TRIM(location_code) = '$loc_esc' AND (" . implode(' OR ', $clauses) . ")";
    $res = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE $where");
    $q = (int)floor((float)($res && $res->num_rows > 0 ? ($res->fetch_assoc()['qty'] ?? 0) : 0));
    if ($q > $best_qty) {
        $best_qty = $q;
        $best_loc = $loc;
    }
}

$qty = (float)$best_qty / $multiplier;
echo json_encode(['success' => true, 'qty' => (float)round($qty, 4), 'satuan' => $uom, 'location_code' => $best_loc], JSON_UNESCAPED_UNICODE);

