<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/counter.php';

use Shuchkin\SimpleXLSX;

// Ensure logs table exists
try {
    $conn->query("CREATE TABLE IF NOT EXISTS upload_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        filename VARCHAR(255),
        status ENUM('success', 'failed'),
        rows_success INT,
        rows_failed INT,
        error_details TEXT
    )");
} catch (Exception $e) {
    // Silently fail if table exists or cannot be created
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Silakan login kembali.']);
        exit;
    }
    redirect('index.php?page=login');
}

// Check access
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'b2b_ops', 'petugas_hc'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
        exit;
    }
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=pemakaian_bhp_list');
}

require_csrf();

$user_id = $_SESSION['user_id'];
$user_klinik_id = $_SESSION['klinik_id'] ?? null;
$filename = $_FILES['excel_file']['name'] ?? 'unknown';

// Validation helper
function add_error(&$errors, $row, $col, $msg) {
    $errors[] = "Baris $row, Kolom '$col': $msg";
}

function parse_custom_date($date_str) {
    // Format: "dd Month yyyy, HH:mm" (e.g. "04 March 2026, 16:00")
    $months = [
        'January' => '01', 'February' => '02', 'March' => '03', 'April' => '04',
        'May' => '05', 'June' => '06', 'July' => '07', 'August' => '08',
        'September' => '09', 'October' => '10', 'November' => '11', 'December' => '12'
    ];
    
    if (preg_match('/^(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4}),\s+(\d{1,2}):(\d{2})$/', trim($date_str), $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month_name = ucfirst(strtolower($matches[2]));
        $year = $matches[3];
        $hour = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
        $min = $matches[5];
        
        if (isset($months[$month_name])) {
            $month = $months[$month_name];
            $iso_date = "$year-$month-$day $hour:$min:00";
            if (checkdate((int)$month, (int)$day, (int)$year)) {
                return $iso_date;
            }
        }
    }
    return false;
}

try {
    // 1. File validation
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak berhasil diupload.');
    }

    $file_path = $_FILES['excel_file']['tmp_name'];
    $file_size = $_FILES['excel_file']['size'];
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($file_ext !== 'xlsx') {
        throw new Exception('Format file harus .xlsx');
    }

    if ($file_size > 5 * 1024 * 1024) {
        throw new Exception('Ukuran file maksimal 5 MB');
    }

    // 2. Load Excel
    if ($xlsx = SimpleXLSX::parse($file_path)) {
        /** @var array $rows */
        $rows = iterator_to_array($xlsx->rows());
    } else {
        throw new Exception('Gagal membaca file Excel: ' . SimpleXLSX::parseError());
    }

    if (count($rows) < 2) {
        throw new Exception('File kosong atau hanya berisi header');
    }

    // 3. Header validation
    $expected_headers = [
        'Tanggal Appointment',
        'Appointment Patient ID',
        'Nama Pasien',
        'Layanan',
        'Nama Item BHP',
        'Jumlah',
        'Satuan (UoM)',
        'Nama Nakes',
        'Nakes Branch',
        'Kode Barang'
    ];

    $actual_headers = array_map('trim', $rows[0]);
    if (count($actual_headers) !== 10) {
        throw new Exception('Jumlah kolom tidak sesuai. Harus ada 10 kolom.');
    }

    foreach ($expected_headers as $i => $expected) {
        if (strcasecmp($actual_headers[$i], $expected) !== 0) {
            throw new Exception("Header kolom ke-" . ($i+1) . " harus '$expected', ditemukan '{$actual_headers[$i]}'");
        }
    }

    // 4. Data Validation Layer
    $errors = [];
    $data_to_process = [];
    $seen_duplicates = [];
    $row_count = 0;

    // Cache master data for performance
    $master_items = [];
    $res_items = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM barang WHERE odoo_product_id IS NOT NULL");
    while($r = $res_items->fetch_assoc()) {
        $master_items[strtolower($r['kode_barang'])] = $r;
    }

    $master_uom = [];
    $res_uom = $conn->query("SELECT barang_id, from_uom, to_uom, multiplier FROM barang_uom_conversion");
    while($r = $res_uom->fetch_assoc()) {
        $master_uom[$r['barang_id']][] = $r;
    }

    $master_nakes = [];
    $res_nakes = $conn->query("SELECT id, nama_lengkap, klinik_id FROM users WHERE role = 'petugas_hc' AND status = 'active'");
    while($r = $res_nakes->fetch_assoc()) {
        $master_nakes[strtolower(trim($r['nama_lengkap']))] = $r;
    }

    $master_klinik = [];
    $res_klinik = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare, alamat FROM klinik WHERE status = 'active'");
    while($r = $res_klinik->fetch_assoc()) {
        $master_klinik[strtolower(trim($r['nama_klinik']))] = $r;
        $master_klinik[strtolower(trim($r['kode_klinik']))] = $r;
        if (!empty($r['kode_homecare'])) $master_klinik[strtolower(trim($r['kode_homecare']))] = $r;
        // Search by address snippet
        $addr = strtolower(trim($r['alamat']));
        if (!empty($addr)) {
            $master_klinik['addr_' . $addr] = $r;
        }
    }

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue; // Skip empty rows
        
        $row_num = $i + 1;
        $row_count++;

        $tgl_app = trim($row[0]);
        $patient_id = trim($row[1]);
        $nama_pasien = trim($row[2]);
        $layanan = trim($row[3]);
        $nama_item = trim($row[4]);
        $jumlah = $row[5];
        $uom = trim($row[6]);
        $nakes = trim($row[7]);
        $branch = trim($row[8]);
        $kode_barang = strtolower(trim($row[9]));

        // a. Date validation
        $iso_date = parse_custom_date($tgl_app);
        if (!$iso_date) {
            add_error($errors, $row_num, 'Tanggal Appointment', "Format salah. Gunakan 'dd Month yyyy, HH:mm' (contoh: 04 March 2026, 16:00)");
        }

        // b. Numeric validation
        if (!is_numeric($patient_id)) {
            add_error($errors, $row_num, 'Appointment Patient ID', "Harus angka numerik.");
        }

        // c. Patient Name
        if (strlen($nama_pasien) > 100) {
            add_error($errors, $row_num, 'Nama Pasien', "Maksimal 100 karakter.");
        }

        // d. Duplication check / Uniqueness logic (Appointment Patient ID + Tanggal Appointment + Kode Barang)
        $dup_key = $patient_id . '|' . $tgl_app . '|' . $kode_barang;
        $time_offset = 0;
        if (isset($seen_duplicates[$dup_key])) {
            // Instead of error, we add seconds to make it unique
            $time_offset = count($seen_duplicates[$dup_key]);
            $seen_duplicates[$dup_key][] = $row_num;
        } else {
            $seen_duplicates[$dup_key] = [$row_num];
        }

        // Adjust iso_date with offset if needed
        if ($time_offset > 0) {
            $dt_adj = new DateTime($iso_date);
            $dt_adj->modify("+$time_offset seconds");
            $iso_date = $dt_adj->format('Y-m-d H:i:s');
        }

        // e. Master Item & Kode Barang
        if (!isset($master_items[$kode_barang])) {
            add_error($errors, $row_num, 'Kode Barang', "Kode '$kode_barang' tidak terdaftar di Master BHP.");
            $item_id = 0;
        } else {
            $item_id = $master_items[$kode_barang]['id'];
        }

        // f. Jumlah
        if (!is_numeric($jumlah) || $jumlah <= 0) {
            add_error($errors, $row_num, 'Jumlah', "Harus angka positif > 0.");
        }

        // g. UoM Validation
        if ($item_id > 0) {
            $allowed_uoms = [strtolower($master_items[$kode_barang]['satuan'])];
            if (isset($master_uom[$item_id])) {
                foreach ($master_uom[$item_id] as $conv) {
                    $allowed_uoms[] = strtolower($conv['from_uom']);
                    $allowed_uoms[] = strtolower($conv['to_uom']);
                }
            }
            $allowed_uoms = array_unique(array_filter($allowed_uoms));
            if (!in_array(strtolower($uom), $allowed_uoms)) {
                add_error($errors, $row_num, 'Satuan (UoM)', "Satuan '$uom' tidak valid untuk item ini. Pilihan: " . implode(', ', $allowed_uoms));
            }
        }

        // h. Nakes & Branch logic
        $target_klinik_id = 0;
        $search_branch = strtolower($branch);
        if (isset($master_klinik[$search_branch])) {
            $target_klinik_id = $master_klinik[$search_branch]['id'];
        } else {
            // Try address search
            foreach ($master_klinik as $key => $k_data) {
                if (strpos($key, 'addr_') === 0 && strpos(substr($key, 5), $search_branch) !== false) {
                    $target_klinik_id = $k_data['id'];
                    break;
                }
            }
            if ($target_klinik_id === 0) {
                add_error($errors, $row_num, 'Nakes Branch', "Cabang '$branch' tidak ditemukan.");
            }
        }

        $user_hc_id = 0;
        if (!empty($nakes)) {
            $search_nakes = strtolower($nakes);
            if (isset($master_nakes[$search_nakes])) {
                $user_hc_id = $master_nakes[$search_nakes]['id'];
                // Optional: Check if nakes belongs to the branch?
                // if ($master_nakes[$search_nakes]['klinik_id'] != $target_klinik_id) { ... }
            } else {
                add_error($errors, $row_num, 'Nama Nakes', "Nakes '$nakes' tidak terdaftar atau tidak aktif.");
            }
        }

        if (empty($errors)) {
            $data_to_process[] = [
                'tanggal_full' => $iso_date,
                'tanggal_only' => date('Y-m-d', strtotime($iso_date)),
                'patient_id' => $patient_id,
                'nama_pasien' => $nama_pasien,
                'layanan' => $layanan,
                'item_id' => $item_id,
                'qty' => (float)$jumlah,
                'uom' => $uom,
                'user_hc_id' => $user_hc_id,
                'klinik_id' => $target_klinik_id,
                'nama_nakes' => $nakes,
                'kode_barang' => $kode_barang,
                'row_num' => $row_num
            ];
        }
    }

    // 5. Check if any errors occurred
    if (!empty($errors)) {
        // Log failure
        $stmt_log = $conn->prepare("INSERT INTO upload_logs (user_id, filename, status, rows_success, rows_failed, error_details) VALUES (?, ?, 'failed', 0, ?, ?)");
        $err_json = json_encode($errors);
        $stmt_log->bind_param("iiis", $user_id, $filename, $row_count, $err_json);
        $stmt_log->execute();

        $_SESSION['error'] = "Upload ditolak. Terdapat " . count($errors) . " kesalahan data.";
        $_SESSION['warnings'] = array_slice($errors, 0, 100); // Limit display
        redirect('index.php?page=pemakaian_bhp_list');
    }

    // 6. Process Atomic Transaction
    $conn->begin_transaction();
    
    // Group items by Transaction (Patient ID + Full Timestamp)
    $transactions = [];
    foreach ($data_to_process as $d) {
        $tx_key = $d['patient_id'] . '|' . $d['tanggal_full'];
        if (!isset($transactions[$tx_key])) {
            $transactions[$tx_key] = [
                'meta' => $d,
                'items' => []
            ];
        }
        $transactions[$tx_key]['items'][] = $d;
    }

    foreach ($transactions as $tx) {
        $m = $tx['meta'];
        $tanggal = $m['tanggal_only'];
        $created_at = $m['tanggal_full'];
        $jenis_pemakaian = !empty($m['nama_nakes']) ? 'hc' : 'klinik';
        $catatan_transaksi = $m['nama_pasien'] . ' (' . $m['patient_id'] . ') - ' . $m['layanan'];
        
        // Generate number
        $dateKey = date('Ymd', strtotime($tanggal));
        $seq = next_sequence($conn, 'PBH', $dateKey);
        $prefix = 'PBH-' . $dateKey . '-';
        $nomor_pemakaian = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiisis", $nomor_pemakaian, $tanggal, $jenis_pemakaian, $m['klinik_id'], $m['user_hc_id'], $catatan_transaksi, $user_id, $created_at);
        $stmt->execute();
        $pemakaian_id = $conn->insert_id;

        foreach ($tx['items'] as $it) {
            // Get UoM ratio
            $item_id = $it['item_id'];
            $input_qty = $it['qty'];
            $input_uom = $it['uom'];
            
            $stmt_b = $conn->prepare("SELECT id, satuan FROM barang WHERE id = ?");
            $stmt_b->bind_param("i", $item_id);
            $stmt_b->execute();
            $barang = $stmt_b->get_result()->fetch_assoc();
            $satuan_db = $barang['satuan'];

            // Ratio detection
            $ratio = 1;
            if (isset($master_uom[$item_id])) {
                foreach ($master_uom[$item_id] as $conv) {
                    if (strcasecmp($conv['from_uom'], $input_uom) === 0) {
                        $ratio = (float)$conv['multiplier'];
                        break;
                    }
                }
            }
            
            $final_qty = (float)round($input_qty / $ratio, 4);

            $stmt_d = $conn->prepare("INSERT INTO pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
            $stmt_d->bind_param("iids", $pemakaian_id, $item_id, $final_qty, $satuan_db);
            $stmt_d->execute();

            // Stock Transaction
            $level = ($jenis_pemakaian === 'hc') ? 'hc' : 'klinik';
            $level_id = $m['klinik_id'];
            $qty_before = 0;

            if ($level === 'klinik') {
                $stmt_stok = $conn->prepare("SELECT qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ?");
                $stmt_stok->bind_param("ii", $item_id, $level_id);
                $stmt_stok->execute();
                $res_stok = $stmt_stok->get_result();
                if ($res_stok->num_rows > 0) $qty_before = $res_stok->fetch_assoc()['qty'];
                
                $stmt_upd = $conn->prepare("UPDATE stok_gudang_klinik SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diii", $final_qty, $user_id, $item_id, $level_id);
                $stmt_upd->execute();
            } else {
                // HC Stock from stok_tas_hc
                $stmt_stok = $conn->prepare("SELECT qty FROM stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                $stmt_stok->bind_param("iii", $item_id, $m['user_hc_id'], $level_id);
                $stmt_stok->execute();
                $res_stok = $stmt_stok->get_result();
                if ($res_stok->num_rows > 0) $qty_before = $res_stok->fetch_assoc()['qty'];

                $stmt_upd = $conn->prepare("UPDATE stok_tas_hc SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diiii", $final_qty, $user_id, $item_id, $m['user_hc_id'], $level_id);
                $stmt_upd->execute();
            }

            $qty_after = $qty_before - $final_qty;
            $stmt_t = $conn->prepare("INSERT INTO transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, ?, ?, 'out', ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, ?)");
            $cat = "Upload BHP: $nomor_pemakaian - " . $catatan_transaksi;
            $stmt_t->bind_param("isidddsisis", $item_id, $level, $level_id, $final_qty, $qty_before, $qty_after, $pemakaian_id, $cat, $user_id, $created_at);
            $stmt_t->execute();
        }
    }

    $conn->commit();

    // Log success
    $stmt_log = $conn->prepare("INSERT INTO upload_logs (user_id, filename, status, rows_success, rows_failed, error_details) VALUES (?, ?, 'success', ?, 0, NULL)");
    $stmt_log->bind_param("isi", $user_id, $filename, $row_count);
    $stmt_log->execute();

    $_SESSION['success'] = "Berhasil mengupload $row_count baris data BHP.";
    redirect('index.php?page=pemakaian_bhp_list');

} catch (Exception $e) {
    // Attempt rollback if in transaction
    try {
        if (method_exists($conn, 'rollback')) {
            $conn->rollback();
        }
    } catch (Exception $rollback_err) {
        // Ignore rollback errors
    }
    
    // Log failure
    $stmt_log = $conn->prepare("INSERT INTO upload_logs (user_id, filename, status, rows_success, rows_failed, error_details) VALUES (?, ?, 'failed', 0, ?, ?)");
    $msg = $e->getMessage();
    $stmt_log->bind_param("isis", $user_id, $filename, $row_count, $msg);
    $stmt_log->execute();

    $_SESSION['error'] = "Gagal memproses file: " . $msg;
    redirect('index.php?page=pemakaian_bhp_list');
}


