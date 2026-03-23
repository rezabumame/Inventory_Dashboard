<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) redirect('index.php?page=login');

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

$conn->query("
    CREATE TABLE IF NOT EXISTS stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS barang_uom_conversion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barang_id INT NOT NULL,
            from_uom VARCHAR(20) NULL,
            to_uom VARCHAR(20) NULL,
            multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_barang (barang_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
}
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS stock_mirror (
            id INT AUTO_INCREMENT PRIMARY KEY,
            odoo_product_id VARCHAR(64) NOT NULL,
            kode_barang VARCHAR(64) NOT NULL,
            location_code VARCHAR(100) NOT NULL,
            qty DECIMAL(18,4) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
}
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS hc_petugas_transfer (
            id INT AUTO_INCREMENT PRIMARY KEY,
            klinik_id INT NOT NULL,
            user_hc_id INT NOT NULL,
            barang_id INT NOT NULL,
            qty INT NOT NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_klinik (klinik_id),
            KEY idx_user (user_hc_id),
            KEY idx_barang (barang_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
}

function resolve_location(mysqli $conn, string $code): string {
    $code = trim($code);
    if ($code === '') return '';
    $esc = $conn->real_escape_string($code);
    $r = $conn->query("SELECT 1 FROM stock_mirror WHERE TRIM(location_code) = '$esc' LIMIT 1");
    if ($r && $r->num_rows > 0) return $code;
    $cand = [$code . '/Stock', $code . '-Stock', $code . ' Stock'];
    foreach ($cand as $c) {
        $e = $conn->real_escape_string($c);
        $r = $conn->query("SELECT 1 FROM stock_mirror WHERE TRIM(location_code) = '$e' LIMIT 1");
        if ($r && $r->num_rows > 0) return $c;
    }
    return $code;
}

$t = $conn->query("SELECT * FROM hc_petugas_transfer WHERE id = $transfer_id AND klinik_id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$t) {
    $_SESSION['error'] = 'Transfer tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}
if ((int)($t['qty'] ?? 0) <= 0) {
    $_SESSION['error'] = 'Transfer ini tidak dapat dibatalkan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$barang_id = (int)($t['barang_id'] ?? 0);
$user_hc_id = (int)($t['user_hc_id'] ?? 0);
$qty_int = (int)($t['qty'] ?? 0);

$cur = $conn->query("SELECT COALESCE(qty, 0) AS qty FROM stok_tas_hc WHERE klinik_id = $klinik_id AND user_id = $user_hc_id AND barang_id = $barang_id LIMIT 1")->fetch_assoc();
$cur_qty = (int)floor((float)($cur['qty'] ?? 0));
if ($cur_qty < $qty_int) {
    $_SESSION['error'] = 'Stok tas petugas tidak mencukupi untuk reversal. Qty saat ini: ' . $cur_qty;
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id . '&tab=' . urlencode($tab));
}

$kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    $_SESSION['error'] = 'Klinik tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc');
}

$b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
if (!$b) {
    $_SESSION['error'] = 'Barang tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$conv = $conn->query("SELECT multiplier FROM barang_uom_conversion WHERE barang_id = $barang_id LIMIT 1")->fetch_assoc();
$mult = (float)($conv['multiplier'] ?? 1);
if ($mult <= 0) $mult = 1;

$kode_barang = trim((string)($b['kode_barang'] ?? ''));
$odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));
$clauses = [];
if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kode_barang) . "'";
if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($odoo_product_id) . "'";
if (empty($clauses)) $clauses[] = "1=0";
$match = '(' . implode(' OR ', $clauses) . ')';

