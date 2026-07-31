<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Aksi koreksi admin: hapus SEMUA laporan mandiri 1 nakes (tipe='hc') utk sesi SO yang sedang
// berjalan di klinik ini, supaya nakes itu bisa lapor ulang dari nol (bypass lock "1x per periode"
// di api/hc_stock_validate.php, yang menolak resubmit kalau qty_fisik sudah terisi).
$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload    = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$klinik_id  = (int)($payload['klinik_id'] ?? 0);
$hc_user_id = (int)($payload['hc_user_id'] ?? 0);
if ($klinik_id <= 0 || $hc_user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
}

// Role non-admin_gudang/super_admin cuma boleh utk klinik sendiri.
if (!in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
    $own = (int)($_SESSION['klinik_id'] ?? 0);
    if ($klinik_id !== $own) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa akses klinik lain.']); exit;
    }
}

// Nakes itu harus benar-benar milik klinik ini.
$rOwn = $conn->query("SELECT id FROM inventory_users WHERE id=$hc_user_id AND klinik_id=$klinik_id LIMIT 1");
if (!$rOwn || $rOwn->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Nakes tidak ditemukan di klinik ini.']); exit;
}

try {
    // Resolusi sesi aktif — SAMA PERSIS dgn pola di save_opname.php: prioritaskan sesi unlocked,
    // fallback ke periode terakhir (bukan draft), tie-break periode DESC.
    $rOp = $conn->query("SELECT id FROM inventory_stok_opname
        WHERE klinik_id = $klinik_id AND (status IS NULL OR status != 'draft')
        ORDER BY (is_locked = 0 OR is_locked IS NULL) DESC, periode DESC, id DESC LIMIT 1");
    $opRow = $rOp ? $rOp->fetch_assoc() : null;
    $opname_id = $opRow ? (int)$opRow['id'] : 0;
    if (!$opname_id) {
        echo json_encode(['success' => false, 'message' => 'Belum ada sesi SO utk klinik ini.']); exit;
    }

    $r_ed_tbl   = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
    $has_ed_tbl = ($r_ed_tbl && $r_ed_tbl->num_rows > 0);
    $r_akt_col  = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'qty_aktual'");
    $has_akt    = ($r_akt_col && $r_akt_col->num_rows > 0);
    $r_exp_col  = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'ed_expired'");
    $has_exp    = ($r_exp_col && $r_exp_col->num_rows > 0);

    $rRows = $conn->query("SELECT id" . ($has_akt ? ', qty_aktual' : '') . "
        FROM inventory_stok_opname_detail
        WHERE opname_id = $opname_id AND hc_user_id = $hc_user_id AND tipe = 'hc'");
    $rows = [];
    while ($rRows && ($r = $rRows->fetch_assoc())) $rows[] = $r;

    $deleted = 0;
    $reset   = 0;
    foreach ($rows as $row) {
        $did = (int)$row['id'];
        if ($has_ed_tbl) {
            $conn->query("DELETE FROM inventory_stok_opname_ed_detail WHERE opname_detail_id = $did");
        }
        $has_validated = $has_akt && $row['qty_aktual'] !== null;
        if ($has_validated) {
            // Sudah divalidasi admin — jangan hilangkan hasil validasinya, cuma kosongkan bagian
            // laporan nakes-nya supaya bisa diisi ulang.
            $exp_set = $has_exp ? ', ed_expired = 0' : '';
            $conn->query("UPDATE inventory_stok_opname_detail SET
                qty_fisik = NULL, selisih = NULL, ed_lte3m = 0, ed_gt3m = 0$exp_set,
                catatan = '', status = 'validated'
                WHERE id = $did");
            $reset++;
        } else {
            // Belum ada validasi admin sama sekali — hapus barisnya total.
            $conn->query("DELETE FROM inventory_stok_opname_detail WHERE id = $did");
            $deleted++;
        }
    }

    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'reset'   => $reset,
        'message' => "Laporan nakes berhasil dihapus ($deleted item dihapus, $reset item dikosongkan krn sudah tervalidasi). Nakes bisa lapor ulang sekarang.",
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}
