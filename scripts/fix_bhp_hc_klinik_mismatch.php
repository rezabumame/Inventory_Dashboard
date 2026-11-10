<?php
/**
 * Perbaikan record Pemakaian BHP (HC) yang klinik headernya TIDAK COCOK dengan klinik
 * terdaftar nakes-nya. Ini bisa kejadian karena bug lama di resolve_nakes_user_id()
 * (api/bhp_auto_sync.php): sekali admin_id eksternal ke-mapping ke user_id, mapping itu
 * dipakai lintas-klinik tanpa validasi -- baris sumber dari klinik lain tetap diproses
 * pakai klinik baris sumber itu, bukan klinik terdaftar nakesnya. Sudah di-fix di
 * bhp_auto_sync.php (sekarang baris begini di-skip & dilaporkan), tapi record yang
 * SUDAH TERLANJUR masuk sebelum fix itu perlu dikoreksi manual lewat script ini.
 *
 * Untuk tiap header HC (change_source='sistem_integrasi', status='active') yang
 * klinik_id-nya beda dari klinik_id TERDAFTAR nakesnya (inventory_users.klinik_id):
 *   - Asumsi: klinik TERDAFTAR nakes adalah klinik yang BENAR (sudah dikonfirmasi user).
 *   - Balikin stok tas HC di klinik SALAH (yang sekarang ada di header), potong stok
 *     tas HC di klinik BENAR, sejumlah qty yang sama persis -- per item.
 *   - Log 2 baris transaksi (reversal di klinik salah, deduction di klinik benar).
 *   - Update klinik_id di header jadi klinik yang benar.
 *   - Update klinik_id di inventory_bhp_sync_tracking yang terkait (field informasional).
 *   - nomor_pemakaian SENGAJA TIDAK diubah (tidak di-renumber) -- biar tidak perlu alokasi
 *     nomor urut baru dan resiko nabrak. Prefix nomor mungkin kelihatan tidak sesuai
 *     klinik barunya (misal "BHP-HCB01" padahal sekarang klinik TB Simatupang) -- itu cuma
 *     kosmetik, nomor tetap unik & valid sebagai identitas record.
 *   - Header yang detail-nya ada barang_lokal (is_lokal=1) DI-SKIP & di-flag, tidak
 *     disentuh otomatis (mekanisme stok lokal beda, perlu review manual terpisah).
 *
 * CLI: default DRY RUN, tambahkan --apply untuk commit sungguhan. Opsional:
 * --nomor=<nomor_pemakaian> untuk batasi ke 1 record spesifik saja.
 * Web: halaman UI (filter tanggal + checkbox pilih per baris), tidak ada mode CLI
 * "apply semua" -- harus pilih sendiri per baris, link ini sengaja tidak ada di menu.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$is_web = (php_sapi_name() !== 'cli');
if ($is_web) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        die("Akses ditolak. Hanya Super Admin yang dapat membuka halaman ini.");
    }
}

/**
 * Kunci stok di kedua klinik (pola sama dengan stock_pemakaian_bhp_{klinik_id}_{jenis}
 * yang dipakai jalur lain) lalu balikin stok di klinik salah & potong di klinik benar.
 * Dipakai oleh jalur CLI maupun UI web.
 */
