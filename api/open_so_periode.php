<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload   = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$is_gudang = !empty($payload['is_gudang']);
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$periode   = trim($payload['periode'] ?? date('Y-m'));
$user_id   = (int)$_SESSION['user_id'];

if (!$is_gudang && $klinik_id <= 0) { echo json_encode(['success' => false, 'message' => 'klinik_id wajib.']); exit; }
if (!preg_match('/^\d{4}-\d{2}$/', $periode)) { echo json_encode(['success' => false, 'message' => 'Format periode tidak valid (YYYY-MM).']); exit; }

$safe_per = $conn->real_escape_string($periode);

// Advisory lock per lokasi supaya 2 klik "Buka SO" nyaris bersamaan tidak lolos berbarengan
// melewati pengecekan "belum ada sesi aktif" dan sama-sama bikin baris baru (race condition).
$lock_name = $conn->real_escape_string($is_gudang ? 'so_open_gudang' : "so_open_klinik_$klinik_id");
$rLock = $conn->query("SELECT GET_LOCK('$lock_name', 5) AS got");
$got_lock = $rLock && (int)($rLock->fetch_assoc()['got'] ?? 0) === 1;
if (!$got_lock) {
    echo json_encode(['success' => false, 'message' => 'Sedang diproses permintaan lain, coba lagi sebentar.']); exit;
}
function so_open_release($conn, $lock_name) { $conn->query("SELECT RELEASE_LOCK('$lock_name')"); }

if ($is_gudang) {
    // Cek apakah kolom is_gudang_utama sudah ada
    $r_col = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_gudang_utama'");
    $has_gudang_col = ($r_col && $r_col->num_rows > 0);
    if (!$has_gudang_col) {
        so_open_release($conn, $lock_name);
        echo json_encode(['success' => false, 'message' => 'Migration belum dijalankan. Jalankan scripts/migrate.php terlebih dahulu.']); exit;
    }

    $r = $conn->query("SELECT id, periode, is_locked FROM inventory_stok_opname WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    $existing = $r ? $r->fetch_assoc() : null;
    if ($existing) {
        so_open_release($conn, $lock_name);
        if ((int)$existing['is_locked']) {
            echo json_encode(['success' => false, 'message' => "SO Gudang periode $periode sudah terkunci."]); exit;
        }
        echo json_encode(['success' => false, 'message' => "SO Gudang periode $periode sudah terbuka."]); exit;
    }
    // Cegah buka periode baru selagi masih ada sesi lain yang belum dikunci (mis. sesi yang berjalan lewat tengah malam)
    $rActive = $conn->query("SELECT periode FROM inventory_stok_opname
        WHERE is_gudang_utama = 1 AND (is_locked = 0 OR is_locked IS NULL) ORDER BY id DESC LIMIT 1");
    $active = $rActive ? $rActive->fetch_assoc() : null;
    if ($active) {
        so_open_release($conn, $lock_name);
        echo json_encode(['success' => false, 'message' => "SO Gudang periode {$active['periode']} masih berjalan (belum dikunci). Kunci dulu sebelum membuka periode baru."]); exit;
    }
    $now = date('Y-m-d H:i:s');
    $conn->query("INSERT INTO inventory_stok_opname (klinik_id, is_gudang_utama, user_id, tanggal_mulai, tanggal_selesai, status, catatan, periode, is_locked)
        VALUES (NULL, 1, $user_id, '$now', '$now', 'open', '', '$safe_per', 0)");
    $new_id = (int)$conn->insert_id;
    so_open_release($conn, $lock_name);
    if ($new_id) {
        echo json_encode(['success' => true, 'opname_id' => $new_id, 'periode' => $periode]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat SO Gudang: ' . $conn->error]);
    }
} else {
    $r = $conn->query("SELECT id, periode, is_locked FROM inventory_stok_opname WHERE klinik_id = $klinik_id AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    $existing = $r ? $r->fetch_assoc() : null;
    if ($existing) {
        so_open_release($conn, $lock_name);
        if ((int)$existing['is_locked']) {
            echo json_encode(['success' => false, 'message' => "SO periode $periode sudah terkunci. Hanya super_admin yang bisa membuka kembali."]); exit;
        }
        echo json_encode(['success' => false, 'message' => "SO periode $periode sudah terbuka."]); exit;
    }
    // Cegah buka periode baru selagi masih ada sesi lain yang belum dikunci (mis. sesi yang berjalan lewat tengah malam)
    $rActive = $conn->query("SELECT periode FROM inventory_stok_opname
        WHERE klinik_id = $klinik_id AND (is_locked = 0 OR is_locked IS NULL) ORDER BY id DESC LIMIT 1");
    $active = $rActive ? $rActive->fetch_assoc() : null;
    if ($active) {
        so_open_release($conn, $lock_name);
        echo json_encode(['success' => false, 'message' => "SO periode {$active['periode']} masih berjalan (belum dikunci). Kunci dulu sebelum membuka periode baru."]); exit;
    }
    $now = date('Y-m-d H:i:s');
    $conn->query("INSERT INTO inventory_stok_opname (klinik_id, user_id, tanggal_mulai, tanggal_selesai, status, catatan, periode, is_locked)
        VALUES ($klinik_id, $user_id, '$now', '$now', 'open', '', '$safe_per', 0)");
    $new_id = (int)$conn->insert_id;
    so_open_release($conn, $lock_name);
    if ($new_id) {
        echo json_encode(['success' => true, 'opname_id' => $new_id, 'periode' => $periode]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat SO: ' . $conn->error]);
    }
}
