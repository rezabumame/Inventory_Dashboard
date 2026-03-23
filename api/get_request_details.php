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
$req = $conn->query("SELECT * FROM request_barang WHERE id = $request_id")->fetch_assoc();
if (!$req) {
    echo '<div class="alert alert-danger mb-0">Permintaan barang tidak ditemukan.</div>';
    exit;
}

// Check if user can approve
$can_approve = false;
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

$can_process = false;
if (in_array($req['status'], ['approved', 'partial'], true)) {
    if ($user_role == 'super_admin') $can_process = true;
    if ($user_role == 'admin_klinik' && $req['dari_level'] == 'klinik' && (int)$req['dari_id'] === (int)$user_klinik) $can_process = true;
}

// Get Request Details
$sql = "SELECT d.*, b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan
        FROM request_barang_detail d 
        JOIN barang b ON d.barang_id = b.id
        LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
        WHERE d.request_barang_id = $request_id";
$res = $conn->query($sql);

if ($can_approve) {
    echo '<div data-pending="true"></div>';
}
if ($can_process) {
    echo '<div data-process="true"></div>';
}
?>

<div class="mb-3">
    <div class="small text-muted">Catatan</div>
    <div class="fw-semibold"><?= nl2br(htmlspecialchars($req['catatan'] ?? '-')) ?></div>
</div>

<?php if (!empty($req['dokumen_path'])): ?>
    <div class="mb-3">
        <div class="small text-muted">Dokumen Odoo</div>
        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars((string)$req['dokumen_path']) ?>" target="_blank" rel="noopener">
            <i class="fas fa-file-download me-1"></i>
            <?= htmlspecialchars((string)($req['dokumen_name'] ?? 'Download Dokumen')) ?>
        </a>
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
                    <?php $max_recv = (int)($row['qty_approved'] ?? 0); if ($max_recv <= 0) $max_recv = (int)$row['qty_request']; ?>
                    <input type="number" name="received_qtys[]" class="form-control form-control-sm"
                           value="<?= $max_recv ?>" max="<?= $max_recv ?>" min="0">
                <?php else: ?>
                    <?= (int)($row['qty_received'] ?? 0) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

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
