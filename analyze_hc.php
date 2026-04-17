<?php
require_once 'config/database.php';

echo "### Checking if HC level_id points to inventory_klinik directly ###\n";
$res = $conn->query("
    SELECT t.id, t.level_id, t.referensi_tipe, t.referensi_id
    FROM inventory_transaksi_stok t
    LEFT JOIN inventory_users u ON t.level_id = u.id
    WHERE t.level = 'hc' AND u.id IS NULL
");

while($row = $res->fetch_assoc()) {
    echo "TX ID: {$row['id']} | Level ID: {$row['level_id']} | Ref: {$row['referensi_tipe']} #{$row['referensi_id']}\n";
    $res_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = {$row['level_id']}");
    if ($res_k && $k = $res_k->fetch_assoc()) {
        echo "  --> Points directly to Klinik: {$k['nama_klinik']}\n";
    }
}
if ($res->num_rows === 0) echo "No HC transactions point directly to Klinik (all found in users).\n";
?>
