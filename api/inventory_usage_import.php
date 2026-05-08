<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

check_role(['super_admin', 'admin_gudang']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Method not allowed");
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "Gagal mengunggah file.";
    redirect("index.php?page=daily_usage_config");
}

$xlsx = SimpleXLSX::parse($_FILES['file']['tmp_name']);
if (!$xlsx) {
    $_SESSION['error'] = "Format file tidak valid atau rusak: " . SimpleXLSX::parseError();
    redirect("index.php?page=daily_usage_config");
}

$rows = $xlsx->rows();
$header = array_shift($rows); // Skip header

$updated = 0;
$created = 0;
$errors = 0;

foreach ($rows as $row) {
    // Column Index (matching export template):
    // 0: Klinik ID
    // 1: Nama Klinik
    // 2: Config ID
    // 3: Kode Barang
    // 4: Nama Barang
    // 5: Mode (auto/manual)
    // 6: Manual Daily Usage

    $row_klinik_id = (int)($row[0] ?? 0);
    $config_id = (int)($row[2] ?? 0);
    $kode_barang = trim((string)($row[3] ?? ''));
    $mode = strtolower(trim((string)($row[5] ?? 'manual')));
    if (!in_array($mode, ['auto', 'manual'])) $mode = 'manual';
    $manual_val = round((float)($row[6] ?? 0), 0);

    if ($row_klinik_id <= 0 || empty($kode_barang)) continue;

    try {
        // Find Barang ID from Kode Barang
        $stmt_b = $conn->prepare("SELECT id FROM inventory_barang WHERE kode_barang = ?");
        $stmt_b->bind_param("s", $kode_barang);
        $stmt_b->execute();
        $res_b = $stmt_b->get_result();
        $barang_row = $res_b->fetch_assoc();
        
        if (!$barang_row) {
            $errors++;
            continue;
        }
        $barang_id = (int)$barang_row['id'];

        if ($config_id > 0) {
            $stmt = $conn->prepare("UPDATE inventory_daily_usage_config SET mode = ?, manual_value = ? WHERE id = ? AND klinik_id = ?");
            $stmt->bind_param("sdii", $mode, $manual_val, $config_id, $row_klinik_id);
            if ($stmt->execute()) {
                if ($conn->affected_rows > 0) $updated++;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO inventory_daily_usage_config (klinik_id, barang_id, mode, manual_value) 
                                   VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE mode = VALUES(mode), manual_value = VALUES(manual_value)");
            $stmt->bind_param("iisd", $row_klinik_id, $barang_id, $mode, $manual_val);
            if ($stmt->execute()) {
                if ($conn->affected_rows == 1) $created++;
                elseif ($conn->affected_rows == 2) $updated++;
            }
        }
    } catch (Exception $e) {
        $errors++;
    }
}

$_SESSION['success'] = "Berhasil memproses data: $created baru, $updated diperbarui." . ($errors > 0 ? " ($errors error)" : "");
redirect("index.php?page=daily_usage_config");
exit;
