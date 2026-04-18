<?php
check_role(['super_admin', 'admin_gudang']);

$q = $conn->query("
    SELECT
        b.id,
        b.kode_barang,
        b.nama_barang,
        b.satuan,
        b.kategori,
        b.stok_minimum,
        b.odoo_product_id,
        b.uom,
        b.barcode,
        b.tipe,
        COALESCE(NULLIF(uc.from_uom, ''), COALESCE(b.uom, '')) AS uom_odoo,
        COALESCE(NULLIF(uc.to_uom, ''), COALESCE(b.satuan, '')) AS uom_operasional,
        COALESCE(uc.multiplier, 1) AS uom_ratio
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY nama_barang ASC
    LIMIT 1000
");
$rows = [];
while ($q && ($r = $q->fetch_assoc())) $rows[] = $r;

$total = count($rows);
$with_min = 0;
foreach ($rows as $r) if ((int)($r['stok_minimum'] ?? 0) > 0) $with_min++;
$without_min = $total - $with_min;
?>

<div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-boxes me-2"></i>Database Barang
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Database Barang</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <div class="d-flex gap-2">
                <a href="api/export_template_min_stok.php" class="btn btn-outline-secondary">
                    <i class="fas fa-download me-2"></i>Export Template
                </a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalImportMinStok">
                    <i class="fas fa-file-import me-2"></i>Import Excel
                </button>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total Barang</div>
                    <div class="h4 mb-0 fw-bold"><?= (int)$total ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Min Stok Diset</div>
                    <div class="h4 mb-0 fw-bold"><?= (int)$with_min ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Min Stok Kosong</div>
                    <div class="h4 mb-0 fw-bold <?= $without_min > 0 ? 'text-warning' : '' ?>"><?= (int)$without_min ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-bold">Daftar Barang</div>
            <div class="text-muted small">Edit Min Stok per item</div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle" id="barangTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:90px;">ID</th>
                            <th style="width:160px;">Kode</th>
                            <th>Nama Barang</th>
                            <th style="width:130px;">UOM Operasional</th>
                            <th style="width:130px;">UOM Odoo</th>
                            <th style="width:120px;" class="text-end">Ratio</th>
                            <th style="width:100px;">Tipe</th>
                            <th style="width:140px;">Kategori</th>
                            <th style="width:120px;" class="text-end">Min Stok</th>
                            <th style="width:220px;">Odoo</th>
                            <th style="width:120px;" class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr
                            data-id="<?= (int)$r['id'] ?>"
                            data-kode="<?= htmlspecialchars((string)($r['kode_barang'] ?? ''), ENT_QUOTES) ?>"
                            data-nama="<?= htmlspecialchars((string)($r['nama_barang'] ?? ''), ENT_QUOTES) ?>"
                            data-min="<?= (int)($r['stok_minimum'] ?? 0) ?>"
                            data-tipe="<?= htmlspecialchars((string)($r['tipe'] ?? ''), ENT_QUOTES) ?>"
                        >
                            <td class="text-muted"><?= (int)$r['id'] ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars((string)($r['kode_barang'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($r['nama_barang'] ?? '-')) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)($r['uom_operasional'] ?? ($r['satuan'] ?? '-'))) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)($r['uom_odoo'] ?? ($r['uom'] ?? '-'))) ?></td>
                            <td class="text-end fw-semibold <?= (float)($r['uom_ratio'] ?? 1) === 1.0 ? 'text-muted' : '' ?>">
                                <?= htmlspecialchars(rtrim(rtrim(number_format((float)($r['uom_ratio'] ?? 1), 8, '.', ''), '0'), '.')) ?>
                            </td>
                            <td>
                                <?php if (($r['tipe'] ?? '') === 'Core'): ?>
                                    <span class="badge bg-danger">Core</span>
                                <?php elseif (($r['tipe'] ?? '') === 'Support'): ?>
                                    <span class="badge bg-info">Support</span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars((string)($r['kategori'] ?? '-')) ?></td>
                            <td class="text-end fw-semibold <?= (int)($r['stok_minimum'] ?? 0) === 0 ? 'text-muted' : '' ?>">
                                <?= (int)($r['stok_minimum'] ?? 0) ?>
                            </td>
                            <td class="small text-muted">
                                <div>odoo_product_id: <?= htmlspecialchars((string)($r['odoo_product_id'] ?? '-')) ?></div>
                                <div>uom (odoo raw): <?= htmlspecialchars((string)($r['uom'] ?? '-')) ?> • barcode: <?= htmlspecialchars((string)($r['barcode'] ?? '-')) ?></div>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary btnEditMin">
                                    <i class="fas fa-pen me-1"></i>Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditMin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-edit me-2"></i>Edit Barang
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_barang_edit.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body">
                    <input type="hidden" name="barang_id" id="editBarangId" value="">
                    <div class="mb-3">
                        <div class="text-muted small">Barang</div>
                        <div class="fw-semibold" id="editBarangLabel">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tipe Barang</label>
                        <select class="form-select" name="tipe" id="editTipeValue">
                            <option value="">- Belum Ditentukan -</option>
                            <option value="Core">Core</option>
                            <option value="Support">Support</option>
                        </select>
                        <div class="form-text">Tipe ini akan otomatis terpilih saat mapping item BHP.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Min Stok</label>
                        <input type="number" class="form-control" name="stok_minimum" id="editMinStokValue" min="0" step="1" required>
                        <div class="form-text">Nilai 0 berarti tidak ada batas minimum.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Import Min Stok -->
