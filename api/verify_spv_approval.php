<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{16,80}$/i', $token)) {
    http_response_code(400);
    echo 'Invalid token';
    exit;
}

$tok = $conn->real_escape_string($token);
$q = $conn->query("
    SELECT
        r.id,
        r.nomor_request,
        r.status,
        r.created_at,
        r.spv_approved_at,
        u.nama_lengkap AS spv_name,
        k.nama_klinik AS klinik_name
    FROM request_barang r
    LEFT JOIN users u ON r.spv_approved_by = u.id
    LEFT JOIN klinik k ON (r.dari_level = 'klinik' AND r.dari_id = k.id)
    WHERE r.spv_qr_token = '$tok'
    LIMIT 1
");
$row = $q && $q->num_rows > 0 ? $q->fetch_assoc() : null;
if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$status = strtoupper((string)($row['status'] ?? '-'));
$title = 'APPROVAL REQUEST BARANG';
$nomor = (string)($row['nomor_request'] ?? '-');
$tanggal = (string)($row['spv_approved_at'] ?? '');
$tanggal = $tanggal !== '' ? date('d M Y, H:i', strtotime($tanggal)) : '-';
$klinik = (string)($row['klinik_name'] ?? '-');
$spv = (string)($row['spv_name'] ?? '-');
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
        .row{display:flex;gap:18px}
        .row .col{flex:1}
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
                            <div class="lbl">TANGGAL APPROVAL</div>
                            <div class="val"><?= htmlspecialchars($tanggal) ?></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <div class="lbl">KLINIK</div>
                            <div class="val"><?= htmlspecialchars($klinik) ?></div>
                        </div>
                        <div class="col">
                            <div class="lbl">STATUS</div>
                            <div class="val"><?= htmlspecialchars($status) ?></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <div class="lbl">DISETUJUI OLEH</div>
                            <div class="val"><?= htmlspecialchars($spv) ?></div>
                        </div>
                        <div class="col">
                            <div class="lbl">&nbsp;</div>
                            <div class="val">&nbsp;</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="foot">Verifikasi Sistem Bumame</div>
        </div>
    </div>
</body>
</html>
