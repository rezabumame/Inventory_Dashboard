<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik']);
$csrf = csrf_token();

$r_col   = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bis = ($r_col && $r_col->num_rows > 0);

$bis_sel = $has_bis
    ? "b.barcode_internal, b.track_ed, b.label_print, b.label_placement, b.label_config_set,
       b.wizard_answers, b.recommended_category, b.final_category, b.override_status,
       b.override_reason, b.override_notes,"
    : "NULL AS barcode_internal, 0 AS track_ed, 'none' AS label_print, NULL AS label_placement, 0 AS label_config_set,
       NULL AS wizard_answers, NULL AS recommended_category, NULL AS final_category, 0 AS override_status,
       NULL AS override_reason, NULL AS override_notes,";

// ── All items ──────────────────────────────────────────────────────────────────
$qAll = $conn->query("
    SELECT b.id, b.kode_barang, b.nama_barang, b.satuan,
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           $bis_sel b.odoo_product_id
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY b.nama_barang ASC LIMIT 2000
");
$all_items = [];
while ($qAll && ($r = $qAll->fetch_assoc())) {
    $r['id']              = (int)$r['id'];
    $r['track_ed']        = (int)($r['track_ed'] ?? 0);
    $r['label_config_set']= (int)($r['label_config_set'] ?? 0);
    $r['override_status']  = (int)($r['override_status'] ?? 0);
    $all_items[] = $r;
}

// ── Barcode lookup map (barcode → item) ────────────────────────────────────────
$barcode_db        = [];
$items_track_ed_map= [];
$kode2id_map       = [];
$items_by_id       = [];

foreach ($all_items as $it) {
    $id = (int)$it['id'];
    $items_by_id[$id] = $it;
    $items_track_ed_map[$id] = (bool)(int)($it['track_ed'] ?? 0);
    $kode2id_map[$it['kode_barang']] = $id;
    if (!empty($it['barcode_internal'])) {
        $barcode_db[$it['barcode_internal']] = $it;
    }
}
// Vendor barcodes
$vendor_barcode_list        = []; // flat list (lowercase) for JS Set
$items_with_vendor_barcode  = []; // barang_id → true (item sudah punya vendor barcode)
$qVend = $conn->query("SELECT barcode, barang_id FROM inventory_barcode_vendor");
while ($qVend && ($rv = $qVend->fetch_assoc())) {
    $bid = (int)$rv['barang_id'];
    if (isset($items_by_id[$bid])) {
        $barcode_db[$rv['barcode']] = $items_by_id[$bid];
    }
    $vendor_barcode_list[$bid][] = strtolower($rv['barcode']);
    $items_with_vendor_barcode[$bid] = true;
}
// Flatten vendor_barcode_list back to flat array for JS Set
$vendor_barcode_list_flat = [];
foreach ($vendor_barcode_list as $barcodes) {
    foreach ($barcodes as $bc) $vendor_barcode_list_flat[] = $bc;
}

// ── Active ED batches only (barang_id → [{id,date,ket}]) ─────────────────────
$active_ed = [];
if ($has_bis) {
    $today_sql = date('Y-m-d');
    $qEd = $conn->query("SELECT id, barang_id, DATE_FORMAT(ed_month,'%Y-%m-%d') AS ed_date, keterangan
        FROM inventory_barang_ed WHERE ed_month >= '$today_sql' ORDER BY ed_month ASC");
    while ($qEd && ($red = $qEd->fetch_assoc())) {
        $active_ed[(int)$red['barang_id']][] = [
            'id'  => (int)$red['id'],
            'date' => $red['ed_date'],
            'ket' => (string)($red['keterangan'] ?? ''),
        ];
    }
}


?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

/* ── Base ─────────────────────────────────────────────────────────────────── */
body, .inbound-wrap { font-family: 'Inter', sans-serif; }

/* ── Scan Zone ────────────────────────────────────────────────────────────── */
.scan-zone-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(32,78,171,.10);
    overflow: hidden;
}
.scan-zone-header {
    background: linear-gradient(135deg, #ffffff 0%, #e8f0fd 100%);
    padding: 12px 20px;
    border-bottom: 1px solid #e8f0fd;
    display: flex;
    align-items: center;
    gap: 12px;
}
.scan-icon-ring {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(32,78,171,.08), 0 2px 8px rgba(32,78,171,.15);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: box-shadow .3s;
}
.scan-icon-ring.pulse {
    animation: scanPulse 1.8s ease-in-out infinite;
}
.scan-icon-ring.flash-green {
    box-shadow: 0 0 0 8px rgba(34,197,94,.3), 0 0 0 16px rgba(34,197,94,.12);
    background: #f0fdf4;
}
.scan-icon-ring.flash-red {
    box-shadow: 0 0 0 8px rgba(245,158,11,.3), 0 0 0 16px rgba(245,158,11,.12);
    background: #fffbeb;
}
@keyframes scanPulse {
    0%, 100% { box-shadow: 0 0 0 6px rgba(32,78,171,.08), 0 2px 12px rgba(32,78,171,.15); }
    50%       { box-shadow: 0 0 0 14px rgba(32,78,171,.05), 0 4px 24px rgba(32,78,171,.20); }
}
.scan-input-field {
    font-family: 'Courier New', monospace;
    font-size: 1.1rem;
    font-weight: 600;
    letter-spacing: .04em;
    border-radius: 12px !important;
    border: 2px solid #dee2e6 !important;
    padding: 14px 18px !important;
    transition: border-color .2s, box-shadow .2s;
}
.scan-input-field:focus {
    border-color: #204EAB !important;
    box-shadow: 0 0 0 4px rgba(32,78,171,.12) !important;
}
.scan-btn {
    border-radius: 12px !important;
    padding: 14px 20px !important;
    background: #204EAB;
    border: none;
    font-weight: 600;
}
.scan-btn:hover { background: #1a3e8c; }

/* ── Status Strip ─────────────────────────────────────────────────────────── */
.scan-status {
    border-radius: 10px;
    padding: 10px 16px;
    font-size: .85rem;
    font-weight: 600;
    display: flex; align-items: center; gap: 8px;
    transition: all .25s;
}
.scan-status.idle    { background: #f8f9fa; color: #6c757d; border: 1px solid #e9ecef; }
.scan-status.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.scan-status.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

/* ── Result Card ──────────────────────────────────────────────────────────── */
.result-card {
    border-radius: 14px;
    border: 0;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
    overflow: hidden;
    transition: all .3s;
}
.result-card.found   { border-left: 5px solid #22c55e; }
.result-card.unknown { border-left: 5px solid #f59e0b; }
.result-item-name  { font-size: 1.1rem; font-weight: 700; color: #1e293b; line-height: 1.3; }
.result-item-code  { display: inline-block; background: #e8f0fd; color: #204EAB; font-family: monospace; font-size: .8rem; font-weight: 700; border-radius: 6px; padding: 2px 10px; margin-top: 4px; }
.result-item-satuan { color: #64748b; font-size: .85rem; margin-top: 6px; }
.result-icon-circle {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ── History Feed ─────────────────────────────────────────────────────────── */
.history-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(32,78,171,.08);
}
.history-feed {
    max-height: 420px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #e2e8f0 transparent;
}
.history-feed::-webkit-scrollbar { width: 4px; }
.history-feed::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.history-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    transition: background .15s;
}
.history-row:last-child { border-bottom: none; }
.history-row:hover { background: #f8faff; }
.hist-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.hist-dot.known   { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.hist-dot.unknown { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
.hist-time  { font-size: .75rem; color: #94a3b8; white-space: nowrap; width: 60px; flex-shrink: 0; }
.hist-code  { font-family: monospace; font-size: .78rem; color: #475569; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hist-name  { flex: 1; font-size: .82rem; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hist-badge { flex-shrink: 0; }

/* ── Demo Chips ───────────────────────────────────────────────────────────── */
.demo-strip {
    border-radius: 14px;
    background: #f8faff;
    border: 1px solid #e2e8f0;
    padding: 14px 18px;
    overflow: hidden;
    position: relative;
    z-index: 0;
}
.demo-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fff;
    border: 1px solid #d1d9ef;
    border-radius: 20px;
    padding: 4px 12px;
    font-family: monospace;
    font-size: .78rem;
    color: #204EAB;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.demo-chip:hover {
    background: #204EAB;
    color: #fff;
    border-color: #204EAB;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(32,78,171,.25);
}
.demo-chip.unknown-chip {
    color: #92400e;
    border-color: #fcd34d;
    background: #fffbeb;
}
.demo-chip.unknown-chip:hover {
    background: #f59e0b;
    color: #fff;
    border-color: #f59e0b;
}

/* ── Search dropdown ──────────────────────────────────────────────────────── */
.search-result-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 16px; cursor: pointer;
    border-bottom: 1px solid #f1f5f9; transition: background .12s;
}
.search-result-item:last-child { border-bottom: none; }
.search-result-item:hover, .search-result-item.active { background: #f0f4ff; }
.search-result-item .sri-icon {
    width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
    background: #e8f0fd; display: flex; align-items: center; justify-content: center;
    font-size: .75rem; color: #204EAB;
}
.search-result-item .sri-name { font-weight: 600; font-size: .88rem; color: #1e293b; line-height: 1.3; }
.search-result-item .sri-meta { font-size: .75rem; color: #94a3b8; margin-top: 1px; }
.search-result-item .sri-odoo { font-family: monospace; font-size: .72rem; background: #f1f5f9; border-radius: 4px; padding: 1px 6px; color: #475569; }
.search-result-item mark { background: #fef08a; color: inherit; border-radius: 2px; padding: 0 1px; }

/* ── Modal Mapping ────────────────────────────────────────────────────────── */
.mapping-modal-header {
    background: #204EAB;
    border-bottom: none;
    padding: 12px 18px;
    border-radius: 16px 16px 0 0;
}
.mapping-steps {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 20px;
}
.mapping-step {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}
.step-num {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700;
    flex-shrink: 0;
}
.step-num.active  { background: #f59e0b; color: #fff; }
.step-num.done    { background: #22c55e; color: #fff; }
.step-num.pending { background: #e2e8f0; color: #94a3b8; }
.step-label { font-size: .78rem; font-weight: 600; }
.step-label.active  { color: #92400e; }
.step-label.pending { color: #94a3b8; }
.step-divider { flex: 0 0 20px; height: 2px; background: #e2e8f0; margin: 0 4px; }
.step-divider.done { background: #22c55e; }

.item-select-option {
    padding: 10px 14px;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    transition: border-color .2s;
}
.item-select-option:focus {
    border-color: #204EAB !important;
    box-shadow: 0 0 0 3px rgba(32,78,171,.1) !important;
}

/* Custom searchable dropdown */
.custom-select-wrap { position: relative; }
.custom-select-trigger {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 14px;
    background: #fff;
    transition: border-color .2s;
}
.custom-select-trigger:hover { border-color: #204EAB; }
.custom-select-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0; right: 0;
    background: #fff;
    border: 2px solid #204EAB;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(32,78,171,.15);
    z-index: 9999;
    overflow: hidden;
}
.custom-select-options {
    max-height: 220px;
    overflow-y: auto;
}
.custom-select-option {
    padding: 9px 14px;
    cursor: pointer;
    font-size: .88rem;
    color: #1e293b;
    transition: background .12s;
    border-bottom: 1px solid #f8f9fa;
}
.custom-select-option:last-child { border-bottom: none; }
.custom-select-option:hover { background: #f0f4ff; }
.custom-select-option.selected { background: #e8f0fd; font-weight: 600; }
.custom-select-option.hidden { display: none; }
.ed-toggle-card {
    border-radius: 12px;
    border: 2px solid #e2e8f0;
    transition: border-color .25s, background .25s;
    overflow: hidden;
}
.ed-toggle-card.active-ed {
    border-color: #f59e0b;
    background: #fffbeb;
}
.ed-section-body {
    border-top: 1px solid #fde68a;
    padding: 16px;
    animation: slideDown .2s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}
.ed-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    border: 2px solid #e2e8f0;
    background: #fff;
    font-size: .83rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
}
.ed-pill:has(input:checked),
.ed-chip.selected {
    border-color: #204EAB;
    background: #e8f0fd;
    color: #204EAB;
}
.ed-pill .status-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #22c55e;
}

/* ── Print Modal ──────────────────────────────────────────────────────────── */
.print-preview-area {
    background: #f8faff;
    border-radius: 12px;
    padding: 20px;
    min-height: 100px;
}
.print-btn-large {
    padding: 12px 36px;
    font-size: 1rem;
    font-weight: 700;
    border-radius: 12px;
    background: #204EAB;
    border: none;
}
.print-btn-large:hover { background: #1a3e8c; }

/* ── Label size toggle ───────────────────────────────────────────────────── */
.pl-size-btn { border:1.5px solid #e2e8f0;background:#fff;color:#64748b;border-radius:8px;padding:5px 12px;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s; }
.pl-size-btn:hover { border-color:#204EAB;color:#204EAB; }
.pl-size-btn.active { border-color:#204EAB;background:#204EAB;color:#fff; }

/* ── Label card (print) ───────────────────────────────────────────────────── */
.label-card {
    width: 200px;
    height: 120px;
    border: 1px dashed #adb5bd;
    border-radius: 4px;
    padding: 5px;
    background: #fff;
    display: flex !important;
    flex-direction: row !important;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    box-sizing: border-box;
    flex-shrink: 0;
}
.label-qr {
    flex: 0 0 88px;
    width: 88px;
    height: 88px;
    position: relative;
    overflow: hidden;
}
.label-qr * {
    width: 88px !important;
    height: 88px !important;
    display: block !important;
    position: absolute !important;
    top: 0 !important; left: 0 !important;
}
.label-info {
    flex: 1;
    min-width: 0;
    text-align: left;
    overflow: hidden;
    position: relative;
    z-index: 1;
}
.label-info .l-code { font-family: monospace; font-size: 11px; font-weight: 700; color: #204EAB; border-bottom: 1px solid #204EAB; padding-bottom: 2px; margin-bottom: 3px; }
.label-info .l-name { font-size: 10px; line-height: 1.3; color: #333; word-break: break-word; margin-top: 2px; }

/* print handled via popup window — doPrint() */
</style>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1 fw-bold" style="color:#204EAB; font-family:'Inter',sans-serif;">
            <i class="fas fa-barcode me-2"></i>Inbound — Scan Barcode
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:.82rem;">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none" style="color:#204EAB;">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php?page=qr_master_barcode" class="text-decoration-none" style="color:#204EAB;">Master Barcode</a></li>
                <li class="breadcrumb-item active">Inbound Scan</li>
            </ol>
        </nav>
    </div>
    <span class="badge fw-semibold px-3 py-2" style="border-radius:20px;background:#e8f0fd;color:#204EAB;">
        <i class="fas fa-database me-1"></i><?= count($all_items) ?> item
    </span>
</div>

<!-- ── Scan Zone (full width) ──────────────────────────────────────────────── -->
<div class="card scan-zone-card mb-4">
    <div class="scan-zone-header">
        <div class="scan-icon-ring pulse" id="scanIconRing">
            <i class="fas fa-barcode" style="color:#204EAB;font-size:.95rem;"></i>
        </div>
        <div>
            <div class="fw-bold" style="color:#1e293b;font-size:.95rem;line-height:1.2;">Input Item Inbound</div>
            <div class="text-muted" style="font-size:.78rem;">Scan barcode atau cari item secara manual</div>
        </div>
    </div>
    <div class="card-body px-4 pb-4 pt-3">
        <!-- Tab switcher -->
        <div class="d-flex justify-content-center mb-4">
            <div class="d-flex gap-1 p-1" style="background:#f1f5f9;border-radius:12px;">
                <button id="tabOdoo" onclick="switchTab('odoo')"
                    class="btn btn-sm fw-semibold px-4"
                    style="border-radius:9px;background:#204EAB;color:#fff;border:none;">
                    <i class="fas fa-cloud-download-alt me-1"></i>Tarik Odoo
                </button>
                <button id="tabScan" onclick="switchTab('scan')"
                    class="btn btn-sm fw-semibold px-4"
                    style="border-radius:9px;background:transparent;color:#64748b;border:none;">
                    <i class="fas fa-barcode me-1"></i>Scan Barcode
                </button>
                <button id="tabCari" onclick="switchTab('cari')"
                    class="btn btn-sm fw-semibold px-4"
                    style="border-radius:9px;background:transparent;color:#64748b;border:none;">
                    <i class="fas fa-search me-1"></i>Cari Item
                </button>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">

                <!-- Panel: Scan Barcode -->
                <div id="panelScan" style="display:none;">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control scan-input-field"
                            id="scanInput"
                            placeholder="Scan atau ketik barcode..."
                            autocomplete="off">
                        <button class="btn scan-btn text-white" onclick="processScan()" title="Proses scan">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div class="scan-status idle" id="scanStatus">
                        <i class="fas fa-circle-dot" style="font-size:.7rem;"></i>
                        <span id="scanStatusText">Menunggu scan...</span>
                    </div>
                </div>

                <!-- Panel: Cari Item -->
                <div id="panelCari" style="display:none;">
                    <div class="position-relative mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0" style="border-radius:10px 0 0 10px;border:2px solid #d1d9ef;">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control scan-input-field border-start-0 ps-0"
                                id="searchInput"
                                placeholder="Nama atau kode barang (mis: NaCl / 15)..."
                                autocomplete="off"
                                oninput="filterSearchResults(this.value)"
                                onkeydown="onSearchKeydown(event)"
                                style="border-radius:0 10px 10px 0;border:2px solid #d1d9ef;border-left:none;">
                        </div>
                        <!-- Dropdown hasil pencarian -->
                        <div id="searchDropdown"
                            style="display:none;position:fixed;z-index:99999;
                                   background:#fff;border:1.5px solid #d1d9ef;border-radius:0 0 12px 12px;
                                   box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;">
                        </div>
                    </div>
                    <div class="scan-status idle" id="searchStatus">
                        <i class="fas fa-circle-dot" style="font-size:.7rem;"></i>
                        <span>Ketik nama atau kode barang...</span>
                    </div>
                </div>

                <!-- Panel: Tarik Odoo -->
                <div id="panelOdoo">
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-white border-end-0" style="border-radius:10px 0 0 10px;border:2px solid #d1d9ef;">
                            <i class="fas fa-cloud text-muted"></i>
                        </span>
                        <input type="text" class="form-control scan-input-field border-start-0 border-end-0 ps-0"
                            id="odooRefInput"
                            placeholder="Nomor referensi (mis: WHS01/IN/00031)..."
                            autocomplete="off"
                            onkeydown="if(event.key==='Enter'){event.preventDefault();fetchOdooPicking();}"
                            style="border:2px solid #d1d9ef;border-left:none;border-right:none;">
                        <button class="btn scan-btn text-white" onclick="fetchOdooPicking()" id="btnFetchOdoo" title="Tarik data">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                    <div class="scan-status idle" id="odooStatus">
                        <i class="fas fa-circle-dot" style="font-size:.7rem;"></i>
                        <span>Masukkan nomor referensi picking dari Odoo (Receipts / Internal Transfers).</span>
                    </div>
                    <div id="odooResultWrap" style="display:none;" class="mt-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <div class="fw-bold small" id="odooPickingName" style="color:#1e293b;"></div>
                                <div class="text-muted" style="font-size:.74rem;" id="odooPickingState"></div>
                            </div>
                            <span class="badge" id="odooItemCount" style="background:#e8f0fd;color:#204EAB;font-size:.74rem;"></span>
                        </div>
                        <div id="odooItemList" class="d-flex flex-column gap-2" style="max-height:320px;overflow-y:auto;"></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ── Two-column: Result + History ────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Left: Last Scan Result -->
    <div class="col-lg-5">


        <!-- Empty state (default) -->
        <div id="resultEmpty" class="card border-0 shadow-sm h-100 d-flex align-items-center justify-content-center" style="border-radius:14px; min-height:180px;">
            <div class="text-center py-5 px-4">
                <div style="width:64px;height:64px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                    <i class="fas fa-barcode fa-xl" style="color:#cbd5e1;"></i>
                </div>
                <div class="fw-semibold text-muted" style="font-size:.9rem;">Belum ada scan</div>
                <div class="text-muted mt-1" style="font-size:.8rem;">Hasil scan terakhir akan tampil di sini</div>
            </div>
        </div>

        <!-- Result card (muncul setelah scan) -->
        <div class="card result-card" id="resultCard" style="display:none;">
            <div class="card-body p-4" id="resultBody"></div>

            <!-- Print Section -->
            <div id="printSection" style="display:none;" class="border-top px-4 pb-4 pt-3" style="background:#fafbff;">
                <div class="fw-semibold small mb-3 d-flex align-items-center gap-2">
                    <i class="fas fa-print text-primary"></i> Print Label QR
                </div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted mb-0">Jumlah:</label>
                        <input type="number" id="printQty" value="1" min="1" max="100"
                            class="form-control form-control-sm text-center fw-bold"
                            style="width:70px; border-radius:8px;">
                        <label class="small text-muted mb-0">lembar</label>
                    </div>
                    <button class="btn btn-primary fw-semibold ms-auto" onclick="openPrintModal()"
                        style="border-radius:10px; padding:8px 20px;">
                        <i class="fas fa-print me-2"></i>Print Label
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Scan History Feed -->
    <div class="col-lg-7">
        <div class="card history-card h-100">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:36px;height:36px;border-radius:10px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-history" style="color:#204EAB;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:.95rem; color:#1e293b;">Riwayat Scan Sesi Ini</div>
                        <div class="text-muted" style="font-size:.75rem;">Terbaru di atas</div>
                    </div>
                </div>
                <button class="btn btn-sm btn-outline-secondary fw-semibold" onclick="clearHistory()"
                    style="border-radius:8px; font-size:.78rem;">
                    <i class="fas fa-trash me-1"></i>Clear
                </button>
            </div>
            <div class="card-body p-0">
                <!-- Empty state history -->
                <div id="historyEmpty" class="text-center py-5 px-4">
                    <div style="width:56px;height:56px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <i class="fas fa-history fa-lg" style="color:#cbd5e1;"></i>
                    </div>
                    <div class="fw-semibold text-muted" style="font-size:.88rem;">Belum ada riwayat scan</div>
                    <div class="text-muted mt-1" style="font-size:.78rem;">Setiap scan akan tercatat di sini</div>
                </div>

                <!-- Feed list -->
                <div class="history-feed" id="historyFeed" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Print Label                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPrintLabel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header border-0 px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:42px;height:42px;border-radius:12px;background:#e8f0fd;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-print" style="color:#204EAB;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" style="color:#fff;">Print Label QR</h5>
                        <div style="font-size:.78rem;color:rgba(255,255,255,.75);">Preview sebelum cetak ke printer label</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-2">
                <div class="alert border-0 d-flex align-items-center gap-2 mb-4"
                    style="background:#eff6ff; color:#1d4ed8; border-radius:10px; font-size:.84rem;">
                    <i class="fas fa-info-circle"></i>
                    Preview label di bawah. Klik <strong>Print Sekarang</strong> untuk cetak ke printer label.
                </div>
                <div class="print-preview-area">
                    <div id="printArea" class="d-flex flex-wrap gap-3 justify-content-start"></div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-3 d-flex gap-2 justify-content-end">
                <button class="btn btn-outline-secondary fw-semibold" data-bs-dismiss="modal"
                    style="border-radius:10px; padding:10px 24px;">
                    <i class="fas fa-times me-1"></i>Tutup
                </button>
                <button class="btn print-btn-large text-white" onclick="doPrint()">
                    <i class="fas fa-print me-2"></i>Print Sekarang
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Barcode Tidak Dikenal — Mapping                                    -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalMapping" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:visible;">

            <!-- Header -->
            <div class="mapping-modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-exclamation-triangle" style="color:#fbbf24;font-size:.88rem;"></i>
                    <div>
                        <div class="fw-bold" style="color:#fff;font-size:.92rem;">Barcode Tidak Dikenal</div>
                        <div style="font-size:.73rem;color:rgba(255,255,255,.7);">Petakan ke item yang sesuai</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1) brightness(2);"></button>
            </div>

            <div class="modal-body px-3 py-3">

                <!-- Unknown barcode compact display -->
                <div class="d-flex align-items-center gap-2 mb-3 px-2 py-2"
                    style="background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
                    <i class="fas fa-barcode" style="color:#d97706;font-size:.9rem;flex-shrink:0;"></i>
                    <code id="unknownBarcodeText" style="font-size:.92rem;font-weight:700;color:#1e293b;"></code>
                </div>

                <!-- Pilih Item -->
                <div class="mb-1">
                    <label class="form-label fw-semibold small mb-2" style="color:#374151;">
                        Pilih item yang sesuai:
                    </label>

                    <!-- Custom searchable dropdown -->
                    <div class="custom-select-wrap" id="customSelectWrap">
                        <div class="custom-select-trigger form-control d-flex align-items-center justify-content-between"
                            id="customSelectTrigger" onclick="toggleCustomSelect()" style="cursor:pointer;">
                            <span id="customSelectLabel" class="text-muted">— Pilih Item —</span>
                            <i class="fas fa-chevron-down small text-muted"></i>
                        </div>
                        <div class="custom-select-dropdown" id="customSelectDropdown" style="display:none;">
                            <div class="p-2">
                                <input type="text" class="form-control form-control-sm"
                                    id="customSelectSearch"
                                    placeholder="Cari item..."
                                    oninput="filterCustomSelect(this.value)"
                                    onclick="event.stopPropagation()">
                            </div>
                            <div class="custom-select-options" id="customSelectOptions">
                                <div class="custom-select-option text-muted" data-value="" data-kode="" data-track-ed="0"
                                    onclick="selectCustomOption(this)">— Pilih Item —</div>
                                <?php foreach ($all_items as $item): ?>
                                <div class="custom-select-option"
                                    data-value="<?= (int)$item['id'] ?>"
                                    data-kode="<?= htmlspecialchars((string)$item['kode_barang']) ?>"
                                    data-track-ed="<?= (int)$item['track_ed'] ?>"
                                    data-name="<?= htmlspecialchars(strtolower($item['kode_barang'] . ' ' . $item['nama_barang'])) ?>"
                                    onclick="selectCustomOption(this)">
                                    <span class="badge me-1" style="background:#e8f0fd;color:#204EAB;font-weight:600;font-size:.72rem;">
                                        <?= htmlspecialchars((string)$item['kode_barang']) ?>
                                    </span>
                                    <?= htmlspecialchars((string)$item['nama_barang']) ?>
                                    <?php if ((int)$item['track_ed']): ?>
                                        <i class="fas fa-calendar-times ms-1" style="color:#f59e0b;font-size:.7rem;" title="Track ED"></i>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Hidden select untuk kompatibilitas JS yang sudah ada -->
                    <select id="selectMapping" style="display:none;" onchange="onMappingSelectChange()">
                        <option value="">— Pilih Item —</option>
                        <?php foreach ($all_items as $item): ?>
                            <option value="<?= (int)$item['id'] ?>"
                                data-track-ed="<?= (int)$item['track_ed'] ?>"
                                data-kode="<?= htmlspecialchars((string)$item['kode_barang']) ?>">
                                <?= htmlspecialchars($item['kode_barang'] . ' — ' . $item['nama_barang']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Track ED + Label config — compact rows -->
                <div class="mt-3 d-flex flex-column gap-2" id="edToggleCard">
                    <!-- Track ED row -->
                    <div class="d-flex align-items-center gap-2 px-2 py-2"
                        style="background:#f8faff;border-radius:8px;border:1px solid #e2e8f0;">
                        <i class="fas fa-calendar-times" style="color:#f59e0b;font-size:.82rem;flex-shrink:0;"></i>
                        <div class="flex-fill">
                            <span class="fw-semibold small" style="color:#374151;">Track ED</span>
                            <span class="text-muted ms-1" style="font-size:.73rem;">— catat kadaluarsa batch</span>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="toggleMappingED"
                                role="switch" onchange="onMappingEDToggle()" style="width:2em;height:1.1em;cursor:pointer;">
                        </div>
                    </div>

                    <!-- ED section body (collapsible) -->
                    <div id="mappingEDSection" style="display:none;"
                        class="px-2 py-2" style="background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
                        <div id="existingEDWrap">
                            <div class="small fw-semibold mb-1" style="color:#374151;">ED berjalan:</div>
                            <div id="existingEDList" class="d-flex flex-wrap gap-1 mb-2"></div>
                        </div>
                        <div id="newEDWrap">
                            <div class="row g-2">
                                <div class="col-auto">
                                    <input type="date" id="mappingEDInput" class="form-control form-control-sm"
                                        min="<?= date('Y-m-d') ?>" oninput="onNewEDInput()" style="border-radius:7px;">
                                </div>
                                <div class="col">
                                    <input type="text" id="mappingEDKet" class="form-control form-control-sm"
                                        placeholder="No. batch / lot" required style="border-radius:7px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Label config hint — muncul saat item dipilih -->
                    <div id="mappingLabelConfig"
                        class="align-items-center gap-2 px-2 py-2"
                        style="display:none;background:#f8faff;border-radius:8px;border:1px solid #e2e8f0;">
                        <i class="fas fa-tag" style="color:#204EAB;font-size:.78rem;flex-shrink:0;"></i>
                        <span class="small fw-semibold" style="color:#374151;">Label:</span>
                        <div id="mappingLabelConfigBody"></div>
                    </div>

                    <!-- Jumlah cetak (muncul saat label_print=physical) -->
                    <div id="mappingPrintQtyRow"
                        class="align-items-center gap-2 px-2 py-2"
                        style="display:none;background:#eef2ff;border-radius:8px;border:1px solid #c7d2fe;">
                        <i class="fas fa-print" style="color:#204EAB;font-size:.82rem;flex-shrink:0;"></i>
                        <span class="small fw-semibold" style="color:#374151;">Jumlah cetak:</span>
                        <input type="number" id="mappingPrintQty" value="1" min="1" max="100"
                            class="form-control form-control-sm text-center fw-bold"
                            style="width:65px;border-radius:7px;">
                        <span class="small text-muted">lembar</span>
                    </div>

                </div>

                <div class="text-muted mt-2" style="font-size:.73rem;">
                    <i class="fas fa-info-circle me-1"></i>Barcode ini akan otomatis dikenali di scan berikutnya.
                </div>

                <script>const activeEDByItemId = <?= json_encode($active_ed) ?>;</script>
            </div>

            <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2" style="border-radius:0 0 16px 16px;background:#fff;">
                <button class="btn btn-outline-secondary fw-semibold flex-fill" data-bs-dismiss="modal"
                    style="border-radius:10px;">
                    Lewati
                </button>
                <button id="mappingSaveBtn" class="btn fw-bold flex-fill text-white" onclick="saveMapping()"
                    style="background:#f59e0b; border:none; border-radius:10px;">
                    <i class="fas fa-save me-2"></i>Simpan Mapping
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Item Ditemukan — Print Label                                       -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPrintPopup" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">

            <!-- Header: biru, item info + label badge -->
            <div class="d-flex align-items-center justify-content-between" style="background:#204EAB;padding:12px 18px;">
                <div class="d-flex align-items-center gap-2 flex-fill min-w-0">
                    <div id="printModalIconWrap"
                        style="width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.15);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                        <i id="printModalIcon" class="fas fa-check" style="color:#6ee7b7;font-size:.78rem;"></i>
                    </div>
                    <div class="min-w-0">
                        <div id="printModalName" class="fw-bold text-white text-truncate" style="font-size:.92rem;"></div>
                        <div id="printModalSub" style="font-size:.73rem;color:rgba(255,255,255,.65);"></div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 ms-2 flex-shrink-0">
                    <div id="printModalBadge"></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                        style="filter:invert(1) brightness(2);"></button>
                </div>
            </div>

            <div class="modal-body px-3 py-3 d-flex flex-column gap-2">

                <!-- Track ED toggle -->
                <label for="printEDToggle" id="printEDToggleCard"
                    class="d-flex align-items-center gap-2 px-2 py-2 mb-0"
                    style="background:#f8faff;border-radius:8px;border:1px solid #e2e8f0;cursor:pointer;user-select:none;">
                    <i class="fas fa-calendar-times" style="color:#f59e0b;font-size:.82rem;flex-shrink:0;"></i>
                    <div class="flex-fill">
                        <span class="fw-semibold small" style="color:#374151;">Track ED</span>
                        <span class="text-muted ms-1" style="font-size:.73rem;">— catat kadaluarsa batch</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="printEDToggle"
                            role="switch" style="width:2em;height:1.1em;cursor:pointer;" onchange="applyPrintEDToggle()">
                    </div>
                </label>

                <!-- ED section (matches modalMapping style) -->
                <div id="printEDSection" style="display:none;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;"
                    class="px-2 py-2">
                    <div id="printExistingEDWrap">
                        <div class="small fw-semibold mb-1" style="color:#374151;">ED berjalan:</div>
                        <div id="printExistingEDList" class="d-flex flex-wrap gap-1 mb-2"></div>
                    </div>
                    <div id="printNewEDWrap">
                        <div class="row g-2">
                            <div class="col-auto">
                                <input type="date" id="printEDInput" class="form-control form-control-sm"
                                    min="<?= date('Y-m-d') ?>" oninput="onPrintNewEDInput()" style="border-radius:7px;">
                            </div>
                            <div class="col">
                                <input type="text" id="printEDKet" class="form-control form-control-sm"
                                    placeholder="No. batch / lot" required style="border-radius:7px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Label config row -->
                <div id="printLabelRow" class="align-items-center gap-2 px-2 py-2"
                    style="display:none;background:#f8faff;border-radius:8px;border:1px solid #e2e8f0;">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="fas fa-tag" style="color:#204EAB;font-size:.78rem;flex-shrink:0;"></i>
                        <span class="small fw-semibold" style="color:#374151;">Konfigurasi Label:</span>
                    </div>
                    <div id="printLabelBody"></div>
                </div>

                <!-- Jumlah cetak -->
                <div id="printQtyRow" class="align-items-center gap-2 px-2 py-2"
                    style="display:flex;background:#f8faff;border-radius:8px;border:1px solid #e2e8f0;">
                    <i class="fas fa-print" style="color:#204EAB;font-size:.82rem;flex-shrink:0;"></i>
                    <span class="small fw-semibold" style="color:#374151;">Jumlah cetak:</span>
                    <input type="number" id="printQtyModal" value="1" min="1" max="100"
                        class="form-control form-control-sm text-center fw-bold"
                        style="width:65px;border-radius:7px;">
                    <span class="small text-muted">lembar</span>
                </div>

                <!-- Ukuran label -->

                <!-- Scan barcode vendor (mode konfirmasi, gantikan jumlah cetak) -->
                <div id="printScanRow" style="display:none;background:#fff7ed;border-radius:8px;border:1px solid #fdba74;"
                    class="align-items-center gap-2 px-2 py-2">
                    <i class="fas fa-barcode" style="color:#ea580c;font-size:.9rem;flex-shrink:0;"></i>
                    <div class="flex-fill">
                        <div class="small fw-semibold mb-1" style="color:#374151;">Scan barcode vendor untuk konfirmasi:</div>
                        <input type="text" id="printScanVendorInput" class="form-control form-control-sm"
                            placeholder="Scan atau ketik barcode vendor..." autocomplete="off"
                            onkeydown="if(event.key==='Enter'){event.preventDefault();confirmScannedVendorBarcode();}"
                            style="border-radius:7px;">
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0 px-3 pb-3 pt-1 d-flex gap-2">
                <button class="btn btn-outline-secondary fw-semibold flex-fill" data-bs-dismiss="modal"
                    style="border-radius:10px;">Lewati</button>
                <button class="btn fw-bold flex-fill text-white" id="printFooterBtn" onclick="onPrintFooterBtnClick()"
                    style="background:#204EAB;border:none;border-radius:10px;">
                    <i class="fas fa-print me-2"></i>Print Label
                </button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const csrfToken = '<?= $csrf ?>';
const CURRENT_ROLE = <?= json_encode($_SESSION['role'] ?? '') ?>;
const canEditLabelInbound = ['super_admin', 'admin_gudang'].includes(CURRENT_ROLE);
const dummyDB = <?= json_encode($barcode_db) ?>; // barcode → item (BIS + vendor)
const itemTrackEdMap = <?= json_encode($items_track_ed_map) ?>; // id → bool
const kode2id = <?= json_encode($kode2id_map) ?>; // kode_barang → id
const allItems = <?= json_encode($all_items) ?>; // semua item untuk search
const vendorBarcodeSet      = new Set(<?= json_encode($vendor_barcode_list_flat ?? []) ?>);
const itemsWithVendorBarcode = new Set(<?= json_encode(array_keys($items_with_vendor_barcode ?? [])) ?>);
let scanHistory = [];
let searchHighlightIdx = -1;

// ── Tab switcher ──────────────────────────────────────────────────────────────
function switchTab(tab, pushUrl = true) {
    document.getElementById('panelScan').style.display = tab === 'scan' ? 'block' : 'none';
    document.getElementById('panelCari').style.display = tab === 'cari' ? 'block' : 'none';
    document.getElementById('panelOdoo').style.display = tab === 'odoo' ? 'block' : 'none';

    const btns = { scan: document.getElementById('tabScan'), cari: document.getElementById('tabCari'), odoo: document.getElementById('tabOdoo') };
    Object.keys(btns).forEach(k => {
        const active = k === tab;
        btns[k].style.background = active ? '#204EAB' : 'transparent';
        btns[k].style.color = active ? '#fff' : '#64748b';
    });

    if (pushUrl) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        history.replaceState(null, '', url.toString());
    }

    if (tab === 'scan') {
        document.getElementById('scanInput').focus();
    } else if (tab === 'cari') {
        setTimeout(() => document.getElementById('searchInput').focus(), 50);
    } else if (tab === 'odoo') {
        setTimeout(() => document.getElementById('odooRefInput').focus(), 50);
    }
}

// Restore tab dari URL — default ke Tarik Odoo kalau tidak ada di URL
(function() {
    const t = new URLSearchParams(window.location.search).get('tab');
    switchTab((t && ['scan','cari','odoo'].includes(t)) ? t : 'odoo', false);
})();

// ── HTML escape helper (barcode/nama produk bisa berasal dari QR/Odoo — jangan dipercaya mentah) ──
function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

// ── Search logic ──────────────────────────────────────────────────────────────
function highlight(text, q) {
    if (!q) return text;
    const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi');
    return text.replace(re, '<mark>$1</mark>');
}

function positionSearchDropdown() {
    const input = document.getElementById('searchInput');
    const dd    = document.getElementById('searchDropdown');
    const rect  = input.getBoundingClientRect();
    dd.style.top   = rect.bottom + 'px';
    dd.style.left  = rect.left + 'px';
    dd.style.width = rect.width + 'px';
}

function filterSearchResults(q) {
    const dd = document.getElementById('searchDropdown');
    const status = document.getElementById('searchStatus');
    searchHighlightIdx = -1;

    if (!q || q.trim().length < 1) {
        dd.style.display = 'none';
        status.innerHTML = '<i class="fas fa-circle-dot" style="font-size:.7rem;"></i> Ketik nama atau kode barang...';
        return;
    }

    const term = q.trim().toLowerCase();
    const results = allItems.filter(it =>
        it.nama_barang.toLowerCase().includes(term) ||
        it.kode_barang.toLowerCase().includes(term)
    );

    if (!results.length) {
        dd.innerHTML = `<div class="text-center text-muted py-3" style="font-size:.85rem;">
            <i class="fas fa-search-minus me-1"></i>Tidak ada item ditemukan</div>`;
        positionSearchDropdown(); dd.style.display = 'block';
        status.innerHTML = '<i class="fas fa-exclamation-circle me-1" style="color:#f59e0b;"></i>Tidak ditemukan';
        return;
    }

    dd.innerHTML = results.map((it, i) => `
        <div class="search-result-item" data-idx="${i}"
            onclick="selectSearchItem(${it.id})"
            onmouseenter="searchHighlightIdx=${i};highlightSearchItem()">
            <div class="sri-icon"><i class="fas fa-box"></i></div>
            <div>
                <div class="sri-name">
                    <span class="text-muted me-1" style="font-size:.8rem;font-weight:600;">${highlight(it.kode_barang, q.trim())}</span>
                    ${highlight(it.nama_barang, q.trim())}
                </div>
                <div class="sri-meta">
                    <span class="sri-odoo">${highlight('BIS-' + String(it.kode_barang).padStart(4,'0'), q.trim())}</span>
                    <span class="ms-2">${it.satuan}</span>
                    ${it.track_ed ? '<span class="ms-2" style="color:#f59e0b;"><i class="fas fa-calendar-times" style="font-size:.7rem;"></i> Track ED</span>' : ''}
                </div>
            </div>
        </div>`).join('');
    dd.dataset.results = JSON.stringify(results.map(it => it.id));
    positionSearchDropdown(); dd.style.display = 'block';
    status.innerHTML = `<i class="fas fa-circle-dot" style="font-size:.7rem;color:#22c55e;"></i> ${results.length} item ditemukan`;
}

function highlightSearchItem() {
    document.querySelectorAll('.search-result-item').forEach((el, i) => {
        el.classList.toggle('active', i === searchHighlightIdx);
    });
}

function onSearchKeydown(e) {
    const dd = document.getElementById('searchDropdown');
    if (dd.style.display === 'none') return;
    const items = dd.querySelectorAll('.search-result-item');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        searchHighlightIdx = Math.min(searchHighlightIdx + 1, items.length - 1);
        highlightSearchItem();
        items[searchHighlightIdx]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        searchHighlightIdx = Math.max(searchHighlightIdx - 1, 0);
        highlightSearchItem();
        items[searchHighlightIdx]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (searchHighlightIdx >= 0) {
            items[searchHighlightIdx]?.click();
        } else if (items.length === 1) {
            items[0]?.click();
        }
    } else if (e.key === 'Escape') {
        dd.style.display = 'none';
    }
}

function selectSearchItem(itemId) {
    const it = allItems.find(x => x.id == itemId); // loose equality: handles string "76" vs number 76
    if (!it) return;
    window._activeOdooRowIdx = null;
    window._activeOdooItem = null;

    // Tutup dropdown
    document.getElementById('searchDropdown').style.display = 'none';
    document.getElementById('searchInput').value = it.nama_barang;

    // Bangun item object seperti dari dummyDB
    const item = {
        kode_barang: it.kode_barang,
        nama_barang: it.nama_barang,
        satuan: it.satuan,
        track_ed: it.track_ed,
        label_print: it.label_print ?? null,
        label_placement: it.label_placement ?? null,
        label_config_set: it.label_config_set ?? false,
        _mapId: it.id,
    };

    const now = new Date().toLocaleTimeString('id-ID');
    currentFoundItem = item;
    currentFoundBarcode = 'BIS-' + String(it.kode_barang).padStart(4, '0');
    showResult(true, currentFoundBarcode, item);
    addHistory(now, 'BIS-' + String(it.kode_barang).padStart(4,'0') + ' / ' + it.nama_barang, it.nama_barang, true);

    // Popup berdasarkan konfigurasi label
    handleItemAction(item);

    // Reset search
    document.getElementById('searchInput').value = '';
    document.getElementById('searchStatus').innerHTML =
        '<i class="fas fa-check-circle me-1" style="color:#22c55e;"></i>' + it.nama_barang;
}

// Tutup dropdown saat klik di luar
document.addEventListener('click', function(e) {
    if (!e.target.closest('#panelCari')) {
        const dd = document.getElementById('searchDropdown');
        if (dd) dd.style.display = 'none';
    }
});
let currentUnknown = '';
let currentFoundItem = null;
let currentFoundBarcode = '';

document.getElementById('scanInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); processScan(); }
});

function simulateScan(code) {
    document.getElementById('scanInput').value = code;
    processScan();
}

// ── Tarik referensi Odoo (read-only RPC) ───────────────────────────────────────
async function fetchOdooPicking() {
    const refInput = document.getElementById('odooRefInput');
    const ref = refInput.value.trim();
    const statusEl  = document.getElementById('odooStatus');
    const resultWrap = document.getElementById('odooResultWrap');
    const btn = document.getElementById('btnFetchOdoo');

    if (!ref) {
        Swal.fire({ icon: 'warning', title: 'Referensi kosong', text: 'Masukkan nomor referensi picking Odoo.' });
        return;
    }

    statusEl.className = 'scan-status idle';
    statusEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:.7rem;"></i><span>Menarik data dari Odoo...</span>';
    resultWrap.style.display = 'none';
    btn.disabled = true;

    try {
        const res = await fetch('api/odoo_picking_lookup.php?ref=' + encodeURIComponent(ref));
        const data = await res.json();

        if (!data.success) {
            statusEl.className = 'scan-status warning';
            statusEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="font-size:.7rem;"></i><span>' + (data.message || 'Gagal menarik data.') + '</span>';
            return;
        }

        statusEl.className = 'scan-status success';
        statusEl.innerHTML = '<i class="fas fa-check-circle" style="font-size:.7rem;"></i><span>Data ditemukan — ' + data.items.length + ' baris produk.</span>';

        document.getElementById('odooPickingName').textContent = data.picking.name;
        document.getElementById('odooPickingState').textContent = data.picking.state ? ('Status: ' + data.picking.state) : '';
        document.getElementById('odooItemCount').textContent = data.items.length + ' item';

        window._currentOdooRef = ref;
        const processedKodes = data.processed_kodes || {};
        const list = document.getElementById('odooItemList');
        list.innerHTML = '';
        window._currentOdooItems = data.items;
        data.items.forEach((item, idx) => {
            const match = matchLocalItem(item.nama);
            const configBadge = buildOdooConfigBadge(match);
            const alreadyDone = match && processedKodes.hasOwnProperty(String(match.kode_barang));
            const doneBadge   = alreadyDone
                ? `<span style="font-size:.7rem;color:#16a34a;background:#dcfce7;border-radius:6px;padding:2px 8px;">
                       <i class="fas fa-check-circle me-1"></i>Sudah Diproses
                   </span>`
                : '';

            const row = document.createElement('div');
            row.id = 'odooRow_' + idx;
            row.className = 'd-flex align-items-center gap-2 px-3 py-2';
            row.style.cssText = 'background:#f8faff;border:1px solid #e2e8f0;border-radius:10px;transition:opacity .2s;';
            if (alreadyDone) row.style.opacity = '0.75';
            row.innerHTML = `
                <div class="flex-fill">
                    <div class="small fw-semibold" style="color:#1e293b;">${escHtml(item.nama)}</div>
                    <div class="text-muted" style="font-size:.72rem;">Demand: ${escHtml(item.demand)} · Qty: ${escHtml(item.qty)} ${escHtml(item.uom)}</div>
                    <div class="mt-1 d-flex gap-2 flex-wrap">${configBadge}${doneBadge}</div>
                </div>
                <button class="btn btn-sm btn-primary fw-semibold btn-proses-odoo" style="border-radius:8px;font-size:.74rem;flex-shrink:0;"
                    onclick='processOdooItem(${JSON.stringify(item).replace(/'/g, "&apos;")}, ${idx})'>
                    <i class="fas fa-arrow-right me-1"></i>Proses
                </button>`;
            list.appendChild(row);
        });

        resultWrap.style.display = 'block';
    } catch (e) {
        statusEl.className = 'scan-status warning';
        statusEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="font-size:.7rem;"></i><span>Error: ' + e.message + '</span>';
    } finally {
        btn.disabled = false;
    }
}

function markOdooItemProcessed(idx) {
    if (idx === null || idx === undefined) return;
    const row = document.getElementById('odooRow_' + idx);
    if (!row) return;
    row.style.opacity = '0.6';
    row.style.background = '#f8fafc';
    const btn = row.querySelector('.btn-proses-odoo');
    if (btn) {
        btn.outerHTML = '<span class="badge" style="background:#dcfce7;color:#166534;font-size:.74rem;border-radius:8px;padding:7px 12px;white-space:nowrap;"><i class="fas fa-check-circle me-1"></i>Sudah Diproses</span>';
    }
}

function buildOdooConfigBadge(match) {
    const s = 'display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;border-radius:6px;padding:2px 8px;';
    if (!match) {
        return `<span style="${s}background:#fef2f2;color:#b91c1c;"><i class="fas fa-times-circle" style="font-size:.6rem;"></i>Belum ada di master lokal</span>`;
    }
    if (!match.label_config_set) {
        return `<span style="${s}background:#fff3cd;color:#92400e;"><i class="fas fa-tag" style="font-size:.6rem;"></i>Label Belum Diset</span>`;
    }
    if (match.label_print === 'physical') {
        const placeLabel = { unit:'Item', box:'Box', outer:'Outer', catalogue:'Katalog' }[match.label_placement] ?? match.label_placement ?? '';
        return `<span style="${s}background:#dcfce7;color:#166534;"><i class="fas fa-print" style="font-size:.6rem;"></i>Cetak Fisik · ${placeLabel}</span>`;
    }
    return `<span style="${s}background:#f1f5f9;color:#64748b;"><i class="fas fa-barcode" style="font-size:.6rem;"></i>Sistem Saja · Pakai Barcode Vendor</span>`;
}

// Nama produk Odoo biasanya berformat "[KODE] Nama Barang" — kode di depan adalah
// default_code Odoo yang sama dengan kode_barang lokal. Cocokkan kode dulu (lebih reliable),
// baru fallback ke substring nama kalau kode tidak ketemu.
function matchLocalItem(odooNama) {
    const codeMatch = odooNama.match(/^\[([^\]]+)\]/);
    const odooCode  = codeMatch ? codeMatch[1].trim() : null;
    const nameLower = odooNama.toLowerCase();

    let match = odooCode ? allItems.find(it => String(it.kode_barang) === odooCode) : null;
    if (!match) {
        match = allItems.find(it =>
            nameLower.includes(it.nama_barang.toLowerCase()) || it.nama_barang.toLowerCase().includes(nameLower)
        );
    }
    return match || null;
}

function processOdooItem(odooItem, idx) {
    window._activeOdooRowIdx = (idx !== undefined) ? idx : null;
    window._activeOdooItem = odooItem;
    const now = new Date().toLocaleTimeString('id-ID');
    const match = matchLocalItem(odooItem.nama);

    if (match) {
        const item = {
            kode_barang: match.kode_barang,
            nama_barang: match.nama_barang,
            satuan: odooItem.uom || match.satuan,
            track_ed: match.track_ed,
            label_print: match.label_print ?? null,
            label_placement: match.label_placement ?? null,
            label_config_set: match.label_config_set ?? false,
            _mapId: match.id,
        };
        const barcode = 'BIS-' + String(match.kode_barang).padStart(4, '0');
        currentFoundItem = item;
        currentFoundBarcode = barcode;
        showResult(true, barcode, item);
        addHistory(now, barcode, item.nama_barang, true);

        if (item.label_config_set && item.label_print === 'physical') {
            // Dikonfigurasi untuk cetak label fisik → langsung tawarkan opsi cetak
            showPrintPopup(item);
        } else {
            // Tidak dicetak (pakai barcode vendor yang sudah ada) → buka popup yang sama, tapi minta scan barcode vendor
            showPrintPopup(item, false, true);
        }
    } else {
        currentFoundItem = null;
        currentFoundBarcode = '';
        showResult(false, odooItem.nama, null);
        addHistory(now, odooItem.nama, '-', false);
        currentUnknown = odooItem.nama;
        window._unknownIsBarcode = false; // ini nama produk Odoo, bukan barcode asli — jangan didaftarkan sbg barcode vendor
        document.getElementById('unknownBarcodeText').textContent = odooItem.nama + ' (dari Odoo, belum ada di master lokal)';
        setTimeout(() => new bootstrap.Modal(document.getElementById('modalMapping')).show(), 300);
    }
}

document.addEventListener('hidden.bs.modal', function(e) {
    if (e.target.id !== 'modalMapping') return;
    // Mapping ditutup (mapping tersimpan via saveMapping, atau dilewati) → tandai baris Odoo terkait selesai diproses
    markOdooItemProcessed(window._activeOdooRowIdx);
    window._activeOdooRowIdx = null;
});

function processScan() {
    const input = document.getElementById('scanInput');
    const barcode = input.value.trim();
    if (!barcode) return;
    window._activeOdooRowIdx = null;
    window._activeOdooItem = null;
    const now = new Date().toLocaleTimeString('id-ID');

    if (dummyDB[barcode]) {
        const item = dummyDB[barcode];
        item._mapId = kode2id[item.kode_barang] ?? null; // untuk lookup ED berjalan
        currentFoundItem = item;
        currentFoundBarcode = barcode;
        showResult(true, barcode, item);
        addHistory(now, barcode, item.nama_barang, true);
        handleItemAction(item);
    } else {
        currentFoundItem = null;
        currentFoundBarcode = '';
        showResult(false, barcode, null);
        addHistory(now, barcode, '-', false);
        currentUnknown = barcode;
        window._unknownIsBarcode = true; // barcode asli hasil scan
        document.getElementById('unknownBarcodeText').textContent = barcode;
        setTimeout(() => new bootstrap.Modal(document.getElementById('modalMapping')).show(), 400);
    }
    input.value = '';
    input.focus();
}

function showResult(found, barcode, item) {
    const emptyEl = document.getElementById('resultEmpty');
    const card = document.getElementById('resultCard');
    const body = document.getElementById('resultBody');
    const printSection = document.getElementById('printSection');
    const ring = document.getElementById('scanIconRing');
    const statusEl = document.getElementById('scanStatus');
    const statusText = document.getElementById('scanStatusText');

    emptyEl.classList.add('d-none');
    card.style.display = 'block';

    // Update scan icon ring
    ring.classList.remove('pulse', 'flash-green', 'flash-red');
    if (found) {
        ring.classList.add('flash-green');
        statusEl.className = 'scan-status success';
        statusText.innerHTML = '<i class="fas fa-check-circle me-1"></i>Dikenal — item ditemukan';
        setTimeout(() => { ring.classList.remove('flash-green'); ring.classList.add('pulse'); }, 1800);
    } else {
        ring.classList.add('flash-red');
        statusEl.className = 'scan-status warning';
        statusText.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Tidak dikenal — perlu mapping';
        setTimeout(() => { ring.classList.remove('flash-red'); ring.classList.add('pulse'); }, 1800);
    }

    card.className = 'card result-card ' + (found ? 'found' : 'unknown');

    if (found) {
        const qrStr = 'BIS-' + String(item.kode_barang).padStart(4, '0');
        body.innerHTML = `
            <div class="d-flex align-items-start gap-3">
                <div class="result-icon-circle" style="background:#f0fdf4;">
                    <i class="fas fa-check-circle fa-lg" style="color:#22c55e;"></i>
                </div>
                <div class="flex-fill">
                    <div class="result-item-name">${escHtml(item.nama_barang)}</div>
                    <div class="mt-1">
                        <span class="result-item-code">${escHtml(qrStr)}</span>
                    </div>
                    <div class="result-item-satuan">
                        <i class="fas fa-cube me-1" style="font-size:.7rem;"></i>${escHtml(item.satuan)}
                        ${item.track_ed ? '<i class="fas fa-calendar-times ms-2" style="color:#f59e0b;font-size:.7rem;" title="Track ED aktif"></i>' : ''}
                    </div>
                    <div class="mt-2">
                        <code style="font-size:.78rem; color:#64748b; background:#f1f5f9; padding:3px 8px; border-radius:6px;">${escHtml(barcode)}</code>
                    </div>
                </div>
            </div>`;
        printSection.style.display = 'none'; // print selalu lewat popup, bukan in-card
    } else {
        body.innerHTML = `
            <div class="d-flex align-items-start gap-3">
                <div class="result-icon-circle" style="background:#fffbeb;">
                    <i class="fas fa-question-circle fa-lg" style="color:#f59e0b;"></i>
                </div>
                <div>
                    <div class="result-item-name" style="color:#92400e;">Barcode Tidak Dikenal</div>
                    <div class="mt-1">
                        <code style="font-size:.88rem; font-weight:700; color:#1e293b;">${escHtml(barcode)}</code>
                    </div>
                    <div class="text-muted mt-2" style="font-size:.82rem;">Silakan petakan ke item yang sesuai</div>
                    <button class="btn btn-sm mt-3 fw-semibold text-white" id="btnMapNowFromResult"
                        style="background:#f59e0b; border:none; border-radius:8px; font-size:.8rem;">
                        <i class="fas fa-link me-1"></i>Mapping Sekarang
                    </button>
                </div>
            </div>`;
        printSection.style.display = 'none';
        // addEventListener (bukan inline onclick) supaya barcode hasil scan/QR tidak pernah
        // disuntikkan mentah sbg source JS — barcode bisa berisi karakter apa pun (input eksternal).
        document.getElementById('btnMapNowFromResult')?.addEventListener('click', () => {
            document.getElementById('unknownBarcodeText').textContent = barcode;
            new bootstrap.Modal(document.getElementById('modalMapping')).show();
        });
    }
}

function openPrintModal(ed = null) {
    if (!currentFoundItem) return;
    const qty = parseInt(document.getElementById('printQty').value) || 1;
    if (qty < 1 || qty > 100) {
        Swal.fire({ icon: 'warning', title: 'Jumlah tidak valid', text: 'Masukkan jumlah antara 1 - 100.' });
        return;
    }

    // ed bisa null, string "2026-12-31", atau object {date, ket}
    const edDate     = ed ? (typeof ed === 'object' ? ed.date : ed) : null;
    const baseQr     = 'BIS-' + String(currentFoundItem.kode_barang).padStart(4, '0');
    const edLabel    = edDate ? formatED(edDate) : null;
    const qrStr      = baseQr; // QR selalu berisi BIS code saja, ED dicatat di DB bukan di QR
    const namaBarang = currentFoundItem.nama_barang;
    const namaShort  = namaBarang.length > 30 ? namaBarang.substring(0,30)+'…' : namaBarang;

    const grid = document.getElementById('printArea');
    grid.innerHTML = '';

    const activeSize = '33x15';
    const qrPx = 80;

    for (let i = 0; i < qty; i++) {
        const wrapperId = `qr-label-${i}-${Date.now()}`;
        const wrapper = document.createElement('div');
        wrapper.className = 'label-card';
        const rawKode = currentFoundItem.kode_barang || '';
        const uom = currentFoundItem.uom || currentFoundItem.satuan || '';
        wrapper.innerHTML = `
            <div class="label-qr" id="${wrapperId}"></div>
            <div class="label-info">
                <div class="l-code">${baseQr}</div>
                <div class="l-name">${rawKode ? `[${rawKode}] ${namaShort}` : namaShort}</div>
            </div>`;
        grid.appendChild(wrapper);
        setTimeout(() => {
            const el = document.getElementById(wrapperId);
            if (el) new QRCode(el, {
                text: qrStr, width: qrPx, height: qrPx,
                colorDark: '#000000', colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }, 60 * i);
    }

    new bootstrap.Modal(document.getElementById('modalPrintLabel')).show();
}

function formatED(val) {
    // val = "2026-12-31" (tanggal YYYY-MM-DD) → "31 Des 2026"
    if (!val) return '';
    const bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    const parts = val.split('-');
    if (parts.length === 3) {
        // full date: YYYY-MM-DD
        return `${parseInt(parts[2])} ${bulan[parseInt(parts[1])]} ${parts[0]}`;
    }
    // month only: YYYY-MM
    return `${bulan[parseInt(parts[1])]} ${parts[0]}`;
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
function _inbLabelCss(size) {
    if (size === '33x15') {
        return `@page { size: 103mm 15mm; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #fff; margin: 0; padding: 0; }
.lrow { width: 103mm; height: 13mm; display: flex; flex-direction: row; gap: 5.5mm; page-break-after: always; break-after: page; overflow: hidden; }
.lc { width: 30.7mm; height: 13mm; display: flex; flex-direction: row; align-items: center; padding: 0 1.5mm 0 1mm; gap: 1.5mm; overflow: hidden; }
.lq { width: 11mm; height: 11mm; flex-shrink: 0; }
.lq img { width: 11mm; height: 11mm; display: block; }
.li { flex: 1; display: flex; flex-direction: column; justify-content: center; overflow: hidden; font-family: Arial, sans-serif; }
.lcode { font-family: 'Courier New', monospace; font-size: 6.5pt; font-weight: 700; color: #1d3d99; border-bottom: .25mm solid #1d3d99; padding-bottom: .3mm; margin-bottom: .5mm; }
.lname { font-size: 5.5pt; line-height: 1.3; color: #111; word-break: break-word; }`;
    }
    /* 50×30mm — height 22mm = 30mm - 8mm Zebra printer top dead zone. Consistent across all labels. */
    return `@page { size: 50mm 30mm; margin: 0; }
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

function doPrint() {
    const cards = document.querySelectorAll('#printArea .label-card');
    if (!cards.length) return;

    const size = '33x15';
    const qrPx = 80;

    const rawHTML = [...cards].map(card => {
        const qrImg  = card.querySelector('.label-qr img');
        const qrSrc  = qrImg ? qrImg.src : '';
        const code   = card.querySelector('.l-code')?.textContent  || '';
        const name   = card.querySelector('.l-name')?.textContent  || '';
        const kode   = card.querySelector('.l-kode')?.textContent  || '';
        const uom    = card.querySelector('.l-uom')?.textContent   || '';
        const rawKodeNum = kode.replace(/^kode\s*:\s*/, '').trim();
        const displayName = rawKodeNum ? `[${rawKodeNum}] ${name}` : name;
        return `<div class="lc">
            ${qrSrc ? `<img class="lq" src="${qrSrc}">` : '<div class="lq"></div>'}
            <div class="li">
                <div class="lcode">${code}</div>
                <div class="lname">${displayName}</div>
            </div>
        </div>`;
    }).join('');
    const labelsHTML = size === '33x15' ? _wrapLabels33(rawHTML) : rawHTML;

    const win = window.open('', '_blank', 'width=400,height=300');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8">
<style>${_inbLabelCss(size)}</style></head><body>${labelsHTML}</body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 600);
}

function addHistory(time, barcode, nama, found) {
    scanHistory.unshift({ time, barcode, nama, found });

    const emptyEl = document.getElementById('historyEmpty');
    const feedEl  = document.getElementById('historyFeed');
    emptyEl.style.display = 'none';
    feedEl.style.display  = 'block';

    const row = document.createElement('div');
    row.className = 'history-row';
    row.id = 'hist-' + barcode.replace(/[^a-zA-Z0-9]/g, '_');
    row.innerHTML = `
        <span class="hist-dot ${found ? 'known' : 'unknown'}"></span>
        <span class="hist-time">${escHtml(time)}</span>
        <span class="hist-code" title="${escHtml(barcode)}">${escHtml(barcode)}</span>
        <span class="hist-name hist-nama">${escHtml(nama)}</span>
        <span class="hist-badge">
            ${found
                ? '<span class="badge" style="background:#dcfce7;color:#166534;font-size:.72rem;border-radius:6px;">Dikenal</span>'
                : '<span class="badge hist-status" style="background:#fef3c7;color:#92400e;font-size:.72rem;border-radius:6px;">Tidak dikenal</span>'}
        </span>`;
    feedEl.prepend(row);
}

function updateHistoryRow(barcode, nama) {
    const rowId = 'hist-' + barcode.replace(/[^a-zA-Z0-9]/g, '_');
    const tr = document.getElementById(rowId);
    if (!tr) return;
    tr.querySelector('.hist-nama').textContent = nama;
    const badgeEl = tr.querySelector('.hist-status');
    if (badgeEl) {
        badgeEl.style.background = '#dcfce7';
        badgeEl.style.color = '#166534';
        badgeEl.textContent = 'Mapped';
    }
    tr.querySelector('.hist-dot').className = 'hist-dot known';
}

function buildLabelBadge(item) {
    const print = item.label_print ?? null;
    const placement = item.label_placement ?? null;
    const configSet = item.label_config_set ?? false;
    const s = 'font-size:.7rem;border-radius:5px;padding:2px 8px;font-weight:600;flex-shrink:0;';
    if (!configSet)       return `<span style="${s}background:#fff3cd;color:#92400e;"><i class="fas fa-tag me-1" style="font-size:.6rem;"></i>Belum Diset</span>`;
    if (print === 'none') return `<span style="${s}background:#f1f5f9;color:#94a3b8;">Sistem Saja</span>`;
    const pl = {unit:'Item',box:'Box',outer:'Outer',catalogue:'Katalog'}[placement] ?? placement;
    return `<span style="${s}background:#eff6ff;color:#1d4ed8;"><i class="fas fa-print me-1" style="font-size:.6rem;"></i>${pl}</span>`;
}

function buildLabelConfigHTML(item) {
    const configSet = item.label_config_set ?? false;
    const print     = item.label_print ?? null;
    const placement = item.label_placement ?? null;

    if (!configSet) {
        return `<div>
            <div class="d-flex align-items-center gap-2 mb-2" style="background:#fff3cd;border:1px solid #fde68a;border-radius:8px;padding:7px 10px;">
                <i class="fas fa-exclamation-triangle" style="color:#d97706;flex-shrink:0;font-size:.8rem;"></i>
                <span class="small" style="color:#92400e;font-weight:600;">Belum dikonfigurasi — atur langsung di sini:</span>
            </div>
            <div id="ibLcWizardWrap"></div>
        </div>`;
    }

    if (print === 'none') {
        return `<div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge px-2 py-1" style="background:#f1f5f9;color:#64748b;font-size:.8rem;border-radius:8px;font-weight:600;">
                <i class="fas fa-times-circle me-1"></i>Sistem Saja
            </span>
            <span class="text-muted small">— tidak perlu cetak label fisik</span>
        </div>`;
    }

    const placementLabel = { unit: 'Item', box: 'Box / Kemasan', outer: 'Outer Package', catalogue: 'Katalog' }[placement] ?? placement;
    const placementHint  = { unit: 'qty per item masuk', box: 'qty per box masuk', outer: 'qty per outer masuk', catalogue: 'tempel di katalog' }[placement] ?? '';

    return `<div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge px-2 py-1" style="background:#dcfce7;color:#166534;font-size:.78rem;border-radius:6px;font-weight:600;">
            <i class="fas fa-print me-1"></i>Cetak Fisik
        </span>
        <span class="badge px-2 py-1" style="background:#e8f0fd;color:#204EAB;font-size:.78rem;border-radius:6px;font-weight:600;">
            <i class="fas fa-map-marker-alt me-1"></i>${placementLabel}
        </span>
        ${placementHint ? `<span class="text-muted" style="font-size:.75rem;">→ ${placementHint}</span>` : ''}
    </div>`;
}

// ── Inline Label Config Wizard (di Mapping Modal & Print Popup) ────────────────
// Logika pertanyaan/kategori ada di assets/js/label_config_wizard.js (shared dgn barang.php)
let _ibWizAnswers = new Array(10).fill(null);
let _ibSelLP = null, _ibSelPlace = null;
let _ibCurrentItemId = null;
let _ibWizContext = 'mapping'; // 'mapping' | 'print'
let _ibPrintItem  = null;      // item object when context='print'
let _ibRecommendedCat = null;
let _ibOverrideActive = false;

function ibLcRender() {
    const wrap = document.getElementById('ibLcWizardWrap');
    if (!wrap) return;

    const { qRowsHtml, catCardsHtml, state } = labelConfigRenderParts(_ibWizAnswers, 'ibLcSetAnswer');
    _ibRecommendedCat = state.category;

    let finalCat = state.category;
    let overrideStatus = false;
    const chosenOverrideCat = _ibOverrideActive
        ? (document.getElementById('ibOverrideCategory')?.value || '')
        : '';
    if (chosenOverrideCat) { finalCat = chosenOverrideCat; overrideStatus = true; }
    if (finalCat) { const ci = LABEL_CONFIG_CATEGORIES[finalCat]; _ibSelLP = ci.lp; _ibSelPlace = ci.place; }
    else { _ibSelLP = null; _ibSelPlace = null; }

    const currentReason = document.getElementById('ibOverrideReason')?.value || '';
    const currentNotes  = document.getElementById('ibOverrideNotes')?.value || '';

    const overrideHtml = canEditLabelInbound ? labelConfigRenderOverrideHTML({
        active: _ibOverrideActive,
        reason: currentReason,
        notes: currentNotes,
        finalCategory: chosenOverrideCat,
        fnPrefix: 'ib',
    }) : '';

    const statusBadge = state.category
        ? `<div style="margin-bottom:6px;">${labelConfigStatusBadgeHTML(overrideStatus)}</div>` : '';

    const saveBtn = (finalCat) ? `<button onclick="ibLcSaveCfg()" id="ibLcSaveBtn"
        style="width:100%;margin-top:8px;background:#204EAB;color:#fff;border:none;border-radius:8px;padding:7px 0;font-size:.8rem;font-weight:600;cursor:pointer;">
        <i class="fas fa-save me-1"></i>Simpan Konfigurasi Label
    </button>` : '';

    wrap.innerHTML = `
        <div style="margin-bottom:8px;">${qRowsHtml}</div>
        <div style="border-top:1px solid #e2e8f0;padding-top:8px;">
            <div style="font-size:.62rem;color:#94a3b8;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;">Kategori</div>
            ${statusBadge}
            <div style="display:flex;flex-direction:column;gap:6px;">${catCardsHtml}</div>
            ${overrideHtml}
        </div>
        ${saveBtn}`;
}

function ibLcSetAnswer(qi, answer) {
    _ibWizAnswers[qi] = _ibWizAnswers[qi] === answer ? null : answer;
    _ibOverrideActive = false;
    ibLcRender();
}

function ibLcOverrideStart() { _ibOverrideActive = true; ibLcRender(); document.getElementById('ibOverrideReason')?.focus(); }
function ibLcOverrideCancel() { _ibOverrideActive = false; ibLcRender(); }
function ibLcOverrideReasonChange() { ibLcRender(); }
function ibLcOverrideCategoryChange() { ibLcRender(); }

function ibLcSaveCfg() {
    if (!_ibSelLP || !_ibCurrentItemId) return;
    const barangId = _ibCurrentItemId;
    const lp       = _ibSelLP;
    const place    = _ibSelPlace || '';

    let overrideStatus = 0, overrideReason = '', overrideNotes = '', finalCategory = _ibRecommendedCat;
    if (_ibOverrideActive) {
        overrideReason = document.getElementById('ibOverrideReason')?.value || '';
        overrideNotes  = document.getElementById('ibOverrideNotes')?.value.trim() || '';
        finalCategory  = document.getElementById('ibOverrideCategory')?.value || '';
        if (!overrideReason) { Swal.fire('Alasan Override Belum Dipilih', 'Pilih alasan Manual Override terlebih dahulu.', 'warning'); return; }
        if (overrideReason === 'Lainnya' && !overrideNotes) { Swal.fire('Keterangan Wajib Diisi', 'Isi keterangan untuk alasan "Lainnya".', 'warning'); return; }
        if (!finalCategory) { Swal.fire('Kategori Belum Dipilih', 'Pilih kategori final untuk Manual Override.', 'warning'); return; }
        overrideStatus = 1;
    }

    const btn = document.getElementById('ibLcSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem;"></span> Menyimpan...'; }

    fetch('api/save_bis_config.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            _csrf: csrfToken,
            barang_id: barangId, label_print: lp, label_placement: place, label_only: 1,
            wizard_answers: JSON.stringify(_ibWizAnswers),
            recommended_category: _ibRecommendedCat || '',
            final_category: finalCategory || '',
            override_status: overrideStatus,
            override_reason: overrideReason,
            override_notes: overrideNotes,
        })
    }).then(r => r.json()).then(res => {
        if (res.success) {
            const idx = allItems.findIndex(x => x.id == barangId);
            if (idx >= 0) {
                allItems[idx].label_print      = lp;
                allItems[idx].label_placement  = place || null;
                allItems[idx].label_config_set = 1;
                allItems[idx].wizard_answers        = JSON.stringify(_ibWizAnswers);
                allItems[idx].recommended_category  = _ibRecommendedCat || null;
                allItems[idx].final_category        = finalCategory || null;
                allItems[idx].override_status       = overrideStatus;
                allItems[idx].override_reason       = overrideReason || null;
                allItems[idx].override_notes        = overrideNotes || null;
                if (res.barcode_internal) allItems[idx].barcode_internal = res.barcode_internal;
            }
            if (_ibWizContext === 'print' && _ibPrintItem) {
                // Update the item object and re-open popup with full state
                _ibPrintItem.label_print      = lp;
                _ibPrintItem.label_placement  = place || null;
                _ibPrintItem.label_config_set = 1;
                if (res.barcode_internal) _ibPrintItem.barcode_internal = res.barcode_internal;
                showPrintPopup(_ibPrintItem);
            } else {
                renderMappingLabelConfig(barangId);
            }
        } else {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Konfigurasi Label'; }
            Swal.fire('Gagal', res.message || 'Gagal menyimpan.', 'error');
        }
    }).catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i>Simpan Konfigurasi Label'; }
        Swal.fire('Error', 'Terjadi kesalahan koneksi.', 'error');
    });
}

// ── Tentukan aksi setelah item dikenal (scan/cari) berdasarkan konfigurasi label ──
function handleItemAction(item) {
    // Physical → print mode; non-physical (Sistem Saja) / unconfigured → scan mode
    // vendorAlreadyConfirmed in showPrintPopup skips re-scan if entry was via vendor barcode
    if (item.label_config_set && item.label_print === 'physical') {
        showPrintPopup(item, false, false);
    } else {
        showPrintPopup(item, false, true);
    }
}

function showPrintPopup(item, fromMapping = false, scanMode = false) {
    const _itemId = item._mapId ?? item.id ?? null;
    const qrStr = 'BIS-' + String(item.kode_barang).padStart(4, '0');
    // Skip vendor scan hanya kalau currentFoundBarcode itu benar-benar barcode vendor yang baru
    // di-scan (dan sudah terdaftar ke item ini) — bukan kode internal (BIS-XXXX) milik item sendiri
    // yang dipakai di flow Cari Item / Tarik Odoo (di situ tidak ada barcode yang benar-benar discan).
    const isRealVendorScan = scanMode && currentFoundBarcode && currentFoundBarcode !== qrStr;
    const vendorAlreadyConfirmed = isRealVendorScan && barcodeOwnedBySameItem(currentFoundBarcode, _itemId);
    window._vendorAlreadyConfirmed = vendorAlreadyConfirmed;
    const autoED = !!item.track_ed;

    // Isi header
    document.getElementById('printModalName').textContent = item.nama_barang;
    document.getElementById('printModalSub').textContent = (fromMapping ? 'Mapping tersimpan · ' : '') + qrStr;
    const icon = document.getElementById('printModalIcon');
    icon.className = 'fas fa-' + (fromMapping ? 'link' : 'check');
    icon.style.color = fromMapping ? '#93c5fd' : '#6ee7b7';
    document.getElementById('printModalBadge').innerHTML = buildLabelBadge(item);

    // ED section — radio-button style matching modalMapping
    const itemId = item._mapId ?? null;
    const showED = autoED || (itemId && (activeEDByItemId[itemId] || []).length > 0);
    const edToggle = document.getElementById('printEDToggle');
    edToggle.checked = showED;
    const edSection = document.getElementById('printEDSection');
    edSection.style.display = showED ? 'block' : 'none';
    if (showED) renderPrintEDSection(itemId);
    // Lacak status track_ed asli & apakah toggle disentuh manual oleh user — supaya ED lama yang
    // masih aktif tidak diam-diam menyalakan lagi track_ed yang sengaja dimatikan admin.
    window._printOrigTrackEd = !!item.track_ed;
    window._printEDManuallyChanged = false;

    // Label config
    const labelRow  = document.getElementById('printLabelRow');
    const labelBody = document.getElementById('printLabelBody');
    labelBody.innerHTML = buildLabelConfigHTML(item);
    labelRow.style.display = 'flex';

    if (!(item.label_config_set ?? false)) {
        _ibCurrentItemId = item._mapId ?? item.id ?? null;
        _ibWizAnswers = new Array(10).fill(null);
        _ibOverrideActive = false;
        _ibSelLP = null; _ibSelPlace = null;
        _ibWizContext = 'print';
        _ibPrintItem  = item;
        ibLcRender();
    }

    // Jumlah cetak vs Scan barcode vendor vs Auto-confirmed
    const qtyRow  = document.getElementById('printQtyRow');
    const scanRow = document.getElementById('printScanRow');
    const footerBtn = document.getElementById('printFooterBtn');
    footerBtn.disabled = false; // reset guard double-submit dari sesi popup sebelumnya
    _printLabelInFlight = false;

    if (!(item.label_config_set ?? false)) {
        qtyRow.style.display  = 'none';
        scanRow.style.display = 'none';
        footerBtn.style.display = 'none';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintPopup')).show();
        return;
    }
    footerBtn.style.display = '';

    if (vendorAlreadyConfirmed) {
        // Vendor barcode sudah di-scan — langsung konfirmasi tanpa scan lagi
        qtyRow.style.display  = 'none';
        scanRow.style.display = 'none';
        footerBtn.innerHTML   = '<i class="fas fa-check me-2"></i>Konfirmasi Penerimaan';
    } else if (scanMode || item.label_print === 'none') {
        // Sistem Saja atau non-physical → minta scan barcode vendor
        qtyRow.style.display  = 'none';
        scanRow.style.display = 'flex';
        document.getElementById('printScanVendorInput').value = '';
        footerBtn.innerHTML = '<i class="fas fa-check me-2"></i>Konfirmasi Penerimaan';
    } else {
        qtyRow.style.display  = 'flex';
        scanRow.style.display = 'none';
        document.getElementById('printQtyModal').value = 1;
        footerBtn.innerHTML = '<i class="fas fa-print me-2"></i>Print Label';
    }

    // Simpan item & mode untuk doPrintLabel
    window._printItem = item;
    window._printScanMode = scanMode || vendorAlreadyConfirmed;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintPopup')).show();
    if (scanMode && !vendorAlreadyConfirmed) {
        setTimeout(() => document.getElementById('printScanVendorInput').focus(), 300);
    }
}

function renderPrintEDSection(itemId) {
    const existing = (itemId && activeEDByItemId[itemId]) ? activeEDByItemId[itemId] : [];
    const existingWrap = document.getElementById('printExistingEDWrap');
    const list = document.getElementById('printExistingEDList');
    const newWrap = document.getElementById('printNewEDWrap');

    if (existing.length > 0) {
        existingWrap.style.display = 'block';
        list.innerHTML = existing.map((e, i) => `
            <label class="ed-chip-p border rounded px-3 py-2 d-flex align-items-center gap-2"
                style="cursor:pointer;font-size:13px;background:${i===0?'#e8f0fd':'#fff'};transition:background .15s;${i===0?'border-color:#204EAB;':''} ">
                <input type="radio" name="existingPrintED" value="${e.date}" ${i===0?'checked':''}
                    onchange="onPrintExistingEDSelect('${e.date}')">
                <i class="fas fa-calendar-check text-success me-1"></i>
                <span class="fw-semibold">${formatED(e.date)}</span>
                ${e.ket ? `<span class="text-muted" style="font-size:11px;">${e.ket}</span>` : ''}
            </label>`).join('') + `
            <label class="ed-chip-p border rounded px-3 py-2 d-flex align-items-center gap-2"
                style="cursor:pointer;font-size:13px;background:#fff;">
                <input type="radio" name="existingPrintED" value="__new__"
                    onchange="onPrintExistingEDSelect('__new__')">
                <i class="fas fa-plus text-primary me-1"></i>
                <span class="fw-semibold">ED baru...</span>
            </label>`;
        newWrap.style.display = 'none';
        document.getElementById('printEDInput').value = existing[0].date;
        document.getElementById('printEDKet').value = existing[0].ket || '';
    } else {
        existingWrap.style.display = 'none';
        newWrap.style.display = 'block';
        document.getElementById('printEDInput').value = '';
        document.getElementById('printEDKet').value = '';
    }
}

function onPrintExistingEDSelect(val) {
    const newWrap = document.getElementById('printNewEDWrap');
    const existing = window._printItem?._mapId ? (activeEDByItemId[window._printItem._mapId] || []) : [];
    if (val === '__new__') {
        newWrap.style.display = 'block';
        document.getElementById('printEDInput').value = '';
        document.getElementById('printEDKet').value = '';
        document.getElementById('printEDInput').focus();
    } else {
        newWrap.style.display = 'none';
        document.getElementById('printEDInput').value = val;
        const found = existing.find(e => e.date === val);
        document.getElementById('printEDKet').value = found?.ket || '';
    }
    document.querySelectorAll('.ed-chip-p').forEach(c => {
        c.style.background = c.querySelector('input').checked ? '#e8f0fd' : '#fff';
        c.style.borderColor = c.querySelector('input').checked ? '#204EAB' : '';
    });
}

function onPrintNewEDInput() {
    document.querySelectorAll('input[name="existingPrintED"]').forEach(r => { r.checked = false; });
    document.querySelectorAll('.ed-chip-p').forEach(c => { c.style.background='#fff'; c.style.borderColor=''; });
}

function applyPrintEDToggle() {
    window._printEDManuallyChanged = true; // user sengaja mengubah toggle — beda dari auto-set sistem
    const on = document.getElementById('printEDToggle').checked;
    const edSection = document.getElementById('printEDSection');
    edSection.style.display = on ? 'block' : 'none';
    if (on) {
        const itemId = window._printItem?._mapId ?? null;
        renderPrintEDSection(itemId);
    }
}

// Cek apakah `barcode` sudah diketahui (client-side) sebagai milik item `itemId` yang SAMA —
// vendorBarcodeSet cuma flat set (tidak tahu barcode itu punya item mana), jadi verifikasi
// kepemilikan harus lewat dummyDB (lookup barcode→item aktual), bukan sekadar "pernah dilihat".
function barcodeOwnedBySameItem(barcode, itemId) {
    if (!barcode || !itemId) return false;
    const owner = dummyDB[barcode];
    if (!owner) return false;
    const ownerId = owner._mapId ?? owner.id ?? null;
    return ownerId !== null && Number(ownerId) === Number(itemId);
}

// Sinkronkan status track_ed item ke DB (ON maupun OFF) + update state lokal (allItems, dropdown, itemTrackEdMap)
function syncTrackEdState(barangId, on) {
    if (!barangId) return;
    fetch('api/set_track_ed.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({_csrf: csrfToken, barang_id: barangId, value: on ? 1 : 0})
    }).then(() => {
        itemTrackEdMap[barangId] = on;
        const idx = allItems.findIndex(x => x.id == barangId);
        if (idx >= 0) allItems[idx].track_ed = on ? 1 : 0;
        document.querySelectorAll(`[data-track-ed][value="${barangId}"], .custom-select-option[data-value="${barangId}"]`)
            .forEach(el => el.setAttribute('data-track-ed', on ? '1' : '0'));
    }).catch(() => {});
}

// Dipanggil dari tombol footer "Konfirmasi Penerimaan" — kalau lagi minta konfirmasi barcode vendor
// (belum otomatis ter-confirm), tampilkan dulu popup review sebelum benar-benar tersimpan, baik
// barcode-nya didapat lewat scan maupun diketik manual.
function onPrintFooterBtnClick() {
    if (window._printScanMode && !window._vendorAlreadyConfirmed) {
        const code = document.getElementById('printScanVendorInput').value.trim();
        if (!code) {
            showToast('Scan atau masukkan barcode vendor terlebih dahulu.', 'warning');
            return;
        }
        confirmScannedVendorBarcode();
    } else {
        doPrintLabel();
    }
}

// Tampilkan popup review barcode vendor — jangan langsung tersimpan begitu saja. User harus
// eksplisit klik "Konfirmasi" sebelum benar-benar diproses (baik lewat Enter dari scanner maupun
// klik tombol "Konfirmasi Penerimaan" setelah ketik manual).
function confirmScannedVendorBarcode() {
    const input = document.getElementById('printScanVendorInput');
    const code = input.value.trim();
    if (!code) return;
    const item = window._printItem;
    const itemId = item?._mapId ?? null;

    let statusHtml;
    if (itemId && barcodeOwnedBySameItem(code, itemId)) {
        statusHtml = `<div style="margin-top:8px;color:#16a34a;font-size:.85rem;"><i class="fas fa-check-circle me-1"></i>Barcode sudah terdaftar untuk item ini.</div>`;
    } else {
        const owner = dummyDB[code];
        if (owner && itemId && (owner._mapId ?? owner.id) !== itemId) {
            statusHtml = `<div style="margin-top:8px;color:#dc2626;font-size:.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>Barcode ini sudah dipetakan ke item lain: <b>${escHtml(owner.nama_barang || '')}</b>. Penerimaan tetap tercatat, tapi barcode tidak akan didaftarkan ulang untuk item ini.</div>`;
        } else {
            statusHtml = `<div style="margin-top:8px;color:#64748b;font-size:.85rem;"><i class="fas fa-info-circle me-1"></i>Barcode baru — akan didaftarkan untuk item ini.</div>`;
        }
    }

    Swal.fire({
        target: document.getElementById('modalPrintPopup'),
        icon: 'question',
        title: 'Konfirmasi Barcode',
        html: `Barcode terscan:<br><code style="font-size:1rem;font-weight:700;">${escHtml(code)}</code><br>
               untuk item <b>${escHtml(item?.nama_barang || '')}</b>${statusHtml}`,
        showCancelButton: true,
        confirmButtonText: 'Ya, Sesuai — Konfirmasi',
        cancelButtonText: 'Batal / Ulangi',
        confirmButtonColor: '#204EAB',
        cancelButtonColor: '#94a3b8',
    }).then(res => {
        if (res.isConfirmed) {
            doPrintLabel();
        } else {
            input.value = '';
            input.focus();
        }
    });
}

let _printLabelInFlight = false;
function doPrintLabel() {
    if (_printLabelInFlight) return; // cegah double-submit (mis. double-klik) sebelum modal sempat tertutup
    const item = window._printItem;
    if (!item) return;

    // ED validation — collect from new radio-button style inputs
    const edSection = document.getElementById('printEDSection');
    const wantED = edSection.style.display !== 'none';
    let eds = [];
    if (wantED) {
        const edDate = document.getElementById('printEDInput').value;
        const edKet  = document.getElementById('printEDKet').value.trim();
        if (!edDate) {
            showToast('Pilih atau masukkan tanggal kadaluarsa.', 'warning');
            document.getElementById('printEDInput').focus();
            return;
        }
        if (!edKet) {
            showToast('No. batch / lot wajib diisi.', 'warning');
            document.getElementById('printEDKet').focus();
            return;
        }
        eds = [{ date: edDate, ket: edKet }];
    }

    if (window._printScanMode) {
        const code = window._vendorAlreadyConfirmed
            ? (currentFoundBarcode || '').trim()
            : document.getElementById('printScanVendorInput').value.trim();
        if (!code) {
            showToast('Scan atau masukkan barcode vendor terlebih dahulu.', 'warning');
            return;
        }
        _printLabelInFlight = true;
        document.getElementById('printFooterBtn').disabled = true;
        // Simpan vendor barcode ke DB & update JS state agar scan berikutnya tidak minta konfirmasi ulang
        // (barcode wajib unik per item — kalau sudah dipetakan ke item lain, server akan menolak)
        if (!window._vendorAlreadyConfirmed && item._mapId) {
            const codeLower = code.toLowerCase();
            if (!barcodeOwnedBySameItem(code, item._mapId)) {
                fetch('api/vendor_barcodes.php?action=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ _csrf: csrfToken, barang_id: item._mapId, barcode: code, keterangan: '' })
                }).then(r => r.json()).then(res => {
                    if (res.success) {
                        vendorBarcodeSet.add(codeLower);
                        // Tambahkan/koreksi lookup map agar scan vendor barcode langsung ketemu item yang benar
                        dummyDB[code] = item;
                        itemsWithVendorBarcode.add(Number(item._mapId));
                    } else {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Barcode Sudah Terdaftar',
                            text: res.message || `Barcode "${code}" sudah dipetakan ke item lain — penerimaan tetap tercatat, tapi barcode ini TIDAK otomatis dikenali untuk item ${item.nama_barang} di scan berikutnya.`,
                        });
                    }
                }).catch(() => {});
            } else {
                // Tandai item sudah punya vendor barcode — BIS scan berikutnya skip prompt
                itemsWithVendorBarcode.add(Number(item._mapId));
            }
        }
        bootstrap.Modal.getInstance(document.getElementById('modalPrintPopup')).hide();
        const now = new Date().toLocaleTimeString('id-ID');
        addHistory(now, code, item.nama_barang, true);
        showToast(`Penerimaan ${item.nama_barang} dikonfirmasi.`, 'success');
        saveInboundLog('confirm');
        markOdooItemProcessed(window._activeOdooRowIdx);
        window._activeOdooRowIdx = null;
        window._activeOdooItem = null;
        // Simpan semua ED ke DB
        if (eds.length && item._mapId) {
            eds.forEach(e => saveEDtoDB(item._mapId, e.date, e.ket || ''));
        }
        // Sinkronkan track_ed ke master HANYA kalau user memang sengaja mengubah toggle — jangan
        // biarkan ED lama yang masih aktif diam-diam menyalakan lagi track_ed yang sengaja dimatikan.
        if (window._printEDManuallyChanged) syncTrackEdState(item._mapId, wantED);
        return;
    }

    _printLabelInFlight = true;
    document.getElementById('printFooterBtn').disabled = true;
    const qty = parseInt(document.getElementById('printQtyModal').value) || 1;
    bootstrap.Modal.getInstance(document.getElementById('modalPrintPopup')).hide();
    document.getElementById('printQty').value = qty;
    // Print label with first ED (label only covers one batch at a time)
    openPrintModal(eds.length ? { date: eds[0].date, ket: eds[0].ket } : null);
    saveInboundLog('print_label');
    markOdooItemProcessed(window._activeOdooRowIdx);
    window._activeOdooRowIdx = null;
    window._activeOdooItem = null;

    // Simpan semua ED ke DB
    if (eds.length && window._printItem?._mapId) {
        eds.forEach(e => saveEDtoDB(window._printItem._mapId, e.date, e.ket || ''));
    }
    // Sinkronkan track_ed ke master HANYA kalau user memang sengaja mengubah toggle — jangan
    // biarkan ED lama yang masih aktif diam-diam menyalakan lagi track_ed yang sengaja dimatikan.
    if (window._printEDManuallyChanged) syncTrackEdState(window._printItem?._mapId, wantED);

    // Update barcode_internal di master barang jika belum ada
    const printedItem = window._printItem || currentFoundItem;
    if (printedItem && printedItem.id) {
        const bisCode = 'BIS-' + String(printedItem.kode_barang).padStart(4, '0');
        if (!printedItem.barcode_internal) {
            fetch('api/update_barcode_internal.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf: csrfToken, barang_id: printedItem.id, barcode_internal: bisCode })
            }).then(r => r.json()).then(res => {
                if (res.success) printedItem.barcode_internal = bisCode;
            });
        }
    }
}

// ── Custom Searchable Dropdown ────────────────────────────────────────────
function toggleCustomSelect() {
    const dd = document.getElementById('customSelectDropdown');
    const isOpen = dd.style.display !== 'none';
    dd.style.display = isOpen ? 'none' : 'block';
    if (!isOpen) {
        const search = document.getElementById('customSelectSearch');
        search.value = '';
        filterCustomSelect('');
        setTimeout(() => search.focus(), 50);
    }
}

function filterCustomSelect(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#customSelectOptions .custom-select-option').forEach(opt => {
        const name = opt.dataset.name || opt.textContent.toLowerCase();
        opt.classList.toggle('hidden', term !== '' && !name.includes(term));
    });
}

function selectCustomOption(el) {
    const value = el.dataset.value;
    const label = el.textContent.trim();
    // Update trigger label
    const triggerLabel = document.getElementById('customSelectLabel');
    triggerLabel.textContent = value ? label : '— Pilih Item —';
    triggerLabel.classList.toggle('text-muted', !value);
    // Highlight selected
    document.querySelectorAll('#customSelectOptions .custom-select-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    // Sync hidden select
    const select = document.getElementById('selectMapping');
    select.value = value;
    // Trigger onchange
    select.dispatchEvent(new Event('change'));
    // Close dropdown
    document.getElementById('customSelectDropdown').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('customSelectWrap');
    if (wrap && !wrap.contains(e.target)) {
        const dd = document.getElementById('customSelectDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// Label size toggle
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.pl-size-btn');
    if (!btn) return;
    const group = btn.closest('[id$="SizeBtns"]');
    if (!group) return;
    group.querySelectorAll('.pl-size-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
});

// Reset custom select when modal closed
document.getElementById('modalMapping').addEventListener('hidden.bs.modal', function() {
    const triggerLabel = document.getElementById('customSelectLabel');
    if (triggerLabel) { triggerLabel.textContent = '— Pilih Item —'; triggerLabel.classList.add('text-muted'); }
    document.querySelectorAll('#customSelectOptions .custom-select-option').forEach(o => o.classList.remove('selected'));
    const dd = document.getElementById('customSelectDropdown');
    if (dd) dd.style.display = 'none';
    const labelWrap = document.getElementById('mappingLabelConfig');
    if (labelWrap) labelWrap.style.display = 'none';
    const qtyRow = document.getElementById('mappingPrintQtyRow');
    if (qtyRow) qtyRow.style.display = 'none';
    document.getElementById('mappingPrintQty').value = 1;
    const saveBtn = document.getElementById('mappingSaveBtn');
    if (saveBtn) { saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Mapping'; saveBtn.style.background = '#f59e0b'; }
});
// ─────────────────────────────────────────────────────────────────────────────

function onMappingSelectChange() {
    const select = document.getElementById('selectMapping');
    const opt    = select.options[select.selectedIndex];
    const itemId = opt ? parseInt(opt.value) : 0;
    const isTrackED = opt && opt.dataset.trackEd === '1';

    // Auto-set toggle sesuai config item, tapi user bisa override
    const toggle = document.getElementById('toggleMappingED');
    toggle.checked = isTrackED;
    document.getElementById('mappingEDInput').value = '';
    document.getElementById('mappingEDKet').value = '';

    // Update ED toggle card style
    const edCard = document.getElementById('edToggleCard');
    edCard.classList.toggle('active-ed', isTrackED);

    renderEDSection(itemId, isTrackED);
    renderMappingLabelConfig(itemId);
}

function renderMappingLabelConfig(itemId) {
    const wrap    = document.getElementById('mappingLabelConfig');
    const body    = document.getElementById('mappingLabelConfigBody');
    const qtyRow  = document.getElementById('mappingPrintQtyRow');
    const saveBtn = document.getElementById('mappingSaveBtn');
    if (!itemId) {
        wrap.style.display = 'none';
        qtyRow.style.display = 'none';
        if (saveBtn) { saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Mapping'; saveBtn.style.background = '#f59e0b'; }
        return;
    }

    const it = allItems.find(x => x.id == itemId);
    if (!it) {
        wrap.style.display = 'none';
        qtyRow.style.display = 'none';
        if (saveBtn) { saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Mapping'; saveBtn.style.background = '#f59e0b'; }
        return;
    }

    body.innerHTML = buildLabelConfigHTML({
        label_print: it.label_print ?? null,
        label_placement: it.label_placement ?? null,
        label_config_set: it.label_config_set ?? false,
    });
    wrap.style.display = 'block';

    if (!(it.label_config_set ?? false)) {
        _ibCurrentItemId = itemId;
        _ibWizAnswers = new Array(10).fill(null);
        _ibOverrideActive = false;
        _ibSelLP = null; _ibSelPlace = null;
        _ibWizContext = 'mapping';
        _ibPrintItem  = null;
        ibLcRender();
    }

    const isPhysical = it.label_config_set && it.label_print === 'physical';
    qtyRow.style.display = isPhysical ? 'flex' : 'none';
    if (saveBtn) {
        if (isPhysical) {
            saveBtn.innerHTML = '<i class="fas fa-print me-2"></i>Simpan & Print';
            saveBtn.style.background = '#204EAB';
        } else {
            saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Mapping';
            saveBtn.style.background = '#f59e0b';
        }
    }
}

function onMappingEDToggle() {
    const select = document.getElementById('selectMapping');
    const opt    = select.options[select.selectedIndex];
    const itemId = opt ? parseInt(opt.value) : 0;
    const on     = document.getElementById('toggleMappingED').checked;

    // Update toggle card style
    const edCard = document.getElementById('edToggleCard');
    edCard.classList.toggle('active-ed', on);

    renderEDSection(itemId, on);
}

function renderEDSection(itemId, show) {
    const section = document.getElementById('mappingEDSection');
    section.style.display = show ? 'block' : 'none';
    if (!show) return;

    const existing = activeEDByItemId[itemId] || [];
    const existingWrap = document.getElementById('existingEDWrap');
    const list = document.getElementById('existingEDList');

    if (existing.length > 0) {
        existingWrap.style.display = 'block';
        list.innerHTML = existing.map((e, i) => `
            <label class="ed-chip border rounded px-3 py-2 d-flex align-items-center gap-2"
                style="cursor:pointer;font-size:13px;background:${i===0?'#e8f0fd':'#fff'};transition:background .15s;${i===0?'border-color:#204EAB;':''} ">
                <input type="radio" name="existingED" value="${e.date}" ${i===0?'checked':''}
                    onchange="onExistingEDSelect('${e.date}')">
                <i class="fas fa-calendar-check text-success me-1"></i>
                <span class="fw-semibold">${formatED(e.date)}</span>
                ${e.ket ? `<span class="text-muted" style="font-size:11px;">· ${e.ket}</span>` : ''}
            </label>`).join('') + `
            <label class="ed-chip border rounded px-3 py-2 d-flex align-items-center gap-2"
                style="cursor:pointer;font-size:13px;background:#fff;">
                <input type="radio" name="existingED" value="__new__"
                    onchange="onExistingEDSelect('__new__')">
                <i class="fas fa-plus text-primary me-1"></i>
                <span class="fw-semibold">ED baru...</span>
            </label>`;
        document.getElementById('newEDWrap').style.display = 'none';
        // Auto-set input ke batch pertama agar langsung valid saat Simpan
        document.getElementById('mappingEDInput').value = existing[0].date;
        document.getElementById('mappingEDKet').value = existing[0].ket || '';
    } else {
        existingWrap.style.display = 'none';
        document.getElementById('newEDWrap').style.display = 'block';
        document.getElementById('mappingEDInput').focus();
    }
}

function onExistingEDSelect(val) {
    const newWrap = document.getElementById('newEDWrap');
    const select  = document.getElementById('selectMapping');
    const itemId  = select ? (parseInt(select.value) || 0) : 0;
    const existing = itemId ? (activeEDByItemId[itemId] || []) : [];
    if (val === '__new__') {
        newWrap.style.display = 'block';
        document.getElementById('mappingEDInput').value = '';
        document.getElementById('mappingEDKet').value = '';
        document.getElementById('mappingEDInput').focus();
    } else {
        newWrap.style.display = 'none';
        document.getElementById('mappingEDInput').value = val;
        const found = existing.find(e => e.date === val);
        document.getElementById('mappingEDKet').value = found?.ket || '';
    }
    // Visual highlight chip terpilih
    document.querySelectorAll('.ed-chip').forEach(c => {
        c.style.background = c.querySelector('input').checked ? '#e8f0fd' : '#fff';
        c.style.borderColor = c.querySelector('input').checked ? '#204EAB' : '';
    });
}

function onNewEDInput() {
    // Deselect existing radio jika user ketik manual
    document.querySelectorAll('input[name="existingED"]').forEach(r => { r.checked = false; });
    document.querySelectorAll('.ed-chip').forEach(c => { c.style.background='#fff'; c.style.borderColor=''; });
}

function saveMapping() {
    const select = document.getElementById('selectMapping');
    const selectedOption = select.options[select.selectedIndex];
    if (!select.value) { Swal.fire({ icon: 'warning', title: 'Pilih item terlebih dahulu' }); return; }

    const barangId     = parseInt(select.value);
    const selectedText = selectedOption.text.replace(/\s*★ ED\s*$/, '').trim();
    const kodeBarang   = selectedOption.dataset.kode;
    const wantED       = document.getElementById('toggleMappingED').checked;

    // Validasi ED jika toggle ON
    let chosenED = null;
    if (wantED) {
        chosenED = document.getElementById('mappingEDInput').value;
        if (!chosenED) {
            Swal.fire({ icon: 'warning', title: 'ED belum dipilih', text: 'Pilih ED yang berjalan atau input ED baru.' });
            return;
        }
        // No. batch/lot wajib diisi
        const edKet = document.getElementById('mappingEDKet')?.value.trim() ?? '';
        if (!edKet) {
            Swal.fire({ icon: 'warning', title: 'No. batch / lot wajib diisi', text: 'Masukkan nomor batch atau lot untuk ED ini.' });
            document.getElementById('mappingEDKet')?.focus();
            return;
        }
        chosenED = { date: chosenED, ket: edKet };
    }

    // Modal ini juga dipakai untuk item Odoo yang namanya tidak match ke master lokal (bukan barcode
    // asli yang di-scan) — dalam kasus itu jangan daftarkan teks nama produk sbg "barcode vendor".
    if (window._unknownIsBarcode === false) {
        finalizeSaveMapping();
        return;
    }

    // Modal ini hanya terbuka untuk barcode yang belum dikenali sama sekali (tidak ada di dummyDB),
    // jadi selalu verifikasi ke server di sini — jangan asumsikan aman hanya karena barcode-nya
    // pernah "terlihat" sebelumnya (vendorBarcodeSet tidak tahu barcode itu milik item yang mana).
    const _bc = (currentUnknown || '').toLowerCase();

    // Cek & simpan mapping barcode→item ke DB dulu (UNIQUE per barcode) sebelum menutup modal —
    // supaya kalau barcode ini ternyata sudah terdaftar ke item lain, user diberi tahu & tidak
    // salah kira mapping-nya berhasil (lihat juga: 1 barcode wajib hanya 1 item).
    const saveBtn = document.getElementById('mappingSaveBtn');
    if (saveBtn) saveBtn.disabled = true;
    fetch('api/vendor_barcodes.php?action=add', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({_csrf: csrfToken, barang_id: select.value, barcode: currentUnknown, keterangan: ''})
    }).then(r => r.json()).then(res => {
        if (saveBtn) saveBtn.disabled = false;
        if (res.success) {
            vendorBarcodeSet.add(_bc);
            itemsWithVendorBarcode.add(barangId);
            finalizeSaveMapping();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Barcode Sudah Terdaftar',
                text: res.message || `Barcode "${currentUnknown}" sudah dipetakan ke item lain, tidak bisa dipakai untuk item ini juga. Pilih item yang benar, atau hapus mapping lama dulu di Database Barang.`,
            });
        }
    }).catch(() => {
        if (saveBtn) saveBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Gagal Menyimpan', text: 'Terjadi kesalahan koneksi. Coba lagi.' });
    });

    function finalizeSaveMapping() {
        bootstrap.Modal.getInstance(document.getElementById('modalMapping')).hide();
        const mappedAllItem = allItems.find(x => String(x.id) === String(select.value));
        const mappedItem = {
            kode_barang: kodeBarang,
            nama_barang: mappedAllItem?.nama_barang ?? selectedText,
            satuan: mappedAllItem?.satuan ?? '-',
            track_ed: wantED ? 1 : 0,
            label_print: mappedAllItem?.label_print ?? null,
            label_placement: mappedAllItem?.label_placement ?? null,
            label_config_set: mappedAllItem?.label_config_set ?? 0,
        };
        dummyDB[currentUnknown] = mappedItem;
        currentFoundItem = mappedItem;
        currentFoundBarcode = currentUnknown;
        updateHistoryRow(currentUnknown, selectedText);

        // Reset toggle & form untuk scan berikutnya
        document.getElementById('toggleMappingED').checked = false;
        document.getElementById('mappingEDSection').style.display = 'none';
        document.getElementById('mappingEDInput').value = '';
        if (document.getElementById('mappingEDKet')) document.getElementById('mappingEDKet').value = '';

        // Update result card ke "ditemukan"
        showResult(true, currentUnknown, dummyDB[currentUnknown]);

        // Sinkronkan status track_ed di inventory_barang sesuai posisi toggle (ON maupun OFF)
        syncTrackEdState(barangId, wantED);

        // Simpan ED ke DB jika diisi (gunakan barangId yang sudah di-capture)
        if (chosenED && barangId) {
            const edObj = typeof chosenED === 'object' ? chosenED : { date: chosenED, ket: '' };
            saveEDtoDB(barangId, edObj.date, edObj.ket || '');
        }

        // Print langsung di modal yang sama jika konfigurasi label = cetak fisik
        if (mappedItem.label_config_set && mappedItem.label_print === 'physical') {
            mappedItem._mapId = barangId;
            const qty = parseInt(document.getElementById('mappingPrintQty').value) || 1;
            document.getElementById('printQty').value = qty;
            document.getElementById('mappingPrintQty').value = 1; // reset
            const edForPrint = chosenED ? { date: chosenED.date, ket: chosenED.ket } : null;
            openPrintModal(edForPrint);
        } else {
            Swal.fire({toast:true, position:'bottom-end', icon:'success',
                title:'Mapping tersimpan — barcode dikenal di scan berikutnya',
                showConfirmButton:false, timer:2500, timerProgressBar:true});
        }
    }
}

// ── Helper: simpan ED ke inventory_barang_ed ──────────────────────────────────
function saveEDtoDB(barangId, edDate, keterangan) {
    if (!barangId || !edDate) return;
    const body = new URLSearchParams({_csrf: csrfToken, barang_id: barangId, ed_date: edDate, keterangan: keterangan || ''});
    fetch('api/save_barang_ed.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body})
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                // Tambahkan ke cache ED lokal supaya modal berikutnya langsung muncul batch ini
                if (!activeEDByItemId[barangId]) activeEDByItemId[barangId] = [];
                const already = activeEDByItemId[barangId].some(e => e.date === edDate);
                if (!already) activeEDByItemId[barangId].push({id: res.id, date: edDate, ket: keterangan || ''});
                if (!res.already_exists) {
                    Swal.fire({toast:true, position:'bottom-end', icon:'success',
                        title:'ED tersimpan: ' + formatED(edDate), showConfirmButton:false, timer:2000, timerProgressBar:true});
                }
            } else {
                Swal.fire({toast:true, position:'bottom-end', icon:'error',
                    title:'Gagal simpan ED', text: res.message || '', showConfirmButton:false, timer:4000});
            }
        })
        .catch(() => {
            Swal.fire({toast:true, position:'bottom-end', icon:'warning',
                title:'Gagal simpan ED ke database', showConfirmButton:false, timer:3000});
        });
}

function saveInboundLog(action) {
    const item    = window._printItem || currentFoundItem;
    const odooIt  = window._activeOdooItem || null;
    const barangId = item?._mapId || item?.id;
    if (!barangId) return;
    fetch('api/save_inbound_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            _csrf:       csrfToken,
            barang_id:   barangId,
            kode_barang: item?.kode_barang || '',
            nama_barang: item?.nama_barang || '',
            qty:         odooIt ? (odooIt.qty ?? odooIt.demand ?? null) : null,
            uom:         odooIt ? (odooIt.uom || '') : (item?.uom || item?.satuan || ''),
            odoo_ref:    window._currentOdooRef || '',
            action:      action
        })
    }).catch(() => {});
}
function showToast(msg, type) {
    Swal.fire({toast:true, position:'bottom-end', icon: type==='success'?'success':'warning',
        title: msg, showConfirmButton:false, timer:2500, timerProgressBar:true});
}

function clearHistory() {
    scanHistory = [];
    document.getElementById('historyFeed').innerHTML = '';
    document.getElementById('historyFeed').style.display = 'none';
    document.getElementById('historyEmpty').style.display = 'block';
    document.getElementById('resultCard').style.display = 'none';
    document.getElementById('resultEmpty').classList.remove('d-none');

    // Reset scan status
    const statusEl = document.getElementById('scanStatus');
    statusEl.className = 'scan-status idle';
    document.getElementById('scanStatusText').textContent = 'Menunggu scan...';
    const ring = document.getElementById('scanIconRing');
    ring.className = 'scan-icon-ring pulse';
}
</script>