function bhp_mismatch_fix_move(mysqli $conn, array $h, int $created_by): array {
    $pb_id = (int)$h['id'];
    $wrong_klinik = (int)$h['header_klinik_id'];
    $right_klinik = (int)$h['nakes_klinik_id'];
    $user_id = (int)$h['user_hc_id'];
    $label = $h['nomor_pemakaian'];

    if ($right_klinik <= 0 || $wrong_klinik === $right_klinik) {
        return ['ok' => false, 'msg' => "$label: tidak ada mismatch (sudah berubah sejak list dimuat), dilewati."];
    }

    $det = $conn->query("SELECT * FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $pb_id");
    $details = [];
    while ($det && ($d = $det->fetch_assoc())) {
        if ((int)($d['is_lokal'] ?? 0) === 1) {
            return ['ok' => false, 'msg' => "$label: ada item barang_lokal, perlu review manual terpisah, dilewati."];
        }
        $details[] = $d;
    }
    if (empty($details)) {
        return ['ok' => false, 'msg' => "$label: tidak ada baris detail, dilewati."];
    }

    $lock_a = "stock_pemakaian_bhp_{$wrong_klinik}_hc";
    $lock_b = "stock_pemakaian_bhp_{$right_klinik}_hc";
    $lock_names = $wrong_klinik < $right_klinik ? [$lock_a, $lock_b] : [$lock_b, $lock_a];
    $acquired = [];
    foreach ($lock_names as $ln) {
        $esc = $conn->real_escape_string($ln);
        $r = $conn->query("SELECT GET_LOCK('$esc', 10) AS got");
        $got = (int)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['got'] ?? 0) : 0);
        if ($got !== 1) {
            foreach ($acquired as $a) {
                $ea = $conn->real_escape_string($a);
                $conn->query("SELECT RELEASE_LOCK('$ea')");
            }
            return ['ok' => false, 'msg' => "$label: GAGAL -- sistem sedang memproses stok klinik terkait, coba lagi nanti."];
        }
        $acquired[] = $ln;
    }

    $conn->begin_transaction();
    try {
        foreach ($details as $d) {
            $bid = (int)$d['barang_id'];
            $qty = (float)$d['qty'];

            $stmt1 = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_at)
                VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = NOW()");
            $stmt1->bind_param("iiid", $bid, $user_id, $wrong_klinik, $qty);
            $stmt1->execute();

            $neg_qty = -$qty;
            $stmt2 = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_at)
                VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = NOW()");
            $stmt2->bind_param("iiid", $bid, $user_id, $right_klinik, $neg_qty);
            $stmt2->execute();

            $cat = "Koreksi klinik mismatch $label: dipindah dari klinik salah ke klinik terdaftar nakes";
            $stmt_log1 = $conn->prepare("INSERT INTO inventory_transaksi_stok
                (barang_id, level, level_id, tipe_transaksi, qty, referensi_tipe, referensi_id, catatan, created_by, created_at)
                VALUES (?, 'hc', ?, 'in', ?, 'pemakaian_bhp', ?, ?, ?, NOW())");
            $stmt_log1->bind_param("iidisi", $bid, $user_id, $qty, $pb_id, $cat, $created_by);
            $stmt_log1->execute();

            $stmt_log2 = $conn->prepare("INSERT INTO inventory_transaksi_stok
                (barang_id, level, level_id, tipe_transaksi, qty, referensi_tipe, referensi_id, catatan, created_by, created_at)
                VALUES (?, 'hc', ?, 'out', ?, 'pemakaian_bhp', ?, ?, ?, NOW())");
            $stmt_log2->bind_param("iidisi", $bid, $user_id, $qty, $pb_id, $cat, $created_by);
            $stmt_log2->execute();
        }

        $stmt_h = $conn->prepare("UPDATE inventory_pemakaian_bhp SET klinik_id = ? WHERE id = ?");
        $stmt_h->bind_param("ii", $right_klinik, $pb_id);
        $stmt_h->execute();

        $conn->query("UPDATE inventory_bhp_sync_tracking SET klinik_id = $right_klinik WHERE pemakaian_bhp_id = $pb_id");

        $conn->commit();
        return ['ok' => true, 'msg' => "$label: berhasil dipindahkan (" . count($details) . " item)."];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => "$label: GAGAL -- " . $e->getMessage()];
    } finally {
        foreach ($acquired as $a) {
            $ea = $conn->real_escape_string($a);
            $conn->query("SELECT RELEASE_LOCK('$ea')");
        }
    }
}

