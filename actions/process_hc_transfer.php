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

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan = trim((string)($_POST['catatan'] ?? ''));

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$barang_ids_raw = $_POST['barang_id'] ?? [];
$qtys_raw = $_POST['qty'] ?? [];
if (!is_array($barang_ids_raw)) $barang_ids_raw = [$barang_ids_raw];
if (!is_array($qtys_raw)) $qtys_raw = [$qtys_raw];

$items = [];
$max = max(count($barang_ids_raw), count($qtys_raw));
for ($i = 0; $i < $max; $i++) {
    $bid = (int)($barang_ids_raw[$i] ?? 0);
    $q = (float)($qtys_raw[$i] ?? 0);
    if ($bid <= 0 || $q <= 0) continue;
    if (abs($q - round($q)) > 0.00005) {
        $_SESSION['error'] = 'Qty harus bilangan bulat.';
        redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$user_hc_id);
    }
    $qi = (int)round($q);
    if (!isset($items[$bid])) $items[$bid] = 0;
    $items[$bid] += $qi;
}

if ($klinik_id <= 0 || $user_hc_id <= 0 || empty($items)) {
    $_SESSION['error'] = 'Data transfer tidak valid.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
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

$kl = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl) {
    $_SESSION['error'] = 'Klinik tidak ditemukan.';
    redirect('index.php?page=stok_petugas_hc');
}

$u = $conn->query("SELECT id, nama_lengkap, klinik_id FROM users WHERE id = $user_hc_id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
if (!$u || (int)$u['klinik_id'] !== $klinik_id) {
    $_SESSION['error'] = 'Petugas HC tidak valid untuk klinik ini.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$loc_onsite = resolve_location($conn, (string)($kl['kode_klinik'] ?? ''));
if ($loc_onsite === '') {
    $_SESSION['error'] = 'Klinik belum memiliki kode_klinik.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$loc_onsite_esc = $conn->real_escape_string($loc_onsite);

$loc_hc = resolve_location($conn, (string)($kl['kode_homecare'] ?? ''));
if ($loc_hc === '') {
    $_SESSION['error'] = 'Klinik belum memiliki kode_homecare.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}
$loc_hc_esc = $conn->real_escape_string($loc_hc);

$conn->begin_transaction();
try {
    foreach ($items as $barang_id => $qty_int) {
        $barang_id = (int)$barang_id;
        $qty_int = (int)$qty_int;
        if ($barang_id <= 0 || $qty_int <= 0) continue;

        $b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
        if (!$b) throw new Exception('Barang tidak ditemukan.');

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
        if ($qty_int > $effective_onsite) throw new Exception('Stok Onsite tidak mencukupi untuk salah satu item.');

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

        $stmt = $conn->prepare("INSERT INTO hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiisi", $klinik_id, $user_hc_id, $barang_id, $qty_int, $catatan, $created_by);
        $stmt->execute();
        $transfer_id = (int)$conn->insert_id;

        $stmt = $conn->prepare("INSERT INTO stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_by = VALUES(updated_by)");
        $qty_dec = (float)$qty_int;
        $stmt->bind_param("iiidi", $barang_id, $user_hc_id, $klinik_id, $qty_dec, $created_by);
        $stmt->execute();

        $cat = 'Transfer HC Petugas #' . $transfer_id;
        if ($catatan !== '') $cat .= ' - ' . $catatan;

        $qty_after_onsite = $effective_onsite - $qty_int;
        if ($qty_after_onsite < 0) $qty_after_onsite = 0;
        $stmt = $conn->prepare("
            INSERT INTO transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'klinik', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiiiiisi", $barang_id, $klinik_id, $qty_int, $effective_onsite, $qty_after_onsite, $transfer_id, $cat, $created_by);
        $stmt->execute();

        $qty_after_hc = $effective_hc + $qty_int;
        $stmt = $conn->prepare("
            INSERT INTO transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'hc', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiiiiisi", $barang_id, $klinik_id, $qty_int, $effective_hc, $qty_after_hc, $transfer_id, $cat, $created_by);
        $stmt->execute();
    }

    $conn->commit();
    $_SESSION['success'] = 'Transfer berhasil disimpan.';
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Gagal menyimpan transfer: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . ($role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$user_hc_id) : ''));
