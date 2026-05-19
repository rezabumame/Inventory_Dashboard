<?php
// Allow petugas_hc + admin roles (admin hanya untuk testing/preview)
$qr_role = (string)($_SESSION['role'] ?? '');
$qr_allowed = ['petugas_hc', 'admin_klinik', 'super_admin', 'spv_klinik'];
if (!in_array($qr_role, $qr_allowed, true)) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;"><h3>Akses Ditolak</h3><p>Halaman ini hanya untuk Petugas HC.</p></div>';
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$klinik_id = (int)($_GET['klinik_id'] ?? (int)$_SESSION['klinik_id']);

// Validasi klinik
$klinik_row = null;
if ($klinik_id > 0) {
    $klinik_row = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
}
if (!$klinik_row && $qr_role !== 'super_admin') {
    $klinik_id  = (int)$_SESSION['klinik_id'];
    $klinik_row = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
}

// Untuk admin yang preview: gunakan nama sesuai session
$is_nakes   = ($qr_role === 'petugas_hc');
$nama_nakes  = htmlspecialchars((string)$_SESSION['nama_lengkap']);
$nama_klinik = htmlspecialchars((string)($klinik_row['nama_klinik'] ?? '-'));

// Pending request (hanya relevan untuk petugas_hc)
$pending_count = 0;
if ($is_nakes) {
    $pr = $conn->query("SELECT COUNT(*) AS c FROM inventory_hc_transfer_request WHERE user_hc_id=$user_id AND klinik_id=$klinik_id AND status='pending'");
    if ($pr) $pending_count = (int)($pr->fetch_assoc()['c'] ?? 0);
}

// Pending request
$pending_count = 0;
$pr = $conn->query("SELECT COUNT(*) AS c FROM inventory_hc_transfer_request WHERE user_hc_id=$user_id AND klinik_id=$klinik_id AND status='pending'");
if ($pr) $pending_count = (int)($pr->fetch_assoc()['c'] ?? 0);

