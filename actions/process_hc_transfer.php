<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');
require_csrf();

// Check role access
check_role(['admin_klinik', 'super_admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=stok_petugas_hc');

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan = trim((string)($_POST['catatan'] ?? ''));

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

$dedup_key = sha1('process_hc_transfer|' . (int)($_SESSION['user_id'] ?? 0) . '|' . json_encode(array_diff_key($_POST, ['_csrf' => true])));
if (!isset($_SESSION['_dedup'])) $_SESSION['_dedup'] = [];
$now = time();
if (!empty($_SESSION['_dedup'][$dedup_key]) && ($now - (int)$_SESSION['_dedup'][$dedup_key]) < 8) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.']);
        exit;
    }
    $_SESSION['error'] = 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)($_POST['klinik_id'] ?? 0));
}
$_SESSION['_dedup'][$dedup_key] = $now;

$barang_ids_raw = $_POST['barang_id'] ?? [];
$qtys_raw = $_POST['qty'] ?? [];
$uom_modes_raw = $_POST['uom_mode'] ?? [];
if (!is_array($barang_ids_raw)) $barang_ids_raw = [$barang_ids_raw];
if (!is_array($qtys_raw)) $qtys_raw = [$qtys_raw];
if (!is_array($uom_modes_raw)) $uom_modes_raw = [$uom_modes_raw];

$items = [];
$max = max(count($barang_ids_raw), count($qtys_raw), count($uom_modes_raw));
for ($i = 0; $i < $max; $i++) {
    $bid = (int)($barang_ids_raw[$i] ?? 0);
    $q = (float)($qtys_raw[$i] ?? 0);
    $mode = trim((string)($uom_modes_raw[$i] ?? 'oper'));
    if ($bid <= 0 || $q <= 0) continue;
    $conv = $conn->query("
        SELECT COALESCE(c.multiplier, 1) AS multiplier 
        FROM inventory_barang_uom_conversion c
        JOIN inventory_barang b ON b.kode_barang = c.kode_barang
        WHERE b.id = $bid 
        LIMIT 1
    ")->fetch_assoc();
    $ratio = (float)($conv['multiplier'] ?? 1);
    if ($ratio <= 0) $ratio = 1;
    $qty_oper = ($mode === 'odoo') ? ($q / $ratio) : $q;
    $qty_oper = (float)round($qty_oper, 4);
    if ($qty_oper <= 0) continue;
    if (!isset($items[$bid])) $items[$bid] = 0;
    $items[$bid] += $qty_oper;
}

if ($klinik_id <= 0 || $user_hc_id <= 0 || empty($items)) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Data transfer tidak valid.']);
        exit;
    }
    $_SESSION['error'] = 'Data transfer tidak valid.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Klinik tidak ditemukan.']);
        exit;
    }
    $_SESSION['error'] = 'Klinik tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc');
}

$u = $conn->query("SELECT id, nama_lengkap, klinik_id FROM inventory_users WHERE id = $user_hc_id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
if (!$u || (int)$u['klinik_id'] !== $klinik_id) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Petugas HC tidak valid untuk klinik ini.']);
        exit;
    }
    $_SESSION['error'] = 'Petugas HC tidak valid untuk klinik ini.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

// Ownership Check for admin_klinik
if ($_SESSION['role'] === 'admin_klinik' && (int)$klinik_id !== (int)$_SESSION['klinik_id']) {
    throw new Exception('Akses Ditolak: Anda tidak memiliki wewenang untuk mentransfer stok di luar klinik Anda');
}

$loc_onsite = stock_resolve_location($conn, (string)($kl['kode_klinik'] ?? ''));
if ($loc_onsite === '') {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_klinik.']);
        exit;
    }
    $_SESSION['error'] = 'Klinik belum memiliki kode_klinik.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$loc_onsite_esc = $conn->real_escape_string($loc_onsite);

$loc_hc = stock_resolve_location($conn, (string)($kl['kode_homecare'] ?? ''));
if ($loc_hc === '') {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_homecare.']);
        exit;
    }
    $_SESSION['error'] = 'Klinik belum memiliki kode_homecare.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$loc_hc_esc = $conn->real_escape_string($loc_hc);

$lock_name = 'stock_hc_transfer_' . (int)$klinik_id;
$lock_esc = $conn->real_escape_string($lock_name);
$rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
$got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
if ($got_lock !== 1) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Sistem sedang memproses stok klinik ini. Coba lagi sebentar.']);
        exit;
    }
    $_SESSION['error'] = 'Sistem sedang memproses stok klinik ini. Coba lagi sebentar.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . ($role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$user_hc_id) : ''));
}

$conn->begin_transaction();
try {
    foreach ($items as $barang_id => $qty_int) {
        $barang_id = (int)$barang_id;
        $qty_oper = (float)$qty_int;
        if ($barang_id <= 0 || $qty_oper <= 0) continue;

        $b = $conn->query("SELECT id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
        if (!$b) throw new Exception('Barang tidak ditemukan.');
        $bname = (string)($b['nama_barang'] ?? '');

        // --- RACE CONDITION FIX: LOCK BOTH SOURCE AND DESTINATION ---
        // 1. Lock Clinic Stock
        $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($barang_id, $klinik_id, 0)");
        $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $barang_id AND klinik_id = $klinik_id FOR UPDATE");
        
        // 2. Lock HC Stock
        $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($barang_id, $user_hc_id, $klinik_id, 0)");
        $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $barang_id AND user_id = $user_hc_id AND klinik_id = $klinik_id FOR UPDATE");

        $ef_on = stock_effective($conn, $klinik_id, false, $barang_id);
        if (!$ef_on['ok']) throw new Exception((string)$ef_on['message']);
        $onsite_before = (float)($ef_on['on_hand'] ?? 0);

        $ef_hc = stock_effective($conn, $klinik_id, true, $barang_id);
        if (!$ef_hc['ok']) throw new Exception((string)$ef_hc['message']);
        $hc_before = (float)($ef_hc['on_hand'] ?? 0);

        $stmt = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $barang_id, $qty_oper, $catatan, $created_by);
        $stmt->execute();
        $transfer_id = (int)$conn->insert_id;

        $stmt = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_by = VALUES(updated_by)");
        $stmt->bind_param("iiidi", $barang_id, $user_hc_id, $klinik_id, $qty_oper, $created_by);
        $stmt->execute();

        $cat = 'Transfer HC Petugas #' . $transfer_id;
        if ($catatan !== '') $cat .= ' - ' . $catatan;

        $qty_after_onsite = $onsite_before - $qty_oper;
        if ($qty_after_onsite < 0) $qty_after_onsite = 0;

        // --- NEW FIX: REDUCE ONSITE CLINIC STOCK ---
        $stmt_upd_onsite = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
        $stmt_upd_onsite->bind_param("diii", $qty_oper, $created_by, $barang_id, $klinik_id);
        $stmt_upd_onsite->execute();

        $stmt = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'klinik', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $klinik_id, $qty_oper, $onsite_before, $qty_after_onsite, $transfer_id, $cat, $created_by);
        $stmt->execute();

        $qty_after_hc = $hc_before + $qty_oper;
        $stmt = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'hc', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $user_hc_id, $qty_oper, $hc_before, $qty_after_hc, $transfer_id, $cat, $created_by);
        $stmt->execute();
    }

    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Transfer berhasil disimpan.', 'redirect' => 'index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . ($role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$user_hc_id) : '')]);
        exit;
    }
    $_SESSION['success'] = 'Transfer berhasil disimpan.';
} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan transfer: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['error'] = 'Gagal menyimpan transfer: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . ($role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$user_hc_id) : ''));


