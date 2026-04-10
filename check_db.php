<?php
$conn = new mysqli("localhost", "root", "", "bumame_inventory_v2");
$res = $conn->query("SHOW FULL COLUMNS FROM inventory_transaksi_stok");
while($row = $res->fetch_assoc()) {
    echo $row["Field"] . " | " . $row["Null"] . " | " . $row["Default"] . "\n";
}
