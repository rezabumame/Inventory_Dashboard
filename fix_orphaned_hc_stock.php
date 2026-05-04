<?php
/**
 * Script untuk membersihkan data stok HC yang "nyangkut" karena petugasnya sudah dihapus dari database.
 * Setelah dijalankan, stok tersebut akan otomatis kembali menjadi 'Unallocated' di sistem.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Pastikan hanya super_admin atau dijalankan via CLI yang bisa akses
if (php_sapi_name() !== 'cli') {
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        die("Akses ditolak. Hanya Super Admin yang dapat menjalankan script ini.");
    }
}

echo "Memulai pembersihan stok HC yatim (orphaned)...\n";

try {
    // 1. Identifikasi data yang nyangkut
    $check = $conn->query("
        SELECT st.*, b.nama_barang 
        FROM inventory_stok_tas_hc st
        LEFT JOIN inventory_users u ON u.id = st.user_id
        LEFT JOIN inventory_barang b ON b.id = st.barang_id
        WHERE u.id IS NULL
    ");

    $count = $check->num_rows;

    if ($count === 0) {
        echo "Tidak ada data stok yang nyangkut. Semua data alokasi memiliki petugas yang valid.\n";
    } else {
        echo "Ditemukan $count baris data stok yang nyangkut.\n";
        
        $conn->begin_transaction();
        
        // 2. Hapus data yang nyangkut
        $delete = $conn->query("
            DELETE FROM inventory_stok_tas_hc 
            WHERE user_id NOT IN (SELECT id FROM inventory_users)
        ");

        if ($delete) {
            $conn->commit();
            echo "BERHASIL: $count data stok telah dihapus. Stok tersebut kini sudah kembali ke status 'Unallocated'.\n";
        } else {
            throw new Exception($conn->error);
        }
    }

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
}
