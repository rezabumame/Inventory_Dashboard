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
        u_hc.nama_lengkap as hc_name,
        u_actor.nama_lengkap as change_actor_name
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
    LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
    LEFT JOIN inventory_users u_actor ON pb.change_actor_user_id = u_actor.id
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
$is_revision = !empty($header['parent_id']);

// Check if we have pending data (Proposed Edit)
$is_pending_edit = (in_array($header['status'], ['pending_edit', 'pending_approval_spv']) && !empty($header['pending_data']));
$has_history = (!empty($header['pending_data'])); // True if there was ever an edit request
$pending_payload = $has_history ? json_decode($header['pending_data'], true) : null;
$request_meta = ($has_history && is_array($pending_payload) && isset($pending_payload['meta']) && is_array($pending_payload['meta']))
    ? $pending_payload['meta'] : [];
$source_map = [
    'admin_logistik' => 'Admin Logistik',
    'nakes' => 'Nakes',
    'sistem_integrasi' => 'Sistem/Integrasi',
];
$change_source_raw = (string)($request_meta['change_source'] ?? ($header['change_source'] ?? ''));
$change_source_label = (string)($source_map[$change_source_raw] ?? '-');
$change_actor_name = (string)($header['change_actor_name'] ?? '-');
$change_actor_id_meta = (int)($request_meta['change_actor_user_id'] ?? 0);
$change_actor_name_meta = (string)($request_meta['change_actor_name'] ?? '');

if ($change_actor_name_meta !== '') {
    $change_actor_name = $change_actor_name_meta;
} elseif (($change_actor_name === '' || $change_actor_name === '-') && $change_actor_id_meta > 0) {
    $r_actor = $conn->query("SELECT nama_lengkap FROM inventory_users WHERE id = " . $change_actor_id_meta . " LIMIT 1");
    if ($r_actor && $r_actor->num_rows > 0) {
        $change_actor_name = (string)($r_actor->fetch_assoc()['nama_lengkap'] ?? '-');
    }
}

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

