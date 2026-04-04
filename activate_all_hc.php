<?php
require_once 'config/database.php';

echo "Memperbarui status semua Petugas HC menjadi 'active'...\n";

$conn->begin_transaction();

try {
    $update_stmt = $conn->prepare("UPDATE users SET status = ? WHERE role = ?");
    $status_active = 'active';
    $role_hc = 'petugas_hc';
    $update_stmt->bind_param("ss", $status_active, $role_hc);
    $update_stmt->execute();
    
    $affected_rows = $update_stmt->affected_rows;
    
    $conn->commit();
    
    echo "Berhasil memperbarui $affected_rows petugas.\n";
    echo "Semua petugas HC sekarang berstatus 'active'.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "Terjadi kesalahan: " . $e->getMessage() . "\n";
    echo "Semua perubahan telah dibatalkan.\n";
}

$conn->close();
?>