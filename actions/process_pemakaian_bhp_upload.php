<?php
session_start();
ob_start(); // Prevent any early output
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/counter.php';

// Use error_log instead of file_put_contents for cloud environments
function log_cloud($msg) {
    error_log("[BHP_UPLOAD] " . $msg);
}
log_cloud("Script started. AJAX: " . (isset($_POST['ajax']) ? '1' : '0') . " Action: " . ($_POST['action'] ?? 'none'));


use Shuchkin\SimpleXLSX;

function acquire_named_locks(mysqli $conn, array $lockNames, int $timeoutSeconds = 10): array {
    $acquired = [];
    foreach ($lockNames as $name) {
        $esc = $conn->real_escape_string($name);
        $r = $conn->query("SELECT GET_LOCK('$esc', " . (int)$timeoutSeconds . ") AS got");
        $got = (int)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['got'] ?? 0) : 0);
        if ($got !== 1) {
            // Release any acquired locks before failing
            foreach ($acquired as $n) {
                $e = $conn->real_escape_string($n);
                $conn->query("SELECT RELEASE_LOCK('$e')");
            }
            throw new Exception("Sistem sedang memproses stok (lock: $name). Coba lagi sebentar.");
        }
        $acquired[] = $name;
    }
    return $acquired;
}

function release_named_locks(mysqli $conn, array $acquired): void {
    foreach ($acquired as $name) {
        $esc = $conn->real_escape_string($name);
        $conn->query("SELECT RELEASE_LOCK('$esc')");
    }
}

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
$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || isset($_POST['ajax']);
$is_confirm_upload = ($is_ajax && isset($_POST['action']) && $_POST['action'] === 'confirm_upload');

log_cloud("Script started. AJAX: " . ($is_ajax ? '1' : '0') . " Action: " . ($_POST['action'] ?? 'none'));

// Validation helper
function add_error(&$errors, $row, $col, $msg, $type = 'general', $data = []) {
    $errors[] = [
        'message' => "Baris $row, Kolom '$col': $msg",
        'type' => $type,
        'data' => array_merge(['row' => $row, 'col' => $col], $data)
    ];
}

// Handle UOM Mapping Fixes via AJAX
if ($is_ajax && isset($_POST['action']) && $_POST['action'] === 'fix_uom_mappings') {
    try {
        $mappings = $_POST['mappings'] ?? [];
        if (!empty($mappings)) {
            $conn->begin_transaction();
            foreach ($mappings as $m) {
                $kb = $conn->real_escape_string($m['kode_barang']);
                $from = $conn->real_escape_string($m['from_uom']);
                $to = $conn->real_escape_string($m['to_uom']);
                $mult = 1.0; // Default to 1 if fixing via popup
                
                // Check if already exists
                $res_check = $conn->query("SELECT id FROM inventory_barang_uom_conversion WHERE kode_barang = '$kb'");
                if ($res_check && $res_check->num_rows > 0) {
                    $conn->query("UPDATE inventory_barang_uom_conversion SET from_uom = '$from', to_uom = '$to', multiplier = $mult WHERE kode_barang = '$kb'");
                } else {
                    $conn->query("INSERT INTO inventory_barang_uom_conversion (kode_barang, from_uom, to_uom, multiplier) VALUES ('$kb', '$from', '$to', $mult)");
                }
            }
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Mapping UOM berhasil diperbarui.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Tidak ada mapping untuk disimpan.']);
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->connect_errno == 0) $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan mapping: ' . $e->getMessage()]);
    }
    exit;
}

function parse_custom_date($date_str) {
    $date_str = trim((string)$date_str);
    if ($date_str === '') return false;

    // Task Fix: Gunakan strtotime untuk fleksibilitas format (April 14, 2026, 4:35 PM dsb)
    // strtotime sangat baik menangani format standar bahasa Inggris.
    $ts = strtotime($date_str);
    if ($ts !== false && $ts > 0) {
        $iso = date('Y-m-d H:i:s', $ts);
        // Validasi tambahan untuk memastikan tahun masuk akal
        $year = (int)date('Y', $ts);
        if ($year > 2000 && $year < 2100) {
            return $iso;
        }
    }

    // Jika strtotime gagal, coba format manual yang mungkin mengandung koma unik
    $formats = [
        'd F Y, H:i',
        'F d, Y, g:i A',
        'F d, Y, h:i A',
        'd M Y, H:i',
        'M d, Y, g:i A',
    ];

    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $date_str);
        if ($d) {
            return $d->format('Y-m-d H:i:s');
        }
    }

    return false;
}

