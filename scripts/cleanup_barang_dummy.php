<?php
// Cleanup products in 'barang' without odoo_product_id
// Usage:
// - php scripts/cleanup_barang_dummy.php
// - php scripts/cleanup_barang_dummy.php --reset-transactions  (DESTRUCTIVE)

require_once __DIR__ . '/../config/database.php';

function count_ref(mysqli $conn, string $table, string $col, int $id): int {
    try {
        $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE `$col` = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return (int) ($res->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

$argv = $argv ?? [];
$resetTransactions = in_array('--reset-transactions', $argv, true) || in_array('--reset', $argv, true);

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return ($r && $r->num_rows > 0);
}

function safe_delete_all(mysqli $conn, string $table): int {
    if (!table_exists($conn, $table)) return 0;
    try {
        $conn->query("DELETE FROM `$table`");
        return (int)$conn->affected_rows;
    } catch (Throwable $e) {
        return 0;
    }
}

$reset = [
    'ran' => false,
    'tables' => [],
];

if ($resetTransactions) {
    $reset['ran'] = true;
    $tablesToClear = [
        // Transaction / activity tables
        'transaksi_stok',
        'pemakaian_bhp_detail',
        'pemakaian_bhp',
        'booking_detail',
        'booking_pemeriksaan',
        'request_barang_detail',
        'request_barang',
        'transfer_barang_detail',
        'transfer_barang',
        'mcu_onsite_items',
        'mcu_onsite_projects',
        // Stock tables (local, not Odoo mirror)
        'stok_gudang_klinik',
        'stok_gudang_utama'
    ];

    $conn->begin_transaction();
    try {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        foreach ($tablesToClear as $t) {
            $reset['tables'][$t] = safe_delete_all($conn, $t);
        }
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->query("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e2) {}
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'reset' => $reset
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$candidates = [];
$q = $conn->query("SELECT id, kode_barang, nama_barang FROM barang WHERE odoo_product_id IS NULL OR odoo_product_id = ''");
while ($q && ($r = $q->fetch_assoc())) {
    $candidates[] = $r;
}

$deleted = 0;
$skipped = [];
$archived = 0;

foreach ($candidates as $r) {
    $id = (int)$r['id'];
    $refs = [
        'stok_gudang_utama'   => count_ref($conn, 'stok_gudang_utama', 'barang_id', $id),
        'stok_gudang_klinik'  => count_ref($conn, 'stok_gudang_klinik', 'barang_id', $id),
        'pemakaian_bhp_detail'=> count_ref($conn, 'pemakaian_bhp_detail', 'barang_id', $id),
        'booking_detail'      => count_ref($conn, 'booking_detail', 'barang_id', $id),
        'transaksi_stok'      => count_ref($conn, 'transaksi_stok', 'barang_id', $id),
        'mcu_onsite_items'    => count_ref($conn, 'mcu_onsite_items', 'barang_id', $id),
        'request_barang_detail'=> count_ref($conn, 'request_barang_detail', 'barang_id', $id)
    ];
    $total_refs = array_sum($refs);
    if ($total_refs === 0) {
        $stmt = $conn->prepare("DELETE FROM barang WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $deleted += $stmt->affected_rows;
    } else {
        if (!$resetTransactions) {
            // Archive by marking kategori as 'Deprecated'
            $stmt = $conn->prepare("UPDATE barang SET kategori = 'Deprecated' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $archived += $stmt->affected_rows;
        }
        $skipped[] = [
            'id' => $id,
            'kode_barang' => $r['kode_barang'],
            'nama_barang' => $r['nama_barang'],
            'refs' => $refs
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'reset' => $reset,
    'checked' => count($candidates),
    'deleted' => $deleted,
    'archived' => $archived,
    'skipped' => $skipped
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
