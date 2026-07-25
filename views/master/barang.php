<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'petugas_hc', 'cs', 'admin_hc']);
$can_edit   = in_array($role, ['super_admin']);
$can_kelola = in_array($role, ['super_admin', 'admin_gudang']); // can open modal (view + print), but edit only for super_admin
$can_edit_label = in_array($role, ['super_admin', 'admin_gudang']); // Konfigurasi Label (wizard + manual override) juga terbuka untuk admin_gudang

$r_col   = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bis = ($r_col && $r_col->num_rows > 0);

$bis_select = $has_bis ? "
    b.barcode_internal, b.track_ed, b.label_print, b.label_placement, b.label_config_set,
    b.wizard_answers, b.recommended_category, b.final_category, b.override_status,
    b.override_reason, b.override_notes, b.override_by, b.override_at,
    (SELECT COUNT(*) FROM inventory_barcode_vendor bv WHERE bv.barang_id = b.id) AS vendor_count," : "
    NULL AS barcode_internal, 0 AS track_ed, 'none' AS label_print,
    NULL AS label_placement, 0 AS label_config_set,
    NULL AS wizard_answers, NULL AS recommended_category, NULL AS final_category, 0 AS override_status,
    NULL AS override_reason, NULL AS override_notes, NULL AS override_by, NULL AS override_at,
    0 AS vendor_count,";

$q = $conn->query("
    SELECT b.id, b.kode_barang, b.nama_barang, b.satuan, b.kategori, b.stok_minimum,
        b.odoo_product_id, b.uom, b.barcode, b.tipe, $bis_select
        COALESCE(NULLIF(uc.from_uom,''), COALESCE(b.uom,'')) AS uom_odoo,
        COALESCE(NULLIF(uc.to_uom,''), COALESCE(b.satuan,'')) AS uom_operasional,
        COALESCE(uc.multiplier,1) AS uom_ratio
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY nama_barang ASC LIMIT 1000
");
$rows = [];
while ($q && ($r = $q->fetch_assoc())) $rows[] = $r;

$total = count($rows); $bis_unset = 0;
$cat_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach ($rows as $r) {
    if (!$has_bis) continue;
    $cfg_set_r = (int)($r['label_config_set'] ?? 0);
    if (!$cfg_set_r) { $bis_unset++; continue; }

    // Kategori final: pakai final_category kalau sudah ada (hasil wizard/override),
    // else simpulkan dari label_print/label_placement (item lama/hasil import)
    $cat_r = (string)($r['final_category'] ?? '');
    if ($cat_r === '') {
        $lp_r = $r['label_print'] ?? 'none';
        $pl_r = $r['label_placement'] ?? null;
        if ($lp_r === 'none') $cat_r = 'A';
        elseif ($lp_r === 'physical' && $pl_r === 'unit')      $cat_r = 'B';
        elseif ($lp_r === 'physical' && $pl_r === 'box')       $cat_r = 'C';
        elseif ($lp_r === 'physical' && $pl_r === 'catalogue') $cat_r = 'D';
    }
    if (isset($cat_counts[$cat_r])) $cat_counts[$cat_r]++;
}
?>

<div class="row mb-2 align-items-center">
    <div class="col">
        <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;"><i class="fas fa-boxes me-2"></i>Database Barang</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Database Barang</li>
        </ol></nav>
    </div>
    <?php if ($can_edit): ?>
    <div class="col-auto">
        <div class="dropdown">
            <button class="btn btn-primary shadow-sm dropdown-toggle px-4" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-file-excel me-2"></i>Import Data Barang
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm p-3" style="width:310px;border-radius:12px;border:1px solid #e2e8f0;">
                <div class="text-muted small mb-3" style="font-size:.78rem;line-height:1.5;">
                    <i class="fas fa-info-circle me-1 text-primary"></i>
                    Untuk update <strong>Stok Minimum</strong> massal lewat Excel:<br>
                    <span class="ms-3">1. Download template di bawah</span><br>
                    <span class="ms-3">2. Isi kolom <strong>Stok Minimum</strong></span><br>
                    <span class="ms-3">3. Upload file yang sudah diisi</span><br>
                    <span class="ms-3">Kolom <strong>Label Print/Placement</strong>: kosongkan agar tidak berubah, isi <code>-</code> untuk reset Konfigurasi Label ke "Belum diset".</span><br>
                    <span class="ms-3">Sheet <strong>Barcode Vendor</strong> &amp; <strong>Batch ED</strong>: isi baris baru untuk menambah, isi kolom <strong>Hapus</strong> dengan <code>X</code> pada baris yang mau dihapus.</span>
                </div>
                <div class="d-flex flex-column gap-2">
                    <a href="api/export_template_min_stok.php" class="btn btn-outline-secondary btn-sm w-100 text-start" style="border-radius:8px;">
                        <i class="fas fa-download me-2 text-muted"></i>Download Template Excel
                    </a>
                    <button class="btn btn-primary btn-sm w-100 text-start" style="border-radius:8px;"
                        data-bs-toggle="modal" data-bs-target="#modalImportMinStok"
                        onclick="document.querySelector('.dropdown-menu').classList.remove('show');">
                        <i class="fas fa-upload me-2"></i>Upload & Import Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$cat_meta = [
    'A' => ['label' => 'Category A', 'desc' => 'Sistem Saja',           'icon' => 'fa-barcode', 'color' => '#64748b', 'bg' => '#f1f5f9'],
    'B' => ['label' => 'Category B', 'desc' => 'Cetak Fisik (Item)',    'icon' => 'fa-tag',     'color' => '#204EAB', 'bg' => '#eff6ff'],
    'C' => ['label' => 'Category C', 'desc' => 'Cetak Fisik (Box)',     'icon' => 'fa-box',     'color' => '#7c3aed', 'bg' => '#f5f3ff'],
    'D' => ['label' => 'Category D', 'desc' => 'Cetak Fisik (Katalog)', 'icon' => 'fa-book',    'color' => '#b45309', 'bg' => '#fffbeb'],
];
?>
<div class="row g-2 mb-4">
    <div class="col-6 col-md-4 col-lg-2"><div class="card h-100 shadow-sm border-0"><div class="card-body py-2 px-3">
        <div class="text-muted text-uppercase fw-semibold mb-1" style="font-size:.68rem;">Total Barang Odoo</div>
        <div class="h5 mb-0 fw-bold"><?= $total ?></div>
    </div></div></div>
    <?php if ($has_bis): ?>
    <div class="col-6 col-md-4 col-lg-2"><div class="card h-100 shadow-sm border-0"><div class="card-body py-2 px-3">
        <div class="text-muted text-uppercase fw-semibold mb-1" style="font-size:.68rem;">Label Belum Diset</div>
        <div class="h5 mb-0 fw-bold <?= $bis_unset > 0 ? 'text-warning' : 'text-success' ?>"><?= $bis_unset ?></div>
    </div></div></div>
    <?php foreach ($cat_meta as $cat_key => $cm): ?>
    <div class="col-6 col-md-4 col-lg-2"><div class="card h-100 shadow-sm border-0" style="border-left:4px solid <?= $cm['color'] ?>!important;"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 mb-1">
            <div style="width:20px;height:20px;border-radius:6px;background:<?= $cm['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas <?= $cm['icon'] ?>" style="color:<?= $cm['color'] ?>;font-size:.62rem;"></i>
            </div>
            <div class="text-muted text-uppercase fw-semibold" style="font-size:.68rem;"><?= $cm['label'] ?></div>
        </div>
        <div class="h5 mb-0 fw-bold" style="color:<?= $cm['color'] ?>;"><?= $cat_counts[$cat_key] ?></div>
        <div class="text-muted" style="font-size:.68rem;"><?= $cm['desc'] ?></div>
    </div></div></div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!$has_bis): ?>
<div class="alert alert-warning small mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Kolom BIS belum tersedia. Jalankan <a href="scripts/migrate.php" class="fw-bold">scripts/migrate.php</a> untuk mengaktifkan fitur BIS.
</div>
<?php endif; ?>