function bhp_mismatch_fix_list(mysqli $conn, string $tgl_dari = '', string $tgl_sampai = '', string $only_nomor = ''): array {
    $where = '';
    if ($tgl_dari !== '') $where .= " AND pb.tanggal >= '" . $conn->real_escape_string($tgl_dari) . "'";
    if ($tgl_sampai !== '') $where .= " AND pb.tanggal <= '" . $conn->real_escape_string($tgl_sampai) . "'";
    if ($only_nomor !== '') $where .= " AND pb.nomor_pemakaian = '" . $conn->real_escape_string($only_nomor) . "'";

    $q = "
        SELECT pb.id, pb.nomor_pemakaian, pb.tanggal, pb.klinik_id AS header_klinik_id,
               pb.user_hc_id, u.nama_lengkap AS nakes_nama, u.klinik_id AS nakes_klinik_id,
               k1.nama_klinik AS header_klinik_nama, k2.nama_klinik AS nakes_klinik_nama,
               (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail d WHERE d.pemakaian_bhp_id = pb.id) AS total_item,
               (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail d WHERE d.pemakaian_bhp_id = pb.id AND d.is_lokal = 1) AS total_lokal
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_users u ON u.id = pb.user_hc_id
        LEFT JOIN inventory_klinik k1 ON k1.id = pb.klinik_id
        LEFT JOIN inventory_klinik k2 ON k2.id = u.klinik_id
        WHERE pb.jenis_pemakaian = 'hc'
          AND pb.change_source = 'sistem_integrasi'
          AND pb.status = 'active'
          AND u.klinik_id IS NOT NULL
          AND pb.klinik_id != u.klinik_id
          $where
        ORDER BY pb.tanggal DESC, pb.id DESC
    ";
    $res = $conn->query($q);
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    return $rows;
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
                $sid = (int)$sid;
                $h = $conn->query("
                    SELECT pb.id, pb.nomor_pemakaian, pb.klinik_id AS header_klinik_id,
                           pb.user_hc_id, u.klinik_id AS nakes_klinik_id
                    FROM inventory_pemakaian_bhp pb
                    JOIN inventory_users u ON u.id = pb.user_hc_id
                    WHERE pb.id = $sid AND pb.jenis_pemakaian = 'hc' AND pb.status = 'active'
                    LIMIT 1
                ")->fetch_assoc();
                if (!$h) {
                    $results[] = ['ok' => false, 'msg' => "Record #$sid tidak ditemukan atau bukan HC aktif."];
                    continue;
                }
                $results[] = bhp_mismatch_fix_move($conn, $h, (int)$_SESSION['user_id']);
            }
        }
    }

    $tgl_dari = trim((string)($_GET['tgl_dari'] ?? $_POST['tgl_dari'] ?? ''));
    $tgl_sampai = trim((string)($_GET['tgl_sampai'] ?? $_POST['tgl_sampai'] ?? ''));
    $rows = bhp_mismatch_fix_list($conn, $tgl_dari, $tgl_sampai);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Fix Klinik Mismatch BHP (HC) - <?= APP_NAME ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }</style>
    </head>
    <body>
    <div class="container-fluid py-4 px-4">
        <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;"><i class="fas fa-map-marker-alt me-2"></i>Fix Klinik Mismatch BHP (HC)</h1>
        <div class="text-muted small mb-3">Halaman khusus, tidak ada di menu.</div>

        <div class="alert alert-info border-0 shadow-sm">
            <i class="fas fa-info-circle me-2"></i>
            Record Pemakaian BHP (HC) yang klinik headernya tidak cocok dengan klinik <strong>terdaftar nakes SAAT INI</strong>.
            <strong>Review dulu per baris</strong> sebelum centang & pindahkan -- nakes bisa saja pernah pindah klinik secara sah di masa lalu.
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
                        <a href="fix_bhp_hc_klinik_mismatch.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1"></i>Reset</a>
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
                        Tidak ada mismatch yang ditemukan untuk filter ini.
                    </div>
                <?php else: ?>
                <form method="POST" action="fix_bhp_hc_klinik_mismatch.php">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="tgl_dari" value="<?= htmlspecialchars($tgl_dari) ?>">
                    <input type="hidden" name="tgl_sampai" value="<?= htmlspecialchars($tgl_sampai) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted">Ditemukan <strong><?= count($rows) ?></strong> record.</div>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Pindahkan semua baris yang dicentang? Ini akan menyesuaikan stok 2 klinik sekaligus.');">
                            <i class="fas fa-exchange-alt me-1"></i>Pindahkan yang Dicentang
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="30"><input type="checkbox" id="chkAll"></th>
                                    <th>No. Pemakaian</th>
                                    <th>Tanggal</th>
                                    <th>Nakes</th>
                                    <th>Klinik Sekarang (di record)</th>
                                    <th></th>
                                    <th>Klinik Terdaftar Nakes</th>
                                    <th class="text-center">Item</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r):
                                    $has_lokal = (int)$r['total_lokal'] > 0;
                                    ?>
                                    <tr class="<?= $has_lokal ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php if ($has_lokal): ?>
                                                <i class="fas fa-exclamation-triangle text-warning" title="Ada barang lokal, perlu review manual"></i>
                                            <?php else: ?>
                                                <input type="checkbox" class="chkRow" name="selected[]" value="<?= (int)$r['id'] ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold"><?= htmlspecialchars($r['nomor_pemakaian']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><?= htmlspecialchars($r['nakes_nama']) ?></td>
                                        <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= htmlspecialchars($r['header_klinik_nama'] ?? '-') ?></span></td>
                                        <td><i class="fas fa-arrow-right text-muted"></i></td>
                                        <td><span class="badge bg-success-subtle text-success border border-success-subtle"><?= htmlspecialchars($r['nakes_klinik_nama'] ?? '-') ?></span></td>
                                        <td class="text-center">
                                            <?= (int)$r['total_item'] ?>
                                            <?php if ($has_lokal): ?><br><small class="text-warning">ada barang lokal</small><?php endif; ?>
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
// CLI: dry-run (default) / --apply, opsional --nomor=XXX
// ──────────────────────────────────────────────────────────────────────────────
$apply = in_array('--apply', $argv ?? [], true);
$only_nomor = '';
foreach (($argv ?? []) as $a) {
    if (preg_match('/^--nomor=(.+)$/', $a, $m)) $only_nomor = trim($m[1]);
}

echo $apply ? "MODE: APPLY (perubahan akan disimpan)\n" : "MODE: DRY RUN (tidak ada perubahan disimpan, tambahkan --apply untuk eksekusi)\n";
if ($only_nomor !== '') echo "Filter: hanya nomor_pemakaian = $only_nomor\n";
echo str_repeat('-', 70) . "\n";

$headers = bhp_mismatch_fix_list($conn, '', '', $only_nomor);

echo "Ditemukan " . count($headers) . " record HC yang klinik headernya tidak cocok klinik terdaftar nakesnya.\n\n";

$fixed = 0;
$flagged = 0;
$created_by = 1; // super_admin, dipakai sbg pemilik teknis koreksi otomatis

foreach ($headers as $h) {
    $label = "[{$h['nomor_pemakaian']}] {$h['tanggal']} nakes={$h['nakes_nama']} ({$h['header_klinik_nama']} -> {$h['nakes_klinik_nama']})";
    $has_lokal = (int)$h['total_lokal'] > 0;

    if ($has_lokal) {
        echo "SKIP    $label (ada item barang_lokal di detail, perlu review manual terpisah)\n";
        $flagged++;
        continue;
    }
    if ((int)$h['total_item'] === 0) {
        echo "SKIP    $label (tidak ada baris detail, aneh -- perlu review manual)\n";
        $flagged++;
        continue;
    }

    echo "MOVE    $label (" . (int)$h['total_item'] . " item)\n";
    if ($apply) {
        $res = bhp_mismatch_fix_move($conn, $h, $created_by);
        if ($res['ok']) {
            $fixed++;
        } else {
            echo "        " . $res['msg'] . "\n";
            $flagged++;
        }
    } else {
        $fixed++;
    }
}

echo "\n" . str_repeat('-', 70) . "\n";
if ($apply) {
    echo "SELESAI. $fixed record diperbaiki, $flagged perlu review manual.\n";
} else {
    echo "DRY RUN selesai (tidak ada yang disimpan). $fixed akan diperbaiki, $flagged akan perlu review manual.\n";
    echo "Jalankan ulang dengan --apply untuk eksekusi sungguhan.\n";
}
