<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['petugas_hc', 'admin_klinik', 'spv_klinik', 'super_admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload   = json_decode(file_get_contents('php://input'), true);
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$items     = $payload['items'] ?? [];
$user_id   = (int)$_SESSION['user_id'];

if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

if ($klinik_id <= 0 || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
}

// Semua role selain super_admin/admin_gudang hanya bisa submit ke klinik sendiri
if (!in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
    $own = (int)($_SESSION['klinik_id'] ?? 0);
    if ($klinik_id !== $own) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
    }
}

// Cek kolom ED pada opname_detail (juga proxy untuk keberadaan kolom hc_user_id)
$r_col = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'ed_lte3m'");
$has_ed_cols = ($r_col && $r_col->num_rows > 0);

// Cek kolom periode — lakukan sebelum transaksi (SHOW tidak butuh transaksi)
$r_per_hc = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'periode'");
$has_per_hc = ($r_per_hc && $r_per_hc->num_rows > 0);

try {
    $conn->begin_transaction();

    $today   = date('Y-m-d');
    $now     = date('Y-m-d H:i:s');
    $periode = date('Y-m');

    if ($has_per_hc) {
        // Laporan nakes murni angka pembanding (bukan bagian proses SO resmi admin) — jadi TIDAK PERNAH
        // menunggu periode dibuka, dan TIDAK PERNAH membuka periode baru atau menembus status kunci.
        // Prioritas: periode yang masih terbuka (unlocked) — kalau ada, meski itu periode lama.
        // Kalau semua sudah terkunci, baru simpan ke periode TERAKHIR (bulan berjalan) apa adanya.
        $rOp = $conn->query("SELECT id, periode FROM inventory_stok_opname
            WHERE klinik_id = $klinik_id
            ORDER BY (is_locked = 0 OR is_locked IS NULL) DESC, periode DESC, id DESC LIMIT 1");
        $opRow = $rOp ? $rOp->fetch_assoc() : null;
        $opname_id = $opRow ? (int)$opRow['id'] : 0;
        if (!$opname_id) throw new RuntimeException('Belum ada SO yang pernah dibuka untuk klinik ini. Hubungi admin gudang.');
    } else {
        // Fallback legacy: cari/buat berdasarkan hari ini
        $rOp = $conn->query("SELECT id FROM inventory_stok_opname
            WHERE klinik_id = $klinik_id AND DATE(tanggal_mulai) = '$today'
            ORDER BY id DESC LIMIT 1");
        $opname_id = $rOp ? (int)($rOp->fetch_assoc()['id'] ?? 0) : 0;
        if (!$opname_id) {
            $conn->query("INSERT INTO inventory_stok_opname (klinik_id, user_id, tanggal_mulai, status, catatan)
                VALUES ($klinik_id, $user_id, '$now', 'draft', '')");
            $opname_id = (int)$conn->insert_id;
            if (!$opname_id) throw new RuntimeException('Gagal membuat sesi opname: ' . $conn->error);
        }
    }

    // Simpan laporan stok HC ke opname_detail (BUKAN update stok_tas_hc). Setiap item HANYA boleh
    // dilaporkan nakes 1x per periode — begitu qty_fisik terisi, item itu terkunci dari sisi nakes
    // (cuma admin/SPV yang bisa koreksi lewat halaman SO, mis. hapus barisnya supaya bisa dilaporkan ulang).
    $saved   = 0;
    $skipped = 0;
    foreach ($items as $d) {
        $barang_id  = (int)($d['barang_id'] ?? 0);
        $qty_sistem = (float)($d['qty_sistem'] ?? 0);
        $qty_fisik  = isset($d['qty_fisik']) ? (float)$d['qty_fisik'] : null;
        $multiplier = max(0.0001, (float)($d['multiplier'] ?? 1));
        $ed_lte3m   = (float)($d['ed_lte3m'] ?? 0);
        $ed_gt3m    = (float)($d['ed_gt3m'] ?? 0);
        $catatan    = $conn->real_escape_string(trim((string)($d['catatan'] ?? '')));
        if ($barang_id <= 0 || $qty_fisik === null) continue;

        // Convert to_uom → from_uom before storing so SO admin display (÷mult) is correct
        $qty_fisik  = $qty_fisik  * $multiplier;
        $qty_sistem = $qty_sistem * $multiplier;

        $selisih = $qty_fisik - $qty_sistem;
        $status  = $selisih == 0 ? 'ok' : 'selisih';

        if ($has_ed_cols) {
            // Manual UPSERT — do not rely on UNIQUE KEY existence in DB
            $rEx = $conn->query("SELECT id, qty_fisik FROM inventory_stok_opname_detail
                WHERE opname_id=$opname_id AND barang_id=$barang_id AND hc_user_id=$user_id AND tipe='hc' LIMIT 1");
            $exRow   = $rEx ? $rEx->fetch_assoc() : null;
            $existId = $exRow ? (int)$exRow['id'] : 0;
            if ($existId > 0 && $exRow['qty_fisik'] !== null) {
                // Sudah pernah dilaporkan periode ini — jangan timpa, lewati.
                $skipped++;
                continue;
            }
            if ($existId > 0) {
                // Update nakes fields only; do NOT overwrite qty_aktual (admin's validated value)
                $conn->query("UPDATE inventory_stok_opname_detail SET
                    stok_sistem=$qty_sistem, qty_fisik=$qty_fisik, selisih=$selisih, status='$status',
                    ed_lte3m=$ed_lte3m, ed_gt3m=$ed_gt3m, catatan='$catatan'
                    WHERE id=$existId");
            } else {
                $conn->query("INSERT INTO inventory_stok_opname_detail
                    (opname_id, barang_id, hc_user_id, tipe, stok_sistem, qty_fisik, selisih, ed_lte3m, ed_gt3m, catatan, status)
                    VALUES ($opname_id, $barang_id, $user_id, 'hc', $qty_sistem, $qty_fisik, $selisih, $ed_lte3m, $ed_gt3m, '$catatan', '$status')");
            }
        } else {
            // Legacy path (no hc_user_id column)
            $conn->query("INSERT INTO inventory_stok_opname_detail
                (opname_id, barang_id, stok_sistem, qty_fisik, selisih, status)
                VALUES ($opname_id, $barang_id, $qty_sistem, $qty_fisik, $selisih, '$status')
                ON DUPLICATE KEY UPDATE
                    stok_sistem = $qty_sistem, qty_fisik = $qty_fisik, selisih = $selisih, status = '$status'");
        }
        $saved++;
    }

    $conn->commit();
    $skip_msg = $skipped > 0 ? " ($skipped item dilewati krn sudah dilaporkan sebelumnya)" : '';
    echo json_encode([
        'success'    => true,
        'saved'      => $saved,
        'skipped'    => $skipped,
        'opname_id'  => $opname_id,
        'message'    => "$saved item laporan stok berhasil dikirim.$skip_msg Stok tas akan diperbarui setelah divalidasi tim accounting."
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
