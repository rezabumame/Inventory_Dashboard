<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Security check: Only Super Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

require_csrf();

try {
    $conn->begin_transaction();

    // Tables to truncate/clear
    $tables = [
        // Booking & Stok Pending
        'inventory_booking_detail',
        'inventory_booking_pasien',
        'inventory_booking_pemeriksaan',
        'inventory_booking_request_dedup',
        
        // Request Barang
        'inventory_request_barang_detail',
        'inventory_request_barang_dokumen',
        'inventory_request_barang',
        
        // Pemakaian BHP
        'inventory_pemakaian_bhp_detail',
        'inventory_pemakaian_bhp',
        
        // Stok Petugas HC
        'inventory_stok_tas_hc',
        'inventory_hc_petugas_transfer',
        'inventory_hc_tas_allocation',
        
        // Riwayat Transaksi Stok
        'inventory_transaksi_stok',

        // Upload Logs
        'inventory_upload_logs'
    ];

    // Disable foreign key checks temporarily if needed, 
    // but here we just delete in order of dependencies (details first)
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($tables as $table) {
        $conn->query("DELETE FROM $table");
        $conn->query("ALTER TABLE $table AUTO_INCREMENT = 1");
    }

    // Reset counters if any
    $conn->query("DELETE FROM inventory_app_counters WHERE prefix IN ('BHP', 'BK', 'REQ', 'TRF')");

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Semua data transaksi telah berhasil dihapus.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
}