// Daftar item - semua barang, qty dari stok klinik (boleh 0/minus)
$klinik_items = [];
$res_ki = $conn->query("
    SELECT b.id AS barang_id, b.nama_barang,
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           COALESCE(sgk.qty, 0) AS qty
    FROM inventory_barang b
    LEFT JOIN inventory_stok_gudang_klinik sgk ON sgk.barang_id = b.id AND sgk.klinik_id = $klinik_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY b.nama_barang ASC
");
while ($res_ki && ($ri = $res_ki->fetch_assoc())) {
    $klinik_items[] = $ri;
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Request Stok – <?= $nama_klinik ?></title>
<link rel="icon" type="image/x-icon" href="<?= base_url('assets/img/favicon.ico') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--primary:#204EAB;--primary-light:#e8eef8;--danger:#dc3545;}
*{box-sizing:border-box;}
body{background:#f0f4fb;font-family:'Segoe UI',sans-serif;min-height:100vh;padding-bottom:80px;}

/* Header */
.qr-header{background:var(--primary);color:#fff;padding:14px 16px 12px;position:sticky;top:0;z-index:200;box-shadow:0 2px 8px rgba(0,0,0,.15);}
.qr-header-top{display:flex;align-items:center;justify-content:space-between;}
.qr-header-top .btn-bag{background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:8px;padding:6px 12px;font-size:13px;display:flex;align-items:center;gap:6px;}
.qr-header-info{margin-top:8px;}
.qr-header-info .name{font-size:17px;font-weight:700;line-height:1.2;}
.qr-header-info .sub{font-size:12px;opacity:.82;margin-top:2px;}

/* Pending banner */
.pending-banner{background:#fff3cd;border-left:4px solid #ffc107;padding:10px 16px;font-size:13px;display:flex;align-items:center;gap:8px;}

/* Card section */
.section-card{background:#fff;border-radius:14px;margin:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden;}
.section-title{padding:14px 16px 0;font-size:14px;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:8px;}

/* Item rows */
.item-list{padding:10px 12px 4px;}
.item-row{
    display:flex;align-items:stretch;gap:0;
    background:#f8faff;border:1.5px solid #dde8ff;border-radius:12px;
    margin-bottom:10px;transition:border-color .15s;
    position:relative;
}
.item-row.is-filled{border-color:#204EAB;background:#f0f5ff;}
.item-row.is-error{border-color:var(--danger)!important;background:#fff5f5!important;}
.item-row-main{flex:1;padding:10px 12px;}
.item-row-remove{
    display:flex;align-items:center;justify-content:center;
    width:44px;flex-shrink:0;
    background:#fee2e2;border:none;border-left:1.5px solid #fecaca;
    color:#ef4444;font-size:18px;cursor:pointer;transition:background .15s;
    border-radius:0 12px 12px 0;
}
.item-row-remove:active{background:#fecaca;}

/* Search */
.searchable-wrap{position:relative;}
.search-input{
    width:100%;border:none;background:transparent;
    font-size:14px;font-weight:500;color:#1e293b;
    outline:none;padding:0;line-height:1.4;
}
.search-input::placeholder{color:#94a3b8;font-weight:400;}
.search-dropdown{position:fixed;background:#fff;border:1.5px solid #c7d8ff;border-radius:10px;box-shadow:0 6px 20px rgba(32,78,171,.12);max-height:200px;overflow-y:auto;z-index:9999;display:none;}
.search-dropdown.open{display:block;}
.search-opt{padding:10px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid #f0f4ff;display:flex;justify-content:space-between;align-items:center;}
.search-opt:last-child{border-bottom:none;}
.search-opt:hover{background:#edf2ff;}
.search-opt .opt-uom{font-size:11px;color:#94a3b8;font-weight:600;}
.search-opt.no-result{color:#aaa;cursor:default;justify-content:center;}

/* Qty row */
.qty-row{display:flex;align-items:center;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #e2e8f0;}
.qty-label{font-size:12px;color:#64748b;white-space:nowrap;}
.qty-controls{display:flex;align-items:center;gap:4px;}
.qty-btn{width:30px;height:30px;border-radius:6px;border:1.5px solid #c7d8ff;background:#fff;color:var(--primary);font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;line-height:1;}
.qty-btn:active{background:#edf2ff;}
.qty-input{width:56px;border:1.5px solid #c7d8ff;border-radius:8px;padding:5px 4px;font-size:15px;font-weight:700;text-align:center;color:#1e293b;outline:none;}
.qty-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(32,78,171,.1);}
.uom-label{font-size:12px;font-weight:700;color:var(--primary);}

/* Empty state */
.items-empty{text-align:center;padding:20px 16px 10px;color:#94a3b8;font-size:13px;}
.items-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.4;}

.btn-add-row{margin:4px 12px 14px;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--primary);color:#fff;border:none;border-radius:10px;padding:12px 16px;font-size:14px;font-weight:600;width:calc(100% - 24px);letter-spacing:.2px;}

/* Foto */
.foto-area{padding:14px 16px;}
.foto-label{font-size:13px;color:#666;margin-bottom:8px;}
.foto-preview{width:100%;border-radius:10px;max-height:200px;object-fit:cover;display:none;margin-top:8px;}
.btn-foto{background:#f5f7fb;border:2px dashed #c5d0e6;border-radius:10px;padding:16px;text-align:center;width:100%;color:#7a8eab;font-size:13px;cursor:pointer;}
.btn-foto i{font-size:22px;display:block;margin-bottom:6px;}

/* Submit */
.submit-bar{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e8ecf4;padding:12px 16px;z-index:300;}
.btn-submit{width:100%;background:var(--primary);color:#fff;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;}
.btn-submit:disabled{background:#a0b0d0;}

/* Drawer */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;display:none;opacity:0;transition:opacity .25s;}
.drawer-overlay.open{display:block;opacity:1;}
.drawer{position:fixed;top:0;left:0;bottom:0;width:80%;max-width:320px;background:#fff;z-index:501;transform:translateX(-100%);transition:transform .28s cubic-bezier(.4,0,.2,1);box-shadow:4px 0 20px rgba(0,0,0,.15);display:flex;flex-direction:column;}
.drawer.open{transform:translateX(0);}
.drawer-header{background:var(--primary);color:#fff;padding:16px;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center;}
.drawer-header .btn-close-drawer{background:none;border:none;color:#fff;font-size:20px;}
.drawer-body{overflow-y:auto;flex:1;padding:12px 0;}
.bag-item{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f0f0f0;font-size:13px;}
.bag-item .qty{font-weight:700;color:var(--primary);white-space:nowrap;margin-left:8px;}
.bag-item.is-negative{background:#fff5f5;}
.bag-item.is-negative .qty{color:var(--danger);}
.bag-section-header{padding:8px 16px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;background:#f8f8f8;border-bottom:1px solid #f0f0f0;}
.bag-section-header.danger{color:#dc3545;background:#fff5f5;}
.bag-empty{padding:24px 16px;text-align:center;color:#aaa;font-size:13px;}
</style>
</head>
<body>

<!-- Drawer Isi Tas -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="bagDrawer">
    <div class="drawer-header">
        <span><i class="fas fa-briefcase-medical me-2"></i>Isi Tas Saya</span>
        <button class="btn-close-drawer" id="closeDrawer"><i class="fas fa-times"></i></button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="bag-empty"><i class="fas fa-box-open fa-2x mb-2 d-block"></i>Memuat...</div>
    </div>
</div>

<!-- Header -->
<div class="qr-header">
    <div class="qr-header-top">
        <button class="btn-bag" id="openDrawer">
            <i class="fas fa-briefcase-medical"></i> Isi Tas
        </button>
        <div style="display:flex;align-items:center;gap:10px;">
            <small style="opacity:.7;font-size:11px;"><?= date('d M Y, H:i') ?></small>
            <a href="<?= base_url('index.php?page=logout') ?>" class="btn-bag" style="text-decoration:none;background:rgba(255,255,255,.15);" onclick="return confirm('Yakin ingin logout?')">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
    <div class="qr-header-info">
        <div class="name"><?= $nama_nakes ?></div>
        <div class="sub"><i class="fas fa-map-marker-alt me-1"></i><?= $nama_klinik ?></div>
    </div>
</div>

<?php if ($pending_count > 0): ?>
<div class="pending-banner">
    <i class="fas fa-clock text-warning"></i>
    <span>Anda memiliki <strong><?= $pending_count ?> request</strong> yang sedang menunggu persetujuan admin.</span>
</div>
<?php endif; ?>

<!-- Form Request -->
<form id="requestForm" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="klinik_id" value="<?= $klinik_id ?>">

    <div class="section-card">
        <div class="section-title"><i class="fas fa-box"></i> Item yang Diminta</div>
        <?php if (!empty($klinik_items)): ?>
        <div class="item-list" id="itemRows">
            <div class="items-empty" id="emptyHint">
                <i class="fas fa-plus-circle"></i>
                Tekan tombol di bawah untuk menambah item
            </div>
        </div>
        <button type="button" class="btn-add-row" id="addRowBtn">
            <i class="fas fa-plus-circle"></i> Tambah Item
        </button>
        <?php else: ?>
        <div class="px-4 py-3 text-muted small" style="padding:14px 16px;">Tidak ada stok tersedia di klinik ini.</div>
        <?php endif; ?>
    </div>

    <!-- Foto -->
    <div class="section-card">
        <div class="section-title"><i class="fas fa-camera"></i> Foto Bukti <span style="font-weight:400;font-size:11px;color:#aaa;margin-left:4px;">(opsional)</span></div>
        <div class="foto-area">
            <input type="file" name="foto" id="fotoInput" accept="image/*" capture="environment" style="display:none;">
            <div class="btn-foto" onclick="document.getElementById('fotoInput').click()">
                <i class="fas fa-camera"></i>
                Ambil Foto
            </div>
            <img id="fotoPreview" class="foto-preview" alt="Preview foto">
            <div id="fotoName" style="font-size:12px;color:#888;margin-top:6px;"></div>
        </div>
    </div>

    <!-- Catatan -->
    <div class="section-card" style="margin-bottom:4px;">
        <div class="section-title"><i class="fas fa-sticky-note"></i> Catatan <span style="font-weight:400;font-size:11px;color:#aaa;margin-left:4px;">(opsional)</span></div>
        <div style="padding:10px 16px 14px;">
            <textarea name="catatan" class="form-control" rows="2" placeholder="Tuliskan catatan jika ada..." style="font-size:13px;border-radius:8px;"></textarea>
        </div>
    </div>
</form>

<!-- Submit bar -->
<div class="submit-bar">
    <button type="button" class="btn-submit" id="submitBtn" <?= empty($klinik_items) ? 'disabled' : '' ?>>
        <i class="fas fa-paper-plane me-2"></i>Kirim Request
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const klinikId = <?= $klinik_id ?>;
const baseUrl  = '<?= base_url() ?>';
let rowCount   = 1;

// Dataset item dari server
const allItems = <?= json_encode(array_values($klinik_items), JSON_UNESCAPED_UNICODE) ?>;

// ── Drawer ───────────────────────────────────────────────────────────────────
const drawerOverlay = document.getElementById('drawerOverlay');
const bagDrawer     = document.getElementById('bagDrawer');
document.getElementById('openDrawer').addEventListener('click', openDrawer);
document.getElementById('closeDrawer').addEventListener('click', closeDrawer);
drawerOverlay.addEventListener('click', closeDrawer);

function openDrawer() {
    drawerOverlay.classList.add('open');
    bagDrawer.classList.add('open');
    loadBag();
}
function closeDrawer() {
    drawerOverlay.classList.remove('open');
    bagDrawer.classList.remove('open');
}
function loadBag() {
    const body = document.getElementById('drawerBody');
    fetch(baseUrl + 'api/get_hc_bag.php?klinik_id=' + klinikId)
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.items.length) {
                body.innerHTML = '<div class="bag-empty"><i class="fas fa-box-open fa-2x mb-2 d-block"></i>Tas kosong</div>';
                return;
            }
            const normal   = res.items.filter(it => it.qty >= 0);
            const negative = res.items.filter(it => it.qty < 0);
            let html = '';
            if (negative.length) {
                html += `<div class="bag-section-header danger"><i class="fas fa-exclamation-triangle me-1"></i>Stok Minus (${negative.length})</div>`;
                html += negative.map(it =>
                    `<div class="bag-item is-negative">
                        <span>${escHtml(it.nama_barang)}</span>
                        <span class="qty">${it.qty} ${escHtml(it.uom)}</span>
                    </div>`
                ).join('');
            }
            if (normal.length) {
                if (negative.length) html += `<div class="bag-section-header">Stok Normal (${normal.length})</div>`;
                html += normal.map(it =>
                    `<div class="bag-item">
                        <span>${escHtml(it.nama_barang)}</span>
                        <span class="qty">${it.qty} ${escHtml(it.uom)}</span>
                    </div>`
                ).join('');
            }
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div class="bag-empty">Gagal memuat data</div>'; });
}

// ── Searchable Dropdown ───────────────────────────────────────────────────────
function initSearchable(row) {
    const hiddenInput = row.querySelector('.hidden-barang-id');
    const searchInput = row.querySelector('.search-input');
    const dropdown    = row.querySelector('.search-dropdown');
    const uomLabel    = row.querySelector('.uom-label');

    function positionDropdown() {
        const rect = searchInput.getBoundingClientRect();
        dropdown.style.left  = rect.left + 'px';
        dropdown.style.top   = (rect.bottom + 4) + 'px';
        dropdown.style.width = rect.width + 'px';
    }

    function renderOptions(query) {
        const q = query.toLowerCase().trim();
        const filtered = q === '' ? allItems : allItems.filter(it => it.nama_barang.toLowerCase().includes(q));
        if (!filtered.length) {
            dropdown.innerHTML = '<div class="search-opt no-result">Item tidak ditemukan</div>';
        } else {
            dropdown.innerHTML = filtered.map(it =>
                `<div class="search-opt" data-id="${it.barang_id}" data-name="${escHtml(it.nama_barang)}" data-uom="${escHtml(it.uom)}">
                    <span>${escHtml(it.nama_barang)}</span>
                    <span class="opt-uom">${escHtml(it.uom)}</span>
                </div>`
            ).join('');
        }
        positionDropdown();
        dropdown.classList.add('open');
        dropdown.querySelectorAll('.search-opt[data-id]').forEach(opt => {
            opt.addEventListener('mousedown', e => {
                e.preventDefault();
                selectItem(opt.dataset.id, opt.dataset.name, opt.dataset.uom);
            });
        });
    }

    function selectItem(id, name, uom) {
        hiddenInput.value    = id;
        searchInput.value    = name;
        uomLabel.textContent = uom || '';
        dropdown.classList.remove('open');
        row.classList.add('is-filled');
        row.classList.remove('is-error');
        // Focus qty
        const qtyInput = row.querySelector('.qty-input');
        if (qtyInput && (!qtyInput.value || parseFloat(qtyInput.value) <= 0)) {
            setTimeout(() => { qtyInput.select(); qtyInput.focus(); }, 50);
        }
    }

    searchInput.addEventListener('focus', () => renderOptions(searchInput.value));
    searchInput.addEventListener('input', () => {
        hiddenInput.value = '';
        uomLabel.textContent = '';
        row.classList.remove('is-filled');
        renderOptions(searchInput.value);
    });
    searchInput.addEventListener('blur', () => setTimeout(() => dropdown.classList.remove('open'), 150));
    document.addEventListener('click', e => { if (!row.contains(e.target)) dropdown.classList.remove('open'); });
}

function buildRowHTML(id) {
    return `<div class="item-row" id="row-${id}">
        <div class="item-row-main">
            <div class="searchable-wrap">
                <input type="hidden" name="barang_id[]" class="hidden-barang-id">
                <input type="text" class="search-input" placeholder="Ketik nama item..." autocomplete="off">
                <div class="search-dropdown"></div>
            </div>
            <div class="qty-row">
                <span class="qty-label">Qty</span>
                <div class="qty-controls">
                    <button type="button" class="qty-btn" onclick="changeQty(this,-1)">−</button>
                    <input type="number" name="qty[]" class="qty-input" value="1" min="1" step="1" required>
                    <button type="button" class="qty-btn" onclick="changeQty(this,1)">+</button>
                </div>
                <span class="uom-label"></span>
            </div>
        </div>
        <button type="button" class="item-row-remove" onclick="removeRow(this)" title="Hapus item">
            <i class="fas fa-trash-alt" style="font-size:15px;"></i>
        </button>
    </div>`;
}

// ── Tambah Row ────────────────────────────────────────────────────────────────
document.getElementById('addRowBtn')?.addEventListener('click', () => {
    const id  = rowCount++;
    const container = document.getElementById('itemRows');
    const hint = document.getElementById('emptyHint');
    if (hint) hint.style.display = 'none';
    const tmp = document.createElement('div');
    tmp.innerHTML = buildRowHTML(id);
    const div = tmp.firstElementChild;
    container.appendChild(div);
    initSearchable(div);
    div.querySelector('.search-input').focus();
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});

function removeRow(btn) {
    const row = btn.closest('.item-row');
    row.remove();
    const remaining = document.querySelectorAll('.item-row');
    const hint = document.getElementById('emptyHint');
    if (hint) hint.style.display = remaining.length === 0 ? 'block' : 'none';
}

function changeQty(btn, delta) {
    const input = btn.closest('.qty-controls').querySelector('.qty-input');
    const cur = parseInt(input.value) || 0;
    input.value = Math.max(1, cur + delta);
}

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input')) {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
        if (e.target.value === '' || parseInt(e.target.value) < 1) e.target.value = 1;
    }
});

// ── Image compression ─────────────────────────────────────────────────────────
function compressImage(file, maxPx, quality) {
    return new Promise(resolve => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = new Image();
            img.onload = () => {
                let w = img.width, h = img.height;
                if (w > maxPx || h > maxPx) {
                    const r = Math.min(maxPx / w, maxPx / h);
                    w = Math.round(w * r); h = Math.round(h * r);
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                canvas.toBlob(blob => resolve(blob ?? new Blob([file])), 'image/jpeg', quality);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

let _compressedFotoBlob = null;

// ── Foto Preview ──────────────────────────────────────────────────────────────
document.getElementById('fotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    compressImage(file, 1280, 0.75).then(blob => {
        _compressedFotoBlob = blob;
        const url = URL.createObjectURL(blob);
        const prev = document.getElementById('fotoPreview');
        prev.src = url; prev.style.display = 'block';
        const kb = Math.round(blob.size / 1024);
        document.getElementById('fotoName').textContent = file.name + ` (${kb} KB)`;
    });
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('submitBtn').addEventListener('click', () => {
    const form = document.getElementById('requestForm');
    const hiddens = form.querySelectorAll('input.hidden-barang-id');
    if (hiddens.length === 0) {
        alert('Tambahkan minimal 1 item sebelum mengirim request.');
        document.getElementById('addRowBtn')?.focus();
        return;
    }
    for (const h of hiddens) {
        if (!h.value) {
            const si = h.closest('.item-row').querySelector('.search-input');
            alert('Pilih item dari daftar untuk semua baris.');
            si.focus();
            return;
        }
    }
    const qtys = form.querySelectorAll('input[name="qty[]"]');
    for (const q of qtys) {
        if (!q.value || parseFloat(q.value) <= 0) {
            alert('Isi qty untuk semua item.');
            q.focus();
            return;
        }
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengirim...';

    const fd = new FormData(form);
    if (_compressedFotoBlob) { fd.delete('foto'); fd.append('foto', _compressedFotoBlob, 'foto.jpg'); }
    fetch(baseUrl + 'api/submit_hc_request.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Request Terkirim!';
                btn.style.background = '#28a745';
                setTimeout(() => location.reload(), 1800);
            } else {
                alert(res.message || 'Gagal mengirim request.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Request';
            }
        })
        .catch(() => {
            alert('Terjadi kesalahan sistem.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Request';
        });
});

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
