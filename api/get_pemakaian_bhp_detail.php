<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Unauthorized</div>';
    exit;
}

$id = $_GET['id'] ?? 0;

// Get header data
$stmt = $conn->prepare("
    SELECT 
        pb.*,
        k.nama_klinik,
        u_created.nama_lengkap as created_by_name,
        u_hc.nama_lengkap as hc_name
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
    LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
    WHERE pb.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-danger">Data tidak ditemukan</div>';
    exit;
}

$header = $result->fetch_assoc();

// Get detail items
$stmt = $conn->prepare("
    SELECT 
        pbd.*,
        COALESCE(b.kode_barang, sm.kode_barang, '') AS kode_barang,
        COALESCE(b.nama_barang, sm.kode_barang) AS nama_barang,
        COALESCE(NULLIF(pbd.satuan, ''), uc.to_uom, b.satuan, '') AS satuan_display
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
    LEFT JOIN inventory_barang b ON pbd.barang_id = b.id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    LEFT JOIN inventory_stock_mirror sm ON sm.odoo_product_id = b.odoo_product_id AND sm.location_code = k.kode_klinik
    WHERE pbd.pemakaian_bhp_id = ?
    ORDER BY pbd.id
");
$stmt->bind_param("i", $id);
$stmt->execute();
$details = $stmt->get_result();
?>

<div class="pemakaian-detail-wrapper">
    <!-- Header Infographic -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="info-card h-100">
                <div class="info-card-header">
                    <i class="fas fa-file-invoice" style="color: #204EAB;"></i>
                    <span>Informasi Transaksi</span>
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <span class="info-label">No. Pemakaian</span>
                        <span class="info-value fw-bold text-dark"><?= htmlspecialchars($header['nomor_pemakaian']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Tanggal</span>
                        <span class="info-value"><?= date('d F Y H:i', strtotime($header['tanggal'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Jenis Pemakaian</span>
                        <span class="info-value">
                            <?php if ($header['jenis_pemakaian'] === 'klinik'): ?>
                                <span class="badge-custom badge-klinik">
                                    <i class="fas fa-hospital me-1"></i> Pemakaian Klinik
                                </span>
                            <?php else: ?>
                                <span class="badge-custom badge-hc">
                                    <i class="fas fa-user-nurse me-1"></i> Pemakaian HC
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-card h-100">
                <div class="info-card-header">
                    <i class="fas fa-map-marker-alt" style="color: #204EAB;"></i>
                    <span>Lokasi & Penanggung Jawab</span>
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <span class="info-label">Unit/Klinik</span>
                        <span class="info-value"><?= htmlspecialchars($header['nama_klinik'] ?? '-') ?></span>
                    </div>
                    <?php if (($header['jenis_pemakaian'] ?? '') !== 'klinik'): ?>
                    <div class="info-item">
                        <span class="info-label">Petugas HC</span>
                        <span class="info-value">
                            <i class="fas fa-user-nurse text-muted me-1"></i>
                            <?= htmlspecialchars($header['hc_name'] ?? '-') ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Dibuat Oleh</span>
                        <span class="info-value">
                            <i class="fas fa-user-circle text-muted me-1"></i>
                            <?= htmlspecialchars($header['created_by_name']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($header['catatan_transaksi'])): ?>
    <div class="note-box mb-4">
        <div class="note-box-title">
            <i class="fas fa-sticky-note me-1"></i> Catatan Transaksi
        </div>
        <div class="note-box-content">
            <?= nl2br(htmlspecialchars($header['catatan_transaksi'])) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items Table -->
    <div class="items-section">
        <div class="section-title mb-2">
            <i class="fas fa-boxes me-2" style="color: #204EAB;"></i>Daftar Item Barang
        </div>
        <div class="table-responsive rounded-3 border">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th width="50" class="text-center py-3 text-muted small fw-bold">No</th>
                        <th class="py-3 text-muted small fw-bold">Kode Barang</th>
                        <th class="py-3 text-muted small fw-bold">Nama Barang</th>
                        <th width="100" class="text-center py-3 text-muted small fw-bold">Qty</th>
                        <th width="100" class="py-3 text-muted small fw-bold">Satuan</th>
                        <th class="py-3 text-muted small fw-bold">Catatan Item</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    while ($detail = $details->fetch_assoc()): 
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?= $no++ ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(!empty($detail['kode_barang']) ? $detail['kode_barang'] : '-') ?></span></td>
                        <td class="fw-medium text-dark"><?= htmlspecialchars($detail['nama_barang']) ?></td>
                        <td class="text-center"><span class="qty-pill"><?= fmt_qty($detail['qty']) ?></span></td>
                        <td><span class="text-muted small"><?= htmlspecialchars($detail['satuan_display']) ?></span></td>
                        <td>
                            <span class="text-muted small italic">
                                <?= htmlspecialchars($detail['catatan_item'] ?: '-') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Modal Enhancements */
.pemakaian-detail-wrapper {
    font-family: 'Poppins', sans-serif;
    color: #444;
    background-color: #f8fafc;
    padding: 20px;
    border-radius: 0 0 12px 12px;
}

.info-card {
    background: #fff;
    border: none;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    transition: all 0.3s ease;
}

.info-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.info-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px dashed #e2e8f0;
    font-size: 0.9rem;
    font-weight: 600;
    color: #2d3748;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.info-label {
    font-size: 0.8rem;
    color: #718096;
}

.info-value {
    font-size: 0.85rem;
    color: #2d3748;
}

.badge-custom {
    padding: 4px 10px;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-klinik {
    background-color: #ebf3ff;
    color: #204EAB;
    border: 1px solid #c8dbf7;
}

.badge-hc {
    background-color: #fffaf0;
    color: #dd6b20;
    border: 1px solid #feebc8;
}

.note-box {
    background: #fff;
    border-left: 4px solid #204EAB;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.note-box-title {
    font-size: 0.8rem;
    font-weight: 700;
    color: #4a5568;
    margin-bottom: 5px;
    text-transform: uppercase;
}

.note-box-content {
    font-size: 0.85rem;
    color: #2d3748;
    line-height: 1.5;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #204EAB;
}

.qty-pill {
    background-color: #204EAB;
    color: white;
    padding: 2px 12px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
}

.table th {
    letter-spacing: 0.5px;
}

.table tbody tr:hover {
    background-color: #f8fbff !important;
}

.italic {
    font-style: italic;
}
</style>

