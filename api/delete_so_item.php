<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload   = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$is_gudang = !empty($payload['is_gudang']);
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$barang_id = (int)($payload['barang_id'] ?? 0);
$periode   = trim($payload['periode'] ?? date('Y-m'));
$user_id   = (int)$_SESSION['user_id'];
$role      = $_SESSION['role'] ?? '';

if ($barang_id <= 0 || !preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']); exit;
}
if (!$is_gudang) {
    if (!in_array($role, ['super_admin', 'admin_gudang'])) {
        $own = (int)($_SESSION['klinik_id'] ?? 0);
        if ($klinik_id !== $own) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
        }
    }
    if ($klinik_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'klinik_id wajib.']); exit;
    }
} else {
    if (!in_array($role, ['super_admin', 'admin_gudang'])) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
    }
}

$scope     = in_array($payload['scope'] ?? 'all', ['mine', 'all', 'entry']) ? ($payload['scope'] ?? 'all') : 'all';
$detail_id = (int)($payload['detail_id'] ?? 0);
if ($scope === 'entry' && $detail_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'detail_id wajib untuk hapus 1 riwayat.']); exit;
}
// Cari sesi aktif: baris yang belum dikunci untuk lokasi ini, apa pun periodenya —
// supaya sesi yang berjalan lewat tengah malam (lintas bulan kalender) tidak terputus.
// status='draft' (sesi auto-create dari laporan nakes, belum pernah "dibuka" admin) dikecualikan —
// endpoint ini dipakai admin utk hapus riwayat hitung, tidak boleh menyentuh sesi yang belum diakui admin ada.
$draft_cond_del = "AND (status IS NULL OR status != 'draft')";
if ($is_gudang) {
    $rOp = $conn->query("SELECT id, periode, is_locked FROM inventory_stok_opname
        WHERE is_gudang_utama = 1 AND (is_locked = 0 OR is_locked IS NULL) $draft_cond_del ORDER BY id DESC LIMIT 1");
} else {
    $rOp = $conn->query("SELECT id, periode, is_locked FROM inventory_stok_opname
        WHERE klinik_id = $klinik_id AND (is_locked = 0 OR is_locked IS NULL) $draft_cond_del ORDER BY id DESC LIMIT 1");
}
$opRow = $rOp ? $rOp->fetch_assoc() : null;
if (!$opRow) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada SO aktif (sudah terkunci atau belum dibuka), tidak bisa dihapus.']); exit;
}
// Jaga-jaga: kalau client sedang menampilkan periode historis (bukan sesi aktif saat ini — mis. tab lama
// yang belum di-refresh saat periode aktif berganti), tolak supaya tidak salah menghapus di periode lain.
if ($opRow['periode'] !== $periode) {
    echo json_encode(['success' => false, 'message' => "Sesi aktif sudah berpindah ke periode {$opRow['periode']}. Muat ulang halaman."]); exit;
}
$opname_id = (int)$opRow['id'];

$r_ed_tbl   = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
$has_ed_tbl = ($r_ed_tbl && $r_ed_tbl->num_rows > 0);

if ($scope === 'entry') {
    // Hapus 1 riwayat scan spesifik (harus milik item & opname ini, tipe klinik)
    $where_detail = "id = $detail_id AND opname_id = $opname_id AND barang_id = $barang_id AND tipe = 'klinik'";
    if ($has_ed_tbl) {
        $conn->query("DELETE ed FROM inventory_stok_opname_ed_detail ed
            INNER JOIN inventory_stok_opname_detail d ON d.id = ed.opname_detail_id
            WHERE d.$where_detail");
    }
    $conn->query("DELETE FROM inventory_stok_opname_detail WHERE $where_detail");
} elseif ($scope === 'mine') {
    // Hapus baris utk 1 hc_user_id tertentu (bukan strictly "milik user login" — untuk tab HC,
    // admin/SPV memang berwenang menghapus laporan nakes LAIN di kliniknya sendiri via hc_user_id
    // eksplisit dari payload; hanya fallback ke user login sendiri kalau tidak dikirim eksplisit).
    // Sudah dibatasi ke opname_id klinik/gudang milik caller sendiri di atas — tidak bisa lintas klinik.
    $hc_uid = isset($payload['hc_user_id']) && (int)$payload['hc_user_id'] > 0
        ? (int)$payload['hc_user_id'] : $user_id;
    $where_detail = "opname_id = $opname_id AND barang_id = $barang_id AND hc_user_id = $hc_uid";
    if ($has_ed_tbl) {
        $conn->query("DELETE ed FROM inventory_stok_opname_ed_detail ed
            INNER JOIN inventory_stok_opname_detail d ON d.id = ed.opname_detail_id
            WHERE d.$where_detail");
    }
    $conn->query("DELETE FROM inventory_stok_opname_detail WHERE $where_detail");
} else {
    // Hapus semua baris klinik untuk item ini (jangan hapus baris HC nakes)
    if ($has_ed_tbl) {
        $conn->query("DELETE ed FROM inventory_stok_opname_ed_detail ed
            INNER JOIN inventory_stok_opname_detail d ON d.id = ed.opname_detail_id
            WHERE d.opname_id = $opname_id AND d.barang_id = $barang_id AND d.tipe = 'klinik'");
    }
    $conn->query("DELETE FROM inventory_stok_opname_detail
        WHERE opname_id = $opname_id AND barang_id = $barang_id AND tipe = 'klinik'");
}

$affected = $conn->affected_rows;
if ($affected > 0 || $conn->error === '') {
    echo json_encode(['success' => true, 'deleted' => $affected, 'scope' => $scope]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
