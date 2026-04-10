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

// Check if we have pending data (Proposed Edit)
$is_pending_edit = ($header['status'] === 'pending_edit' && !empty($header['pending_data']));
$pending_payload = $is_pending_edit ? json_decode($header['pending_data'], true) : null;

// Get detail items (Original)
$original_details_data = [];
$stmt_original = $conn->prepare("
    SELECT 
        pbd.*,
        COALESCE(b.kode_barang, '') AS kode_barang,
        COALESCE(b.nama_barang, '') AS nama_barang,
        COALESCE(NULLIF(pbd.satuan, ''), uc.to_uom, b.satuan, '') AS satuan_display
    FROM inventory_pemakaian_bhp_detail pbd
    LEFT JOIN inventory_barang b ON pbd.barang_id = b.id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang AND uc.from_uom = b.satuan
    WHERE pbd.pemakaian_bhp_id = ?
    ORDER BY pbd.id
");
$stmt_original->bind_param("i", $id);
$stmt_original->execute();
$original_details_result = $stmt_original->get_result();
while ($row = $original_details_result->fetch_assoc()) {
    $original_details_data[$row['barang_id']] = $row;
}

// If pending edit, overwrite header values with proposed ones and prepare proposed items
$display_details = [];
if ($is_pending_edit && $pending_payload) {
    $header['tanggal'] = $pending_payload['tanggal'] ?? $header['tanggal'];
    $header['jenis_pemakaian'] = $pending_payload['jenis_pemakaian'] ?? $header['jenis_pemakaian'];
    $header['catatan_transaksi'] = $pending_payload['catatan_transaksi'] ?? $header['catatan_transaksi'];
    
    $proposed_items_map = [];
    if (isset($pending_payload['items']) && is_array($pending_payload['items'])) {
        foreach ($pending_payload['items'] as $it) {
            $bid = (int)$it['barang_id'];
            $b_res = $conn->query("SELECT kode_barang, nama_barang, satuan FROM inventory_barang WHERE id = $bid")->fetch_assoc();
            $proposed_items_map[$bid] = [
                'barang_id' => $bid,
                'kode_barang' => $b_res['kode_barang'] ?? '-',
                'nama_barang' => $b_res['nama_barang'] ?? 'Unknown Item',
                'qty' => (float)$it['qty'],
                'satuan_display' => $it['satuan'] ?? ($b_res['satuan'] ?? '-'),
                'catatan_item' => $it['catatan'] ?? '-'
            ];
        }
    }

    // Compare original and proposed items
    foreach ($proposed_items_map as $bid => $proposed_item) {
        if (isset($original_details_data[$bid])) {
            $original_item = $original_details_data[$bid];
            
            $is_qty_changed = (float)$original_item['qty'] !== (float)$proposed_item['qty'];
            $is_satuan_changed = trim((string)$original_item['satuan_display']) !== trim((string)$proposed_item['satuan_display']);
            $is_catatan_changed = trim((string)($original_item['catatan_item'] ?? '')) !== trim((string)($proposed_item['catatan_item'] ?? ''));

            if ($is_qty_changed || $is_satuan_changed || $is_catatan_changed) {
                $proposed_item['change_type'] = 'changed';
                $proposed_item['original_qty'] = $original_item['qty'];
                $proposed_item['original_satuan'] = $original_item['satuan_display'];
                $proposed_item['original_catatan'] = $original_item['catatan_item'];
            } else {
                $proposed_item['change_type'] = 'unchanged';
            }
            $display_details[] = $proposed_item;
            unset($original_details_data[$bid]); // Mark as processed
        } else {
            $proposed_item['change_type'] = 'added';
            $display_details[] = $proposed_item;
        }
    }

    // Any remaining items in original_details_data were removed
    foreach ($original_details_data as $bid => $original_item) {
        $original_item['change_type'] = 'removed';
        $display_details[] = $original_item;
    }
    // Sort display_details for consistent output (e.g., by barang_id)
    usort($display_details, fn($a, $b) => $a['barang_id'] <=> $b['barang_id']);

} else {
    // If not pending edit, use original details from the map
    foreach ($original_details_data as $bid => $original_item) {
        $display_details[] = $original_item;
    }
    // Sort for consistent output
    usort($display_details, fn($a, $b) => $a['barang_id'] <=> $b['barang_id']);
}
?>

<div class="pemakaian-detail-wrapper">
    <?php if ($is_pending_edit): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
            <i class="fas fa-exclamation-triangle me-3 fs-4 text-warning"></i>
            <div>
                <h6 class="mb-1 fw-bold">Menunggu Persetujuan SPV</h6>
                <p class="mb-0 small opacity-75">Detail di bawah adalah perubahan yang diusulkan oleh Admin Klinik. <br>
                <span class="badge bg-success-subtle text-success border border-success-subtle">Ditambahkan</span> 
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Dihapus</span> 
                <span class="badge bg-info-subtle text-info border border-info-subtle">Diubah</span>
                </p>
            </div>
        </div>
    <?php endif; ?>

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
    <div class="note-box mb-3">
        <div class="note-box-title">
            <i class="fas fa-sticky-note me-1"></i> Catatan Transaksi
        </div>
        <div class="note-box-content">
            <?= nl2br(htmlspecialchars($header['catatan_transaksi'])) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($header['approval_reason'])): ?>
    <div class="note-box mb-4 border-warning bg-warning-subtle">
        <div class="note-box-title text-warning">
            <i class="fas fa-history me-1"></i> Alasan Perubahan / History
        </div>
        <div class="note-box-content text-dark fw-medium">
            <?= nl2br(htmlspecialchars($header['approval_reason'])) ?>
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
                    if (!empty($display_details)):
                        foreach ($display_details as $detail):
                            $row_class = '';
                            $qty_display = fmt_qty($detail['qty']);
                            $satuan_display = htmlspecialchars($detail['satuan_display']);
                            $catatan_display = htmlspecialchars($detail['catatan_item'] ?: '-');
                            $change_indicator = '';

                            if (isset($detail['change_type'])) {
                                switch ($detail['change_type']) {
                                    case 'added':
                                        $row_class = 'table-success';
                                        $change_indicator = '<i class="fas fa-plus-circle text-success me-1"></i>';
                                        break;
                                    case 'removed':
                                        $row_class = 'table-danger';
                                        $change_indicator = '<i class="fas fa-minus-circle text-danger me-1"></i>';
                                        $qty_display = '<del>' . $qty_display . '</del>';
                                        $satuan_display = '<del>' . $satuan_display . '</del>';
                                        $catatan_display = '<del>' . $catatan_display . '</del>';
                                        break;
                                    case 'changed':
                                        $row_class = 'table-info';
                                        $change_indicator = '<i class="fas fa-edit text-info me-1"></i>';
                                        $qty_display = fmt_qty($detail['original_qty']) . ' <i class="fas fa-arrow-right mx-1"></i> ' . fmt_qty($detail['qty']);
                                        $satuan_display = htmlspecialchars($detail['original_satuan']) . ' <i class="fas fa-arrow-right mx-1"></i> ' . htmlspecialchars($detail['satuan_display']);
                                        $catatan_display = htmlspecialchars($detail['original_catatan'] ?: '-') . ' <i class="fas fa-arrow-right mx-1"></i> ' . htmlspecialchars($detail['catatan_item'] ?: '-');
                                        break;
                                }
                            }
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="text-center text-muted"><?= $no++ ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(!empty($detail['kode_barang']) ? $detail['kode_barang'] : '-') ?></span></td>
                        <td class="fw-medium text-dark"><?= $change_indicator ?><?= htmlspecialchars($detail['nama_barang']) ?></td>
                        <td class="text-center"><span class="qty-pill"><?= $qty_display ?></span></td>
                        <td><span class="text-muted small"><?= $satuan_display ?></span></td>
                        <td>
                            <span class="text-muted small italic">
                                <?= $catatan_display ?>
                            </span>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    else:
                    ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Tidak ada item ditemukan.</td>
                    </tr>
                    <?php
                    endif;
                    ?>
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

