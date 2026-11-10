<?php
/**
 * Bersihin record "BHP-AUT" (estimasi booking, is_auto=1) yang sebenarnya SUDAH BASI --
 * klinik+tanggal+jenis yang sama udah punya record REAL (is_auto=0, status='active'),
 * tapi estimasinya tidak pernah ke-cleanup karena api/bhp_auto_sync.php belum punya logic
 * cleanup ini sampai sekarang (sudah di-fix, lihat komentar "Cleanup BHP-AUT" di file itu).
 * Ini cuma buat bersihin backlog yang terlanjur numpuk SEBELUM fix itu ada.
 *
 * Pola hapusnya SAMA PERSIS dengan yang sudah dipakai & terbukti aman di jalur manual/Excel
 * upload (process_pemakaian_bhp.php) maupun di fix bhp_auto_sync.php barusan -- cuma hapus
 * baris detail/transaksi/header-nya, TIDAK membalikkan stok fisik. Ini aman KARENA sejak
 * commit 681ece43 (28 Apr 2026 ~21:07 WIB, lihat juga 6b410fe & f0624da di jam yg sama)
 * pemotongan stok fisik buat record is_auto=1 SUDAH DINONAKTIFKAN -- cuma nyatet "virtual"
 * di inventory_transaksi_stok (dipakai buat kurangi angka "stok efektif" di dashboard),
 * tidak pernah motong inventory_stok_gudang_klinik/inventory_stok_tas_hc beneran.
 *
 * PENGAMAN: record is_auto=1 yang CREATED_AT-nya SEBELUM 2026-04-28 20:27:00 (saat baris
 * pemotongan stok fisik masih aktif) TIDAK bisa dihapus lewat sini -- di-flag "PERLU REVIEW
 * MANUAL" karena kemungkinan beneran pernah motong stok fisik waktu itu, butuh pengecekan
 * manual sebelum dihapus (perlu reversal stok kalau memang masih ada baris setua itu).
 *
 * CLI: default DRY RUN, tambahkan --apply untuk commit sungguhan (hapus SEMUA yang lolos
 * cutoff sekaligus). Web: halaman UI (filter tanggal + checkbox pilih per baris), tidak ada
 * mode "apply semua" -- harus pilih sendiri per baris, link ini sengaja tidak ada di menu.
 */

const SAFE_CUTOFF = '2026-04-28 20:27:00';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$is_web = (php_sapi_name() !== 'cli');
if ($is_web) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        die("Akses ditolak. Hanya Super Admin yang dapat membuka halaman ini.");
    }
}

function bhp_auto_stale_list(mysqli $conn, string $tgl_dari = '', string $tgl_sampai = ''): array {
    $where_tgl = '';
    if ($tgl_dari !== '') $where_tgl .= " AND pb_auto.tanggal >= '" . $conn->real_escape_string($tgl_dari) . "'";
    if ($tgl_sampai !== '') $where_tgl .= " AND pb_auto.tanggal <= '" . $conn->real_escape_string($tgl_sampai) . "'";

    $q = "
        SELECT DISTINCT pb_auto.id AS auto_id, pb_auto.nomor_pemakaian, pb_auto.tanggal,
               pb_auto.created_at, pb_auto.klinik_id, pb_auto.jenis_pemakaian, k.nama_klinik,
               (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail d WHERE d.pemakaian_bhp_id = pb_auto.id) AS total_item
        FROM inventory_pemakaian_bhp pb_auto
        LEFT JOIN inventory_klinik k ON k.id = pb_auto.klinik_id
        WHERE pb_auto.is_auto = 1
          AND EXISTS (
              SELECT 1 FROM inventory_pemakaian_bhp pb_real
              WHERE pb_real.is_auto = 0
                AND pb_real.status = 'active'
                AND pb_real.klinik_id = pb_auto.klinik_id
                AND pb_real.jenis_pemakaian = pb_auto.jenis_pemakaian
                AND DATE(pb_real.tanggal) = DATE(pb_auto.tanggal)
          )
          $where_tgl
        ORDER BY pb_auto.tanggal DESC, pb_auto.id DESC
    ";
    $res = $conn->query($q);
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    return $rows;
}