<div class="modal fade" id="modalImportMinStok" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-file-excel me-2"></i>Import Min Stok (Bulk)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formImportMinStok">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i> 
                        Gunakan tombol <strong>Export Template</strong> untuk mendapatkan file Excel terbaru. 
                        Isi kolom <strong>Stok Minimum</strong> dan upload kembali di sini.
                        <br><br>
                        <strong>Aturan:</strong> 
                        <ul>
                            <li>Nilai kosong akan diabaikan.</li>
                            <li>Nilai yang sama dengan database akan diabaikan.</li>
                            <li>Hanya nilai yang berubah yang akan diperbarui.</li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pilih File Excel (.xlsx)</label>
                        <input type="file" class="form-control" name="excel_file" accept=".xlsx" required>
                    </div>
                    <div id="importResult" class="mt-3 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary" id="btnDoImport">
                        <i class="fas fa-upload me-1"></i> Mulai Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btnEditMin');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;
    const id = tr.getAttribute('data-id') || '';
    const kode = tr.getAttribute('data-kode') || '-';
    const nama = tr.getAttribute('data-nama') || '-';
    const min = tr.getAttribute('data-min') || '0';
    const tipe = tr.getAttribute('data-tipe') || '';
    document.getElementById('editBarangId').value = id;
    document.getElementById('editBarangLabel').textContent = kode + ' - ' + nama;
    document.getElementById('editMinStokValue').value = min;
    document.getElementById('editTipeValue').value = tipe;
    const modal = new bootstrap.Modal(document.getElementById('modalEditMin'));
    modal.show();
});

document.getElementById('formImportMinStok').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnDoImport');
    const resultDiv = document.getElementById('importResult');
    const formData = new FormData(this);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';
    resultDiv.classList.add('d-none');

    fetch('api/ajax_import_min_stok.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload me-1"></i> Mulai Import';
        
        resultDiv.classList.remove('d-none');
        if (res.success) {
            resultDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> ${res.message}</div>`;
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> ${res.message}</div>`;
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload me-1"></i> Mulai Import';
        resultDiv.classList.remove('d-none');
        resultDiv.innerHTML = `<div class="alert alert-danger">Terjadi kesalahan sistem.</div>`;
    });
});
</script>
