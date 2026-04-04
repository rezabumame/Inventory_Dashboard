<?php
require_once 'config/database.php';

/**
 * Script ini digunakan untuk menghapus data stock_mirror yang terkait dengan kode_homecare
 * (Stok Home Care / HC) di semua klinik. Berguna untuk membersihkan data dummy/manual.
 */

// 1. Ambil semua kode_homecare yang aktif dari tabel klinik
$klinik_res = $conn->query("SELECT kode_homecare, nama_klinik FROM klinik WHERE kode_homecare IS NOT NULL AND kode_homecare <> '' AND status = 'active'");
$hc_locations = [];
while ($row = $klinik_res->fetch_assoc()) {
    $hc_locations[] = $row;
}

if (empty($hc_locations)) {
    echo "Tidak ada klinik dengan kode_homecare yang ditemukan.\n";
    exit;
}

// 2. Mulai proses penghapusan
$conn->begin_transaction();

try {
    $total_deleted = 0;
    foreach ($hc_locations as $loc) {
        $kode_homecare = $loc['kode_homecare'];
        $nama_klinik = $loc['nama_klinik'];
        
        $stmt = $conn->prepare("DELETE FROM stock_mirror WHERE TRIM(location_code) = ?");
        $stmt->bind_param("s", $kode_homecare);
        $stmt->execute();
        
        $affected = $stmt->affected_rows;
        if ($affected > 0) {
            echo "Berhasil menghapus $affected baris stok HC untuk $nama_klinik ($kode_homecare).\n";
            $total_deleted += $affected;
        }
    }

    $conn->commit();
    echo "\nTotal $total_deleted baris data stok HC berhasil dihapus dari stock_mirror.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "Gagal menghapus data: " . $e->getMessage();
}

$conn->close();
?>
