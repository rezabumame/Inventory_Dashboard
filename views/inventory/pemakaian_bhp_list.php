<?php
// Check access
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'petugas_hc'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    redirect('index.php?page=dashboard');
}

$user_klinik_id = $_SESSION['klinik_id'] ?? null;
$user_role = $_SESSION['role'];
$can_choose_klinik = in_array($user_role, ['super_admin', 'admin_gudang', 'cs', 'admin_sales'], true);

try {
    $t = $conn->real_escape_string('inventory_pemakaian_bhp');
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE 'user_hc_id'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$t` ADD COLUMN `user_hc_id` INT NULL AFTER `klinik_id`");
    }
} catch (Exception $e) {
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'list';

// Get filters (Default to last 7 days)
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));

// Build query based on role for LIST tab
$where_clause = "1=1";
$params = [];
$types = "";

if ($user_role === 'admin_klinik' && $user_klinik_id) {
    $where_clause .= " AND pb.klinik_id = ?";
    $params[] = $user_klinik_id;
    $types .= "i";
}

if ($user_role === 'petugas_hc') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $where_clause .= " AND pb.user_hc_id = ?";
    $params[] = $uid;
    $types .= "i";
}

if (!empty($start_date)) {
    $where_clause .= " AND pb.tanggal >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if (!empty($end_date)) {
    $where_clause .= " AND pb.tanggal <= ?";
    $params[] = $end_date;
    $types .= "s";
}

if ($active_tab === 'data_out') {
    // Logic for data_out view remains here, but export logic moved to api/export_odoo_bhp.php
}

if ($active_tab == 'list') {
    $query = "
        SELECT 
            pb.*,
            k.nama_klinik,
            u_created.nama_lengkap as created_by_name,
            (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = pb.id) as total_items
        FROM inventory_pemakaian_bhp pb
        LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
        LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
        WHERE $where_clause
        ORDER BY pb.tanggal DESC, pb.created_at DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} elseif ($active_tab == 'data_out') {
    $query_out = "
        SELECT 
            pb.tanggal, 
            pb.nomor_pemakaian,
            pb.jenis_pemakaian, 
            b.nama_barang, 
            COALESCE(NULLIF(pbd.satuan, ''), uc.to_uom, b.satuan) AS satuan, 
            COALESCE(NULLIF(uc.from_uom, ''), b.satuan) AS uom_odoo,
            COALESCE(uc.multiplier, 1) AS uom_ratio,
            pbd.qty, 
            u_hc.nama_lengkap as hc_name,
            k.nama_klinik,
            k.id as klinik_id
        FROM inventory_pemakaian_bhp pb
        JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
        JOIN inventory_barang b ON pbd.barang_id = b.id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
        JOIN inventory_klinik k ON pb.klinik_id = k.id
        WHERE $where_clause
        ORDER BY pb.tanggal DESC, pb.created_at DESC
        LIMIT 500
    ";
    
    $stmt_out = $conn->prepare($query_out);
    if (!empty($params)) {
        $stmt_out->bind_param($types, ...$params);
    }
    $stmt_out->execute();
    $result_out = $stmt_out->get_result();
}

// Data for Modal Tambah
$seeded_barang_from_mirror = false;
try {
    $conn->query("
        INSERT INTO inventory_barang (odoo_product_id, kode_barang, nama_barang, satuan, stok_minimum, kategori)
        SELECT DISTINCT sm.odoo_product_id, sm.kode_barang, sm.kode_barang, 'Unit', 0, 'Odoo'
        FROM inventory_stock_mirror sm
        LEFT JOIN inventory_barang b ON b.odoo_product_id = sm.odoo_product_id
        WHERE sm.odoo_product_id IS NOT NULL AND sm.odoo_product_id <> ''
          AND b.id IS NULL
    ");
    $seeded_barang_from_mirror = ($conn->affected_rows > 0);
} catch (Exception $e) {
    $seeded_barang_from_mirror = false;
}

$barang_list = [];
$stmt_barang = $conn->query("
    SELECT
        b.id,
        b.odoo_product_id,
        b.nama_barang,
        COALESCE(uc.to_uom, b.satuan) AS satuan,
        COALESCE(NULLIF(uc.from_uom, ''), '') AS uom_odoo,
        COALESCE(uc.multiplier, 1) AS uom_ratio
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE b.odoo_product_id IS NOT NULL AND b.odoo_product_id <> ''
    ORDER BY b.nama_barang
");
while ($row_barang = $stmt_barang->fetch_assoc()) {
    $barang_list[] = $row_barang;
}

$hc_list = [];

$klinik_options = [];
$res_k = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik");
while ($res_k && ($row_k = $res_k->fetch_assoc())) {
    $klinik_options[] = $row_k;
}
$default_modal_klinik_id = $user_klinik_id ?: (isset($klinik_options[0]['id']) ? (int)$klinik_options[0]['id'] : null);

$petugas_by_klinik = [];
$res_p = $conn->query("SELECT id, klinik_id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id IS NOT NULL ORDER BY nama_lengkap ASC");
while ($res_p && ($rp = $res_p->fetch_assoc())) {
    $kid = (int)($rp['klinik_id'] ?? 0);
    if ($kid <= 0) continue;
    if (!isset($petugas_by_klinik[$kid])) $petugas_by_klinik[$kid] = [];
    $petugas_by_klinik[$kid][] = ['id' => (int)$rp['id'], 'nama' => (string)($rp['nama_lengkap'] ?? '')];
}

$available_klinik_map = [];
try {
    $res_av_k = $conn->query("
        SELECT k.id as klinik_id, b.id as barang_id, SUM(sm.qty) as total_qty_odoo,
               COALESCE(uc.multiplier, 1) as multiplier
        FROM inventory_klinik k
        JOIN inventory_stock_mirror sm ON sm.location_code = k.kode_klinik
        JOIN inventory_barang b ON b.odoo_product_id = sm.odoo_product_id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE k.status='active'
          AND k.kode_klinik IS NOT NULL AND k.kode_klinik <> ''
          AND sm.qty > 0
        GROUP BY k.id, b.id
    ");
    while ($res_av_k && ($r = $res_av_k->fetch_assoc())) {
        $kid = (int)$r['klinik_id'];
        $bid = (int)$r['barang_id'];
        
        // Simpan stok RAW (satuan terkecil dari Mirror)
        $qty_mirror = (float)$r['total_qty_odoo'];
        
        if (!isset($available_klinik_map[$kid])) $available_klinik_map[$kid] = [];
        $available_klinik_map[$kid][$bid] = $qty_mirror;
    }
} catch (Exception $e) {
    $available_klinik_map = [];
}

$available_hc_map = [];
try {
    $res_av_h = $conn->query("
        SELECT k.id as klinik_id, b.id as barang_id, SUM(sm.qty) as total_qty_mirror
        FROM inventory_klinik k
        JOIN inventory_stock_mirror sm ON sm.location_code = k.kode_homecare
        JOIN inventory_barang b ON b.odoo_product_id = sm.odoo_product_id
        WHERE k.status='active'
          AND k.kode_homecare IS NOT NULL AND k.kode_homecare <> ''
          AND sm.qty > 0
        GROUP BY k.id, b.id
    ");
    while ($res_av_h && ($r = $res_av_h->fetch_assoc())) {
        $kid = (int)$r['klinik_id'];
        $bid = (int)$r['barang_id'];
        
        // Simpan stok RAW
        $qty_mirror = (float)$r['total_qty_mirror'];

        if (!isset($available_hc_map[$kid])) $available_hc_map[$kid] = [];
        $available_hc_map[$kid][$bid] = $qty_mirror;
    }
} catch (Exception $e) {
    $available_hc_map = [];
}

$klinik_name = '';
if ($default_modal_klinik_id) {
    $stmt_klinik = $conn->prepare("SELECT nama_klinik FROM inventory_klinik WHERE id = ?");
    $stmt_klinik->bind_param("i", $default_modal_klinik_id);
    $stmt_klinik->execute();
    $result_klinik = $stmt_klinik->get_result();
    if ($row_klinik = $result_klinik->fetch_assoc()) {
        $klinik_name = $row_klinik['nama_klinik'];
    }
}
?>

<div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-clipboard-list me-2"></i><?= ($user_role === 'petugas_hc') ? 'BHP Saya' : 'Pemakaian BHP' ?>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active"><?= ($user_role === 'petugas_hc') ? 'BHP Saya' : 'Pemakaian BHP' ?></li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <button type="button" class="btn shadow-sm text-white px-4 me-2" style="background-color: #204EAB;" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="fas fa-plus me-2"></i>Tambah Pemakaian
            </button>
            <?php if (in_array($user_role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'], true)): ?>
            <button type="button" class="btn btn-success shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#modalUpload">
                <i class="fas fa-file-excel me-2"></i>Upload Excel
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-pills mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link rounded-pill py-2 px-4 me-2 <?= $active_tab == 'list' ? 'active-blue' : 'text-muted border' ?>" 
               href="index.php?page=pemakaian_bhp_list&tab=list">
                <i class="fas fa-list me-2"></i>Daftar Transaksi
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill py-2 px-4 <?= $active_tab == 'data_out' ? 'active-blue' : 'text-muted border' ?>" 
               href="index.php?page=pemakaian_bhp_list&tab=data_out">
                <i class="fas fa-file-export me-2"></i>Data Out (Detail Item)
            </a>
        </li>
    </ul>

    <!-- Filter Section -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form action="index.php" method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="pemakaian_bhp_list">
                <input type="hidden" name="tab" value="<?= $active_tab ?>">
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                    <a href="index.php?page=pemakaian_bhp_list&tab=<?= $active_tab ?>" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-undo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <style>
        .active-blue {
            background-color: #204EAB !important;
            color: white !important;
            box-shadow: 0 4px 6px rgba(32, 78, 171, 0.2);
        }
        .breadcrumb-item.active { color: #6c757d; }
        .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
    </style>

    <?php if ($active_tab == 'list'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" id="tablePemakaianBHP">
                    <thead class="table-light">
                        <tr>
                            <th>No. Pemakaian</th>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Klinik</th>
                            <th>Total Item</th>
                            <th>Dibuat Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['nomor_pemakaian']) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td>
                                <?php if ($row['jenis_pemakaian'] === 'klinik'): ?>
                                    <span class="badge bg-info">Pemakaian Klinik</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pemakaian HC</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['nama_klinik'] ?? '-') ?></td>
                            <td><?= $row['total_items'] ?> item</td>
                            <td><?= htmlspecialchars($row['created_by_name']) ?></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-info view-detail" data-id="<?= $row['id'] ?>" title="Detail">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php 
                                    $is_today = date('Y-m-d', strtotime($row['created_at'])) === date('Y-m-d');
                                    $is_creator = $row['created_by'] == $_SESSION['user_id'];
                                    ?>
                                    <?php if ($is_today && $is_creator): ?>
                                    <button class="btn btn-sm btn-warning edit-pemakaian" 
                                            data-id="<?= $row['id'] ?>" 
                                            title="Edit"
                                            data-bs-toggle="modal" data-bs-target="#modalEdit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($user_role === 'super_admin'): ?>
                                    <button class="btn btn-sm btn-danger delete-pemakaian" data-id="<?= $row['id'] ?>" data-nomor="<?= htmlspecialchars($row['nomor_pemakaian']) ?>" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: // TAB DATA OUT ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="d-flex justify-content-end p-3 bg-white border-bottom align-items-center">
                <?php
                // Build export URL with current filters
                $export_url = "api/export_odoo_bhp.php?tab=data_out";
                if (isset($_GET['start_date'])) $export_url .= "&start_date=" . urlencode($_GET['start_date']);
                if (isset($_GET['end_date'])) $export_url .= "&end_date=" . urlencode($_GET['end_date']);
                if (isset($_GET['klinik_id'])) $export_url .= "&klinik_id=" . urlencode($_GET['klinik_id']);
                ?>
                <a href="<?= $export_url ?>" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-file-excel me-2"></i>Export Odoo (.xlsx)
                </a>
            </div>
            <div class="table-responsive rounded-top scrollbar-custom">
                <table class="table table-spreadsheet datatable align-middle mb-0" id="tableDataOut">
                    <thead>
                        <tr>
                            <th width="120"><i class="far fa-calendar-alt me-1"></i> Tanggal</th>
                            <th><i class="fas fa-box me-1"></i> Item</th>
                            <th width="80" class="text-center">Qty (Btl)</th>
                            <th width="100">UoM</th>
                            <th width="100" class="text-center bg-light">Qty Odoo</th>
                            <th width="80" class="bg-light">UoM Odoo</th>
                            <th><i class="fas fa-info-circle me-1"></i> Status</th>
                            <th><i class="fas fa-user-md me-1"></i> PIC / Nakes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_out->num_rows == 0): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">Tidak ada data pemakaian ditemukan.</td></tr>
                        <?php else: ?>
                            <?php while ($row = $result_out->fetch_assoc()): 
                                $status_text = ($row['jenis_pemakaian'] === 'hc') ? 'Additional Used - From Tas HC' : 'Direct Used - On Site Klinik';
                                $pic_name = ($row['jenis_pemakaian'] === 'hc') ? $row['hc_name'] : $row['nama_klinik'];
                                $badge_class = ($row['jenis_pemakaian'] === 'hc') ? 'badge-hc' : 'badge-klinik';
                                
                                // Calculate Odoo Qty
                                $ratio = (float)($row['uom_ratio'] ?? 1);
                                $qty_odoo = $row['qty'] * $ratio;
                                $uom_odoo = $row['uom_odoo'] ?: $row['satuan'];
                            ?>
                            <tr>
                                <td class="text-muted small"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td>
                                    <div class="item-pill">
                                        <?= htmlspecialchars($row['nama_barang']) ?>
                                    </div>
                                </td>
                                <td class="text-center fw-bold text-primary"><?= $row['qty'] ?></td>
                                <td>
                                    <span class="uom-text">
                                          <?= htmlspecialchars($row['satuan']) ?>
                                    </span>
                                </td>
                                <td class="text-center fw-bold text-success bg-light"><?= number_format($qty_odoo, 0, ',', '.') ?></td>
                                <td class="text-muted small bg-light"><?= htmlspecialchars($uom_odoo) ?></td>
                                <td>
                                    <span class="status-pill <?= $badge_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="nakes-pill">
                                        <?= htmlspecialchars($pic_name) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
    /* Spreadsheet Style for Data Out */
    .table-spreadsheet {
        border-collapse: separate;
        border-spacing: 0;
    }

    .table-spreadsheet thead th {
        background-color: #f8f9fa !important;
        color: #444 !important;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 14px 15px;
        border: 1px solid #eef0f5;
        font-size: 0.8rem;
    }

    .table-spreadsheet tbody td {
        padding: 12px 15px;
        border-bottom: 1px solid #eef0f5;
        border-right: 1px solid #f8f9fa;
        background-color: #fff;
        font-size: 0.85rem;
    }

    .table-spreadsheet tbody tr:hover td {
        background-color: #f1f4f9;
        cursor: default;
    }

    .table-spreadsheet tbody tr:nth-child(even) td {
        background-color: #f8fbfa;
    }

    /* UI Pills */
    .item-pill {
        background: #f1f3f5;
        border-radius: 4px;
        padding: 4px 10px;
        display: inline-block;
        color: #495057;
        border: 1px solid #dee2e6;
    }

    .uom-text {
        color: #6c757d;
        font-weight: 500;
    }

    .status-pill {
        background: #ffffff;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        padding: 2px 10px;
        font-size: 0.75rem;
        color: #888;
        display: inline-block;
    }

    .status-pill.badge-hc {
        border-left: 3px solid #0dcaf0;
        color: #055160;
    }

    .status-pill.badge-klinik {
        border-left: 3px solid #204EAB;
        color: #0a2b6b;
    }

    .nakes-pill {
        background: #f1f3f5;
        border-radius: 4px;
        padding: 4px 10px;
        display: inline-block;
        color: #495057;
        font-weight: 500;
    }

    /* Custom Scrollbar */
    .scrollbar-custom::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    .scrollbar-custom::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    .scrollbar-custom::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }
    </style>
    <?php endif; ?>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3" style="background-color: #204EAB; color: white;">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-info-circle me-2"></i>Detail Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="modalDetailContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Pemakaian -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-labelledby="modalEditLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalEditLabel"><i class="fas fa-edit me-2"></i>Edit Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formEditPemakaianBHP" method="POST" action="actions/process_pemakaian_bhp.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body p-4 bg-light">
                    <div id="editLoadingSpinner" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 text-muted">Memuat data...</p>
                    </div>
                    <div id="editFormContent" style="display:none;">
                        <!-- Form Header Info -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="tanggal" id="editTanggal" class="form-control" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-tags text-primary me-1"></i>Jenis Pemakaian <span class="text-danger">*</span>
                                        </label>
                                        <select name="jenis_pemakaian" id="editJenisPemakaian" class="form-select" required <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>
                                            <option value="klinik">Pemakaian Klinik</option>
                                            <option value="hc">Pemakaian HC</option>
                                        </select>
                                        <?php if ($user_role === 'petugas_hc'): ?>
                                            <input type="hidden" name="jenis_pemakaian" value="hc">
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-hospital text-primary me-1"></i>Klinik
                                        </label>
                                        <input type="text" class="form-control bg-light" id="editKlinikName" value="" readonly>
                                        <input type="hidden" name="klinik_id" id="editKlinikId" value="">
                                    </div>
                                    <div class="col-md-3" id="editPetugasHcWrap" style="<?= ($user_role === 'petugas_hc') ? 'display:block;' : 'display:none;' ?>">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-user-nurse text-primary me-1"></i>Petugas HC <span class="text-danger">*</span>
                                        </label>
                                        <?php if ($user_role === 'petugas_hc'): ?>
                                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($_SESSION['nama_lengkap']) ?>" readonly>
                                            <input type="hidden" name="user_hc_id" id="editUserHcIdHidden" value="<?= (int)$_SESSION['user_id'] ?>">
                                        <?php else: ?>
                                            <select name="user_hc_id" id="editUserHcId" class="form-select">
                                                <option value="">- Pilih Petugas -</option>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-sticky-note text-primary me-1"></i>Catatan Transaksi
                                        </label>
                                        <textarea name="catatan_transaksi" id="editCatatan" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item Table -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-boxes me-2"></i>Daftar Item Barang</h6>
                                <button type="button" class="btn btn-success btn-sm" id="editAddRowBtn">
                                    <i class="fas fa-plus-circle me-1"></i> Tambah Item
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="editItemTable">
                                        <thead class="bg-light">
                                            <tr>
                                                <th width="40%" class="py-3 text-muted small fw-bold">Item Barang <span class="text-danger">*</span></th>
                                                <th width="15%" class="py-3 text-muted small fw-bold">Qty <span class="text-danger">*</span></th>
                                                <th width="15%" class="py-3 text-muted small fw-bold">UoM</th>
                                                <th width="20%" class="py-3 text-muted small fw-bold">Catatan Item</th>
                                                <th width="10%" class="text-center py-3 text-muted small fw-bold border-start">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="editItemTableBody">
                                            <!-- Rows populated by AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" id="editSubmitBtn" class="btn btn-primary px-5 shadow-sm rounded-pill py-2 fw-bold" style="display:none;">
                        <i class="fas fa-save me-2"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tambah Pemakaian -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-labelledby="modalTambahLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalTambahLabel"><i class="fas fa-plus-circle me-2"></i>Tambah Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formPemakaianBHP" method="POST" action="actions/process_pemakaian_bhp.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body p-4 bg-light">
                    <!-- Form Header Info -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-tags text-primary me-1"></i>Jenis Pemakaian <span class="text-danger">*</span>
                                    </label>
                                    <select name="jenis_pemakaian" id="modalJenisPemakaian" class="form-select" required <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>
                                        <option value="">-- Pilih --</option>
                                        <option value="klinik" <?= ($user_role !== 'petugas_hc') ? '' : '' ?>>Pemakaian Klinik</option>
                                        <option value="hc" <?= ($user_role === 'petugas_hc') ? 'selected' : '' ?>>Pemakaian HC</option>
                                    </select>
                                    <?php if ($user_role === 'petugas_hc'): ?>
                                        <input type="hidden" name="jenis_pemakaian" value="hc">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-hospital text-primary me-1"></i>Klinik
                                    </label>
                                    <?php if ($can_choose_klinik): ?>
                                        <select name="klinik_id" id="modalKlinikId" class="form-select" required>
                                            <?php foreach ($klinik_options as $k): ?>
                                                <option value="<?= (int)$k['id'] ?>" <?= ((int)$k['id'] === (int)$default_modal_klinik_id) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($k['nama_klinik']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($klinik_name) ?>" readonly>
                                        <input type="hidden" name="klinik_id" id="modalKlinikIdHidden" value="<?= (int)$user_klinik_id ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3" id="modalPetugasHcWrap" style="<?= ($user_role === 'petugas_hc') ? 'display:block;' : 'display:none;' ?>">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-user-nurse text-primary me-1"></i>Petugas HC <span class="text-danger">*</span>
                                    </label>
                                    <?php if ($user_role === 'petugas_hc'): ?>
                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($_SESSION['nama_lengkap']) ?>" readonly>
                                        <input type="hidden" name="user_hc_id" value="<?= (int)$_SESSION['user_id'] ?>">
                                    <?php else: ?>
                                        <select name="user_hc_id" id="modalUserHcId" class="form-select">
                                            <option value="">- Pilih Petugas -</option>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-sticky-note text-primary me-1"></i>Catatan Transaksi
                                    </label>
                                    <textarea name="catatan_transaksi" class="form-control" rows="2" placeholder="Catatan keseluruhan (opsional)"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-boxes me-2"></i>Daftar Item Barang</h6>
                            <button type="button" class="btn btn-success btn-sm" id="modalAddRowBtn">
                                <i class="fas fa-plus-circle me-1"></i> Tambah Item
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="modalItemTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="40%" class="py-3 text-muted small fw-bold">Item Barang <span class="text-danger">*</span></th>
                                            <th width="15%" class="py-3 text-muted small fw-bold">Qty <span class="text-danger">*</span></th>
                                            <th width="15%" class="py-3 text-muted small fw-bold">UoM</th>
                                            <th width="20%" class="py-3 text-muted small fw-bold">Catatan Item</th>
                                            <th width="10%" class="text-center py-3 text-muted small fw-bold border-start">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalItemTableBody">
                                        <tr class="modal-item-row">
                                            <td class="p-2">
                                                <select name="items[0][barang_id]" class="form-select form-select-sm modal-barang-select" required>
                                                    <option value="">-- Pilih Barang --</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <input type="number" name="items[0][qty]" class="form-control form-control-sm" min="1" placeholder="0" required>
                                            </td>
                                            <td class="p-2">
                                                <select name="items[0][uom_mode]" class="form-select form-select-sm modal-uom-select" required>
                                                    <option value="oper">-</option>
                                                </select>
                                                <input type="hidden" name="items[0][satuan]" class="modal-satuan-hidden">
                                            </td>
                                            <td class="p-2">
                                                <input type="text" name="items[0][catatan_item]" class="form-control form-control-sm" placeholder="Catatan">
                                            </td>
                                            <td class="text-center border-start">
                                                <button type="button" class="btn btn-sm btn-link text-danger modal-remove-row" disabled>
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-5 shadow-sm rounded-pill py-2 fw-bold">
                        <i class="fas fa-save me-2"></i>Simpan Pemakaian
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const PEMAKAIAN_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
window.__petugasByKlinik = <?= json_encode($petugas_by_klinik, JSON_UNESCAPED_UNICODE) ?>;

function renderPetugasOptions(selectEl, klinikId) {
    var opts = ['<option value="">- Pilih Petugas -</option>'];
    var list = window.__petugasByKlinik[String(klinikId)] || window.__petugasByKlinik[Number(klinikId)] || [];
    list.forEach(function(p) {
        var safeName = String(p.nama).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        opts.push('<option value="' + String(p.id) + '">' + safeName + '</option>');
    });
    $(selectEl).html(opts.join(''));
}

function togglePetugasHc(modePrefix) {
    var jenis = $('#' + modePrefix + 'JenisPemakaian').val();
    var klinikIdEl = $('#' + modePrefix + 'KlinikId');
    var klinikId;
    if (klinikIdEl.length) {
        klinikId = klinikIdEl.val();
    } else if ($('#modalKlinikIdHidden').length) {
        klinikId = $('#modalKlinikIdHidden').val();
    } else {
        klinikId = $('input[name="klinik_id"]').first().val();
    }
    var wrap = $('#' + modePrefix + 'PetugasHcWrap');
    var select = $('#' + modePrefix + 'UserHcId');
    if (jenis === 'hc') {
        wrap.show();
        renderPetugasOptions(select, klinikId);
        select.prop('required', true);
    } else {
        wrap.hide();
        select.prop('required', false);
        select.val('');
    }
}

$(document).on('change', '#modalJenisPemakaian, #modalKlinikId', function() {
    togglePetugasHc('modal');
});

$(document).on('change', '#editJenisPemakaian, #editKlinikId', function() {
    togglePetugasHc('edit');
});

$(document).on('shown.bs.modal', '#modalTambah', function() {
    togglePetugasHc('modal');
});
</script>

<!-- Modal Upload Excel -->
<div class="modal fade" id="modalUpload" tabindex="-1" aria-labelledby="modalUploadLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #28a745;">
                <h5 class="modal-title fw-bold text-white" id="modalUploadLabel"><i class="fas fa-file-excel me-2"></i>Upload Excel Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formUploadExcel" method="POST" action="actions/process_pemakaian_bhp_upload.php" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body p-4 bg-light">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">
                                    <i class="fas fa-file-excel text-success me-1"></i>Pilih File Excel <span class="text-danger">*</span>
                                </label>
                                <input type="file" name="excel_file" id="excelFile" class="form-control" accept=".xlsx" required>
                                <div class="form-text small">Format wajib: <b>.xlsx</b> (Maksimal 5MB).</div>
                            </div>

                            <div class="alert alert-info border-0 shadow-sm small py-3">
                                <h6 class="fw-bold mb-2"><i class="fas fa-info-circle me-1"></i>Petunjuk Format Baru (10 Kolom):</h6>
                                <ul class="mb-0 ps-3">
                                    <li>Gunakan <b>Template Baru</b> (xlsx) agar urutan kolom tepat (10 kolom).</li>
                                    <li><b>Format Tanggal:</b> dd Month yyyy, HH:mm (Contoh: 04 March 2026, 16:00).</li>
                                    <li><b>Atomic Transaction:</b> Jika ada 1 baris salah, seluruh file akan ditolak.</li>
                                    <li><b>Duplikasi:</b> Sistem mengecek kombinasi ID Pasien + Tanggal + Kode Barang.</li>
                                    <li>Validasi ketat terhadap Master Item, Satuan (UoM), Nakes, dan Cabang.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-3 d-flex justify-content-between align-items-center bg-white border-start border-primary border-4 rounded">
                            <div>
                                <h6 class="mb-1 fw-bold text-primary">Template Excel</h6>
                                <p class="small text-muted mb-0">Pastikan format file Anda sesuai dengan template kami.</p>
                            </div>
                            <a href="api/download_template_pemakaian.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                                <i class="fas fa-download me-1"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success px-5 shadow-sm rounded-pill py-2 fw-bold">
                        <i class="fas fa-upload me-2"></i>Upload & Proses
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const barangMaster = <?php
        $m = [];
        foreach ($barang_list as $b) {
            $m[(int)$b['id']] = [
                'name' => (string)$b['nama_barang'],
                'satuan' => (string)$b['satuan'],
                'uom_odoo' => (string)($b['uom_odoo'] ?? ''),
                'uom_ratio' => (float)($b['uom_ratio'] ?? 1)
            ];
        }
        echo json_encode($m, JSON_UNESCAPED_UNICODE);
    ?>;
    const availableKlinik = <?php echo json_encode($available_klinik_map, JSON_UNESCAPED_UNICODE); ?>;
    const availableHc = <?php echo json_encode($available_hc_map, JSON_UNESCAPED_UNICODE); ?>;

    function initBarangSelect2($select) {
        if (!$select || !$select.length) return;
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') return;
        
        // Re-init if needed or skip if already active
        if ($select.hasClass('select2-hidden-accessible')) return;

        const $modal = $select.closest('.modal');
        const opts = {
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '-- Pilih Barang --',
            allowClear: true
            // Removed minimumInputLength to show items immediately
        };
        
        if ($modal.length) {
            opts.dropdownParent = $modal;
        }
        
        $select.select2(opts);
    }

    function getAllowedIds(klinikId, jenis) {
        if (!klinikId || !jenis) return null;
        const kid = String(klinikId);
        if (jenis === 'hc') return availableHc[kid] || {};
        return availableKlinik[kid] || {};
    }

    function buildOptionsHtml(klinikId, jenis) {
        if (!jenis) return '<option value="">-- Pilih Jenis Pemakaian dahulu --</option>';
        if (!klinikId) return '<option value="">-- Pilih Klinik dahulu --</option>';
        const map = getAllowedIds(klinikId, jenis);
        const ids = Object.keys(map);
        if (!ids || ids.length === 0) return '<option value="">-- Tidak ada barang tersedia --</option>';
        const items = ids
            .map(id => ({ 
                id: String(id), 
                name: (barangMaster[String(id)] && barangMaster[String(id)].name) ? barangMaster[String(id)].name : String(id),
                rawQty: map[id] || 0
            }))
            .sort((a, b) => a.name.localeCompare(b.name));
        let html = '<option value="">-- Pilih Barang --</option>';
        items.forEach(it => {
            const master = barangMaster[it.id] || {};
            const satuan = master.satuan || '';
            const ratio = Number(master.uom_ratio) || 1;
            
            // Default: Satuan Operasional
            const displayQty = it.rawQty / ratio;
            
            const safeName = String(it.name).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const safeSatuan = String(satuan).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const qtyText = Number(displayQty).toLocaleString('id-ID');
            
            html += `<option value="${it.id}" data-satuan="${safeSatuan}" data-raw-qty="${it.rawQty}">${safeName} (Stok: ${qtyText})</option>`;
        });
        return html;
    }

    function fillSelectOptions($select, klinikId, jenis, keepValue = true) {
        const prev = keepValue ? $select.val() : '';
        $select.html(buildOptionsHtml(klinikId, jenis));
        if (keepValue && prev && $select.find(`option[value="${prev}"]`).length) {
            $select.val(prev);
        } else {
            $select.val('');
        }
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.trigger('change.select2');
        } else {
            // Trigger change to update UOM dropdown if needed
            $select.trigger('change');
        }
    }

    function refreshModalBarangOptions(keepValue = true) {
        const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int)$default_modal_klinik_id ?>';
        const jenis = $('#modalJenisPemakaian').val();
        $('.modal-barang-select').each(function() {
            const $s = $(this);
            fillSelectOptions($s, klinikId, jenis, keepValue);
            initBarangSelect2($s);
        });
    }

    function refreshEditBarangOptions(keepValue = true) {
        const klinikId = $('#editKlinikId').val();
        const jenis = $('#editJenisPemakaian').val();
        $('.edit-barang-select').each(function() {
            const $s = $(this);
            const $row = $s.closest('tr');
            const prevUom = $row.find('.edit-uom-select').val();
            
            fillSelectOptions($s, klinikId, jenis, keepValue);
            initBarangSelect2($s);
            
            if (prevUom) {
                $row.find('.edit-uom-select').val(prevUom).trigger('change');
            }
        });
    }

    // Initialize DataOut if present
    if ($('#tableDataOut').length && !$('#tableDataOut').hasClass('select2-hidden-accessible')) {
        // No explicit init needed if it has .datatable class, 
        // but we want to ensure it sorts by Tanggal (index 0)
        // Actually, the global one in footer.php will handle it with index 1.
        // Let's explicitly override if needed or just let it be.
    }

    // View detail
    $('.view-detail').on('click', function() {
        const id = $(this).data('id');
        $('#modalDetail').modal('show');
        $('#modalDetailContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>');

        $.ajax({
            url: 'api/get_pemakaian_bhp_detail.php',
            method: 'GET',
            data: { id: id },
            success: function(response) {
                $('#modalDetailContent').html(response);
            },
            error: function() {
                $('#modalDetailContent').html('<div class="p-3"><div class="alert alert-danger">Gagal memuat detail</div></div>');
            }
        });
    });

    // Delete pemakaian
    $('.delete-pemakaian').on('click', function() {
        const id = $(this).data('id');
        const nomor = $(this).data('nomor');

        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: `Anda akan menghapus data pemakaian ${nomor}. Stok akan dikembalikan otomatis.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Memproses...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'actions/process_pemakaian_bhp_delete.php',
                    method: 'POST',
                    data: { id: id, _csrf: PEMAKAIAN_CSRF },
                    success: function(response) {
                        try {
                            const res = JSON.parse(response);
                            if (res.success) {
                                Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                            } else {
                                Swal.fire('Gagal', res.message, 'error');
                            }
                        } catch (e) {
                            Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Gagal menghubungi server', 'error');
                    }
                });
            }
        });
        });
    
    // --- MODAL TAMBAH LOGIC ---
    let modalRowIndex = 1;

    // Handle UOM selection change
    $(document).on('change', '.modal-uom-select, .edit-uom-select', function() {
        const row = $(this).closest('tr');
        const $barangSelect = row.find('.modal-barang-select, .edit-barang-select');
        const barangId = $barangSelect.val();
        if (!barangId) return;

        const master = barangMaster[barangId] || {};
        const ratio = Number(master.uom_ratio) || 1;
        const uomMode = $(this).val(); // 'oper' or 'odoo'
        
        // Ambil raw qty dari option yang terpilih
        const $selectedOption = $barangSelect.find('option:selected');
        const rawQty = Number($selectedOption.attr('data-raw-qty')) || 0;
        
        let displayQty = 0;
        if (uomMode === 'odoo') {
            displayQty = rawQty; // Satuan kecil (mL/pcs)
        } else {
            displayQty = rawQty / ratio; // Satuan besar (Btl/Box)
        }
        
        // Update teks di option
        const qtyText = Number(displayQty).toLocaleString('id-ID');
        const baseName = master.name || barangId;
        const newText = `${baseName} (Stok: ${qtyText})`;
        
        $selectedOption.text(newText);
        
        // Force Select2 to refresh its displayed label
        if ($barangSelect.hasClass('select2-hidden-accessible')) {
            // Select2 refresh label hack: trigger change and then manually update container if needed
            $barangSelect.trigger('change.select2');
            
            // Additional fallback: update the select2 selection text directly
            const $container = $barangSelect.next('.select2-container');
            $container.find('.select2-selection__rendered').text(newText).attr('title', newText);
        }
    });

    // Handle barang selection in modal
    $(document).on('change', '.modal-barang-select', function() {
        const id = $(this).val();
        const row = $(this).closest('tr');
        const satuan = (id && barangMaster[id] && barangMaster[id].satuan) ? barangMaster[id].satuan : '';
        const uomOdoo = (id && barangMaster[id] && barangMaster[id].uom_odoo) ? barangMaster[id].uom_odoo : '';
        const ratio = (id && barangMaster[id] && barangMaster[id].uom_ratio) ? Number(barangMaster[id].uom_ratio) : 1;
        const $uomSelect = row.find('.modal-uom-select');
        const $hiddenSatuan = row.find('.modal-satuan-hidden');

        $hiddenSatuan.val(satuan);
        let opts = '';
        if (uomOdoo && ratio && ratio !== 1 && String(uomOdoo).toLowerCase() !== String(satuan).toLowerCase()) {
            opts += `<option value="oper">${satuan}</option>`;
            opts += `<option value="odoo">${uomOdoo}</option>`;
            $uomSelect.prop('disabled', false);
        } else {
            opts += `<option value="oper">${satuan || '-'}</option>`;
            $uomSelect.prop('disabled', true);
        }
        $uomSelect.html(opts).val('oper');
    });

    // Add row in modal
    $('#modalAddRowBtn').on('click', function() {
        const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int)$default_modal_klinik_id ?>';
        const jenis = $('#modalJenisPemakaian').val();
        const optionsHtml = buildOptionsHtml(klinikId, jenis);
        const newRow = `
            <tr class="modal-item-row">
                <td class="p-2">
                    <select name="items[${modalRowIndex}][barang_id]" class="form-select form-select-sm modal-barang-select" required>
                        ${optionsHtml}
                    </select>
                </td>
                <td class="p-2">
                    <input type="number" name="items[${modalRowIndex}][qty]" class="form-control form-control-sm" min="1" placeholder="0" required>
                </td>
                <td class="p-2">
                    <select name="items[${modalRowIndex}][uom_mode]" class="form-select form-select-sm modal-uom-select" required>
                        <option value="oper">-</option>
                    </select>
                    <input type="hidden" name="items[${modalRowIndex}][satuan]" class="modal-satuan-hidden">
                </td>
                <td class="p-2">
                    <input type="text" name="items[${modalRowIndex}][catatan_item]" class="form-control form-control-sm" placeholder="Catatan">
                </td>
                <td class="text-center border-start">
                    <button type="button" class="btn btn-sm btn-link text-danger modal-remove-row">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
        $('#modalItemTableBody').append(newRow);
        initBarangSelect2($('#modalItemTableBody tr:last').find('.modal-barang-select'));
        modalRowIndex++;
        updateModalRemoveButtons();
    });

    // Remove row in modal
    $(document).on('click', '.modal-remove-row', function() {
        $(this).closest('tr').remove();
        updateModalRemoveButtons();
    });

    function updateModalRemoveButtons() {
        const rowCount = $('.modal-item-row').length;
        $('.modal-remove-row').prop('disabled', rowCount === 1);
    }

    // Form validation in modal
    $('#formPemakaianBHP').on('submit', function(e) {
        e.preventDefault();
        const rowCount = $('.modal-item-row').length;
        if (rowCount === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Perhatian',
                text: 'Minimal harus ada 1 item barang'
            });
            return false;
        }
        
        const $btn = $(this).find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire('Berhasil', res.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                    $btn.prop('disabled', false).html(oldHtml);
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                $btn.prop('disabled', false).html(oldHtml);
            }
        });
        return false;
    });

    // --- MODAL EDIT LOGIC ---
    let editRowIndex = 0;

    function makeEditRow(idx, barangId, qty, satuan, catatan, uomMode) {
        return `
            <tr class="edit-item-row">
                <td class="p-2">
                    <select name="items[${idx}][barang_id]" class="form-select form-select-sm edit-barang-select" required>
                        <option value="">-- Pilih Barang --</option>
                    </select>
                </td>
                <td class="p-2">
                    <input type="number" name="items[${idx}][qty]" class="form-control form-control-sm" min="1" value="${qty || ''}" placeholder="0" required>
                </td>
                <td class="p-2">
                    <select name="items[${idx}][uom_mode]" class="form-select form-select-sm edit-uom-select" required>
                        <option value="oper">-</option>
                    </select>
                    <input type="hidden" name="items[${idx}][satuan]" class="edit-satuan-hidden" value="${satuan || ''}">
                </td>
                <td class="p-2">
                    <input type="text" name="items[${idx}][catatan_item]" class="form-control form-control-sm" value="${catatan || ''}" placeholder="Catatan">
                </td>
                <td class="text-center border-start">
                    <button type="button" class="btn btn-sm btn-link text-danger edit-remove-row">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
    }

    function updateEditRemoveButtons() {
        const count = $('.edit-item-row').length;
        $('.edit-remove-row').prop('disabled', count === 1);
    }

    // Open edit modal - load data via AJAX
    $('.edit-pemakaian').on('click', function() {
        const id = $(this).data('id');
        $('#editId').val(id);
        $('#editLoadingSpinner').show();
        $('#editFormContent').hide();
        $('#editSubmitBtn').hide();
        $('#editItemTableBody').empty();

        $.ajax({
            url: 'api/get_pemakaian_bhp_edit.php',
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    $('#editLoadingSpinner').hide();
                    Swal.fire('Gagal', res.message, 'error');
                    return;
                }
                const h = res.header;
                $('#editTanggal').val(h.tanggal);
                $('#editKlinikId').val(h.klinik_id);
                $('#editKlinikName').val(h.nama_klinik || '');
                $('#editJenisPemakaian').val(h.jenis_pemakaian).trigger('change');
                togglePetugasHc('edit');
                if (h.user_hc_id) {
                    $('#editUserHcId').val(String(h.user_hc_id));
                }
                $('#editCatatan').val(h.catatan_transaksi || '');

                editRowIndex = 0;
                res.details.forEach(function(d) {
                    const rowHtml = makeEditRow(editRowIndex, d.barang_id, d.qty, d.satuan, d.catatan_item, d.uom_mode);
                    $('#editItemTableBody').append(rowHtml);
                    const $row = $('#editItemTableBody tr:last');
                    const $barangSelect = $row.find('.edit-barang-select');
                    
                    fillSelectOptions($barangSelect, h.klinik_id, h.jenis_pemakaian, false);
                    $barangSelect.val(String(d.barang_id)).trigger('change');
                    
                    // Initialize Select2 for this row
                    initBarangSelect2($barangSelect);
                    
                    // Set UOM Mode after barang change (since barang change resets UOM options)
                    if (d.uom_mode) {
                        $row.find('.edit-uom-select').val(d.uom_mode).trigger('change');
                    }
                    
                    editRowIndex++;
                });

                updateEditRemoveButtons();
                $('#editLoadingSpinner').hide();
                $('#editFormContent').show();
                $('#editSubmitBtn').show();
            },
            error: function() {
                $('#editLoadingSpinner').hide();
                Swal.fire('Error', 'Gagal memuat data', 'error');
            }
        });
    });

    // Barang change in edit modal
    $(document).on('change', '.edit-barang-select', function() {
        const id = $(this).val();
        const row = $(this).closest('tr');
        const master = barangMaster[id] || {};
        const satuan = master.satuan || '';
        const uomOdoo = master.uom_odoo || '';
        const ratio = Number(master.uom_ratio) || 1;
        const $uomSelect = row.find('.edit-uom-select');
        const $hiddenSatuan = row.find('.edit-satuan-hidden');

        $hiddenSatuan.val(satuan);
        let opts = '';
        if (uomOdoo && ratio && ratio !== 1 && String(uomOdoo).toLowerCase() !== String(satuan).toLowerCase()) {
            opts += `<option value="oper">${satuan}</option>`;
            opts += `<option value="odoo">${uomOdoo}</option>`;
            $uomSelect.prop('disabled', false);
        } else {
            opts += `<option value="oper">${satuan || '-'}</option>`;
            $uomSelect.prop('disabled', true);
        }
        $uomSelect.html(opts).val('oper');
        $uomSelect.trigger('change'); // trigger stock label update
    });

    // Add row in edit modal
    $('#editAddRowBtn').on('click', function() {
        const rowHtml = makeEditRow(editRowIndex, null, '', '', '', 'oper');
        $('#editItemTableBody').append(rowHtml);
        const $sel = $('#editItemTableBody tr:last').find('.edit-barang-select');
        fillSelectOptions($sel, $('#editKlinikId').val(), $('#editJenisPemakaian').val(), false);
        initBarangSelect2($sel);
        editRowIndex++;
        updateEditRemoveButtons();
    });

    // Remove row in edit modal
    $(document).on('click', '.edit-remove-row', function() {
        $(this).closest('tr').remove();
        updateEditRemoveButtons();
    });

    // Validate edit form on submit
    $('#formEditPemakaianBHP').on('submit', function(e) {
        e.preventDefault();
        if ($('.edit-item-row').length === 0) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Minimal harus ada 1 item barang' });
            return false;
        }
        
        const $btn = $(this).find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire('Berhasil', res.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                    $btn.prop('disabled', false).html(oldHtml);
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                $btn.prop('disabled', false).html(oldHtml);
            }
        });
        return false;
    });

    $('#modalJenisPemakaian').on('change', function() {
        refreshModalBarangOptions(false);
    });
    $('#editJenisPemakaian').on('change', function() {
        refreshEditBarangOptions(false);
    });
    if ($('#modalKlinikId').length) {
        $('#modalKlinikId').on('change', function() {
            refreshModalBarangOptions(false);
        });
    }
    refreshModalBarangOptions(false);

    // --- MODAL UPLOAD LOGIC ---
    $('#formUploadExcel').on('submit', function(e) {
        const fileInput = $('#excelFile')[0];
        
        if (!fileInput.files.length) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Perhatian',
                text: 'Silakan pilih file Excel terlebih dahulu'
            });
            return false;
        }

        const file = fileInput.files[0];
        const maxSize = 5 * 1024 * 1024; // 5MB

        if (file.size > maxSize) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'File Terlalu Besar',
                text: 'Ukuran file maksimal 5MB'
            });
            return false;
        }

        // Show loading
        Swal.fire({
            title: 'Memproses...',
            text: 'Mohon tunggu, file sedang diproses',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    });
});
</script>

