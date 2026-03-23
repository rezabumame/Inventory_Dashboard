<?php
require_once __DIR__ . '/../config/database.php';

$actions = [];

// Drop stok_tas_hc (HC per-user stock) because HC is sourced from Odoo mirror
$conn->query("DROP TABLE IF EXISTS stok_tas_hc");
$actions[] = "Dropped table stok_tas_hc";

// Remove legacy column user_hc_id if exists (some DBs may still have it)
$res = $conn->query("SHOW COLUMNS FROM pemakaian_bhp LIKE 'user_hc_id'");
if ($res && $res->num_rows > 0) {
    $conn->query("ALTER TABLE pemakaian_bhp DROP COLUMN user_hc_id");
    $actions[] = "Dropped column pemakaian_bhp.user_hc_id";
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'actions' => $actions], JSON_PRETTY_PRINT);
?>
