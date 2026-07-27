<?php
// Allow petugas_hc + admin roles (admin hanya untuk testing/preview)
$qr_role = (string)($_SESSION['role'] ?? '');
$qr_allowed = ['petugas_hc', 'admin_klinik', 'super_admin', 'spv_klinik'];
if (!in_array($qr_role, $qr_allowed, true)) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;"><h3>Akses Ditolak</h3><p>Halaman ini hanya untuk Petugas HC.</p></div>';
    exit;
}

$user_id       = (int)$_SESSION['user_id'];
$own_klinik_id = (int)$_SESSION['klinik_id'];
$klinik_id     = (int)($_GET['klinik_id'] ?? $own_klinik_id);
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'opname') ? 'opname' : 'request';

// Selain super_admin, tidak boleh lihat data klinik lain lewat manipulasi klinik_id di URL —
// paksa balik ke klinik sendiri kalau beda.
if ($qr_role !== 'super_admin' && $klinik_id !== $own_klinik_id) {
    $klinik_id = $own_klinik_id;
}

// Validasi klinik
$klinik_row = null;
if ($klinik_id > 0) {
    $klinik_row = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
}
if (!$klinik_row && $qr_role !== 'super_admin') {
    $klinik_id  = $own_klinik_id;
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

// Check track_ed column existence once, used by both queries below
$r_bis = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'track_ed'");
$has_track_ed = ($r_bis && $r_bis->num_rows > 0);

// Daftar item - semua barang, qty dari stok klinik (boleh 0/minus)
$klinik_items = [];
$track_ed_sel2 = $has_track_ed ? 'b.track_ed,' : '0 AS track_ed,';
$res_ki = $conn->query("
    SELECT b.id AS barang_id, b.nama_barang,
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           COALESCE(uc.from_uom, '') AS from_uom,
           COALESCE(uc.multiplier, 1) AS multiplier,
           $track_ed_sel2
           COALESCE(sgk.qty, 0) AS qty
    FROM inventory_barang b
    LEFT JOIN inventory_stok_gudang_klinik sgk ON sgk.barang_id = b.id AND sgk.klinik_id = $klinik_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY b.nama_barang ASC
");
while ($res_ki && ($ri = $res_ki->fetch_assoc())) {
    $ri['track_ed'] = (int)($ri['track_ed'] ?? 0);
    $ri['multiplier'] = (float)($ri['multiplier'] ?? 1);
    $klinik_items[] = $ri;
}

// Resolusi opname_id HARUS sama persis dengan api/hc_stock_validate.php & api/get_hc_bag.php
// (prioritaskan sesi yang masih unlocked, fallback ke ID terakhir kalau semua terkunci) —
// dipakai utk cek barang_id mana yang SUDAH dilaporkan nakes periode ini.
$r_opname = $conn->query("SELECT id, periode FROM inventory_stok_opname WHERE klinik_id = $klinik_id
    ORDER BY (is_locked = 0 OR is_locked IS NULL) DESC, periode DESC, id DESC LIMIT 1");
$opname_row = $r_opname ? $r_opname->fetch_assoc() : null;
$opname_id_now = $opname_row ? (int)$opname_row['id'] : 0;
$opname_periode_label = ($opname_row && !empty($opname_row['periode']))
    ? date('F Y', strtotime($opname_row['periode'] . '-01')) : null;

$reported_bids = []; // barang_id => true, sudah ada qty_fisik utk (opname_id_now, user_id) periode ini
if ($opname_id_now > 0) {
    $r_rep = $conn->query("SELECT barang_id FROM inventory_stok_opname_detail
        WHERE opname_id = $opname_id_now AND tipe = 'hc' AND hc_user_id = $user_id AND qty_fisik IS NOT NULL");
    while ($r_rep && ($rr = $r_rep->fetch_assoc())) $reported_bids[(int)$rr['barang_id']] = true;
}

// Stok tas HC untuk opname — hanya item yang ada di tas dengan qty > 0
$track_ed_sel = $has_track_ed ? 'b.track_ed,' : '0 AS track_ed,';

$hc_bag_items = [];
$hc_bag_ids   = [];
// LEFT JOIN opname_detail periode berjalan supaya, kalau item ini sudah divalidasi admin
// (qty_aktual masuk ke stok tas → qty_sistem di bawah), laporan ASLI nakes (qty_fisik) tetap
// bisa dibedakan dari angka hasil validasi — bukan tertimpa begitu saja di tampilan nakes.
$opname_join = $opname_id_now > 0
    ? "LEFT JOIN inventory_stok_opname_detail d ON d.opname_id = $opname_id_now AND d.tipe = 'hc'
        AND d.hc_user_id = $user_id AND d.barang_id = st.barang_id"
    : '';
$res_bag = $conn->query("
    SELECT b.id AS barang_id, b.nama_barang, b.kode_barang,
           $track_ed_sel
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           COALESCE(uc.from_uom, '') AS from_uom,
           COALESCE(uc.multiplier, 1) AS multiplier,
           COALESCE(st.qty, 0) AS qty_sistem
           " . ($opname_join !== '' ? ', d.qty_fisik AS qty_lapor_raw, d.qty_aktual AS qty_aktual_now' : ', NULL AS qty_lapor_raw, NULL AS qty_aktual_now') . "
    FROM inventory_stok_tas_hc st
    JOIN inventory_barang b ON b.id = st.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    $opname_join
    WHERE st.user_id = $user_id AND st.klinik_id = $klinik_id AND st.qty > 0
    ORDER BY b.nama_barang ASC
");
while ($res_bag && ($rb = $res_bag->fetch_assoc())) {
    $bid = (int)$rb['barang_id'];
    $rb['track_ed']  = (int)($rb['track_ed'] ?? 0);
    $rb['multiplier'] = (float)($rb['multiplier'] ?? 1);
    // Item stok tas ini bisa saja SUDAH dilaporkan ulang periode ini juga — dikunci dari sisi nakes.
    $rb['already_reported'] = isset($reported_bids[$bid]);
    // Laporan asli nakes periode ini (kalau ada) — beda dari qty_sistem yang sudah jadi angka
    // hasil validasi admin begitu qty_aktual disimpan ke stok tas.
    $mult_rb = $rb['multiplier'] > 0 ? $rb['multiplier'] : 1;
    $rb['qty_lapor'] = ($rb['qty_lapor_raw'] !== null) ? (float)$rb['qty_lapor_raw'] / $mult_rb : null;
    // qty_aktual (sudah dalam to_uom, tidak perlu konversi) — HARUS scoped ke opname_id_now,
    // bukan qty_sistem (cache stok tas) yang bisa berisi angka lama lintas periode/reset.
    $rb['qty_aktual_now'] = ($rb['qty_aktual_now'] !== null) ? (float)$rb['qty_aktual_now'] : null;
    unset($rb['qty_lapor_raw']);
    $hc_bag_items[] = $rb;
    $hc_bag_ids[$bid] = true;
}

// Item yang sudah dilaporkan (self-report) periode ini tapi belum divalidasi admin
// (belum masuk inventory_stok_tas_hc) — tetap tampilkan pakai laporan sendiri sbg acuan,
// dan dikunci dari sisi nakes (sudah lapor = tidak bisa diubah lagi sampai admin koreksi
// lewat halaman SO, atau periode baru dibuka).
if ($opname_id_now > 0 && !empty($reported_bids)) {
    $res_pending = $conn->query("
        SELECT b.id AS barang_id, b.nama_barang, b.kode_barang,
               $track_ed_sel
               COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
               COALESCE(uc.from_uom, '') AS from_uom,
               COALESCE(uc.multiplier, 1) AS multiplier,
               d.qty_fisik AS qty_fisik_raw
        FROM inventory_stok_opname_detail d
        JOIN inventory_barang b ON b.id = d.barang_id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE d.opname_id = $opname_id_now AND d.tipe = 'hc' AND d.hc_user_id = $user_id
          AND d.qty_fisik IS NOT NULL
        ORDER BY b.nama_barang ASC
    ");
    while ($res_pending && ($rp = $res_pending->fetch_assoc())) {
        $bid = (int)$rp['barang_id'];
        if (isset($hc_bag_ids[$bid])) continue; // sudah ada dari stok tas (flag di-set di loop atas)
        $mult = (float)($rp['multiplier'] ?? 1);
        if ($mult <= 0) $mult = 1;
        // qty_fisik disimpan dalam from_uom (lihat hc_stock_validate.php) → konversi ke to_uom utk tampilan
        $rp['track_ed']   = (int)($rp['track_ed'] ?? 0);
        $rp['multiplier'] = $mult;
        $rp['qty_sistem'] = (float)$rp['qty_fisik_raw'] / $mult;
        $rp['already_reported'] = true; // sudah lapor periode ini — read-only utk nakes
        unset($rp['qty_fisik_raw']);
        $hc_bag_items[] = $rp;
        $hc_bag_ids[$bid] = true;
    }
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

/* ── Tab nav ─────────────────────────────────────────────────────────────── */
.tab-nav{display:flex;background:#fff;border-bottom:2px solid #e8ecf4;position:sticky;top:64px;z-index:190;}
.tab-btn{flex:1;padding:12px 8px;text-align:center;font-size:13px;font-weight:600;color:#64748b;background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;transition:all .15s;}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);}
.tab-pane{display:none;}
.tab-pane.active{display:block;}

/* ── Opname ──────────────────────────────────────────────────────────────── */
.op-item{background:#fff;border:1.5px solid #dde8ff;border-radius:12px;margin:10px 14px;padding:12px 14px;}
.op-item.has-selisih{border-color:#ef4444;background:#fff8f8;}
.op-item.ok{border-color:#10b981;background:#f0fdf4;}
.op-item.reported{background:#f8fafc;border-color:#e2e8f0;}
.op-item.reported .op-qty-input,.op-item.reported .qty-btn{opacity:.55;cursor:not-allowed;}
.badge-reported{display:inline-block;margin-left:6px;font-size:11px;font-weight:700;color:#059669;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:99px;padding:1px 8px;white-space:nowrap;}
.op-name{font-size:14px;font-weight:600;color:#1e293b;margin-bottom:2px;}
.op-sys{font-size:12px;color:#64748b;margin-bottom:10px;}

/* Ringkasan read-only utk item yang sudah dilaporkan periode ini (ganti stepper+input aktif) */
.op-report-summary{display:flex;align-items:center;gap:10px;margin-top:2px;}
.op-report-box{flex:1;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;text-align:center;}
.op-report-box.is-final{border-color:#c7d8ff;background:#f5f8ff;}
.op-report-label{font-size:10.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;}
.op-report-box.is-final .op-report-label{color:#204EAB;}
.op-report-val{font-size:17px;font-weight:700;color:#1e293b;margin-top:1px;}
.op-report-val .uom{font-size:11px;font-weight:600;color:#94a3b8;}
.op-report-arrow{color:#c7d0dc;font-size:13px;flex-shrink:0;}
.op-report-diff{flex-shrink:0;font-size:11px;font-weight:700;border-radius:99px;padding:3px 9px;white-space:nowrap;}
.op-report-diff.zero{color:#059669;background:#ecfdf5;}
.op-report-diff.minus{color:#b45309;background:#fffbeb;}
.op-report-diff.plus{color:#204EAB;background:#eff6ff;}
.op-qty-row{display:flex;align-items:center;gap:8px;}
.op-qty-label{font-size:12px;color:#64748b;white-space:nowrap;}
.op-qty-input{width:72px;border:1.5px solid #c7d8ff;border-radius:8px;padding:6px 6px;font-size:15px;font-weight:700;text-align:center;color:#1e293b;outline:none;}
.op-qty-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(32,78,171,.1);}
.op-selisih{font-size:12px;font-weight:700;margin-left:auto;}
.op-selisih.minus{color:#ef4444;}
.op-selisih.plus{color:#10b981;}
.op-selisih.zero{color:#64748b;}
.op-ed-row{margin-top:8px;padding-top:8px;border-top:1px solid #e8eef8;display:flex;gap:8px;flex-wrap:wrap;}
.op-ed-input{width:90px;border:1.5px solid #fde68a;border-radius:8px;padding:5px 6px;font-size:13px;font-weight:600;text-align:center;background:#fffbeb;outline:none;}
.op-ed-input:focus{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1);}
.op-ed-label{font-size:11px;color:#92400e;font-weight:600;white-space:nowrap;}
.op-catatan{margin-top:8px;width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 8px;font-size:12px;resize:none;outline:none;color:#374151;}
.op-catatan:focus{border-color:var(--primary);}
.op-empty{text-align:center;padding:40px 20px;color:#94a3b8;font-size:13px;}
.op-empty i{font-size:32px;display:block;margin-bottom:10px;opacity:.35;}
.op-item{position:relative;}
.op-del-btn{position:absolute;top:8px;right:8px;background:#fee2e2;border:none;border-radius:6px;padding:2px 7px;font-size:13px;color:#ef4444;cursor:pointer;line-height:1.4;}
.op-del-btn:active{background:#fecaca;}
.op-add-wrap{margin:0 14px 10px;position:relative;}
.op-add-input{width:100%;border:1.5px dashed #c7d8ff;border-radius:10px;background:#f8faff;padding:10px 14px;font-size:13px;color:#374151;outline:none;}
.op-add-input:focus{border-color:var(--primary);border-style:solid;background:#fff;}
.op-add-input::placeholder{color:#94a3b8;}
.op-add-drop{position:fixed;background:#fff;border:1.5px solid #c7d8ff;border-radius:10px;box-shadow:0 6px 20px rgba(32,78,171,.12);max-height:220px;overflow-y:auto;z-index:9999;display:none;}
.op-add-drop.open{display:block;}
.op-add-opt{padding:10px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid #f0f4ff;display:flex;justify-content:space-between;align-items:center;}
.op-add-opt:last-child{border-bottom:none;}
.op-add-opt:active{background:#edf2ff;}
.op-add-opt .opt-uom{font-size:11px;color:#94a3b8;font-weight:600;}
.op-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;margin-left:6px;vertical-align:middle;}
.op-badge.minus{background:#fee2e2;color:#dc2626;}
.op-badge.plus{background:#dcfce7;color:#16a34a;}
.op-summary-bar{background:#f0f4ff;border-radius:12px;margin:14px;padding:12px 14px;font-size:13px;color:#374151;display:flex;gap:16px;flex-wrap:wrap;}
.op-summary-bar span{font-weight:700;}
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

<!-- Tab Navigation -->
<div class="tab-nav">
    <button id="tabBtnRequest" class="tab-btn<?= $active_tab === 'request' ? ' active' : '' ?>" onclick="switchTab('request',this)">
        <i class="fas fa-paper-plane me-1"></i>Request Stok
        <?php if ($pending_count > 0): ?>
        <span style="background:#f59e0b;color:#fff;border-radius:99px;font-size:10px;padding:1px 6px;margin-left:4px;"><?= $pending_count ?></span>
        <?php endif; ?>
    </button>
    <button id="tabBtnOpname" class="tab-btn<?= $active_tab === 'opname' ? ' active' : '' ?>" onclick="switchTab('opname',this)">
        <i class="fas fa-clipboard-check me-1"></i>Input Stok
        <?php if (count($hc_bag_items) > 0): ?>
        <span style="background:#204EAB;color:#fff;border-radius:99px;font-size:10px;padding:1px 6px;margin-left:4px;"><?= count($hc_bag_items) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ── TAB: Request Stok ──────────────────────────────────────────────────── -->
<div id="tab-request" class="tab-pane<?= $active_tab === 'request' ? ' active' : '' ?>">

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

<!-- Submit bar for Request -->
<div class="submit-bar" id="submitBarRequest" style="<?= $active_tab === 'request' ? '' : 'display:none;' ?>">
    <button type="button" class="btn-submit" id="submitBtn" <?= empty($klinik_items) ? 'disabled' : '' ?>>
        <i class="fas fa-paper-plane me-2"></i>Kirim Request
    </button>
</div>

</div><!-- end #tab-request -->

<!-- ── TAB: Input Stok (Opname) ──────────────────────────────────────────── -->
<div id="tab-opname" class="tab-pane<?= $active_tab === 'opname' ? ' active' : '' ?>" style="padding-bottom:90px;">

    <?php if ($opname_periode_label): ?>
    <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:8px 14px;margin:12px 14px 0;font-size:13px;font-weight:600;color:#3730a3;display:flex;align-items:center;gap:6px;">
        <i class="fas fa-calendar-alt"></i> Laporan untuk SO periode: <?= htmlspecialchars($opname_periode_label) ?>
    </div>
    <?php endif; ?>

    <div class="op-summary-bar" id="opSummaryBar">
        <div><i class="fas fa-box me-1" style="color:#204EAB;"></i>Total item: <span id="opTotalItems"><?= count($hc_bag_items) ?></span></div>
        <div><i class="fas fa-exclamation-triangle me-1" style="color:#ef4444;"></i>Selisih: <span id="opSelisihCount">0</span></div>
        <div style="margin-left:auto;font-size:12px;color:#64748b;">Laporan dikirim ke tim accounting untuk divalidasi</div>
    </div>

    <!-- Search / tambah item -->
    <div class="op-add-wrap">
        <input type="text" id="opAddInput" class="op-add-input" placeholder="+ Tambah item...">
        <div id="opAddDrop" class="op-add-drop"></div>
    </div>

    <div id="opItemList">
    <?php foreach ($hc_bag_items as $bi):
        $reported = !empty($bi['already_reported']);
        // Kalau sudah dilaporkan periode ini, tampilkan laporan ASLI nakes (qty_lapor) sebagai
        // "Qty fisik" — jangan qty_sistem (cache stok tas, bisa berisi angka lama lintas
        // periode/reset). Kalau belum pernah lapor periode ini, qty_sistem tetap dipakai
        // sbg acuan awal (perilaku lama, tidak berubah).
        $qty_display = ($reported && $bi['qty_lapor'] !== null) ? $bi['qty_lapor'] : $bi['qty_sistem'];
        // "Divalidasi" HARUS berdasarkan qty_aktual milik sesi (opname_id_now) yang sedang
        // berjalan — bukan qty_sistem, supaya item yang belum divalidasi periode ini (qty_aktual
        // masih kosong) tidak salah dianggap "sudah divalidasi" hanya krn cache stok tas lama.
        $has_validated_now = ($reported && $bi['qty_aktual_now'] !== null);
        $qty_validated_diff = ($has_validated_now && $bi['qty_lapor'] !== null
            && (int)round($bi['qty_lapor']) !== (int)round($bi['qty_aktual_now']));
    ?>
    <div class="op-item<?= $reported ? ' reported' : '' ?>" data-id="<?= (int)$bi['barang_id'] ?>" data-sys="<?= (float)$bi['qty_sistem'] ?>" data-mult="<?= (float)$bi['multiplier'] ?>" <?= $reported ? 'data-reported="1"' : '' ?>>
        <?php if (!$reported): ?>
        <button type="button" class="op-del-btn" onclick="opRemoveItem(this)" title="Hapus">×</button>
        <?php endif; ?>
        <div class="op-name" style="padding-right:28px;"><?= htmlspecialchars($bi['nama_barang']) ?>
            <?php if ($reported): ?><span class="badge-reported"><i class="fas fa-check-circle"></i> Sudah Lapor</span><?php endif; ?>
        </div>
        <?php if ($reported): ?>
            <?php $diff_final = $has_validated_now ? ((int)round($bi['qty_aktual_now']) - (int)round((float)$qty_display)) : 0; ?>
            <div class="op-report-summary">
                <?php if ($qty_validated_diff): ?>
                    <div class="op-report-box">
                        <div class="op-report-label">Lapor Awal</div>
                        <div class="op-report-val"><?= (int)round((float)$qty_display) ?> <span class="uom"><?= htmlspecialchars($bi['uom']) ?></span></div>
                    </div>
                    <i class="fas fa-arrow-right op-report-arrow"></i>
                    <div class="op-report-box is-final">
                        <div class="op-report-label">Divalidasi</div>
                        <div class="op-report-val"><?= (int)round($bi['qty_aktual_now']) ?> <span class="uom"><?= htmlspecialchars($bi['uom']) ?></span></div>
                    </div>
                    <span class="op-report-diff <?= $diff_final === 0 ? 'zero' : ($diff_final > 0 ? 'plus' : 'minus') ?>"><?= $diff_final > 0 ? '+' : '' ?><?= $diff_final ?></span>
                <?php else: ?>
                    <div class="op-report-box is-final" style="flex:0 0 auto;min-width:110px;">
                        <div class="op-report-label">Qty Fisik</div>
                        <div class="op-report-val"><?= (int)round((float)$qty_display) ?> <span class="uom"><?= htmlspecialchars($bi['uom']) ?></span></div>
                    </div>
                    <span class="op-report-diff zero"><?= $has_validated_now ? '✓ Sesuai' : '✓ Terkirim' ?></span>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="op-sys">Stok sistem: <strong><?= (int)round((float)$bi['qty_sistem']) ?></strong> <?= htmlspecialchars($bi['uom']) ?></div>
        <div class="op-qty-row">
            <span class="op-qty-label">Qty fisik:</span>
            <button type="button" class="qty-btn" onclick="opChangeQty(this,-1)">−</button>
            <input type="number" class="op-qty-input" value="<?= (int)round((float)$qty_display) ?>" min="0" step="1"
                data-barang-id="<?= (int)$bi['barang_id'] ?>"
                oninput="opUpdateSelisih(this)">
            <button type="button" class="qty-btn" onclick="opChangeQty(this,1)">+</button>
            <span class="op-selisih" id="selisih-<?= (int)$bi['barang_id'] ?>"></span>
        </div>
        <textarea class="op-catatan" rows="1" placeholder="Catatan (opsional)..."
            data-barang-id="<?= (int)$bi['barang_id'] ?>"></textarea>
        <?php endif; ?>
        <?php if ($reported): ?>
        <input type="hidden" class="op-qty-input" value="<?= (int)round((float)$qty_display) ?>" data-barang-id="<?= (int)$bi['barang_id'] ?>">
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

</div><!-- end #tab-opname -->

<!-- Submit bar for Opname -->
<div class="submit-bar" id="submitBarOpname" style="<?= $active_tab === 'opname' ? '' : 'display:none;' ?>">
    <button type="button" class="btn-submit" id="opSubmitBtn" style="background:#10b981;">
        <i class="fas fa-paper-plane me-2"></i>Kirim Laporan Stok
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const klinikId = <?= $klinik_id ?>;
const baseUrl  = '<?= base_url() ?>';
let rowCount   = 1;

// Dataset item dari server
const allItems = <?= json_encode(array_values($klinik_items), JSON_UNESCAPED_UNICODE) ?>;
const hcBagItems = <?= json_encode(array_values($hc_bag_items), JSON_UNESCAPED_UNICODE) ?>;
const csrfToken = '<?= $csrf ?>';

// ── Tab switcher ──────────────────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    if (!btn) btn = document.getElementById(tab === 'opname' ? 'tabBtnOpname' : 'tabBtnRequest');
    if (btn) btn.classList.add('active');
    document.getElementById('tab-' + tab)?.classList.add('active');
    document.getElementById('submitBarRequest').style.display = tab === 'request' ? '' : 'none';
    document.getElementById('submitBarOpname').style.display  = tab === 'opname'  ? '' : 'none';
    // Simpan tab yang sedang aktif di URL supaya kalau halaman di-refresh tetap di tab yang sama
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
}

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

// ── Opname Logic ──────────────────────────────────────────────────────────────
// Track which barang_id are already in the list
const opBidSet = new Set(<?= json_encode(array_map(fn($b) => (int)$b['barang_id'], $hc_bag_items)) ?>);

function opChangeQty(btn, delta) {
    const input = btn.closest('.op-qty-row').querySelector('.op-qty-input');
    const cur = parseFloat(input.value) || 0;
    input.value = Math.max(0, Math.round(cur + delta));
    opUpdateSelisih(input);
}

function opUpdateSelisih(input) {
    const card  = input.closest('.op-item');
    const sys   = parseFloat(card.dataset.sys) || 0;
    const fisik = parseFloat(input.value) || 0;
    const diff  = Math.round(fisik - sys);
    const bid   = input.dataset.barangId;
    const el    = document.getElementById('selisih-' + bid);
    if (el) {
        if (diff === 0) { el.textContent = '✓ Sesuai'; el.className = 'op-selisih zero'; }
        else { el.textContent = (diff > 0 ? '+' : '') + diff; el.className = 'op-selisih ' + (diff > 0 ? 'plus' : 'minus'); }
    }
    // Item yang sudah dilaporkan periode ini sudah final (read-only) — jangan ikut dihitung
    // sbg "selisih perlu perhatian" di ringkasan atas, itu cuma riwayat lapor-vs-validasi.
    if (card.dataset.reported !== '1') {
        card.classList.toggle('has-selisih', diff !== 0);
        card.classList.toggle('ok', diff === 0);
    }
    opRefreshSummary();
}

function opUpdateTotal(input) {
    const bid  = input.dataset.barangId;
    const card = document.querySelector('.op-item[data-id="' + bid + '"]');
    if (!card) return;
    const lte = parseFloat(card.querySelector('[data-field="ed_lte3m"]')?.value) || 0;
    const gt  = parseFloat(card.querySelector('[data-field="ed_gt3m"]')?.value) || 0;
    const totalEl = document.getElementById('ed-total-' + bid);
    if (totalEl) totalEl.textContent = (lte + gt);
    const qtyInput = card.querySelector('.op-qty-input');
    if (qtyInput && (lte > 0 || gt > 0)) { qtyInput.value = lte + gt; opUpdateSelisih(qtyInput); }
}

function opRefreshSummary() {
    const cards = document.querySelectorAll('.op-item');
    const selisih = document.querySelectorAll('.op-item.has-selisih').length;
    const cntEl = document.getElementById('opTotalItems');
    const selEl = document.getElementById('opSelisihCount');
    if (cntEl) cntEl.textContent = cards.length;
    if (selEl) { selEl.textContent = selisih; selEl.style.color = selisih > 0 ? '#ef4444' : '#10b981'; }
    opUpdateSubmitBtnState();
}

// Kalau semua item sudah "Sudah Lapor" (tidak ada yang baru utk dikirim), tombol submit
// dinonaktifkan dan labelnya diubah supaya tidak terlihat seolah masih bisa kirim laporan lagi.
function opUpdateSubmitBtnState() {
    const btn = document.getElementById('opSubmitBtn');
    if (!btn) return;
    const hasPending = document.querySelector('.op-item:not([data-reported="1"])') !== null;
    if (hasPending) {
        btn.disabled = false;
        btn.style.background = '';
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Laporan Stok';
    } else {
        btn.disabled = true;
        btn.style.background = '#94a3b8';
        btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Semua Item Sudah Dilaporkan';
    }
}

function opRemoveItem(btn) {
    const card = btn.closest('.op-item');
    const bid  = parseInt(card.dataset.id);
    card.remove();
    opBidSet.delete(bid);
    opRefreshSummary();
}

function opAddItemCard(item) {
    const bid   = parseInt(item.barang_id);
    if (opBidSet.has(bid)) return;
    opBidSet.add(bid);
    // Item baru (bukan dari stok tas yang sudah tercatat) — tidak ada baseline "Stok sistem" tas
    // yang valid untuk dibandingkan (angka di `allItems` itu stok klinik, bukan stok tas nakes),
    // jadi jangan ditampilkan supaya tidak menyesatkan.
    const sys   = 0;
    const uom   = escHtml(item.uom || '');
    const mult  = parseFloat(item.multiplier ?? 1);
    const div = document.createElement('div');
    div.className = 'op-item';
    div.dataset.id   = bid;
    div.dataset.sys  = sys;
    div.dataset.mult = mult;
    div.innerHTML = `
        <button type="button" class="op-del-btn" onclick="opRemoveItem(this)" title="Hapus">×</button>
        <div class="op-name" style="padding-right:28px;">${escHtml(item.nama_barang)}</div>
        <div class="op-qty-row">
            <span class="op-qty-label">Qty fisik:</span>
            <button type="button" class="qty-btn" onclick="opChangeQty(this,-1)">−</button>
            <input type="number" class="op-qty-input" value="0" min="0" step="1"
                data-barang-id="${bid}" oninput="opUpdateSelisih(this)">
            <button type="button" class="qty-btn" onclick="opChangeQty(this,1)">+</button>
            <span class="op-selisih" id="selisih-${bid}"></span>
        </div>
        <textarea class="op-catatan" rows="1" placeholder="Catatan (opsional)..."
            data-barang-id="${bid}"></textarea>`;
    document.getElementById('opItemList').appendChild(div);
    div.querySelector('.op-qty-input')?.dispatchEvent(new Event('input'));
    opRefreshSummary();
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Add item search ───────────────────────────────────────────────────────────
const opAddInput = document.getElementById('opAddInput');
const opAddDrop  = document.getElementById('opAddDrop');

function opRenderDrop(q) {
    const results = allItems.filter(it =>
        !opBidSet.has(parseInt(it.barang_id)) &&
        it.nama_barang.toLowerCase().includes(q.toLowerCase())
    ).slice(0, 30);
    if (!results.length) {
        opAddDrop.innerHTML = '<div class="op-add-opt" style="color:#aaa;justify-content:center;pointer-events:none;">Tidak ada item</div>';
    } else {
        opAddDrop.innerHTML = results.map(it =>
            `<div class="op-add-opt" data-id="${it.barang_id}" onclick="opSelectItem(${it.barang_id})">
                <span>${escHtml(it.nama_barang)}</span>
                <span class="opt-uom">${escHtml(it.uom||'')}</span>
             </div>`
        ).join('');
    }
    const rect = opAddInput.getBoundingClientRect();
    opAddDrop.style.left  = rect.left + 'px';
    opAddDrop.style.top   = (rect.bottom + 4) + 'px';
    opAddDrop.style.width = rect.width + 'px';
    opAddDrop.classList.add('open');
}

opAddInput.addEventListener('input', () => {
    const q = opAddInput.value.trim();
    if (q.length === 0) { opAddDrop.classList.remove('open'); return; }
    opRenderDrop(q);
});
opAddInput.addEventListener('focus', () => {
    if (opAddInput.value.trim()) opRenderDrop(opAddInput.value.trim());
});
document.addEventListener('click', e => {
    if (!opAddInput.contains(e.target) && !opAddDrop.contains(e.target))
        opAddDrop.classList.remove('open');
});

function opSelectItem(bid) {
    const item = allItems.find(it => parseInt(it.barang_id) === bid);
    if (!item) return;
    opAddDrop.classList.remove('open');
    opAddInput.value = '';
    opAddItemCard(item);
}

// Initialize selisih badges on load
document.querySelectorAll('.op-qty-input').forEach(inp => opUpdateSelisih(inp));
opUpdateSubmitBtnState();

// Submit opname
document.getElementById('opSubmitBtn')?.addEventListener('click', async () => {
    const items = [];
    document.querySelectorAll('.op-item').forEach(card => {
        if (card.dataset.reported === '1') return; // sudah lapor, jangan kirim ulang
        const bid      = parseInt(card.dataset.id);
        const sys      = parseFloat(card.dataset.sys) || 0;
        const mult     = parseFloat(card.dataset.mult) || 1;
        const qtyEl    = card.querySelector('.op-qty-input');
        const qty      = parseFloat(qtyEl?.value) || 0;
        const ed_lte3m = parseFloat(card.querySelector('[data-field="ed_lte3m"]')?.value) || 0;
        const ed_gt3m  = parseFloat(card.querySelector('[data-field="ed_gt3m"]')?.value) || 0;
        const catatan  = card.querySelector('.op-catatan')?.value.trim() || '';
        // qty is in to_uom — send multiplier so server can convert to from_uom
        items.push({ barang_id: bid, qty_sistem: sys, qty_fisik: qty, multiplier: mult, ed_lte3m, ed_gt3m, catatan });
    });

    if (!items.length) { alert('Tidak ada item untuk dilaporkan.'); return; }

    const btn = document.getElementById('opSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';

    try {
        const res = await fetch(baseUrl + 'api/hc_stock_validate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf: csrfToken, klinik_id: klinikId, items })
        });
        const data = await res.json();
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check me-2"></i>Laporan Terkirim!';
            btn.style.background = '#059669';
            // Tidak reload — laporan self-report baru masuk ke inventory_stok_opname_detail,
            // stok tas (inventory_stok_tas_hc) baru berubah setelah admin memvalidasi di halaman SO.
            // Kalau di-reload sekarang, item yang belum tervalidasi (terutama yang baru ditambah manual)
            // akan hilang dari daftar walau laporannya sudah tersimpan — jadi list dibiarkan tetap tampil.
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Laporan Stok';
                btn.style.background = '';
            }, 2000);
        } else {
            alert('Gagal: ' + (data.message || 'Terjadi kesalahan'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Laporan Stok';
        }
    } catch (e) {
        alert('Koneksi gagal. Coba lagi.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Laporan Stok';
    }
});
</script>
</body>
</html>
