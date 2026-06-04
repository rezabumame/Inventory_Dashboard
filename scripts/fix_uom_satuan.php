<?php
/**
 * Diagnostic & Fix: Standarisasi satuan BHP ke UOM Operasional (to_uom).
 *
 * Menangkap dua kondisi:
 *   1. satuan = from_uom  → salah pakai UOM Odoo (misal: "mL")
 *   2. satuan ≠ to_uom    → varian tidak standar (misal: "btl", "botol" vs "Botol")
 *
 * Yang diubah: HANYA kolom `satuan` di inventory_pemakaian_bhp_detail.
 * Qty, stok, export Odoo TIDAK berubah.
 *
 * Akses: super_admin only
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

check_login();
check_role(['super_admin']);

$do_fix   = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix');
$fix_kode = isset($_POST['kode_barang']) ? array_filter(array_map('trim', (array)$_POST['kode_barang'])) : [];
$messages = [];

// ── Eksekusi fix ──────────────────────────────────────────────────────────────
if ($do_fix && !empty($fix_kode)) {
    require_csrf();
    $conn->begin_transaction();
    try {
        $total_updated = 0;
        foreach ($fix_kode as $kode) {
            $kode_esc = $conn->real_escape_string($kode);
            $conv = $conn->query("
                SELECT from_uom, to_uom
                FROM inventory_barang_uom_conversion
                WHERE kode_barang = '$kode_esc'
                LIMIT 1
            ")->fetch_assoc();
            if (!$conv) continue;

            $to_esc = $conn->real_escape_string($conv['to_uom']);

            // Update SEMUA satuan yang bukan persis to_uom
            $conn->query("
                UPDATE inventory_pemakaian_bhp_detail pbd
                JOIN inventory_barang b ON b.id = pbd.barang_id
                SET pbd.satuan = '$to_esc'
                WHERE b.kode_barang = '$kode_esc'
                  AND pbd.satuan != '$to_esc'
                  AND pbd.is_lokal = 0
            ");
            $affected = $conn->affected_rows;
            $total_updated += $affected;
            $messages[] = "✓ {$kode} [{$conv['to_uom']}]: {$affected} record diperbaiki";
        }
        $conn->commit();
        $messages[] = "── Total {$total_updated} record berhasil distandarisasi.";
    } catch (Throwable $e) {
        $conn->rollback();
        $messages[] = "✗ ERROR: " . htmlspecialchars($e->getMessage());
    }
}

// ── Query diagnostic: satuan apapun yang bukan persis to_uom ─────────────────
$summary_res = $conn->query("
    SELECT
        b.kode_barang,
        b.nama_barang,
        uc.from_uom,
        uc.to_uom,
        uc.multiplier,
        pbd.satuan                 AS satuan_tersimpan,
        COUNT(pbd.id)              AS jumlah_record,
        SUM(pbd.qty)               AS total_qty,
        MIN(pb.tanggal)            AS tgl_awal,
        MAX(pb.tanggal)            AS tgl_akhir,
        CASE
            WHEN LOWER(TRIM(pbd.satuan)) = LOWER(TRIM(uc.from_uom)) THEN 'odoo'
            ELSE 'varian'
        END AS jenis_masalah
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_barang b ON b.id = pbd.barang_id
    JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
    WHERE pbd.satuan != uc.to_uom
      AND pbd.is_lokal = 0
      AND pb.status NOT IN ('cancelled', 'pending_approval_spv')
    GROUP BY b.kode_barang, b.nama_barang, uc.from_uom, uc.to_uom, uc.multiplier, pbd.satuan
    ORDER BY b.nama_barang ASC, jumlah_record DESC
");
$summary = [];
while ($row = $summary_res->fetch_assoc()) {
    $summary[$row['kode_barang']]['item'] = [
        'kode_barang' => $row['kode_barang'],
        'nama_barang' => $row['nama_barang'],
        'from_uom'    => $row['from_uom'],
        'to_uom'      => $row['to_uom'],
        'multiplier'  => $row['multiplier'],
    ];
    $summary[$row['kode_barang']]['variants'][] = $row;
    $summary[$row['kode_barang']]['total_record'] = ($summary[$row['kode_barang']]['total_record'] ?? 0) + $row['jumlah_record'];
}

// ── Detail per item ───────────────────────────────────────────────────────────
$detail_res = $conn->query("
    SELECT
        b.kode_barang,
        b.nama_barang,
        pb.nomor_pemakaian,
        pb.tanggal,
        pb.jenis_pemakaian,
        k.nama_klinik,
        pbd.id        AS detail_id,
        pbd.qty,
        pbd.satuan    AS satuan_salah,
        uc.to_uom     AS satuan_benar,
        CASE
            WHEN LOWER(TRIM(pbd.satuan)) = LOWER(TRIM(uc.from_uom)) THEN 'odoo'
            ELSE 'varian'
        END AS jenis_masalah
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_barang b ON b.id = pbd.barang_id
    JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
    JOIN inventory_klinik k ON k.id = pb.klinik_id
    WHERE pbd.satuan != uc.to_uom
      AND pbd.is_lokal = 0
      AND pb.status NOT IN ('cancelled', 'pending_approval_spv')
    ORDER BY b.nama_barang ASC, pb.tanggal DESC
    LIMIT 500
");
$details = [];
while ($row = $detail_res->fetch_assoc()) {
    $details[$row['kode_barang']][] = $row;
}

$total_items   = count($summary);
$total_records = array_sum(array_column(array_column($summary, 'total_record'), null));

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Standarisasi UOM Satuan BHP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
body { background: #f4f6fb; font-family: 'Segoe UI', sans-serif; }
.page-header { background: #204EAB; color: #fff; padding: 20px 32px; margin-bottom: 24px; }
.badge-odoo    { background: #fee2e2; color: #dc2626; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.badge-varian  { background: #fef9c3; color: #854d0e; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.badge-right   { background: #dcfce7; color: #16a34a; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.arrow { color: #94a3b8; margin: 0 6px; }
.item-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.07); margin-bottom: 16px; overflow: hidden; }
.item-header { background: #f8faff; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; cursor: pointer; }
.item-header:hover { background: #eef2ff; }
.detail-table th { background: #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
.detail-table td { font-size: 13px; vertical-align: middle; }
.fix-bar { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #204EAB; padding: 14px 24px; z-index: 100; box-shadow: 0 -2px 12px rgba(0,0,0,.1); }
</style>
</head>
<body>

<div class="page-header">
    <h4 class="mb-1 fw-bold"><i class="fas fa-exchange-alt me-2"></i>Standarisasi UOM Satuan BHP</h4>
    <div style="font-size:13px;opacity:.85;">
        Mendeteksi semua record yang satuannya bukan persis <code>to_uom</code> (UOM Operasional).
        Mencakup: salah pakai UOM Odoo (<code>mL</code>) maupun varian tidak standar (<code>btl</code>, <code>botol</code>, dll).
        Hanya label satuan yang diubah — qty, stok, dan export Odoo <strong>tidak berubah</strong>.
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
    <strong>Semua bersih.</strong> Semua satuan sudah sesuai UOM Operasional.
</div>
<?php else: ?>

<!-- Summary cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div style="font-size:28px;font-weight:700;color:#dc2626;"><?= $total_items ?></div>
            <div class="text-muted small">Item bermasalah</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div style="font-size:28px;font-weight:700;color:#f59e0b;"><?= $total_records ?></div>
            <div class="text-muted small">Total record perlu difix</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm p-3" style="font-size:13px;">
            <div class="fw-semibold text-muted mb-2" style="font-size:11px;letter-spacing:.5px;">LEGENDA</div>
            <div class="mb-1"><span class="badge-odoo">mL</span> <span class="arrow">→</span> <span class="badge-right">Botol</span> &nbsp; Salah pakai UOM Odoo</div>
            <div><span class="badge-varian">btl</span> <span class="arrow">→</span> <span class="badge-right">Botol</span> &nbsp; Varian tidak standar</div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="fix">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <?php foreach ($summary as $kode => $data): ?>
    <?php $item = $data['item']; $variants = $data['variants']; ?>
    <div class="item-card">
        <div class="item-header d-flex align-items-center justify-content-between"
             onclick="toggleDetail('<?= htmlspecialchars($kode) ?>')">
            <div class="d-flex align-items-center gap-3">
                <input type="checkbox" name="kode_barang[]" value="<?= htmlspecialchars($kode) ?>"
                    class="form-check-input check-item" style="width:18px;height:18px;"
                    onclick="event.stopPropagation()">
                <div>
                    <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($item['nama_barang']) ?></div>
                    <div class="text-muted" style="font-size:12px;">
                        Kode: <?= htmlspecialchars($kode) ?>
                        &bull; Target: <span class="badge-right"><?= htmlspecialchars($item['to_uom']) ?></span>
                        &bull; Ratio 1 <?= htmlspecialchars($item['to_uom']) ?> = <?= (int)$item['multiplier'] ?> <?= htmlspecialchars($item['from_uom']) ?>
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-4">
                <!-- Varian yang ditemukan -->
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($variants as $v): ?>
                    <span class="<?= $v['jenis_masalah'] === 'odoo' ? 'badge-odoo' : 'badge-varian' ?>">
                        <?= htmlspecialchars($v['satuan_tersimpan']) ?>
                        <span style="font-weight:400;opacity:.7;">(<?= $v['jumlah_record'] ?>)</span>
                    </span>
                    <?php endforeach; ?>
                    <span class="arrow">→</span>
                    <span class="badge-right"><?= htmlspecialchars($item['to_uom']) ?></span>
                </div>
                <div class="text-center">
                    <div style="font-size:20px;font-weight:700;color:#dc2626;"><?= $data['total_record'] ?></div>
                    <div class="text-muted" style="font-size:11px;">record</div>
                </div>
                <i class="fas fa-chevron-down text-muted"></i>
            </div>
        </div>

        <div id="detail_<?= htmlspecialchars($kode) ?>" style="display:none;padding:16px 20px;">
            <div class="alert alert-warning py-2 small mb-3">
                <strong>Preview fix:</strong>
                Semua varian di bawah akan diganti menjadi <span class="badge-right"><?= htmlspecialchars($item['to_uom']) ?></span>.
                Qty tidak berubah.
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
                            <th>Tipe Masalah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details[$kode] as $d): ?>
                        <tr>
                            <td><code style="font-size:11px;"><?= htmlspecialchars($d['nomor_pemakaian']) ?></code></td>
                            <td><?= date('d M Y', strtotime($d['tanggal'])) ?></td>
                            <td><?= htmlspecialchars($d['nama_klinik']) ?></td>
                            <td>
                                <span class="badge bg-<?= $d['jenis_pemakaian'] === 'hc' ? 'warning text-dark' : 'info text-dark' ?>" style="font-size:10px;">
                                    <?= strtoupper($d['jenis_pemakaian']) ?>
                                </span>
                            </td>
                            <td class="text-end fw-semibold"><?= number_format($d['qty'], 0) ?></td>
                            <td class="text-center">
                                <span class="<?= $d['jenis_masalah'] === 'odoo' ? 'badge-odoo' : 'badge-varian' ?>">
                                    <?= htmlspecialchars($d['satuan_salah']) ?>
                                </span>
                            </td>
                            <td class="text-center"><span class="badge-right"><?= htmlspecialchars($d['satuan_benar']) ?></span></td>
                            <td class="text-muted small"><?= $d['jenis_masalah'] === 'odoo' ? 'Salah UOM Odoo' : 'Varian tidak standar' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($details[$kode]) >= 500): ?>
                        <tr><td colspan="8" class="text-center text-muted small py-2">... ditampilkan max 500 record</td></tr>
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
            onclick="return confirm('Yakin ingin standarisasi satuan untuk item yang dipilih?\n\nHanya label satuan yang berubah, qty tidak berubah.')">
            <i class="fas fa-wrench me-2"></i>Standarisasi Satuan yang Dipilih
        </button>
    </div>
</form>

<?php endif; ?>
</div>

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
</body>
</html>