try {
    // Confirm previously previewed upload (no need to re-upload the file)
    if ($is_confirm_upload) {
        $token = trim((string)($_POST['token'] ?? ''));
        if ($token === '') throw new Exception('Token preview tidak valid.');
        $preview_store = $_SESSION['pemakaian_bhp_upload_preview'] ?? [];
        $payload = $preview_store[$token] ?? null;
        if (!$payload || empty($payload['data_to_process']) || !is_array($payload['data_to_process'])) {
            throw new Exception('Preview tidak ditemukan atau sudah kadaluarsa. Silakan upload ulang.');
        }

        $data_to_process = $payload['data_to_process'];
        $filename = $payload['filename'] ?? 'unknown_from_session';
        $row_count = count($data_to_process);
        
        log_cloud("Confirm Upload started. Token: $token. Rows: $row_count. File: $filename");

        // Do NOT unset session yet. Wait until success to allow retries on transient errors.
        // unset($_SESSION['pemakaian_bhp_upload_preview'][$token]);

        // Rebuild master_uom for ratio conversion
        $master_uom = [];
        $res_uom = $conn->query("
            SELECT c.kode_barang, b.id AS barang_id, c.from_uom, c.to_uom, c.multiplier 
            FROM inventory_barang_uom_conversion c
            JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        ");
        while($res_uom && ($r = $res_uom->fetch_assoc())) {
            $master_uom[(int)$r['barang_id']][] = $r;
        }

        // Go to processing phase
        goto PROCESS_UPLOAD;
    }

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
    $res_items = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang WHERE odoo_product_id IS NOT NULL");
    while($r = $res_items->fetch_assoc()) {
        $master_items[strtolower($r['kode_barang'])] = $r;
    }

    $master_uom = [];
    $res_uom = $conn->query("
        SELECT c.kode_barang, b.id AS barang_id, c.from_uom, c.to_uom, c.multiplier 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
    ");
    while($r = $res_uom->fetch_assoc()) {
        $master_uom[$r['barang_id']][] = $r;
    }

    $master_nakes = [];
    $res_nakes = $conn->query("SELECT id, nama_lengkap, klinik_id FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active'");
    while($r = $res_nakes->fetch_assoc()) {
        $master_nakes[strtolower(trim($r['nama_lengkap']))] = $r;
    }

    $master_klinik = [];
    $res_klinik = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare, alamat FROM inventory_klinik WHERE status = 'active'");
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
            add_error($errors, $row_num, 'Tanggal Appointment', "Format salah. Gunakan format standar (Contoh: '04 March 2026, 16:00' atau 'April 14, 2026, 4:35 PM')");
            continue;
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
            $op_uoms = [];
            $odoo_uoms = [];
            if (isset($master_uom[$item_id])) {
                foreach ($master_uom[$item_id] as $conv) {
                    // PRIORITIZE 'to_uom' as it's the operational unit (btl, box, vial, etc)
                    $op_uoms[] = strtolower($conv['to_uom']);
                    $odoo_uoms[] = strtolower($conv['from_uom']);
                }
            }
            $base_uom = strtolower($master_items[$kode_barang]['satuan']);
            
            // Merge with priority: Operational > Odoo Conversion > Base Master
            $allowed_uoms = array_merge($op_uoms, $odoo_uoms, [$base_uom]);
            $allowed_uoms = array_values(array_unique(array_filter($allowed_uoms)));
            
            if (!in_array(strtolower($uom), array_map('strtolower', $allowed_uoms))) {
                $item_display_name = $master_items[$kode_barang]['nama_barang'] ?? $nama_item;
                add_error($errors, $row_num, 'Satuan (UoM)', "Satuan '$uom' tidak valid untuk item '$item_display_name'. Pilihan: " . implode(', ', $allowed_uoms), 'uom_mismatch', [
                    'kode_barang' => $kode_barang,
                    'nama_item' => $item_display_name,
                    'invalid_uom' => $uom,
                    'allowed_uoms' => $allowed_uoms
                ]);
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
        $stmt_log = $conn->prepare("INSERT INTO inventory_upload_logs (user_id, filename, status, rows_success, rows_failed, error_details) VALUES (?, ?, 'failed', 0, ?, ?)");
        $err_json = json_encode($errors);
        $stmt_log->bind_param("iiis", $user_id, $filename, $row_count, $err_json);
        $stmt_log->execute();

        if ($is_ajax) {
            echo json_encode(['status' => 'error', 'message' => "Upload ditolak. Terdapat " . count($errors) . " kesalahan data.", 'errors' => $errors]);
            exit;
        }

        $_SESSION['error'] = "Upload ditolak. Terdapat " . count($errors) . " kesalahan data.";
        $_SESSION['warnings'] = array_slice($errors, 0, 100); // Limit display
        redirect('index.php?page=pemakaian_bhp_list');
    }

    // Preview diff (BHP Harian) before committing
    if ($is_ajax) {
        // Prune old previews
        if (!isset($_SESSION['pemakaian_bhp_upload_preview']) || !is_array($_SESSION['pemakaian_bhp_upload_preview'])) {
            $_SESSION['pemakaian_bhp_upload_preview'] = [];
        }
        foreach ($_SESSION['pemakaian_bhp_upload_preview'] as $k => $v) {
            $ts = (int)($v['created_at'] ?? 0);
            if ($ts > 0 && $ts < (time() - 3600)) unset($_SESSION['pemakaian_bhp_upload_preview'][$k]);
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION['pemakaian_bhp_upload_preview'][$token] = [
            'created_at' => time(),
            'filename' => $filename,
            'data_to_process' => $data_to_process
        ];
        log_cloud("Preview stored in session. Token: $token. File: $filename");

        // Build barang map for display
        $barang_map = [];
        $barang_ids = array_values(array_unique(array_map(fn($d) => (int)$d['item_id'], $data_to_process)));
        if (!empty($barang_ids)) {
            $ids_str = implode(',', array_map('intval', $barang_ids));
            $res_bm = $conn->query("SELECT id, kode_barang, nama_barang FROM inventory_barang WHERE id IN ($ids_str)");
            while ($res_bm && ($rbm = $res_bm->fetch_assoc())) {
                $barang_map[(int)$rbm['id']] = [
                    'kode_barang' => (string)($rbm['kode_barang'] ?? ''),
                    'nama_barang' => (string)($rbm['nama_barang'] ?? '')
                ];
            }
        }

        $klinik_map = [];
        $res_km = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status = 'active'");
        while ($res_km && ($rk = $res_km->fetch_assoc())) {
            $klinik_map[(int)$rk['id']] = (string)($rk['nama_klinik'] ?? '');
        }

        $upload_agg = [];
        $group_keys = [];
        foreach ($data_to_process as $d) {
            $tgl = (string)$d['tanggal_only'];
            $kid = (int)$d['klinik_id'];
            $jenis = !empty($d['nama_nakes']) ? 'hc' : 'klinik';
            $bid = (int)$d['item_id'];
            $qty_in = (float)$d['qty'];
            $uom_in = (string)$d['uom'];

            $ratio = 1.0;
            if (isset($master_uom[$bid])) {
                foreach ($master_uom[$bid] as $conv) {
                    if (strcasecmp((string)$conv['from_uom'], $uom_in) === 0) {
                        $ratio = (float)($conv['multiplier'] ?? 1);
                        break;
                    }
                }
            }
            if ($ratio <= 0.0000001) $ratio = 1.0;
            $qty_final = (float)round($qty_in / $ratio, 4);

            $gk = $tgl . '|' . $kid . '|' . $jenis;
            $group_keys[$gk] = ['tanggal' => $tgl, 'klinik_id' => $kid, 'jenis' => $jenis];
            $k = $gk . '|' . $bid;
            if (!isset($upload_agg[$k])) $upload_agg[$k] = 0.0;
            $upload_agg[$k] += $qty_final;
        }

        $existing_agg = [];
        foreach ($group_keys as $gk => $g) {
            $stmt_e = $conn->prepare("
                SELECT pbd.barang_id, SUM(pbd.qty) AS qty
                FROM inventory_pemakaian_bhp_detail pbd
                JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
                WHERE pb.klinik_id = ? AND pb.jenis_pemakaian = ? AND DATE(pb.tanggal) = ?
                GROUP BY pbd.barang_id
            ");
            $stmt_e->bind_param("iss", $g['klinik_id'], $g['jenis'], $g['tanggal']);
            $stmt_e->execute();
            $res_e = $stmt_e->get_result();
            while ($res_e && ($er = $res_e->fetch_assoc())) {
                $bid = (int)$er['barang_id'];
                $k = $gk . '|' . $bid;
                $existing_agg[$k] = (float)($er['qty'] ?? 0);
            }
        }

        $all_keys = array_unique(array_merge(array_keys($upload_agg), array_keys($existing_agg)));
        $diffs = [];
        foreach ($all_keys as $k) {
            $parts = explode('|', $k);
            if (count($parts) < 4) continue;
            $tgl = $parts[0];
            $kid = (int)$parts[1];
            $jenis = $parts[2];
            $bid = (int)$parts[3];

            $u = (float)($upload_agg[$k] ?? 0);
            $e = (float)($existing_agg[$k] ?? 0);
            $dlt = $u - $e;
            if (abs($dlt) < 0.00005) continue;

            $bm = $barang_map[$bid] ?? ['kode_barang' => '', 'nama_barang' => ''];
            $diffs[] = [
                'tanggal' => $tgl,
                'klinik_id' => $kid,
                'nama_klinik' => $klinik_map[$kid] ?? '',
                'jenis' => $jenis,
                'barang_id' => $bid,
                'kode_barang' => $bm['kode_barang'],
                'nama_barang' => $bm['nama_barang'],
                'existing_qty' => round($e, 4),
                'upload_qty' => round($u, 4),
                'diff' => round($dlt, 4),
            ];
        }

        echo json_encode([
            'status' => 'preview',
            'token' => $token,
            'message' => 'Preview membandingkan jumlah di file Excel dengan jumlah yang sudah tercatat di sistem per tanggal, cabang, dan jenis (klinik/HC). Kolom selisih = Qty Excel (aktual) dikurangi qty tercatat; pada alur normal qty tercatat berasal dari pemakaian auto hasil mapping booking CS berstatus Completed.',
            'diffs' => array_slice($diffs, 0, 300),
            'diff_count' => count($diffs)
        ]);
        exit;
    }

    // 6. Process Atomic Transaction
    PROCESS_UPLOAD:
    // Acquire locks for all affected (date|clinic|jenis) to prevent concurrent stock mutation conflicts.
    // This does NOT change business logic, only prevents race conditions during upload commits.
    $lock_names = [];
    foreach ($data_to_process as $d) {
        $tgl = (string)($d['tanggal_only'] ?? '');
        $kid = (int)($d['klinik_id'] ?? 0);
        $jenis = !empty($d['nama_nakes']) ? 'hc' : 'klinik';
        if ($tgl === '' || $kid <= 0) continue;
        $lock_names[] = 'stock_pemakaian_bhp_upload_' . $kid . '_' . $jenis . '_' . preg_replace('/[^0-9]/', '', $tgl);
    }
    $lock_names = array_values(array_unique($lock_names));
    sort($lock_names); // deterministic order to avoid deadlocks
    $locks_acquired = [];
    if (!empty($lock_names)) {
        $locks_acquired = acquire_named_locks($conn, $lock_names, 10);
    }

    $conn->begin_transaction();
    
    // Group items by Transaction (Patient ID + Full Timestamp)
    $transactions = [];
    foreach ($data_to_process as $d) {
        $patient_id = trim((string)($d['patient_id'] ?? ''));
        $tanggal_full = trim((string)($d['tanggal_full'] ?? ''));
        $tx_key = $patient_id . '|' . $tanggal_full;
        
        if (!isset($transactions[$tx_key])) {
            $transactions[$tx_key] = [
                'tx_key' => $tx_key,
                'items' => []
            ];
        }
        $transactions[$tx_key]['items'][] = $d;
    }

    foreach ($transactions as $tx) {
        if (empty($tx['items'])) continue;
        
        $m = $tx['items'][0];
        $tanggal_val = $m['tanggal_full'];
        $created_at = $m['tanggal_full'];
        $jenis_pemakaian = !empty($m['nama_nakes']) ? 'hc' : 'klinik';
        $catatan_transaksi = ($m['nama_pasien'] ?? 'Unknown') . ' (' . ($m['patient_id'] ?? '0') . ') - ' . ($m['layanan'] ?? '-');

        // --- BACKDATE CHECK (H-2) ---
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $selected_date = date('Y-m-d', strtotime($tanggal_val));
        $is_backdate = ($selected_date < $yesterday);

        if ($is_backdate) {
            // IF BACKDATE, SAVE AS PENDING ADD
            $pending_items = [];
            foreach ($tx['items'] as $it) {
                $pending_items[] = [
                    'barang_id' => $it['item_id'],
                    'qty' => $it['qty'],
                    'satuan' => $it['uom'],
                    'uom_mode' => 'odoo', // Upload always uses Odoo UOM mode ratio logic
                    'catatan_item' => $m['layanan']
                ];
            }

            $pending_data = json_encode([
                'version' => 2,
                'action' => 'create',
                'meta' => [
                    'reason_label' => 'Upload Excel (Backdate)',
                    'change_source' => 'sistem_integrasi',
                    'change_actor_name' => 'Upload System'
                ],
                'tanggal' => $tanggal_val,
                'jenis_pemakaian' => $jenis_pemakaian,
                'klinik_id' => $m['klinik_id'],
                'user_hc_id' => $m['user_hc_id'],
                'catatan_transaksi' => $catatan_transaksi,
                'items' => $pending_items
            ]);

            $dateKey = date('ymd', strtotime($m['tanggal_only']));
            $seq = next_sequence($conn, 'BHP-REQ', $dateKey);
            $nomor_req = 'REQ-ADD-' . $dateKey . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, created_at, status, approval_reason, pending_data, change_source, change_actor_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_add', 'Upload Excel (Backdate)', ?, 'sistem_integrasi', 'Upload System')");
            $stmt->bind_param("sssiisiss", $nomor_req, $tanggal_val, $jenis_pemakaian, $m['klinik_id'], $m['user_hc_id'], $catatan_transaksi, $user_id, $created_at, $pending_data);
            $stmt->execute();
            
            // Note: We don't insert detail or deduct stock for pending_add.
            // It will be processed during approval in process_pemakaian_bhp_action.php
            continue;
        }

        // --- NORMAL FLOW (NOT BACKDATE) ---
        // Take out temporary auto-deduction for the same day & clinic (BHP harian)
        
        // Generate number
        $dateKey = date('ymd', strtotime($m['tanggal_only'])); // Use ymd (6 digits) for consistency
        $prefix = 'BHP-' . $dateKey . '-';
        $max_retries = 10;
        $nomor_pemakaian = '';
        for ($i = 0; $i < $max_retries; $i++) {
            $seq = next_sequence($conn, 'BHP', $dateKey);
            $temp_nomor = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            
            $stmt_check = $conn->prepare("SELECT id FROM inventory_pemakaian_bhp WHERE nomor_pemakaian = ? LIMIT 1");
            $stmt_check->bind_param("s", $temp_nomor);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows === 0) {
                $nomor_pemakaian = $temp_nomor;
                break;
            }
        }

        if (empty($nomor_pemakaian)) {
            throw new Exception('Gagal membuat nomor pemakaian unik.');
        }

        $stmt = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiisis", $nomor_pemakaian, $tanggal_val, $jenis_pemakaian, $m['klinik_id'], $m['user_hc_id'], $catatan_transaksi, $user_id, $created_at);
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan header BHP ($nomor_pemakaian): " . $stmt->error);
        }
        $pemakaian_id = $conn->insert_id;

        foreach ($tx['items'] as $it) {
            $item_id = $it['item_id'];
            $input_qty = $it['qty'];
            $input_uom = $it['uom'];
            
            $stmt_b = $conn->prepare("SELECT id, satuan FROM inventory_barang WHERE id = ?");
            $stmt_b->bind_param("i", $item_id);
            $stmt_b->execute();
            $barang = $stmt_b->get_result()->fetch_assoc();
            if (!$barang) {
                throw new Exception("Barang ID $item_id tidak ditemukan saat proses simpan.");
            }
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
            
            // FIX: Multiplication for ratio (e.g. 1 Box * 10 = 10 Pcs)
            $final_qty = (float)round($input_qty * $ratio, 4);

            $stmt_d = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
            $stmt_d->bind_param("iids", $pemakaian_id, $item_id, $final_qty, $satuan_db);
            if (!$stmt_d->execute()) {
                throw new Exception("Gagal menyimpan detail BHP item ID $item_id: " . $stmt_d->error);
            }

            // Stock Transaction
            $level = ($jenis_pemakaian === 'hc') ? 'hc' : 'klinik';
            $level_id = ($level === 'hc') ? $m['user_hc_id'] : $m['klinik_id'];
            $qty_before = 0;

            if ($level === 'klinik') {
                $stmt_stok = $conn->prepare("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ?");
                $stmt_stok->bind_param("ii", $item_id, $level_id);
                $stmt_stok->execute();
                $res_stok = $stmt_stok->get_result();
                if ($res_stok->num_rows > 0) $qty_before = $res_stok->fetch_assoc()['qty'];
                
                $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diii", $final_qty, $user_id, $item_id, $level_id);
                if (!$stmt_upd->execute()) {
                    throw new Exception("Gagal update stok klinik item ID $item_id: " . $stmt_upd->error);
                }
            } else {
                $stmt_stok = $conn->prepare("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                $stmt_stok->bind_param("iii", $item_id, $m['user_hc_id'], $level_id);
                $stmt_stok->execute();
                $res_stok = $stmt_stok->get_result();
                if ($res_stok->num_rows > 0) $qty_before = $res_stok->fetch_assoc()['qty'];

                $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diiii", $final_qty, $user_id, $item_id, $m['user_hc_id'], $level_id);
                if (!$stmt_upd->execute()) {
                    throw new Exception("Gagal update stok HC item ID $item_id: " . $stmt_upd->error);
                }
            }

            $qty_after = $qty_before - $final_qty;
            $stmt_t = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, ?, ?, 'out', ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, ?)");
            $cat = "Upload BHP: $nomor_pemakaian - " . $catatan_transaksi;
            $stmt_t->bind_param("isidddisis", $item_id, $level, $level_id, $final_qty, $qty_before, $qty_after, $pemakaian_id, $cat, $user_id, $created_at);
            if (!$stmt_t->execute()) {
                throw new Exception("Gagal simpan log transaksi stok item ID $item_id: " . $stmt_t->error);
            }
        }
    }

    $conn->commit();
    log_cloud("Transaction committed successfully.");
    
    if ($is_confirm_upload && isset($token)) {
        unset($_SESSION['pemakaian_bhp_upload_preview'][$token]);
    }

    $msg = "Berhasil mengupload data BHP.";
    if ($is_ajax) {
        ob_clean();
        echo json_encode(['status' => 'success', 'message' => $msg]);
        exit;
    }

    $_SESSION['success'] = $msg;
    redirect('index.php?page=pemakaian_bhp_list');

} catch (Throwable $e) {
    log_cloud("ERROR CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    log_cloud("Stack trace: " . $e->getTraceAsString());
    // Attempt rollback if in transaction
    try {
        if (method_exists($conn, 'rollback')) {
            $conn->rollback();
        }
    } catch (Exception $rollback_err) {
        // Ignore rollback errors
    }
    // Always try to release locks if they were acquired
    try {
        if (isset($locks_acquired) && !empty($locks_acquired)) {
            release_named_locks($conn, $locks_acquired);
        }
    } catch (Exception $lock_err) {
        // Ignore lock release errors
    }
    
    // Log failure
    $stmt_log = $conn->prepare("INSERT INTO inventory_upload_logs (user_id, filename, status, rows_success, rows_failed, error_details) VALUES (?, ?, 'failed', 0, ?, ?)");
    $msg = $e->getMessage();
    $stmt_log->bind_param("isis", $user_id, $filename, $row_count, $msg);
    $stmt_log->execute();

    if ($is_ajax) {
        echo json_encode(['status' => 'error', 'message' => "Gagal memproses file: " . $msg]);
        exit;
    }

    $_SESSION['error'] = "Gagal memproses file: " . $msg;
    redirect('index.php?page=pemakaian_bhp_list');
}
