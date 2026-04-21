<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    redirect('index.php?page=login');
}
require_csrf();

$role = (string)($_SESSION['role'] ?? '');
// Allow super_admin, admin_klinik, and spv_klinik to perform mass allocation SO
if (!in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses.']);
        exit;
    }
    $_SESSION['error'] = 'Anda tidak memiliki akses.';
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=stok_petugas_hc');
}

$created_by = (int)$_SESSION['user_id'];
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$user_hc_id = (int)($_POST['user_hc_id'] ?? 0);
$catatan_header = trim((string)($_POST['catatan'] ?? ''));

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

$barang_ids = $_POST['barang_id'] ?? [];
$qtys_lama = $_POST['qty_lama'] ?? [];
$qtys_so = $_POST['qty_so'] ?? [];
$uom_modes = $_POST['uom_mode'] ?? [];

if (!is_array($barang_ids)) $barang_ids = [$barang_ids];
if (!is_array($qtys_lama)) $qtys_lama = [$qtys_lama];
if (!is_array($qtys_so)) $qtys_so = [$qtys_so];
if (!is_array($uom_modes)) $uom_modes = [$uom_modes];

$items = [];
$count_items = count($barang_ids);
for ($i = 0; $i < $count_items; $i++) {
    $bid = (int)($barang_ids[$i] ?? 0);
    $qs = (float)($qtys_so[$i] ?? 0);
    $mode = trim((string)($uom_modes[$i] ?? 'oper'));

    if ($bid <= 0) continue;

    $items[] = [
        'barang_id' => $bid,
        'qty_so' => $qs,
        'uom_mode' => $mode
    ];
}

if ($klinik_id <= 0 || $user_hc_id <= 0 || empty($items)) {
    $msg = empty($items) ? 'Tidak ada data item yang dikirim.' : 'Data tidak valid.';
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $_SESSION['error'] = $msg;
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . $klinik_id);
}

// Check clinic and user
$kl = $conn->query("SELECT id, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$kl || trim((string)($kl['kode_homecare'] ?? '')) === '') {
    $msg = 'Klinik tidak valid atau belum memiliki kode homecare.';
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $_SESSION['error'] = $msg;
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . $klinik_id);
}
$kode_homecare = trim((string)$kl['kode_homecare']);

$u = $conn->query("SELECT id, klinik_id FROM inventory_users WHERE id = $user_hc_id AND role = 'petugas_hc' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$u || (int)($u['klinik_id'] ?? 0) !== $klinik_id) {
    $msg = 'Petugas HC tidak valid untuk klinik ini.';
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $_SESSION['error'] = $msg;
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . $klinik_id);
}

$conn->begin_transaction();
try {
    $processed_count = 0;
    foreach ($items as $item) {
        $bid = (int)$item['barang_id'];
        $qty_so = (float)$item['qty_so'];
        $uom_mode = (string)$item['uom_mode'];

        // 1. Fetch latest data from DB (ratio and current stock)
        $b = $conn->query("
            SELECT b.id, b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(c.multiplier, 1) AS ratio
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion c ON b.kode_barang = c.kode_barang
            WHERE b.id = $bid 
            LIMIT 1
        ")->fetch_assoc();
        
        if (!$b) throw new Exception('Barang ID ' . $bid . ' tidak ditemukan.');
        
        $ratio = (float)($b['ratio'] ?? 1);
        if ($ratio <= 0) $ratio = 1;
        
        $res_curr = $conn->query("SELECT COALESCE(SUM(qty), 0) AS qty FROM inventory_stok_tas_hc WHERE user_id = $user_hc_id AND klinik_id = $klinik_id AND barang_id = $bid");
        $latest_qty_db = (float)($res_curr && $res_curr->num_rows > 0 ? ($res_curr->fetch_assoc()['qty'] ?? 0) : 0);

        // 2. Calculate target SO in operational units
        $qty_so_oper = ($uom_mode === 'odoo') ? ($qty_so / $ratio) : $qty_so;
        
        // 3. Calculate actual diff (delta)
        $diff = (float)round($qty_so_oper - $latest_qty_db, 4);

        // 4. Safety Check: If diff > 0, we are adding stock. Check unallocated mirror.
        if ($diff > 0.00001) {
            $kode_barang = trim((string)($b['kode_barang'] ?? ''));
            $odoo_product_id = trim((string)($b['odoo_product_id'] ?? ''));

            $clauses = [];
            if ($kode_barang !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kode_barang) . "'";
            if ($odoo_product_id !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($odoo_product_id) . "'";
            if (empty($clauses)) $clauses[] = "1=0";
            $match = '(' . implode(' OR ', $clauses) . ')';

            $r = $conn->query("SELECT COALESCE(SUM(qty), 0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '" . $conn->real_escape_string($kode_homecare) . "' AND $match");
            $mirror_qty = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
            $mirror_converted = (float)($mirror_qty / $ratio);

            $r = $conn->query("SELECT COALESCE(SUM(qty), 0) AS total FROM inventory_stok_tas_hc WHERE klinik_id = $klinik_id AND barang_id = $bid");
            $allocated_item = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['total'] ?? 0) : 0);
            $unallocated = $mirror_converted - $allocated_item;
            if ($unallocated < 0) $unallocated = 0;

            if ($diff > $unallocated + 0.00005) {
                $label = trim((string)($b['nama_barang'] ?? 'Barang'));
                if ($kode_barang !== '') $label = $kode_barang . ' - ' . $label;
                throw new Exception('Unallocated tidak cukup untuk penambahan item: ' . $label . '. Unallocated: ' . fmt_qty($unallocated) . ', Dibutuhkan: ' . fmt_qty($diff));
            }
        }

        // 5. Safety Check: Ensure final stock is not negative
        if ($qty_so_oper < -0.00001) {
             $label = trim((string)($b['nama_barang'] ?? 'Barang'));
             throw new Exception('Stok hasil SO untuk ' . $label . ' tidak boleh negatif.');
        }

        $catatan = $catatan_header ?: 'Stock Opname / Alokasi Ulang';
        
        // Log to allocation history
        $stmt = $conn->prepare("INSERT INTO inventory_hc_tas_allocation (klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsi", $klinik_id, $user_hc_id, $bid, $diff, $catatan, $created_by);
        $stmt->execute();

        // Update current stock
        $stmt = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_by = VALUES(updated_by)");
        $stmt->bind_param("iiidi", $bid, $user_hc_id, $klinik_id, $diff, $created_by);
        $stmt->execute();
        
        $processed_count++;
    }

    if ($processed_count === 0) {
        throw new Exception('Tidak ada perubahan stok yang dideteksi.');
    }

    $conn->commit();
    $msg = 'Alokasi ulang berhasil disimpan (' . $processed_count . ' item disesuaikan).';
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $msg, 'redirect' => 'index.php?page=stok_petugas_hc&klinik_id=' . $klinik_id . '&petugas_user_id=' . $user_hc_id]);
        exit;
    }
    $_SESSION['success'] = $msg;
} catch (Exception $e) {
    $conn->rollback();
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan alokasi ulang: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['error'] = 'Gagal menyimpan alokasi ulang: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . $klinik_id . '&petugas_user_id=' . $user_hc_id);
