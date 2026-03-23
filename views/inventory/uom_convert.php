<?php
check_role(['super_admin', 'admin_gudang']);

$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        from_uom VARCHAR(20) NULL,
        to_uom VARCHAR(20) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $barang_id = (int)($_POST['barang_id'] ?? 0);
        $from_uom = trim((string)($_POST['from_uom'] ?? ''));
        $multiplier = (string)($_POST['multiplier'] ?? '1');
        $note = trim((string)($_POST['note'] ?? ''));
        $to_uom = '';
        if ($barang_id > 0) {
            $b = $conn->query("SELECT satuan FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
            $to_uom = (string)($b['satuan'] ?? '');
        }
        $mult = (float)$multiplier;
        if ($barang_id > 0 && $mult > 0) {
            $stmt = $conn->prepare("INSERT INTO barang_uom_conversion (barang_id, from_uom, to_uom, multiplier, note) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE from_uom = VALUES(from_uom), to_uom = VALUES(to_uom), multiplier = VALUES(multiplier), note = VALUES(note)");
            $stmt->bind_param("issds", $barang_id, $from_uom, $to_uom, $mult, $note);
            $stmt->execute();
            $_SESSION['success'] = 'Konversi UOM berhasil disimpan.';
        } else {
            $_SESSION['error'] = 'Data tidak valid.';
        }
        redirect('index.php?page=uom_convert');
    }
    if ($action === 'delete') {
        $barang_id = (int)($_POST['barang_id'] ?? 0);
        if ($barang_id > 0) {
            $stmt = $conn->prepare("DELETE FROM barang_uom_conversion WHERE barang_id = ?");
            $stmt->bind_param("i", $barang_id);
            $stmt->execute();
            $_SESSION['success'] = 'Konversi UOM berhasil dihapus.';
        }
        redirect('index.php?page=uom_convert');
    }
}

$barang_list = [];
$res = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM barang WHERE kode_barang IS NOT NULL AND TRIM(kode_barang) <> '' ORDER BY nama_barang ASC");
while ($res && ($row = $res->fetch_assoc())) $barang_list[] = $row;

$conv_list = [];
$res = $conn->query("
    SELECT c.barang_id, c.from_uom, c.to_uom, c.multiplier, c.note, c.updated_at, b.kode_barang, b.nama_barang, b.satuan
    FROM barang_uom_conversion c
    JOIN barang b ON b.id = c.barang_id
    ORDER BY b.nama_barang ASC
");
while ($res && ($row = $res->fetch_assoc())) $conv_list[] = $row;
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-exchange-alt me-2"></i>Konversi UOM BHP
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Konversi UOM</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="alert alert-info shadow-sm">
        <div class="fw-semibold mb-1">Cara kerja</div>
        <div class="small">
            Stok dari Odoo (stock_mirror) dianggap dalam UOM sumber. Sistem akan menampilkan stok dan ketersediaan dalam UOM target sesuai satuan di master barang (dipakai juga di Pemakaian BHP).
            Rumus: Qty Target = Qty Odoo × Multiplier.
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="save">
                <div class="col-md-5">
                    <label class="form-label fw-bold small text-muted mb-1">Barang (UOM target mengikuti master barang)</label>
                    <select name="barang_id" class="form-select" required>
                        <option value="">- Pilih Barang -</option>
                        <?php foreach ($barang_list as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" data-satuan="<?= htmlspecialchars((string)($b['satuan'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars(($b['kode_barang'] ?? '-') . ' - ' . ($b['nama_barang'] ?? '-') . ' (' . ($b['satuan'] ?? '-') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">UOM Odoo</label>
                    <input type="text" name="from_uom" class="form-control" placeholder="contoh: Pcs/uL">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">Multiplier (Odoo → Target)</label>
                    <input type="number" name="multiplier" class="form-control" step="0.00000001" min="0" value="1" required>
                    <div class="form-text" id="uomPreview">Qty target = qty odoo × multiplier</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">Catatan</label>
                    <input type="text" name="note" class="form-control" placeholder="opsional">
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>Barang</th>
                            <th>UOM Odoo</th>
                            <th>UOM Target</th>
                            <th class="text-end">Multiplier</th>
                            <th>Catatan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($conv_list)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data konversi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($conv_list as $c): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars(($c['kode_barang'] ?? '-') . ' - ' . ($c['nama_barang'] ?? '-')) ?></div>
                                        <div class="small text-muted">Update: <?= htmlspecialchars(date('d M Y H:i', strtotime($c['updated_at']))) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($c['from_uom'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($c['satuan'] ?? ($c['to_uom'] ?? '-')) ?></td>
                                    <td class="text-end fw-semibold">
                                        <?= htmlspecialchars(rtrim(rtrim(number_format((float)$c['multiplier'], 8, '.', ''), '0'), '.')) ?>
                                        <div class="small text-muted">1 <?= htmlspecialchars($c['from_uom'] ?? '-') ?> → <?= htmlspecialchars(rtrim(rtrim(number_format((float)$c['multiplier'], 8, '.', ''), '0'), '.')) ?> <?= htmlspecialchars($c['satuan'] ?? ($c['to_uom'] ?? '-')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($c['note'] ?? '-') ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="barang_id" value="<?= (int)$c['barang_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus konversi ini?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function fmtPreviewNumber(v) {
    var n = Number(v || 0);
    if (!isFinite(n)) n = 0;
    if (Math.abs(n - Math.round(n)) < 0.00005) return String(Math.round(n));
    var s = n.toFixed(8);
    s = s.replace(/0+$/,'').replace(/\.$/,'');
    return s === '' ? '0' : s;
}

function updatePreview() {
    var sel = document.querySelector('select[name="barang_id"]');
    var from = document.querySelector('input[name="from_uom"]');
    var mult = document.querySelector('input[name="multiplier"]');
    var preview = document.getElementById('uomPreview');
    if (!preview) return;
    var target = '';
    if (sel && sel.selectedOptions && sel.selectedOptions.length) {
        target = sel.selectedOptions[0].getAttribute('data-satuan') || '';
    }
    var fromTxt = (from && from.value) ? from.value.trim() : '';
    var multVal = (mult && mult.value) ? mult.value : '1';
    var m = fmtPreviewNumber(multVal);
    if (fromTxt !== '' && target !== '') {
        preview.textContent = '1 ' + fromTxt + ' → ' + m + ' ' + target + ' | Qty target = qty odoo × multiplier';
    } else if (target !== '') {
        preview.textContent = 'UOM target: ' + target + ' | Qty target = qty odoo × multiplier';
    } else {
        preview.textContent = 'Qty target = qty odoo × multiplier';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.querySelector('select[name="barang_id"]');
    var from = document.querySelector('input[name="from_uom"]');
    var mult = document.querySelector('input[name="multiplier"]');
    if (sel) sel.addEventListener('change', updatePreview);
    if (from) from.addEventListener('input', updatePreview);
    if (mult) mult.addEventListener('input', updatePreview);
    updatePreview();
});
</script>
