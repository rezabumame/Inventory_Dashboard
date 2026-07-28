<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Hanya super_admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Hanya super_admin yang bisa mereset SO.']); exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$scope     = ($payload['scope'] ?? 'single') === 'all' ? 'all' : 'single';
$periode   = trim($payload['periode'] ?? date('Y-m'));
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$is_gudang = !empty($payload['is_gudang']);

if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['success' => false, 'message' => 'Format periode tidak valid.']); exit;
}

$safe_per = $conn->real_escape_string($periode);

$r_ed_tbl   = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
$has_ed_tbl = ($r_ed_tbl && $r_ed_tbl->num_rows > 0);

$r_gu_col   = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_gudang_utama'");
$has_gu_col = ($r_gu_col && $r_gu_col->num_rows > 0);

$reset_count   = 0;
$deleted_total = 0;
$skipped       = 0;
$errors        = 0;
$results       = [];

// Fungsi helper: reset satu opname record
function do_reset_opname(mysqli $conn, int $opname_id, bool $has_ed_tbl): array {
    $deleted = 0;
    // Hapus ED detail dulu (tidak ada FK CASCADE)
    if ($has_ed_tbl) {
        $conn->query("DELETE ed FROM inventory_stok_opname_ed_detail ed
            INNER JOIN inventory_stok_opname_detail d ON d.id = ed.opname_detail_id
            WHERE d.opname_id = $opname_id");
    }
    // Hapus semua detail rows
    $conn->query("DELETE FROM inventory_stok_opname_detail WHERE opname_id = $opname_id");
    $deleted = (int)$conn->affected_rows;
    return ['deleted' => $deleted, 'error' => $conn->error ?: null];
}

if ($scope === 'all') {
    // Reset semua klinik untuk periode ini
    $rAll = $conn->query("SELECT id, klinik_id, is_locked, COALESCE(status,'open') AS status
        FROM inventory_stok_opname
        WHERE periode = '$safe_per' AND (klinik_id IS NOT NULL OR " . ($has_gu_col ? "is_gudang_utama = 1" : "0") . ")
        ORDER BY id ASC");

    while ($rAll && ($row = $rAll->fetch_assoc())) {
        $oid    = (int)$row['id'];
        $label  = $row['klinik_id'] ? 'Klinik #' . $row['klinik_id'] : 'Gudang Utama';
        // status='draft' = sesi auto-create dari laporan nakes, belum pernah "dibuka" admin — jangan
        // ikut direset diam-diam lewat "Reset SO Semua", nakes bisa kehilangan laporannya tanpa jejak.
        if ($row['status'] === 'draft') {
            $results[] = ['label' => $label, 'status' => 'skip', 'msg' => 'belum dibuka (draft)'];
            $skipped++;
            continue;
        }
        if ((int)$row['is_locked']) {
            $results[] = ['label' => $label, 'status' => 'skip', 'msg' => 'terkunci'];
            $skipped++;
            continue;
        }
        $r = do_reset_opname($conn, $oid, $has_ed_tbl);
        if ($r['error']) {
            $results[] = ['label' => $label, 'status' => 'error', 'msg' => $r['error']];
            $errors++;
        } else {
            $results[] = ['label' => $label, 'status' => 'ok', 'msg' => $r['deleted'] . ' rows'];
            $deleted_total += $r['deleted'];
            $reset_count++;
        }
    }
} else {
    // Reset satu lokasi
    if ($is_gudang) {
        if (!$has_gu_col) {
            echo json_encode(['success' => false, 'message' => 'Kolom is_gudang_utama tidak ditemukan.']); exit;
        }
        $rOp = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname
            WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    } else {
        if ($klinik_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'klinik_id tidak valid.']); exit;
        }
        $rOp = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname
            WHERE klinik_id = $klinik_id AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    }

    $opRow = $rOp ? $rOp->fetch_assoc() : null;
    if (!$opRow) {
        echo json_encode(['success' => false, 'message' => "SO periode $periode tidak ditemukan."]); exit;
    }
    if ($opRow['status'] === 'draft') {
        echo json_encode(['success' => false, 'message' => "SO periode $periode belum pernah dibuka admin."]); exit;
    }
    if ((int)$opRow['is_locked']) {
        echo json_encode(['success' => false, 'message' => 'SO terkunci, tidak bisa direset.']); exit;
    }

    $oid = (int)$opRow['id'];
    $r   = do_reset_opname($conn, $oid, $has_ed_tbl);
    if ($r['error']) {
        echo json_encode(['success' => false, 'message' => $r['error']]); exit;
    }
    $deleted_total = $r['deleted'];
    $reset_count   = 1;
}

$summary = "$reset_count SO direset, $deleted_total baris data dihapus"
    . ($skipped  ? ", $skipped dilewati (terkunci)" : '')
    . ($errors   ? ", $errors error" : '');

echo json_encode([
    'success' => $errors === 0,
    'reset'   => $reset_count,
    'deleted' => $deleted_total,
    'skipped' => $skipped,
    'errors'  => $errors,
    'summary' => $summary,
    'results' => $results,
]);
