<?php
check_role(['admin_klinik', 'super_admin', 'spv_klinik']);

$role      = (string)$_SESSION['role'];
$s_klinik  = (int)($_SESSION['klinik_id'] ?? 0);

// Ambil daftar klinik sesuai role
$kliniks = [];
if ($role === 'super_admin') {
    $res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik ORDER BY nama_klinik ASC");
} else {
    $res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE id=$s_klinik LIMIT 1");
}
while ($res && ($r = $res->fetch_assoc())) $kliniks[] = $r;
?>
<div class="container-fluid pt-2 pb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0"><i class="fas fa-qrcode me-2 text-primary"></i>QR Code Klinik</h4>
            <div class="text-muted small">Cetak QR untuk setiap lokasi klinik agar nakes bisa scan dan request stok.</div>
        </div>
    </div>

    <?php if (empty($kliniks)): ?>
        <div class="alert alert-info">Tidak ada klinik yang tersedia.</div>
    <?php else: ?>
    <div class="row g-4" id="qrCards">
        <?php foreach ($kliniks as $kl): ?>
        <?php
            $url = base_url('index.php?page=qr_transfer&klinik_id=' . (int)$kl['id'] . '&layout=1');
            $qr_src = base_url('api/qr.php?text=' . rawurlencode($url));
        ?>
        <div class="col-sm-6 col-md-4 col-lg-3">
            <div class="card shadow-sm text-center h-100" style="border-radius:14px;overflow:hidden;">
                <div class="card-body py-4">
                    <div class="fw-bold mb-3" style="color:#204EAB;font-size:15px;"><?= htmlspecialchars($kl['nama_klinik']) ?></div>
                    <img src="<?= $qr_src ?>"
                         alt="QR <?= htmlspecialchars($kl['nama_klinik']) ?>"
                         class="img-fluid mb-3"
                         style="width:180px;height:180px;border-radius:8px;border:1px solid #e8ecf4;">
                    <div class="text-muted" style="font-size:10px;word-break:break-all;margin-bottom:12px;"><?= htmlspecialchars($url) ?></div>
                    <a href="<?= base_url('print_qr.php?klinik_id=' . (int)$kl['id']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-print me-1"></i>Cetak
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4">
        <a href="<?= base_url('print_qr.php?all=1') ?>" target="_blank" class="btn btn-primary">
            <i class="fas fa-print me-1"></i>Cetak Semua QR
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Print frame -->
<iframe id="printFrame" style="display:none;"></iframe>

<script>
const baseUrl = '<?= base_url() ?>';

function printQR(klinikId, klinikName) {
    const url = baseUrl + 'index.php?page=qr_transfer&klinik_id=' + klinikId + '&layout=1';
    const qrSrc = baseUrl + 'api/qr.php?size=420x420&text=' + encodeURIComponent(url);
    const win = window.open('', '_blank', 'width=600,height=800');
    win.document.write(buildQRPage(klinikName, url, qrSrc, true));
    win.document.close();
}

