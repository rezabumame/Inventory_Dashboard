<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');
require_csrf();

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik'], true)) {
    $_SESSION['error'] = 'Anda tidak memiliki akses.';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php?page=stok_petugas_hc');

$created_by = (int)($_SESSION['user_id'] ?? 0);
$transfer_id = (int)($_POST['transfer_id'] ?? 0);
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$petugas_user_id = (int)($_POST['petugas_user_id'] ?? 0);
$tab = (string)($_POST['tab'] ?? 'history');
$history_from = (string)($_POST['history_from'] ?? '');
$history_to = (string)($_POST['history_to'] ?? '');

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
if ($transfer_id <= 0 || $klinik_id <= 0) {
    $_SESSION['error'] = 'Data tidak valid.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$dedup_key = sha1('reverse_hc_transfer|' . (int)($_SESSION['user_id'] ?? 0) . '|' . json_encode(array_diff_key($_POST, ['_csrf' => true])));
if (!isset($_SESSION['_dedup'])) $_SESSION['_dedup'] = [];
$now = time();
if (!empty($_SESSION['_dedup'][$dedup_key]) && ($now - (int)$_SESSION['_dedup'][$dedup_key]) < 8) {
    $_SESSION['error'] = 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}
$_SESSION['_dedup'][$dedup_key] = $now;

function resolve_location(mysqli $conn, string $code): string {
    $code = trim($code);
    if ($code === '') return '';
    $esc = $conn->real_escape_string($code);
    $r = $conn->query("SELECT 1 FROM inventory_stock_mirror WHERE TRIM(location_code) = '$esc' LIMIT 1");
    if ($r && $r->num_rows > 0) return $code;
    $cand = [$code . '/Stock', $code . '-Stock', $code . ' Stock'];
    foreach ($cand as $c) {
        $e = $conn->real_escape_string($c);
        $r = $conn->query("SELECT 1 FROM inventory_stock_mirror WHERE TRIM(location_code) = '$e' LIMIT 1");
        if ($r && $r->num_rows > 0) return $c;
    }
    return $code;
}

$t = $conn->query("SELECT * FROM inventory_hc_petugas_transfer WHERE id = $transfer_id AND klinik_id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$t) {
    $_SESSION['error'] = 'Transfer tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}
if ((float)($t['qty'] ?? 0) <= 0) {
    $_SESSION['error'] = 'Transfer ini tidak dapat dibatalkan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$barang_id = (int)($t['barang_id'] ?? 0);
$user_hc_id = (int)($t['user_hc_id'] ?? 0);
$qty = (float)($t['qty'] ?? 0);
if ($qty <= 0) {
    $_SESSION['error'] = 'Transfer ini tidak dapat dibatalkan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$cur = $conn->query("SELECT COALESCE(qty, 0) AS qty FROM inventory_stok_tas_hc WHERE klinik_id = $klinik_id AND user_id = $user_hc_id AND barang_id = $barang_id LIMIT 1")->fetch_assoc();
$cur_qty = (float)($cur['qty'] ?? 0);
if ($cur_qty + 0.00005 < $qty) {
    $_SESSION['error'] = 'Stok tas petugas tidak mencukupi untuk reversal. Qty saat ini: ' . fmt_qty($cur_qty);
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id . '&tab=' . urlencode($tab));
}

$kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    $_SESSION['error'] = 'Klinik tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc');
}

$b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
if (!$b) {
    $_SESSION['error'] = 'Barang tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$ef_on = stock_effective($conn, $klinik_id, false, $barang_id);
if (!$ef_on['ok']) {
    $_SESSION['error'] = (string)$ef_on['message'];
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$effective_onsite = (float)($ef_on['available'] ?? 0);

$ef_hc = stock_effective($conn, $klinik_id, true, $barang_id);
if (!$ef_hc['ok']) {
    $_SESSION['error'] = (string)$ef_hc['message'];
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$effective_hc = (float)($ef_hc['available'] ?? 0);

$lock_name = 'stock_hc_transfer_' . (int)$klinik_id;
$lock_esc = $conn->real_escape_string($lock_name);
$rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
$got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
if ($got_lock !== 1) {
    $_SESSION['error'] = 'Sistem sedang memproses stok klinik ini. Coba lagi sebentar.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$conn->begin_transaction();
try {
    $cat = 'Reversal Transfer HC Petugas #' . $transfer_id;
    $cat_detail = $cat;
    $cat_orig = trim((string)($t['catatan'] ?? ''));
    if ($cat_orig !== '') $cat_detail .= ' - ' . $cat_orig;

    $stmt = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $neg = 0 - $qty;
    $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $barang_id, $neg, $cat, $created_by);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
    $stmt->bind_param("diiii", $qty, $created_by, $barang_id, $user_hc_id, $klinik_id);
    $stmt->execute();

    $qty_after_onsite = (float)$effective_onsite + $qty;
    $stmt = $conn->prepare("
        INSERT INTO inventory_transaksi_stok
        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
        VALUES (?, 'klinik', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iidddisi", $barang_id, $klinik_id, $qty, $effective_onsite, $qty_after_onsite, $transfer_id, $cat_detail, $created_by);
    $stmt->execute();

    $qty_after_hc = (float)$effective_hc - $qty;
    if ($qty_after_hc < 0) $qty_after_hc = 0;
    $stmt = $conn->prepare("
        INSERT INTO inventory_transaksi_stok
        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
        VALUES (?, 'hc', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iidddisi", $barang_id, $user_hc_id, $qty, $effective_hc, $qty_after_hc, $transfer_id, $cat_detail, $created_by);
    $stmt->execute();

    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    $_SESSION['success'] = 'Transfer berhasil dibatalkan.';
} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    $_SESSION['error'] = 'Gagal membatalkan transfer: ' . $e->getMessage();
}

$url = 'index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab);
if ($petugas_user_id > 0) $url .= '&petugas_user_id=' . (int)$petugas_user_id;
if ($history_from !== '') $url .= '&history_from=' . urlencode($history_from);
if ($history_to !== '') $url .= '&history_to=' . urlencode($history_to);
redirect($url);


