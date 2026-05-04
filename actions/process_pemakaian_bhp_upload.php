<?php
ob_start(); 
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/counter.php';
require_once __DIR__ . '/../lib/stock.php';

use Shuchkin\SimpleXLSX;

/**
 * Log debugging to server error log
 */
function log_cloud($msg) {
    error_log("[BHP_UPLOAD] " . $msg);
}

function acquire_named_locks(mysqli $conn, array $lockNames, int $timeoutSeconds = 10): array {
    $acquired = [];
    foreach ($lockNames as $name) {
        $esc = $conn->real_escape_string($name);
        $r = $conn->query("SELECT GET_LOCK('$esc', " . (int)$timeoutSeconds . ") AS got");
        $got = (int)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['got'] ?? 0) : 0);
        if ($got !== 1) {
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

/**
 * Parsing date with support for multiple formats
 */
function parse_custom_date($date_str) {
    $date_str = trim((string)$date_str);
    if ($date_str === '') return false;
    $ts = strtotime($date_str);
    if ($ts !== false && $ts > 0) {
        $iso = date('Y-m-d H:i:s', $ts);
        $year = (int)date('Y', $ts);
        if ($year > 2000 && $year < 2100) return $iso;
    }
    $formats = ['d F Y, H:i', 'F d, Y, g:i A', 'F d, Y, h:i A', 'd M Y, H:i', 'M d, Y, g:i A'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $date_str);
        if ($d) return $d->format('Y-m-d H:i:s');
    }
    return false;
}

/**
 * Validation helper
 */
function add_error(&$errors, $row, $col, $msg) {
    $errors[] = ['row' => $row, 'column' => $col, 'message' => $msg];
}

/**
 * Main Controller
 */
function run_upload_process($conn) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) throw new Exception("Session expired. Silakan login kembali.");

    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || isset($_POST['ajax']);
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_upload') {
        process_confirmed_upload($conn, $user_id, $is_ajax);
    } else {
        process_initial_excel($conn, $user_id, $is_ajax);
    }
}

/**
 * STEP 1: Parse Excel and Compare with System Data (Auto Deduction from Completed Bookings)
 */
