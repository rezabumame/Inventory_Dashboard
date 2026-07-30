<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Seluruh logic dibungkus try/catch(Throwable) supaya error PHP apa pun selalu balik sbg JSON
// yang jelas, bukan halaman error HTML mentah yang bikin JS gagal parse.
try {

// Kolom status di beberapa environment (mis. live) masih ENUM('draft','selesai','batal') tanpa
// 'open'/'locked' — di mode SQL strict itu bikin UPDATE gagal ("Data truncated for column
// status"). Pastikan dulu nilai yang dipakai kode ini sah di enum-nya (no-op kalau sudah ada).
ensure_enum_value($conn, 'inventory_stok_opname', 'status', 'open');
ensure_enum_value($conn, 'inventory_stok_opname', 'status', 'locked');

// Hanya super_admin yang bisa lock / unlock
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Hanya super_admin yang bisa mengunci / membuka SO.']); exit;
}

$payload   = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$is_gudang = !empty($payload['is_gudang']);
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$periode   = trim($payload['periode'] ?? '');
$action    = ($payload['action'] ?? '') === 'unlock' ? 'unlock' : 'lock';
$user_id   = (int)$_SESSION['user_id'];

if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']); exit;
}
if (!$is_gudang && $klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']); exit;
}

$safe_per = $conn->real_escape_string($periode);

// status='draft' (sesi auto-create dari laporan nakes, belum pernah "dibuka" admin) dikecualikan —
// tidak boleh dikunci langsung, harus lewat "Buka SO" dulu (yang mempromosikan baris draft ini).
if ($is_gudang) {
    $r = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
} else {
    $r = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname WHERE klinik_id = $klinik_id AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
}
$row = $r ? $r->fetch_assoc() : null;

if (!$row) { echo json_encode(['success' => false, 'message' => "SO periode $periode tidak ditemukan."]); exit; }
if ($row['status'] === 'draft') { echo json_encode(['success' => false, 'message' => "SO periode $periode belum pernah dibuka admin."]); exit; }
$opname_id = (int)$row['id'];

$now = date('Y-m-d H:i:s');
if ($action === 'lock') {
    if ((int)$row['is_locked']) { echo json_encode(['success' => false, 'message' => 'SO sudah terkunci.']); exit; }
    $conn->query("UPDATE inventory_stok_opname SET is_locked = 1, locked_by = $user_id, locked_at = '$now', status = 'locked' WHERE id = $opname_id");
} else {
    if (!(int)$row['is_locked']) { echo json_encode(['success' => false, 'message' => 'SO belum terkunci.']); exit; }
    // Cegah 2 sesi terbuka bersamaan: kalau lokasi ini sudah punya sesi lain (periode beda) yang
    // masih belum dikunci, jangan buka kembali periode lama ini dulu.
    $loc_cond = $is_gudang ? "is_gudang_utama = 1" : "klinik_id = $klinik_id";
    $rActive = $conn->query("SELECT periode FROM inventory_stok_opname
        WHERE $loc_cond AND (is_locked = 0 OR is_locked IS NULL) ORDER BY id DESC LIMIT 1");
    $activeRow = $rActive ? $rActive->fetch_assoc() : null;
    if ($activeRow && $activeRow['periode'] !== $periode) {
        echo json_encode(['success' => false, 'message' => "Sesi periode {$activeRow['periode']} masih berjalan (belum dikunci). Kunci itu dulu sebelum membuka kembali periode lama."]); exit;
    }
    $conn->query("UPDATE inventory_stok_opname SET is_locked = 0, locked_by = NULL, locked_at = NULL, status = 'open' WHERE id = $opname_id");
}

echo json_encode(['success' => true, 'action' => $action, 'opname_id' => $opname_id]);

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}
