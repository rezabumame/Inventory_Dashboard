<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
    die('Unauthorized');
}

$sheet = [[
    'Internal Reference',
    'Name',
    'Unit of Measure',
    'Product Category',
    'Income Account',
    'Stock Valuation Account',
    'Expense Account',
]];

$res = $conn->query("SELECT internal_reference, name, uom, product_category, income_account, valuation_account, expense_account
    FROM inventory_odoo_format_config ORDER BY internal_reference ASC");
while ($row = $res->fetch_assoc()) {
    $sheet[] = [
        (string)$row['internal_reference'],
        (string)$row['name'],
        (string)$row['uom'],
        (string)$row['product_category'],
        (string)$row['income_account'],
        (string)$row['valuation_account'],
        (string)$row['expense_account'],
    ];
}

// Tambah baris kosong contoh jika belum ada data
if (count($sheet) === 1) {
    $sheet[] = ['', '', '', '', '', '', ''];
}

$filename = 'template_master_odoo_' . date('Ymd_His') . '.xlsx';
SimpleXLSXGen::fromArray($sheet, 'Master Odoo')
    ->downloadAs($filename);
exit;
