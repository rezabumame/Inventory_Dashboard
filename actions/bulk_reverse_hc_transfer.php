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
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$date = (string)($_POST['date'] ?? '');
$tab = (string)($_POST['tab'] ?? 'history');
$selected_ids = $_POST['transfer_ids'] ?? []; // Can be array from checkboxes

if ($role === 'admin_klinik') $klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

if ($klinik_id <= 0) {
    $_SESSION['error'] = 'Data klinik tidak valid.';
    redirect('index.php?page=stok_petugas_hc&tab=' . urlencode($tab));
}

$transfer_ids = [];
if (!empty($selected_ids) && is_array($selected_ids)) {
    foreach ($selected_ids as $id) if ((int)$id > 0) $transfer_ids[] = (int)$id;
} elseif ($user_hc_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    // Find all transfers for this date/user
    $date_esc = $conn->real_escape_string($date);
    $res = $conn->query("
        SELECT id 
        FROM inventory_hc_petugas_transfer 
        WHERE klinik_id = $klinik_id 
          AND user_hc_id = $user_hc_id 
          AND DATE(created_at) = '$date_esc' 
          AND qty > 0
    ");
    while ($row = $res->fetch_assoc()) $transfer_ids[] = (int)$row['id'];
}

if (empty($transfer_ids)) {
    $_SESSION['error'] = 'Tidak ada transfer yang dapat dibatalkan pada tanggal tersebut.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$lock_name = 'stock_hc_transfer_' . (int)$klinik_id;
$lock_esc = $conn->real_escape_string($lock_name);
$rl = $conn->query("SELECT GET_LOCK('$lock_esc', 15) AS got");
$got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
if ($got_lock !== 1) {
    $_SESSION['error'] = 'Sistem sedang sibuk. Coba lagi sebentar.';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab));
}

$conn->begin_transaction();
try {
    $success_count = 0;
    foreach ($transfer_ids as $transfer_id) {
        // Fetch transfer details again inside loop to be safe
        $t = $conn->query("SELECT * FROM inventory_hc_petugas_transfer WHERE id = $transfer_id FOR UPDATE")->fetch_assoc();
        if (!$t) continue;
        
        $barang_id = (int)$t['barang_id'];
        $qty = (float)$t['qty'];
        $user_hc_id = (int)$t['user_hc_id'];
        $t_klinik_id = (int)$t['klinik_id']; // Use klinik_id from transfer record!
        
        // Check bag stock
        $cur = $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE klinik_id = $t_klinik_id AND user_id = $user_hc_id AND barang_id = $barang_id FOR UPDATE")->fetch_assoc();
        $cur_qty = (float)($cur['qty'] ?? 0);
        
        if ($cur_qty + 0.00005 < $qty) {
            // Get item name for better error message
            $bi = $conn->query("SELECT nama_barang FROM inventory_barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
            $nama_barang = $bi['nama_barang'] ?? "ID $barang_id";
            throw new Exception("Stok tas petugas untuk item '$nama_barang' tidak mencukupi (Sisa: " . fmt_qty($cur_qty) . ", Perlu: " . fmt_qty($qty) . "). Pembatalan massal dibatalkan.");
        }
        
        $t_date = date('d-m-Y', strtotime($t['created_at']));
        $cat = 'Bulk Reversal Transfer HC Petugas #' . $transfer_id . ' (Transfer Tgl ' . $t_date . ')';
        $cat_detail = $cat;
        $cat_orig = trim((string)($t['catatan'] ?? ''));
        if ($cat_orig !== '') $cat_detail .= ' - ' . $cat_orig;

        // 1. Insert reversal record
        $stmt = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $neg = 0 - $qty;
        $stmt->bind_param("iiidsi", $t_klinik_id, $user_hc_id, $barang_id, $neg, $cat, $created_by);
        $stmt->execute();

        // 2. Update bag stock
        $stmt = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ? WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
        $stmt->bind_param("diiii", $qty, $created_by, $barang_id, $user_hc_id, $t_klinik_id);
        $stmt->execute();

        // 3. Update stock transactions (onsite in, hc out)
        $ef_on = stock_effective($conn, $t_klinik_id, false, $barang_id);
        $effective_onsite = (float)($ef_on['available'] ?? 0);
        $qty_after_onsite = $effective_onsite + $qty;
        
        $stmt = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'klinik', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $t_klinik_id, $qty, $effective_onsite, $qty_after_onsite, $transfer_id, $cat_detail, $created_by);
        $stmt->execute();

        $ef_hc = stock_effective($conn, $t_klinik_id, true, $barang_id);
        $effective_hc = (float)($ef_hc['available'] ?? 0);
        $qty_after_hc = $effective_hc - $qty;
        if ($qty_after_hc < 0) $qty_after_hc = 0;
        
        $stmt = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, 'hc', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iidddisi", $barang_id, $user_hc_id, $qty, $effective_hc, $qty_after_hc, $transfer_id, $cat_detail, $created_by);
        $stmt->execute();
        
        $success_count++;
    }

    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    $_SESSION['success'] = "Berhasil membatalkan $success_count item transfer untuk petugas tersebut.";
} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    $_SESSION['error'] = 'Gagal membatalkan transfer massal: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&tab=' . urlencode($tab) . '&history_view=rekap');
