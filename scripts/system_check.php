<?php
require_once __DIR__ . '/../config/database.php';

function table_exists($name) {
    global $conn;
    $n = $conn->real_escape_string($name);
    $r = $conn->query("SHOW TABLES LIKE '$n'");
    return $r && $r->num_rows > 0;
}

function column_exists($table, $column) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

function count_rows($table) {
    global $conn;
    try {
        $r = $conn->query("SELECT COUNT(*) as c FROM `$table`");
        if ($r && $r->num_rows > 0) return (int)$r->fetch_assoc()['c'];
    } catch (Exception $e) {}
    return null;
}

$checks = [];

$actions = [];
$do_cleanup = false;
if (PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    $do_cleanup = in_array('--cleanup', $args, true);
}

if ($do_cleanup) {
    if (table_exists('stock_mirror')) {
        $conn->query("TRUNCATE TABLE stock_mirror");
        $actions[] = "TRUNCATE stock_mirror";
    }

    $legacy_tables = [
        'purchase_order_detail',
        'purchase_order',
        'po_receive_detail',
        'po_receive',
        'stok_opname_detail',
        'stok_opname',
        'stock_opname_log'
    ];
    foreach ($legacy_tables as $t) {
        if (table_exists($t)) {
            $conn->query("DROP TABLE IF EXISTS `$t`");
            $actions[] = "DROP $t";
        }
    }
}

$tables = [
    'klinik',
    'barang',
    'stock_mirror',
    'booking_pemeriksaan',
    'booking_detail',
    'pemakaian_bhp',
    'pemakaian_bhp_detail',
    'users',
    'stok_tas_hc'
];

foreach ($tables as $t) {
    $exists = table_exists($t);
    $checks['tables'][$t] = [
        'exists' => $exists,
        'rows' => $exists ? count_rows($t) : null
    ];
}

$checks['actions'] = $actions;

$checks['hc_user_removed'] = [
    'stok_tas_hc_exists' => $checks['tables']['stok_tas_hc']['exists'],
    'pemakaian_bhp_user_hc_id_exists' => column_exists('pemakaian_bhp', 'user_hc_id')
];

$checks['odoo_mapping'] = [
    'klinik_has_kode_klinik' => column_exists('klinik', 'kode_klinik'),
    'klinik_has_kode_homecare' => column_exists('klinik', 'kode_homecare'),
    'barang_has_kode_barang' => column_exists('barang', 'kode_barang'),
    'barang_has_odoo_product_id' => column_exists('barang', 'odoo_product_id'),
    'stock_mirror_has_location_code' => column_exists('stock_mirror', 'location_code'),
    'stock_mirror_has_kode_barang' => column_exists('stock_mirror', 'kode_barang')
];

// Spot-check: how many active klinik have mapping codes
if ($checks['tables']['klinik']['exists'] && $checks['odoo_mapping']['klinik_has_kode_klinik']) {
    $r = $conn->query("SELECT 
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_cnt,
        SUM(CASE WHEN status='active' AND kode_klinik IS NOT NULL AND kode_klinik <> '' THEN 1 ELSE 0 END) as active_with_kode,
        SUM(CASE WHEN status='active' AND kode_homecare IS NOT NULL AND kode_homecare <> '' THEN 1 ELSE 0 END) as active_with_hc
        FROM klinik");
    if ($r && $r->num_rows > 0) {
        $checks['klinik_mapping_counts'] = $r->fetch_assoc();
    }
}

// Spot-check: top 5 locations in mirror
if ($checks['tables']['stock_mirror']['exists']) {
    $r = $conn->query("SELECT location_code, COUNT(*) as items FROM stock_mirror GROUP BY location_code ORDER BY items DESC LIMIT 5");
    $top = [];
    while ($r && ($row = $r->fetch_assoc())) $top[] = $row;
    $checks['stock_mirror_top_locations'] = $top;
}

header('Content-Type: application/json');
echo json_encode($checks, JSON_PRETTY_PRINT);
?>
