<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{16,80}$/i', $token)) {
    http_response_code(400);
    echo 'Invalid token';
    exit;
}

$tok = $conn->real_escape_string($token);
$who = trim((string)($_GET['who'] ?? 'requester'));

$sql = "
    SELECT
        r.id,
        r.nomor_request,
        r.status,
        r.created_at,
        r.request_qr_at,
        r.spv_approved_at,
        u_req.nama_lengkap AS requestor_name,
        u_app.nama_lengkap AS approver_name,
        k_from.nama_klinik AS dari_klinik_nama,
        k_to.nama_klinik AS ke_klinik_nama
    FROM inventory_request_barang r
    LEFT JOIN inventory_users u_req ON r.created_by = u_req.id
    LEFT JOIN inventory_users u_app ON r.spv_approved_by = u_app.id
    LEFT JOIN inventory_klinik k_from ON (r.dari_level = 'klinik' AND r.dari_id = k_from.id)
    LEFT JOIN inventory_klinik k_to ON (r.ke_level = 'klinik' AND r.ke_id = k_to.id)
";

if ($who === 'approver') {
    $sql .= " WHERE r.spv_qr_token = '$tok'";
} else {
    $sql .= " WHERE r.request_qr_token = '$tok'";
}
$sql .= " LIMIT 1";

$q = $conn->query($sql);
$row = $q && $q->num_rows > 0 ? $q->fetch_assoc() : null;
if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
$status_raw = strtoupper((string)($row['status'] ?? '-'));
$status = ($status_raw === 'PENDING') ? 'APPROVED' : $status_raw;

$title = 'PERMINTAAN BARANG';
$nomor = (string)($row['nomor_request'] ?? '-');
$tanggal = (string)($row['created_at'] ?? '');
$tanggal_display = $tanggal !== '' ? date('d M Y, H:i', strtotime($tanggal)) : '-';

if ($who === 'approver' && !empty($row['spv_approved_at'])) {
    $tanggal_display = date('d M Y, H:i', strtotime($row['spv_approved_at']));
}

$dari = (string)($row['dari_klinik_nama'] ?? ($row['dari_level'] ?? '-'));
$tujuan = (string)($row['ke_klinik_nama'] ?? ($row['ke_level'] ?? '-'));
$pemohon = (string)($row['requestor_name'] ?? '-');
$approver = (string)($row['approver_name'] ?? '-');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verified</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:24px;color:#1f2d3d}
        .wrap{max-width:420px;margin:0 auto}
        .card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(15,23,42,.08);overflow:hidden}
        .head{padding:18px 18px 10px;display:flex;align-items:center;justify-content:space-between}
        .logo{font-weight:800;font-size:22px;letter-spacing:.2px;color:#0b3aa4}
        .pill{border:1px solid #dbe3f2;border-radius:999px;padding:6px 10px;font-size:12px;color:#6b7280}
        .body{padding:18px}
        .ok{width:72px;height:72px;border-radius:999px;background:#eaf7f1;display:flex;align-items:center;justify-content:center;margin:10px auto 12px}
        .ok svg{width:38px;height:38px}
        .h1{text-align:center;font-size:24px;margin:0 0 6px}
        .sub{text-align:center;color:#6b7280;font-size:13px;margin:0 0 18px}
        .grid{border-top:1px solid #eef2f7;margin-top:14px;padding-top:14px}
        .lbl{color:#6b7280;font-size:11px;letter-spacing:.6px}
        .val{font-weight:700;margin:4px 0 14px}
        .mb-0{margin-bottom:0}
        .row{display:flex;gap:18px}
        .row .col{flex:1}
        .approver-box{display:flex;align-items:center;background:#f8fafc;border-radius:12px;padding:12px;margin:10px 0 18px;border:1px solid #eef2f7}
        .approver-icon{width:42px;height:42px;border-radius:999px;background:#0b3aa4;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;margin-right:12px}
        .approver-info{flex:1}
        .foot{padding:14px 18px;color:#6b7280;font-size:12px;border-top:1px solid #eef2f7;text-align:center}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="head">
                <div class="logo">bumame</div>
                <div class="pill">OFFICIAL DOCUMENT</div>
            </div>
            <div class="body">
                <div class="ok" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#19a974" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6L9 17l-5-5"></path>
                    </svg>
                </div>
                <h1 class="h1">VERIFIED</h1>
                <p class="sub">Dokumen ini telah disetujui secara digital.</p>
                <div class="grid">
                    <div class="lbl">JUDUL DOKUMEN</div>
                    <div class="val"><?= htmlspecialchars($title) ?></div>
                    
                    <div class="row">
                        <div class="col">
                            <div class="lbl">NOMOR DOKUMEN</div>
                            <div class="val"><?= htmlspecialchars($nomor) ?></div>
                        </div>
                        <div class="col">
                            <div class="lbl">TANGGAL <?= $who === 'approver' ? 'APPROVAL' : 'DIBUAT' ?></div>
                            <div class="val"><?= htmlspecialchars($tanggal_display) ?></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <div class="lbl">STATUS</div>
                            <div class="val text-success" style="color: #19a974 !important;"><?= htmlspecialchars($status) ?></div>
                        </div>
                        <div class="col"></div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <div class="lbl">DARI</div>
                            <div class="val"><?= htmlspecialchars($dari) ?></div>
                        </div>
                        <div class="col">
                            <div class="lbl">TUJUAN</div>
                            <div class="val"><?= htmlspecialchars($tujuan) ?></div>
                        </div>
                    </div>

                    <?php
                        $box_name = ($who === 'approver') ? $approver : $pemohon;
                        $box_label = ($who === 'approver') ? 'DISETUJUI OLEH' : 'PEMOHON';
                        $box_sub = ($who === 'approver') ? 'SPV/Manager Klinik' : '';
                    ?>
                    <div class="approver-box">
                        <div class="approver-icon">
                            <?= strtoupper(substr((string)$box_name, 0, 1)) ?>
                        </div>
                        <div class="approver-info">
                            <div class="lbl"><?= $box_label ?></div>
                            <div class="val mb-0"><?= htmlspecialchars((string)$box_name) ?></div>
                            <?php if ($box_sub !== ''): ?>
                                <div class="lbl"><?= $box_sub ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="foot">✓ Verifikasi Sistem Bumame</div>
        </div>
    </div>
</body>
</html>