// If pending edit or has history, prepare the "diff" view
$display_details = [];
if ($has_history && $pending_payload) {
    // If status is active, header is already the "new" version from DB.
    // If status is pending_edit, we need to manually apply pending changes for preview.
    if ($is_pending_edit) {
        $header['tanggal'] = $pending_payload['tanggal'] ?? $header['tanggal'];
        $header['jenis_pemakaian'] = $pending_payload['jenis_pemakaian'] ?? $header['jenis_pemakaian'];
        $header['catatan_transaksi'] = $pending_payload['catatan_transaksi'] ?? $header['catatan_transaksi'];
    }
    
    // Task Fix: If the transaction is already 'active', we should NOT show the diff view
    // because the 'original' data in DB is already the 'new' data.
    // We only show diff view if it's still 'pending_edit'.
    if (!$is_pending_edit && $header['status'] === 'active') {
        foreach ($original_details_data as $bid => $original_item) {
            if ($is_revision) {
                // If it's a revision and already active, the qty in DB is the delta
                if ($original_item['qty'] < 0) {
                    $original_item['change_type'] = 'removed';
                } else {
                    $original_item['change_type'] = 'added'; // or 'changed', but 'added' is safer for positive delta
                }
            }
            $display_details[] = $original_item;
        }
    } else {
        $ops = $pending_payload['items_ops'] ?? [];
        $delta_items = $pending_payload['delta_items'] ?? [];
        
        if ($is_revision && !empty($delta_items)) {
            // New logic for Revision (Delta-based)
            foreach ($delta_items as $d_it) {
                $bid = (int)($d_it['barang_id'] ?? 0);
                $b_res = $conn->query("SELECT kode_barang, nama_barang, satuan FROM inventory_barang WHERE id = " . $bid . " LIMIT 1");
                $b = $b_res ? $b_res->fetch_assoc() : [];
                $qty_delta = (float)($d_it['qty'] ?? 0);
                
                $display_details[] = [
                    'change_type' => ($qty_delta < 0 ? 'removed' : 'added'),
                    'barang_id' => $bid,
                    'kode_barang' => $b['kode_barang'] ?? '-',
                    'nama_barang' => $b['nama_barang'] ?? 'Unknown Item',
                    'qty' => abs($qty_delta),
                    'satuan_display' => (string)($d_it['satuan'] ?? ($b['satuan'] ?? '-')),
                    'catatan_item' => (string)($d_it['catatan_item'] ?? '')
                ];
            }
        } elseif (is_array($ops) && !empty($ops)) {
            // Logic for standard Edit (Operation-based)
            $original_by_detail_id = [];
        foreach ($original_details_data as $row) {
            $original_by_detail_id[(int)$row['id']] = $row;
        }
        foreach ($ops as $op) {
            $op_type = (string)($op['op'] ?? '');
            if ($op_type === 'add') {
                $bid = (int)($op['barang_id'] ?? 0);
                $b_res = $conn->query("SELECT kode_barang, nama_barang, satuan FROM inventory_barang WHERE id = " . $bid . " LIMIT 1");
                $b = $b_res ? $b_res->fetch_assoc() : [];
                $display_details[] = [
                    'change_type' => 'added',
                    'barang_id' => $bid,
                    'kode_barang' => $b['kode_barang'] ?? '-',
                    'nama_barang' => $b['nama_barang'] ?? 'Unknown Item',
                    'qty' => (float)($op['qty'] ?? 0),
                    'satuan_display' => (string)($op['satuan'] ?? ($b['satuan'] ?? '-')),
                    'catatan_item' => (string)($op['catatan_item'] ?? '')
                ];
                continue;
            }
            if ($op_type === 'remove') {
                $did = (int)($op['detail_id'] ?? 0);
                $row = $original_by_detail_id[$did] ?? null;
                if (!$row) continue;
                $row['change_type'] = 'removed';
                $display_details[] = $row;
                continue;
            }
            if ($op_type === 'update') {
                $did = (int)($op['detail_id'] ?? 0);
                $row = $original_by_detail_id[$did] ?? null;
                $after = $op['after'] ?? [];
                if (!$row) continue;
                $bid_after = (int)($after['barang_id'] ?? 0);
                $b_res = $conn->query("SELECT kode_barang, nama_barang, satuan FROM inventory_barang WHERE id = " . $bid_after . " LIMIT 1");
                $b = $b_res ? $b_res->fetch_assoc() : [];
                $display_details[] = [
                    'change_type' => 'changed',
                    'barang_id' => $bid_after,
                    'kode_barang' => $b['kode_barang'] ?? '-',
                    'nama_barang' => $b['nama_barang'] ?? 'Unknown Item',
                    'qty' => (float)($after['qty'] ?? 0),
                    'satuan_display' => (string)($after['satuan'] ?? ($b['satuan'] ?? '-')),
                    'catatan_item' => (string)($after['catatan_item'] ?? ''),
                    'original_qty' => (float)($row['qty'] ?? 0),
                    'original_satuan' => (string)($row['satuan_display'] ?? ''),
                    'original_catatan' => (string)($row['catatan_item'] ?? ''),
                    'original_nama_barang' => (string)($row['nama_barang'] ?? '-'),
                ];
            }
        }
    } else {
        // Backward compatibility for old pending payload.
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
                unset($original_details_data[$bid]);
            } else {
                $proposed_item['change_type'] = 'added';
                $display_details[] = $proposed_item;
            }
        }
        foreach ($original_details_data as $bid => $original_item) {
            $original_item['change_type'] = 'removed';
            $display_details[] = $original_item;
        }
    }
}
    usort($display_details, fn($a, $b) => ($a['barang_id'] <=> $b['barang_id']));

} else {
    // If not pending edit, use original details from the map
    foreach ($original_details_data as $bid => $original_item) {
        $display_details[] = $original_item;
    }
    // Sort for consistent output
    usort($display_details, fn($a, $b) => $a['barang_id'] <=> $b['barang_id']);
}

