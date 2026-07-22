<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!csrf_validate($_POST['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Nama kategori tidak boleh kosong.']);
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS `inventory_odoo_categories` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$name_esc = $conn->real_escape_string($name);
$check = $conn->query("SELECT id FROM inventory_odoo_categories WHERE name = '$name_esc'");
if ($check && $check->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Kategori tersebut sudah ada.']);
    exit;
}

if ($conn->query("INSERT INTO inventory_odoo_categories (name) VALUES ('$name_esc')")) {
    echo json_encode(['success' => true, 'name' => $name]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $conn->error]);
}
