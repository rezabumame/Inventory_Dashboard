<?php
/**
 * Bersihin data master "BHP Non-Odoo" (Barang Lokal) -- inventory_barang_lokal &
 * inventory_stok_lokal -- setelah fitur ini dihapus total dari aplikasi (tidak ada lagi
 * halaman/menu buat kelola barang lokal).
 *
 * SENGAJA TIDAK menyentuh inventory_pemakaian_bhp_detail (riwayat transaksi Pemakaian BHP)
 * sama sekali -- itu data transaksi asli, bukan data master, di luar scope cleanup ini.
 * Kalau ada baris detail historis yang masih is_lokal=1 dan merujuk ke barang_lokal_id yang
 * mau dihapus, baris itu DI-FLAG "PERLU REVIEW MANUAL" dan TIDAK di-delete otomatis.
 *
 * CLI: default DRY RUN, tambahkan --apply untuk commit sungguhan.
 * Web: halaman UI (checkbox pilih per baris), tidak ada mode "apply semua" -- harus pilih
 * sendiri, link ini sengaja tidak ada di menu.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$is_web = (php_sapi_name() !== 'cli');
if ($is_web) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        die("Akses ditolak. Hanya Super Admin yang dapat membuka halaman ini.");
    }
}

function bhp_lokal_list(mysqli $conn): array {
    $q = "
        SELECT bl.id, bl.kode_item, bl.nama_item, bl.uom,
               COALESCE(SUM(sl.qty), 0) AS total_qty,
               (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail d WHERE d.barang_id = bl.id AND d.is_lokal = 1) AS total_riwayat
        FROM inventory_barang_lokal bl
        LEFT JOIN inventory_stok_lokal sl ON sl.barang_lokal_id = bl.id
        GROUP BY bl.id, bl.kode_item, bl.nama_item, bl.uom
        ORDER BY bl.nama_item ASC
    ";
    $res = $conn->query($q);
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    return $rows;
}

function bhp_lokal_delete(mysqli $conn, int $id): array {
    $r = $conn->query("SELECT id, nama_item FROM inventory_barang_lokal WHERE id = $id LIMIT 1")->fetch_assoc();
    if (!$r) return ['ok' => false, 'msg' => "Barang #$id tidak ditemukan, dilewati."];

    $total_riwayat = (int)($conn->query("SELECT COUNT(*) c FROM inventory_pemakaian_bhp_detail WHERE barang_id = $id AND is_lokal = 1")->fetch_assoc()['c'] ?? 0);
    if ($total_riwayat > 0) {
        return ['ok' => false, 'msg' => "{$r['nama_item']}: masih dipakai di $total_riwayat baris riwayat Pemakaian BHP, tidak dihapus -- perlu review manual."];
    }

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM inventory_stok_lokal WHERE barang_lokal_id = $id");
        $conn->query("DELETE FROM inventory_barang_lokal WHERE id = $id");
        $conn->commit();
        return ['ok' => true, 'msg' => "{$r['nama_item']}: berhasil dihapus."];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => "{$r['nama_item']}: GAGAL -- " . $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// WEB: halaman UI (checkbox pilih per baris)
// ──────────────────────────────────────────────────────────────────────────────
if ($is_web) {
    $results = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $selected = $_POST['selected'] ?? [];
        if (is_array($selected) && !empty($selected)) {
            foreach ($selected as $sid) {
                $results[] = bhp_lokal_delete($conn, (int)$sid);
            }
        }
    }

    $rows = bhp_lokal_list($conn);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cleanup Data BHP Non-Odoo - <?= APP_NAME ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }</style>
    </head>
    <body>
    <div class="container-fluid py-4 px-4">
        <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;"><i class="fas fa-broom me-2"></i>Cleanup Data BHP Non-Odoo</h1>
        <div class="text-muted small mb-3">Halaman khusus, tidak ada di menu. Fitur "BHP Non-Odoo" (Barang Lokal) sudah dihapus dari aplikasi -- ini buat bersihin data master & stoknya yang masih nyangkut.</div>

        <div class="alert alert-info border-0 shadow-sm">
            <i class="fas fa-info-circle me-2"></i>
            Menghapus baris di sini akan menghapus data master barang lokal + semua stoknya di semua klinik. Riwayat transaksi Pemakaian BHP (kalau ada) TIDAK disentuh -- barang yang masih dipakai di riwayat otomatis tidak bisa dihapus lewat sini.
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
                        Tidak ada data Barang Lokal tersisa.
                    </div>
                <?php else: ?>
                <form method="POST" action="cleanup_bhp_lokal_data.php">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted">Ditemukan <strong><?= count($rows) ?></strong> barang lokal.</div>
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus semua baris yang dicentang beserta stoknya? Aksi ini tidak bisa dibatalkan.');">
                            <i class="fas fa-trash me-1"></i>Hapus yang Dicentang
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="30"><input type="checkbox" id="chkAll"></th>
                                    <th>Kode</th>
                                    <th>Nama Barang</th>
                                    <th>Satuan</th>
                                    <th class="text-center">Total Stok (semua klinik)</th>
                                    <th class="text-center">Dipakai di Riwayat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r):
                                    $has_history = (int)$r['total_riwayat'] > 0;
                                    ?>
                                    <tr class="<?= $has_history ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php if ($has_history): ?>
                                                <i class="fas fa-exclamation-triangle text-warning" title="Masih dipakai di riwayat, perlu review manual"></i>
                                            <?php else: ?>
                                                <input type="checkbox" class="chkRow" name="selected[]" value="<?= (int)$r['id'] ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['kode_item'] ?: '-') ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($r['nama_item']) ?></td>
                                        <td><?= htmlspecialchars($r['uom'] ?: '-') ?></td>
                                        <td class="text-center"><?= htmlspecialchars((string)$r['total_qty']) ?></td>
                                        <td class="text-center">
                                            <?= (int)$r['total_riwayat'] ?>
                                            <?php if ($has_history): ?><br><small class="text-warning">perlu review manual</small><?php endif; ?>
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

$rows = bhp_lokal_list($conn);
echo "Ditemukan " . count($rows) . " barang lokal.\n\n";

$deleted = 0;
$flagged = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $has_history = (int)$r['total_riwayat'] > 0;
    $label = "[{$r['kode_item']}] {$r['nama_item']} (stok total: {$r['total_qty']}, riwayat: {$r['total_riwayat']})";

    if ($has_history) {
        echo "REVIEW  $label (masih dipakai di riwayat, JANGAN auto-hapus)\n";
        $flagged++;
        continue;
    }

    echo "DELETE  $label\n";
    if ($apply) {
        $res = bhp_lokal_delete($conn, $id);
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
    echo "SELESAI. $deleted barang lokal dihapus. $flagged di-flag utk review manual.\n";
} else {
    echo "DRY RUN selesai. $deleted AKAN dihapus, $flagged akan di-flag utk review manual, kalau dijalankan dari CLI dengan --apply.\n";
}
