<?php
session_start();
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

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan = trim((string)($_POST['catatan'] ?? ''));

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

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
        FROM barang_uom_conversion c
        JOIN barang b ON b.kode_barang = c.kode_barang
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
    $_SESSION['error'] = 'Data transfer tidak valid.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
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
        $qty_oper = (float)$qty_int;
        if ($barang_id <= 0 || $qty_oper <= 0) continue;

        $b = $conn->query("SELECT id, nama_barang FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
        if (!$b) throw new Exception('Barang tidak ditemukan.');
        $bname = (string)($b['nama_barang'] ?? '');

        $ef_on = stock_effective($conn, $klinik_id, false, $barang_id);
        if (!$ef_on['ok']) throw new Exception((string)$ef_on['message']);
        $effective_onsite = (float)($ef_on['available'] ?? 0);
        if ($qty_oper > $effective_onsite + 0.00005) throw new Exception('Stok Onsite tidak mencukupi untuk item: ' . $bname);

        $ef_hc = stock_effective($conn, $klinik_id, true, $barang_id);
        if (!$ef_hc['ok']) throw new Exception((string)$ef_hc['message']);
        $effective_hc = (float)($ef_hc['available'] ?? 0);

        $stmt = $conn->prepare("INSERT INTO hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $barang_id, $qty_oper, $catatan, $created_by);
        $stmt->execute();
        $transfer_id = (int)$conn->insert_id;

        $stmt = $conn->prepare("INSERT INTO stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_by = VALUES(updated_by)");
        $stmt->bind_param("iiidi", $barang_id, $user_hc_id, $klinik_id, $qty_oper, $created_by);
        $stmt->execute();

        $cat = 'Transfer HC Petugas #' . $transfer_id;
        if ($catatan !== '') $cat .= ' - ' . $catatan;

        $qty_after_onsite = $effective_onsite - $qty_oper;
        if ($qty_after_onsite < 0) $qty_after_onsite = 0;
        $stmt = $conn->prepare("
            INSERT INTO transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'klinik', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $klinik_id, $qty_oper, $effective_onsite, $qty_after_onsite, $transfer_id, $cat, $created_by);
        $stmt->execute();

        $qty_after_hc = $effective_hc + $qty_oper;
        $stmt = $conn->prepare("
            INSERT INTO transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'hc', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $klinik_id, $qty_oper, $effective_hc, $qty_after_hc, $transfer_id, $cat, $created_by);
        $stmt->execute();
    }

    $conn->commit();
    $_SESSION['success'] = 'Transfer berhasil disimpan.';
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Gagal menyimpan transfer: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . ($role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$user_hc_id) : ''));


