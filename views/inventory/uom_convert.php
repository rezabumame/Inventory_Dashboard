<?php
check_role(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $barang_id = (int)($_POST['barang_id'] ?? 0);
        $from_uom = trim((string)($_POST['from_uom'] ?? ''));
        $to_uom = trim((string)($_POST['to_uom'] ?? ''));
        $multiplier = (string)($_POST['multiplier'] ?? '1');
        $note = trim((string)($_POST['note'] ?? ''));
        $mult = (float)$multiplier;
        if ($barang_id > 0 && $mult > 0) {
            $base = $conn->query("SELECT COALESCE(NULLIF(uom,''),'') AS uom, COALESCE(NULLIF(satuan,''),'') AS satuan FROM barang WHERE id = $barang_id LIMIT 1")->fetch_assoc();
            $base_uom = trim((string)($base['uom'] ?? ''));
            $satuan_db = trim((string)($base['satuan'] ?? ''));
            if ($base_uom !== '') $from_uom = $base_uom;
            if ($to_uom === '' && $satuan_db !== '') $to_uom = $satuan_db;
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
$res = $conn->query("SELECT id, kode_barang, nama_barang, satuan, COALESCE(NULLIF(uom,''),'') AS uom FROM barang WHERE kode_barang IS NOT NULL AND TRIM(kode_barang) <> '' ORDER BY nama_barang ASC");
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
            UOM Odoo adalah unit dasar dari Odoo. UOM Operasional mengikuti satuan di master barang (dipakai di UI & input).
            Ratio Konversi menyatakan: 1 UOM Operasional = Ratio UOM Odoo.
            Rumus: Qty Operasional = Qty Odoo ÷ Ratio, dan Qty Odoo = Qty Operasional × Ratio.
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div id="uomEditStatus" class="text-muted small"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearForm">Bersihkan Form</button>
                </div>
            </div>
            <form method="POST" class="row g-3 align-items-end" id="uomForm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="save">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-muted mb-1">Nama Barang</label>
                    <select name="barang_id" class="form-select" required>
                        <option value="">- Pilih Barang -</option>
                        <?php foreach ($barang_list as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" data-satuan="<?= htmlspecialchars((string)($b['satuan'] ?? ''), ENT_QUOTES) ?>" data-uom-odoo="<?= htmlspecialchars((string)($b['uom'] ?? ''), ENT_QUOTES) ?>" data-label="<?= htmlspecialchars(($b['kode_barang'] ?? '-') . ' - ' . ($b['nama_barang'] ?? '-')) ?>"><?= htmlspecialchars(($b['kode_barang'] ?? '-') . ' - ' . ($b['nama_barang'] ?? '-') . ' (' . ($b['satuan'] ?? '-') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="from_uom" id="fromUomHidden" value="">
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">UOM Operasional</label>
                    <input type="text" name="to_uom" class="form-control" placeholder="contoh: Btl/Pcs" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">Ratio Konversi (Operasional → Odoo)</label>
                    <input type="number" name="multiplier" class="form-control" step="0.00000001" min="0" value="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted mb-1">Catatan</label>
                    <input type="text" name="note" class="form-control" placeholder="opsional">
                </div>
                <div class="col-12">
                    <div class="small text-muted" id="uomPreview"></div>
                </div>
                <div class="col-md-12 d-grid">
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
                            <th>UOM Operasional</th>
                            <th class="text-end">Ratio</th>
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
                                    <td><?= htmlspecialchars($c['to_uom'] ?? '-') ?></td>
                                    <td class="text-end fw-semibold">
                                        <?= htmlspecialchars(rtrim(rtrim(number_format((float)$c['multiplier'], 8, '.', ''), '0'), '.')) ?>
                                        <div class="small text-muted">1 <?= htmlspecialchars($c['to_uom'] ?? '-') ?> → <?= htmlspecialchars(rtrim(rtrim(number_format((float)$c['multiplier'], 8, '.', ''), '0'), '.')) ?> <?= htmlspecialchars($c['from_uom'] ?? '-') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($c['note'] ?? '-') ?></td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary me-1 btnEditConv"
                                                data-barang-id="<?= (int)$c['barang_id'] ?>"
                                                data-from-uom="<?= htmlspecialchars((string)($c['from_uom'] ?? ''), ENT_QUOTES) ?>"
                                                data-to-uom="<?= htmlspecialchars((string)($c['to_uom'] ?? ''), ENT_QUOTES) ?>"
                                                data-multiplier="<?= htmlspecialchars((string)($c['multiplier'] ?? '1'), ENT_QUOTES) ?>"
                                                data-note="<?= htmlspecialchars((string)($c['note'] ?? ''), ENT_QUOTES) ?>"
                                                data-label="<?= htmlspecialchars(($c['kode_barang'] ?? '-') . ' - ' . ($c['nama_barang'] ?? '-'), ENT_QUOTES) ?>">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
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
    var to = document.querySelector('input[name="to_uom"]');
    var mult = document.querySelector('input[name="multiplier"]');
    var preview = document.getElementById('uomPreview');
    if (!preview) return;
    var target = to && to.value ? to.value.trim() : '';
    var fromTxt = (from && from.value) ? from.value.trim() : '';
    var multVal = (mult && mult.value) ? mult.value : '1';
    var m = fmtPreviewNumber(multVal);
    if (fromTxt !== '' && target !== '') {
        preview.textContent = '1 ' + target + ' = ' + m + ' ' + fromTxt + ' | Qty operasional = qty odoo ÷ ratio';
    } else if (target !== '') {
        preview.textContent = '1 ' + target + ' = ' + m + ' (UOM Odoo) | Qty operasional = qty odoo ÷ ratio';
    } else {
        preview.textContent = 'Qty operasional = qty odoo ÷ ratio';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.querySelector('select[name="barang_id"]');
    var from = document.querySelector('input[name="from_uom"]');
    var to = document.querySelector('input[name="to_uom"]');
    var mult = document.querySelector('input[name="multiplier"]');
    var note = document.querySelector('input[name="note"]');
    var editStatus = document.getElementById('uomEditStatus');
    var btnClear = document.getElementById('btnClearForm');
    function clearForm() {
        if (sel) sel.value = '';
        if (from) from.value = '';
        var fh = document.getElementById('fromUomHidden'); if (fh) fh.value = '';
        if (to) to.value = '';
        if (mult) mult.value = '1';
        if (note) note.value = '';
        if (editStatus) editStatus.textContent = '';
        updatePreview();
        sel && sel.dispatchEvent(new Event('change'));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    if (btnClear) btnClear.addEventListener('click', clearForm);
    if (sel) {
        sel.addEventListener('change', function() {
            var opt = sel.selectedOptions && sel.selectedOptions.length ? sel.selectedOptions[0] : null;
            var base = opt ? (opt.getAttribute('data-uom-odoo') || '') : '';
            var satuan = opt ? (opt.getAttribute('data-satuan') || '') : '';
            var label = opt ? (opt.getAttribute('data-label') || '') : '';
            var fromHidden = document.getElementById('fromUomHidden');
            if (fromHidden) fromHidden.value = base || '';
            if (to && (!to.value || to.value.trim() === '')) {
                to.value = satuan || '';
            }
            if (editStatus) editStatus.textContent = label ? ('Memilih: ' + label) : '';
            updatePreview();
        });
    }
    if (from) from.addEventListener('input', updatePreview);
    if (to) to.addEventListener('input', updatePreview);
    if (mult) mult.addEventListener('input', updatePreview);
    updatePreview();
    document.querySelectorAll('.btnEditConv').forEach(function(btn){
        btn.addEventListener('click', function(){
            var bid = this.getAttribute('data-barang-id');
            var fu = this.getAttribute('data-from-uom') || '';
            var tu = this.getAttribute('data-to-uom') || '';
            var mm = this.getAttribute('data-multiplier') || '1';
            var nt = this.getAttribute('data-note') || '';
            var label = this.getAttribute('data-label') || '';
            if (sel) sel.value = bid || '';
            var fh = document.getElementById('fromUomHidden'); if (fh) fh.value = fu || '';
            if (to) to.value = tu || '';
            if (mult) mult.value = mm || '1';
            if (note) note.value = nt || '';
            if (editStatus) editStatus.textContent = label ? ('Mode edit: ' + label) : 'Mode edit';
            updatePreview();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
});
</script>