function process_initial_excel($conn, $user_id, $is_ajax) {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Gagal upload file.");
    }

    $file_path = $_FILES['excel_file']['tmp_name'];
    if (!$xlsx = SimpleXLSX::parse($file_path)) {
        throw new Exception('Gagal membaca Excel: ' . SimpleXLSX::parseError());
    }

    $rows = iterator_to_array($xlsx->rows());
    if (count($rows) < 2) throw new Exception('File kosong.');

    // Pre-cache Masters
    $master_items = [];
    $res = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang");
    while($r = $res->fetch_assoc()) $master_items[strtolower(trim($r['kode_barang']))] = $r;

    $master_nakes = [];
    $res = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc'");
    while($r = $res->fetch_assoc()) $master_nakes[strtolower(trim($r['nama_lengkap']))] = $r;

    $master_klinik = [];
    $res = $conn->query("SELECT id, nama_klinik, kode_klinik, alamat FROM inventory_klinik");
    while($r = $res->fetch_assoc()) {
        $master_klinik[strtolower(trim($r['nama_klinik']))] = $r;
        $master_klinik[strtolower(trim($r['kode_klinik']))] = $r;
        if (!empty($r['alamat'])) $master_klinik[strtolower(trim($r['alamat']))] = $r;
    }

    $errors = [];
    $excel_groups = [];
    $raw_data = [];

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue;
        
        $iso_date = parse_custom_date($row[0]);
        if (!$iso_date) { add_error($errors, $i+1, 'Tanggal', 'Format tanggal tidak dikenali'); continue; }
        
        $date_only = date('Y-m-d', strtotime($iso_date));
        $patient_id = trim($row[1] ?? '');
        $nama_pasien = trim($row[2] ?? '');
        $layanan = trim($row[3] ?? '');
        $qty = (float)($row[5] ?? 0);
        $uom = trim($row[6] ?? '');
        $nakes_name = trim($row[7] ?? '');
        $jenis_raw = strtolower(trim($row[8] ?? ''));
        $branch_name = strtolower(trim($row[9] ?? ''));
        $kode_barang = strtolower(trim($row[10] ?? ''));

        $item = $master_items[$kode_barang] ?? null;
        
        // --- Improved Branch Matching (Alias/Partial) ---
        $klinik = $master_klinik[$branch_name] ?? null;
        if (!$klinik) {
            // Try partial match if not found exactly
            foreach ($master_klinik as $key => $k) {
                if ($branch_name !== '' && (strpos($key, $branch_name) !== false || strpos($branch_name, $key) !== false)) {
                    $klinik = $k;
                    break;
                }
            }
        }
        
        $nakes = $master_nakes[strtolower($nakes_name)] ?? null;

        if (!$item) add_error($errors, $i+1, 'Kode Barang', "Barang '$kode_barang' tidak ditemukan (Kolom 11)");
        if (!$klinik) add_error($errors, $i+1, 'Cabang', "Cabang '$branch_name' tidak ditemukan (Kolom 9)");

        $jenis = ($jenis_raw === 'hc') ? 'hc' : 'klinik';
        if ($jenis === 'hc' && !$nakes) {
            add_error($errors, $i+1, 'Nama Nakes', "Untuk Jenis Pemakaian HC, Nama Nakes '$nakes_name' wajib diisi dan harus valid (Kolom 8)");
        }

        if (empty($errors)) {
            $raw_data[] = [
                'tanggal_full' => $iso_date,
                'patient_id' => $patient_id,
                'nama_pasien' => $nama_pasien,
                'layanan' => $layanan,
                'item_id' => $item['id'],
                'qty' => $qty,
                'uom' => $uom,
                'user_hc_id' => $nakes['id'] ?? 0,
                'klinik_id' => $klinik['id'],
                'nama_nakes' => $nakes_name,
                'kode_barang' => $kode_barang,
                'jenis' => $jenis
            ];

            // Grouping for Diff Calculation
            $group_key = $date_only . '|' . $klinik['id'] . '|' . $jenis . '|' . $item['id'];
            if (!isset($excel_groups[$group_key])) {
                $excel_groups[$group_key] = [
                    'tanggal' => $date_only,
                    'klinik_id' => $klinik['id'],
                    'nama_klinik' => $klinik['nama_klinik'],
                    'jenis' => $jenis,
                    'barang_id' => $item['id'],
                    'kode_barang' => $item['kode_barang'],
                    'nama_barang' => $item['nama_barang'],
                    'qty_excel' => 0
                ];
            }
            $excel_groups[$group_key]['qty_excel'] += $qty;
        }
    }

    if (!empty($errors)) {
        echo json_encode(['status' => 'error', 'errors' => array_slice($errors, 0, 10)]);
        exit;
    }

    // Step 1.2: Calculate Diffs against "Hasil Mapping Sistem" (Completed Bookings)
    $diffs = [];
    $has_auto_global = false;
    foreach ($excel_groups as $g) {
        $tgl = $g['tanggal'];
        $kid = $g['klinik_id'];
        $bid = $g['barang_id'];
        $is_hc = ($g['jenis'] === 'hc');
        
        // Check if there are ANY auto records for this date/clinic/type to determine if this is a "realization"
        if (!$has_auto_global) {
            $check_auto = $conn->query("SELECT id FROM inventory_pemakaian_bhp WHERE klinik_id = $kid AND tanggal = '$tgl' AND jenis_pemakaian = '" . $g['jenis'] . "' AND is_auto = 1 AND status = 'active' LIMIT 1");
            if ($check_auto && $check_auto->num_rows > 0) {
                $has_auto_global = true;
            }
        }

        // Count already in system (Existing Pemakaian BHP for this date/clinic/type)
        $q_sys = 0;
        $stmt_s = $conn->prepare("
            SELECT COALESCE(SUM(pbd.qty), 0) as qty
            FROM inventory_pemakaian_bhp_detail pbd
            JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
            WHERE pb.klinik_id = ? 
              AND pb.tanggal = ? 
              AND pb.jenis_pemakaian = ?
              AND pbd.barang_id = ?
              AND pb.status = 'active'
              AND pb.is_auto = 1
        ");
        $stmt_s->bind_param("issi", $kid, $tgl, $g['jenis'], $bid);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result()->fetch_assoc();
        $q_sys = (float)($res_s['qty'] ?? 0);

        $selisih = $g['qty_excel'] - $q_sys;
        
        $diffs[] = [
            'tanggal' => date('d/m/Y', strtotime($tgl)),
            'nama_klinik' => $g['nama_klinik'],
            'jenis' => strtoupper($g['jenis']),
            'kode_barang' => $g['kode_barang'],
            'nama_barang' => $g['nama_barang'],
            'existing_qty' => (float)$q_sys,
            'upload_qty' => (float)$g['qty_excel'],
            'diff' => (float)$selisih
        ];
    }

    // Step 1.3: Check for Backdate (older than H-1)
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $has_backdate = false;
    foreach ($excel_groups as $g) {
        if ($g['tanggal'] !== $today && $g['tanggal'] !== $yesterday) {
            $has_backdate = true;
            break;
        }
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['pemakaian_bhp_upload_preview'][$token] = ['data' => $raw_data];

    echo json_encode([
        'status' => 'preview', 
        'token' => $token, 
        'diffs' => $diffs,
        'has_auto' => $has_auto_global,
        'has_backdate' => $has_backdate,
        'diff_count' => count($diffs),
        'message' => count($diffs) > 0 ? 'Ditemukan perbedaan data antara Excel dan Sistem.' : 'Tidak ada perbedaan. Data upload sudah sama dengan data sistem.'
    ]);
    exit;
}

/**
 * STEP 2: Process and Save to DB with Stock Deduction
 */
function process_confirmed_upload($conn, $user_id, $is_ajax) {
    $token = $_POST['token'] ?? '';
    $payload = $_SESSION['pemakaian_bhp_upload_preview'][$token] ?? null;
    if (!$payload) throw new Exception("Sesi kadaluarsa. Silakan upload ulang.");

    $data = $payload['data'];
    $transactions = [];
    foreach ($data as $d) {
        // Group items by Nakes (user_hc_id) + Klinik + Jenis + Date to create Consolidated Header records
        // This prevents the list from being cluttered with one record per patient.
        $tgl_key = date('Y-m-d', strtotime($d['tanggal_full']));
        $key = $d['jenis'] . '|' . $d['klinik_id'] . '|' . $d['user_hc_id'] . '|' . $tgl_key;
        
        if (!isset($transactions[$key])) {
            $transactions[$key] = [
                'meta' => $d,
                'items' => [],
                'patients' => []
            ];
        }
        $transactions[$key]['items'][] = $d;
        if (!empty($d['nama_pasien'])) {
            $transactions[$key]['patients'][$d['nama_pasien']] = true;
        }
    }

    $conn->begin_transaction();
    $locks = acquire_named_locks($conn, ['inventory_bhp_process']);
    
    try {
        // --- CLEANUP AUTO-DEDUCTION BY DATE & CLINIC ---
        // We collect unique dates from transactions to clear them once
        $unique_dates = [];
        $target_klinik_id = 0;
        foreach ($transactions as $tx) {
            $m = $tx['meta'];
            $tgl = date('Y-m-d', strtotime($m['tanggal_full']));
            $unique_dates[$tgl] = true;
            $target_klinik_id = $m['klinik_id'];
        }

        foreach (array_keys($unique_dates) as $tgl) {
            // Find and Delete ALL Auto BHP records for this Clinic and Date (Both HC & Clinic)
            $res_auto = $conn->query("SELECT id FROM inventory_pemakaian_bhp WHERE is_auto = 1 AND klinik_id = $target_klinik_id AND tanggal LIKE '$tgl%'");
            while ($auto_row = $res_auto->fetch_assoc()) {
                $auto_id = $auto_row['id'];
                $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $auto_id");
                $conn->query("DELETE FROM inventory_pemakaian_bhp WHERE id = $auto_id");
            }
        }
        // ----------------------------------------------

        foreach ($transactions as $tx) {
            $m = $tx['meta'];
            $tgl = date('Y-m-d', strtotime($m['tanggal_full']));
            $created = date('Y-m-d H:i:s');
            $jenis = $m['jenis'];
            
            // Generate Number with Clinic Code
            $res_k = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = " . $m['klinik_id'] . " LIMIT 1");
            $k_row = $res_k->fetch_assoc();
            $k_code_raw = ($jenis === 'hc') ? ($k_row['kode_homecare'] ?? 'HC') : ($k_row['kode_klinik'] ?? 'CLN');
            $k_code = explode('/', $k_code_raw)[0]; // Strip /Stock
            
            $date_ym = date('ym', strtotime($tgl));
            $prefix = "BHP-" . $k_code;
            $seq = next_sequence($conn, $prefix, $date_ym);
            $nomor = $prefix . "-" . $date_ym . "-" . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            
            $patient_count = count($tx['patients']);
            $note = "Pemakaian " . strtoupper($jenis) . " oleh " . $m['nama_nakes'] . " - Gabungan $patient_count Pasien";
            if ($jenis === 'klinik') {
                $note = "Pemakaian Klinik - Gabungan $patient_count Pasien";
            }

            // Backdate Approval Logic
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $status = 'active';
            $backdate_reason = '';
            $change_source = '';
            $change_actor_name = '';
            $change_reason_code = '';

            if ($tgl !== $today && $tgl !== $yesterday) {
                $status = 'pending_approval_spv';
                $backdate_reason = $_POST['backdate_reason'] ?? '';
                $change_source = $_POST['change_source'] ?? '';
                $change_actor_name = $_POST['change_actor'] ?? '';
                $change_reason_code = $_POST['change_reason_code'] ?? '';
            }

            $change_actor_user_id = isset($_POST['change_actor_user_id']) && (int)$_POST['change_actor_user_id'] > 0 ? (int)$_POST['change_actor_user_id'] : null;

            log_cloud("Save BHP: $nomor | Status: $status | Reason: $backdate_reason | Source: $change_source | Actor: $change_actor_name | ActorID: $change_actor_user_id | ReasonCode: $change_reason_code");

            // Insert Header
            $st = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, created_at, status, approval_reason, change_source, change_actor_user_id, change_actor_name, change_reason_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("sssiisississss", $nomor, $tgl, $jenis, $m['klinik_id'], $m['user_hc_id'], $note, $user_id, $created, $status, $backdate_reason, $change_source, $change_actor_user_id, $change_actor_name, $change_reason_code);
            $st->execute();
            $pid = $conn->insert_id;

            foreach ($tx['items'] as $it) {
                $bid = (int)$it['item_id'];
                $qty = (float)$it['qty'];
                $kid = (int)$m['klinik_id'];
                $uid = (int)$m['user_hc_id'];

                // Get Stock Before
                $eff = stock_effective($conn, $kid, $jenis === 'hc', $bid);
                $qty_sebelum = (float)($eff['on_hand'] ?? 0);

                // Insert Detail
                $st = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
                $st->bind_param("iids", $pid, $bid, $qty, $it['uom']);
                $st->execute();
                
                // Deduct Stock
                if ($jenis === 'hc' && $uid > 0) {
                    // Ensure row exists before update to support negative stock
                    $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($bid, $uid, $kid, 0)");
                    
                    $st_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $st_upd->bind_param("diiii", $qty, $user_id, $bid, $uid, $kid);
                    $level = 'hc'; $level_id = $uid;
                } else {
                    // Ensure row exists before update to support negative stock
                    $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($bid, $kid, 0)");
                    
                    $st_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $st_upd->bind_param("diii", $qty, $user_id, $bid, $kid);
                    $level = 'klinik'; $level_id = $kid;
                }
                $st_upd->execute();

                // Log Transaction (History)
                $qty_sesudah = $qty_sebelum - $qty;
                $cat = "Upload Excel: $nomor";
                $st_log = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, ?, ?, 'out', ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, NOW())");
                $st_log->bind_param("isidddisi", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $pid, $cat, $user_id);
                $st_log->execute();
            }
        }
        $conn->commit();
        unset($_SESSION['pemakaian_bhp_upload_preview'][$token]);
        echo json_encode(['status' => 'success', 'message' => 'Berhasil upload dan stok telah diperbarui.']);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    } finally {
        release_named_locks($conn, $locks);
    }
    exit;
}

// EXECUTE PROCESS
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        run_upload_process($conn);
    }
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
