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
            <a href="api/export_template_min_stok.php" class="btn btn-outline-secondary shadow-sm">
                <i class="fas fa-download me-2"></i>Export Template
            </a>
            <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalImportMinStok">
                <i class="fas fa-file-import me-2"></i>Import Excel
            </button>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Total Barang Odoo</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$total ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Min Stok Diset</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$with_min ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Min Stok Kosong</div>
                <div class="h4 mb-0 fw-bold <?= $without_min > 0 ? 'text-warning' : '' ?>"><?= (int)$without_min ?></div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-4" id="barangTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active rounded-pill px-4" id="odoo-tab" data-bs-toggle="pill" data-bs-target="#tab-odoo" type="button" role="tab">
            <i class="fas fa-boxes me-2"></i>Barang Odoo
        </button>
    </li>
    <li class="nav-item ms-2" role="presentation">
        <button class="nav-link rounded-pill px-4" id="non-odoo-tab" data-bs-toggle="pill" data-bs-target="#tab-non-odoo" type="button" role="tab">
            <i class="fas fa-box-open me-2"></i>Barang Non-Odoo
        </button>
    </li>
</ul>

<style>
    .nav-pills .nav-link { color: #6c757d; font-weight: 500; }
    .nav-pills .nav-link.active { background-color: #204EAB !important; color: white !important; }
</style>

<div class="tab-content" id="barangTabsContent">
    <!-- Odoo Items Tab -->
    <div class="tab-pane fade show active" id="tab-odoo" role="tabpanel">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">Daftar Barang Odoo</h5>
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
                                    <button type="button" class="btn btn-sm btn-outline-primary btnEditMin shadow-sm">
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

    <!-- Non-Odoo Items Tab -->
    <div class="tab-pane fade" id="tab-non-odoo" role="tabpanel">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">Daftar Barang Non-Odoo (Lokal)</h5>
                <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAddItemNonOdoo">
                    <i class="fas fa-plus me-2"></i>Tambah Item Baru
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tableMasterItemNonOdoo" class="table table-hover align-middle datatable" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th width="120">Kode Item</th>
                                <th>Nama Item</th>
                                <th>UOM</th>
                                <th width="120" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Barang Odoo -->
<div class="modal fade" id="modalEditMin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary-custom">
                    <i class="fas fa-edit me-2"></i>Edit Barang Odoo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_barang_edit.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body py-4">
                    <input type="hidden" name="barang_id" id="editBarangId" value="">
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <div class="text-muted x-small text-uppercase fw-bold mb-1">Barang</div>
                        <div class="fw-bold" id="editBarangLabel">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Tipe Barang</label>
                        <select class="form-select" name="tipe" id="editTipeValue">
                            <option value="">- Belum Ditentukan -</option>
                            <option value="Core">Core</option>
                            <option value="Support">Support</option>
                        </select>
                        <div class="form-text x-small text-muted">Tipe ini akan otomatis terpilih saat mapping item BHP.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Min Stok</label>
                        <input type="number" class="form-control" name="stok_minimum" id="editMinStokValue" min="0" step="1" required>
                        <div class="form-text x-small text-muted">Nilai 0 berarti tidak ada batas minimum.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Non-Odoo Item -->
<div class="modal fade" id="modalAddItemNonOdoo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form id="formAddItemNonOdoo">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary-custom" id="nonOdooModalTitle">Tambah Item Non-Odoo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <input type="hidden" name="id" id="non_odoo_edit_id">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nama Item</label>
                        <input type="text" name="nama_item" id="non_odoo_edit_nama_item" class="form-control" placeholder="Contoh: Alkohol Swab Lokal" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">UOM (Satuan)</label>
                        <input type="text" name="uom" id="non_odoo_edit_uom" class="form-control" placeholder="Contoh: Pcs, Box, ml" required>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Import Min Stok -->
<div class="modal fade" id="modalImportMinStok" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary-custom">
                    <i class="fas fa-file-excel me-2"></i>Import Min Stok (Bulk)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formImportMinStok">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body py-4">
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle me-1"></i> 
                        Gunakan tombol <strong>Export Template</strong> untuk mendapatkan file Excel terbaru. 
                        Isi kolom <strong>Stok Minimum</strong> dan upload kembali di sini.
                        <br><br>
                        <strong>Aturan:</strong> 
                        <ul>
                            <li>Nilai kosong akan diabaikan.</li>
                            <li>Hanya nilai yang berubah yang akan diperbarui.</li>
                        </ul>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">Pilih File Excel (.xlsx)</label>
                        <input type="file" class="form-control" name="excel_file" accept=".xlsx" required>
                    </div>
                    <div id="importResult" class="mt-3 d-none"></div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary px-4" id="btnDoImport">
                        <i class="fas fa-upload me-1"></i> Mulai Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // 1. Odoo Item Edit Modal
    $(document).on('click', '.btnEditMin', function() {
        const tr = $(this).closest('tr');
        const id = tr.attr('data-id') || '';
        const kode = tr.attr('data-kode') || '-';
        const nama = tr.attr('data-nama') || '-';
        const min = tr.attr('data-min') || '0';
        const tipe = tr.attr('data-tipe') || '';
        $('#editBarangId').val(id);
        $('#editBarangLabel').text(kode + ' - ' + nama);
        $('#editMinStokValue').val(min);
        $('#editTipeValue').val(tipe);
        $('#modalEditMin').modal('show');
    });

    // 2. Import Logic
    $('#formImportMinStok').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#btnDoImport');
        const resultDiv = $('#importResult');
        const formData = new FormData(this);

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');
        resultDiv.addClass('d-none');

        fetch('api/ajax_import_min_stok.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(res => {
            btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
            resultDiv.removeClass('d-none');
            if (res.success) {
                resultDiv.html(`<div class="alert alert-success mt-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> ${res.message}</div>`);
                setTimeout(() => { location.reload(); }, 2000);
            } else {
                resultDiv.html(`<div class="alert alert-danger mt-2 mb-0 small"><i class="fas fa-exclamation-circle me-1"></i> ${res.message}</div>`);
            }
        })
        .catch(err => {
            btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
            resultDiv.removeClass('d-none').html(`<div class="alert alert-danger mt-2 mb-0 small">Terjadi kesalahan sistem.</div>`);
        });
    });

    // 3. Non-Odoo Item Logic
    var tableNonOdoo = $('#tableMasterItemNonOdoo').DataTable({
        ajax: 'api/ajax_bhp_lokal.php?action=list_master',
        columns: [
            { data: 'kode_item' },
            { data: 'nama_item', render: function(data) { return '<b>' + data + '</b>'; } },
            { data: 'uom', render: function(data) { return '<span class="badge bg-secondary-light text-dark">' + data + '</span>'; } },
            { 
                data: null, 
                orderable: false,
                className: 'text-end',
                render: function(data) {
                    return `
                        <button class="btn btn-sm btn-outline-primary btn-edit-non-odoo shadow-sm" data-id="${data.id}" data-name="${data.nama_item}" data-uom="${data.uom}" title="Edit Master">
                            <i class="fas fa-edit me-1"></i> Edit
                        </button>
                    `;
                }
            }
        ]
    });

    $('#formAddItemNonOdoo').submit(function(e) {
        e.preventDefault();
        var data = $(this).serialize();
        $.post('api/ajax_bhp_lokal.php?action=save_item', data, function(res) {
            if (res.success) {
                $('#modalAddItemNonOdoo').modal('hide');
                tableNonOdoo.ajax.reload();
                Swal.fire('Berhasil', res.message, 'success');
            } else {
                Swal.fire('Gagal', res.message, 'error');
            }
        }, 'json');
    });

    $(document).on('click', '.btn-edit-non-odoo', function() {
        var d = $(this).data();
        $('#non_odoo_edit_id').val(d.id);
        $('#non_odoo_edit_nama_item').val(d.name);
        $('#non_odoo_edit_uom').val(d.uom);
        $('#nonOdooModalTitle').text('Edit Item Non-Odoo');
        $('#modalAddItemNonOdoo').modal('show');
    });

    $('#modalAddItemNonOdoo').on('hidden.bs.modal', function() {
        $('#formAddItemNonOdoo')[0].reset();
        $('#non_odoo_edit_id').val('');
        $('#nonOdooModalTitle').text('Tambah Item Non-Odoo');
    });
});
</script>
