<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

$argv = $argv ?? [];

function argv_value(array $argv, string $prefix): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $prefix . '=')) return substr($a, strlen($prefix) + 1);
    }
    return null;
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

$klinik_id = (int)(argv_value($argv, '--klinik_id') ?? 0);
$klinik_like = (string)(argv_value($argv, '--klinik_like') ?? '');
$barang_id = (int)(argv_value($argv, '--barang_id') ?? 0);
$kode = (string)(argv_value($argv, '--kode') ?? '');
$odoo = (string)(argv_value($argv, '--odoo') ?? '');
$barang_like = (string)(argv_value($argv, '--barang_like') ?? '');

if ($klinik_id <= 0 && $klinik_like !== '') {
    $like = $conn->real_escape_string('%' . $klinik_like . '%');
    $r = $conn->query("SELECT id FROM klinik WHERE nama_klinik LIKE '$like' ORDER BY (status='active') DESC, id ASC LIMIT 1");
    if ($r && $r->num_rows > 0) $klinik_id = (int)$r->fetch_assoc()['id'];
}

if ($barang_id <= 0) {
    $conds = [];
    if ($kode !== '') $conds[] = "kode_barang = '" . $conn->real_escape_string($kode) . "'";
    if ($odoo !== '') $conds[] = "odoo_product_id = '" . $conn->real_escape_string($odoo) . "'";
    if ($barang_like !== '') $conds[] = "nama_barang LIKE '" . $conn->real_escape_string('%' . $barang_like . '%') . "'";
    if (!empty($conds)) {
        $where = implode(' OR ', $conds);
        $r = $conn->query("SELECT id FROM barang WHERE $where ORDER BY id ASC LIMIT 1");
        if ($r && $r->num_rows > 0) $barang_id = (int)$r->fetch_assoc()['id'];
    }
}

$out = [
    'input' => [
        'klinik_id' => $klinik_id,
        'klinik_like' => $klinik_like,
        'barang_id' => $barang_id,
        'kode' => $kode,
        'odoo' => $odoo,
        'barang_like' => $barang_like
    ],
    'klinik' => null,
    'barang' => null,
    'location_candidates' => [],
    'mirror_hits' => [],
    'mirror_row_samples' => []
];

if ($klinik_id > 0) {
    $r = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare, status FROM klinik WHERE id = $klinik_id LIMIT 1");
    if ($r && $r->num_rows > 0) $out['klinik'] = $r->fetch_assoc();
}
if ($barang_id > 0) {
    $r = $conn->query("SELECT id, odoo_product_id, kode_barang, nama_barang, satuan FROM barang WHERE id = $barang_id LIMIT 1");
    if ($r && $r->num_rows > 0) $out['barang'] = $r->fetch_assoc();
}

$kode_klinik = trim((string)($out['klinik']['kode_klinik'] ?? ''));
$odoo_product_id = trim((string)($out['barang']['odoo_product_id'] ?? ''));
$kode_barang = trim((string)($out['barang']['kode_barang'] ?? ''));

if ($kode_klinik !== '') {
    $out['location_candidates'] = location_candidates($kode_klinik);
}

foreach ($out['location_candidates'] as $loc) {
    $loc_esc = $conn->real_escape_string($loc);
    $count = (int)($conn->query("SELECT COUNT(*) AS c FROM stock_mirror WHERE TRIM(location_code) = '$loc_esc'")->fetch_assoc()['c'] ?? 0);
    $out['mirror_row_samples'][$loc] = [
        'rows' => $count,
        'top_3' => []
    ];
    $res = $conn->query("SELECT odoo_product_id, kode_barang, qty FROM stock_mirror WHERE TRIM(location_code) = '$loc_esc' ORDER BY qty DESC LIMIT 3");
    while ($res && ($row = $res->fetch_assoc())) $out['mirror_row_samples'][$loc]['top_3'][] = $row;

    $clauses = [];
    if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($odoo_product_id) . "'";
    if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kode_barang) . "'";
    $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string((string)$barang_id) . "'";
    $where = "TRIM(location_code) = '$loc_esc' AND (" . implode(' OR ', $clauses) . ")";

    $q = $conn->query("SELECT odoo_product_id, kode_barang, qty FROM stock_mirror WHERE $where ORDER BY qty DESC LIMIT 10");
    $hits = [];
    while ($q && ($row = $q->fetch_assoc())) $hits[] = $row;
    $out['mirror_hits'][$loc] = $hits;
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

