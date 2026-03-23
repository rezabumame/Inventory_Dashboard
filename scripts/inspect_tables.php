<?php
require_once __DIR__ . '/../config/database.php';

$tables = ['pemakaian_bhp', 'stok_tas_hc', 'booking_detail', 'users'];
$out = [];
foreach ($tables as $t) {
    try {
        $r = $conn->query("SHOW CREATE TABLE `$t`");
        if ($r && $r->num_rows > 0) {
            $row = $r->fetch_assoc();
            $out[$t] = $row['Create Table'] ?? null;
        } else {
            $out[$t] = null;
        }
    } catch (Exception $e) {
        $out[$t] = 'ERROR: ' . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT);
?>
