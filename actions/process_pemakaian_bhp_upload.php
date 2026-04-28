<?php
session_start();
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
 * STEP 1: Parse Excel and Show Preview
 */
function process_initial_excel($conn, $user_id, $is_ajax) {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Gagal upload file.");
    }

    $file_path = $_FILES['excel_file']['tmp_name'];
    $filename = $_FILES['excel_file']['name'];
    if (!$xlsx = SimpleXLSX::parse($file_path)) {
        throw new Exception('Gagal membaca Excel: ' . SimpleXLSX::parseError());
    }

    $rows = iterator_to_array($xlsx->rows());
    if (count($rows) < 2) throw new Exception('File kosong.');

    // Pre-cache Masters
    $master_items = [];
    $res = $conn->query("SELECT id, kode_barang, satuan FROM inventory_barang");
    while($r = $res->fetch_assoc()) $master_items[strtolower(trim($r['kode_barang']))] = $r;

    $master_nakes = [];
    $res = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc'");
    while($r = $res->fetch_assoc()) $master_nakes[strtolower(trim($r['nama_lengkap']))] = $r;

    $master_klinik = [];
    $res = $conn->query("SELECT id, nama_klinik, kode_klinik, alamat FROM inventory_klinik");
    while($r = $res->fetch_assoc()) {
        $master_klinik[strtolower(trim($r['nama_klinik']))] = $r;
        $master_klinik[strtolower(trim($r['kode_klinik']))] = $r;
        if (!empty($r['alamat'])) {
            $master_klinik[strtolower(trim($r['alamat']))] = $r;
        }
    }

    $errors = [];
    $data_to_process = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue;
        
        $iso_date = parse_custom_date($row[0]);
        $patient_id = trim($row[1] ?? '');
        $nama_pasien = trim($row[2] ?? '');
        $layanan = trim($row[3] ?? '');
        $kode_barang = strtolower(trim($row[9] ?? ''));
        $qty = (float)($row[5] ?? 0);
        $uom = trim($row[6] ?? '');
        $nakes_name = trim($row[7] ?? '');
        $branch_name = strtolower(trim($row[8] ?? ''));

        $item = $master_items[$kode_barang] ?? null;
        $klinik = $master_klinik[$branch_name] ?? null;
        $nakes = $master_nakes[strtolower($nakes_name)] ?? null;

        if (!$iso_date) add_error($errors, $i+1, 'Tanggal', 'Format tanggal tidak dikenali');
        if (!$item) add_error($errors, $i+1, 'Kode Barang', "Barang '$kode_barang' tidak ditemukan");
        if (!$klinik) add_error($errors, $i+1, 'Cabang', "Cabang '$branch_name' tidak ditemukan");

        if (empty($errors)) {
            $data_to_process[] = [
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
                'kode_barang' => $kode_barang
            ];
        }
    }

    if (!empty($errors)) {
        echo json_encode(['status' => 'error', 'errors' => array_slice($errors, 0, 10)]);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['pemakaian_bhp_upload_preview'][$token] = ['data' => $data_to_process];

    echo json_encode(['status' => 'preview', 'token' => $token, 'row_count' => count($data_to_process)]);
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
        $key = $d['patient_id'] . '|' . date('Y-m-d', strtotime($d['tanggal_full']));
        $transactions[$key]['items'][] = $d;
    }

    $conn->begin_transaction();
    $locks = acquire_named_locks($conn, ['inventory_bhp_process']);
    
    try {
        foreach ($transactions as $tx) {
            $m = $tx['items'][0];
            $tgl = date('Y-m-d', strtotime($m['tanggal_full']));
            $created = date('Y-m-d H:i:s');
            $jenis = (!empty($m['nama_nakes']) || $m['user_hc_id'] > 0) ? 'hc' : 'klinik';
            
            // Generate Number
            $date_key = date('ymd', strtotime($tgl));
            $seq = next_sequence($conn, 'BHP', $date_key);
            $nomor = "BHP-$date_key-" . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $note = $m['nama_pasien'] . " (" . $m['patient_id'] . ") - " . $m['layanan'];

            // Insert Header
            $st = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $st->bind_param("sssiisis", $nomor, $tgl, $jenis, $m['klinik_id'], $m['user_hc_id'], $note, $user_id, $created);
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
                $st = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan, created_by) VALUES (?, ?, ?, ?, ?)");
                $st->bind_param("idisi", $pid, $bid, $qty, $it['uom'], $user_id);
                $st->execute();
                
                // Deduct Stock
                if ($jenis === 'hc' && $uid > 0) {
                    $st_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $st_upd->bind_param("diiii", $qty, $user_id, $bid, $uid, $kid);
                    $level = 'hc'; $level_id = $uid;
                } else {
                    $st_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $st_upd->bind_param("diii", $qty, $user_id, $bid, $kid);
                    $level = 'klinik'; $level_id = $kid;
                }
                $st_upd->execute();

                // Log Transaction (History)
                $qty_sesudah = $qty_sebelum - $qty;
                $cat = "Upload Excel: $nomor";
                $st_log = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, ?, ?, 'out', ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, NOW())");
                $st_log->bind_param("isiddddissi", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $pid, $cat, $user_id);
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
