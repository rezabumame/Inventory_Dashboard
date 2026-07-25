<?php
check_role(['super_admin', 'admin_gudang']);

// ── DUMMY DATA ────────────────────────────────────────────────────────────────
$dummy_items = [
    // id  kode   nama                                        satuan  tipe       kategori    min    uom_odoo  ratio  track_ed  barcode_internal  vendor_barcodes                                                                              label_print   label_placement  label_config_set
    ['id'=>1,  'kode_barang'=>'15',  'nama_barang'=>'Alcohol Swab 70% (new)',       'satuan'=>'Pcs',   'tipe'=>'Support', 'kategori'=>'BHP',       'stok_minimum'=>500, 'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>false, 'barcode_internal'=>'BIS-0015', 'vendor_barcodes'=>[['id'=>1,'barcode'=>'8991234567890','ket'=>'Vendor A']],                    'label_print'=>'physical', 'label_placement'=>'unit',      'label_config_set'=>true],
    ['id'=>2,  'kode_barang'=>'532', 'nama_barang'=>'NaCl 0.9% 25ml',              'satuan'=>'Botol', 'tipe'=>'Support', 'kategori'=>'BHP',       'stok_minimum'=>100, 'uom_odoo'=>'mL',    'uom_ratio'=>25,   'track_ed'=>true,  'barcode_internal'=>null,        'vendor_barcodes'=>[],                                                                       'label_print'=>'none',     'label_placement'=>null,        'label_config_set'=>false],
    ['id'=>3,  'kode_barang'=>'10',  'nama_barang'=>'Vaccuttainer Needle 22',       'satuan'=>'Pcs',   'tipe'=>'Core',    'kategori'=>'BHP',       'stok_minimum'=>200, 'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>false, 'barcode_internal'=>null,        'vendor_barcodes'=>[['id'=>2,'barcode'=>'4009803001636','ket'=>'Vendor B']],                   'label_print'=>'physical', 'label_placement'=>'box',       'label_config_set'=>true],
    ['id'=>4,  'kode_barang'=>'506', 'nama_barang'=>'Plester Medis',                'satuan'=>'Pcs',   'tipe'=>'Support', 'kategori'=>'BHP',       'stok_minimum'=>200, 'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>false, 'barcode_internal'=>'BIS-0506', 'vendor_barcodes'=>[['id'=>3,'barcode'=>'8993370005012','ket'=>'Vendor A'],['id'=>4,'barcode'=>'4891010002345','ket'=>'Vendor C']], 'label_print'=>'none', 'label_placement'=>null, 'label_config_set'=>true],
    ['id'=>5,  'kode_barang'=>'401', 'nama_barang'=>'Vaksin HPV Gardasil 9-strain', 'satuan'=>'Vial',  'tipe'=>'Core',    'kategori'=>'Vaksin',    'stok_minimum'=>50,  'uom_odoo'=>'Doses', 'uom_ratio'=>4,    'track_ed'=>true,  'barcode_internal'=>null,        'vendor_barcodes'=>[],                                                                       'label_print'=>'physical', 'label_placement'=>'outer',     'label_config_set'=>true],
    ['id'=>6,  'kode_barang'=>'92',  'nama_barang'=>'Cell Free Tube DNA - CoWin',   'satuan'=>'Tube',  'tipe'=>'Core',    'kategori'=>'BHP',       'stok_minimum'=>100, 'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>true,  'barcode_internal'=>null,        'vendor_barcodes'=>[['id'=>5,'barcode'=>'5060225512022','ket'=>'CoWin Official']],              'label_print'=>'physical', 'label_placement'=>'unit',      'label_config_set'=>true],
    ['id'=>7,  'kode_barang'=>'7',   'nama_barang'=>'Spuit 10cc',                   'satuan'=>'Pcs',   'tipe'=>'Support', 'kategori'=>'BHP',       'stok_minimum'=>300, 'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>false, 'barcode_internal'=>'BIS-0007', 'vendor_barcodes'=>[],                                                                       'label_print'=>'physical', 'label_placement'=>'catalogue', 'label_config_set'=>true],
    ['id'=>8,  'kode_barang'=>'356', 'nama_barang'=>'Plastik Ziplock Besar',        'satuan'=>'Pcs',   'tipe'=>'Support', 'kategori'=>'Non-Klinis','stok_minimum'=>0,   'uom_odoo'=>'Units', 'uom_ratio'=>1,    'track_ed'=>false, 'barcode_internal'=>null,        'vendor_barcodes'=>[],                                                                       'label_print'=>'none',     'label_placement'=>null,        'label_config_set'=>false],
];

$total           = count($dummy_items);
$with_internal   = count(array_filter($dummy_items, fn($i) => !empty($i['barcode_internal'])));
$with_vendor     = count(array_filter($dummy_items, fn($i) => !empty($i['vendor_barcodes'])));
$no_barcode      = count(array_filter($dummy_items, fn($i) => empty($i['barcode_internal']) && empty($i['vendor_barcodes'])));
$with_min        = count(array_filter($dummy_items, fn($i) => ($i['stok_minimum'] ?? 0) > 0));
$label_set       = count(array_filter($dummy_items, fn($i) => ($i['label_config_set'] ?? false)));

// ── DUMMY NON-ODOO ────────────────────────────────────────────────────────────
$dummy_lokal = [
    ['id'=>101, 'kode_barang'=>'LOCAL-001', 'nama_barang'=>'Buku Kuning Anamnesa',     'satuan'=>'Pcs',  'track_ed'=>false, 'barcode_internal'=>'BIS-L001', 'vendor_barcodes'=>[], 'label_print'=>'physical', 'label_placement'=>'unit',    'label_config_set'=>true],
    ['id'=>102, 'kode_barang'=>'LOCAL-002', 'nama_barang'=>'Formulir Lab Internal',    'satuan'=>'Lembar','track_ed'=>false, 'barcode_internal'=>null,       'vendor_barcodes'=>[], 'label_print'=>'none',     'label_placement'=>null,      'label_config_set'=>false],
    ['id'=>103, 'kode_barang'=>'LOCAL-003', 'nama_barang'=>'Stiker Identitas Pasien',  'satuan'=>'Roll', 'track_ed'=>false, 'barcode_internal'=>null,       'vendor_barcodes'=>[], 'label_print'=>'physical', 'label_placement'=>'catalogue','label_config_set'=>true],
];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

/* ── Base ─────────────────────────────────────────────────────────────────── */
.master-wrap { font-family: 'Inter', sans-serif; }

/* ── Stat Cards ───────────────────────────────────────────────────────────── */
.stat-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 2px 16px rgba(0,0,0,.07);
    transition: transform .2s, box-shadow .2s;
    cursor: default;
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 28px rgba(32,78,171,.15);
}
.stat-icon-circle {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.stat-number {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    font-family: 'Inter', sans-serif;
}
.stat-label {
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
    color: #94a3b8;
    margin-top: 4px;
}

/* ── Item Card List ───────────────────────────────────────────────────────── */
.item-card-row {
    background: #fff;
    border: 1px solid #f1f5f9;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: box-shadow .18s, border-color .18s, transform .15s;
    flex-wrap: wrap;
}
.item-card-row:hover {
    border-color: #d1d9ef;
    box-shadow: 0 4px 18px rgba(32,78,171,.09);
    transform: translateY(-1px);
}
.item-kode-pill {
    display: inline-block;
    background: #e8f0fd;
    color: #204EAB;
    font-family: monospace;
    font-size: .78rem;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 12px;
    white-space: nowrap;
}
.item-name {
    font-weight: 700;
    color: #1e293b;
    font-size: .95rem;
    line-height: 1.3;
}
.item-satuan {
    font-size: .78rem;
    color: #94a3b8;
    margin-top: 2px;
}
.item-info-col {
    flex: 1;
    min-width: 180px;
}
.item-badges-col {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* status chips */
.chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 600;
    white-space: nowrap;
}
.chip-qr-active   { background: #e8f0fd; color: #204EAB; border: 1px solid #c7d7f8; }
.chip-qr-empty    { background: #f8faff; color: #94a3b8; border: 1px dashed #d1d5db; }
.chip-ed-yes      { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.chip-ed-no       { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
.chip-vendor      { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.chip-vendor-none { background: #f8f9fa; color: #adb5bd; border: 1px dashed #dee2e6; }
.chip-label-warn     { background: #fff3cd; color: #92400e; border: 1px solid #fde68a; }
.chip-label-none     { background: #f8f9fa; color: #adb5bd; border: 1px dashed #dee2e6; }
.chip-label-physical { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

/* ── Filter Chips ─────────────────────────────────────────────────────────── */
.filter-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px; font-size: .78rem; font-weight: 600;
    border: 1.5px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer;
    transition: all .15s;
}
.filter-chip:hover    { border-color: #204EAB; color: #204EAB; background: #eff6ff; }
.filter-chip.active   { border-color: #204EAB; background: #204EAB; color: #fff; }
.filter-chip .badge   { font-size: .68rem; font-weight: 700; border-radius: 10px; padding: 1px 6px; }
.filter-chip.active .badge { background: rgba(255,255,255,.25); color: #fff; }
.filter-chip:not(.active) .badge { background: #e2e8f0; color: #64748b; }

/* ── Modal section separator ──────────────────────────────────────────────── */
.modal-section-hr {
    border: 0;
    border-top: 1.5px solid #d0d7de;
    margin: 20px 0;
}

/* ── Nav Tabs ─────────────────────────────────────────────────────────────── */
#barangTabs .nav-link          { color: #6b7280; font-weight: 600; border-radius: 20px; padding: 8px 20px; }
#barangTabs .nav-link:hover    { background: #eff6ff; color: #204EAB; }
#barangTabs .nav-link.active   { background: #204EAB !important; color: #fff !important; }
#barangTabs .nav-link .badge   { font-size: .7rem; }
#barangTabs .nav-link.active .badge { background: rgba(255,255,255,.9) !important; color: #204EAB !important; }

/* ── Label Config Modal ───────────────────────────────────────────────────── */
.label-opt-card {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    margin-bottom: 8px;
    user-select: none;
}
.label-opt-card:hover { border-color: #a5b4fc; }
.label-opt-card.selected { border-color: #204EAB; background: #f0f4ff; }
.placement-opt {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    border: 2px solid #e2e8f0;
    background: #fff;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    color: #374151;
}
.placement-opt:hover { border-color: #204EAB; }
.placement-opt.selected { border-color: #204EAB; background: #e8f0fd; color: #204EAB; }

/* search input */
.search-input {
    border-radius: 10px !important;
    border: 2px solid #e2e8f0 !important;
    padding: 10px 16px !important;
    font-size: .88rem;
    transition: border-color .2s, box-shadow .2s;
}
.search-input:focus {
    border-color: #204EAB !important;
    box-shadow: 0 0 0 3px rgba(32,78,171,.1) !important;
}

/* ── Kelola Modal ─────────────────────────────────────────────────────────── */
.modal-kelola .modal-content {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
}
.modal-kelola .modal-header {
    border-bottom: none !important;
    padding: 12px 18px !important;
}
.modal-kelola .modal-header .btn-close {
    filter: invert(1) brightness(2);
    opacity: .8;
}
.modal-header-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .68rem;
    border-radius: 5px;
    padding: 2px 7px;
    font-weight: 600;
    white-space: nowrap;
    background: rgba(255,255,255,.18);
    color: rgba(255,255,255,.9);
}

/* Section headers inside modal */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    color: #1e293b;
    font-size: .95rem;
}
.section-title .section-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    flex-shrink: 0;
}

/* QR empty state */
.qr-empty-state {
    padding: 14px 16px;
    background: #f8faff;
    border-radius: 12px;
    border: 2px dashed #d1d9ef;
}

/* QR generated section */
.qr-generated-card {
    background: #f8faff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 20px;
}
.qr-code-string {
    font-family: 'Courier New', monospace;
    font-size: 1.2rem;
    font-weight: 800;
    color: #204EAB;
    letter-spacing: .06em;
}
.qr-note {
    font-size: .75rem;
    color: #94a3b8;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Inline add forms */
.inline-add-form {
    background: #f8faff;
    border-radius: 10px;
    border: 2px dashed #d1d9ef;
    padding: 14px;
    margin-bottom: 10px;
}

/* Compact flat list rows — shared by vendor & ED */
.flat-list-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 4px;
    border-bottom: 1px solid #f1f5f9;
    font-size: .85rem;
}
.flat-list-row:last-child { border-bottom: none; }
.flat-list-wrap {
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    padding: 0 10px;
    background: #fff;
}
.flat-list-empty {
    font-size: .8rem;
    color: #94a3b8;
    padding: 10px 2px;
}

/* QRCode.js injects canvas+img — keep inside container */
#labelQRCode * {
    width: 88px !important;
    height: 88px !important;
    display: block !important;
    position: absolute !important;
    top: 0 !important; left: 0 !important;
}

/* ── Print Modal ──────────────────────────────────────────────────────────── */
@media print {
    @page { size: 50mm 30mm; margin: 0; }
    body * { visibility: hidden !important; }
    #labelPreview, #labelPreview * { visibility: visible !important; }
    #labelPreview {
        position: fixed !important; top: 0 !important; left: 0 !important;
        width: 50mm !important; height: 30mm !important;
        border: none !important; border-radius: 0 !important;
        padding: 1mm 1.5mm !important; gap: 2mm !important;
        display: flex !important; flex-direction: row !important;
        align-items: center !important; box-sizing: border-box !important;
    }
    #labelQRCode        { position: relative !important; overflow: hidden !important; }
    #labelQRCode *      { width: 27mm !important; height: 27mm !important;
                          position: absolute !important; top: 0 !important; left: 0 !important; }
    #labelKode   { font-size: 7.5pt !important; }
    #labelNama   { font-size: 6.5pt !important; }
    #labelKodeBarang { font-size: 6pt !important; }
    #labelSatuan     { font-size: 6pt !important; }
}

/* ── Bulk print ───────────────────────────────────────────────────────────── */
#bulkToolbar {
    background: #204EAB;
    border-radius: 10px;
    padding: 10px 16px;
    display: none;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
#bulkToolbar .bulk-count {
    color: #fff;
    font-size: .84rem;
    font-weight: 600;
    flex: 1;
}
#bulkToolbar .btn-bulk-clear {
    background: rgba(255,255,255,.15);
    border: none;
    color: #fff;
    font-size: .78rem;
    border-radius: 7px;
    padding: 5px 12px;
    cursor: pointer;
}
#bulkToolbar .btn-bulk-clear:hover { background: rgba(255,255,255,.25); }
#bulkToolbar .btn-bulk-print {
    background: #fff;
    border: none;
    color: #204EAB;
    font-size: .82rem;
    font-weight: 700;
    border-radius: 8px;
    padding: 6px 16px;
    cursor: pointer;
}
#bulkToolbar .btn-bulk-print:hover { background: #e8f0fd; }
.bulk-item-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8faff;
}
.bulk-item-row.no-bis { background: #fff8f8; border-color: #fecaca; }
.bulk-item-row .bi-name { flex: 1; font-size: .84rem; font-weight: 600; color: #1e293b; }
.bulk-item-row .bi-code { font-size: .72rem; color: #64748b; margin-top: 1px; }
.bulk-item-row .bi-qty  { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.bulk-total-bar {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 9px;
    padding: 10px 16px;
    font-size: .84rem;
    color: #1e40af;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
/* Checkbox col */
#barangBisTable th:first-child,
#barangBisTable td:first-child { width: 40px !important; text-align: center; }
</style>

<div class="master-wrap">

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h4 mb-1 fw-bold" style="color:#204EAB; font-family:'Inter',sans-serif;">
            <i class="fas fa-boxes me-2"></i>Database Barang
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:.82rem;">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none" style="color:#204EAB;">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php?page=qr_master_barcode" class="text-decoration-none" style="color:#204EAB;">Master Barcode</a></li>
                <li class="breadcrumb-item active">Database Barang</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <a href="index.php?page=qr_inbound" class="btn btn-outline-primary fw-semibold" style="border-radius:10px;">
            <i class="fas fa-barcode me-2"></i>Inbound Scan
        </a>
        <a href="index.php?page=qr_stock_opname" class="btn btn-outline-success fw-semibold" style="border-radius:10px;">
            <i class="fas fa-clipboard-check me-2"></i>Stock Opname
        </a>
        <div class="vr mx-1"></div>
        <button class="btn btn-outline-secondary fw-semibold" style="border-radius:10px;" data-bs-toggle="modal" data-bs-target="#modalImportMinStok">
            <i class="fas fa-file-import me-2"></i>Import Min Stok
        </button>
        <span class="badge text-bg-warning fw-semibold px-3 py-2" style="border-radius:20px;">
            <i class="fas fa-flask me-1"></i>DUMMY DATA
        </span>
    </div>
</div>

<!-- ── Summary Stat Cards ───────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon-circle" style="background:#e8f0fd;">
                    <i class="fas fa-boxes" style="color:#204EAB;"></i>
                </div>
                <div>
                    <div class="stat-number" style="color:#1e293b;"><?= $total ?></div>
                    <div class="stat-label">Total Barang</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon-circle" style="background:#ede9fe;">
                    <i class="fas fa-qrcode" style="color:#7c3aed;"></i>
                </div>
                <div>
                    <div class="stat-number" style="color:#7c3aed;"><?= $with_internal ?></div>
                    <div class="stat-label">BIS Barcode Aktif</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon-circle" style="background:#dcfce7;">
                    <i class="fas fa-layer-group" style="color:#16a34a;"></i>
                </div>
                <div>
                    <div class="stat-number" style="color:#16a34a;"><?= $with_min ?></div>
                    <div class="stat-label">Min Stok Diset</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon-circle" style="background:<?= $label_set < $total ? '#fff3cd' : '#dcfce7' ?>;">
                    <i class="fas fa-tag" style="color:<?= $label_set < $total ? '#d97706' : '#16a34a' ?>;"></i>
                </div>
                <div>
                    <div class="stat-number" style="color:<?= $label_set < $total ? '#d97706' : '#16a34a' ?>;"><?= $label_set ?>/<?= $total ?></div>
                    <div class="stat-label">Label Terkonfigurasi</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────────────────────── -->
<ul class="nav nav-pills mb-3 gap-2" id="barangTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-semibold d-flex align-items-center gap-2" id="tab-odoo-btn"
            data-bs-toggle="pill" data-bs-target="#tab-odoo" type="button" role="tab"
            style="border-radius:20px; padding:8px 20px;">
            <i class="fas fa-cloud"></i> Barang Odoo
            <span class="badge bg-white text-primary ms-1" style="font-size:.72rem;"><?= $total ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold d-flex align-items-center gap-2" id="tab-lokal-btn"
            data-bs-toggle="pill" data-bs-target="#tab-lokal" type="button" role="tab"
            style="border-radius:20px; padding:8px 20px;">
            <i class="fas fa-box"></i> Barang Non-Odoo
            <span class="badge bg-secondary ms-1" style="font-size:.72rem;"><?= count($dummy_lokal) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- Tab Odoo -->
<div class="tab-pane fade show active" id="tab-odoo" role="tabpanel">
<div class="card border-0 shadow-sm" style="border-radius:16px; overflow:hidden;">
    <div class="card-header bg-white border-0 px-4 pt-4 pb-0">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h5 class="fw-bold mb-0" style="color:#1e293b;">Daftar Barang Odoo</h5>
                <div class="text-muted" style="font-size:.78rem;"><?= $total ?> item · Edit Min Stok atau Kelola BIS Config per item</div>
            </div>
        </div>
        <!-- Filter chips -->
        <div class="d-flex gap-2 flex-wrap pb-3" id="filterChips">
            <?php
            $no_bis     = count(array_filter($dummy_items, fn($i) => empty($i['barcode_internal'])));
            $label_unset= count(array_filter($dummy_items, fn($i) => !($i['label_config_set'] ?? false)));
            $with_ed    = count(array_filter($dummy_items, fn($i) => $item['track_ed'] ?? false));
            $no_min     = count(array_filter($dummy_items, fn($i) => ($i['stok_minimum'] ?? 0) == 0));
            $no_vendor  = count(array_filter($dummy_items, fn($i) => empty($i['vendor_barcodes'])));
            ?>
            <button class="filter-chip active" data-filter="all" onclick="applyFilter(this)">
                Semua <span class="badge"><?= $total ?></span>
            </button>
            <button class="filter-chip" data-filter="no-bis" onclick="applyFilter(this)">
                <i class="fas fa-qrcode" style="font-size:.72rem;"></i> Belum Ada BIS
                <span class="badge"><?= $no_bis ?></span>
            </button>
            <button class="filter-chip" data-filter="label-unset" onclick="applyFilter(this)">
                <i class="fas fa-tag" style="font-size:.72rem;"></i> Label Belum Diset
                <span class="badge"><?= $label_unset ?></span>
            </button>
            <button class="filter-chip" data-filter="no-vendor" onclick="applyFilter(this)">
                <i class="fas fa-barcode" style="font-size:.72rem;"></i> Belum Ada Vendor
                <span class="badge"><?= $no_vendor ?></span>
            </button>
            <button class="filter-chip" data-filter="track-ed" onclick="applyFilter(this)">
                <i class="fas fa-calendar-times" style="font-size:.72rem;"></i> Track ED
                <span class="badge"><?= count(array_filter($dummy_items, fn($i) => ($i['track_ed'] ?? false))) ?></span>
            </button>
            <button class="filter-chip" data-filter="no-minstok" onclick="applyFilter(this)">
                <i class="fas fa-layer-group" style="font-size:.72rem;"></i> Min Stok = 0
                <span class="badge"><?= $no_min ?></span>
            </button>
        </div>
    </div>
    <div class="card-body px-2 pb-3 pt-0">
        <!-- Bulk toolbar -->
        <div id="bulkToolbar">
            <span class="bulk-count"><span id="bulkCountText">0</span> item dipilih</span>
            <button class="btn-bulk-clear" onclick="clearBulkSelection()">
                <i class="fas fa-times me-1"></i>Batal Pilih
            </button>
            <button class="btn-bulk-print" onclick="openBulkPrint()">
                <i class="fas fa-print me-1"></i>Print Label
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover datatable align-middle" id="barangBisTable" style="width:100%" data-order-col="1" data-order-dir="asc">
                <thead>
                    <tr>
                        <th data-orderable="false" style="width:40px;">
                            <input type="checkbox" id="checkAll" style="cursor:pointer;width:15px;height:15px;">
                        </th>
                        <th style="width:80px;">Kode</th>
                        <th>Nama Barang</th>
                        <th style="width:100px;">UOM</th>
                        <th style="width:90px;">Tipe</th>
                        <th style="width:90px;" class="text-end">Min Stok</th>
                        <th style="width:120px;">BIS Barcode</th>
                        <th style="width:90px;">Vendor</th>
                        <th style="width:90px;">Track ED</th>
                        <th style="width:120px;">Label Config</th>
                        <th style="width:130px;" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $placeShort = ['unit'=>'Item','box'=>'Box','outer'=>'Outer','catalogue'=>'Katalog'];
                foreach ($dummy_items as $item):
                    $lprint = $item['label_print']     ?? null;
                    $lplace = $item['label_placement']  ?? null;
                    $lset   = $item['label_config_set'] ?? false;
                ?>
                <tr
                    data-id="<?= (int)$item['id'] ?>"
                    data-kode="<?= htmlspecialchars($item['kode_barang'], ENT_QUOTES) ?>"
                    data-nama="<?= htmlspecialchars($item['nama_barang'], ENT_QUOTES) ?>"
                    data-min="<?= (int)($item['stok_minimum'] ?? 0) ?>"
                    data-tipe="<?= htmlspecialchars($item['tipe'] ?? '', ENT_QUOTES) ?>"
                    data-bis="<?= htmlspecialchars($item['barcode_internal'] ?? '', ENT_QUOTES) ?>"
                    data-label-set="<?= ($item['label_config_set'] ?? false) ? '1' : '0' ?>"
                    data-track-ed="<?= ($item['track_ed'] ?? false) ? '1' : '0' ?>"
                    data-vendor="<?= count($item['vendor_barcodes'] ?? []) ?>"
                >
                    <td><input type="checkbox" class="row-check" style="cursor:pointer;width:15px;height:15px;" data-id="<?= (int)$item['id'] ?>" data-nama="<?= htmlspecialchars($item['nama_barang'], ENT_QUOTES) ?>" data-bis="<?= htmlspecialchars($item['barcode_internal'] ?? '', ENT_QUOTES) ?>"></td>
                    <td class="fw-semibold small" style="color:#204EAB;"><?= htmlspecialchars($item['kode_barang']) ?></td>
                    <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                    <td>
                        <?php
                        $ratio    = $item['uom_ratio'] ?? 1;
                        $uomOdoo  = $item['uom_odoo']  ?? $item['satuan'];
                        $ratioFmt = rtrim(rtrim(number_format((float)$ratio, 4, '.', ''), '0'), '.');
                        ?>
                        <div class="text-muted small"><?= htmlspecialchars($item['satuan']) ?></div>
                        <?php if ($ratio != 1): ?>
                            <div class="text-muted" style="font-size:.68rem; white-space:nowrap;">1 <?= htmlspecialchars($item['satuan']) ?> → <?= $ratioFmt ?> <?= htmlspecialchars($uomOdoo) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($item['tipe'] ?? '') === 'Core'): ?>
                            <span class="badge bg-danger">Core</span>
                        <?php elseif (($item['tipe'] ?? '') === 'Support'): ?>
                            <span class="badge" style="background:#6366f1;">Support</span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold <?= ($item['stok_minimum'] ?? 0) == 0 ? 'text-muted' : '' ?>">
                        <?= (int)($item['stok_minimum'] ?? 0) ?>
                    </td>
                    <td>
                        <?php if (!empty($item['barcode_internal'])): ?>
                            <span class="chip chip-qr-active" style="font-size:.72rem;">
                                <i class="fas fa-qrcode" style="font-size:.6rem;"></i><?= htmlspecialchars($item['barcode_internal']) ?>
                            </span>
                        <?php else: ?>
                            <span class="chip chip-qr-empty" style="font-size:.72rem;">
                                <i class="fas fa-qrcode" style="font-size:.6rem;"></i>Belum ada
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $vc = count($item['vendor_barcodes'] ?? []); ?>
                        <?php if ($vc > 0): ?>
                            <span class="chip chip-vendor" style="font-size:.72rem;">
                                <i class="fas fa-barcode" style="font-size:.6rem;"></i><?= $vc ?> vendor
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['track_ed']): ?>
                            <span class="chip chip-ed-yes" style="font-size:.72rem;">
                                <i class="fas fa-calendar-times" style="font-size:.6rem;"></i>Track ED
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$lset): ?>
                            <span class="chip chip-label-warn" style="font-size:.72rem;">
                                <i class="fas fa-tag" style="font-size:.6rem;"></i>Belum Diset
                            </span>
                        <?php elseif ($lprint === 'none'): ?>
                            <span class="chip chip-label-none" style="font-size:.72rem;">
                                <i class="fas fa-tag" style="font-size:.6rem;"></i>Sistem Saja
                            </span>
                        <?php else: ?>
                            <span class="chip chip-label-physical" style="font-size:.72rem;">
                                <i class="fas fa-print" style="font-size:.6rem;"></i><?= $placeShort[$lplace] ?? $lplace ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-primary fw-semibold shadow-sm"
                            style="border-radius:8px; font-size:.78rem;"
                            onclick="openBarcodeModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)">
                            <i class="fas fa-sliders-h me-1"></i>Kelola
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div><!-- end tab-odoo -->

<!-- Tab Non-Odoo -->
<div class="tab-pane fade" id="tab-lokal" role="tabpanel">
<div class="card border-0 shadow-sm" style="border-radius:16px; overflow:hidden;">
    <div class="card-header bg-white border-0 px-4 pt-4 pb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0" style="color:#1e293b;">Daftar Barang Non-Odoo (Lokal)</h5>
            <div class="text-muted" style="font-size:.78rem;"><?= count($dummy_lokal) ?> item lokal · tidak tersinkron dengan Odoo</div>
        </div>
        <button class="btn btn-primary fw-semibold" style="border-radius:10px;" onclick="alert('Tambah item lokal (dummy)')">
            <i class="fas fa-plus me-2"></i>Tambah Item Baru
        </button>
    </div>
    <div class="card-body px-2 pb-3 pt-0">
        <div class="table-responsive">
            <table class="table table-hover datatable align-middle" id="lokalBisTable" style="width:100%" data-order-col="0" data-order-dir="asc">
                <thead class="table-light">
                    <tr>
                        <th style="width:120px;">Kode Item</th>
                        <th>Nama Item</th>
                        <th style="width:80px;">UOM</th>
                        <th style="width:120px;">BIS Barcode</th>
                        <th style="width:90px;">Track ED</th>
                        <th style="width:120px;">Label Config</th>
                        <th style="width:100px;" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($dummy_lokal as $item):
                    $lprint = $item['label_print']     ?? null;
                    $lplace = $item['label_placement']  ?? null;
                    $lset   = $item['label_config_set'] ?? false;
                ?>
                <tr>
                    <td class="fw-semibold small" style="color:#204EAB;"><?= htmlspecialchars($item['kode_barang']) ?></td>
                    <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($item['satuan']) ?></td>
                    <td>
                        <?php if (!empty($item['barcode_internal'])): ?>
                            <span class="chip chip-qr-active" style="font-size:.72rem;">
                                <i class="fas fa-qrcode" style="font-size:.6rem;"></i><?= htmlspecialchars($item['barcode_internal']) ?>
                            </span>
                        <?php else: ?>
                            <span class="chip chip-qr-empty" style="font-size:.72rem;">
                                <i class="fas fa-qrcode" style="font-size:.6rem;"></i>Belum ada
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['track_ed']): ?>
                            <span class="chip chip-ed-yes" style="font-size:.72rem;">
                                <i class="fas fa-calendar-times" style="font-size:.6rem;"></i>Track ED
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$lset): ?>
                            <span class="chip chip-label-warn" style="font-size:.72rem;">
                                <i class="fas fa-tag" style="font-size:.6rem;"></i>Belum Diset
                            </span>
                        <?php elseif ($lprint === 'none'): ?>
                            <span class="chip chip-label-none" style="font-size:.72rem;">
                                <i class="fas fa-tag" style="font-size:.6rem;"></i>Sistem Saja
                            </span>
                        <?php else: ?>
                            <span class="chip chip-label-physical" style="font-size:.72rem;">
                                <i class="fas fa-print" style="font-size:.6rem;"></i><?= $placeShort[$lplace] ?? $lplace ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary fw-semibold shadow-sm"
                            style="border-radius:8px; font-size:.78rem;"
                            onclick="openBarcodeModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)">
                            <i class="fas fa-sliders-h me-1"></i>BIS Config
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div><!-- end tab-lokal -->

</div><!-- end tab-content -->

</div><!-- end .master-wrap -->


<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Import Min Stok — dummy                                             -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalImportMinStok" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" style="color:#204EAB;">
                    <i class="fas fa-file-excel me-2"></i>Import Min Stok (Bulk)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <!-- Step 1: Download template -->
                <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3" style="background:#eff6ff;border:1px solid #bfdbfe;">
                    <div>
                        <div class="fw-semibold small" style="color:#1d4ed8;">Step 1 — Download Template</div>
                        <div class="text-muted" style="font-size:.78rem;">Isi kolom yang dibutuhkan lalu upload di Step 2.</div>
                    </div>
                    <a href="api/export_template_min_stok.php" class="btn btn-sm btn-primary shadow-sm flex-shrink-0">
                        <i class="fas fa-download me-1"></i>Export Template
                    </a>
                </div>

                <!-- Panduan kolom -->
                <div class="mb-3">
                    <div class="fw-semibold small mb-2" style="color:#374151;">Panduan Pengisian Kolom</div>
                    <div class="rounded-3 overflow-hidden" style="border:1px solid #e5e7eb;font-size:.76rem;">
                        <table class="table table-sm mb-0" style="font-size:.76rem;">
                            <thead style="background:#f9fafb;">
                                <tr>
                                    <th class="px-3 py-2 fw-semibold" style="color:#6b7280;width:38%;">Kolom</th>
                                    <th class="px-3 py-2 fw-semibold" style="color:#6b7280;">Panduan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-top">
                                    <td class="px-3 py-2 text-muted">ID</td>
                                    <td class="px-3 py-2 text-muted"><em>Jangan diubah</em> — dipakai sebagai referensi baris.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2 text-muted">Kode / Nama Barang</td>
                                    <td class="px-3 py-2 text-muted"><em>Jangan diubah</em> — hanya untuk referensi pembacaan.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">Stok Minimum</span></td>
                                    <td class="px-3 py-2">Angka bulat ≥ 0. Isi <code>0</code> jika tidak ada batas minimum. Kosong = diabaikan.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">Tipe</span></td>
                                    <td class="px-3 py-2">Isi <code>Core</code> atau <code>Support</code>. Kosong = tidak berubah.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">BIS Barcode</span></td>
                                    <td class="px-3 py-2">Kosongkan — sistem akan generate otomatis (<code>BIS-XXXX</code>) saat inbound pertama kali.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">Track ED</span></td>
                                    <td class="px-3 py-2">Isi <code>1</code> jika item perlu tracking expiry date, <code>0</code> jika tidak.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">Label Print</span></td>
                                    <td class="px-3 py-2"><code>none</code> = cukup di sistem saja &nbsp;|&nbsp; <code>physical</code> = perlu cetak label fisik.</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="px-3 py-2"><span class="fw-semibold">Label Placement</span></td>
                                    <td class="px-3 py-2">Wajib jika Label Print = <code>physical</code>.<br><code>unit</code> · <code>box</code> · <code>outer</code> · <code>catalogue</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Step 2: Upload -->
                <div class="mb-0">
                    <label class="form-label small fw-bold">Step 2 — Upload File Excel (.xlsx)</label>
                    <input type="file" class="form-control" accept=".xlsx" id="importExcelFile">
                    <div class="form-text small text-muted mt-1">Nilai kosong diabaikan · Hanya nilai yang berubah yang diperbarui.</div>
                </div>
                <div id="importResult" class="mt-3 d-none"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary px-4" onclick="dummyImport()">
                    <i class="fas fa-upload me-1"></i>Mulai Import
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Kelola Barcode                                                      -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade modal-kelola" id="modalKelolaBarcde" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2 flex-fill min-w-0">
                    <i class="fas fa-qrcode" style="color:rgba(255,255,255,.7);font-size:.85rem;flex-shrink:0;"></i>
                    <code style="font-size:.78rem;color:rgba(255,255,255,.65);flex-shrink:0;" id="modalKodeBarang"></code>
                    <span class="fw-bold" style="color:#fff;font-size:.95rem;" id="modalNamaBarang"></span>
                </div>
                <button type="button" class="btn-close ms-3 flex-shrink-0" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4 py-4">

                <!-- ── SECTION 0: Info Dasar ──────────────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#e8f0fd;">
                                <i class="fas fa-tag" style="color:#204EAB;"></i>
                            </div>
                            Info Dasar
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Tipe Barang</label>
                            <select class="form-select form-select-sm no-select2" id="kelolaTipeValue">
                                <option value="">- Belum Ditentukan -</option>
                                <option value="Core">Core</option>
                                <option value="Support">Support</option>
                            </select>
                            <div class="form-text" style="font-size:.72rem;">Dipakai saat mapping item BHP.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Min Stok</label>
                            <input type="number" class="form-control form-control-sm" id="kelolaMinStokValue" min="0" step="1" value="0">
                            <div class="form-text" style="font-size:.72rem;">Nilai 0 = tidak ada batas minimum.</div>
                        </div>
                    </div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── SECTION 1: QR Internal ──────────────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#e8f0fd;">
                                <i class="fas fa-qrcode" style="color:#204EAB;"></i>
                            </div>
                            QR Code Internal
                        </div>
                        <button class="btn btn-primary btn-sm fw-semibold" id="btnGenerateQR" onclick="generateQR()"
                            style="border-radius:8px; padding:7px 16px; font-size:.8rem; display:none;">
                            <i class="fas fa-magic me-1"></i>Generate QR
                        </button>
                    </div>

                    <div id="qrInternalSection">

                        <!-- Not yet generated: compact horizontal empty state -->
                        <div id="qrNotGenerated" class="qr-empty-state">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:38px;height:38px;border-radius:10px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-qrcode" style="color:#94a3b8;font-size:.9rem;"></i>
                                </div>
                                <div class="flex-fill">
                                    <div class="fw-semibold text-muted" style="font-size:.88rem;">Belum Ada QR Internal</div>
                                    <div class="text-muted" style="font-size:.75rem;">Kode deterministik dari kode barang, berlaku di semua cabang</div>
                                </div>
                                <button class="btn btn-primary btn-sm fw-semibold flex-shrink-0" onclick="generateQR()"
                                    style="border-radius:8px;padding:7px 16px;font-size:.82rem;">
                                    <i class="fas fa-magic me-1"></i>Generate QR
                                </button>
                            </div>
                        </div>

                        <!-- Already generated -->
                        <div id="qrGeneratedSection" style="display:none;">
                            <div class="qr-generated-card">
                                <div class="row align-items-center g-3">
                                    <div class="col-auto">
                                        <div id="qrCodeCanvas"
                                            style="padding:8px; background:#fff; border-radius:10px; border:1px solid #e2e8f0; display:inline-block;"></div>
                                    </div>
                                    <div class="col">
                                        <div class="text-muted small mb-1" style="font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:600;">Kode QR Internal</div>
                                        <div class="qr-code-string" id="qrCodeString"></div>
                                        <div class="qr-note">
                                            <i class="fas fa-lock" style="font-size:.65rem;"></i>
                                            Deterministik — tidak akan berubah
                                        </div>
                                        <div class="d-flex gap-2 mt-3">
                                            <button class="btn btn-success btn-sm fw-semibold" onclick="printLabel()"
                                                style="border-radius:8px; padding:8px 16px; font-size:.8rem;">
                                                <i class="fas fa-print me-1"></i>Print Label
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm fw-semibold" onclick="deleteInternalQR()"
                                                style="border-radius:8px; padding:8px 14px; font-size:.8rem;">
                                                <i class="fas fa-trash me-1"></i>Hapus
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── SECTION 2: Kadaluarsa (ED) ─────────────────────────── -->
                <div id="edBatchesSection" class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#fef3c7;">
                                <i class="fas fa-calendar-times" style="color:#d97706;"></i>
                            </div>
                            Kadaluarsa (ED)
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="form-check form-switch mb-0 d-flex align-items-center gap-1">
                                <input class="form-check-input" type="checkbox" id="toggleTrackED"
                                    role="switch" onchange="onToggleTrackED()"
                                    style="width:2em;height:1.1em;cursor:pointer;">
                                <label class="form-check-label small fw-semibold" for="toggleTrackED" style="color:#374151;">Track ED</label>
                            </div>
                            <button class="btn btn-sm fw-semibold" id="btnTambahED" onclick="showAddED()"
                                style="display:none;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:5px 12px;font-size:.8rem;">
                                <i class="fas fa-plus me-1"></i>Tambah ED
                            </button>
                        </div>
                    </div>

                    <!-- State: Track ED OFF -->
                    <div id="edOffState">
                        <p class="flat-list-empty mb-0"><i class="fas fa-toggle-off me-1"></i>Aktifkan Track ED untuk mencatat batch kadaluarsa</p>
                    </div>

                    <!-- State: Track ED ON -->
                    <div id="edOnState" style="display:none;">
                        <div id="addEDForm" style="display:none;" class="inline-add-form mb-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-auto">
                                    <label class="form-label small fw-semibold mb-1" style="color:#374151;">Bulan / Tahun ED <span class="text-danger">*</span></label>
                                    <input type="month" class="form-control form-control-sm" id="inputEDMonth"
                                        min="<?= date('Y-m') ?>" style="border-radius:7px;">
                                </div>
                                <div class="col">
                                    <label class="form-label small fw-semibold mb-1" style="color:#374151;">Keterangan <span class="text-muted fw-normal">(opsional)</span></label>
                                    <input type="text" class="form-control form-control-sm" id="inputEDKet"
                                        placeholder="No. batch / lot" style="border-radius:7px;">
                                </div>
                                <div class="col-auto d-flex gap-2">
                                    <button class="btn btn-warning btn-sm text-dark fw-semibold" onclick="saveED()" style="border-radius:7px;">
                                        <i class="fas fa-check me-1"></i>Simpan
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="hideAddED()" style="border-radius:7px;">Batal</button>
                                </div>
                            </div>
                        </div>
                        <div id="edBatchList"></div>
                    </div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── SECTION 3: Barcode Vendor ────────────────────────────── -->
                <div>
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#dcfce7;">
                                <i class="fas fa-barcode" style="color:#16a34a;"></i>
                            </div>
                            Barcode Vendor
                        </div>
                        <button class="btn btn-sm btn-success fw-semibold" onclick="showAddVendorBarcode()"
                            style="border-radius:8px; padding:7px 14px; font-size:.8rem;">
                            <i class="fas fa-plus me-1"></i>Tambah
                        </button>
                    </div>

                    <!-- Inline add vendor form -->
                    <div id="addVendorForm" style="display:none;" class="inline-add-form mb-2">
                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label class="form-label small fw-semibold mb-1" style="color:#374151;">Barcode Vendor <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="inputVendorBarcode"
                                    placeholder="Scan atau ketik..." autofocus
                                    style="border-radius:7px;font-family:monospace;">
                            </div>
                            <div class="col">
                                <label class="form-label small fw-semibold mb-1" style="color:#374151;">Keterangan <span class="text-muted fw-normal">(opsional)</span></label>
                                <input type="text" class="form-control form-control-sm" id="inputVendorKet"
                                    placeholder="Nama vendor, dll" style="border-radius:7px;">
                            </div>
                            <div class="col-auto d-flex gap-2">
                                <button class="btn btn-success btn-sm fw-semibold" onclick="saveVendorBarcode()" style="border-radius:7px;">
                                    <i class="fas fa-check me-1"></i>Simpan
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="hideAddVendorBarcode()" style="border-radius:7px;">Batal</button>
                            </div>
                        </div>
                    </div>

                    <div id="vendorBarcodeList"></div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── SECTION 4: Konfigurasi Label ──────────────────────────── -->
                <div>
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#eff6ff;">
                                <i class="fas fa-tag" style="color:#204EAB;"></i>
                            </div>
                            Konfigurasi Label
                        </div>
                    </div>
                    <div id="labelConfigSection"></div>
                </div>

            </div>
            <!-- no modal-footer needed -->
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Print Label                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Bulk Print                                                          -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalBulkPrint" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header border-0 px-4 pt-4 pb-3" style="background:#204EAB;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:40px;height:40px;border-radius:11px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-print" style="color:#fff;font-size:1rem;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" style="color:#fff;">Bulk Print Label</h5>
                        <div style="font-size:.76rem;color:rgba(255,255,255,.75);">Atur jumlah cetak per item</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-4">
                <div id="bulkPrintList" class="d-flex flex-column gap-2"></div>
                <div id="bulkPrintTotal" class="bulk-total-bar mt-3" style="display:none;">
                    <span>Total cetak</span>
                    <span id="bulkTotalCount">0 lembar</span>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex gap-2">
                <button class="btn btn-light fw-semibold flex-fill" data-bs-dismiss="modal" style="border-radius:10px;">Batal</button>
                <button class="btn btn-primary fw-bold flex-fill" onclick="doBulkPrint()" style="border-radius:10px;background:#204EAB;border:none;">
                    <i class="fas fa-print me-1"></i>Print Semua
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPrintLabel" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header border-0 px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:40px;height:40px;border-radius:10px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-print" style="color:#204EAB;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" style="color:#1e293b;">Print Label QR</h5>
                        <div class="text-muted" style="font-size:.75rem;">Preview 50×30mm — Zebra printer</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-2">
                <div class="alert border-0 d-flex align-items-center gap-2 mb-4"
                    style="background:#eff6ff; color:#1d4ed8; border-radius:10px; font-size:.83rem;">
                    <i class="fas fa-info-circle flex-shrink-0"></i>
                    Preview label ukuran <strong>50×30mm</strong>. Pastikan printer Zebra sudah terhubung sebelum mencetak.
                </div>

                <!-- Label preview box -->
                <div class="text-center mb-3">
                    <div id="labelPreview"
                        style="width:200px;height:120px;border:1px dashed #adb5bd;border-radius:4px;
                               padding:5px;background:#fff;display:flex;flex-direction:row;
                               align-items:center;gap:6px;overflow:hidden;box-sizing:border-box;
                               margin:0 auto; box-shadow:0 2px 10px rgba(0,0,0,.08);">
                        <div id="labelQRCode" style="flex:0 0 88px;width:88px;height:88px;position:relative;overflow:hidden;"></div>
                        <div style="flex:1;min-width:0;text-align:left;overflow:hidden;">
                            <div id="labelKode"
                                style="font-family:monospace;font-size:10px;font-weight:700;color:#204EAB;"></div>
                            <div id="labelNama"
                                style="font-size:9px;line-height:1.25;color:#333;word-break:break-word;margin-top:1px;"></div>
                            <div id="labelKodeBarang"
                                style="font-size:8px;color:#6c757d;margin-top:2px;"></div>
                            <div id="labelSatuan"
                                style="font-size:8px;color:#555;margin-top:1px;"></div>
                        </div>
                    </div>
                    <div class="text-muted mt-2" style="font-size:.72rem;">Preview proporsional — ukuran sebenarnya 50×30mm</div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2 justify-content-end">
                <button class="btn btn-outline-secondary fw-semibold" data-bs-dismiss="modal"
                    style="border-radius:10px; padding:10px 24px;">
                    <i class="fas fa-times me-1"></i>Tutup
                </button>
                <button class="btn btn-primary fw-bold" onclick="window.print()"
                    style="border-radius:10px; padding:10px 28px; background:#204EAB; border:none;">
                    <i class="fas fa-print me-2"></i>Print Sekarang
                </button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let currentItem = null;
let qrGenerated = false;
let _selectedLabelPrint = null;
let _selectedPlacement  = null;
// Dummy ED storage: { item_id: [{id, month, ket}] }
const edStorage = {
    2: [{id:1, month:'2026-03', ket:'Lot A'}],
    5: [{id:2, month:'2026-06', ket:''}, {id:3, month:'2026-12', ket:'Lot B'}],
};
let edIdCounter = 10;


function openBarcodeModal(item) {
    currentItem = item;
    document.getElementById('modalNamaBarang').textContent = item.nama_barang;
    document.getElementById('modalKodeBarang').textContent = item.kode_barang ?? '';

    // Info Dasar
    document.getElementById('kelolaTipeValue').value    = item.tipe        ?? '';
    document.getElementById('kelolaMinStokValue').value = item.stok_minimum ?? 0;


    // Reset
    document.getElementById('qrNotGenerated').style.display = 'block';
    document.getElementById('qrGeneratedSection').style.display = 'none';
    document.getElementById('btnGenerateQR').style.display = 'none';
    document.getElementById('addVendorForm').style.display = 'none';
    document.getElementById('addEDForm').style.display = 'none';
    document.getElementById('qrCodeCanvas').innerHTML = '';
    qrGenerated = false;

    // Inisialisasi toggle Track ED
    const hasED = item.track_ed === true;
    document.getElementById('toggleTrackED').checked = hasED;
    document.getElementById('addEDForm').style.display = 'none';
    document.getElementById('edOffState').style.display = hasED ? 'none' : 'block';
    document.getElementById('edOnState').style.display  = hasED ? 'block' : 'none';
    document.getElementById('btnTambahED').style.display = hasED ? '' : 'none';
    if (hasED) renderEDBatches(item.id);

    // Jika sudah punya QR internal
    if (item.barcode_internal) {
        showExistingQR(item.barcode_internal);
    }

    // Render vendor barcodes
    renderVendorBarcodes(item.vendor_barcodes);

    // Render label config section
    _selectedLabelPrint = null;
    _selectedPlacement  = null;
    renderLabelConfig(item);

    new bootstrap.Modal(document.getElementById('modalKelolaBarcde')).show();
}

function showExistingQR(code) {
    document.getElementById('qrNotGenerated').style.display = 'none';
    document.getElementById('qrGeneratedSection').style.display = 'flex';
    document.getElementById('btnGenerateQR').style.display = 'none';
    document.getElementById('qrCodeString').textContent = code;
    document.getElementById('qrCodeCanvas').innerHTML = '';
    new QRCode(document.getElementById('qrCodeCanvas'), {
        text: code, width: 100, height: 100,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
    qrGenerated = true;
}

function generateQR() {
    if (!currentItem) return;
    const kode = String(currentItem.kode_barang).padStart(4, '0');
    const qrString = 'BIS-' + kode;

    Swal.fire({
        title: 'Generate QR Internal?',
        html: `QR code <b>${qrString}</b> akan digenerate untuk item ini.<br><small class="text-muted">Kode ini permanen dan tidak akan berubah.</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#204EAB',
        confirmButtonText: 'Ya, Generate',
        cancelButtonText: 'Batal'
    }).then(result => {
        if (result.isConfirmed) {
            currentItem.barcode_internal = qrString;
            showExistingQR(qrString);
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: `QR Internal ${qrString} telah digenerate.`, timer: 1800, showConfirmButton: false });
        }
    });
}

function deleteInternalQR() {
    Swal.fire({
        title: 'Hapus QR Internal?',
        text: 'QR internal item ini akan dihapus.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal'
    }).then(result => {
        if (result.isConfirmed) {
            currentItem.barcode_internal = null;
            document.getElementById('qrNotGenerated').style.display = 'block';
            document.getElementById('qrGeneratedSection').style.display = 'none';
            document.getElementById('qrCodeCanvas').innerHTML = '';
        }
    });
}

function renderVendorBarcodes(list) {
    const el = document.getElementById('vendorBarcodeList');
    if (!list || list.length === 0) {
        el.innerHTML = `<p class="flat-list-empty mb-0"><i class="fas fa-barcode me-1"></i>Belum ada barcode vendor</p>`;
        return;
    }
    el.innerHTML = `<div class="flat-list-wrap">${list.map(v => `
        <div class="flat-list-row">
            <code style="font-family:monospace;font-weight:700;color:#1e293b;flex:1;">${v.barcode}</code>
            ${v.ket ? `<span class="text-muted" style="font-size:.78rem;">${v.ket}</span>` : ''}
            <button class="btn btn-sm text-danger p-0" onclick="deleteVendorBarcode('${v.barcode}')"
                style="font-size:.78rem;line-height:1;" title="Hapus">
                <i class="fas fa-times-circle"></i>
            </button>
        </div>`).join('')}</div>`;
}

function showAddVendorBarcode() {
    document.getElementById('addVendorForm').style.display = 'block';
    setTimeout(() => document.getElementById('inputVendorBarcode').focus(), 100);
}
function hideAddVendorBarcode() {
    document.getElementById('addVendorForm').style.display = 'none';
    document.getElementById('inputVendorBarcode').value = '';
    document.getElementById('inputVendorKet').value = '';
}

function saveVendorBarcode() {
    const barcode = document.getElementById('inputVendorBarcode').value.trim();
    const ket = document.getElementById('inputVendorKet').value.trim();
    if (!barcode) { Swal.fire({ icon: 'warning', title: 'Barcode kosong', text: 'Scan atau input barcode vendor terlebih dahulu.' }); return; }
    if (!currentItem.vendor_barcodes) currentItem.vendor_barcodes = [];
    currentItem.vendor_barcodes.push({ id: Date.now(), barcode, ket });
    renderVendorBarcodes(currentItem.vendor_barcodes);
    hideAddVendorBarcode();
    Swal.fire({ icon: 'success', title: 'Tersimpan!', text: `Barcode vendor ${barcode} berhasil ditambahkan.`, timer: 1500, showConfirmButton: false });
}

function deleteVendorBarcode(barcode) {
    Swal.fire({
        title: 'Hapus barcode vendor?',
        text: barcode,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Hapus'
    }).then(r => {
        if (r.isConfirmed) {
            currentItem.vendor_barcodes = currentItem.vendor_barcodes.filter(v => v.barcode !== barcode);
            renderVendorBarcodes(currentItem.vendor_barcodes);
        }
    });
}

// ── ED BATCH FUNCTIONS ─────────────────────────────────────────────────────
function formatEDLabel(ym) {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    const bln = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    return `${bln[parseInt(m)]} ${y}`;
}

function renderEDBatches(itemId) {
    const el = document.getElementById('edBatchList');
    const list = edStorage[itemId] || [];
    if (!list.length) {
        el.innerHTML = `<p class="flat-list-empty mb-0"><i class="fas fa-calendar-times me-1"></i>Belum ada ED terdaftar</p>`;
        return;
    }
    const now = new Date().toISOString().slice(0,7);
    el.innerHTML = `<div class="flat-list-wrap">${list.map(e => {
        const expired = e.month < now;
        return `
        <div class="flat-list-row">
            <span class="fw-semibold" style="font-size:.88rem;min-width:76px;">${formatEDLabel(e.month)}</span>
            <code style="font-size:.73rem;color:#94a3b8;">${e.month}</code>
            <span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;background:${expired?'#fef2f2':'#f0fdf4'};color:${expired?'#dc2626':'#16a34a'};">
                ${expired ? 'Expired' : 'Aktif'}
            </span>
            ${e.ket ? `<span class="text-muted flex-fill" style="font-size:.78rem;">· ${e.ket}</span>` : '<span class="flex-fill"></span>'}
            <button class="btn btn-sm text-danger p-0" onclick="deleteEDBatch(${e.id})"
                style="font-size:.78rem;line-height:1;" title="Hapus">
                <i class="fas fa-times-circle"></i>
            </button>
        </div>`;
    }).join('')}</div>`;
}

function onToggleTrackED() {
    const on = document.getElementById('toggleTrackED').checked;
    currentItem.track_ed = on;
    document.getElementById('edOffState').style.display  = on ? 'none' : 'block';
    document.getElementById('edOnState').style.display   = on ? 'block' : 'none';
    document.getElementById('btnTambahED').style.display = on ? '' : 'none';
    document.getElementById('addEDForm').style.display   = 'none';
    if (on) renderEDBatches(currentItem.id);
}

function showAddED() {
    document.getElementById('addEDForm').style.display = 'block';
    setTimeout(() => document.getElementById('inputEDMonth').focus(), 100);
}
function hideAddED() {
    document.getElementById('addEDForm').style.display = 'none';
    document.getElementById('inputEDMonth').value = '';
    document.getElementById('inputEDKet').value = '';
}

function saveED() {
    const month = document.getElementById('inputEDMonth').value;
    const ket   = document.getElementById('inputEDKet').value.trim();
    if (!month) { Swal.fire({ icon: 'warning', title: 'ED belum diisi', text: 'Pilih bulan dan tahun kadaluarsa.' }); return; }
    if (!edStorage[currentItem.id]) edStorage[currentItem.id] = [];
    // Cek duplikat
    if (edStorage[currentItem.id].find(e => e.month === month)) {
        Swal.fire({ icon: 'warning', title: 'ED sudah terdaftar', text: `${formatEDLabel(month)} sudah ada.` }); return;
    }
    edStorage[currentItem.id].push({ id: ++edIdCounter, month, ket });
    renderEDBatches(currentItem.id);
    hideAddED();
    Swal.fire({ icon: 'success', title: 'ED Ditambahkan!', text: `${formatEDLabel(month)} berhasil disimpan.`, timer: 1500, showConfirmButton: false });
}

function deleteEDBatch(id) {
    Swal.fire({
        title: 'Hapus ED ini?', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Hapus'
    }).then(r => {
        if (r.isConfirmed) {
            edStorage[currentItem.id] = (edStorage[currentItem.id] || []).filter(e => e.id !== id);
            renderEDBatches(currentItem.id);
        }
    });
}
// ─────────────────────────────────────────────────────────────────────────────

// ── LABEL CONFIG FUNCTIONS ────────────────────────────────────────────────────
function renderLabelConfig(item) {
    const el = document.getElementById('labelConfigSection');
    const print     = item.label_print     ?? 'none';
    const placement = item.label_placement ?? null;
    const configSet = item.label_config_set ?? false;

    _selectedLabelPrint = print;
    _selectedPlacement  = placement;

    const placements = [
        { val: 'unit',      label: 'Unit (per item)',    icon: 'fa-cube'  },
        { val: 'box',       label: 'Box / Kemasan Luar', icon: 'fa-box'   },
        { val: 'outer',     label: 'Outer Package',      icon: 'fa-boxes' },
        { val: 'catalogue', label: 'Katalog',            icon: 'fa-book'  },
    ];

    const placementHTML = placements.map(p => `
        <button type="button" class="placement-opt ${print === 'physical' && placement === p.val ? 'selected' : ''}"
            onclick="selectPlacement('${p.val}', this)">
            <i class="fas ${p.icon}" style="font-size:.72rem;"></i>${p.label}
        </button>`).join('');

    el.innerHTML = `
        <div id="labelOptNone" class="label-opt-card ${print === 'none' ? 'selected' : ''}" onclick="selectLabelPrint('none')">
            <div class="d-flex align-items-center gap-2">
                <div id="ringNone" style="width:18px;height:18px;border-radius:50%;border:2px solid ${print==='none'?'#204EAB':'#e2e8f0'};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <div id="dotNone" style="width:8px;height:8px;border-radius:50%;background:${print==='none'?'#204EAB':'transparent'};transition:background .15s;"></div>
                </div>
                <div>
                    <div class="fw-semibold small" style="color:#374151;">Sistem Saja</div>
                    <div class="text-muted" style="font-size:.75rem;">Tidak perlu cetak label fisik — barcode cukup di sistem</div>
                </div>
            </div>
        </div>

        <div id="labelOptPhysical" class="label-opt-card ${print === 'physical' ? 'selected' : ''}" onclick="selectLabelPrint('physical')">
            <div class="d-flex align-items-center gap-2 mb-2">
                <div id="ringPhysical" style="width:18px;height:18px;border-radius:50%;border:2px solid ${print==='physical'?'#204EAB':'#e2e8f0'};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <div id="dotPhysical" style="width:8px;height:8px;border-radius:50%;background:${print==='physical'?'#204EAB':'transparent'};transition:background .15s;"></div>
                </div>
                <div>
                    <div class="fw-semibold small" style="color:#374151;">Cetak Label Fisik</div>
                    <div class="text-muted" style="font-size:.75rem;">Label QR dicetak dan ditempel / disimpan sesuai penempatan</div>
                </div>
            </div>
            <div id="placementWrap" style="display:${print==='physical'?'block':'none'};padding-left:26px;padding-top:4px;">
                <div class="fw-semibold mb-2" style="font-size:.75rem;color:#374151;">Tempel / simpan di:</div>
                <div class="d-flex flex-wrap gap-2">${placementHTML}</div>
            </div>
        </div>

        ${!configSet ? `<div class="d-flex align-items-center gap-2 mb-2 px-1">
            <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:.75rem;flex-shrink:0;"></i>
            <span class="text-muted" style="font-size:.75rem;">Belum dikonfigurasi — pilih opsi di atas lalu simpan</span>
        </div>` : ''}

        <div class="mt-3">
            <button class="btn btn-primary fw-semibold" onclick="saveLabelConfig()"
                style="border-radius:9px;padding:9px 24px;font-size:.85rem;background:#204EAB;border:none;">
                <i class="fas fa-save me-1"></i>Simpan Konfigurasi
            </button>
        </div>`;
}

function selectLabelPrint(val) {
    _selectedLabelPrint = val;
    ['None','Physical'].forEach(x => {
        const isSelected = val === x.toLowerCase();
        document.getElementById('labelOpt' + x).classList.toggle('selected', isSelected);
        document.getElementById('ring' + x).style.borderColor = isSelected ? '#204EAB' : '#e2e8f0';
        document.getElementById('dot' + x).style.background   = isSelected ? '#204EAB' : 'transparent';
    });
    document.getElementById('placementWrap').style.display = val === 'physical' ? 'block' : 'none';
}

function selectPlacement(val, el) {
    event.stopPropagation();
    _selectedPlacement = val;
    document.querySelectorAll('.placement-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
}

function saveLabelConfig() {
    const print     = _selectedLabelPrint ?? currentItem.label_print ?? 'none';
    const placement = _selectedPlacement  ?? currentItem.label_placement ?? null;

    if (print === 'physical' && !placement) {
        Swal.fire({ icon: 'warning', title: 'Pilih penempatan', text: 'Tentukan label ditempel / disimpan di mana sebelum menyimpan.' });
        return;
    }

    currentItem.label_print      = print;
    currentItem.label_placement  = print === 'none' ? null : placement;
    currentItem.label_config_set = true;
    _selectedLabelPrint = null;
    _selectedPlacement  = null;

    renderLabelConfig(currentItem);
    updateRowLabelConfig(currentItem);

    const desc = print === 'none' ? 'Sistem Saja' : 'Cetak Fisik — ' + placement;
    Swal.fire({ icon: 'success', title: 'Konfigurasi Disimpan!', text: desc, timer: 1600, showConfirmButton: false })
        .then(() => {
            const m = bootstrap.Modal.getInstance(document.getElementById('modalKelolaBarcde'));
            if (m) m.hide();
        });
}

function updateRowLabelConfig(item) {
    const row = document.querySelector(`#barangBisTable tbody tr[data-id="${item.id}"]`);
    if (!row) return;

    row.dataset.labelSet = item.label_config_set ? '1' : '0';

    // Label Config = td index 9 (Chk,Kode,Nama,UOM,Tipe,MinStok,BIS,Vendor,TrackED,LabelConfig,Aksi)
    const td = row.querySelectorAll('td')[9];
    if (!td) return;

    const s = 'font-size:.72rem;';
    const placeShort = { unit:'Unit', box:'Box', outer:'Outer', catalogue:'Katalog' };

    if (!item.label_config_set) {
        td.innerHTML = `<span class="chip chip-label-warn" style="${s}"><i class="fas fa-tag" style="font-size:.6rem;"></i>Belum Diset</span>`;
    } else if (item.label_print === 'none') {
        td.innerHTML = `<span class="chip chip-label-none" style="${s}"><i class="fas fa-tag" style="font-size:.6rem;"></i>Sistem Saja</span>`;
    } else {
        const lbl = placeShort[item.label_placement] ?? item.label_placement ?? '';
        td.innerHTML = `<span class="chip chip-label-physical" style="${s}"><i class="fas fa-print" style="font-size:.6rem;"></i>${lbl}</span>`;
    }

    // Invalidate DataTables cache agar filter data-* terbaca ulang
    $('#barangBisTable').DataTable().row(row).invalidate().draw(false);
}
// ─────────────────────────────────────────────────────────────────────────────

function printLabel() {
    if (!currentItem || !currentItem.barcode_internal) return;
    document.getElementById('labelKode').textContent = currentItem.barcode_internal;
    document.getElementById('labelNama').textContent = currentItem.nama_barang;
    document.getElementById('labelKodeBarang').textContent = currentItem.kode_barang ? 'Kode: ' + currentItem.kode_barang : '';
    document.getElementById('labelSatuan').textContent = currentItem.satuan ? 'Sat: ' + currentItem.satuan : '';
    document.getElementById('labelQRCode').innerHTML = '';
    new QRCode(document.getElementById('labelQRCode'), {
        text: currentItem.barcode_internal, width: 88, height: 88,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
    new bootstrap.Modal(document.getElementById('modalPrintLabel')).show();
}


// ── Table filter chips ───────────────────────────────────────────────────────
let activeFilter = 'all';

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    if (settings.nTable.id !== 'barangBisTable') return true;
    if (activeFilter === 'all') return true;
    const api  = new $.fn.dataTable.Api(settings);
    const $row = $(api.row(dataIndex).node());
    const bis      = ($row.data('bis')       || '').toString();
    const labelSet = ($row.data('label-set') || '0').toString();
    const trackEd  = ($row.data('track-ed')  || '0').toString();
    const vendor   = parseInt($row.data('vendor') || '0');
    const min      = parseInt($row.data('min')    || '0');
    if (activeFilter === 'no-bis')      return bis === '';
    if (activeFilter === 'label-unset') return labelSet === '0';
    if (activeFilter === 'no-vendor')   return vendor === 0;
    if (activeFilter === 'track-ed')    return trackEd === '1';
    if (activeFilter === 'no-minstok')  return min === 0;
    return true;
});

function applyFilter(el) {
    activeFilter = el.dataset.filter;
    document.querySelectorAll('#filterChips .filter-chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    $('#barangBisTable').DataTable().draw();
}

// ── Bulk print ───────────────────────────────────────────────────────────────
const selectedIds = new Map(); // id → {nama, bis}

function updateBulkToolbar() {
    const toolbar = document.getElementById('bulkToolbar');
    const count = selectedIds.size;
    toolbar.style.display = count > 0 ? 'flex' : 'none';
    document.getElementById('bulkCountText').textContent = count;
}

function syncCheckboxStates() {
    document.querySelectorAll('#barangBisTable tbody .row-check').forEach(cb => {
        cb.checked = selectedIds.has(parseInt(cb.dataset.id));
    });
    const allVisible = document.querySelectorAll('#barangBisTable tbody .row-check');
    const checkAll = document.getElementById('checkAll');
    if (allVisible.length > 0) {
        checkAll.checked = [...allVisible].every(cb => cb.checked);
        checkAll.indeterminate = !checkAll.checked && [...allVisible].some(cb => cb.checked);
    } else {
        checkAll.checked = false;
        checkAll.indeterminate = false;
    }
}

// Re-sync checkboxes setelah DataTable redraw (filter/sort/page)
$(document).on('draw.dt', '#barangBisTable', syncCheckboxStates);

$(document).on('change', '#checkAll', function() {
    const checked = this.checked;
    this.indeterminate = false;
    document.querySelectorAll('#barangBisTable tbody .row-check').forEach(cb => {
        cb.checked = checked;
        const id = parseInt(cb.dataset.id);
        if (checked) {
            selectedIds.set(id, { nama: cb.dataset.nama, bis: cb.dataset.bis });
        } else {
            selectedIds.delete(id);
        }
    });
    updateBulkToolbar();
});

$(document).on('change', '#barangBisTable tbody .row-check', function() {
    const id = parseInt(this.dataset.id);
    if (this.checked) {
        selectedIds.set(id, { nama: this.dataset.nama, bis: this.dataset.bis });
    } else {
        selectedIds.delete(id);
    }
    updateBulkToolbar();
    syncCheckboxStates();
});

function clearBulkSelection() {
    selectedIds.clear();
    updateBulkToolbar();
    syncCheckboxStates();
}

function openBulkPrint() {
    if (selectedIds.size === 0) return;
    const list = document.getElementById('bulkPrintList');
    list.innerHTML = '';
    selectedIds.forEach((item, id) => {
        const hasBis = !!item.bis;
        const row = document.createElement('div');
        row.className = 'bulk-item-row' + (hasBis ? '' : ' no-bis');
        row.innerHTML = `
            <div class="flex-fill">
                <div class="bi-name">${item.nama}</div>
                <div class="bi-code">${hasBis
                    ? `<span style="color:#204EAB;font-weight:700;">${item.bis}</span>`
                    : '<span style="color:#ef4444;"><i class="fas fa-exclamation-triangle me-1" style="font-size:.65rem;"></i>Belum ada BIS barcode — dilewati</span>'
                }</div>
            </div>
            ${hasBis ? `
            <div class="bi-qty">
                <input type="number" class="form-control form-control-sm text-center fw-bold"
                    id="bpqty_${id}" value="1" min="1" max="100"
                    style="width:60px;border-radius:7px;"
                    oninput="updateBulkTotal()">
                <span class="small text-muted" style="flex-shrink:0;">lbr</span>
            </div>` : ''}`;
        list.appendChild(row);
    });
    updateBulkTotal();
    document.getElementById('bulkPrintTotal').style.display = 'flex';
    new bootstrap.Modal(document.getElementById('modalBulkPrint')).show();
}

function updateBulkTotal() {
    let total = 0;
    document.querySelectorAll('[id^="bpqty_"]').forEach(inp => {
        total += parseInt(inp.value) || 0;
    });
    document.getElementById('bulkTotalCount').textContent = total + ' lembar';
}

function doBulkPrint() {
    const items = [];
    selectedIds.forEach((item, id) => {
        if (!item.bis) return;
        const inp = document.getElementById('bpqty_' + id);
        const qty = inp ? (parseInt(inp.value) || 1) : 1;
        items.push({ bis: item.bis, nama: item.nama, qty });
    });
    if (!items.length) {
        Swal.fire({ icon: 'warning', title: 'Tidak ada item yang bisa diprint', text: 'Pastikan item yang dipilih sudah memiliki BIS barcode.' });
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('modalBulkPrint')).hide();

    // Generate label HTML untuk setiap item × qty
    let labelsHTML = '';
    let qrIdx = 0;
    items.forEach(item => {
        const namaShort = item.nama.length > 30 ? item.nama.substring(0, 30) + '…' : item.nama;
        for (let i = 0; i < item.qty; i++) {
            labelsHTML += `<div class="lc" data-bis="${item.bis}">
                <div class="lq" id="qr${qrIdx++}"></div>
                <div class="li">
                    <div class="lcode">${item.bis}</div>
                    <div class="lname">${namaShort}</div>
                </div>
            </div>`;
        }
    });

    const win = window.open('', '_blank', 'width=400,height=300');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
<style>
@page { size: 50mm 30mm; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #fff; }
.lc {
    width: 50mm; height: 30mm;
    display: flex; align-items: center;
    padding: 1.5mm 2mm; gap: 2mm;
    page-break-after: always; break-after: page;
}
.lq { width: 26mm; height: 26mm; flex-shrink: 0; overflow: hidden; position: relative; }
.lq img, .lq canvas { width: 26mm !important; height: 26mm !important; display: block !important; }
.li { flex: 1; overflow: hidden; font-family: Arial, sans-serif; }
.lcode { font-family: 'Courier New', monospace; font-size: 7.5pt; font-weight: 700; color: #1d3d99; }
.lname { font-size: 6.5pt; line-height: 1.3; color: #222; margin-top: 1.5mm; word-break: break-word; }
</style></head><body>${labelsHTML}
<script>
document.querySelectorAll('.lq[id]').forEach(function(el) {
    var bis = el.closest('.lc').dataset.bis;
    new QRCode(el, {
        text: bis, width: 98, height: 98,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
});
setTimeout(function() { window.print(); window.close(); }, 800);
<\/script>
</body></html>`);
    win.document.close();
    win.focus();
}

function dummyImport() {
    const f = document.getElementById('importExcelFile');
    const res = document.getElementById('importResult');
    if (!f.files.length) {
        res.classList.remove('d-none');
        res.innerHTML = '<div class="alert alert-warning small mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Pilih file Excel terlebih dahulu.</div>';
        return;
    }
    res.classList.remove('d-none');
    res.innerHTML = '<div class="alert alert-success small mb-0"><i class="fas fa-check-circle me-1"></i>Import berhasil (dummy — tidak ada perubahan nyata).</div>';
    setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('modalImportMinStok')).hide(); }, 1500);
}
</script>
