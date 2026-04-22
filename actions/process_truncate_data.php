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

    $requested_modules = $_POST['modules'] ?? [];
    if (!is_array($requested_modules) || empty($requested_modules)) {
        throw new Exception("Tidak ada modul yang dipilih.");
    }

    // Mapping module to tables
    $module_map = [
        'booking' => [
            'inventory_booking_detail',
            'inventory_booking_pasien',
            'inventory_booking_pemeriksaan',
            'inventory_booking_request_dedup'
        ],
        'request' => [
            'inventory_request_barang_detail',
            'inventory_request_barang_dokumen',
            'inventory_request_barang'
        ],
        'bhp' => [
            'inventory_pemakaian_bhp_detail',
            'inventory_pemakaian_bhp'
        ],
        'hc' => [
            'inventory_stok_tas_hc',
            'inventory_hc_petugas_transfer',
            'inventory_hc_tas_allocation'
        ],
        'history' => [
            'inventory_transaksi_stok',
            'inventory_upload_logs'
        ]
    ];

    $tables_to_delete = [];
    $prefixes_to_reset = [];

    foreach ($requested_modules as $mod) {
        if (isset($module_map[$mod])) {
            $tables_to_delete = array_merge($tables_to_delete, $module_map[$mod]);
            if ($mod === 'booking') $prefixes_to_reset[] = 'BK';
            if ($mod === 'request') $prefixes_to_reset[] = 'REQ';
            if ($mod === 'bhp') $prefixes_to_reset[] = 'BHP';
            if ($mod === 'hc') $prefixes_to_reset[] = 'TRF';
        }
    }

    $tables_to_delete = array_unique($tables_to_delete);

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($tables_to_delete as $table) {
        $conn->query("DELETE FROM $table");
        $conn->query("ALTER TABLE $table AUTO_INCREMENT = 1");
    }

    if (!empty($prefixes_to_reset)) {
        $prefix_list = "'" . implode("','", array_map([$conn, 'real_escape_string'], $prefixes_to_reset)) . "'";
        $conn->query("DELETE FROM inventory_app_counters WHERE k IN ($prefix_list)");
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Data terpilih telah berhasil dihapus.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
}