if (!function_exists('compact_transaction_note')) {
    function compact_transaction_note(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        if (stripos($raw, 'Catatan Item:') === false && stripos($raw, 'Auto Deduction') === false) {
            return $raw;
        }

        $main = preg_replace('/\bCatatan Item:\s*[\s\S]*$/i', '', $raw);
        $main = preg_replace('/\bAuto Deduction(\s*\(.*?\))?:\s*[\s\S]*$/i', '', (string)$main);
        $main = trim((string)$main);

        preg_match_all('/Auto:\s*([A-Za-z0-9\-\/_,\s]+)/i', $raw, $m);
        $refs = [];
        if (!empty($m[1])) {
            foreach ($m[1] as $chunk) {
                foreach (preg_split('/\s*,\s*/', trim((string)$chunk)) as $ref) {
                    $ref = trim((string)$ref);
                    if ($ref !== '') $refs[$ref] = true;
                }
            }
        }

        $parts = [];
        if ($main !== '') $parts[] = $main;
        if (!empty($refs)) $parts[] = 'Auto Deduction: ' . implode(', ', array_keys($refs));

        return trim(implode("\n\n", $parts));
    }
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
    <?php elseif ($has_history): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
            <i class="fas fa-check-circle me-3 fs-4 text-success"></i>
            <div>
                <h6 class="mb-1 fw-bold">Transaksi Telah Diubah (Approved)</h6>
                <p class="mb-0 small opacity-75">Detail di bawah menampilkan riwayat perubahan yang telah disetujui oleh SPV. <br>
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
                        <span class="info-label">Tanggal Pemakaian BHP</span>
                        <span class="info-value"><?= date('d F Y', strtotime($header['tanggal'])) ?></span>
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
    <?php $catatan_transaksi_display = compact_transaction_note((string)$header['catatan_transaksi']); ?>
    <div class="note-box mb-3">
        <div class="note-box-title">
            <i class="fas fa-sticky-note me-1"></i> Catatan Transaksi
        </div>
        <div class="note-box-content">
            <?= nl2br(htmlspecialchars($catatan_transaksi_display)) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($header['approval_reason']) && $header['status'] !== 'active'): ?>
    <div class="note-box mb-4 border-warning bg-warning-subtle">
        <div class="note-box-title text-warning">
            <i class="fas fa-history me-1"></i> Alasan Perubahan / History
        </div>
        <div class="note-box-content text-dark fw-medium">
            <?= nl2br(htmlspecialchars($header['approval_reason'])) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_history): ?>
    <div class="note-box mb-4 border-info bg-info-subtle">
        <div class="note-box-title text-info">
            <i class="fas fa-file-signature me-1"></i> Riwayat Request Perubahan
        </div>
        <div class="note-box-content text-dark fw-medium">
            <div><strong>Alasan perubahan:</strong> <?= htmlspecialchars((string)($request_meta['reason_label'] ?? $header['approval_reason'] ?? '-')) ?></div>
            <div><strong>Sumber perubahan:</strong> <?= htmlspecialchars($change_source_label) ?></div>
            <div><strong>Pelaku asal:</strong> <?= htmlspecialchars($change_actor_name) ?></div>
            <?php if (!empty($header['spv_approved_by'])): ?>
                <?php 
                    $spv_id = (int)$header['spv_approved_by'];
                    $r_spv = $conn->query("SELECT nama_lengkap FROM inventory_users WHERE id = $spv_id LIMIT 1");
                    $spv_name = $r_spv ? ($r_spv->fetch_assoc()['nama_lengkap'] ?? '-') : '-';
                ?>
                <div class="mt-2 pt-2 border-top border-info-subtle small text-muted">
                    <i class="fas fa-check-double me-1"></i> Disetujui oleh <strong><?= htmlspecialchars($spv_name) ?></strong> pada <?= date('d F Y H:i', strtotime($header['spv_approved_at'])) ?>
                </div>
            <?php endif; ?>
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
                <thead style="background-color: #204EAB; color: white;">
                    <tr>
                        <th width="60" class="text-center py-3 small fw-bold text-uppercase">No</th>
                        <th width="120" class="py-3 small fw-bold text-uppercase">Kode Barang</th>
                        <th class="py-3 small fw-bold text-uppercase">Nama Barang</th>
                        <th width="100" class="text-center py-3 small fw-bold text-uppercase">Qty</th>
                        <th width="100" class="py-3 small fw-bold text-uppercase">Satuan</th>
                        <th class="py-3 small fw-bold text-uppercase">Catatan Item</th>
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
                                        if ($is_revision) {
                                            $qty_display = '<span class="badge bg-success px-3 py-2" style="font-size: 0.9rem;">+' . fmt_qty($detail['qty']) . '</span>';
                                        }
                                        break;
                                    case 'removed':
                                        $row_class = 'table-danger';
                                        $change_indicator = '<i class="fas fa-minus-circle text-danger me-1"></i>';
                                        if ($is_revision) {
                                            $qty_display = '<span class="badge bg-danger px-3 py-2" style="font-size: 0.9rem;">' . fmt_qty($detail['qty']) . '</span>';
                                        } else {
                                            $qty_display = '<del>' . $qty_display . '</del>';
                                            $satuan_display = '<del>' . $satuan_display . '</del>';
                                            $catatan_display = '<del>' . $catatan_display . '</del>';
                                        }
                                        break;
                                    case 'changed':
                                        $row_class = 'table-info';
                                        $change_indicator = '<i class="fas fa-edit text-info me-1"></i>';
                                        if (!empty($detail['original_nama_barang']) && (string)$detail['original_nama_barang'] !== (string)$detail['nama_barang']) {
                                            $change_indicator .= '<small class="text-muted">(Item: ' . htmlspecialchars((string)$detail['original_nama_barang']) . ' <i class="fas fa-arrow-right mx-1"></i> ' . htmlspecialchars((string)$detail['nama_barang']) . ')</small> ';
                                        }
                                        if ($is_revision) {
                                            $delta = (float)$detail['qty'] - (float)$detail['original_qty'];
                                            $delta_str = ($delta > 0 ? '+' : '') . fmt_qty($delta);
                                            $qty_display = '<span class="badge bg-primary px-3 py-2" style="font-size: 0.9rem;">' . $delta_str . '</span><br><small class="text-muted">(' . fmt_qty($detail['original_qty']) . ' <i class="fas fa-arrow-right"></i> ' . fmt_qty($detail['qty']) . ')</small>';
                                        } else {
                                            $qty_display = fmt_qty($detail['original_qty']) . ' <i class="fas fa-arrow-right mx-1"></i> ' . fmt_qty($detail['qty']);
                                        }
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
    max-height: 120px;
    overflow-y: auto;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #204EAB;
}

.qty-pill {
    display: inline-block;
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

