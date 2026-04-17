<?php
require_once __DIR__ . '/config/database.php';
$res = $conn->query("DESCRIBE inventory_pemakaian_bhp");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>