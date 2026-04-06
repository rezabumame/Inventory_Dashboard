<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    echo "Unauthorized";
    exit;
}

$request_id = (int)$_GET['id'];
$user_role = $_SESSION['role'];
$user_klinik = $_SESSION['klinik_id'];

// Get Request Header
$req = $conn->query("SELECT * FROM inventory_request_barang WHERE id = $request_id")->fetch_assoc();
if (!$req) {
    echo '<div class="alert alert-danger mb-0">Permintaan barang tidak ditemukan.</div>';
    exit;
}

// Check if user can approve (destination) or approve SPV (internal)
$can_approve = false;
$can_spv = false;
if ($req['status'] == 'pending' || $req['status'] == 'pending_gudang') {
    if ($user_role == 'super_admin') $can_approve = true;
    if ($user_role == 'admin_gudang') {
        // Admin Gudang can approve if:
        // 1. Request is to Gudang Utama
        // 2. Request is Inter-Klinik (pending_gudang usually implies waiting for gudang validation)
        // 3. Status is pending_gudang
        if ($req['ke_level'] == 'gudang_utama') $can_approve = true;
        if ($req['status'] == 'pending_gudang') $can_approve = true;
        if ($req['dari_level'] == 'klinik' && $req['ke_level'] == 'klinik') $can_approve = true; 
    }
    if ($user_role == 'admin_klinik' && $req['ke_level'] == 'klinik' && $req['ke_id'] == $user_klinik && $req['status'] == 'pending') $can_approve = true;
}
if ($req['status'] == 'pending_spv') {
    if ($user_role == 'super_admin') $can_spv = true;
    if ($user_role == 'spv_klinik' && $req['dari_level'] == 'klinik' && (int)$req['dari_id'] === (int)$user_klinik) $can_spv = true;
}

$can_process = false;
if (in_array($req['status'], ['approved', 'partial'], true)) {
    if ($user_role == 'super_admin') $can_process = true;
    if ($user_role == 'admin_klinik' && $req['dari_level'] == 'klinik' && (int)$req['dari_id'] === (int)$user_klinik) $can_process = true;
}
$can_cancel = false;
if ($user_role == 'admin_klinik' && $req['dari_level'] == 'klinik' && (int)$req['dari_id'] === (int)$user_klinik && $req['status'] == 'pending_spv') $can_cancel = true;
if ($user_role == 'spv_klinik' && $req['dari_level'] == 'klinik' && (int)$req['dari_id'] === (int)$user_klinik && in_array($req['status'], ['pending_spv','pending'], true)) $can_cancel = true;

// Get Request Details
$sql = "SELECT d.*, b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan
        FROM inventory_request_barang_detail d 
        JOIN inventory_barang b ON d.barang_id = b.id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE d.request_barang_id = $request_id";
$res = $conn->query($sql);

if ($can_approve) {
    echo '<div data-pending="true"></div>';
}
if ($can_spv) {
    echo '<div data-spv="true"></div>';
}
if ($can_process) {
    echo '<div data-process="true"></div>';
    if (($req['status'] ?? '') === 'partial') {
        echo '<div data-is-partial="true"></div>';
    }
}
if ($can_cancel) {
    echo '<div data-can-cancel="true"></div>';
}
?>

<div class="mb-3">
    <div class="small text-muted">Catatan</div>
    <div class="fw-semibold"><?= nl2br(htmlspecialchars($req['catatan'] ?? '-')) ?></div>
</div>

<?php 
$dokumens = [];
$res_dok = $conn->query("SELECT * FROM inventory_request_barang_dokumen WHERE request_barang_id = $request_id ORDER BY created_at ASC");
if ($res_dok && $res_dok->num_rows > 0) {
    while ($d = $res_dok->fetch_assoc()) {
        $dokumens[] = $d;
    }
} elseif (!empty($req['dokumen_path'])) {
    $dokumens[] = [
        'dokumen_path' => $req['dokumen_path'],
        'dokumen_name' => $req['dokumen_name'],
        'created_at' => $req['processed_at'] ?? ''
    ];
}
?>

<?php if (!empty($dokumens)): ?>
    <div class="mb-3">
        <div class="small text-muted mb-1">Dokumen Odoo Tertaut</div>
        <?php foreach ($dokumens as $idx => $dok): ?>
            <div class="mb-2">
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars((string)$dok['dokumen_path']) ?>" target="_blank" rel="noopener">
                    <i class="fas fa-file-download me-1"></i>
                    <?= htmlspecialchars((string)($dok['dokumen_name'] ?? ('Dokumen ' . ($idx + 1)))) ?>
                </a>
                <span class="text-muted small ms-2"><?= !empty($dok['created_at']) ? date('d M Y H:i', strtotime($dok['created_at'])) : '' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>Barang</th>
            <th>Qty Request</th>
            <th>Qty Disetujui</th>
            <th>Qty Diterima</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars(($row['kode_barang'] ?? '-') . ' - ' . $row['nama_barang']) ?></td>
            <td><?= $row['qty_request'] ?> <?= $row['satuan'] ?></td>
            <td>
                <?php if ($can_approve): ?>
                    <input type="hidden" name="approved_items[]" value="<?= $row['barang_id'] ?>">
                    <input type="number" name="approved_qtys[]" class="form-control form-control-sm" 
                           value="<?= $row['qty_request'] ?>" max="<?= $row['qty_request'] ?>" min="0">
                <?php else: ?>
                    <?= $row['qty_approved'] ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($can_process): ?>
                    <input type="hidden" name="received_items[]" value="<?= $row['barang_id'] ?>">
                    <?php 
                        $app = (float)($row['qty_approved'] ?? 0); 
                        if ($app <= 0) $app = (float)$row['qty_request'];
                        $already = (float)($row['qty_received'] ?? 0);
                        $max_recv = $app - $already;
                        if ($max_recv < 0) $max_recv = 0;
                    ?>
                    <?php if ($already > 0): ?>
                        <div class="small text-success mb-1">Sudah diterima: <?= $already ?></div>
                    <?php endif; ?>
                    <?php if ($max_recv > 0): ?>
                        <input type="number" name="received_qtys[]" class="form-control form-control-sm"
                               value="" max="<?= htmlspecialchars((string)$max_recv) ?>" min="0" step="0.0001" placeholder="isi qty diterima" required>
                    <?php else: ?>
                        <span class="badge bg-success">Lengkap</span>
                        <input type="hidden" name="received_qtys[]" value="0">
                    <?php endif; ?>
                <?php else: ?>
                    <?= (float)($row['qty_received'] ?? 0) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<?php if ($can_spv): ?>
    <div class="alert alert-info">
        <small><i class="fas fa-info-circle"></i> Request ini menunggu approval SPV/Manager Klinik sebelum diteruskan ke tujuan.</small>
    </div>
<?php endif; ?>

<?php if ($can_approve): ?>
    <div class="alert alert-info">
        <small><i class="fas fa-info-circle"></i> Persetujuan hanya menetapkan jumlah yang disetujui. Pergerakan stok akan dilakukan setelah dokumen diproses.</small>
    </div>
<?php endif; ?>

<?php if ($can_process): ?>
    <div class="mb-3">
        <label class="form-label fw-bold">Unggah Dokumen Odoo (Penerimaan)</label>
        <input type="file" name="dokumen" class="form-control" accept=".pdf,.xls,.xlsx,.csv" required>
        <div class="form-text">Diunggah oleh klinik pemohon setelah barang diterima. Format: PDF/XLS/XLSX/CSV.</div>
    </div>
<?php endif; ?>
