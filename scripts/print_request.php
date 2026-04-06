<?php
session_start();
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    die("Unauthorized access");
}

$id = intval($_GET['id']);

// Fetch Request Header
$has_spv = false;
try {
    $res_col = $conn->query("SHOW COLUMNS FROM `inventory_request_barang` LIKE 'spv_approved_by'");
    if ($res_col && $res_col->num_rows > 0) $has_spv = true;
} catch (Exception $e) {}

$query = "SELECT r.*, 
            u_req.nama_lengkap as requestor_name,
            u_app.nama_lengkap as approver_name," .
            ($has_spv ? " u_spv.nama_lengkap as spv_approver_name," : "") . "
            k_dari.nama_klinik as dari_klinik_nama,
            k_ke.nama_klinik as ke_klinik_nama
          FROM inventory_request_barang r
          JOIN inventory_users u_req ON r.created_by = u_req.id
          LEFT JOIN inventory_users u_app ON r.approved_by = u_app.id" .
          ($has_spv ? " LEFT JOIN inventory_users u_spv ON r.spv_approved_by = u_spv.id" : "") . "
          LEFT JOIN inventory_klinik k_dari ON (r.dari_level = 'klinik' AND r.dari_id = k_dari.id)
          LEFT JOIN inventory_klinik k_ke ON (r.ke_level = 'klinik' AND r.ke_id = k_ke.id)
          WHERE r.id = $id";
$res = $conn->query($query);
$req = $res->fetch_assoc();

if (!$req) {
    die("Request tidak ditemukan");
}

try {
    $res_col = $conn->query("SHOW COLUMNS FROM `inventory_request_barang` LIKE 'request_qr_token'");
    if ($res_col && $res_col->num_rows > 0) {
        if (empty($req['request_qr_token'])) {
            $newTok = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("UPDATE inventory_request_barang SET request_qr_token = ?, request_qr_at = COALESCE(request_qr_at, NOW()) WHERE id = ?");
            $stmt->bind_param("si", $newTok, $id);
            $stmt->execute();
            $req['request_qr_token'] = $newTok;
        }
    }
} catch (Exception $e) {}

// Fetch Details
$query_det = "SELECT d.*, b.kode_barang, b.nama_barang, b.satuan 
              FROM inventory_request_barang_detail d
              JOIN inventory_barang b ON d.barang_id = b.id
              WHERE d.request_barang_id = $id";
$details = $conn->query($query_det);

$dari_label = "";
if ($req['dari_level'] == 'klinik') {
    $dari_label = $req['dari_klinik_nama'] ?: "Klinik (ID: ".$req['dari_id'].")";
} elseif ($req['dari_level'] == 'hc') {
    $dari_label = "Petugas HC: " . $req['requestor_name'];
} else {
    $dari_label = strtoupper($req['dari_level']);
}

