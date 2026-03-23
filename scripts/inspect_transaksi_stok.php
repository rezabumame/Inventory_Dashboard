<?php
require_once __DIR__ . '/../config/database.php';

$out = [];
try {
    $r = $conn->query("SHOW COLUMNS FROM transaksi_stok");
    $cols = [];
    while ($r && ($row = $r->fetch_assoc())) $cols[] = $row;
    $out['columns'] = $cols;
} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT);
?>
