<?php
require_once __DIR__ . '/../../config/settings.php';
check_role(['super_admin', 'admin_klinik', 'cs']);

$role = $_SESSION['role'] ?? '';
$can_edit = ($role === 'super_admin');

// Fetch Groups - REMOVED for server-side pagination
//$groups = $conn->query("..."); 

// Fetch Barang for Dropdown
$barangs = $conn->query("
    SELECT 
        b.id, 
        b.kode_barang, 
        b.nama_barang, 
        COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS satuan, 
        b.tipe 
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE b.kode_barang IS NOT NULL AND b.kode_barang <> '' 
    ORDER BY b.nama_barang ASC
");
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
            <?php if ($can_edit): ?>
            <button class="btn btn-outline-primary btn-sm me-2" id="btnConfigGSheet">
                <i class="fas fa-cog me-1"></i>Config GSheet
            </button>
            <button class="btn btn-outline-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalImport">
                <i class="fas fa-file-import me-1"></i>Import
            </button>
            <button class="btn btn-primary btn-sm px-3" id="btnNewExam">
                <i class="fas fa-plus me-1"></i>Buat Baru
            </button>
            <?php endif; ?>
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
    /* Expand Row Styles */
    .btn-toggle-detail {
        cursor: pointer;
        color: #204EAB;
        width: 30px;
        text-align: center;
        font-size: 0.9rem;
    }
    .main-row {
        cursor: pointer;
    }
    .main-row:hover {
        background-color: rgba(32, 78, 171, 0.05) !important;
    }
    .detail-row {
        background-color: #fcfcfc;
        display: none;
    }
    .detail-container {
        padding: 15px;
        border-left: 4px solid #204EAB;
    }
    .table-detail th {
        background-color: #e9ecef !important;
        font-size: 0.7rem !important;
        color: #495057 !important;
    }
    .extra-small {
        font-size: 0.7rem;
    }
    .btn-xs {
        padding: 0.1rem 0.4rem;
        font-size: 0.7rem;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="examTable">
                        <thead class="table-light">
                            <tr>
                                <th width="40"></th>
                                <th width="120">ID Paket</th>
                                <th>Nama Paket</th>
                                <th width="120" class="text-center">Item</th>
                                <?php if ($can_edit): ?>
                                <th width="100">Aksi</th>
                                <?php endif; ?>
                                <th class="d-none">Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Loaded via DataTables Server-side -->
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
                        <i class="fas fa-info-circle me-1"></i> Gunakan template Excel untuk mengimport data secara massal. Item mapping akan otomatis menyesuaikan dengan data di <strong>Database Barang</strong>.
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
                        <label class="form-label fw-bold">Pilih File Excel (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                        <div class="form-text">Gunakan template yang sudah didownload.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="delete_all" id="checkDeleteAll" value="1">
                        <label class="form-check-label fw-bold text-danger" for="checkDeleteAll">
                            Hapus Semua Data Lama Sebelum Import
                        </label>
                        <div class="form-text small text-muted">Centang ini jika Anda ingin mengganti seluruh daftar pemeriksaan dengan data dari file Excel.</div>
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

<!-- Modal Config GSheet -->
<div class="modal fade" id="modalConfigGSheet" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>Konfigurasi Google Sheets</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Apps Script Webhook URL</label>
                    <div class="input-group">
                        <input type="text" id="gsheetUrl" class="form-control" placeholder="https://script.google.com/macros/s/.../exec" value="<?= get_setting('gsheet_exam_url') ?>">
                        <button class="btn btn-primary" id="btnCheckGSheet">Cek Sheet</button>
                    </div>
                    <div class="form-text text-muted">Input URL Webhook dari Google Apps Script yang sudah dideploy.</div>
                </div>

                <div id="sectionSheetMapping" style="display:none;">
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Sheet</label>
                        <select id="gsheetName" class="form-select"></select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Mapping Kolom</label>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Field Database</th>
                                        <th>Kolom GSheet</th>
                                    </tr>
                                </thead>
                                <tbody id="mappingBody">
                                    <tr>
                                        <td>ID Paket / Kode Pemeriksaan</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="id_paket"></select></td>
                                    </tr>
                                    <tr>
                                        <td>Nama Pemeriksaan / Paket</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="nama_paket"></select></td>
                                    </tr>
                                    <tr>
                                        <td>ID Biosys</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="id_biosys"></select></td>
                                    </tr>
                                    <tr>
                                        <td>Layanan</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="layanan"></select></td>
                                    </tr>
                                    <tr>
                                        <td>ID Barang / Kode Odoo</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="barang_id"></select></td>
                                    </tr>
                                    <tr>
                                        <td>Qty</td>
                                        <td><select class="form-select form-select-sm map-col" data-key="qty"></select></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-info" id="btnSyncNow" style="display:none;"><i class="fas fa-sync me-1"></i>Sync Sekarang</button>
                <button type="button" class="btn btn-primary" id="btnSaveGSheetConfig">Simpan Konfigurasi</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Review Import -->
<div class="modal fade" id="modalReviewImport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-exclamation-triangle me-2"></i>Review Validasi Import
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fas fa-info-circle me-1"></i> Terdapat beberapa ketidaksesuaian data antara Excel dan Database. Silakan perbaiki sebelum melanjutkan.
                </div>
                
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="tableReviewImport">
                        <thead class="table-light">
                            <tr>
                                <th>Item (Berdasarkan Excel)</th>
                                <th width="100" class="text-center">UoM</th>
                                <th width="350">Koreksi & Tindakan</th>
                                <th width="120" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="reviewImportBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <div class="me-auto small text-muted" id="reviewStats"></div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success fw-bold" id="btnFinalizeImport">
                    <i class="fas fa-check-circle me-1"></i> Konfirmasi & Import Data
                </button>
            </div>
        </div>
    </div>
</div>



<div class="modal fade" id="modalDetailGrup" tabindex="-1">
    <div class="modal-dialog modal-xl">
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
                                        <th width="100">ID Biosys</th>
                                        <th>Layanan</th>
                                        <th>Item</th>
                                        <th width="80" class="text-center">Tipe</th>
                                        <th width="60" class="text-center">Qty</th>
                                        <?php if ($can_edit): ?>
                                        <th width="44"></th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="detailItemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="fw-bold mb-2 text-primary"><i class="fas fa-cog me-1"></i> Pengaturan</div>
                            <label class="form-label mb-1 small fw-bold">ID Paket</label>
                            <input type="text" class="form-control mb-3" id="detailGrupId" readonly>
                            
                            <label class="form-label mb-1 small fw-bold">Nama Paket</label>
                            <div class="input-group mb-4">
                                <input type="text" class="form-control" id="detailGrupNama" value="" <?= $can_edit ? '' : 'readonly' ?>>
                                <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-primary-custom" id="btnSaveNama">
                                    <i class="fas fa-save"></i>
                                </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($can_edit): ?>
                            <div class="fw-bold mb-2 text-success"><i class="fas fa-plus-circle me-1"></i> Tambah Item</div>
                            <form class="row g-2" id="formAddMapping">
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-bold">ID Biosys</label>
                                    <input type="text" name="id_biosys" class="form-control form-control-sm" placeholder="Contoh: 191001" id="detailIdBiosys">
                                </div>
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-bold">Layanan</label>
                                    <input type="text" name="layanan" class="form-control form-control-sm" placeholder="Contoh: Hematologi" id="detailLayanan">
                                </div>
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-bold">Pilih Barang</label>
                                    <select name="barang_id" class="form-select form-select-sm select2" required id="detailBarangId">
                                        <option value="">- Pilih Barang -</option>
                                        <?php foreach($barang_opts as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['kode_barang']) ?> - <?= htmlspecialchars($b['nama_barang']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-bold">Qty</label>
                                    <input type="number" name="qty" class="form-control form-control-sm" placeholder="Qty" required min="1" value="1" id="detailQty">
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-sm btn-success w-100 fw-bold"><i class="fas fa-plus me-1"></i> Tambah ke Mapping</button>
                                </div>
                            </form>
                            <?php else: ?>
                            <div class="alert alert-info small py-2">
                                <i class="fas fa-info-circle me-1"></i> Anda dalam mode <strong>View Only</strong>. Hubungi tim Super Admin untuk perubahan data paket pemeriksaan.
                            </div>
                            <?php endif; ?>
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
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <input type="hidden" name="id" id="grupId" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="grupModalTitle">Buat Pemeriksaan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">ID Paket</label>
                        <input type="text" name="id_paket" class="form-control" required id="grupIdPaket" placeholder="Contoh: PKT001">
                        <div class="form-text small">ID Paket bersifat unik dan wajib diinput manual.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Nama Paket</label>
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
                                        <th width="150">ID Biosys</th>
                                        <th>Layanan</th>
                                        <th>Item Barang</th>
                                        <th width="120" class="text-center">Jumlah (Qty)</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="newMappingBody">
                                    <tr>
                                        <td>
                                            <input type="text" name="id_biosys_list[]" class="form-control form-control-sm" placeholder="ID Biosys">
                                        </td>
                                        <td>
                                            <input type="text" name="layanan_list[]" class="form-control form-control-sm" placeholder="Nama Layanan">
                                        </td>
                                        <td>
                                            <select name="barang_ids[]" class="form-select form-select-sm select2-modal barang-select-row" required>
                                                <option value="">- Pilih Barang -</option>
                                                <?php foreach($barang_opts as $b): ?>
                                                    <option value="<?= (int)$b['id'] ?>" data-tipe="<?= htmlspecialchars((string)($b['tipe'] ?? '')) ?>"><?= htmlspecialchars($b['kode_barang']) ?> - <?= htmlspecialchars($b['nama_barang']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
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
const CAN_EDIT = <?= json_encode($can_edit) ?>;
function getRowByGrupId(grupId) {
    return $('#examTable tbody tr[data-grup-id="' + grupId + '"]');
}

function setRowTotalItems(grupId, total) {
    const row = getRowByGrupId(grupId);
    row.find('[data-role="total-items"]').text(total);
}

function loadDetail(grupId) {
    $('#detailItemsBody').html('<tr><td colspan="6" class="text-center text-muted py-3">Memuat...</td></tr>');
    $.ajax({
        url: 'api/ajax_pemeriksaan_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { grup_id: grupId },
        success: function(res) {
            if (!res || !res.success) {
                $('#detailItemsBody').html('<tr><td colspan="6" class="text-center text-danger py-3">' + (res && res.message ? res.message : 'Gagal memuat') + '</td></tr>');
                return;
            }
            $('#detailGrupId').val(res.grup.id);
            $('#detailGrupNama').val(res.grup.nama_pemeriksaan);
            $('#detailTitle').text('Edit: [' + res.grup.id + '] ' + res.grup.nama_pemeriksaan);
            const rows = [];
            if (Array.isArray(res.details) && res.details.length > 0) {
                res.details.forEach(function(d) {
                    const code = (d.kode_barang ? d.kode_barang : (d.odoo_product_id ? d.odoo_product_id : '-'));
                    const itemText = code + ' - ' + (d.nama_barang ? d.nama_barang : '') + (d.satuan ? ' (' + d.satuan + ')' : '');
                    const badge = d.tipe === 'Core' ? 
                        '<span class="badge bg-danger px-2">Core</span>' : 
                        '<span class="badge bg-info px-2">Support</span>';
                    const deleteBtn = CAN_EDIT ? 
                        '<td class="text-center">' +
                            '<button type="button" class="btn btn-sm btn-outline-danger btnDeleteDetail" data-detail-id="' + d.id + '"><i class="fas fa-trash"></i></button>' +
                        '</td>' : '';
                    rows.push(
                        '<tr>' +
                            '<td class="text-muted small">' + (d.id_biosys || '-') + '</td>' +
                            '<td class="small">' + (d.nama_layanan || '-') + '</td>' +
                            '<td class="fw-semibold">' + $('<div>').text(itemText).html() + '</td>' +
                            '<td class="text-center">' + badge + '</td>' +
                            '<td class="text-center fw-semibold">' + d.qty_per_pemeriksaan + '</td>' +
                            deleteBtn +
                        '</tr>'
                    );
                });
            } else {
                rows.push('<tr><td colspan="6" class="text-center text-muted py-3">Belum ada mapping item.</td></tr>');
            }
            $('#detailItemsBody').html(rows.join(''));
            setRowTotalItems(res.grup.id, res.total_items);
        },
        error: function() {
            $('#detailItemsBody').html('<tr><td colspan="6" class="text-center text-danger py-3">Gagal memuat</td></tr>');
        }
    });
}

function loadView(grupId, container) {
    const contentBox = container.find('.detail-content');
    const spinner = container.find('.loading-spinner');

    if (contentBox.html().trim() !== '') return; // Already loaded

    spinner.removeClass('d-none');
    $.ajax({
        url: 'api/ajax_pemeriksaan_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { grup_id: grupId },
        success: function(res) {
            spinner.addClass('d-none');
            if (!res || !res.success) {
                contentBox.html('<div class="text-danger small p-2">Gagal memuat detail: ' + (res && res.message ? res.message : '') + '</div>');
                return;
            }
            
            let html = '<div class="p-3 bg-light border-start border-primary border-4">' +
                       '<table class="table table-sm table-bordered table-detail mb-0">' +
                       '<thead><tr>' +
                       '<th width="100">ID Biosys</th>' +
                       '<th>Layanan</th>' +
                       '<th width="120">Kode Barang</th>' +
                       '<th>Consumables</th>' +
                       '<th width="80" class="text-center">Qty</th>' +
                       '<th width="80" class="text-center">UoM</th>' +
                       '</tr></thead><tbody>';

            if (Array.isArray(res.details) && res.details.length > 0) {
                res.details.forEach(function(d) {
                    const code = (d.kode_barang ? d.kode_barang : (d.odoo_product_id ? d.odoo_product_id : '-'));
                    html += '<tr>' +
                            '<td class="text-muted">' + (d.id_biosys || '-') + '</td>' +
                            '<td>' + (d.nama_layanan || '-') + '</td>' +
                            '<td class="fw-bold text-primary">' + code + '</td>' +
                            '<td class="fw-semibold">' + d.nama_barang + '</td>' +
                            '<td class="text-center">' + d.qty_per_pemeriksaan + '</td>' +
                            '<td class="text-center">' + (d.satuan || '-') + '</td>' +
                            '</tr>';
                });
            } else {
                html += '<tr><td colspan="6" class="text-center text-muted py-2">Belum ada mapping item.</td></tr>';
            }
            html += '</tbody></table></div>';
            contentBox.html(html);
        },
        error: function() {
            spinner.addClass('d-none');
            contentBox.html('<div class="text-danger small p-2">Terjadi kesalahan saat memuat data.</div>');
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

    const table = $('#examTable').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "api/ajax_pemeriksaan_list.php",
            "type": "POST"
        },
        "order": [[ 5, "desc" ]],
        "pageLength": 10,
        "lengthChange": false,
        "columns": [
            { 
                "data": null, 
                "orderable": false,
                "render": function() {
                    return '<div class="btn-toggle-detail" title="Lihat Detail"><i class="fas fa-caret-right"></i></div>';
                }
            },
            { 
                "data": "id",
                "render": function(data) {
                    return '<span class="fw-bold text-primary">' + data + '</span>';
                }
            },
            { 
                "data": "nama_pemeriksaan",
                "render": function(data) {
                    return '<span class="fw-semibold">' + data + '</span>';
                }
            },
            { 
                "data": "total_items",
                "className": "text-center",
                "render": function(data) {
                    return '<span class="badge bg-light text-dark border" data-role="total-items">' + data + '</span>';
                }
            },
            { 
                "data": null,
                "visible": CAN_EDIT,
                "orderable": false,
                "render": function(data, type, row) {
                    return '<div class="btn-group" role="group">' +
                                '<button type="button" class="btn btn-sm btn-outline-warning btnEdit" title="Edit">' +
                                    '<i class="fas fa-edit"></i>' +
                                '</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-danger btnDelete" title="Hapus">' +
                                    '<i class="fas fa-trash"></i>' +
                                '</button>' +
                            '</div>';
                }
            },
            { "data": "created_at", "visible": false }
        ],
        "createdRow": function(row, data, dataIndex) {
            $(row).addClass('main-row');
            $(row).attr('data-grup-id', data.id);
            $(row).attr('data-grup-nama', data.nama_pemeriksaan);
        },
        "dom": "<'row mb-2'<'col-sm-12 col-md-6 d-flex align-items-center'><'col-sm-12 col-md-6 d-flex align-items-center justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        "language": {
            "searchPlaceholder": "Cari...",
            "processing": '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Memuat data...'
        }
    });

    // Store clean options HTML for dynamic rows
    const BARANG_OPTIONS_HTML = <?= json_encode(array_reduce($barang_opts, function($carry, $item) {
        return $carry . '<option value="'.(int)$item['id'].'" data-tipe="'.htmlspecialchars((string)($item['tipe'] ?? '')).'" data-uom="'.htmlspecialchars((string)($item['satuan'] ?? '')).'">'.htmlspecialchars($item['kode_barang']).' - '.htmlspecialchars($item['nama_barang']).'</option>';
    }, '')) ?>;

    $('#btnNewExam').on('click', function() {
        $('#grupModalTitle').text('Buat Pemeriksaan Baru');
        $('#grupId').val('');
        $('#grupIdPaket').val('');
        $('#grupNama').val('');
        $('#newMappingBody').html('<tr>' +
            '<td><input type="text" name="id_biosys_list[]" class="form-control form-control-sm" placeholder="ID Biosys"></td>' +
            '<td><input type="text" name="layanan_list[]" class="form-control form-control-sm" placeholder="Nama Layanan"></td>' +
            '<td><select name="barang_ids[]" class="form-select form-select-sm select2-modal barang-select-row" required><option value="">- Pilih Barang -</option>' + 
            BARANG_OPTIONS_HTML + 
            '</select></td>' +
            '<td><input type="number" name="qtys[]" class="form-control form-control-sm text-center" value="1" min="1" required></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnRemoveRow"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
        initSelect2Modal();
        new bootstrap.Modal(document.getElementById('modalGrup')).show();
    });

    $('#btnAddRow').off('click').on('click', function() {
        const newRow = $('<tr>' +
            '<td><input type="text" name="id_biosys_list[]" class="form-control form-control-sm" placeholder="ID Biosys"></td>' +
            '<td><input type="text" name="layanan_list[]" class="form-control form-control-sm" placeholder="Nama Layanan"></td>' +
            '<td><select name="barang_ids[]" class="form-select form-select-sm select2-modal barang-select-row" required>' + 
            '<option value="">- Pilih Barang -</option>' + 
            BARANG_OPTIONS_HTML + 
            '</select></td>' +
            '<td><input type="number" name="qtys[]" class="form-control form-control-sm text-center" value="1" min="1" required></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnRemoveRow"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
        
        $('#newMappingBody').append(newRow);
        
        // Initialize Select2 only for the new row's select
        newRow.find('.select2-modal').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#modalGrup')
        });
    });

    $(document).on('change', '.barang-select-row', function() {
        const tr = $(this).closest('tr');
        const data = tr.find('.barang-select-row').select2('data')[0];
        const tipe = data && data.element ? $(data.element).data('tipe') : '';
        
        if (tipe === 'Core') {
            updateRowTipe(tr, 1);
        } else if (tipe === 'Support') {
            updateRowTipe(tr, 0);
        }
    });

    function updateRowTipe(tr, val) {
        tr.find('.hid-mandatory').val(val);
        tr.find('.btn-seg-row').removeClass('active-mandatory');
        tr.find('.btn-seg-row[data-val="' + val + '"]').addClass('active-mandatory');
    }

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

    // GSheet Configuration
    let GS_HEADERS = [];
    const GS_CONFIG = <?= json_encode([
        'url' => get_setting('gsheet_exam_url'),
        'sheet' => get_setting('gsheet_exam_sheet'),
        'mapping' => json_decode(get_setting('gsheet_exam_mapping', '{}'), true)
    ]) ?>;

    $('#btnConfigGSheet').on('click', function() {
        const m = new bootstrap.Modal(document.getElementById('modalConfigGSheet'));
        m.show();
        
        // Reset view to show saved config immediately
        if (GS_CONFIG.url && GS_CONFIG.sheet) {
            $('#sectionSheetMapping').show();
            $('#btnSyncNow').show();
            
            // Populate Sheet Name dropdown with the saved value
            $('#gsheetName').html(`<option value="${GS_CONFIG.sheet}" selected>${GS_CONFIG.sheet}</option>`);
            
            // Populate mapping dropdowns with saved values
            $('.map-col').each(function() {
                const key = $(this).data('key');
                const savedVal = (GS_CONFIG.mapping && GS_CONFIG.mapping[key]) ? GS_CONFIG.mapping[key] : '';
                if (savedVal) {
                    $(this).html(`<option value="${savedVal}" selected>${savedVal}</option>`);
                } else {
                    $(this).html('<option value="">- Pilih Kolom -</option>');
                }
            });
        }
    });

    $('#btnCheckGSheet').on('click', function() {
        const url = $('#gsheetUrl').val();
        if (!url) return alert('Input URL terlebih dahulu');

        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.getJSON('api/ajax_gsheet_proxy.php', { url: url, action: 'get_sheets' }, function(res) {
            btn.prop('disabled', false).text('Cek Sheet');
            if (res.success) {
                let opts = '<option value="">- Pilih Sheet -</option>';
                res.sheets.forEach(s => {
                    const selected = s === GS_CONFIG.sheet ? 'selected' : '';
                    opts += `<option value="${s}" ${selected}>${s}</option>`;
                });
                $('#gsheetName').html(opts);
                $('#sectionSheetMapping').fadeIn();
                if (GS_CONFIG.sheet) $('#gsheetName').trigger('change');
            } else {
                alert(res.message || 'Gagal mengambil daftar sheet');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Cek Sheet');
            alert('Gagal menghubungi server proxy atau Apps Script. Pastikan URL benar dan sudah dideploy sebagai Public (Anyone).');
        });
    });

    $('#gsheetName').on('change', function() {
        const sheet = $(this).val();
        const url = $('#gsheetUrl').val();
        if (!sheet) return;

        $.getJSON('api/ajax_gsheet_proxy.php', { url: url, action: 'get_headers', sheet: sheet }, function(res) {
            if (res.success) {
                GS_HEADERS = res.headers;
                let opts = '<option value="">- Pilih Kolom -</option>';
                GS_HEADERS.forEach(h => {
                    opts += `<option value="${h}">${h}</option>`;
                });
                $('.map-col').each(function() {
                    const key = $(this).data('key');
                    $(this).html(opts);
                    if (GS_CONFIG.mapping && GS_CONFIG.mapping[key]) {
                        $(this).val(GS_CONFIG.mapping[key]);
                    }
                });
                $('#btnSyncNow').show();
            } else {
                alert(res.message || 'Gagal mengambil header kolom');
            }
        });
    });

    $('#btnSaveGSheetConfig').on('click', function() {
        const config = {
            url: $('#gsheetUrl').val(),
            sheet: $('#gsheetName').val(),
            mapping: {}
        };
        $('.map-col').each(function() {
            config.mapping[$(this).data('key')] = $(this).val();
        });

        const btn = $(this);
        btn.prop('disabled', true).text('Menyimpan...');

        $.post('api/ajax_pemeriksaan_save_gs_config.php', {
            config: JSON.stringify(config),
            _csrf: PEMERIKSAAN_CSRF
        }, function(res) {
            btn.prop('disabled', false).text('Simpan Konfigurasi');
            if (res.success) {
                alert('Konfigurasi berhasil disimpan');
                location.reload();
            } else {
                alert(res.message || 'Gagal menyimpan konfigurasi');
            }
        }, 'json');
    });

    $('#btnSyncNow').on('click', function() {
        if (!confirm('Mulai sinkronisasi sekarang?')) return;
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing...');

        $.post('api/ajax_pemeriksaan_sync_gsheet.php', { _csrf: PEMERIKSAAN_CSRF }, function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-sync me-1"></i> Sync Sekarang');
            if (res.success) {
                alert(res.message);
                location.reload();
            } else {
                alert(res.message || 'Gagal sinkronisasi');
            }
        }, 'json');
    });

    $('#examTable').on('click', '.btnEdit', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        loadDetail(id);
        const m = new bootstrap.Modal(document.getElementById('modalDetailGrup'));
        m.show();
    });

    $('#examTable').on('click', '.main-row', function(e) {
        // Don't expand if clicking on action buttons
        if ($(e.target).closest('.btn-group').length) return;
        
        const tr = $(this);
        const row = table.row(tr);
        const icon = tr.find('.btn-toggle-detail i');
        const id = tr.data('grup-id');

        if (row.child.isShown()) {
            row.child.hide();
            icon.removeClass('fa-caret-down').addClass('fa-caret-right');
        } else {
            // Create container for detail
            const containerHtml = '<div class="detail-wrapper-' + id + '">' +
                                  '<div class="text-center py-2 loading-spinner">' +
                                  '<i class="fas fa-spinner fa-spin text-primary"></i> Memuat detail...' +
                                  '</div><div class="detail-content"></div></div>';
            row.child(containerHtml).show();
            loadView(id, $('.detail-wrapper-' + id));
            icon.removeClass('fa-caret-right').addClass('fa-caret-down');
        }
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
            data: $(this).serialize(),
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
                    existing.find('td').eq(2).text(nama); // Index 2 is Nama Paket
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

    let IMPORT_VALID_DATA = [];
    let IMPORT_INVALID_SUMMARY = [];
    let IMPORT_ALL_INVALID_ROWS = [];
    let IMPORT_DELETE_ALL = false;
    let IMPORT_TOTAL_EXCEL_ROWS = 0;

    $('#formImport').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('_csrf', PEMERIKSAAN_CSRF);
        
        const btn = $('#btnDoImport');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Memvalidasi...');
        
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
                    alert(res && res.message ? res.message : 'Gagal memproses file');
                    return;
                }
                
                IMPORT_VALID_DATA = res.valid_data || [];
                IMPORT_INVALID_SUMMARY = res.invalid_summary || [];
                IMPORT_ALL_INVALID_ROWS = res.all_invalid_rows || [];
                IMPORT_DELETE_ALL = res.delete_all;
                IMPORT_TOTAL_EXCEL_ROWS = res.total_excel_rows || 0;

                if (res.needs_review) {
                    $('#modalImport').modal('hide');
                    renderReviewImport();
                    new bootstrap.Modal(document.getElementById('modalReviewImport')).show();
                } else {
                    finalizeImport(IMPORT_VALID_DATA);
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
                alert('Terjadi kesalahan saat mengimport.');
            }
        });
    });

    function renderReviewImport() {
        const body = $('#reviewImportBody');
        body.empty();
        
        const activeSummary = IMPORT_INVALID_SUMMARY.filter(s => s !== null);
        if (activeSummary.length === 0) {
            body.append('<tr><td colspan="6" class="text-center py-4 text-success"><i class="fas fa-check-circle me-1"></i> Semua isu sudah diperbaiki. Silakan klik "Konfirmasi & Import".</td></tr>');
            $('#reviewStats').text(`${IMPORT_VALID_DATA.length} baris siap import.`);
            return;
        }

        IMPORT_INVALID_SUMMARY.forEach((summary, index) => {
            if (summary === null) return;

            let correctionHtml = '';
            const key = summary.error_type + '|' + summary.barang_id + (summary.uom_excel ? '|' + summary.uom_excel.toLowerCase() : '');

            if (summary.error_type === 'item_not_found') {
                correctionHtml = `
                    <div class="input-group input-group-sm">
                        <select class="form-select select2-review replace-item" data-index="${index}" data-key="${key}">
                            <option value="">- Cari Item Pengganti -</option>
                            ${BARANG_OPTIONS_HTML}
                        </select>
                    </div>
                    <div class="extra-small text-muted mt-1">Ditemukan pada <strong>${summary.count}</strong> baris mapping</div>`;
            } else if (summary.error_type === 'uom_mismatch') {
                correctionHtml = `
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-light text-dark border">Excel: ${summary.uom_excel}</span>
                        <i class="fas fa-arrow-right text-muted small"></i>
                        <span class="badge bg-success">Sistem: ${summary.system_uom}</span>
                    </div>
                    <button type="button" class="btn btn-xs btn-primary btnFixUom" data-index="${index}" data-key="${key}">
                        <i class="fas fa-magic me-1"></i>Gunakan UoM Sistem untuk ${summary.count} baris
                    </button>`;
            }

            body.append(`
                <tr data-index="${index}">
                    <td>
                        <div class="fw-bold small">${summary.consumables} (ID: ${summary.barang_id})</div>
                        <div class="text-danger extra-small"><i class="fas fa-times-circle me-1"></i>${summary.error}</div>
                    </td>
                    <td class="text-center small">${summary.uom_excel || '-'}</td>
                    <td>${correctionHtml}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger btnIgnoreSummary" data-index="${index}" data-key="${key}" title="Abaikan semua">
                            <i class="fas fa-trash-alt me-1"></i>Abaikan
                        </button>
                    </td>
                </tr>
            `);
        });

        $('#reviewStats').text(`${activeSummary.length} kelompok isu ditemukan, total ${IMPORT_VALID_DATA.length} baris tervalidasi.`);
        
        $('.select2-review').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $('#modalReviewImport')
                });
            }
        });
    }

    function applyBulkFix(summaryKey, newBarangId) {
        // Move all rows with this key from ALL_INVALID to VALID
        const rowsToMove = IMPORT_ALL_INVALID_ROWS.filter(r => r && r.summary_key === summaryKey);
        
        rowsToMove.forEach(row => {
            if (newBarangId) row.barang_id = newBarangId;
            IMPORT_VALID_DATA.push(row);
        });

        // Mark these rows as processed in the raw list
        IMPORT_ALL_INVALID_ROWS = IMPORT_ALL_INVALID_ROWS.map(r => r && r.summary_key === summaryKey ? null : r);
    }

    $(document).on('change', '.replace-item', function() {
        const tr = $(this).closest('tr');
        const index = $(this).data('index');
        const summaryKey = $(this).data('key');
        const selectedId = $(this).val();
        if (!selectedId) return;

        applyBulkFix(summaryKey, selectedId);
        IMPORT_INVALID_SUMMARY[index] = null;
        
        tr.fadeOut(300, function() {
            tr.remove();
            const activeSummaryCount = IMPORT_INVALID_SUMMARY.filter(s => s !== null).length;
            if (activeSummaryCount === 0) {
                renderReviewImport();
            } else {
                $('#reviewStats').text(`${activeSummaryCount} kelompok isu ditemukan, total ${IMPORT_VALID_DATA.length} baris tervalidasi.`);
            }
        });
    });

    $(document).on('click', '.btnFixUom', function() {
        const tr = $(this).closest('tr');
        const index = $(this).data('index');
        const summaryKey = $(this).data('key');

        applyBulkFix(summaryKey, null); // Keep existing ID, just move to valid
        IMPORT_INVALID_SUMMARY[index] = null;
        
        tr.fadeOut(300, function() {
            tr.remove();
            const activeSummaryCount = IMPORT_INVALID_SUMMARY.filter(s => s !== null).length;
            if (activeSummaryCount === 0) {
                renderReviewImport();
            } else {
                $('#reviewStats').text(`${activeSummaryCount} kelompok isu ditemukan, total ${IMPORT_VALID_DATA.length} baris tervalidasi.`);
            }
        });
    });

    $(document).on('click', '.btnIgnoreSummary', function() {
        const tr = $(this).closest('tr');
        const index = $(this).data('index');
        const summaryKey = $(this).data('key');

        // Remove from raw invalid rows
        IMPORT_ALL_INVALID_ROWS = IMPORT_ALL_INVALID_ROWS.map(r => r && r.summary_key === summaryKey ? null : r);
        IMPORT_INVALID_SUMMARY[index] = null;
        
        tr.fadeOut(300, function() {
            tr.remove();
            const activeSummaryCount = IMPORT_INVALID_SUMMARY.filter(s => s !== null).length;
            if (activeSummaryCount === 0) {
                renderReviewImport();
            } else {
                $('#reviewStats').text(`${activeSummaryCount} kelompok isu ditemukan, total ${IMPORT_VALID_DATA.length} baris tervalidasi.`);
            }
        });
    });

    $('#btnFinalizeImport').on('click', function() {
        const finalMappings = IMPORT_VALID_DATA;
        if (finalMappings.length === 0) {
            alert('Tidak ada data valid untuk diimport.');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Menyimpan...');

        $.ajax({
            url: 'api/ajax_pemeriksaan_import_finalize.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                mappings: finalMappings,
                delete_all: IMPORT_DELETE_ALL,
                total_excel_rows: IMPORT_TOTAL_EXCEL_ROWS,
                _csrf: PEMERIKSAAN_CSRF
            }),
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan data');
                    btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Konfirmasi & Import Data');
                    return;
                }
                
                if (confirm(res.message + '\n\nApakah Anda ingin mendownload laporan baris yang dilewati?')) {
                    window.location.href = 'api/download_ignored_report.php';
                    setTimeout(() => { location.reload(); }, 2000);
                } else {
                    location.reload();
                }
            },
            error: function() {
                alert('Terjadi kesalahan saat menyimpan data.');
                btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Konfirmasi & Import Data');
            }
        });
    });

    function finalizeImport(data) {
        IMPORT_VALID_DATA = data;
        $('#btnFinalizeImport').trigger('click');
    }

    $('#formAddMapping').on('submit', function(e) {
        e.preventDefault();
        const grupId = $('#detailGrupId').val();
        const barangId = $('#detailBarangId').val();
        const qty = $('#detailQty').val();
        const idBiosys = $('#detailIdBiosys').val();
        const layanan = $('#detailLayanan').val();

        if (!grupId || !barangId || !qty) return;

        $.ajax({
            url: 'api/ajax_pemeriksaan_detail_save.php',
            method: 'POST',
            dataType: 'json',
            data: { 
                grup_id: grupId, 
                barang_id: barangId, 
                qty: qty,
                id_biosys: idBiosys,
                layanan: layanan,
                _csrf: PEMERIKSAAN_CSRF 
            },
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
                row.find('td').eq(2).text(nm); // Index 2 is Nama Paket
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
