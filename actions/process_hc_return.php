<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    redirect('index.php?page=login');
}
require_csrf();

// Check role access
check_role(['super_admin', 'admin_klinik']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=stok_petugas_hc');

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan = trim((string)($_POST['catatan'] ?? ''));

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

$dedup_key = sha1('process_hc_return|' . (int)($_SESSION['user_id'] ?? 0) . '|' . json_encode(array_diff_key($_POST, ['_csrf' => true])));
if (!isset($_SESSION['_dedup'])) $_SESSION['_dedup'] = [];
$now = time();
if (!empty($_SESSION['_dedup'][$dedup_key]) && ($now - (int)$_SESSION['_dedup'][$dedup_key]) < 8) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.']);
        exit;
    }
    $_SESSION['error'] = 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
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
        echo json_encode(['success' => false, 'message' => 'Data pengembalian tidak valid.']);
        exit;
    }
    $_SESSION['error'] = 'Data pengembalian tidak valid.';
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
    throw new Exception('Akses Ditolak: Anda tidak memiliki wewenang untuk memproses pengembalian stok di luar klinik Anda');
}

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
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id);
}

$conn->begin_transaction();
try {
    foreach ($items as $barang_id => $qty_oper) {
        $barang_id = (int)$barang_id;
        $qty_oper = (float)$qty_oper;
        if ($barang_id <= 0 || $qty_oper <= 0) continue;

        $b = $conn->query("SELECT id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
        if (!$b) throw new Exception('Barang tidak ditemukan.');
        $bname = (string)($b['nama_barang'] ?? '');

        // --- RACE CONDITION FIX: LOCK BOTH SOURCE AND DESTINATION ---
        // ALWAYS lock in the same order (Clinic then HC) to prevent DEADLOCKS
        // 1. Lock Clinic Stock (Destination)
        $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($barang_id, $klinik_id, 0)");
        $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $barang_id AND klinik_id = $klinik_id FOR UPDATE");

        // 2. Lock HC Stock (Source)
        $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($barang_id, $user_hc_id, $klinik_id, 0)");
        $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $barang_id AND user_id = $user_hc_id AND klinik_id = $klinik_id FOR UPDATE");

        // 2. Effective stock for transactions
        $ef_on = stock_effective($conn, $klinik_id, false, $barang_id);
        if (!$ef_on['ok']) throw new Exception((string)$ef_on['message']);
        $onsite_before = (float)($ef_on['on_hand'] ?? 0);

        $ef_hc = stock_effective($conn, $klinik_id, true, $barang_id);
        if (!$ef_hc['ok']) throw new Exception((string)$ef_hc['message']);
        $hc_before = (float)($ef_hc['on_hand'] ?? 0);

        // 3. Record in transfer history (negative qty)
        $stmt = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $neg_qty = 0 - $qty_oper;
        $cat_hist = 'Pengembalian Petugas → Onsite';
        if ($catatan !== '') $cat_hist .= ' - ' . $catatan;
        $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $barang_id, $neg_qty, $cat_hist, $created_by);
        $stmt->execute();
        $return_id = (int)$conn->insert_id;

        // 4. Update bag stock
        $stmt_upd_bag = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
        $stmt_upd_bag->bind_param("diiii", $qty_oper, $created_by, $barang_id, $user_hc_id, $klinik_id);
        $stmt_upd_bag->execute();

        // 5. Update clinic stock
        $stmt_upd_klinik = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
        $stmt_upd_klinik->bind_param("diii", $qty_oper, $created_by, $barang_id, $klinik_id);
        $stmt_upd_klinik->execute();

        $cat_trans = 'Pengembalian HC Petugas #' . $return_id;
        if ($catatan !== '') $cat_trans .= ' - ' . $catatan;

        // 6. Record transaction Onsite (IN)
        $qty_after_onsite = $onsite_before + $qty_oper;
        $stmt_trans_on = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'klinik', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt_trans_on->bind_param("iidddisi", $barang_id, $klinik_id, $qty_oper, $onsite_before, $qty_after_onsite, $return_id, $cat_trans, $created_by);
        $stmt_trans_on->execute();

        // 7. Record transaction HC (OUT)
        $qty_after_hc = $hc_before - $qty_oper;
        if ($qty_after_hc < 0) $qty_after_hc = 0;
        $stmt_trans_hc = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'hc', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt_trans_hc->bind_param("iidddisi", $barang_id, $user_hc_id, $qty_oper, $hc_before, $qty_after_hc, $return_id, $cat_trans, $created_by);
        $stmt_trans_hc->execute();
    }

    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Pengembalian berhasil disimpan.', 'redirect' => 'index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id]);
        exit;
    }
    $_SESSION['success'] = 'Pengembalian berhasil disimpan.';
} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan pengembalian: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['error'] = 'Gagal menyimpan pengembalian: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id);
