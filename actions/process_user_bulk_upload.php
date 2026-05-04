<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

check_role(['super_admin']);
require_csrf();

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "Gagal mengunggah file.";
    header("Location: ../index.php?page=users");
    exit;
}

$filePath = $_FILES['excel_file']['tmp_name'];
$xlsx = SimpleXLSX::parse($filePath);

if (!$xlsx) {
    $_SESSION['error'] = "Gagal membaca file Excel: " . SimpleXLSX::parseError();
    header("Location: ../index.php?page=users");
    exit;
}

$rows = $xlsx->rows();
if (count($rows) <= 1) {
    $_SESSION['error'] = "File Excel kosong atau tidak memiliki data.";
    header("Location: ../index.php?page=users");
    exit;
}

// Remove header row
$header = array_shift($rows);

$successCount = 0;
$failCount = 0;
$errors = [];

$conn->begin_transaction();

try {
    foreach ($rows as $index => $row) {
        $row = (array)$row;
        // Skip empty rows
        if (empty(array_filter($row))) continue;

        // Check if we reached the note section (Catatan Role...)
        if (strpos(strtolower((string)($row[0] ?? '')), 'catatan') !== false) break;
        if (count($row) < 4) continue;

        $username = trim((string)$row[0]);
        $password = trim((string)$row[1]);
        $nama_lengkap = trim((string)$row[2]);
        $role = trim((string)$row[3]);
        $klinik_id = isset($row[4]) && trim((string)$row[4]) !== '' ? (int)$row[4] : null;

        if (empty($username) || empty($password) || empty($nama_lengkap) || empty($role)) {
            $failCount++;
            $errors[] = "Baris " . ($index + 2) . ": Data tidak lengkap.";
            continue;
        }

        // Validate Role
        $validRoles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'petugas_hc', 'cs', 'b2b_ops', 'spv_manager', 'manager_klinik'];
        if (!in_array($role, $validRoles)) {
            $failCount++;
            $errors[] = "Baris " . ($index + 2) . ": Role '$role' tidak valid.";
            continue;
        }

        // Check for duplicate username
        $checkStmt = $conn->prepare("SELECT id FROM inventory_users WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $failCount++;
            $errors[] = "Baris " . ($index + 2) . ": Username '$username' sudah terdaftar.";
            continue;
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert User
        $stmt = $conn->prepare("INSERT INTO inventory_users (username, password, nama_lengkap, role, klinik_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $username, $hashedPassword, $nama_lengkap, $role, $klinik_id);
        
        if ($stmt->execute()) {
            $successCount++;
        } else {
            $failCount++;
            $errors[] = "Baris " . ($index + 2) . ": Gagal menyimpan data: " . $conn->error;
        }
    }

    $conn->commit();
    
    $msg = "Berhasil mengimpor $successCount user.";
    if ($failCount > 0) {
        $msg .= " Gagal: $failCount. " . implode(" ", array_slice($errors, 0, 3));
        if (count($errors) > 3) $msg .= " ...";
        $_SESSION['error'] = $msg;
    } else {
        $_SESSION['success'] = $msg;
    }

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Terjadi kesalahan sistem: " . $e->getMessage();
}

header("Location: ../index.php?page=users");
exit;
