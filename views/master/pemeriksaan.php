<?php
check_role(['super_admin']);

// Fetch Groups
$groups = $conn->query("
    SELECT 
        g.id,
        g.nama_pemeriksaan,
        COUNT(d.id) AS total_items
    FROM inventory_pemeriksaan_grup g
    LEFT JOIN inventory_pemeriksaan_grup_detail d ON d.pemeriksaan_grup_id = g.id
    GROUP BY g.id, g.nama_pemeriksaan
    ORDER BY g.nama_pemeriksaan ASC
    LIMIT 500
");

// Fetch Barang for Dropdown
$barangs = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang WHERE kode_barang IS NOT NULL AND kode_barang <> '' ORDER BY nama_barang ASC");
$barang_opts = [];
while($b = $barangs->fetch_assoc()) $barang_opts[] = $b;
?>

<div class="container-fluid">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-notes-medical me-2"></i>Daftar Pemeriksaan
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Master Pemeriksaan</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto text-end">
            <button class="btn btn-outline-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalImport">
                <i class="fas fa-file-import me-1"></i>Import
            </button>
            <button class="btn btn-primary btn-sm px-3" id="btnNewExam">
                <i class="fas fa-plus me-1"></i>Buat Baru
            </button>
        </div>
    </div>

<style>
    #examTable th {
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #6c757d;
    }
    #examTable td {
        padding-top: .5rem;
        padding-bottom: .5rem;
        vertical-align: middle;
    }
    #examTable .btn-group .btn {
        padding: .25rem .5rem;
        line-height: 1.1;
    }
    .segmented-control .btn {
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    .segmented-control .btn.active-mandatory {
        background-color: #dc3545 !important;
        color: white !important;
        border-color: #dc3545 !important;
    }
    .segmented-control .btn.active-optional {
        background-color: #0dcaf0 !important;
        color: white !important;
        border-color: #0dcaf0 !important;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle" id="examTable">
                        <thead class="table-light">
                            <tr>
                                <th>Nama Pemeriksaan</th>
                                <th width="120" class="text-center">Item</th>
                                <th width="140">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($g = $groups->fetch_assoc()): ?>
                                <tr data-grup-id="<?= (int)$g['id'] ?>" data-grup-nama="<?= htmlspecialchars($g['nama_pemeriksaan'], ENT_QUOTES) ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($g['nama_pemeriksaan']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border" data-role="total-items"><?= (int)$g['total_items'] ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary btnDetail" title="Detail">
                                                <i class="fas fa-list"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning btnEdit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btnDelete" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Import -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formImport" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Import Pemeriksaan & Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i> Gunakan template Excel untuk mengimport data secara massal. Pastikan kolom <strong>Kategori</strong> diisi dengan "Mandatory" atau "Optional".
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Download Template</label>
                        <div>
                            <a href="api/download_template_pemeriksaan.php" class="btn btn-sm btn-outline-success">
                                <i class="fas fa-file-excel me-1"></i> Template_Pemeriksaan.xlsx
                            </a>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pilih File Excel (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary-custom" id="btnDoImport">
                        <i class="fas fa-upload me-1"></i> Mulai Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal View -->
<div class="modal fade" id="modalViewGrup" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="viewTitle">Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th width="100" class="text-center">Tipe</th>
                                <th width="80" class="text-center">Qty</th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetailGrup" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="detailTitle">Edit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="detailGrupId" value="">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-bold">Item Consumable / Obat</div>
                            <div class="text-muted small">Barang lokal (ID/Kode Barang)</div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th width="120" class="text-center">Kategori</th>
                                        <th width="80" class="text-center">Qty</th>
                                        <th width="44"></th>
                                    </tr>
                                </thead>
                                <tbody id="detailItemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="fw-bold mb-2 text-primary"><i class="fas fa-cog me-1"></i> Pengaturan</div>
                            <label class="form-label mb-1 small fw-bold">Nama Pemeriksaan</label>
                            <div class="input-group mb-4">
                                <input type="text" class="form-control" id="detailGrupNama" value="">
                                <button type="button" class="btn btn-primary-custom" id="btnSaveNama">
                                    <i class="fas fa-save"></i>
                                </button>
                            </div>

                            <div class="fw-bold mb-2 text-success"><i class="fas fa-plus-circle me-1"></i> Tambah Item</div>
                            <form class="row g-2" id="formAddMapping">
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-bold">Pilih Barang</label>
                                    <select name="barang_id" class="form-select form-select-sm select2" required id="detailBarangId">
                                        <option value="">- Pilih Barang -</option>
                                        <?php foreach($barang_opts as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['kode_barang']) ?> - <?= htmlspecialchars($b['nama_barang']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-1 small fw-bold">Kategori</label>
                                    <div class="segmented-control">
                                        <input type="hidden" name="is_mandatory" id="detailIsMandatory" value="1">
                                        <div class="btn-group btn-group-sm w-100">
                                            <button type="button" class="btn btn-outline-danger active-mandatory btn-seg-main" data-val="1">Mandatory</button>
                                            <button type="button" class="btn btn-outline-info btn-seg-main" data-val="0">Optional</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-1 small fw-bold">Qty</label>
                                    <input type="number" name="qty" class="form-control form-control-sm" placeholder="Qty" required min="1" value="1" id="detailQty">
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-sm btn-success w-100 fw-bold"><i class="fas fa-plus me-1"></i> Tambah ke Mapping</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGrup" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form id="formGrup" class="modal-content">
            <input type="hidden" name="id" id="grupId" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="grupModalTitle">Buat Pemeriksaan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nama Pemeriksaan</label>
                        <input type="text" name="nama_pemeriksaan" class="form-control" required id="grupNama" placeholder="Contoh: Paket Screening A">
                    </div>
                    
                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Mapping Item BHP</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                                <i class="fas fa-plus me-1"></i>Tambah Baris
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle" id="tableNewMapping">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item Barang</th>
                                        <th width="150" class="text-center">Kategori</th>
                                        <th width="120" class="text-center">Jumlah (Qty)</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="newMappingBody">
                                    <tr>
                                        <td>
                                            <select name="barang_ids[]" class="form-select form-select-sm select2-modal" required>
                                                <option value="">- Pilih Barang -</option>
                                                <?php foreach($barang_opts as $b): ?>
                                                    <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['kode_barang']) ?> - <?= htmlspecialchars($b['nama_barang']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="segmented-control">
                                                <input type="hidden" name="is_mandatory_list[]" class="hid-mandatory" value="1">
                                                <div class="btn-group btn-group-sm w-100">
                                                    <button type="button" class="btn btn-outline-danger active-mandatory btn-seg-row" data-val="1">Mandatory</button>
                                                    <button type="button" class="btn btn-outline-info btn-seg-row" data-val="0">Optional</button>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" name="qtys[]" class="form-control form-control-sm text-center" value="1" min="1" required>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger btnRemoveRow"><i class="fas fa-times"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary-custom px-4">Simpan Pemeriksaan</button>
            </div>
        </form>
    </div>
</div>

</div> <!-- End container-fluid -->

<script>
const PEMERIKSAAN_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
function getRowByGrupId(grupId) {
    return $('#examTable tbody tr[data-grup-id="' + grupId + '"]');
}

function setRowTotalItems(grupId, total) {
    const row = getRowByGrupId(grupId);
    row.find('[data-role="total-items"]').text(total);
}

function loadDetail(grupId) {
    $('#detailItemsBody').html('<tr><td colspan="4" class="text-center text-muted py-3">Memuat...</td></tr>');
    $.ajax({
        url: 'api/ajax_pemeriksaan_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { grup_id: grupId },
        success: function(res) {
            if (!res || !res.success) {
                $('#detailItemsBody').html('<tr><td colspan="4" class="text-center text-danger py-3">' + (res && res.message ? res.message : 'Gagal memuat') + '</td></tr>');
                return;
            }
            $('#detailGrupId').val(res.grup.id);
            $('#detailGrupNama').val(res.grup.nama_pemeriksaan);
            $('#detailTitle').text('Edit: ' + res.grup.nama_pemeriksaan);
            const rows = [];
            if (Array.isArray(res.details) && res.details.length > 0) {
                res.details.forEach(function(d) {
                    const code = (d.kode_barang ? d.kode_barang : (d.barang_id ? d.barang_id : '-'));
                    const itemText = code + ' - ' + (d.nama_barang ? d.nama_barang : '') + (d.satuan ? ' (' + d.satuan + ')' : '');
                    const badge = parseInt(d.is_mandatory) === 1 ? 
                        '<span class="badge bg-danger px-2"><i class="fas fa-exclamation-circle me-1"></i>Mandatory</span>' : 
                        '<span class="badge bg-info px-2"><i class="fas fa-info-circle me-1"></i>Optional</span>';
                    rows.push(
                        '<tr>' +
                            '<td class="fw-semibold">' + $('<div>').text(itemText).html() + '</td>' +
                            '<td class="text-center">' + badge + '</td>' +
                            '<td class="text-center fw-semibold">' + d.qty_per_pemeriksaan + '</td>' +
                            '<td class="text-center">' +
                                '<button type="button" class="btn btn-sm btn-outline-danger btnDeleteDetail" data-detail-id="' + d.id + '"><i class="fas fa-trash"></i></button>' +
                            '</td>' +
                        '</tr>'
                    );
                });
            } else {
                rows.push('<tr><td colspan="4" class="text-center text-muted py-3">Belum ada mapping item.</td></tr>');
            }
            $('#detailItemsBody').html(rows.join(''));
            setRowTotalItems(res.grup.id, res.total_items);
        },
        error: function() {
            $('#detailItemsBody').html('<tr><td colspan="4" class="text-center text-danger py-3">Gagal memuat</td></tr>');
        }
    });
}

function loadView(grupId) {
    $('#viewItemsBody').html('<tr><td colspan="3" class="text-center text-muted py-3">Memuat...</td></tr>');
    $.ajax({
        url: 'api/ajax_pemeriksaan_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { grup_id: grupId },
        success: function(res) {
            if (!res || !res.success) {
                $('#viewItemsBody').html('<tr><td colspan="3" class="text-center text-danger py-3">' + (res && res.message ? res.message : 'Gagal memuat') + '</td></tr>');
                return;
            }
            $('#viewTitle').text('Detail: ' + res.grup.nama_pemeriksaan);
            const rows = [];
            if (Array.isArray(res.details) && res.details.length > 0) {
                res.details.forEach(function(d) {
                    const code = (d.kode_barang ? d.kode_barang : (d.barang_id ? d.barang_id : '-'));
                    const itemText = code + ' - ' + (d.nama_barang ? d.nama_barang : '') + (d.satuan ? ' (' + d.satuan + ')' : '');
                    const badge = parseInt(d.is_mandatory) === 1 ? 
                        '<span class="badge bg-danger px-2">Mandatory</span>' : 
                        '<span class="badge bg-info px-2">Optional</span>';
                    rows.push(
                        '<tr>' +
                            '<td class="fw-semibold">' + $('<div>').text(itemText).html() + '</td>' +
                            '<td class="text-center">' + badge + '</td>' +
                            '<td class="text-center fw-semibold">' + d.qty_per_pemeriksaan + '</td>' +
                        '</tr>'
                    );
                });
            } else {
                rows.push('<tr><td colspan="3" class="text-center text-muted py-3">Belum ada mapping item.</td></tr>');
            }
            $('#viewItemsBody').html(rows.join(''));
            setRowTotalItems(res.grup.id, res.total_items);
        },
        error: function() {
            $('#viewItemsBody').html('<tr><td colspan="3" class="text-center text-danger py-3">Gagal memuat</td></tr>');
        }
    });
}

$(document).ready(function() {
    // Handle Segmented Button Clicks (Main Form)
    $(document).on('click', '.btn-seg-main', function() {
        const val = $(this).data('val');
        $('#detailIsMandatory').val(val);
        $(this).siblings().removeClass('active-mandatory active-optional').addClass('btn-outline-info');
        $(this).removeClass('btn-outline-info');
        if (parseInt(val) === 1) {
            $(this).addClass('active-mandatory');
        } else {
            $(this).addClass('active-optional');
        }
    });

    // Handle Segmented Button Clicks (Table Rows)
    $(document).on('click', '.btn-seg-row', function() {
        const val = $(this).data('val');
        $(this).closest('.segmented-control').find('.hid-mandatory').val(val);
        $(this).siblings().removeClass('active-mandatory active-optional').addClass('btn-outline-info');
        $(this).removeClass('btn-outline-info');
        if (parseInt(val) === 1) {
            $(this).addClass('active-mandatory');
        } else {
            $(this).addClass('active-optional');
        }
    });

    $('#examTable').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 10,
        "lengthChange": false,
        "dom": "<'row mb-2'<'col-sm-12 col-md-6 d-flex align-items-center'><'col-sm-12 col-md-6 d-flex align-items-center justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        "language": {
            "searchPlaceholder": "Cari..."
        }
    });

    $('#btnNewExam').on('click', function() {
        $('#grupModalTitle').text('Buat Pemeriksaan Baru');
        $('#grupId').val('');
        $('#grupNama').val('');
        $('#newMappingBody').html('<tr>' +
            '<td><select name="barang_ids[]" class="form-select form-select-sm select2-modal" required><option value="">- Pilih Barang -</option>' + 
            <?= json_encode(array_reduce($barang_opts, function($carry, $item) {
                return $carry . '<option value="'.(int)$item['id'].'">'.htmlspecialchars($item['kode_barang']).' - '.htmlspecialchars($item['nama_barang']).'</option>';
            }, '')) ?> + 
            '</select></td>' +
            '<td><div class="segmented-control"><input type="hidden" name="is_mandatory_list[]" class="hid-mandatory" value="1"><div class="btn-group btn-group-sm w-100"><button type="button" class="btn btn-outline-danger active-mandatory btn-seg-row" data-val="1">Mandatory</button><button type="button" class="btn btn-outline-info btn-seg-row" data-val="0">Optional</button></div></div></td>' +
            '<td><input type="number" name="qtys[]" class="form-control form-control-sm text-center" value="1" min="1" required></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnRemoveRow"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
        initSelect2Modal();
        new bootstrap.Modal(document.getElementById('modalGrup')).show();
    });

    $('#btnAddRow').off('click').on('click', function() {
        const newRow = $('<tr>' +
            '<td><select name="barang_ids[]" class="form-select form-select-sm select2-modal" required><option value="">- Pilih Barang -</option>' + 
            <?= json_encode(array_reduce($barang_opts, function($carry, $item) {
                return $carry . '<option value="'.(int)$item['id'].'">'.htmlspecialchars($item['kode_barang']).' - '.htmlspecialchars($item['nama_barang']).'</option>';
            }, '')) ?> + 
            '</select></td>' +
            '<td><div class="segmented-control"><input type="hidden" name="is_mandatory_list[]" class="hid-mandatory" value="1"><div class="btn-group btn-group-sm w-100"><button type="button" class="btn btn-outline-danger active-mandatory btn-seg-row" data-val="1">Mandatory</button><button type="button" class="btn btn-outline-info btn-seg-row" data-val="0">Optional</button></div></div></td>' +
            '<td><input type="number" name="qtys[]" class="form-control form-control-sm text-center" value="1" min="1" required></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnRemoveRow"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
        $('#newMappingBody').append(newRow);
        initSelect2Modal();
    });

    $(document).on('click', '.btnRemoveRow', function() {
        if ($('#newMappingBody tr').length > 1) {
            $(this).closest('tr').remove();
        } else {
            alert('Minimal harus ada 1 baris item.');
        }
    });

    function initSelect2Modal() {
        $('.select2-modal').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $('#modalGrup')
                });
            }
        });
    }

    $('#examTable').on('click', '.btnEdit', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        loadDetail(id);
        const m = new bootstrap.Modal(document.getElementById('modalDetailGrup'));
        m.show();
    });

    $('#examTable').on('click', '.btnDetail', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        loadView(id);
        new bootstrap.Modal(document.getElementById('modalViewGrup')).show();
    });

    $('#examTable').on('click', '.btnDelete', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        const nama = tr.data('grup-nama');
        if (!confirm('Hapus pemeriksaan "' + nama + '" beserta mapping itemnya?')) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_delete.php',
            method: 'POST',
            dataType: 'json',
            data: { id: id, _csrf: PEMERIKSAAN_CSRF },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menghapus');
                    return;
                }
                tr.remove();
            },
            error: function() {
                alert('Gagal menghapus');
            }
        });
    });

    $('#formGrup').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: 'api/ajax_pemeriksaan_save.php',
            method: 'POST',
            dataType: 'json',
            data: $(this).serialize() + '&_csrf=' + encodeURIComponent(PEMERIKSAAN_CSRF),
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan');
                    return;
                }
                const id = res.id;
                const nama = res.nama_pemeriksaan;
                const existing = getRowByGrupId(id);
                if (existing.length) {
                    existing.data('grup-nama', nama);
                    existing.attr('data-grup-nama', nama);
                    existing.find('td').first().text(nama);
                    $('#modalGrup').modal('hide');
                } else {
                    // Success, reload page to show new data with mapping count
                    $('#modalGrup').modal('hide');
                    location.reload();
                }
            },
            error: function() {
                alert('Gagal menyimpan');
            }
        });
    });

    $('#formImport').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('_csrf', PEMERIKSAAN_CSRF);
        
        const btn = $('#btnDoImport');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Mengimport...');
        
        $.ajax({
            url: 'api/ajax_pemeriksaan_import.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal mengimport');
                    return;
                }
                alert(res.message);
                location.reload();
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
                alert('Terjadi kesalahan saat mengimport.');
            }
        });
    });

    $('#formAddMapping').on('submit', function(e) {
        e.preventDefault();
        const grupId = $('#detailGrupId').val();
        const barangId = $('#detailBarangId').val();
        const qty = $('#detailQty').val();
        const isMandatory = $('#detailIsMandatory').val();
        if (!grupId || !barangId || !qty) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_detail_save.php',
            method: 'POST',
            dataType: 'json',
            data: { grup_id: grupId, barang_id: barangId, qty: qty, is_mandatory: isMandatory, _csrf: PEMERIKSAAN_CSRF },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan mapping');
                    return;
                }
                loadDetail(grupId);
                $('#detailBarangId').val('').trigger('change');
                $('#detailQty').val(1);
                $('#detailIsMandatory').val(1);
                $('.btn-seg-main[data-val="1"]').trigger('click');
            },
            error: function() {
                alert('Gagal menyimpan mapping');
            }
        });
    });

    $('#detailItemsBody').on('click', '.btnDeleteDetail', function() {
        const detailId = $(this).data('detail-id');
        const grupId = $('#detailGrupId').val();
        if (!confirm('Hapus item mapping ini?')) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_detail_delete.php',
            method: 'POST',
            dataType: 'json',
            data: { detail_id: detailId, _csrf: PEMERIKSAAN_CSRF },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menghapus');
                    return;
                }
                loadDetail(grupId);
            },
            error: function() {
                alert('Gagal menghapus');
            }
        });
    });

    $('#btnSaveNama').on('click', function() {
        const grupId = $('#detailGrupId').val();
        const nama = ($('#detailGrupNama').val() || '').trim();
        if (!grupId || !nama) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_save.php',
            method: 'POST',
            dataType: 'json',
            data: { id: grupId, nama_pemeriksaan: nama, _csrf: PEMERIKSAAN_CSRF },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan');
                    return;
                }
                const id = res.id;
                const nm = res.nama_pemeriksaan;
                const row = getRowByGrupId(id);
                row.data('grup-nama', nm);
                row.attr('data-grup-nama', nm);
                row.find('td').first().text(nm);
                $('#detailTitle').text('Edit: ' + nm);
            },
            error: function() {
                alert('Gagal menyimpan');
            }
        });
    });

    $('#modalDetailGrup').on('shown.bs.modal', function() {
        const $sel = $('#detailBarangId');
        if ($sel.length) {
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalDetailGrup') });
        }
    });
});
</script>
