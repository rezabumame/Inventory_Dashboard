<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
}

// Check access
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'b2b_ops'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=pemakaian_bhp_list');
}

// Check if file uploaded
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = 'File tidak berhasil diupload';
    redirect('index.php?page=pemakaian_bhp_list');
}

$user_klinik_id = $_SESSION['klinik_id'];
$created_by = $_SESSION['user_id'];
$warnings = [];
$success_count = 0;
$error_count = 0;

try {
    // Load file
    $file = $_FILES['excel_file']['tmp_name'];
    $file_extension = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    
    // Read file based on extension
    $rows = [];
    
    if ($file_extension === 'csv') {
        // Read CSV
        $handle = fopen($file, 'r');
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    } elseif (in_array($file_extension, ['xlsx', 'xls'])) {
        // Read Excel using SimpleXLSX
        if ($xlsx = SimpleXLSX::parse($file)) {
            $rows = $xlsx->rows();
        } else {
            throw new Exception('Gagal membaca file Excel: ' . SimpleXLSX::parseError());
        }
    } else {
        throw new Exception('Format file tidak didukung. Gunakan CSV, XLSX, atau XLS');
    }

    if (empty($rows)) {
        throw new Exception('File kosong atau tidak dapat dibaca');
    }

    // Validate headers
    $expected_headers = [
        'Tanggal Appointment',
        'Order ID',
        'Parent ID',
        'Appointment Patient ID',
        'Nama Pasien',
        'Layanan',
        'Nama Item BHP',
        'Jumlah',
        'Satuan (UoM)',
        'Nama Nakes',
        'Nakes Branch'
    ];

    $headers = array_map('trim', $rows[0]);
    
    // Check if all required headers exist
    foreach ($expected_headers as $expected) {
        if (!in_array($expected, $headers)) {
            throw new Exception("Header '$expected' tidak ditemukan di file Excel");
        }
    }

    // Get header indices
    $header_map = array_flip($headers);

    // Remove header row
    array_shift($rows);

    // Group data by Appointment Patient ID
    $grouped_data = [];
    foreach ($rows as $row_index => $row) {
        $appointment_patient_id = trim($row[$header_map['Appointment Patient ID']] ?? '');
        $jumlah = floatval($row[$header_map['Jumlah']] ?? 0);

        // Skip if Appointment Patient ID empty or Jumlah = 0
        if (empty($appointment_patient_id) || $jumlah <= 0) {
            continue;
        }

        if (!isset($grouped_data[$appointment_patient_id])) {
            $grouped_data[$appointment_patient_id] = [];
        }

        $grouped_data[$appointment_patient_id][] = [
            'tanggal' => trim($row[$header_map['Tanggal Appointment']] ?? ''),
            'order_id' => trim($row[$header_map['Order ID']] ?? ''),
            'nama_pasien' => trim($row[$header_map['Nama Pasien']] ?? ''),
            'layanan' => trim($row[$header_map['Layanan']] ?? ''),
            'nama_item_bhp' => trim($row[$header_map['Nama Item BHP']] ?? ''),
            'jumlah' => $jumlah,
            'satuan' => trim($row[$header_map['Satuan (UoM)']] ?? ''),
            'nama_nakes' => trim($row[$header_map['Nama Nakes']] ?? ''),
            'nakes_branch' => trim($row[$header_map['Nakes Branch']] ?? ''),
            'row_number' => $row_index + 2 // +2 because array starts at 0 and we removed header
        ];
    }

    if (empty($grouped_data)) {
        throw new Exception('Tidak ada data valid yang ditemukan di file Excel');
    }

    // --- START PRE-VALIDATION ---
    $validation_errors = [];
    $validated_items = []; // Store validated data to avoid redundant DB calls later

    foreach ($grouped_data as $appointment_patient_id => $items) {
        $first_item = $items[0];
        
        // 1. Validate Tanggal
        $tanggal = date('Y-m-d', strtotime($first_item['tanggal']));
        if ($tanggal === '1970-01-01') {
            $validation_errors[] = "Appointment Patient ID $appointment_patient_id: Format tanggal '{$first_item['tanggal']}' tidak valid";
        }

        // Validate Items and UoM
        foreach ($items as $item) {
            $identifier = "Row {$item['row_number']} [Pasien: {$item['nama_pasien']} / ID: {$appointment_patient_id}]";
            
            $stmt = $conn->prepare("SELECT id, satuan FROM barang WHERE nama_barang = ? LIMIT 1");
            $stmt->bind_param("s", $item['nama_item_bhp']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $validation_errors[] = "$identifier: Item '{$item['nama_item_bhp']}' tidak ditemukan di Database";
            } else {
                $barang = $result->fetch_assoc();
                // Validate UoM
                if (strcasecmp($item['satuan'], $barang['satuan']) !== 0) {
                    $validation_errors[] = "$identifier: Satuan (UoM) '{$item['satuan']}' tidak sesuai. Di database adalah '{$barang['satuan']}' untuk item '{$item['nama_item_bhp']}'";
                }
                
                // Save for processing
                $validated_items[$item['row_number']] = [
                    'barang_id' => $barang['id'],
                    'satuan_db' => $barang['satuan']
                ];
            }
        }
    }

    // If there are ANY validation errors, ABORT everything
    if (!empty($validation_errors)) {
        $_SESSION['error'] = 'Upload dibatalkan karena terdapat data yang tidak valid. Silakan perbaiki file Excel Anda dan upload kembali.';
        $_SESSION['warnings'] = $validation_errors;
        redirect('index.php?page=pemakaian_bhp_list');
    }
    // --- END PRE-VALIDATION ---

    // Process each group (Transaction)
    $conn->begin_transaction();

    foreach ($grouped_data as $appointment_patient_id => $items) {
        $first_item = $items[0];
        $tanggal = date('Y-m-d', strtotime($first_item['tanggal']));
        $jenis_pemakaian = !empty($first_item['nama_nakes']) ? 'hc' : 'klinik';
        
        $catatan_transaksi = $first_item['nama_pasien'] . ' (' . $first_item['order_id'] . ')';

        // Find clinic ID based on Nakes Branch if provided, otherwise use current user's clinic
        $target_klinik_id = $user_klinik_id;
        if (!empty($first_item['nakes_branch'])) {
            $stmt_k = $conn->prepare("SELECT id FROM klinik WHERE nama_klinik = ? OR kode_klinik = ? LIMIT 1");
            $stmt_k->bind_param("ss", $first_item['nakes_branch'], $first_item['nakes_branch']);
            $stmt_k->execute();
            $res_k = $stmt_k->get_result();
            if ($res_k->num_rows > 0) {
                $target_klinik_id = $res_k->fetch_assoc()['id'];
            }
        }

        // Generate nomor pemakaian
        $prefix = 'PBH-' . date('Ymd', strtotime($tanggal)) . '-';
        $stmt = $conn->prepare("SELECT nomor_pemakaian FROM pemakaian_bhp WHERE nomor_pemakaian LIKE ? ORDER BY nomor_pemakaian DESC LIMIT 1");
        $like_prefix = $prefix . '%';
        $stmt->bind_param("s", $like_prefix);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $last = $result->fetch_assoc();
            $last_num = intval(substr($last['nomor_pemakaian'], -4));
            $new_num = $last_num + 1;
        } else {
            $new_num = 1;
        }
        $nomor_pemakaian = $prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);

        // Insert header
        $stmt = $conn->prepare("
            INSERT INTO pemakaian_bhp 
            (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, catatan_transaksi, created_by) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssisi", $nomor_pemakaian, $tanggal, $jenis_pemakaian, $target_klinik_id, $catatan_transaksi, $created_by);
        $stmt->execute();
        $pemakaian_id = $conn->insert_id;

        // Process items
        foreach ($items as $item) {
            $v_data = $validated_items[$item['row_number']];
            $barang_id = $v_data['barang_id'];
            $satuan_db = $v_data['satuan_db'];
            $qty = $item['jumlah'];
            $catatan_item = null;
            
            $stmt = $conn->prepare("
                INSERT INTO pemakaian_bhp_detail 
                (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiss", $pemakaian_id, $barang_id, $qty, $satuan_db, $catatan_item);
            $stmt->execute();

            $level = ($jenis_pemakaian === 'hc') ? 'hc' : 'klinik';
            $level_id = (int)$target_klinik_id;
            $qty_before = 0;
            if ($level === 'klinik') {
                $stmt_q = $conn->prepare("SELECT qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ? LIMIT 1");
                $stmt_q->bind_param("ii", $barang_id, $target_klinik_id);
                $stmt_q->execute();
                $res_q = $stmt_q->get_result();
                if ($res_q && $res_q->num_rows > 0) $qty_before = (int)$res_q->fetch_assoc()['qty'];
            } else {
                $kode_homecare = '';
                $kode_barang = '';
                $stmt_k = $conn->prepare("SELECT kode_homecare FROM klinik WHERE id = ? LIMIT 1");
                $stmt_k->bind_param("i", $target_klinik_id);
                $stmt_k->execute();
                $res_k = $stmt_k->get_result();
                if ($res_k && $res_k->num_rows > 0) $kode_homecare = (string)($res_k->fetch_assoc()['kode_homecare'] ?? '');

                $stmt_b = $conn->prepare("SELECT kode_barang FROM barang WHERE id = ? LIMIT 1");
                $stmt_b->bind_param("i", $barang_id);
                $stmt_b->execute();
                $res_b = $stmt_b->get_result();
                if ($res_b && $res_b->num_rows > 0) $kode_barang = (string)($res_b->fetch_assoc()['kode_barang'] ?? '');

                if ($kode_homecare !== '' && $kode_barang !== '') {
                    $stmt_sm = $conn->prepare("SELECT qty FROM stock_mirror WHERE location_code = ? AND kode_barang = ? ORDER BY updated_at DESC LIMIT 1");
                    $stmt_sm->bind_param("ss", $kode_homecare, $kode_barang);
                    $stmt_sm->execute();
                    $res_sm = $stmt_sm->get_result();
                    if ($res_sm && $res_sm->num_rows > 0) $qty_before = (int)floor((float)$res_sm->fetch_assoc()['qty']);
                }
            }
            $qty_after = $qty_before - (int)$qty;
            if ($qty_after < 0) $qty_after = 0;

            $ref_type = 'pemakaian_bhp';
            $ref_id = (int)$pemakaian_id;
            $catatan = "PBH " . $nomor_pemakaian . " - " . ($level === 'klinik' ? 'Klinik' : 'HC');
            if (!empty($catatan_transaksi)) $catatan .= " - " . $catatan_transaksi;

            $stmt_trans = $conn->prepare("
                INSERT INTO transaksi_stok
                (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $created_at = date('Y-m-d H:i:s', strtotime($tanggal));
            $stmt_trans->bind_param(
                "isiiiisisis",
                $barang_id,
                $level,
                $level_id,
                $qty,
                $qty_before,
                $qty_after,
                $ref_type,
                $ref_id,
                $catatan,
                $created_by,
                $created_at
            );
            $stmt_trans->execute();

            // Update stock
            $stmt = $conn->prepare("
                UPDATE stok_gudang_klinik 
                SET qty = qty - ?, updated_by = ?, updated_at = NOW() 
                WHERE barang_id = ? AND klinik_id = ?
            ");
            $stmt->bind_param("iiii", $qty, $created_by, $barang_id, $target_klinik_id);
            $stmt->execute();
        }
        $success_count++;
    }

    $conn->commit();

    // Check if any transaction succeeded
    if ($success_count === 0) {
        $_SESSION['error'] = 'Tidak ada transaksi yang berhasil diproses';
        if (!empty($warnings)) {
            $_SESSION['warnings'] = $warnings;
        }
        redirect('index.php?page=pemakaian_bhp_list');
    }

    // Set success message
    $message = "Berhasil memproses $success_count transaksi";
    if ($error_count > 0) {
        $message .= ", $error_count transaksi gagal";
    }
    
    $_SESSION['success'] = $message;
    
    if (!empty($warnings)) {
        $_SESSION['warnings'] = $warnings;
    }

    redirect('index.php?page=pemakaian_bhp_list');

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    // Log error for debugging
    error_log('Upload Pemakaian BHP Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    $_SESSION['error'] = 'Gagal memproses file: ' . $e->getMessage();
    
    // If there are warnings, keep them
    if (!empty($warnings)) {
        $_SESSION['warnings'] = $warnings;
    }
    
    redirect('index.php?page=pemakaian_bhp_list');
}
