<?php
$conn = new mysqli("localhost", "root", "", "bumame_inventory_v2");
$res = $conn->query("DESCRIBE inventory_hc_tas_allocation");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
$res = $conn->query("DESCRIBE inventory_stok_tas_hc");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>