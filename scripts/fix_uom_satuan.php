<?php
/**
 * Diagnostic & Fix: Satuan BHP yang salah pakai UOM Odoo (from_uom)
 * padahal seharusnya pakai UOM Operasional (to_uom).
 *
 * Yang diubah: HANYA kolom `satuan` di inventory_pemakaian_bhp_detail
 * Qty, stok, dan export Odoo TIDAK berubah.
 *
 * Akses: super_admin only
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

check_login();
check_role(['super_admin']);

$do_fix    = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix');
$fix_kode  = isset($_POST['kode_barang']) ? array_filter(array_map('trim', (array)$_POST['kode_barang'])) : [];

$messages  = [];

// ── Eksekusi fix jika dikonfirmasi ────────────────────────────────────────────
if ($do_fix && !empty($fix_kode)) {
    require_csrf();
    $conn->begin_transaction();
    try {
        $total_updated = 0;
        foreach ($fix_kode as $kode) {
            $kode_esc = $conn->real_escape_string($kode);
            // Ambil konversi untuk kode ini
            $conv = $conn->query("
                SELECT uc.from_uom, uc.to_uom
                FROM inventory_barang_uom_conversion uc
                WHERE uc.kode_barang = '$kode_esc'
                LIMIT 1
            ")->fetch_assoc();
            if (!$conv) continue;

            $from_esc = $conn->real_escape_string($conv['from_uom']);
            $to_esc   = $conn->real_escape_string($conv['to_uom']);

            // Update: ganti satuan from_uom → to_uom pada detail yang salah
            $conn->query("
                UPDATE inventory_pemakaian_bhp_detail pbd
                JOIN inventory_barang b ON b.id = pbd.barang_id
                SET pbd.satuan = '$to_esc'
                WHERE b.kode_barang = '$kode_esc'
                  AND LOWER(TRIM(pbd.satuan)) = LOWER('$from_esc')
                  AND pbd.is_lokal = 0
            ");
            $affected = $conn->affected_rows;
            $total_updated += $affected;
            $messages[] = "✓ {$kode}: {$conv['from_uom']} → {$conv['to_uom']} ({$affected} record diperbaiki)";
        }
        $conn->commit();
        $messages[] = "── Total {$total_updated} record berhasil diperbaiki.";
    } catch (Throwable $e) {
        $conn->rollback();
        $messages[] = "✗ ERROR: " . htmlspecialchars($e->getMessage());
    }
}

// ── Query diagnostic ──────────────────────────────────────────────────────────
$diagnostic_sql = "
    SELECT
        b.kode_barang,
        b.nama_barang,
        uc.from_uom,
        uc.to_uom,
        uc.multiplier,
        COUNT(pbd.id)              AS jumlah_record,
        SUM(pbd.qty)               AS total_qty,
        MIN(pb.tanggal)            AS tgl_awal,
        MAX(pb.tanggal)            AS tgl_akhir
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_barang b ON b.id = pbd.barang_id
    JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
    WHERE LOWER(TRIM(pbd.satuan)) = LOWER(TRIM(uc.from_uom))
      AND pbd.is_lokal = 0
      AND pb.status NOT IN ('cancelled', 'pending_approval_spv')
    GROUP BY b.kode_barang, b.nama_barang, uc.from_uom, uc.to_uom, uc.multiplier
    ORDER BY jumlah_record DESC
";
$summary_res = $conn->query($diagnostic_sql);
$summary = [];
while ($row = $summary_res->fetch_assoc()) $summary[] = $row;

// ── Detail per item (max 50 per item) ────────────────────────────────────────
$detail_sql = "
    SELECT
        b.kode_barang,
        b.nama_barang,
        pb.nomor_pemakaian,
        pb.tanggal,
        pb.jenis_pemakaian,
        k.nama_klinik,
        pbd.id     AS detail_id,
        pbd.qty,
        pbd.satuan AS satuan_salah,
        uc.to_uom  AS satuan_benar,
        uc.multiplier
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_barang b ON b.id = pbd.barang_id
    JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
    JOIN inventory_klinik k ON k.id = pb.klinik_id
    WHERE LOWER(TRIM(pbd.satuan)) = LOWER(TRIM(uc.from_uom))
      AND pbd.is_lokal = 0
      AND pb.status NOT IN ('cancelled', 'pending_approval_spv')
    ORDER BY b.nama_barang ASC, pb.tanggal DESC
    LIMIT 500
";
$detail_res = $conn->query($detail_sql);
$details = [];
while ($row = $detail_res->fetch_assoc()) {
    $details[$row['kode_barang']][] = $row;
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic UOM Satuan BHP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f4f6fb; font-family: 'Segoe UI', sans-serif; }
.page-header { background: #204EAB; color: #fff; padding: 20px 32px; margin-bottom: 24px; }
.badge-wrong { background: #fee2e2; color: #dc2626; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.badge-right { background: #dcfce7; color: #16a34a; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.arrow { color: #94a3b8; margin: 0 6px; }
.item-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.07); margin-bottom: 20px; overflow: hidden; }
.item-header { background: #f8faff; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; cursor: pointer; }
.item-header:hover { background: #eef2ff; }
.detail-table th { background: #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
.detail-table td { font-size: 13px; vertical-align: middle; }
.check-item { cursor: pointer; }
.fix-bar { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #204EAB; padding: 14px 24px; z-index: 100; box-shadow: 0 -2px 12px rgba(0,0,0,.1); }
</style>
</head>
<body>

<div class="page-header">
    <h4 class="mb-1 fw-bold"><i class="fas fa-stethoscope me-2"></i>Diagnostic: UOM Satuan BHP Salah</h4>
    <div style="font-size:13px;opacity:.85;">
        Cari record BHP yang satuannya pakai UOM Odoo (<code>from_uom</code>) padahal harusnya UOM Operasional (<code>to_uom</code>).
        Hanya label satuan yang diubah — qty, stok, dan export Odoo tidak berubah.
    </div>
</div>

<div class="container-fluid px-4">

<?php if (!empty($messages)): ?>
    <div class="alert alert-<?= strpos(implode('', $messages), 'ERROR') !== false ? 'danger' : 'success' ?> mb-4">
        <?php foreach ($messages as $m): ?>
            <div><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($summary)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        <strong>Semua bersih.</strong> Tidak ada record BHP dengan satuan yang salah.
    </div>
<?php else: ?>

<!-- Summary -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div style="font-size:28px;font-weight:700;color:#dc2626;"><?= count($summary) ?></div>
            <div class="text-muted small">Item bermasalah</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div style="font-size:28px;font-weight:700;color:#f59e0b;"><?= array_sum(array_column($summary, 'jumlah_record')) ?></div>
            <div class="text-muted small">Total record salah</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm p-3">
            <div class="small text-muted mb-1 fw-semibold">CARA BACA</div>
            <div class="small">Satuan <span class="badge-wrong">mL</span> <span class="arrow">→</span> <span class="badge-right">Botol</span> artinya record disimpan dengan satuan <em>mL</em> padahal seharusnya <em>Botol</em>. Qty-nya tidak berubah.</div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="fix">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <?php foreach ($summary as $item): ?>
    <?php $kode = $item['kode_barang']; ?>
    <div class="item-card">
        <div class="item-header d-flex align-items-center justify-content-between" onclick="toggleDetail('<?= htmlspecialchars($kode) ?>')">
            <div class="d-flex align-items-center gap-3">
                <input type="checkbox" name="kode_barang[]" value="<?= htmlspecialchars($kode) ?>"
                    class="form-check-input check-item" style="width:18px;height:18px;"
                    onclick="event.stopPropagation()" id="chk_<?= htmlspecialchars($kode) ?>">
                <div>
                    <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($item['nama_barang']) ?></div>
                    <div class="text-muted" style="font-size:12px;">Kode: <?= htmlspecialchars($kode) ?> &bull; Ratio: 1 <?= htmlspecialchars($item['to_uom']) ?> = <?= (int)$item['multiplier'] ?> <?= htmlspecialchars($item['from_uom']) ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-center">
                    <div style="font-size:20px;font-weight:700;color:#dc2626;"><?= $item['jumlah_record'] ?></div>
                    <div class="text-muted" style="font-size:11px;">record salah</div>
                </div>
                <div class="text-center">
                    <span class="badge-wrong"><?= htmlspecialchars($item['from_uom']) ?></span>
                    <span class="arrow">→</span>
                    <span class="badge-right"><?= htmlspecialchars($item['to_uom']) ?></span>
                </div>
                <div class="text-center">
                    <div class="text-muted" style="font-size:11px;"><?= date('d M Y', strtotime($item['tgl_awal'])) ?> – <?= date('d M Y', strtotime($item['tgl_akhir'])) ?></div>
                </div>
                <i class="fas fa-chevron-down text-muted"></i>
            </div>
        </div>

        <div id="detail_<?= htmlspecialchars($kode) ?>" style="display:none; padding: 16px 20px;">
            <div class="alert alert-warning py-2 small mb-3">
                <strong>Preview fix:</strong>
                Kolom <code>satuan</code> akan diganti dari
                <span class="badge-wrong"><?= htmlspecialchars($item['from_uom']) ?></span>
                menjadi
                <span class="badge-right"><?= htmlspecialchars($item['to_uom']) ?></span>
                pada <strong><?= $item['jumlah_record'] ?> record</strong> di bawah ini.
                Qty (<?= number_format($item['total_qty'], 2) ?>) tidak berubah.
            </div>
            <?php if (!empty($details[$kode])): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover detail-table mb-0">
                    <thead>
                        <tr>
                            <th>No. BHP</th>
                            <th>Tanggal</th>
                            <th>Klinik</th>
                            <th>Jenis</th>
                            <th class="text-end">Qty</th>
                            <th class="text-center">Satuan Sekarang</th>
                            <th class="text-center">→ Satuan Benar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details[$kode] as $d): ?>
                        <tr>
                            <td><code style="font-size:11px;"><?= htmlspecialchars($d['nomor_pemakaian']) ?></code></td>
                            <td><?= date('d M Y', strtotime($d['tanggal'])) ?></td>
                            <td><?= htmlspecialchars($d['nama_klinik']) ?></td>
                            <td><span class="badge bg-<?= $d['jenis_pemakaian'] === 'hc' ? 'warning text-dark' : 'info text-dark' ?>" style="font-size:10px;"><?= strtoupper($d['jenis_pemakaian']) ?></span></td>
                            <td class="text-end fw-semibold"><?= number_format($d['qty'], 0) ?></td>
                            <td class="text-center"><span class="badge-wrong"><?= htmlspecialchars($d['satuan_salah']) ?></span></td>
                            <td class="text-center"><span class="badge-right"><?= htmlspecialchars($d['satuan_benar']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($details[$kode]) >= 500): ?>
                        <tr><td colspan="7" class="text-center text-muted small py-2">... dan lebih banyak lagi (ditampilkan max 500)</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Fix bar -->
    <div class="fix-bar d-flex align-items-center justify-content-between">
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="toggleAll(true)">Pilih Semua</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(false)">Batal Semua</button>
            <span class="ms-3 text-muted small" id="selectedCount">0 item dipilih</span>
        </div>
        <button type="submit" class="btn btn-danger px-4"
            onclick="return confirm('Yakin ingin memperbaiki satuan untuk item yang dipilih?\n\nHanya label satuan yang berubah, qty tidak berubah.')">
            <i class="fas fa-wrench me-2"></i>Perbaiki Satuan yang Dipilih
        </button>
    </div>

</form>

<?php endif; ?>
</div><!-- /container -->

<script>
function toggleDetail(kode) {
    const el = document.getElementById('detail_' + kode);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
function toggleAll(check) {
    document.querySelectorAll('.check-item').forEach(cb => cb.checked = check);
    updateCount();
}
function updateCount() {
    const n = document.querySelectorAll('.check-item:checked').length;
    document.getElementById('selectedCount').textContent = n + ' item dipilih';
}
document.querySelectorAll('.check-item').forEach(cb => cb.addEventListener('change', updateCount));
</script>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</body>
</html>
