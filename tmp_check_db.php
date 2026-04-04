<?php
$conn = new mysqli('localhost', 'root', '', 'bumame_inventory_v2');
if($conn->connect_error) die('Connect Error');
$res = $conn->query("DESCRIBE booking_pasien");
while($row = $res->fetch_assoc()) echo $row['Field'] . ' | ' . $row['Type'] . "\n";
$res = $conn->query("DESCRIBE booking_detail");
echo "---\n";
while($row = $res->fetch_assoc()) echo $row['Field'] . ' | ' . $row['Type'] . "\n";
