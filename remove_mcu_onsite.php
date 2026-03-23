<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo "Unauthorized";
    exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    echo "Access denied";
    exit;
}

$confirm = (string)($_GET['confirm'] ?? '');
if ($confirm !== 'YES') {
    echo "Script ini akan menghapus fitur MCU Onsite beserta tabel database terkait.\n\n";
    echo "Jalankan dengan parameter: ?confirm=YES\n";
    exit;
}

function drop_table_if_exists($name) {
    global $conn;
    $t = $conn->real_escape_string($name);
    $conn->query("DROP TABLE IF EXISTS `$t`");
}

function drop_column_if_exists($table, $column) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    if ($res && $res->num_rows > 0) {
        $conn->query("ALTER TABLE `$t` DROP COLUMN `$column`");
    }
}

$conn->begin_transaction();
try {
    drop_table_if_exists('mcu_onsite_items');
    drop_table_if_exists('mcu_onsite_projects');
    drop_column_if_exists('stok_gudang_utama', 'reserved_qty');
    $conn->commit();
    echo "OK: MCU Onsite dihapus (tabel + reserved_qty).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage();
}

