<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
require_csrf();

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses.']); exit;
}

$created_by = (int)$_SESSION['user_id'];
$klinik_id  = (int)($_POST['klinik_id'] ?? 0);

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Klinik tidak valid.']); exit;
}

// Ambil kode_klinik untuk query Odoo mirror
$kl_row = $conn->query("SELECT kode_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$kode_klinik_esc = $conn->real_escape_string(trim((string)($kl_row['kode_klinik'] ?? '')));

// Ambil semua pending pull — stok klinik dari Odoo mirror
$res = $conn->query("
    SELECT pp.id, pp.barang_id, pp.qty, b.kode_barang, b.nama_barang,
           COALESCE(uc.to_uom, b.satuan) AS satuan,
           COALESCE(uc.multiplier, 1) AS ratio,
           COALESCE((
               SELECT SUM(sm.qty)
               FROM inventory_stock_mirror sm
               WHERE TRIM(sm.location_code) = '$kode_klinik_esc'
               AND (TRIM(sm.kode_barang) = b.kode_barang OR TRIM(sm.odoo_product_id) = b.odoo_product_id)
           ), 0) AS stok_klinik_odoo
    FROM inventory_hc_pending_pull pp
    JOIN inventory_barang b ON b.id = pp.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE pp.klinik_id = $klinik_id
");

$items = [];
while ($res && ($row = $res->fetch_assoc())) $items[] = $row;

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada pending pull untuk klinik ini.']); exit;
}

$conn->begin_transaction();
try {
    $pulled_count  = 0;
    $skipped_count = 0;
    $skipped_items = [];
    $now = date('Y-m-d H:i:s');

    foreach ($items as $item) {
        $bid         = (int)$item['barang_id'];
        $qty_need    = (float)$item['qty'];
        $ratio_item  = max((float)($item['ratio'] ?? 1), 0.000001);
        $stok_klinik = round((float)$item['stok_klinik_odoo'] / $ratio_item, 4);
        $ratio       = max((float)$item['ratio'], 0.000001);
        $label       = trim($item['kode_barang'] . ' - ' . $item['nama_barang']);

        if ($qty_need <= 0.00001) {
            $conn->query("DELETE FROM inventory_hc_pending_pull WHERE id = " . (int)$item['id']);
            continue;
        }

        if ($stok_klinik < $qty_need - 0.00005) {
            // Stok klinik tidak cukup → skip, tetap di pending
            $skipped_count++;
            $skipped_items[] = $label . ' (butuh ' . fmt_qty($qty_need) . ', tersedia ' . fmt_qty($stok_klinik) . ')';
            continue;
        }

        // Lock stok klinik
        $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $bid AND klinik_id = $klinik_id FOR UPDATE");

        $catatan_esc = $conn->real_escape_string($item['catatan'] ?: 'Pull dari klinik ke HC (SO)');

        // Deduct dari stok klinik
        $conn->query("
            UPDATE inventory_stok_gudang_klinik
            SET qty = qty - $qty_need
            WHERE barang_id = $bid AND klinik_id = $klinik_id
        ");

        // Catat transaksi stok: klinik OUT
        $conn->query("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, referensi_tipe, catatan, created_by, created_at)
            VALUES ($bid, 'klinik', $klinik_id, 'out', $qty_need, 'hc_petugas_transfer',
                    '$catatan_esc', $created_by, '$now')
        ");

        // Catat transaksi stok: HC IN (unallocated pool — level_id = klinik_id)
        $conn->query("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, referensi_tipe, catatan, created_by, created_at)
            VALUES ($bid, 'hc', $klinik_id, 'in', $qty_need, 'hc_petugas_transfer',
                    '$catatan_esc', $created_by, '$now')
        ");

        // Catat ke History Transfer (user_hc_id = 0 = tidak dikunci ke nakes tertentu)
        $user_hc_zero = 0;
        $stmt_hist = $conn->prepare("
            INSERT INTO inventory_hc_tas_allocation
            (klinik_id, user_hc_id, barang_id, qty, catatan, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt_hist->bind_param("iiidsi", $klinik_id, $user_hc_zero, $bid, $qty_need, $catatan_esc, $created_by);
        $stmt_hist->execute();

        // Hapus dari pending_pull
        $conn->query("DELETE FROM inventory_hc_pending_pull WHERE id = " . (int)$item['id']);

        $pulled_count++;
    }

    $conn->commit();

    $msg = 'Pull berhasil: ' . $pulled_count . ' item dipindahkan dari stok klinik ke HC.';
    if ($skipped_count > 0) {
        $msg .= ' ' . $skipped_count . ' item dilewati karena stok klinik tidak cukup: ' . implode('; ', $skipped_items);
    }

    echo json_encode(['success' => true, 'message' => $msg, 'pulled' => $pulled_count, 'skipped' => $skipped_count]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Gagal pull: ' . $e->getMessage()]);
}