$loc_onsite = resolve_location($conn, (string)($kl['kode_klinik'] ?? ''));
$loc_hc = resolve_location($conn, (string)($kl['kode_homecare'] ?? ''));
if ($loc_onsite === '' || $loc_hc === '') {
    $_SESSION['error'] = 'Kode lokasi klinik/homecare belum lengkap.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$loc_onsite_esc = $conn->real_escape_string($loc_onsite);
$loc_hc_esc = $conn->real_escape_string($loc_hc);

$baseline_onsite = 0.0;
$r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE TRIM(location_code) = '$loc_onsite_esc' AND $match");
if ($r && $r->num_rows > 0) $baseline_onsite = (float)($r->fetch_assoc()['qty'] ?? 0) * $mult;

$last_update_onsite = '';
$r = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror WHERE TRIM(location_code) = '$loc_onsite_esc'");
if ($r && $r->num_rows > 0) $last_update_onsite = (string)($r->fetch_assoc()['last_update'] ?? '');

$pending_out = 0;
$pending_in = 0;
if ($last_update_onsite !== '') {
    $lu = $conn->real_escape_string($last_update_onsite);
    $r = $conn->query("SELECT COALESCE(SUM(qty),0) AS qty FROM transaksi_stok WHERE level = 'klinik' AND level_id = $klinik_id AND tipe_transaksi = 'out' AND referensi_tipe = 'hc_petugas_transfer' AND created_at > '$lu' AND barang_id = $barang_id");
    if ($r && $r->num_rows > 0) $pending_out = (int)($r->fetch_assoc()['qty'] ?? 0);
    $r = $conn->query("SELECT COALESCE(SUM(qty),0) AS qty FROM transaksi_stok WHERE level = 'klinik' AND level_id = $klinik_id AND tipe_transaksi = 'in' AND referensi_tipe = 'hc_petugas_transfer' AND created_at > '$lu' AND barang_id = $barang_id");
    if ($r && $r->num_rows > 0) $pending_in = (int)($r->fetch_assoc()['qty'] ?? 0);
}
$effective_onsite = (int)floor($baseline_onsite) + $pending_in - $pending_out;
if ($effective_onsite < 0) $effective_onsite = 0;

$baseline_hc = 0.0;
$r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE TRIM(location_code) = '$loc_hc_esc' AND $match");
if ($r && $r->num_rows > 0) $baseline_hc = (float)($r->fetch_assoc()['qty'] ?? 0) * $mult;

$last_update_hc = '';
$r = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror WHERE TRIM(location_code) = '$loc_hc_esc'");
if ($r && $r->num_rows > 0) $last_update_hc = (string)($r->fetch_assoc()['last_update'] ?? '');

$pending_hc_out = 0;
$pending_hc_in = 0;
if ($last_update_hc !== '') {
    $lu = $conn->real_escape_string($last_update_hc);
    $r = $conn->query("SELECT COALESCE(SUM(qty),0) AS qty FROM transaksi_stok WHERE level = 'hc' AND level_id = $klinik_id AND tipe_transaksi = 'out' AND referensi_tipe = 'hc_petugas_transfer' AND created_at > '$lu' AND barang_id = $barang_id");
    if ($r && $r->num_rows > 0) $pending_hc_out = (int)($r->fetch_assoc()['qty'] ?? 0);
    $r = $conn->query("SELECT COALESCE(SUM(qty),0) AS qty FROM transaksi_stok WHERE level = 'hc' AND level_id = $klinik_id AND tipe_transaksi = 'in' AND referensi_tipe = 'hc_petugas_transfer' AND created_at > '$lu' AND barang_id = $barang_id");
    if ($r && $r->num_rows > 0) $pending_hc_in = (int)($r->fetch_assoc()['qty'] ?? 0);
}
$effective_hc = (int)floor($baseline_hc) + $pending_hc_in - $pending_hc_out;
if ($effective_hc < 0) $effective_hc = 0;

$conn->begin_transaction();
try {
    $cat = 'Reversal Transfer HC Petugas #' . $transfer_id;
    $cat_detail = $cat;
    $cat_orig = trim((string)($t['catatan'] ?? ''));
    if ($cat_orig !== '') $cat_detail .= ' - ' . $cat_orig;

    $stmt = $conn->prepare("INSERT INTO hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $neg = 0 - $qty_int;
    $stmt->bind_param("iiiisi", $klinik_id, $user_hc_id, $barang_id, $neg, $cat, $created_by);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE stok_tas_hc SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
    $qty_dec = (float)$qty_int;
    $stmt->bind_param("diiii", $qty_dec, $created_by, $barang_id, $user_hc_id, $klinik_id);
    $stmt->execute();

    $qty_after_onsite = $effective_onsite + $qty_int;
    $stmt = $conn->prepare("
        INSERT INTO transaksi_stok
        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
        VALUES (?, 'klinik', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iiiiiisi", $barang_id, $klinik_id, $qty_int, $effective_onsite, $qty_after_onsite, $transfer_id, $cat_detail, $created_by);
    $stmt->execute();

    $qty_after_hc = $effective_hc - $qty_int;
    if ($qty_after_hc < 0) $qty_after_hc = 0;
    $stmt = $conn->prepare("
        INSERT INTO transaksi_stok
        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
        VALUES (?, 'hc', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iiiiiisi", $barang_id, $klinik_id, $qty_int, $effective_hc, $qty_after_hc, $transfer_id, $cat_detail, $created_by);
    $stmt->execute();

    $conn->commit();
    $_SESSION['success'] = 'Transfer berhasil dibatalkan.';
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Gagal membatalkan transfer: ' . $e->getMessage();
}

$url = 'index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab);
if ($petugas_user_id > 0) $url .= '&petugas_user_id=' . (int)$petugas_user_id;
if ($history_from !== '') $url .= '&history_from=' . urlencode($history_from);
if ($history_to !== '') $url .= '&history_to=' . urlencode($history_to);
redirect($url);