<style>
/* ── Print label size toggle ──────────────────────────────────── */
.pl-size-btn { border:1.5px solid #e2e8f0;background:#fff;color:#64748b;border-radius:8px;padding:5px 12px;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s; }
.pl-size-btn:hover { border-color:#204EAB;color:#204EAB; }
.pl-size-btn.active { border-color:#204EAB;background:#204EAB;color:#fff; }

/* ── Filter chips ─────────────────────────────────────────────── */
.filter-chip { display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:600;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s; }
.filter-chip:hover  { border-color:#204EAB;color:#204EAB;background:#eff6ff; }
.filter-chip.active { border-color:#204EAB;background:#204EAB;color:#fff; }

/* ── Kelola Modal ─────────────────────────────────────────────── */
.modal-kelola .modal-content { border:0;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18); }
.modal-kelola .modal-header  { background:linear-gradient(135deg,#204EAB,#1a3d8a);border-bottom:none!important;padding:14px 20px!important; }
.modal-kelola .modal-header .btn-close { filter:invert(1) brightness(2);opacity:.8; }
.modal-section-hr { border:0;border-top:1.5px solid #d0d7de;margin:0; }

/* ── Section layout ───────────────────────────────────────────── */
.section-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:14px; }
.section-title  { display:flex;align-items:center;gap:10px;font-weight:700;color:#1e293b;font-size:.95rem; }
.section-icon   { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0; }

/* ── QR card ──────────────────────────────────────────────────── */
.qr-empty-state    { padding:14px 16px;background:#f8faff;border-radius:12px;border:2px dashed #d1d9ef; }
.qr-generated-card { background:#f8faff;border-radius:14px;border:1px solid #e2e8f0;padding:18px 20px; }
.qr-code-string    { font-family:'Courier New',monospace;font-size:1.2rem;font-weight:800;color:#204EAB;letter-spacing:.06em; }
.qr-note           { font-size:.74rem;color:#94a3b8;margin-top:4px;display:flex;align-items:center;gap:4px; }

/* ── Flat list (vendor/ED) ────────────────────────────────────── */
.flat-list-wrap { border:1px solid #f1f5f9;border-radius:10px;padding:0 10px;background:#fff; }
.flat-list-row  { display:flex;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid #f1f5f9;font-size:.85rem; }
.flat-list-row:last-child { border-bottom:none; }
.flat-list-empty { font-size:.8rem;color:#94a3b8;padding:10px 2px; }

/* ── Inline add form ──────────────────────────────────────────── */
.inline-add-form { background:#f8faff;border-radius:10px;border:2px dashed #d1d9ef;padding:14px;margin-bottom:10px; }

/* ── Label config cards ───────────────────────────────────────── */
.label-opt-card { border:2px solid #e2e8f0;border-radius:10px;padding:12px 14px;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:8px;user-select:none; }
.label-opt-card:hover    { border-color:#a5b4fc; }
.label-opt-card.selected { border-color:#204EAB;background:#f0f4ff; }
.placement-opt { display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;border:2px solid #e2e8f0;background:#fff;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .15s;color:#374151; }
.placement-opt:hover    { border-color:#204EAB; }
.placement-opt.selected { border-color:#204EAB;background:#e8f0fd;color:#204EAB; }

/* ── Read-only modal (non-super_admin) ───────────────────────── */
.modal-readonly .edit-only { display:none !important; }
.modal-readonly input:not([type=hidden]), .modal-readonly select, .modal-readonly textarea { pointer-events:none;opacity:.6;background:#f8faff; }
.modal-readonly .form-check-input { pointer-events:none;opacity:.6; }
.modal-readonly .placement-opt, .modal-readonly .tipe-opt { pointer-events:none;opacity:.6;cursor:default; }
.modal-readonly #labelConfigSection button { pointer-events:none;opacity:.5; }
/* Konfigurasi Label tetap bisa diedit oleh admin_gudang meski bagian lain modal read-only */
.modal-readonly.label-editable #labelConfigSection input,
.modal-readonly.label-editable #labelConfigSection select,
.modal-readonly.label-editable #labelConfigSection textarea { pointer-events:auto;opacity:1;background:#fff; }
.modal-readonly.label-editable #labelConfigSection button { pointer-events:auto;opacity:1; }

/* ── Table BIS badges ─────────────────────────────────────────── */
.bis-badge { display:inline-flex;align-items:center;gap:3px;font-size:.68rem;font-weight:600;border-radius:6px;padding:2px 8px;white-space:nowrap; }

/* ── Table compact ────────────────────────────────────────────── */
#barangTable thead th { font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;padding:10px 12px;vertical-align:middle; }
#barangTable tbody td { font-size:.83rem;padding:8px 12px;vertical-align:middle;white-space:nowrap; }
#barangTable tbody td.nama-col { white-space:normal;min-width:180px; }
#barangTable tbody td.kode-col { white-space:normal;word-break:break-all;min-width:60px;max-width:80px; }
#barangTable .tipe-pill { display:inline-block;font-size:.68rem;font-weight:700;border-radius:20px;padding:2px 10px;white-space:nowrap; }
#barangTable .bis-code  { font-size:.78rem;font-weight:700;color:#204EAB;font-family:'Courier New',monospace;letter-spacing:.03em; }
#barangTable .btn-kelola-sm { font-size:.72rem;border-radius:7px;padding:4px 12px;font-weight:600;white-space:nowrap; }
</style>

<!-- Tabs -->
<ul class="nav nav-pills mb-4" role="tablist" style="gap:6px;">
    <li class="nav-item"><button class="nav-link active rounded-pill px-4" data-bs-toggle="pill" data-bs-target="#tab-odoo"><i class="fas fa-boxes me-2"></i>Barang Odoo</button></li>
    <li class="nav-item"><button class="nav-link rounded-pill px-4" data-bs-toggle="pill" data-bs-target="#tab-non-odoo"><i class="fas fa-box-open me-2"></i>Barang Non-Odoo</button></li>
</ul>
<style>.nav-pills .nav-link{color:#6c757d;font-weight:500;}.nav-pills .nav-link.active{background-color:#204EAB!important;color:#fff!important;}</style>

<div class="tab-content">
    <!-- ── Odoo Tab ── -->
    <div class="tab-pane fade show active" id="tab-odoo">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-3">
                <div class="d-flex flex-wrap gap-2" id="filterChips">
                    <button class="filter-chip active" data-filter="all">Semua</button>
                    <?php if ($has_bis): ?>
                    <button class="filter-chip" data-filter="label_unset">Label Belum Diset</button>
                    <button class="filter-chip" data-filter="need_print">Perlu Cetak</button>
                    <button class="filter-chip" data-filter="track_ed">Track ED</button>
                    <div class="dropdown d-inline-block">
                        <button class="filter-chip" id="filterLabelDropBtn" data-bs-toggle="dropdown" aria-expanded="false">
                            <span id="filterLabelTxt">Jenis Label</span> <i class="fas fa-chevron-down ms-1" style="font-size:.6rem;"></i>
                        </button>
                        <ul class="dropdown-menu shadow-sm border-0 py-1" style="border-radius:10px;min-width:150px;font-size:.8rem;">
                            <li><a class="dropdown-item fw-semibold" href="#" data-label-type="">Semua Jenis</a></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item" href="#" data-label-type="sistem"><i class="fas fa-desktop me-2" style="color:#64748b;width:14px;"></i>Sistem Saja</a></li>
                            <li><a class="dropdown-item" href="#" data-label-type="unit"><i class="fas fa-print me-2" style="color:#204EAB;width:14px;"></i>Item</a></li>
                            <li><a class="dropdown-item" href="#" data-label-type="box"><i class="fas fa-print me-2" style="color:#204EAB;width:14px;"></i>Box</a></li>
                            <li><a class="dropdown-item" href="#" data-label-type="catalogue"><i class="fas fa-print me-2" style="color:#204EAB;width:14px;"></i>Katalog</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <button class="filter-chip" data-filter="no_min">Min Stok = 0</button>
                </div>
            </div>
            <div class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable align-middle" id="barangTable" style="width:100%">
                        <thead class="table-light">
                            <tr>
                                <?php if ($has_bis): ?>
                                <th style="width:32px;" class="text-center no-sort"><input type="checkbox" id="chkAll" class="form-check-input" style="cursor:pointer;" title="Pilih semua"></th>
                                <?php endif; ?>
                                <th style="width:80px;">Kode</th>
                                <th>Nama Barang</th>
                                <th style="width:90px;">UOM</th>
                                <th style="width:80px;">Tipe</th>
                                <th style="width:75px;" class="text-end">Min Stok</th>
                                <?php if ($has_bis): ?>
                                <th style="width:105px;">Internal</th>
                                <th style="width:60px;" class="text-center">Vendor</th>
                                <th style="width:70px;" class="text-center">Track ED</th>
                                <th style="width:130px;">Label</th>
                                <?php endif; ?>
                                <th style="width:80px;" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $bis       = (string)($r['barcode_internal'] ?? '');
                            $track_ed  = (int)($r['track_ed'] ?? 0);
                            $lp        = (string)($r['label_print'] ?? 'none');
                            $placement = (string)($r['label_placement'] ?? '');
                            $cfg_set   = (int)($r['label_config_set'] ?? 0);
                            $vcnt      = (int)($r['vendor_count'] ?? 0);
                            $ratio     = (float)($r['uom_ratio'] ?? 1);
                            $uom_str   = htmlspecialchars((string)($r['uom_operasional'] ?? ($r['satuan'] ?? '-')));
                            $ratio_str = ($ratio !== 1.0) ? ' · '.rtrim(rtrim(number_format($ratio,4,'.',''),'0'),'.') : '';
                        ?>
                            <tr
                                data-id="<?= (int)$r['id'] ?>"
                                data-kode="<?= htmlspecialchars((string)($r['kode_barang'] ?? ''),ENT_QUOTES) ?>"
                                data-nama="<?= htmlspecialchars((string)($r['nama_barang'] ?? ''),ENT_QUOTES) ?>"
                                data-min="<?= (int)($r['stok_minimum'] ?? 0) ?>"
                                data-tipe="<?= htmlspecialchars((string)($r['tipe'] ?? ''),ENT_QUOTES) ?>"
                                data-bis="<?= htmlspecialchars($bis,ENT_QUOTES) ?>"
                                data-track-ed="<?= $track_ed ?>"
                                data-label-print="<?= htmlspecialchars($lp,ENT_QUOTES) ?>"
                                data-label-placement="<?= htmlspecialchars($placement,ENT_QUOTES) ?>"
                                data-label-set="<?= $cfg_set ?>"
                                data-vendor-count="<?= $vcnt ?>"
                                data-satuan="<?= htmlspecialchars((string)($r['satuan'] ?? ''), ENT_QUOTES) ?>"
                                data-wizard-answers="<?= htmlspecialchars((string)($r['wizard_answers'] ?? ''), ENT_QUOTES) ?>"
                                data-recommended-category="<?= htmlspecialchars((string)($r['recommended_category'] ?? ''), ENT_QUOTES) ?>"
                                data-final-category="<?= htmlspecialchars((string)($r['final_category'] ?? ''), ENT_QUOTES) ?>"
                                data-override-status="<?= (int)($r['override_status'] ?? 0) ?>"
                                data-override-reason="<?= htmlspecialchars((string)($r['override_reason'] ?? ''), ENT_QUOTES) ?>"
                                data-override-notes="<?= htmlspecialchars((string)($r['override_notes'] ?? ''), ENT_QUOTES) ?>"
                            >
                                <?php if ($has_bis): ?>
                                <td class="text-center" style="padding:8px 6px;">
                                    <input type="checkbox" class="form-check-input chk-item" style="cursor:<?= $bis ? 'pointer' : 'not-allowed' ?>;opacity:<?= $bis ? '1' : '.3' ?>;" <?= !$bis ? 'disabled' : '' ?>>
                                </td>
                                <?php endif; ?>
                                <td class="fw-semibold kode-col" style="font-size:.78rem;color:#374151;"><?= htmlspecialchars((string)($r['kode_barang'] ?? '-')) ?></td>
                                <td class="nama-col"><?= htmlspecialchars((string)($r['nama_barang'] ?? '-')) ?></td>
                                <td class="text-muted" style="font-size:.78rem;"><?= $uom_str ?></td>
                                <td><?php
                                    $t = $r['tipe'] ?? '';
                                    if ($t==='Core')    echo '<span class="tipe-pill" style="background:#fee2e2;color:#b91c1c;">Core</span>';
                                    elseif ($t==='Support') echo '<span class="tipe-pill" style="background:#dbeafe;color:#1d4ed8;">Support</span>';
                                    else echo '<span class="text-muted">-</span>';
                                ?></td>
                                <td class="text-end fw-semibold <?= (int)($r['stok_minimum']??0)===0 ? 'text-muted' : 'text-dark' ?>"><?= (int)($r['stok_minimum']??0) ?></td>
                                <?php if ($has_bis): ?>
                                <td><?= $bis ? '<span class="bis-code">'.htmlspecialchars($bis).'</span>' : '<span class="text-muted">-</span>' ?></td>
                                <td class="text-center" data-order="<?= $vcnt ?>">
                                    <?= $vcnt > 0 ? '<span class="tipe-pill" style="background:#dbeafe;color:#1e40af;">'.$vcnt.'</span>' : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td class="text-center" data-order="<?= $track_ed ?>">
                                    <?= $track_ed ? '<span class="tipe-pill" style="background:#dcfce7;color:#166534;">Ya</span>' : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td><?php
                                    if (!$cfg_set) echo '<span class="bis-badge" style="background:#fff7ed;color:#92400e;">Belum diset</span>';
                                    elseif ($lp==='physical') {
                                        $pl = ['unit'=>'Item','box'=>'Box','catalogue'=>'Katalog'][$placement] ?? $placement;
                                        echo '<span class="bis-badge" style="background:#dcfce7;color:#166534;"><i class="fas fa-print me-1"></i>'.htmlspecialchars($pl).'</span>';
                                    } else echo '<span class="bis-badge" style="background:#f1f5f9;color:#64748b;">Sistem</span>';
                                ?></td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <?php if ($can_kelola): ?>
                                    <button class="btn btn-primary btn-kelola btn-kelola-sm"><i class="fas fa-cog me-1"></i>Kelola</button>
                                    <?php else: ?>
                                    <button class="btn btn-outline-primary btn-print-bis btn-kelola-sm" <?= !$bis ? 'disabled' : '' ?>><i class="fas fa-qrcode me-1"></i>Print</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Non-Odoo Tab ── -->
    <div class="tab-pane fade" id="tab-non-odoo">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">Daftar Barang Non-Odoo (Lokal)</h5>
                <?php if ($can_edit): ?><button class="btn btn-primary btn-sm px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAddItemNonOdoo"><i class="fas fa-plus me-2"></i>Tambah Item</button><?php endif; ?>
            </div>
            <div class="card-body">
                <table id="tableMasterItemNonOdoo" class="table table-hover align-middle datatable" style="width:100%">
                    <thead class="bg-light"><tr>
                        <th>Kode Item</th><th>Nama Item</th><th>UOM</th>
                        <?php if ($can_edit): ?><th class="text-end" style="width:100px;">Aksi</th><?php endif; ?>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Kelola Barang                                                   -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<?php if ($can_kelola): ?>
<div class="modal fade modal-kelola" id="modalKelola" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2 flex-fill min-w-0">
                    <i class="fas fa-qrcode" style="color:rgba(255,255,255,.65);font-size:.85rem;flex-shrink:0;"></i>
                    <code style="font-size:.78rem;color:rgba(255,255,255,.65);flex-shrink:0;" id="kelolaKode"></code>
                    <span class="fw-bold text-white" style="font-size:.95rem;" id="kelolaNama"></span>
                </div>
                <button type="button" class="btn-close ms-3 flex-shrink-0" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4 py-4">
                <input type="hidden" id="kelolaBarangId">

                <!-- ── Section 0: Info Dasar ─────────────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#e8f0fd;"><i class="fas fa-tag" style="color:#204EAB;"></i></div>
                            Info Dasar
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Tipe Barang</label>
                            <div class="d-flex flex-wrap gap-2 mt-1" id="kelolaTipeRadio">
                                <label class="placement-opt tipe-opt" data-val="">
                                    <i class="fas fa-minus" style="font-size:.7rem;color:#94a3b8;"></i> Belum
                                </label>
                                <label class="placement-opt tipe-opt" data-val="Core">
                                    <i class="fas fa-star" style="font-size:.7rem;color:#dc2626;"></i> Core
                                </label>
                                <label class="placement-opt tipe-opt" data-val="Support">
                                    <i class="fas fa-layer-group" style="font-size:.7rem;color:#0ea5e9;"></i> Support
                                </label>
                            </div>
                            <input type="hidden" id="kelolaTipe" value="">
                            <div class="form-text mt-1" style="font-size:.72rem;">Dipakai saat mapping item BHP.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Min Stok</label>
                            <input type="number" class="form-control form-control-sm" id="kelolaMinStok" min="0" step="1" value="0">
                            <div class="form-text" style="font-size:.72rem;">Nilai 0 = tidak ada batas minimum.</div>
                        </div>
                    </div>
                </div>

                <?php if ($has_bis): ?>
                <hr class="modal-section-hr">

                <!-- ── Section 1: BIS Barcode Internal ────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#e8f0fd;"><i class="fas fa-qrcode" style="color:#204EAB;"></i></div>
                            QR Code Internal
                        </div>
                    </div>

                    <!-- Empty state (no BIS code yet) -->
                    <div id="qrNotGenerated" class="qr-empty-state" style="display:none;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:38px;height:38px;border-radius:10px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-qrcode" style="color:#94a3b8;font-size:.9rem;"></i>
                            </div>
                            <div class="flex-fill">
                                <div class="fw-semibold text-muted" style="font-size:.88rem;">Belum Ada QR Internal</div>
                                <div class="text-muted" style="font-size:.75rem;">Akan otomatis dibuat saat Simpan Konfigurasi pertama kali</div>
                            </div>
                        </div>
                    </div>

                    <!-- Generated state -->
                    <div id="qrGeneratedSection" style="display:none;">
                        <div class="qr-generated-card">
                            <div class="row align-items-center g-3">
                                <div class="col-auto">
                                    <div id="qrCodeCanvas" style="padding:8px;background:#fff;border-radius:10px;border:1px solid #e2e8f0;display:inline-block;"></div>
                                </div>
                                <div class="col">
                                    <div class="text-muted small mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Kode QR Internal</div>
                                    <div class="qr-code-string" id="qrCodeString"></div>
                                    <div class="qr-note"><i class="fas fa-lock" style="font-size:.65rem;"></i>Deterministik — tidak akan berubah</div>
                                    <div class="d-flex gap-2 mt-3">
                                        <button class="btn btn-success btn-sm fw-semibold" onclick="printBisLabel()" style="border-radius:8px;padding:8px 16px;font-size:.8rem;">
                                            <i class="fas fa-print me-1"></i>Print Label
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="copyBisCode()" style="border-radius:8px;font-size:.8rem;">
                                            <i class="fas fa-copy me-1"></i>Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── Section 2: Track ED ───────────────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#fef3c7;"><i class="fas fa-calendar-times" style="color:#d97706;"></i></div>
                            Kadaluarsa (ED)
                        </div>
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-1">
                            <input class="form-check-input" type="checkbox" id="kelolaTrackEd" role="switch" style="width:2em;height:1.1em;cursor:pointer;">
                            <label class="form-check-label small fw-semibold" for="kelolaTrackEd" style="color:#374151;">Track ED</label>
                        </div>
                    </div>
                    <div id="edOffMsg"><p class="flat-list-empty mb-0"><i class="fas fa-toggle-off me-1"></i>Aktifkan Track ED untuk mencatat batch kadaluarsa</p></div>
                    <div id="edOnMsg" style="display:none;">
                        <p class="flat-list-empty mb-2 text-success"><i class="fas fa-check-circle me-1"></i>Item ini dicatat tanggal kadaluarsa batch-nya saat inbound</p>
                        <div id="edBatchList"></div>
                    </div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── Section 3: Barcode Vendor ─────────────────────────── -->
                <div class="mb-4">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#dcfce7;"><i class="fas fa-barcode" style="color:#16a34a;"></i></div>
                            Barcode Vendor
                        </div>
                        <button class="btn btn-sm btn-success fw-semibold edit-only" onclick="showAddVendorForm()" style="border-radius:8px;padding:7px 14px;font-size:.8rem;">
                            <i class="fas fa-plus me-1"></i>Tambah
                        </button>
                    </div>

                    <div id="addVendorForm" style="display:none;" class="inline-add-form mb-2">
                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label class="form-label small fw-semibold mb-1">Barcode Vendor <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="vendorBarcodeInput" placeholder="Scan atau ketik..." style="border-radius:7px;font-family:monospace;">
                            </div>
                            <div class="col">
                                <label class="form-label small fw-semibold mb-1">Keterangan <span class="text-muted fw-normal">(opsional)</span></label>
                                <input type="text" class="form-control form-control-sm" id="vendorKeteranganInput" placeholder="Nama vendor, dll" style="border-radius:7px;">
                            </div>
                            <div class="col-auto d-flex gap-2">
                                <button class="btn btn-success btn-sm fw-semibold" onclick="doAddVendorBarcode()" style="border-radius:7px;"><i class="fas fa-check me-1"></i>Simpan</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('addVendorForm').style.display='none'" style="border-radius:7px;">Batal</button>
                            </div>
                        </div>
                    </div>

                    <div id="vendorBarcodeList"></div>
                </div>

                <hr class="modal-section-hr">

                <!-- ── Section 4: Konfigurasi Label ──────────────────────── -->
                <div>
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon" style="background:#eff6ff;"><i class="fas fa-tag" style="color:#204EAB;"></i></div>
                            Konfigurasi Label
                        </div>
                    </div>
                    <div id="labelConfigSection"></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-semibold edit-only" id="btnSimpanKelola" onclick="saveKelola()">
                    <i class="fas fa-save me-2"></i>Simpan Konfigurasi
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Print Label -->
<div class="modal fade" id="modalPrintLabel" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-0" style="border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.45)!important;">
            <div class="modal-header border-0" style="background:#204EAB;padding:16px 20px;">
                <div class="d-flex align-items-center gap-3 flex-fill" style="min-width:0;">
                    <div style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-check" style="color:#fff;font-size:.9rem;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div id="plNama" style="color:#fff;font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                        <div id="plKode" style="color:rgba(255,255,255,.7);font-size:.76rem;margin-top:2px;font-family:monospace;"></div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <span id="plSatuan" class="badge" style="background:rgba(255,255,255,.2);color:#fff;font-size:.72rem;border-radius:6px;padding:4px 9px;font-weight:600;"></span>
                        <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" style="font-size:.75rem;opacity:.8;"></button>
                    </div>
                </div>
            </div>
            <div class="modal-body p-0" style="background:#f8fafc;">
                <!-- Track ED row -->
                <div id="plTrackEdRow" style="display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid #e9eef5;background:#fff;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-calendar-times" style="color:#d97706;font-size:.85rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <span style="font-size:.85rem;font-weight:600;color:#374151;">Track ED</span>
                        <span style="font-size:.83rem;font-weight:400;color:#94a3b8;"> — catat kadaluarsa batch</span>
                    </div>
                    <div id="plTrackEdBadge"></div>
                </div>
                <!-- Konfigurasi Label row -->
                <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 20px;border-bottom:1px solid #e9eef5;background:#fff;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                        <i class="fas fa-tag" style="color:#204EAB;font-size:.85rem;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:7px;">Konfigurasi Label:</div>
                        <div id="plLabelBadges" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                        <div id="plLabelDesc" style="font-size:.75rem;color:#94a3b8;margin-top:5px;"></div>
                    </div>
                </div>
                <!-- Jumlah cetak row -->
                <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;background:#fff;margin-top:8px;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#e0f2fe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-print" style="color:#0284c7;font-size:.85rem;"></i>
                    </div>
                    <div style="flex:1;font-size:.88rem;font-weight:600;color:#374151;">Jumlah cetak:</div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="number" id="plCountInput" min="1" max="100" value="1"
                            style="width:68px;border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 10px;text-align:center;font-size:.9rem;font-weight:600;outline:none;">
                        <span style="font-size:.85rem;color:#64748b;">lembar</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0" style="padding:10px 20px 20px;gap:10px;">
                <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal"
                    style="border-radius:10px;padding:10px 0;flex:1;border:1.5px solid #e2e8f0;font-size:.88rem;">Lewati</button>
                <button type="button" class="btn btn-primary fw-semibold" onclick="executePrintLabel()"
                    style="border-radius:10px;padding:10px 0;flex:2;font-size:.88rem;">
                    <i class="fas fa-print me-2"></i>Print Label
                </button>
            </div>
        </div>
    </div>
</div>
<!-- hidden spans for legacy references -->
<span id="printBisCode"  style="display:none;"></span>
<span id="printBisNama"  style="display:none;"></span>
<span id="printBisKode"  style="display:none;"></span>
<span id="printBisSatuan" style="display:none;"></span>

<!-- Floating multi-print bar -->
<div id="printSelBar" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:1060;background:#204EAB;color:#fff;border-radius:14px;padding:11px 16px;align-items:center;gap:12px;box-shadow:0 8px 32px rgba(0,0,0,.25);white-space:nowrap;">
    <i class="fas fa-print" style="font-size:.9rem;opacity:.8;"></i>
    <span id="printSelCount" style="font-weight:700;font-size:.88rem;">0 item dipilih</span>
    <button onclick="openMultiPrint()" style="background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:8px;padding:5px 14px;font-weight:700;font-size:.82rem;cursor:pointer;">
        Cetak Label
    </button>
    <button onclick="clearPrintSelection()" style="background:none;border:none;color:rgba(255,255,255,.6);padding:4px 6px;cursor:pointer;font-size:.9rem;"><i class="fas fa-times"></i></button>
</div>

<!-- Modal Multi Print -->
<div class="modal fade" id="modalMultiPrint" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:460px;">
        <div class="modal-content border-0" style="border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.3)!important;">
            <div class="modal-header border-0" style="background:#204EAB;padding:16px 20px;">
                <div class="d-flex align-items-center gap-3 flex-fill">
                    <div style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-print" style="color:#fff;font-size:.9rem;"></i>
                    </div>
                    <div>
                        <div style="color:#fff;font-weight:700;font-size:.95rem;">Cetak Multi Label</div>
                        <div id="mpItemCount" style="color:rgba(255,255,255,.7);font-size:.76rem;margin-top:1px;"></div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal" style="opacity:.8;"></button>
            </div>
            <div class="modal-body p-0" style="background:#f8fafc;max-height:55vh;overflow-y:auto;">
                <div id="mpItemList"></div>
            </div>
            <div class="modal-footer border-0 flex-column gap-0" style="padding:12px 20px 18px;background:#fff;">
                <div class="d-flex align-items-center w-100 mb-3" style="gap:10px;">
                </div>
                <div class="d-flex w-100 gap-2">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal"
                        style="border-radius:10px;padding:10px 0;flex:1;border:1.5px solid #e2e8f0;font-size:.88rem;">Batal</button>
                    <button type="button" class="btn btn-primary fw-semibold" onclick="executeMultiPrint()"
                        style="border-radius:10px;padding:10px 0;flex:2;font-size:.88rem;">
                        <i class="fas fa-print me-2"></i>Print Semua
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Non-Odoo modals (can_edit only) -->
<?php if ($can_edit): ?>
<div class="modal fade" id="modalAddItemNonOdoo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg">
        <form id="formAddItemNonOdoo">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-primary-custom" id="nonOdooModalTitle">Tambah Item Non-Odoo</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body py-4">
                <input type="hidden" name="id" id="non_odoo_edit_id">
                <div class="mb-3"><label class="form-label small fw-bold">Nama Item</label><input type="text" name="nama_item" id="non_odoo_edit_nama_item" class="form-control" required></div>
                <div class="mb-0"><label class="form-label small fw-bold">UOM (Satuan)</label><input type="text" name="uom" id="non_odoo_edit_uom" class="form-control" required></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary px-4">Simpan</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="modalImportMinStok" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg">
        <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-primary-custom"><i class="fas fa-file-excel me-2"></i>Import Min Stok</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="formImportMinStok"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>">
            <div class="modal-body py-4">
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle me-1"></i>Download template, isi data, lalu upload di sini.<br>
                    <strong>Sheet 1 – Konfigurasi Barang:</strong> Stok Minimum, Tipe, Track ED (0/1), Label Print (none/physical), Label Placement (unit/box/catalogue). Kolom kosong = tidak berubah.<br>
                    <strong>Sheet 2 – Barcode Vendor:</strong> Tambah baris baru dengan ID Barang + Barcode Vendor untuk bulk import. Barcode yang sudah ada dilewati otomatis.
                </div>
                <div class="mb-0"><label class="form-label small fw-bold">File Excel (.xlsx)</label><input type="file" class="form-control" name="excel_file" accept=".xlsx" required></div>
                <div id="importResult" class="mt-3 d-none"></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button class="btn btn-light" data-bs-dismiss="modal">Tutup</button><button type="submit" class="btn btn-primary px-4" id="btnDoImport"><i class="fas fa-upload me-1"></i>Mulai Import</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const kelolaCanEdit = <?= $can_edit ? 'true' : 'false' ?>;
const kelolaCanEditLabel = <?= $can_edit_label ? 'true' : 'false' ?>;
const csrfToken = <?= json_encode(csrf_token()) ?>;

// ── Helpers ───────────────────────────────────────────────────────────────────
function tipeBadgeHtml(tipe) {
    if (tipe === 'Core')    return '<span class="tipe-pill" style="background:#fee2e2;color:#b91c1c;">Core</span>';
    if (tipe === 'Support') return '<span class="tipe-pill" style="background:#dbeafe;color:#1d4ed8;">Support</span>';
    return '<span class="text-muted">-</span>';
}
function labelConfigBadge(lp, placement, cfgSet) {
    if (!cfgSet) return '<span class="bis-badge" style="background:#fff7ed;color:#92400e;">Belum diset</span>';
    if (lp === 'physical') {
        const pl = {unit:'Item',box:'Box',catalogue:'Katalog'}[placement] || placement;
        return `<span class="bis-badge" style="background:#dcfce7;color:#166534;"><i class="fas fa-print me-1"></i>${pl}</span>`;
    }
    return '<span class="bis-badge" style="background:#f1f5f9;color:#64748b;">Sistem</span>';
}
function trackEdBadge(on) {
    return on ? '<span class="tipe-pill" style="background:#dcfce7;color:#166534;">Ya</span>' : '<span class="text-muted">-</span>';
}
function bisBadge(code) {
    return code ? `<span class="bis-code">${code}</span>` : '<span class="text-muted">-</span>';
}

// ── DataTable + Filter ────────────────────────────────────────────────────────
let activeFilter = 'all', activeLabelType = '';
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    if (settings.nTable.id !== 'barangTable') return true;
    const tr = settings.aoData[dataIndex].nTr;
    if (!tr) return true;
    const $r = $(tr);
    // Label type filter (independent)
    if (activeLabelType) {
        const lp    = $r.attr('data-label-print') || 'none';
        const place = $r.attr('data-label-placement') || '';
        const cfgSet= $r.attr('data-label-set') === '1';
        if (activeLabelType === 'sistem') { if (!(cfgSet && lp === 'none')) return false; }
        else { if (!(lp === 'physical' && place === activeLabelType)) return false; }
    }
    if (activeFilter === 'all') return true;
    if (activeFilter === 'label_unset') return $r.attr('data-label-set') === '0';
    if (activeFilter === 'need_print')  return $r.attr('data-label-print') === 'physical';
    if (activeFilter === 'track_ed')    return $r.attr('data-track-ed') === '1';
    if (activeFilter === 'no_min')      return $r.attr('data-min') === '0';
    return true;
});

$(document).ready(function() {
    window.tableBarang = $('#barangTable').DataTable({
        pageLength: 25,
        order: [[<?php echo $has_bis ? 1 : 0; ?>,'asc']],
        language: {search:'Cari:',lengthMenu:'Tampilkan _MENU_ item',zeroRecords:'Tidak ada data',info:'Halaman _PAGE_ dari _PAGES_',paginate:{previous:'‹',next:'›'}},
        <?php if ($has_bis): ?>
        columnDefs: [{ orderable: false, targets: 0 }],
        drawCallback: function() {
            // Restore checkbox states after pagination/redraw
            this.api().rows({ page: 'current' }).nodes().each(function(tr) {
                const id = $(tr).data('id');
                const chk = tr.querySelector('.chk-item');
                if (chk) chk.checked = !!_printSelection[id];
            });
            // Hitung terhadap baris yang lolos filter aktif saja (bukan seluruh tabel),
            // supaya checkbox "pilih semua" mencerminkan status seleksi per kategori/filter.
            const visibleIds = this.api().rows({ search: 'applied' }).nodes().toArray().map(tr => $(tr).data('id'));
            const total = visibleIds.length;
            const checked = visibleIds.filter(id => _printSelection[id]).length;
            $('#chkAll').prop('indeterminate', checked > 0 && checked < total);
            $('#chkAll').prop('checked', total > 0 && checked === total);
        }
        <?php endif; ?>
    });

    $('#filterChips').on('click', '.filter-chip:not(#filterLabelDropBtn)', function() {
        $('#filterChips .filter-chip').removeClass('active');
        $(this).addClass('active');
        activeFilter = $(this).data('filter');
        // Reset label type filter when switching to other chips
        activeLabelType = '';
        document.getElementById('filterLabelTxt').textContent = 'Jenis Label';
        window.tableBarang.draw();
    });

    // Jenis Label dropdown handler
    $('#filterChips').on('click', '[data-label-type]', function(e) {
        e.preventDefault();
        const type  = $(this).data('label-type');
        const label = type ? $(this).text().trim() : 'Jenis Label';
        activeLabelType = type;
        document.getElementById('filterLabelTxt').textContent = label;
        // Set dropdown btn as active if a type is selected
        const $dropBtn = $('#filterLabelDropBtn');
        if (type) {
            $('#filterChips .filter-chip').removeClass('active');
            activeFilter = 'all';
            $dropBtn.addClass('active');
        } else {
            $dropBtn.removeClass('active');
        }
        window.tableBarang.draw();
    });

    // ── Kelola button ─────────────────────────────────────────────────────────
    $(document).on('click', '.btn-kelola', function() {
        const $r = $(this).closest('tr');
        const id       = $r.attr('data-id');
        const kode     = $r.attr('data-kode') || '';
        const nama     = $r.attr('data-nama') || '';
        const satuan   = $r.attr('data-satuan') || '';
        const min      = $r.attr('data-min') || '0';
        const tipe     = $r.attr('data-tipe') || '';
        const bis      = $r.attr('data-bis') || '';
        const trackEd  = $r.attr('data-track-ed') === '1';
        const lp       = $r.attr('data-label-print') || 'none';
        const placement= $r.attr('data-label-placement') || '';
        const cfgSet   = $r.attr('data-label-set') === '1';
        const wizAnswersRaw = $r.attr('data-wizard-answers') || '';
        const recommendedCat = $r.attr('data-recommended-category') || '';
        const finalCat        = $r.attr('data-final-category') || '';
        const overrideStatus  = $r.attr('data-override-status') === '1';
        const overrideReason  = $r.attr('data-override-reason') || '';
        const overrideNotes   = $r.attr('data-override-notes') || '';

        $('#kelolaBarangId').val(id);
        $('#kelolaKode').text(kode ? '['+kode+']' : '');
        $('#kelolaNama').text(nama);
        // Store for print popup
        document.getElementById('printBisKode').textContent = kode;
        document.getElementById('printBisSatuan').textContent = satuan;
        $('#kelolaMinStok').val(min);
        setTipeRadio(tipe);
        $('#kelolaTrackEd').prop('checked', trackEd);
        toggleEdMsg(trackEd);

        // QR section
        showBisQR(bis);

        // Label config
        renderLabelConfig({
            lp, placement, cfgSet,
            wizardAnswers: wizAnswersRaw,
            recommendedCategory: recommendedCat || null,
            finalCategory: finalCat || null,
            overrideStatus, overrideReason, overrideNotes,
        });

        // Vendor barcodes
        loadVendorBarcodes(id);
        document.getElementById('addVendorForm').style.display = 'none';
        $('#vendorBarcodeInput, #vendorKeteranganInput').val('');

        // ED batches
        loadEdBatches(id);

        // Apply read-only mode for non-super_admin (Konfigurasi Label tetap terbuka utk admin_gudang)
        const mbody = document.querySelector('#modalKelola .modal-body');
        if (mbody) {
            mbody.classList.toggle('modal-readonly', !kelolaCanEdit);
            mbody.classList.toggle('label-editable', kelolaCanEditLabel);
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKelola')).show();
    });

    // Track ED toggle
    $('#kelolaTrackEd').on('change', function() {
        toggleEdMsg(this.checked);
        if (this.checked) {
            const id = document.getElementById('kelolaBarangId').value;
            if (id) loadEdBatches(parseInt(id));
        }
    });

    // Print button (read-only roles) — single item
    $(document).on('click', '.btn-print-bis', function() {
        const $r    = $(this).closest('tr');
        const bis   = $r.attr('data-bis') || '';
        const nama  = $r.attr('data-nama') || '';
        const kode  = $r.attr('data-kode') || '';
        const satuan= $r.attr('data-satuan') || '';
        const lp    = $r.attr('data-label-print') || 'none';
        const place = $r.attr('data-label-placement') || '';
        if (!bis) return;
        showPrintLabelModal(bis, nama, kode, satuan, lp, place);
    });

    // ── Checkbox: select-all ─────────────────────────────────────────────────
    <?php if ($has_bis): ?>
    $('#chkAll').on('change', function() {
        const checked = this.checked;
        window.tableBarang.rows({ search: 'applied' }).nodes().each(function(tr) {
            const $tr = $(tr);
            const id  = $tr.data('id');
            const bis = $tr.attr('data-bis') || '';
            const chk = tr.querySelector('.chk-item');
            if (!chk || chk.disabled) return;
            chk.checked = checked;
            if (checked && bis) {
                _printSelection[id] = {bis, nama:$tr.attr('data-nama')||'', kode:$tr.attr('data-kode')||'', satuan:$tr.attr('data-satuan')||''};
            } else {
                delete _printSelection[id];
            }
        });
        updatePrintSelBar();
    });

    $(document).on('change', '.chk-item', function() {
        const $tr = $(this).closest('tr');
        const id  = $tr.data('id');
        const bis = $tr.attr('data-bis') || '';
        if (this.checked && bis) {
            _printSelection[id] = {bis, nama:$tr.attr('data-nama')||'', kode:$tr.attr('data-kode')||'', satuan:$tr.attr('data-satuan')||''};
        } else {
            delete _printSelection[id];
        }
        updatePrintSelBar();
    });
    <?php endif; ?>

    // ── Non-Odoo DataTable ────────────────────────────────────────────────────
    var tableNonOdoo = $('#tableMasterItemNonOdoo').DataTable({
        ajax: 'api/ajax_bhp_lokal.php?action=list_master',
        columns: [
            {data:'kode_item'},
            {data:'nama_item', render: d => '<b>'+d+'</b>'},
            {data:'uom', render: d => '<span class="badge bg-secondary-light text-dark">'+d+'</span>'},
            <?php if ($can_edit): ?>
            {data:null, orderable:false, className:'text-end', render: d => `<button class="btn btn-sm btn-outline-primary btn-edit-non-odoo" data-id="${d.id}" data-name="${d.nama_item}" data-uom="${d.uom}"><i class="fas fa-edit me-1"></i>Edit</button>`}
            <?php endif; ?>
        ]
    });

    <?php if ($can_edit): ?>
    $('#formAddItemNonOdoo').submit(function(e) {
        e.preventDefault();
        $.post('api/ajax_bhp_lokal.php?action=save_item', $(this).serialize(), function(res) {
            if (res.success) { $('#modalAddItemNonOdoo').modal('hide'); tableNonOdoo.ajax.reload(); Swal.fire('Berhasil',res.message,'success'); }
            else Swal.fire('Gagal',res.message,'error');
        },'json');
    });
    $(document).on('click', '.btn-edit-non-odoo', function() {
        const d = $(this).data();
        $('#non_odoo_edit_id').val(d.id); $('#non_odoo_edit_nama_item').val(d.name); $('#non_odoo_edit_uom').val(d.uom);
        $('#nonOdooModalTitle').text('Edit Item Non-Odoo');
        $('#modalAddItemNonOdoo').modal('show');
    });
    $('#modalAddItemNonOdoo').on('hidden.bs.modal', function() {
        $('#formAddItemNonOdoo')[0].reset(); $('#non_odoo_edit_id').val(''); $('#nonOdooModalTitle').text('Tambah Item Non-Odoo');
    });
    $('#formImportMinStok').on('submit', function(e) {
        e.preventDefault();
        const btn=$('#btnDoImport'), rd=$('#importResult');
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');
        rd.addClass('d-none');
        fetch('api/ajax_import_min_stok.php',{method:'POST',body:new FormData(this)})
            .then(r=>r.json()).then(res=>{
                btn.prop('disabled',false).html('<i class="fas fa-upload me-1"></i> Mulai Import');
                rd.removeClass('d-none');
                if(res.success){rd.html(`<div class="alert alert-success mt-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> ${res.message}</div>`);setTimeout(()=>location.reload(),2000);}
                else rd.html(`<div class="alert alert-danger mt-2 mb-0 small"><i class="fas fa-exclamation-circle me-1"></i> ${res.message}</div>`);
            });
    });
    <?php endif; ?>
});

// ── Tipe radio ────────────────────────────────────────────────────────────────
function setTipeRadio(val) {
    document.getElementById('kelolaTipe').value = val || '';
    document.querySelectorAll('.tipe-opt').forEach(el => {
        el.classList.toggle('selected', el.dataset.val === (val || ''));
    });
}
document.addEventListener('click', function(e) {
    const opt = e.target.closest('.tipe-opt');
    if (!opt) return;
    setTipeRadio(opt.dataset.val);
});

// ── BIS QR section ─────────────────────────────────────────────────────────────
function showBisQR(bis) {
    const notGen  = document.getElementById('qrNotGenerated');
    const genSec  = document.getElementById('qrGeneratedSection');
    if (!notGen) return;
    if (!bis) {
        notGen.style.display  = 'block';
        genSec.style.display  = 'none';
        return;
    }
    notGen.style.display = 'none';
    genSec.style.display = 'block';
    document.getElementById('qrCodeString').textContent = bis;
    const canvas = document.getElementById('qrCodeCanvas');
    canvas.innerHTML = '';
    new QRCode(canvas, {text:bis, width:72, height:72, correctLevel:QRCode.CorrectLevel.M});
}

// ── Track ED toggle msg ────────────────────────────────────────────────────────
function toggleEdMsg(on) {
    document.getElementById('edOffMsg').style.display = on ? 'none' : 'block';
    document.getElementById('edOnMsg').style.display  = on ? 'block' : 'none';
}

function loadEdBatches(barangId) {
    const wrap = document.getElementById('edBatchList');
    if (!wrap) return;
    wrap.innerHTML = '<p class="small text-muted mb-0"><i class="fas fa-spinner fa-spin me-1"></i>Memuat...</p>';
    fetch('api/get_barang_ed.php?barang_id=' + barangId)
        .then(r => r.json())
        .then(res => {
            if (!res.success || res.batches.length === 0) {
                wrap.innerHTML = '<p class="small text-muted mb-0"><i class="fas fa-inbox me-1"></i>Belum ada batch ED tercatat</p>';
                return;
            }
            wrap.innerHTML = '<div class="d-flex flex-wrap gap-2 mt-1">' +
                res.batches.map(b => {
                    const label = formatEDMonth(b.ed_date);
                    return `<span style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">
                               <i class="fas fa-check-circle" style="font-size:10px;"></i>${label}
                               ${b.keterangan ? `<span style="font-weight:400;opacity:.8;">· ${b.keterangan}</span>` : ''}
                           </span>`;
                }).join('') + '</div>';
        })
        .catch(() => {
            wrap.innerHTML = '';
        });
}

function formatEDMonth(val) {
    if (!val) return '-';
    const parts = val.split('-');
    const names = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    if (parts.length === 3) {
        // YYYY-MM-DD → "31 Des 2026"
        return `${parseInt(parts[2])} ${names[parseInt(parts[1]) - 1] || parts[1]} ${parts[0]}`;
    }
    return `${names[parseInt(parts[1]) - 1] || parts[1]} ${parts[0]}`;
}

// ── Label Config Wizard (logika di assets/js/label_config_wizard.js) ───────────
let _selLP = null, _selPlace = null;
let _wizAnswers = new Array(10).fill(null); // Q1..Q10 (true/false/null)
let _lcRecommendedCat = null; // hasil rekomendasi otomatis terakhir (buat audit trail)
let _lcOverrideActive = false;
let _lcOverrideStatusSaved = false; // status tersimpan di DB
let _lcOverrideReasonSaved = '';
let _lcOverrideNotesSaved  = '';
let _lcFinalCategorySaved  = null;
let _lcUsingInferred = false; // kategori berasal dari data lama/import, belum lewat wizard
let _lcInferredLp = null, _lcInferredPlace = null;

function renderLabelConfig(opts) {
    opts = opts || {};
    const { lp, placement, cfgSet, wizardAnswers, recommendedCategory, finalCategory, overrideStatus, overrideReason, overrideNotes } = opts;

    // Prioritas: pakai jawaban wizard tersimpan (JSON) kalau ada, supaya wizard bisa dilanjutkan/diedit persis dari state terakhir
    let parsed = null;
    if (wizardAnswers) {
        try { parsed = JSON.parse(wizardAnswers); } catch (e) { parsed = null; }
    }
    _lcUsingInferred = false;
    if (Array.isArray(parsed) && parsed.length === 10 && parsed.some(v => v !== null)) {
        _wizAnswers = parsed;
    } else if (!cfgSet) {
        _wizAnswers = new Array(10).fill(null);
    } else if (labelConfigInferCategory(lp, placement)) {
        // Kategori sudah diketahui dari data lama/import (belum pernah lewat wizard) — tampilkan langsung, jangan paksa jawab ulang
        _wizAnswers = new Array(10).fill(null);
        _lcUsingInferred = true;
    } else {
        _wizAnswers = new Array(10).fill(null);
    }

    _lcOverrideStatusSaved = !!overrideStatus;
    _lcOverrideReasonSaved = overrideReason || '';
    _lcOverrideNotesSaved  = overrideNotes || '';
    _lcFinalCategorySaved  = finalCategory || null;
    _lcOverrideActive      = _lcOverrideStatusSaved; // langsung buka panel override kalau memang sedang override
    _lcInferredLp = lp; _lcInferredPlace = placement;

    _lcRender();
}

function lcRedoWizard() {
    _lcUsingInferred = false;
    _wizAnswers = new Array(10).fill(null);
    _lcOverrideActive = false;
    _lcRender();
}

function _lcRender() {
    const c = document.getElementById('labelConfigSection');
    if (!c) return;

    let qRowsHtml, catCardsHtml, recommendedCat, inferredBanner = '';
    if (_lcUsingInferred) {
        recommendedCat = labelConfigInferCategory(_lcInferredLp, _lcInferredPlace);
        qRowsHtml = '';
        catCardsHtml = labelConfigCatCardsHtml(recommendedCat);
        inferredBanner = labelConfigInferredBannerHTML('lcRedoWizard');
    } else {
        const parts = labelConfigRenderParts(_wizAnswers, 'lcSetAnswer');
        qRowsHtml = parts.qRowsHtml;
        catCardsHtml = parts.catCardsHtml;
        recommendedCat = parts.state.category;
    }
    _lcRecommendedCat = recommendedCat;

    // Kategori final: hasil override kalau override aktif & kategori dipilih, else rekomendasi otomatis
    let finalCat = recommendedCat;
    let overrideStatus = false;
    const chosenOverrideCat = _lcOverrideActive
        ? (document.getElementById('lcOverrideCategory')?.value || _lcFinalCategorySaved || '')
        : '';
    if (chosenOverrideCat) { finalCat = chosenOverrideCat; overrideStatus = true; }
    if (finalCat) { const ci = LABEL_CONFIG_CATEGORIES[finalCat]; _selLP = ci.lp; _selPlace = ci.place; }
    else { _selLP = null; _selPlace = null; }

    const reasonEl = document.getElementById('lcOverrideReason');
    const notesEl  = document.getElementById('lcOverrideNotes');
    const currentReason = reasonEl ? reasonEl.value : _lcOverrideReasonSaved;
    const currentNotes  = notesEl ? notesEl.value : _lcOverrideNotesSaved;

    const overrideHtml = kelolaCanEditLabel ? labelConfigRenderOverrideHTML({
        active: _lcOverrideActive,
        reason: currentReason,
        notes: currentNotes,
        finalCategory: chosenOverrideCat,
        fnPrefix: 'lc',
    }) : '';

    const statusBadge = recommendedCat
        ? `<div style="margin-bottom:10px;">${labelConfigStatusBadgeHTML(overrideStatus)}</div>`
        : '';

    const saveBtn = (finalCat && kelolaCanEditLabel) ? `<button type="button" onclick="saveLabelConfigOnly()" id="btnSimpanLabelOnly"
        style="width:100%;margin-top:10px;background:#204EAB;color:#fff;border:none;border-radius:8px;padding:8px 0;font-size:.8rem;font-weight:600;cursor:pointer;">
        <i class="fas fa-save me-1"></i>Simpan Konfigurasi Label
    </button>` : '';

    c.innerHTML = `
        ${inferredBanner}
        <div style="margin-bottom:12px;">${qRowsHtml}</div>
        <div style="border-top:1px solid #e2e8f0;padding-top:12px;">
            <div style="font-size:.68rem;color:#94a3b8;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:8px;">Kategori</div>
            ${statusBadge}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">${catCardsHtml}</div>
            ${overrideHtml}
        </div>
        ${saveBtn}`;
}

function lcSetAnswer(qi, answer) {
    _wizAnswers[qi] = _wizAnswers[qi] === answer ? null : answer;
    // Jawaban berubah → kategori final ikut hasil rekomendasi baru, batalkan override yang sedang diedit
    _lcOverrideActive = false;
    _lcRender();
}
function lcReset() {
    _wizAnswers = new Array(10).fill(null);
    _selLP = null; _selPlace = null;
    _lcOverrideActive = false;
    _lcUsingInferred = false;
    _lcRender();
}

// ── Manual Override ─────────────────────────────────────────────────────────
function lcOverrideStart() {
    _lcOverrideActive = true;
    _lcRender();
    document.getElementById('lcOverrideReason')?.focus();
}
function lcOverrideCancel() {
    _lcOverrideActive = false;
    _lcRender();
}
function lcOverrideReasonChange() {
    _lcRender();
}
function lcOverrideCategoryChange() {
    _lcRender();
}

// ── Vendor barcodes ────────────────────────────────────────────────────────────
function loadVendorBarcodes(barangId) {
    document.getElementById('vendorBarcodeList').innerHTML = '<p class="flat-list-empty mb-0">Memuat...</p>';
    fetch('api/vendor_barcodes.php?action=list&barang_id='+barangId)
        .then(r=>r.json()).then(res=>{
            if (!res.success) { document.getElementById('vendorBarcodeList').innerHTML='<p class="flat-list-empty text-danger mb-0">Gagal memuat.</p>'; return; }
            renderVendorList(barangId, res.items);
        });
}
function renderVendorList(barangId, items) {
    const c = document.getElementById('vendorBarcodeList');
    if (!items.length) { c.innerHTML='<p class="flat-list-empty mb-0">Belum ada barcode vendor terdaftar.</p>'; return; }
    c.innerHTML = `<div class="flat-list-wrap">${items.map(it=>`
        <div class="flat-list-row" id="vb_${it.id}">
            <i class="fas fa-barcode" style="color:#94a3b8;flex-shrink:0;"></i>
            <span class="fw-semibold flex-fill" style="font-family:monospace;">${it.barcode}</span>
            ${it.keterangan ? `<span class="text-muted small">${it.keterangan}</span>` : ''}
            ${kelolaCanEdit ? `<button class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="deleteVendorBarcode(${barangId},${it.id})" title="Hapus"><i class="fas fa-trash" style="font-size:.75rem;"></i></button>` : ''}
        </div>`).join('')}</div>`;
}
function showAddVendorForm() {
    const f = document.getElementById('addVendorForm');
    f.style.display = f.style.display==='none' ? 'block' : 'none';
    if (f.style.display!=='none') document.getElementById('vendorBarcodeInput').focus();
}
function doAddVendorBarcode() {
    const barangId   = document.getElementById('kelolaBarangId').value;
    const barcode    = document.getElementById('vendorBarcodeInput').value.trim();
    const keterangan = document.getElementById('vendorKeteranganInput').value.trim();
    if (!barcode) { alert('Barcode tidak boleh kosong.'); return; }
    fetch('api/vendor_barcodes.php?action=add', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({_csrf: csrfToken, barang_id:barangId, barcode, keterangan})
    }).then(r=>r.json()).then(res=>{
        if (res.success) {
            $('#vendorBarcodeInput,#vendorKeteranganInput').val('');
            document.getElementById('addVendorForm').style.display='none';
            loadVendorBarcodes(barangId);
            const $tr = $(`tr[data-id="${barangId}"]`);
            const vcnt = (parseInt($tr.attr('data-vendor-count')||'0')+1);
            $tr.attr('data-vendor-count', vcnt);
            $tr.find('td').eq(7).html(`<span class="tipe-pill" style="background:#dbeafe;color:#1e40af;">${vcnt}</span>`);
        } else alert(res.message||'Gagal menambahkan barcode.');
    });
}
function deleteVendorBarcode(barangId, id) {
    if (!confirm('Hapus barcode vendor ini?')) return;
    fetch('api/vendor_barcodes.php?action=delete', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({_csrf: csrfToken, barang_id:barangId, id})
    }).then(r=>r.json()).then(res=>{
        if (res.success) {
            document.getElementById('vb_'+id)?.remove();
            if (!document.querySelector('[id^="vb_"]')) loadVendorBarcodes(barangId);
            const $tr = $(`tr[data-id="${barangId}"]`);
            const vcnt = Math.max(0, parseInt($tr.attr('data-vendor-count')||'1')-1);
            $tr.attr('data-vendor-count', vcnt);
            $tr.find('td').eq(7).html(vcnt > 0 ? `<span class="tipe-pill" style="background:#dbeafe;color:#1e40af;">${vcnt}</span>` : '<span class="text-muted">-</span>');
        } else alert(res.message||'Gagal menghapus.');
    });
}

// ── Validasi & kumpulkan payload Konfigurasi Label (wizard + override) ─────────
function collectLabelConfigPayload() {
    const lp    = _selLP;
    const place = _selPlace || '';
    if (!lp) return { error: 'Jawab pertanyaan pada Konfigurasi Label hingga satu kategori terpilih (atau gunakan Manual Override) sebelum menyimpan.' };

    let overrideStatus = 0, overrideReason = '', overrideNotes = '', finalCategory = _lcRecommendedCat;
    if (_lcOverrideActive) {
        const catSel = document.getElementById('lcOverrideCategory');
        overrideReason = document.getElementById('lcOverrideReason')?.value || '';
        overrideNotes  = document.getElementById('lcOverrideNotes')?.value.trim() || '';
        finalCategory  = catSel ? catSel.value : '';
        if (!overrideReason) return { error: 'Pilih alasan Manual Override terlebih dahulu.' };
        if (overrideReason === 'Lainnya' && !overrideNotes) return { error: 'Isi keterangan untuk alasan "Lainnya".' };
        if (!finalCategory) return { error: 'Pilih kategori final untuk Manual Override.' };
        overrideStatus = 1;
    }

    return {
        error: null, lp, place,
        wizard_answers: JSON.stringify(_wizAnswers),
        recommended_category: _lcRecommendedCat || '',
        final_category: finalCategory || '',
        override_status: overrideStatus,
        override_reason: overrideReason,
        override_notes: overrideNotes,
    };
}

// ── Save Kelola ────────────────────────────────────────────────────────────────
function saveKelola() {
    const id      = document.getElementById('kelolaBarangId').value;
    const tipe    = document.getElementById('kelolaTipe').value;
    const minStok = document.getElementById('kelolaMinStok').value;
    const trackEd = document.getElementById('kelolaTrackEd')?.checked ? 1 : 0;

    const lc = collectLabelConfigPayload();
    if (lc.error) { Swal.fire('Konfigurasi Label Belum Lengkap', lc.error, 'warning'); return; }

    const btn = document.getElementById('btnSimpanKelola');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...';

    fetch('api/save_bis_config.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: csrfToken,
            barang_id:id, tipe, stok_minimum:minStok, track_ed:trackEd,
            label_print:lc.lp, label_placement:lc.place,
            wizard_answers:lc.wizard_answers, recommended_category:lc.recommended_category,
            final_category:lc.final_category, override_status:lc.override_status,
            override_reason:lc.override_reason, override_notes:lc.override_notes,
        })
    }).then(r=>r.json()).then(res=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Konfigurasi';
        if (res.success) {
            updateRowDOM(id, res.barcode_internal, tipe, minStok, trackEd, lc.lp, lc.place, lc);
            // Show QR if newly generated
            showBisQR(res.barcode_internal);
            Swal.fire({icon:'success',title:'Tersimpan!',text:res.message,timer:1500,showConfirmButton:false})
                .then(()=>bootstrap.Modal.getInstance(document.getElementById('modalKelola'))?.hide());
        } else {
            Swal.fire('Gagal',res.message,'error');
        }
    }).catch(()=>{
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-save me-2"></i>Simpan Konfigurasi';
        Swal.fire('Error','Terjadi kesalahan koneksi.','error');
    });
}

// ── Save Konfigurasi Label saja (dipakai admin_gudang, atau super_admin utk update cepat) ──
function saveLabelConfigOnly() {
    const id = document.getElementById('kelolaBarangId').value;
    const lc = collectLabelConfigPayload();
    if (lc.error) { Swal.fire('Konfigurasi Label Belum Lengkap', lc.error, 'warning'); return; }

    const btn = document.getElementById('btnSimpanLabelOnly');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem;"></span> Menyimpan...'; }

    fetch('api/save_bis_config.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: csrfToken,
            barang_id:id, label_only:1, label_print:lc.lp, label_placement:lc.place,
            wizard_answers:lc.wizard_answers, recommended_category:lc.recommended_category,
            final_category:lc.final_category, override_status:lc.override_status,
            override_reason:lc.override_reason, override_notes:lc.override_notes,
        })
    }).then(r=>r.json()).then(res=>{
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Konfigurasi Label'; }
        if (res.success) {
            updateRowDOM(id, res.barcode_internal, null, null, null, lc.lp, lc.place, lc);
            showBisQR(res.barcode_internal);
            Swal.fire({icon:'success',title:'Tersimpan!',text:res.message,timer:1500,showConfirmButton:false});
        } else {
            Swal.fire('Gagal', res.message || 'Gagal menyimpan.', 'error');
        }
    }).catch(()=>{
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Konfigurasi Label'; }
        Swal.fire('Error','Terjadi kesalahan koneksi.','error');
    });
}

// ── Update tabel DOM langsung tanpa reload ─────────────────────────────────────
function updateRowDOM(id, bisCode, tipe, minStok, trackEd, lp, placement, lc) {
    const $tr = $(`tr[data-id="${id}"]`);
    if (!$tr.length) return;

    // Update data attributes (tipe/minStok/trackEd bisa null saat save label-only — jangan ditimpa)
    const attrs = {'data-bis':bisCode||'','data-label-print':lp,'data-label-placement':placement,'data-label-set':'1'};
    if (tipe    !== null) attrs['data-tipe']     = tipe;
    if (minStok !== null) attrs['data-min']      = minStok;
    if (trackEd !== null) attrs['data-track-ed'] = trackEd;
    if (lc) {
        attrs['data-wizard-answers']       = lc.wizard_answers || '';
        attrs['data-recommended-category'] = lc.recommended_category || '';
        attrs['data-final-category']       = lc.final_category || '';
        attrs['data-override-status']      = lc.override_status ? '1' : '0';
        attrs['data-override-reason']      = lc.override_reason || '';
        attrs['data-override-notes']       = lc.override_notes || '';
    }
    $tr.attr(attrs);

    const $tds = $tr.find('td');
    const hasBis = <?= $has_bis ? 'true' : 'false' ?>;

    // td[0]=Checkbox, td[1]=Kode, td[2]=Nama, td[3]=UOM, td[4]=Tipe, td[5]=MinStok
    if (tipe    !== null) $tds.eq(4).html(tipeBadgeHtml(tipe));
    if (minStok !== null) { const ms = parseInt(minStok)||0; $tds.eq(5).html(`<span class="fw-semibold ${ms===0?'text-muted':'text-dark'}">${ms}</span>`); }

    if (hasBis) {
        // td[6]=BIS, td[7]=Vendor(unchanged), td[8]=Track ED, td[9]=Label Config
        $tds.eq(6).html(bisBadge(bisCode));
        if (trackEd !== null) $tds.eq(8).html(trackEdBadge(trackEd));
        $tds.eq(9).html(labelConfigBadge(lp, placement, 1));
    }

    // Re-draw the DataTable row in place (no full redraw)
    window.tableBarang.row($tr).invalidate('dom').draw(false);
}

// ── Copy BIS code ──────────────────────────────────────────────────────────────
function copyBisCode() {
    const code = document.getElementById('qrCodeString')?.textContent.trim();
    if (!code) return;
    navigator.clipboard.writeText(code).then(()=>{
        Swal.fire({icon:'success',title:'Disalin!',text:code,timer:1200,showConfirmButton:false});
    });
}

// ── Multi-print selection ──────────────────────────────────────────────────────
let _printSelection = {}; // id -> {bis, nama, kode, satuan}

function updatePrintSelBar() {
    const count = Object.keys(_printSelection).length;
    const bar = document.getElementById('printSelBar');
    if (!bar) return;
    document.getElementById('printSelCount').textContent = count + ' item dipilih';
    bar.style.display = count > 0 ? 'flex' : 'none';
}
function clearPrintSelection() {
    _printSelection = {};
    document.querySelectorAll('.chk-item').forEach(c => c.checked = false);
    const ca = document.getElementById('chkAll');
    if (ca) { ca.checked = false; ca.indeterminate = false; }
    updatePrintSelBar();
}
function openMultiPrint() {
    const items = Object.entries(_printSelection)
        .sort((a, b) => (a[1].nama || a[1].bis || '').localeCompare(b[1].nama || b[1].bis || '', 'id'));
    if (!items.length) return;
    const listHtml = items.map(([id, it]) => `
        <div class="mp-item d-flex align-items-center gap-3" data-id="${id}"
            style="padding:13px 20px;border-bottom:1px solid #f1f5f9;background:#fff;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.88rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${it.nama||it.bis}</div>
                <div style="font-family:monospace;font-size:.76rem;color:#204EAB;margin-top:2px;">${it.bis}</div>
                ${it.kode ? '<div style="font-size:.7rem;color:#94a3b8;">kode: '+it.kode+'</div>' : ''}
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <input type="number" class="mp-qty" min="1" max="100" value="1"
                    style="width:60px;border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 8px;text-align:center;font-size:.88rem;font-weight:700;">
                <span style="font-size:.78rem;color:#64748b;">lbr</span>
            </div>
        </div>`).join('');
    document.getElementById('mpItemList').innerHTML = listHtml;
    document.getElementById('mpItemCount').textContent = items.length + ' item';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMultiPrint')).show();
}
function executeMultiPrint() {
    const items = [];
    document.querySelectorAll('#mpItemList .mp-item').forEach(el => {
        const id  = el.dataset.id;
        const it  = _printSelection[id];
        const qty = Math.max(1, Math.min(100, parseInt(el.querySelector('.mp-qty').value) || 1));
        if (it) items.push({ ...it, qty });
    });
    if (!items.length) return;
    const size = '33x15';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMultiPrint')).hide();
    openMultiItemPrintPopup(items, size);
}
function openMultiItemPrintPopup(items, size) {
    size = size || '50x30';
    let pending = items.length;
    const labelsByItem = new Array(items.length).fill('');
    const qrPx = size === '33x15' ? 70 : 130;
    items.forEach((it, idx) => {
        const tmp = document.createElement('div');
        tmp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
        document.body.appendChild(tmp);
        new QRCode(tmp, {text: it.bis, width: qrPx, height: qrPx, correctLevel: QRCode.CorrectLevel.M});
        setTimeout(() => {
            const img = tmp.querySelector('img');
            const src = img ? img.src : '';
            document.body.removeChild(tmp);
            const displayName = it.kode ? `[${it.kode}] ${it.nama||''}` : (it.nama||'');
            const single = `<div class="lc">
  ${src ? `<img class="lq" src="${src}">` : '<div class="lq"></div>'}
  <div class="li">
    <div class="lcode">${it.bis}</div>
    <div class="lname">${displayName}</div>
  </div>
</div>`;
            labelsByItem[idx] = Array(it.qty).fill(single).join('');
            pending--;
            if (pending === 0) _doPrintAllLabels(labelsByItem, size);
        }, 300);
    });
}
function _wrapLabels33(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    const lcs = [...tmp.querySelectorAll('.lc')].map(el => el.outerHTML);
    let out = '';
    for (let i = 0; i < lcs.length; i += 3) {
        const chunk = lcs.slice(i, i + 3);
        while (chunk.length < 3) chunk.push('<div class="lc"></div>');
        out += `<div class="lrow">${chunk.join('')}</div>`;
    }
    return out;
}
function _labelCss(size) {
    if (size === '33x15') return `
@page { size: 103mm 15mm; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #fff; margin: 0; padding: 0; }
.lrow { width: 103mm; height: 13mm; display: flex; flex-direction: row; gap: 5.5mm; page-break-after: always; break-after: page; overflow: hidden; }
.lc { width: 30.7mm; height: 13mm; display: flex; flex-direction: row; align-items: center; padding: 0 1.5mm 0 1mm; gap: 1.5mm; overflow: hidden; }
.lq { width: 11mm; height: 11mm; flex-shrink: 0; }
.lq img { width: 11mm; height: 11mm; display: block; }
.li { flex: 1; display: flex; flex-direction: column; justify-content: center; overflow: hidden; font-family: Arial, sans-serif; }
.lcode { font-family: 'Courier New', monospace; font-size: 6.5pt; font-weight: 700; color: #1d3d99; border-bottom: .25mm solid #1d3d99; padding-bottom: .3mm; margin-bottom: .5mm; }
.lname { font-size: 5.5pt; line-height: 1.3; color: #111; word-break: break-word; }`;
    /* 50×30mm — .lc height 22mm = 30mm page - 8mm Zebra printer top dead zone.
       Content renders CSS 0-22mm → prints at sticker 8-30mm. Consistent across all labels. */
    return `
@page { size: 50mm 30mm; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #fff; margin: 0; padding: 0; }
.lc { width: 50mm; height: 27mm; display: flex; flex-direction: row; align-items: center; padding: 2mm 2.5mm 0 2mm; gap: 2mm; page-break-after: always; break-after: page; overflow: hidden; }
.lq { width: 20mm; height: 20mm; flex-shrink: 0; }
.lq img { width: 20mm; height: 20mm; display: block; }
.li { flex: 1; display: flex; flex-direction: column; justify-content: center; overflow: hidden; font-family: Arial, Helvetica, sans-serif; }
.lcode { font-family: 'Courier New', monospace; font-size: 10.5pt; font-weight: 700; color: #1d3d99; border-bottom: .35mm solid #1d3d99; padding-bottom: .7mm; margin-bottom: .8mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lname { font-size: 8.5pt; line-height: 1.3; color: #111; word-break: break-word; }
.lkode { font-size: 7pt; color: #777; margin-top: .8mm; }
.luom  { font-size: 7pt; font-weight: 700; color: #333; margin-top: .3mm; }`;
}
function _doPrintAllLabels(labelsByItem, size) {
    size = size || '50x30';
    const rawLabels = labelsByItem.join('');
    const totalCnt  = (rawLabels.match(/class="lc"/g) || []).length;
    const allLabels = size === '33x15' ? _wrapLabels33(rawLabels) : rawLabels;
    const rowH = size === '33x15' ? 60 : 115;
    const winH = Math.min(700, 40 + (size === '33x15' ? Math.ceil(totalCnt / 3) : totalCnt) * rowH);
    const win = window.open('', '_blank', `width=420,height=${winH},toolbar=0,menubar=0,scrollbars=1`);
    win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print BIS (${totalCnt}x)</title>
<style>${_labelCss(size)}</style></head><body>${allLabels}</body></html>`);
    win.document.close(); win.focus();
    setTimeout(() => { win.print(); win.close(); }, 600);
}

// ── Print popup window ─────────────────────────────────────────────────────────
let _printCode = '', _printNama = '', _printKode = '', _printUom = '';

function printBisLabel() {
    const code   = document.getElementById('qrCodeString')?.textContent.trim();
    const nama   = document.getElementById('kelolaNama')?.textContent.trim();
    const kode   = document.getElementById('printBisKode')?.textContent.trim() || '';
    const satuan = document.getElementById('printBisSatuan')?.textContent.trim() || '';
    if (!code) { Swal.fire('Info','Simpan konfigurasi terlebih dahulu untuk generate BIS barcode.','info'); return; }
    showPrintLabelModal(code, nama, kode, satuan);
}
function doPrintBisLabel() {
    const code   = document.getElementById('printBisCode')?.textContent.trim();
    const nama   = document.getElementById('printBisNama')?.textContent.trim();
    const kode   = document.getElementById('printBisKode')?.textContent.trim() || '';
    const satuan = document.getElementById('printBisSatuan')?.textContent.trim() || '';
    if (!code) return;
    showPrintLabelModal(code, nama, kode, satuan);
}
function showPrintLabelModal(code, nama, kode, satuan, lp, placement) {
    _printCode = code; _printNama = nama; _printKode = kode; _printUom = satuan;

    document.getElementById('plNama').textContent = nama || code;
    document.getElementById('plKode').textContent = kode || code;
    const satuanEl = document.getElementById('plSatuan');
    satuanEl.textContent = satuan || '';
    satuanEl.style.display = satuan ? '' : 'none';

    // Track ED badge
    const trackEd = document.getElementById('kelolaTrackEd')?.checked;
    document.getElementById('plTrackEdBadge').innerHTML = trackEd
        ? '<span style="background:#dcfce7;color:#16a34a;padding:3px 10px;border-radius:6px;font-size:.75rem;font-weight:700;">Aktif</span>'
        : '<span style="background:#f1f5f9;color:#94a3b8;padding:3px 10px;border-radius:6px;font-size:.75rem;font-weight:600;">Nonaktif</span>';

    // Konfigurasi Label badges — use passed lp/placement or fall back to _selLP/_selPlace (can_edit)
    const effectiveLp    = (lp !== undefined && lp !== null) ? lp : (typeof _selLP !== 'undefined' ? _selLP : 'none');
    const effectivePlace = (placement !== undefined && placement !== null) ? placement : (typeof _selPlace !== 'undefined' ? _selPlace : null);
    let badges = '', desc = '';
    if (effectiveLp === 'physical') {
        badges += '<span style="background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:700;">Cetak Fisik</span>';
        if (effectivePlace) {
            const lbl = {unit:'Item',box:'Box',outer:'Outer',catalogue:'Katalog'}[effectivePlace] || effectivePlace;
            badges += '<span style="background:#eff6ff;color:#204EAB;padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:700;">' + lbl + '</span>';
            desc = '→ qty per ' + lbl.toLowerCase() + ' masuk';
        }
    } else {
        badges = '<span style="background:#f1f5f9;color:#64748b;padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:600;">Sistem Saja</span>';
        desc = 'Tidak perlu label fisik';
    }
    document.getElementById('plLabelBadges').innerHTML = badges;
    document.getElementById('plLabelDesc').textContent  = desc;

    document.getElementById('plCountInput').value = 1;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintLabel')).show();
}
// Size toggle handler (shared by both modals)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.pl-size-btn');
    if (!btn) return;
    const group = btn.closest('[id$="SizeBtns"]');
    if (!group) return;
    group.querySelectorAll('.pl-size-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
});

function executePrintLabel() {
    const count = Math.max(1, Math.min(100, parseInt(document.getElementById('plCountInput').value) || 1));
    const size  = '33x15';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintLabel')).hide();
    openBisPrintPopup(_printCode, _printNama, _printKode, _printUom, count, size);
}
function openBisPrintPopup(code, nama, kode, uom, count, size) {
    count = Math.max(1, Math.min(100, parseInt(count) || 1));
    size  = size || '50x30';
    const qrPx = size === '33x15' ? 70 : 130;
    const tmp = document.createElement('div');
    tmp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
    document.body.appendChild(tmp);
    new QRCode(tmp, {text:code, width:qrPx, height:qrPx, correctLevel:QRCode.CorrectLevel.M});
    setTimeout(()=>{
        const img = tmp.querySelector('img');
        const src = img ? img.src : '';
        document.body.removeChild(tmp);
        const displayName = kode ? `[${kode}] ${nama||''}` : (nama||'');
        const labelHtml = `<div class="lc">
  ${src ? `<img class="lq" src="${src}">` : '<div class="lq"></div>'}
  <div class="li">
    <div class="lcode">${code}</div>
    <div class="lname">${displayName}</div>
  </div>
</div>`;
        const rawLabels = Array(count).fill(labelHtml).join('');
        const allLabels = size === '33x15' ? _wrapLabels33(rawLabels) : rawLabels;
        const rowH = size === '33x15' ? 60 : 115;
        const winH = Math.min(600, 40 + (size === '33x15' ? Math.ceil(count / 3) : count) * rowH);
        const win = window.open('', '_blank', `width=400,height=${winH},toolbar=0,menubar=0,scrollbars=1`);
        win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print BIS (${count}x)</title>
<style>${_labelCss(size)}</style></head><body>${allLabels}</body></html>`);
        win.document.close(); win.focus();
        setTimeout(()=>{ win.print(); win.close(); }, 500);
    }, 300);
}
</script>
