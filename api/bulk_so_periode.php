<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

// Aksi bulk (buka/kunci/reset SO Semua lokasi sekaligus) hanya untuk super_admin.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Hanya super_admin yang bisa melakukan aksi bulk SO.']); exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$action  = $payload['action'] ?? 'open'; // open | lock | unlock
$periode = trim($payload['periode'] ?? date('Y-m'));
$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';

if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['success' => false, 'message' => 'Format periode tidak valid.']); exit;
}
if (!in_array($action, ['open', 'lock', 'unlock'])) {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid.']); exit;
}

// Cek kolom is_gudang_utama tersedia
$r_gu = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_gudang_utama'");
$has_gu_col = ($r_gu && $r_gu->num_rows > 0);

$safe_per = $conn->real_escape_string($periode);
$now      = date('Y-m-d H:i:s');

// Kumpulkan semua klinik aktif
$results  = [];
$opened   = 0;
$locked   = 0;
$unlocked = 0;
$skipped  = 0;
$errors   = 0;

$rKl = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
$kliniks = [];
while ($rKl && ($row = $rKl->fetch_assoc())) $kliniks[] = $row;

foreach ($kliniks as $kl) {
    $kid  = (int)$kl['id'];
    $nama = $kl['nama_klinik'];

    $rOp = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname
        WHERE klinik_id = $kid AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    $opRow = $rOp ? $rOp->fetch_assoc() : null;

    // Cegah 2 sesi terbuka bersamaan utk 1 klinik: cek dulu apakah klinik ini sudah punya sesi lain
    // (periode apa pun) yang masih belum dikunci — kalau ada dan itu BUKAN periode target, jangan
    // buka/unlock periode ini dulu (mis. sesi bulan lalu masih berjalan lewat tengah malam).
    $rActive = $conn->query("SELECT periode FROM inventory_stok_opname
        WHERE klinik_id = $kid AND (is_locked = 0 OR is_locked IS NULL) ORDER BY id DESC LIMIT 1");
    $activeRow = $rActive ? $rActive->fetch_assoc() : null;
    $hasOtherActive = $activeRow && $activeRow['periode'] !== $periode;

    if ($action === 'open') {
        if ($hasOtherActive) {
            $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => "sesi periode {$activeRow['periode']} masih berjalan"];
            $skipped++; continue;
        }
        // Advisory lock (nama sama persis dgn api/open_so_periode.php & api/hc_stock_validate.php)
        // supaya bulk-open tidak race dgn nakes yg bersamaan auto-create sesi draft.
        $lock_name = "so_open_klinik_$kid";
        $lock = advisory_get_lock($conn, $lock_name, 5);
        if (!$lock['got'] && $lock['supported']) {
            $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => 'sedang diproses permintaan lain, coba lagi'];
            $errors++; continue;
        }
        // Re-cek setelah dapat lock — barangkali nakes baru saja bikin baris draft.
        $rOp2 = $conn->query("SELECT id, is_locked, status FROM inventory_stok_opname
            WHERE klinik_id = $kid AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
        $opRow = $rOp2 ? $rOp2->fetch_assoc() : null;

        if ($opRow) {
            if ($opRow['status'] === 'draft') {
                // Sesi auto-create dari laporan nakes — promosikan, jangan dianggap "sudah buka".
                $oid = (int)$opRow['id'];
                $ok  = $conn->query("UPDATE inventory_stok_opname SET status='open', is_locked=0 WHERE id=$oid");
                if ($ok) { $results[] = ['klinik' => $nama, 'status' => 'ok', 'msg' => 'dibuka']; $opened++; }
                else { $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => $conn->error]; $errors++; }
                advisory_release_lock($conn, $lock_name); continue;
            }
            if (!(int)($opRow['is_locked'] ?? 0)) {
                $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'sudah buka']; $skipped++;
                advisory_release_lock($conn, $lock_name); continue;
            }
            // Terkunci → buka kembali (aman krn sudah dipastikan tidak ada sesi lain yg masih terbuka)
            $oid = (int)$opRow['id'];
            $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=0, locked_by=NULL, locked_at=NULL, status='open' WHERE id=$oid");
            if ($ok) { $results[] = ['klinik' => $nama, 'status' => 'ok', 'msg' => 'dibuka kembali']; $opened++; }
            else { $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => $conn->error]; $errors++; }
            advisory_release_lock($conn, $lock_name); continue;
        }
        $ok = $conn->query("INSERT INTO inventory_stok_opname (klinik_id, user_id, tanggal_mulai, tanggal_selesai, status, catatan, periode, is_locked)
            VALUES ($kid, $user_id, '$now', '$now', 'open', '', '$safe_per', 0)");
        if ($ok && $conn->insert_id) { $results[] = ['klinik' => $nama, 'status' => 'ok', 'msg' => 'dibuka']; $opened++; }
        else { $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => $conn->error]; $errors++; }
        advisory_release_lock($conn, $lock_name);

    } elseif ($action === 'lock') {
        if (!$opRow) { $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'belum dibuka']; $skipped++; continue; }
        if ($opRow['status'] === 'draft') {
            // Belum pernah "dibuka" admin sungguhan — jangan langsung dikunci, harus lewat "Buka SO" dulu.
            $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'belum dibuka']; $skipped++; continue;
        }
        if ((int)($opRow['is_locked'] ?? 0)) { $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'sudah terkunci']; $skipped++; continue; }
        $oid = (int)$opRow['id'];
        $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=1, locked_by=$user_id, locked_at='$now', status='locked' WHERE id=$oid");
        if ($ok) { $results[] = ['klinik' => $nama, 'status' => 'ok', 'msg' => 'dikunci']; $locked++; }
        else { $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => $conn->error]; $errors++; }

    } elseif ($action === 'unlock') {
        if (!$opRow) { $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'belum dibuka']; $skipped++; continue; }
        if (!(int)($opRow['is_locked'] ?? 0)) { $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => 'tidak terkunci']; $skipped++; continue; }
        if ($hasOtherActive) {
            $results[] = ['klinik' => $nama, 'status' => 'skip', 'msg' => "sesi periode {$activeRow['periode']} sudah aktif, kunci itu dulu"];
            $skipped++; continue;
        }
        $oid = (int)$opRow['id'];
        $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=0, locked_by=NULL, locked_at=NULL, status='open' WHERE id=$oid");
        if ($ok) { $results[] = ['klinik' => $nama, 'status' => 'ok', 'msg' => 'dibuka kembali']; $unlocked++; }
        else { $results[] = ['klinik' => $nama, 'status' => 'error', 'msg' => $conn->error]; $errors++; }
    }
}