$ke_label = "";
if ($req['ke_level'] == 'klinik') {
    $ke_label = $req['ke_klinik_nama'] ?: "Klinik (ID: ".$req['ke_id'].")";
} elseif ($req['ke_level'] == 'gudang_utama') {
    $ke_label = "Gudang Utama";
} else {
    $ke_label = strtoupper($req['ke_level']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Permintaan Barang - <?= $req['nomor_request'] ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; color: #333; line-height: 1.4; margin: 0; padding: 20px; font-size: 12px; }
        
        /* Hide browser headers/footers */
        @page {
            size: auto;
            margin: 10mm;
        }
        
        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #204EAB; padding-bottom: 15px; margin-bottom: 25px; }
        .logo-box { flex-grow: 1; display: flex; flex-direction: column; }
        .logo-box h1 { margin: 0; color: #204EAB; font-size: 32px; font-weight: 700; text-transform: none; letter-spacing: -1px; }
        .logo-box h1 span { color: #4facfe; }
        .logo-box p { margin: 5px 0 0; color: #666; font-size: 11px; }
        
        .doc-title { text-align: center; margin-bottom: 30px; }
        .doc-title h2 { margin: 0; padding: 5px 20px; border-bottom: 2px solid #333; display: inline-block; text-transform: uppercase; font-size: 18px; }

        .info-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .info-table td { padding: 4px 8px; vertical-align: top; }
        .info-table td.label { width: 100px; font-weight: bold; color: #555; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th { background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: 1px solid #aaa; padding: 8px; text-align: left; text-transform: uppercase; font-size: 10px; }
        .items-table td { border: 1px solid #aaa; padding: 8px; }
        
        .notes { margin-bottom: 40px; border: 1px solid #eee; padding: 10px; background: #fdfdfd; min-height: 60px; }
        .notes strong { display: block; margin-bottom: 5px; color: #555; }
        
        .signature-section { width: 100%; margin-top: 50px; display: table; table-layout: fixed; }
        .signature-box { display: table-cell; text-align: center; vertical-align: top; }
        .signature-box p { margin: 0 0 5px 0; }
        .signature-space { height: 110px; margin-bottom: 5px; }
        .signature-space img { display: block; margin: 0 auto; }
        .signature-name { border-top: 1.5px solid #333; display: inline-block; min-width: 180px; padding-top: 5px; font-weight: bold; }
        
        @media print {
            body { padding: 0; }
            .btn-print { display: none; }
        }
        
        .btn-print {
            background: #204EAB; color: white !important; border: none; padding: 10px 25px;
            border-radius: 50px; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            display: inline-flex; align-items: center; gap: 8px; 
            white-space: nowrap; text-decoration: none; font-size: 14px;
        }
        .btn-print:hover { background: #1a3e8a; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-box">
            <h1>Bumame <span>Inventory</span></h1>
            <p>JL. TB Simatupang NO.33 RT.01/ RW.05, Ragunan, Pasar Minggu, Jakarta Selatan, DKI Jakarta 12550</p>
        </div>
        <div class="btn-container">
            <button class="btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> CETAK DOKUMEN
            </button>
        </div>
    </div>

    <div class="doc-title">
        <h2>FORM PERMINTAAN BARANG</h2>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">No. Request</td>
            <td>: <?= $req['nomor_request'] ?></td>
            <td class="label">Tanggal</td>
            <td>: <?= date('d F Y', strtotime($req['created_at'])) ?></td>
        </tr>
        <tr>
            <td class="label">Dari</td>
            <td>: <?= htmlspecialchars($dari_label) ?></td>
            <td class="label">Dicetak Oleh</td>
            <td>: <?= htmlspecialchars($_SESSION['nama_lengkap']) ?> (<?= date('d/m/Y H:i') ?>)</td>
        </tr>
        <tr>
            <td class="label">Ke Tujuan</td>
            <td>: <?= htmlspecialchars($ke_label) ?></td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th width="30" style="text-align: center;">NO</th>
                <th width="110">KODE BARANG</th>
                <th>NAMA BARANG</th>
                <th width="80">SATUAN</th>
                <th width="80" style="text-align: center;">QTY REQ</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            while ($item = $details->fetch_assoc()): ?>
            <tr>
                <td style="text-align: center;"><?= $no++ ?></td>
                <td><?= htmlspecialchars($item['kode_barang']) ?></td>
                <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                <td><?= htmlspecialchars($item['satuan']) ?></td>
                <td style="text-align: center; font-weight: bold;"><?= $item['qty_request'] ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="notes">
        <strong>Catatan:</strong>
        <?= nl2br(htmlspecialchars($req['catatan'] ?: '-')) ?>
    </div>

    <?php
        // Build verify URLs using base_url with page=qr_verify_rab
        $reqTok  = (string)($req['request_qr_token'] ?? '');
        $verifyUrlReq = $reqTok !== '' ? base_url('index.php?page=qr_verify_rab&id=' . $id . '&token=' . urlencode($reqTok) . '&who=requester') : '';

        $show_spv_qr  = $has_spv && !empty($req['spv_approved_by']) && !empty($req['spv_qr_token']);
        $verifyUrlSpv = $show_spv_qr ? base_url('index.php?page=qr_verify_rab&id=' . $id . '&token=' . urlencode((string)$req['spv_qr_token']) . '&who=approver') : '';
        $spv_label    = $show_spv_qr ? htmlspecialchars((string)($req['spv_approver_name'] ?? 'SPV/Manager')) : '..........................';
    ?>
    <div class="signature-section">
        <div class="signature-box">
            <p>Pemohon,</p>
            <div class="signature-space">
                <?php if ($verifyUrlReq !== ''): ?>
                    <img src="<?= htmlspecialchars(base_url('api/qr.php?text=' . urlencode($verifyUrlReq))) ?>"
                         style="width:95px;height:95px;" alt="QR Pemohon">
                <?php endif; ?>
            </div>
            <div class="signature-name">( <?= htmlspecialchars($req['requestor_name']) ?> )</div>
            <p style="margin-top: 4px; font-size: 10px;">Requester</p>
        </div>
        <div class="signature-box">
            <p>Menyetujui,</p>
            <div class="signature-space">
                <?php if ($show_spv_qr): ?>
                    <img src="<?= htmlspecialchars(base_url('api/qr.php?text=' . urlencode($verifyUrlSpv))) ?>"
                         style="width:95px;height:95px;" alt="QR Approval">
                <?php endif; ?>
            </div>
            <div class="signature-name">( <?= $spv_label ?> )</div>
            <p style="margin-top: 4px; font-size: 10px;">SPV / Manager</p>
        </div>
        <div class="signature-box" style="visibility: hidden;">
            <p>&nbsp;</p>
        </div>
    </div>

    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <script>
        // Auto print usually not desired but let's keep it clean
    </script>
</body>
</html>