function buildQRPage(klinikName, url, qrSrc, autoPrint) {
    return `<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>QR ${klinikName}</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
@page { size: A5 portrait; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Arial', sans-serif;
    width: 148mm;
    height: 210mm;
    overflow: hidden;
    background: #fff;
    display: flex;
    flex-direction: column;
}

/* TOP HEADER BAND */
.header {
    background: linear-gradient(135deg, #204EAB 0%, #1a3e8a 100%);
    color: #fff;
    padding: 10mm 8mm 8mm;
    text-align: center;
    position: relative;
    flex-shrink: 0;
}
.header-brand { font-size: 9pt; font-weight: 700; letter-spacing: 2px; opacity: 0.85; margin-bottom: 2mm; text-transform: uppercase; }
.header-title { font-size: 15pt; font-weight: 800; line-height: 1.2; }
.header-sub { font-size: 9pt; opacity: 0.8; margin-top: 2mm; }

/* DECO ICONS ROW */
.deco-row {
    display: flex;
    justify-content: center;
    gap: 6mm;
    padding: 4mm 0 3mm;
    background: #f0f5ff;
    flex-shrink: 0;
}
.deco-icon {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1mm;
}
.deco-icon i { font-size: 14pt; color: #204EAB; opacity: 0.7; }
.deco-icon span { font-size: 6pt; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }

/* QR SECTION */
.qr-section {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4mm 8mm;
}
.qr-wrap {
    background: #fff;
    border: 3px solid #204EAB;
    border-radius: 10px;
    padding: 5mm;
    display: inline-block;
    box-shadow: 0 2px 12px rgba(32,78,171,0.15);
}
.qr-wrap img { display: block; width: 55mm; height: 55mm; }
.scan-label {
    margin-top: 3mm;
    font-size: 10pt;
    font-weight: 700;
    color: #204EAB;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* STEPS */
.steps {
    background: #f7f9ff;
    border: 1px solid #dde8ff;
    border-radius: 8px;
    padding: 3mm 5mm;
    margin-top: 4mm;
    width: 100%;
    flex-shrink: 0;
}
.steps-title { font-size: 8pt; font-weight: 700; color: #204EAB; margin-bottom: 2mm; text-transform: uppercase; letter-spacing: 0.5px; }
.steps ol { padding-left: 4mm; }
.steps li { font-size: 7.5pt; color: #444; margin-bottom: 1mm; line-height: 1.4; }

/* FOOTER */
.footer {
    background: #f0f5ff;
    border-top: 1px solid #dde8ff;
    padding: 3mm 8mm;
    text-align: center;
    flex-shrink: 0;
}
.footer-url { font-size: 6.5pt; color: #999; word-break: break-all; }
.footer-brand { font-size: 7pt; color: #204EAB; font-weight: 700; margin-top: 1mm; opacity: 0.7; }

@media print {
    @page { size: A5 portrait; margin: 0; }
    body { width: 148mm; height: 210mm; }
}
</style>
</head>
<body>
<div class="header">
    <div class="header-brand">&#9651; Bumame Inventory System</div>
    <div class="header-title">${klinikName}</div>
    <div class="header-sub">Stok &amp; Permintaan Alat Kesehatan</div>
</div>

<div class="deco-row">
    <div class="deco-icon"><i class="fas fa-syringe"></i><span>Vaksin</span></div>
    <div class="deco-icon"><i class="fas fa-first-aid"></i><span>BHP</span></div>
    <div class="deco-icon"><i class="fas fa-stethoscope"></i><span>Alkes</span></div>
    <div class="deco-icon"><i class="fas fa-pills"></i><span>Obat</span></div>
    <div class="deco-icon"><i class="fas fa-band-aid"></i><span>Lainnya</span></div>
</div>

<div class="qr-section">
    <div class="qr-wrap">
        <img src="${qrSrc}" ${autoPrint ? 'onload="window.print()"' : ''}>
    </div>
    <div class="scan-label"><i class="fas fa-qrcode" style="margin-right:3px;"></i>Scan untuk Request Stok</div>

    <div class="steps">
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

<div class="footer">
    <div class="footer-url">${url}</div>
    <div class="footer-brand">PT Bumame Cahaya Medika &mdash; Inventory Management</div>
</div>
</body></html>`;
}

function printAll() {
    const pages = [];
    <?php foreach ($kliniks as $kl): ?>
    (function() {
        const klinikId = <?= (int)$kl['id'] ?>;
        const name = <?= json_encode($kl['nama_klinik']) ?>;
        const url = baseUrl + 'index.php?page=qr_transfer&klinik_id=' + klinikId + '&layout=1';
        const qrSrc = baseUrl + 'api/qr.php?size=420x420&text=' + encodeURIComponent(url);
        pages.push({ name, url, qrSrc });
    })();
    <?php endforeach; ?>

    if (pages.length === 1) {
        const p = pages[0];
        const win = window.open('', '_blank', 'width=600,height=800');
        win.document.write(buildQRPage(p.name, p.url, p.qrSrc, true));
        win.document.close();
        return;
    }

    // Multiple pages: combine into one document with page breaks
    const win = window.open('', '_blank', 'width=600,height=800');
    const allPages = pages.map((p, i) => {
        const isLast = i === pages.length - 1;
        return buildQRPage(p.name, p.url, p.qrSrc, false)
            .replace('</body></html>', '')
            .replace('<!DOCTYPE html><html><head>', '')
            .replace(/.*<\/style>[\s\S]*?<\/head>\s*<body>/m, '')
            + (isLast ? '' : '<div style="page-break-after:always;"></div>');
    });

    const firstFull = buildQRPage(pages[0].name, pages[0].url, pages[0].qrSrc, false);
    const bodyStart = firstFull.indexOf('<body>') + 6;
    const head = firstFull.substring(0, bodyStart);
    let combined = head;
    pages.forEach((p, i) => {
        const full = buildQRPage(p.name, p.url, p.qrSrc, false);
        const bStart = full.indexOf('<body>') + 6;
        const bEnd = full.lastIndexOf('</body>');
        combined += full.substring(bStart, bEnd);
        if (i < pages.length - 1) combined += '<div style="page-break-after:always;"></div>';
    });
    combined += '</body></html>';

    win.document.write(combined);
    win.document.close();
    setTimeout(() => win.print(), 3000);
}
</script>
