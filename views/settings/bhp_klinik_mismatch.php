<?php
check_role(['super_admin']);
require_once __DIR__ . '/../../lib/stock.php';

/**
 * Tool buat benerin record Pemakaian BHP (HC) yang klinik header-nya tidak cocok sama
 * klinik TERDAFTAR nakesnya saat ini (akibat bug lama di resolve_nakes_user_id() --
 * sudah di-fix di api/bhp_auto_sync.php, ini cuma buat bersihin data yang terlanjur masuk
 * sebelum fix itu).
 *
 * SENGAJA tidak auto-proses semua -- nakes bisa aja beneran pernah pindah klinik secara
 * sah di masa lalu, jadi record lama yang klinik-nya "beda dari klinik terdaftar SEKARANG"
 * belum tentu salah. Makanya di sini di-list dulu, admin pilih sendiri per baris mana yang
 * mau dipindahin (bukan asumsi semua salah).
 */

function bhp_mismatch_list(mysqli $conn, string $tgl_dari = '', string $tgl_sampai = ''): array {
    $where_tgl = '';
    if ($tgl_dari !== '') $where_tgl .= " AND pb.tanggal >= '" . $conn->real_escape_string($tgl_dari) . "'";
    if ($tgl_sampai !== '') $where_tgl .= " AND pb.tanggal <= '" . $conn->real_escape_string($tgl_sampai) . "'";

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
          $where_tgl
        ORDER BY pb.tanggal DESC, pb.id DESC
    ";
    $res = $conn->query($q);
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    return $rows;
}

function bhp_mismatch_move(mysqli $conn, int $pb_id, int $created_by): array {
    $h = $conn->query("
        SELECT pb.id, pb.nomor_pemakaian, pb.klinik_id AS header_klinik_id, pb.user_hc_id,
               u.klinik_id AS nakes_klinik_id
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_users u ON u.id = pb.user_hc_id
        WHERE pb.id = $pb_id AND pb.jenis_pemakaian = 'hc' AND pb.status = 'active'
        LIMIT 1
    ")->fetch_assoc();

    if (!$h) return ['ok' => false, 'msg' => "Record #$pb_id tidak ditemukan atau bukan HC aktif."];
    $wrong_klinik = (int)$h['header_klinik_id'];
    $right_klinik = (int)($h['nakes_klinik_id'] ?? 0);
    $user_id = (int)$h['user_hc_id'];

    if ($right_klinik <= 0 || $wrong_klinik === $right_klinik) {
        return ['ok' => false, 'msg' => "{$h['nomor_pemakaian']}: tidak ada mismatch (sudah berubah sejak list dimuat), dilewati."];
    }

    $det = $conn->query("SELECT * FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $pb_id");
    $details = [];
    while ($det && ($d = $det->fetch_assoc())) {
        if ((int)($d['is_lokal'] ?? 0) === 1) {
            return ['ok' => false, 'msg' => "{$h['nomor_pemakaian']}: ada item barang_lokal, perlu review manual terpisah, dilewati."];
        }
        $details[] = $d;
    }
    if (empty($details)) {
        return ['ok' => false, 'msg' => "{$h['nomor_pemakaian']}: tidak ada baris detail, dilewati."];
    }

    // Kunci stok di kedua klinik (pola sama dengan stock_pemakaian_bhp_{klinik_id}_{jenis}
    // yang dipakai jalur lain) -- supaya tidak balapan dengan proses pemakaian BHP lain yang
    // sedang jalan di klinik yang sama saat record ini dipindah.
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
            return ['ok' => false, 'msg' => "{$h['nomor_pemakaian']}: GAGAL -- sistem sedang memproses stok klinik terkait, coba lagi sebentar."];
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

            $cat = "Koreksi klinik mismatch " . $h['nomor_pemakaian'] . ": dipindah ke klinik terdaftar nakes (via tool admin)";
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
        return ['ok' => true, 'msg' => "{$h['nomor_pemakaian']}: berhasil dipindahkan (" . count($details) . " item)."];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => "{$h['nomor_pemakaian']}: GAGAL -- " . $e->getMessage()];
    } finally {
        foreach ($acquired as $a) {
            $ea = $conn->real_escape_string($a);
            $conn->query("SELECT RELEASE_LOCK('$ea')");
        }
    }
}

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $selected = $_POST['selected'] ?? [];
    if (is_array($selected) && !empty($selected)) {
        foreach ($selected as $sid) {
            $results[] = bhp_mismatch_move($conn, (int)$sid, (int)$_SESSION['user_id']);
        }
    }
}

$tgl_dari = trim((string)($_GET['tgl_dari'] ?? $_POST['tgl_dari'] ?? ''));
$tgl_sampai = trim((string)($_GET['tgl_sampai'] ?? $_POST['tgl_sampai'] ?? ''));
$rows = bhp_mismatch_list($conn, $tgl_dari, $tgl_sampai);
?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;"><i class="fas fa-map-marker-alt me-2"></i>Perbaikan Klinik Mismatch BHP (HC)</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Klinik Mismatch BHP</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm">
        <i class="fas fa-info-circle me-2"></i>
        Daftar record Pemakaian BHP (HC) yang klinik-nya tidak cocok dengan klinik <strong>terdaftar nakes SAAT INI</strong>.
        <strong>Review dulu per baris</strong> sebelum centang & pindahkan — nakes bisa saja pernah pindah klinik secara sah di masa lalu, jadi tidak semua baris di sini otomatis salah.
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="bhp_klinik_mismatch">
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
                    <a href="index.php?page=bhp_klinik_mismatch" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1"></i>Reset</a>
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
                    Tidak ada mismatch yang ditemukan. Semua record HC auto-sync sudah sesuai klinik terdaftar nakesnya.
                </div>
            <?php else: ?>
            <form method="POST" action="index.php?page=bhp_klinik_mismatch">
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