// Gudang juga jika is_gudang_utama column tersedia
$gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
if ($gudang_loc !== '' && $has_gu_col) {
    $rGu = $conn->query("SELECT id, is_locked FROM inventory_stok_opname
        WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
    $guRow = $rGu ? $rGu->fetch_assoc() : null;

    $rGuActive = $conn->query("SELECT periode FROM inventory_stok_opname
        WHERE is_gudang_utama = 1 AND (is_locked = 0 OR is_locked IS NULL) ORDER BY id DESC LIMIT 1");
    $guActiveRow = $rGuActive ? $rGuActive->fetch_assoc() : null;
    $guHasOtherActive = $guActiveRow && $guActiveRow['periode'] !== $periode;

    if ($action === 'open') {
        if ($guHasOtherActive) {
            $results[] = ['klinik' => 'Gudang Utama', 'status' => 'skip', 'msg' => "sesi periode {$guActiveRow['periode']} masih berjalan"];
            $skipped++;
        } else {
            $guLock = advisory_get_lock($conn, 'so_open_gudang', 5);
            if (!$guLock['got'] && $guLock['supported']) {
                $results[] = ['klinik' => 'Gudang Utama', 'status' => 'error', 'msg' => 'sedang diproses permintaan lain, coba lagi'];
                $errors++;
            } else {
                $rGu2 = $conn->query("SELECT id, is_locked FROM inventory_stok_opname
                    WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
                $guRow = $rGu2 ? $rGu2->fetch_assoc() : null;
                if (!$guRow) {
                    $ok = $conn->query("INSERT INTO inventory_stok_opname (klinik_id, is_gudang_utama, user_id, tanggal_mulai, tanggal_selesai, status, catatan, periode, is_locked)
                        VALUES (NULL, 1, $user_id, '$now', '$now', 'open', '', '$safe_per', 0)");
                    if ($ok && $conn->insert_id) { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'ok', 'msg' => 'dibuka']; $opened++; }
                    else { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'error', 'msg' => $conn->error]; $errors++; }
                } elseif ((int)($guRow['is_locked'] ?? 0)) {
                    $oid = (int)$guRow['id'];
                    $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=0, locked_by=NULL, locked_at=NULL, status='open' WHERE id=$oid");
                    if ($ok) { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'ok', 'msg' => 'dibuka kembali']; $opened++; }
                    else { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'error', 'msg' => $conn->error]; $errors++; }
                } else { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'skip', 'msg' => 'sudah buka']; $skipped++; }
                advisory_release_lock($conn, 'so_open_gudang');
            }
        }
    } elseif ($action === 'lock' && $guRow && !(int)($guRow['is_locked'] ?? 0)) {
        $oid = (int)$guRow['id'];
        $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=1, locked_by=$user_id, locked_at='$now', status='locked' WHERE id=$oid");
        if ($ok) { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'ok',    'msg' => 'dikunci'];        $locked++; }
        else     { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'error', 'msg' => $conn->error];    $errors++; }
    } elseif ($action === 'unlock' && $guRow && (int)($guRow['is_locked'] ?? 0)) {
        if ($guHasOtherActive) {
            $results[] = ['klinik' => 'Gudang Utama', 'status' => 'skip', 'msg' => "sesi periode {$guActiveRow['periode']} sudah aktif, kunci itu dulu"];
            $skipped++;
        } else {
            $oid = (int)$guRow['id'];
            $ok  = $conn->query("UPDATE inventory_stok_opname SET is_locked=0, locked_by=NULL, locked_at=NULL, status='open' WHERE id=$oid");
            if ($ok) { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'ok',    'msg' => 'dibuka kembali']; $unlocked++; }
            else     { $results[] = ['klinik' => 'Gudang Utama', 'status' => 'error', 'msg' => $conn->error];     $errors++; }
        }
    }
}

$summary = match($action) {
    'open'   => "$opened lokasi dibuka, $skipped sudah ada" . ($errors ? ", $errors error" : ''),
    'lock'   => "$locked lokasi dikunci, $skipped dilewati" . ($errors ? ", $errors error" : ''),
    'unlock' => "$unlocked lokasi dibuka kembali, $skipped dilewati" . ($errors ? ", $errors error" : ''),
};

echo json_encode([
    'success' => $errors === 0,
    'action'  => $action,
    'periode' => $periode,
    'opened'  => $opened,
    'locked'  => $locked,
    'unlocked'=> $unlocked,
    'skipped' => $skipped,
    'errors'  => $errors,
    'summary' => $summary,
    'results' => $results,
]);