function bhp_auto_stale_delete(mysqli $conn, int $auto_id): array {
    $r = $conn->query("SELECT id, nomor_pemakaian, created_at FROM inventory_pemakaian_bhp WHERE id = $auto_id AND is_auto = 1 LIMIT 1")->fetch_assoc();
    if (!$r) return ['ok' => false, 'msg' => "Record #$auto_id tidak ditemukan atau bukan BHP-AUT, dilewati."];

    if (strtotime($r['created_at']) < strtotime(SAFE_CUTOFF)) {
        return ['ok' => false, 'msg' => "{$r['nomor_pemakaian']}: dibuat sebelum cutoff aman (" . SAFE_CUTOFF . "), tidak boleh dihapus otomatis -- butuh review manual."];
    }

    $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $auto_id");
    $conn->query("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = $auto_id");
    $conn->query("DELETE FROM inventory_pemakaian_bhp WHERE id = $auto_id AND is_auto = 1");
    return ['ok' => true, 'msg' => "{$r['nomor_pemakaian']}: berhasil dihapus."];
}

// ──────────────────────────────────────────────────────────────────────────────
// WEB: halaman UI (filter tanggal + checkbox pilih per baris)
// ──────────────────────────────────────────────────────────────────────────────
if ($is_web) {
    $results = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $selected = $_POST['selected'] ?? [];
        if (is_array($selected) && !empty($selected)) {
            foreach ($selected as $sid) {
                $results[] = bhp_auto_stale_delete($conn, (int)$sid);
            }
        }
    }

    $tgl_dari = trim((string)($_GET['tgl_dari'] ?? $_POST['tgl_dari'] ?? ''));
    $tgl_sampai = trim((string)($_GET['tgl_sampai'] ?? $_POST['tgl_sampai'] ?? ''));
    $rows = bhp_auto_stale_list($conn, $tgl_dari, $tgl_sampai);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cleanup BHP-AUT Basi - <?= APP_NAME ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }</style>
    </head>
    <body>
    <div class="container-fluid py-4 px-4">
        <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;"><i class="fas fa-broom me-2"></i>Cleanup BHP-AUT Basi</h1>
        <div class="text-muted small mb-3">Halaman khusus, tidak ada di menu. Cutoff aman: <strong><?= SAFE_CUTOFF ?></strong> (dibuat sebelum ini tidak bisa dihapus lewat sini).</div>

        <div class="alert alert-info border-0 shadow-sm">
            <i class="fas fa-info-circle me-2"></i>
            Record BHP-AUT (estimasi booking) yang klinik+tanggal+jenisnya sudah punya data REAL, jadi estimasinya sudah tidak relevan. Menghapus baris ini TIDAK membalikkan stok fisik (memang tidak pernah memotong stok fisik, lihat komentar di bagian atas file ini).
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small fw-bold text-muted mb-1">Dari Tanggal</label>
                        <input type="date" name="tgl_dari" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_dari) ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small fw-bold text-muted mb-1">Sampai Tanggal</label>
                        <input type="date" name="tgl_sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_sampai) ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                        <a href="cleanup_stale_bhp_auto.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1"></i>Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($results)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-2">Hasil Proses</h6>
                <?php foreach ($results as $r): ?>
                    <div class="small <?= $r['ok'] ? 'text-success' : 'text-danger' ?>">
                        <i class="fas <?= $r['ok'] ? 'fa-check-circle' : 'fa-times-circle' ?> me-1"></i><?= htmlspecialchars($r['msg']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if (empty($rows)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-check-circle d-block h2 mb-2 opacity-50"></i>
                        Tidak ada record BHP-AUT basi yang ditemukan untuk filter ini.
                    </div>
                <?php else: ?>
                <form method="POST" action="cleanup_stale_bhp_auto.php">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="tgl_dari" value="<?= htmlspecialchars($tgl_dari) ?>">
                    <input type="hidden" name="tgl_sampai" value="<?= htmlspecialchars($tgl_sampai) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted">Ditemukan <strong><?= count($rows) ?></strong> record.</div>
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus semua baris yang dicentang? Aksi ini tidak bisa dibatalkan.');">
                            <i class="fas fa-trash me-1"></i>Hapus yang Dicentang
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="30"><input type="checkbox" id="chkAll"></th>
                                    <th>No. Pemakaian</th>
                                    <th>Tanggal BHP</th>
                                    <th>Dibuat</th>
                                    <th>Klinik</th>
                                    <th>Jenis</th>
                                    <th class="text-center">Item</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r):
                                    $needs_review = strtotime($r['created_at']) < strtotime(SAFE_CUTOFF);
                                    ?>
                                    <tr class="<?= $needs_review ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php if ($needs_review): ?>
                                                <i class="fas fa-exclamation-triangle text-warning" title="Dibuat sebelum cutoff aman, perlu review manual"></i>
                                            <?php else: ?>
                                                <input type="checkbox" class="chkRow" name="selected[]" value="<?= (int)$r['auto_id'] ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold"><?= htmlspecialchars($r['nomor_pemakaian']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_klinik'] ?? '-') ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($r['jenis_pemakaian'])) ?></span></td>
                                        <td class="text-center">
                                            <?= (int)$r['total_item'] ?>
                                            <?php if ($needs_review): ?><br><small class="text-warning">perlu review manual</small><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('chkAll')?.addEventListener('change', function () {
        document.querySelectorAll('.chkRow').forEach(function (c) { c.checked = this.checked; }.bind(this));
    });
    </script>
    </body>
    </html>
    <?php
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// CLI: dry-run (default) / --apply
// ──────────────────────────────────────────────────────────────────────────────
$apply = in_array('--apply', $argv ?? [], true);

echo $apply ? "MODE: APPLY (perubahan akan disimpan)\n" : "MODE: DRY RUN (tidak ada perubahan disimpan)\n";
echo str_repeat('-', 70) . "\n";

$stale = bhp_auto_stale_list($conn);

echo "Ditemukan " . count($stale) . " record BHP-AUT yang sudah basi (klinik+tanggal+jenisnya sudah punya data real).\n\n";

$deleted = 0;
$flagged = 0;
foreach ($stale as $s) {
    $auto_id = (int)$s['auto_id'];
    $label = "[{$s['nomor_pemakaian']}] dibuat {$s['created_at']}, tanggal BHP " . date('d/m/Y', strtotime($s['tanggal'])) . " - {$s['nama_klinik']} ({$s['jenis_pemakaian']})";

    if (strtotime($s['created_at']) < strtotime(SAFE_CUTOFF)) {
        echo "REVIEW  $label (dibuat SEBELUM cutoff " . SAFE_CUTOFF . " -- mungkin pernah motong stok fisik, JANGAN auto-hapus)\n";
        $flagged++;
        continue;
    }

    echo "DELETE  $label\n";
    if ($apply) {
        $res = bhp_auto_stale_delete($conn, $auto_id);
        if ($res['ok']) {
            $deleted++;
        } else {
            echo "        " . $res['msg'] . "\n";
            $flagged++;
        }
    } else {
        $deleted++;
    }
}

echo "\n" . str_repeat('-', 70) . "\n";
if ($apply) {
    echo "SELESAI. $deleted record BHP-AUT basi dihapus. $flagged di-flag utk review manual (lebih tua dari cutoff).\n";
} else {
    echo "DRY RUN selesai. $deleted record AKAN dihapus, $flagged akan di-flag utk review manual, kalau dijalankan dari CLI dengan --apply.\n";
}
