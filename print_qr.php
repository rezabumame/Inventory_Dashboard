<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/stock.php';

// Auth check
$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik', 'admin_gudang'])) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;"><h3>Akses Ditolak</h3></div>';
    exit;
}

$klinik_id = (int)($_GET['klinik_id'] ?? 0);
$all       = isset($_GET['all']) && $_GET['all'] === '1' && $role === 'super_admin';

// Fetch kliniks
$kliniks = [];
if ($all) {
    $res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik ASC");
    while ($r = $res->fetch_assoc()) $kliniks[] = $r;
} elseif ($klinik_id > 0) {
    $s_klinik = (int)($_SESSION['klinik_id'] ?? 0);
    if ($role === 'super_admin' || $s_klinik === $klinik_id) {
        $res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE id=$klinik_id LIMIT 1");
        if ($r = $res->fetch_assoc()) $kliniks[] = $r;
    }
}

if (empty($kliniks)) {
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;"><h3>Klinik tidak ditemukan.</h3></div>';
    exit;
}

// Build base URL for QR content
$base = base_url();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak QR Klinik</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
@page { size: A5 portrait; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    color-adjust: exact;
}

.qr-page {
    width: 148mm;
    height: 210mm;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #fff;
    page-break-after: always;
}
.qr-page:last-child { page-break-after: avoid; }

/* HEADER */
.hdr {
    background: linear-gradient(135deg, #204EAB 0%, #1635a0 100%);
    color: #fff;
    text-align: center;
    padding: 9mm 8mm 7mm;
    flex-shrink: 0;
}
.hdr-brand {
    font-size: 7.5pt;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    opacity: 0.85;
    margin-bottom: 2mm;
}
.hdr-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 3mm;
    margin-bottom: 2mm;
}
.hdr-logo i { font-size: 20pt; opacity: 0.9; }
.hdr-name { font-size: 14pt; font-weight: 800; line-height: 1.2; }
.hdr-sub { font-size: 8.5pt; opacity: 0.82; margin-top: 1.5mm; }

/* ICON ROW */
.icon-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5mm;
    padding: 3.5mm 0 3mm;
    background: #edf2ff;
    border-bottom: 1px solid #c7d8ff;
    flex-shrink: 0;
}
.icon-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1mm;
}
.icon-item i { font-size: 13pt; color: #204EAB; }
.icon-item span { font-size: 5.5pt; color: #555; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

/* QR BODY */
.qr-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4mm 8mm 3mm;
    gap: 3mm;
}

.qr-frame {
    border: 3px solid #204EAB;
    border-radius: 10px;
    padding: 4mm;
    background: #fff;
    box-shadow: 0 0 0 1px #c7d8ff;
}
.qr-frame img { display: block; width: 55mm; height: 55mm; }

.scan-label {
    font-size: 9pt;
    font-weight: 700;
    color: #204EAB;
    letter-spacing: 0.8px;
    text-align: center;
}

/* STEPS */
.steps-box {
    background: #f0f5ff;
    border: 1px solid #c7d8ff;
    border-radius: 8px;
    padding: 3mm 4mm;
    width: 100%;
}
.steps-title {
    font-size: 7.5pt;
    font-weight: 700;
    color: #204EAB;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2mm;
}
.steps-box ol { padding-left: 4mm; }
.steps-box li {
    font-size: 7.5pt;
    color: #333;
    margin-bottom: 1mm;
    line-height: 1.45;
}

/* FOOTER */
.ftr {
    background: #edf2ff;
    border-top: 1px solid #c7d8ff;
    padding: 2.5mm 8mm;
    text-align: center;
    flex-shrink: 0;
}
.ftr-url { font-size: 6pt; color: #888; word-break: break-all; }
.ftr-brand { font-size: 6.5pt; color: #204EAB; font-weight: 700; margin-top: 1mm; opacity: 0.75; }

/* Screen preview: show page as card */
@media screen {
    body { background: #d0d8e8; padding: 10mm; display: flex; flex-direction: column; align-items: center; gap: 8mm; }
    .qr-page { box-shadow: 0 4px 24px rgba(0,0,0,0.18); border-radius: 4px; }
    .no-print { display: block; }
}
@media print {
    body { background: #fff; padding: 0; }
    .no-print { display: none !important; }
    @page { size: A5 portrait; margin: 0; }
}

.no-print {
    text-align: center;
    margin-bottom: 6mm;
}
.btn-print {
    background: #204EAB;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px 28px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print" style="margin-right:6px;"></i>Cetak / Print
    </button>
</div>

<?php foreach ($kliniks as $kl):
    $url    = $base . 'index.php?page=qr_transfer&klinik_id=' . (int)$kl['id'] . '&layout=1';
    $qr_src = $base . 'api/qr.php?size=420x420&text=' . rawurlencode($url);
    $nama   = htmlspecialchars($kl['nama_klinik']);
?>
<div class="qr-page">

    <div class="hdr">
        <div class="hdr-brand">Bumame Inventory System</div>
        <div class="hdr-logo">
            <i class="fas fa-clinic-medical"></i>
        </div>
        <div class="hdr-name"><?= $nama ?></div>
        <div class="hdr-sub">Stok &amp; Permintaan Alat Kesehatan</div>
    </div>

    <div class="icon-row">
        <div class="icon-item"><i class="fas fa-syringe"></i><span>Vaksin</span></div>
        <div class="icon-item"><i class="fas fa-first-aid"></i><span>BHP</span></div>
        <div class="icon-item"><i class="fas fa-stethoscope"></i><span>Alkes</span></div>
        <div class="icon-item"><i class="fas fa-pills"></i><span>Obat</span></div>
        <div class="icon-item"><i class="fas fa-band-aid"></i><span>Lainnya</span></div>
    </div>

    <div class="qr-body">
        <div class="qr-frame">
            <img src="<?= $qr_src ?>" alt="QR <?= $nama ?>">
        </div>
        <div class="scan-label"><i class="fas fa-qrcode" style="margin-right:3px;"></i>Scan untuk Request Stok</div>

        <div class="steps-box">
            <div class="steps-title"><i class="fas fa-list-ol" style="margin-right:3px;"></i>Cara Penggunaan</div>
            <ol>
                <li>Scan QR ini menggunakan kamera HP Anda</li>
                <li>Login dengan akun Bumame Inventory</li>
                <li>Pilih item &amp; qty yang dibutuhkan</li>
                <li>Upload foto bukti <em>(opsional)</em></li>
                <li>Tekan <strong>Kirim Request</strong></li>
                <li>Admin akan mereview &amp; menyetujui</li>
            </ol>
        </div>
    </div>

    <div class="ftr">
        <div class="ftr-url"><?= htmlspecialchars($url) ?></div>
        <div class="ftr-brand">PT Bumame Cahaya Medika &mdash; Inventory Management</div>
    </div>

</div>
<?php endforeach; ?>

<script>
// Auto print if only one klinik
<?php if (count($kliniks) === 1): ?>
window.addEventListener('load', function() {
    // Small delay to let FontAwesome load
    setTimeout(function() { window.print(); }, 800);
});
<?php endif; ?>
</script>
</body>
</html>