<!-- SheetJS for .xlsx Export -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>

<script>
function exportToOdoo() {
    const table = document.getElementById("tableDataOut");
    const rows = table.querySelectorAll("tbody tr");
    
    // Check if table is empty
    if (rows.length === 1 && rows[0].innerText.includes("Tidak ada data")) {
        Swal.fire('Info', 'Tidak ada data untuk diekspor', 'info');
        return;
    }

    // Prepare Odoo Data Array
    // Format: Product, Quantity, Unit, Source Location, Date, Internal Reference
    const data = [
        ["Product", "Quantity", "Unit of Measure", "Source Location", "Date", "Internal Reference", "Type"]
    ];

    rows.forEach(row => {
        const date = row.cells[0].innerText.trim();
        const itemName = row.cells[1].innerText.trim();
        const qtyText = row.cells[2].innerText.trim();
        const uomEl = row.cells[3].querySelector('.uom-text');
        const uomShown = row.cells[3].innerText.trim();
        const ratioText = uomEl ? (uomEl.getAttribute('data-uom-ratio') || '1') : '1';
        const ratio = parseFloat(String(ratioText).replace(',', '.')) || 1;
        const uomOdoo = uomEl ? (uomEl.getAttribute('data-uom-odoo') || '') : '';
        const status = row.cells[4].innerText.trim();
        const location = row.cells[5].innerText.trim();

        const qtyShown = parseFloat(String(qtyText).replace(',', '.')) || 0;
        let qtyOdoo = qtyShown;
        if (ratio > 0.0000001) qtyOdoo = qtyShown * ratio;
        qtyOdoo = Math.round(qtyOdoo * 1000000) / 1000000;
        const qtyOut = (String(qtyOdoo).includes('.') ? qtyOdoo.toString().replace(/\.?0+$/, '') : String(qtyOdoo));
        
        // Find correct reference
        const reference = "SCRAP-" + date.replace(/\//g, '') + "-" + itemName.substring(0, 3).toUpperCase();

        data.push([
            itemName,
            qtyOut,
            (uomOdoo || uomShown),
            location,
            date,
            reference,
            status
        ]);
    });

    // Create Workbook
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);

    // Set Column Widths
    const wscols = [
        {wch: 30}, // Product
        {wch: 10}, // Qty
        {wch: 15}, // UoM
        {wch: 25}, // Location
        {wch: 15}, // Date
        {wch: 25}, // Reference
        {wch: 30}  // Type
    ];
    ws['!cols'] = wscols;

    XLSX.utils.book_append_sheet(wb, ws, "Odoo_Import");

    // Generate Filename based on filters
    let startDate = "<?= $start_date ?>";
    let endDate = "<?= $end_date ?>";
    let filename = "Pemakaian_BHP_Odoo";
    if (startDate || endDate) {
        filename += "_" + (startDate || 'Any') + "_to_" + (endDate || 'Any');
    }
    filename += ".xlsx";

    // Download
    XLSX.writeFile(wb, filename);
}

// Keep original function for generic use if needed
function exportTableToExcel(tableID, filename = 'Data_Export') {
    const table = document.getElementById(tableID);
    const wb = XLSX.utils.table_to_book(table);
    XLSX.writeFile(wb, filename + ".xlsx");
}
</script>
