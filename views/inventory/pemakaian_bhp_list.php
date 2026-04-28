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

    // Ensure status enum has all required values
    require_once __DIR__ . '/../../config/database.php';
    ensure_enum_value($conn, 'inventory_pemakaian_bhp', 'status', 'pending_add');
} catch (Exception $e) {
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'list';

// Get filters (Default to last 7 days)
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$filter_q = trim((string) ($_GET['q'] ?? ''));

// Build query based on role for LIST tab
$where_clause = "1=1";
$params = [];
$types = "";
$pending_verifikasi = [];

if ($user_role === 'admin_klinik' && $user_klinik_id) {
    $where_clause .= " AND pb.klinik_id = ?";
    $params[] = $user_klinik_id;
    $types .= "i";
} elseif ($user_role === 'spv_klinik' && $user_klinik_id) {
    $where_clause .= " AND pb.klinik_id = ?";
    $params[] = $user_klinik_id;
    $types .= "i";
}

if ($user_role === 'petugas_hc') {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $where_clause .= " AND pb.user_hc_id = ?";
    $params[] = $uid;
    $types .= "i";
}

if (!empty($start_date)) {
    $where_clause .= " AND pb.created_at >= ?";
    $params[] = $start_date . ' 00:00:00';
    $types .= "s";
}
if (!empty($end_date)) {
    $where_clause .= " AND pb.created_at <= ?";
    $params[] = $end_date . ' 23:59:59';
    $types .= "s";
}

// Base filters (Dates, Roles)
$base_where = $where_clause;
$base_params = $params;
$base_types = $types;

// Search filter
$search_where_list = "";
$search_where_out = "";
$search_params = [];
$search_types = "";

if ($filter_q !== '') {
    $q_param = "%$filter_q%";
    // We need 5 params now: nomor, klinik, created_by, hc_name, item_name/code
    $search_params = [$q_param, $q_param, $q_param, $q_param, $q_param, $q_param];
    $search_types = "ssssss";

    $search_where_list = " AND (pb.nomor_pemakaian LIKE ? OR k.nama_klinik LIKE ? OR u_created.nama_lengkap LIKE ? OR u_hc.nama_lengkap LIKE ? OR EXISTS (SELECT 1 FROM inventory_pemakaian_bhp_detail pbd2 JOIN inventory_barang b2 ON pbd2.barang_id = b2.id WHERE pbd2.pemakaian_bhp_id = pb.id AND (b2.nama_barang LIKE ? OR b2.kode_barang LIKE ?)))";
    $search_where_out = " AND (pb.nomor_pemakaian LIKE ? OR k.nama_klinik LIKE ? OR u_created.nama_lengkap LIKE ? OR u_hc.nama_lengkap LIKE ? OR b.nama_barang LIKE ? OR b.kode_barang LIKE ?)";
}

if ($active_tab === 'data_out') {
    // Logic for data_out view remains here, but export logic moved to api/export_odoo_bhp.php
}

if ($active_tab == 'list') {
    // 1. Get total count for pagination
    $count_query = "SELECT COUNT(*) as cnt 
                    FROM inventory_pemakaian_bhp pb 
                    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
                    LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
                    LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
                    WHERE $base_where $search_where_list AND pb.is_auto = 0";

    $stmt_count = $conn->prepare($count_query);
    $all_params = array_merge($base_params, $search_params);
    $all_types = $base_types . $search_types;
    if (!empty($all_params)) {
        $stmt_count->bind_param($all_types, ...$all_params);
    }
    $stmt_count->execute();
    $total_all = (int) ($stmt_count->get_result()->fetch_assoc()['cnt'] ?? 0);

    $items_per_page = 10;
    $total_pages = ceil($total_all / $items_per_page);
    $current_page = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($current_page - 1) * $items_per_page;

    $query = "
        SELECT 
            pb.*,
            k.nama_klinik,
            u_created.nama_lengkap as created_by_name,
            (SELECT COUNT(*) FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = pb.id) as total_items_detail
        FROM inventory_pemakaian_bhp pb
        LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
        LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
        LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
        WHERE $base_where $search_where_list AND pb.is_auto = 0
        ORDER BY pb.created_at DESC, pb.nomor_pemakaian DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    $all_params_p = array_merge($base_params, $search_params, [$items_per_page, $offset]);
    $all_types_p = $base_types . $search_types . "ii";
    $stmt->bind_param($all_types_p, ...$all_params_p);
    $stmt->execute();
    $result = $stmt->get_result();

    $q_pending = "
        SELECT pb.tanggal AS tgl, COUNT(*) AS cnt
        FROM inventory_pemakaian_bhp pb
        LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
        LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
        LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
        WHERE $base_where $search_where_list
          AND pb.status IN ('pending_edit', 'pending_delete', 'pending_approval_spv', 'pending_add')
          AND pb.is_auto = 0
        GROUP BY pb.tanggal
        ORDER BY tgl DESC
        LIMIT 30
    ";
    $stmt_pending = $conn->prepare($q_pending);
    $all_params_pending = array_merge($base_params, $search_params);
    $all_types_pending = $base_types . $search_types;
    if (!empty($all_params_pending)) {
        $stmt_pending->bind_param($all_types_pending, ...$all_params_pending);
    }
    $stmt_pending->execute();
    $res_pending = $stmt_pending->get_result();
    while ($rp = $res_pending->fetch_assoc()) {
        $pending_verifikasi[] = [
            'tgl' => (string) ($rp['tgl'] ?? ''),
            'cnt' => (int) ($rp['cnt'] ?? 0)
        ];
    }
} elseif ($active_tab == 'data_out') {
    // 1. Get total count for pagination
    $count_query_out = "SELECT COUNT(*) as cnt 
                        FROM inventory_pemakaian_bhp pb 
                        JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
                        JOIN inventory_barang b ON pbd.barang_id = b.id
                        LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
                        LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
                        LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
                        WHERE $base_where $search_where_out AND pb.is_auto = 0 AND pb.status = 'active'";

    $stmt_count_out = $conn->prepare($count_query_out);
    $all_params_out_c = array_merge($base_params, $search_params);
    $all_types_out_c = $base_types . $search_types;
    if (!empty($all_params_out_c)) {
        $stmt_count_out->bind_param($all_types_out_c, ...$all_params_out_c);
    }
    $stmt_count_out->execute();
    $total_all = (int) ($stmt_count_out->get_result()->fetch_assoc()['cnt'] ?? 0);

    $items_per_page = 100; // More items for detail view
    $total_pages = ceil($total_all / $items_per_page);
    $current_page = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($current_page - 1) * $items_per_page;

    $query_out = "
        SELECT 
            pb.tanggal, 
            pb.created_at,
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
        LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
        WHERE $base_where $search_where_out AND pb.is_auto = 0 AND pb.status = 'active'
        ORDER BY pb.created_at DESC, pb.nomor_pemakaian DESC
        LIMIT ? OFFSET ?
    ";

    $stmt_out = $conn->prepare($query_out);
    $all_params_out = array_merge($base_params, $search_params, [$items_per_page, $offset]);
    $all_types_out = $base_types . $search_types . "ii";
    $stmt_out->bind_param($all_types_out, ...$all_params_out);
    $stmt_out->execute();
    $result_out = $stmt_out->get_result();
}

// Get missed upload dates (Auto exist but Manual does not)
$missed_uploads = [];
$q_missed = "
    SELECT 
        DATE(pb.tanggal) as tgl,
        pb.klinik_id,
        pb.jenis_pemakaian,
        k.nama_klinik
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_klinik k ON pb.klinik_id = k.id
    LEFT JOIN inventory_users u_created ON pb.created_by = u_created.id
    LEFT JOIN inventory_users u_hc ON pb.user_hc_id = u_hc.id
    WHERE $base_where $search_where_list
    GROUP BY DATE(pb.tanggal), pb.klinik_id, pb.jenis_pemakaian
    HAVING SUM(pb.is_auto = 1) > 0
    ORDER BY tgl DESC, k.nama_klinik ASC
    LIMIT 100
";
$stmt_missed = $conn->prepare($q_missed);
$all_params_missed = array_merge($base_params, $search_params);
$all_types_missed = $base_types . $search_types;
if (!empty($all_params_missed)) {
    $stmt_missed->bind_param($all_types_missed, ...$all_params_missed);
}
$stmt_missed->execute();
$res_missed = $stmt_missed->get_result();
while ($rm = $res_missed->fetch_assoc()) {
    $missed_uploads[] = $rm;
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

require_once __DIR__ . '/../../lib/stock.php';

$hc_list = [];

$klinik_options = [];
$res_k = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik");
while ($res_k && ($row_k = $res_k->fetch_assoc())) {
    $klinik_options[] = $row_k;
}
$default_modal_klinik_id = $user_klinik_id ?: (isset($klinik_options[0]['id']) ? (int) $klinik_options[0]['id'] : null);

$petugas_by_klinik = [];
$res_p = $conn->query("SELECT id, klinik_id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id IS NOT NULL ORDER BY nama_lengkap ASC");
while ($res_p && ($rp = $res_p->fetch_assoc())) {
    $kid = (int) ($rp['klinik_id'] ?? 0);
    if ($kid <= 0)
        continue;
    if (!isset($petugas_by_klinik[$kid]))
        $petugas_by_klinik[$kid] = [];
    $petugas_by_klinik[$kid][] = ['id' => (int) $rp['id'], 'nama' => (string) ($rp['nama_lengkap'] ?? '')];
}

// Mass stock calculation optimized for performance
$available_klinik_map = [];
$available_hc_map = [];

$available_hc_user_map = [];
try {
    $res_av_hu = $conn->query("
        SELECT st.user_id, st.barang_id, st.qty, COALESCE(c.multiplier, 1) as multiplier
        FROM inventory_stok_tas_hc st
        JOIN inventory_barang b ON st.barang_id = b.id
        LEFT JOIN inventory_barang_uom_conversion c ON b.kode_barang = c.kode_barang
        WHERE st.qty > 0
    ");
    while ($res_av_hu && ($r = $res_av_hu->fetch_assoc())) {
        $uid = (int) $r['user_id'];
        $bid = (int) $r['barang_id'];
        $mult = (float) ($r['multiplier'] ?? 1);
        // Store in RAW (Odoo unit) for JS consistency
        $qty_raw = (float) $r['qty'] * $mult;
        if (!isset($available_hc_user_map[$uid]))
            $available_hc_user_map[$uid] = [];
        $available_hc_user_map[$uid][$bid] = $qty_raw;
    }
} catch (Exception $e) {
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
            <i
                class="fas fa-clipboard-list me-2"></i><?= ($user_role === 'petugas_hc') ? 'BHP Saya' : 'Pemakaian BHP' ?>
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard"
                        class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active"><?= ($user_role === 'petugas_hc') ? 'BHP Saya' : 'Pemakaian BHP' ?>
                </li>
            </ol>
        </nav>
    </div>
    <div class="col-auto">
        <button type="button" class="btn shadow-sm text-white px-4 me-2" style="background-color: #204EAB;"
            data-bs-toggle="modal" data-bs-target="#modalTambah">
            <i class="fas fa-plus me-2"></i>Tambah Pemakaian
        </button>
        <?php if (in_array($user_role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'], true)): ?>
            <button type="button" class="btn btn-success shadow-sm px-4" data-bs-toggle="modal"
                data-bs-target="#modalUpload">
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

<?php if ($active_tab === 'list' && !empty($pending_verifikasi)): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-3" role="alert">
        <div class="d-flex align-items-start">
            <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
            <div class="small">
                <div class="fw-bold mb-1">Ada Pemakaian BHP yang belum diverifikasi/di-approve (Pending).</div>
                <div class="text-muted">
                    <?php
                    $parts = [];
                    foreach ($pending_verifikasi as $pv) {
                        $tgl = $pv['tgl'] ? date('d/m/Y', strtotime($pv['tgl'])) : '-';
                        $parts[] = $tgl . ' (' . (int) $pv['cnt'] . ')';
                    }
                    echo htmlspecialchars(implode(', ', $parts));
                    ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($missed_uploads)): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-3" role="alert">
        <div class="d-flex align-items-start">
            <i class="fas fa-exclamation-circle me-2 mt-1"></i>
            <div class="small">
                <div class="fw-bold mb-1">Peringatan: BHP pada tanggal berikut belum diupload/input manual:</div>
                <div class="text-muted">
                    <ul class="mb-0 ps-3 mt-1">
                        <?php foreach ($missed_uploads as $mu):
                            $tgl = date('d/m/Y', strtotime($mu['tgl']));
                            $klinik = htmlspecialchars($mu['nama_klinik'] ?? '-');
                            $jenis = ($mu['jenis_pemakaian'] === 'hc') ? 'HC' : 'Klinik';
                            ?>
                            <li><?= $tgl ?> - <strong><?= $klinik ?></strong> (<?= $jenis ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
                <a href="index.php?page=pemakaian_bhp_list&tab=<?= $active_tab ?>"
                    class="btn btn-outline-secondary px-4">
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

    .breadcrumb-item.active {
        color: #6c757d;
    }

    .breadcrumb-item+.breadcrumb-item::before {
        content: "/";
    }

    .jenis-segmented {
        background: #f1f3f5;
        border-radius: 12px;
        padding: 4px;
        display: flex;
        gap: 4px;
        width: 100%;
    }

    .jenis-segmented .jenis-segment-btn {
        flex: 1 1 0;
        border: 0;
        background: transparent;
        border-radius: 10px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 0.9rem;
        color: #6c757d;
    }

    .jenis-segmented .jenis-segment-btn.active {
        background: #ffffff;
        color: #204EAB;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .jenis-segmented .jenis-segment-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .grayscale {
        filter: grayscale(1);
    }

    /* CIRCULAR PAGINATION STYLING */
    .pagination-circular .page-item {
        margin: 0 4px;
    }

    .pagination-circular .page-link {
        border-radius: 50% !important;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-weight: 500;
        transition: all 0.2s ease;
        background: #fff;
    }

    .pagination-circular .page-link:hover {
        background-color: #f8fafc;
        color: #204EAB;
        border-color: #204EAB;
    }

    .pagination-circular .page-item.active .page-link {
        background-color: #eff6ff !important;
        color: #204EAB !important;
        border-color: #bfdbfe !important;
        font-weight: 700;
    }

    .pagination-circular .page-item.disabled .page-link {
        background-color: #fff;
        color: #cbd5e1;
        border-color: #f1f5f9;
        opacity: 0.6;
    }
</style>

<?php if ($active_tab == 'list'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-end align-items-center mb-3">
                <div style="width: 300px;">
                    <form method="GET" action="index.php">
                        <input type="hidden" name="page" value="pemakaian_bhp_list">
                        <input type="hidden" name="tab" value="list">
                        <?php foreach ($_GET as $key => $val):
                            if (!in_array($key, ['q', 'p', 'page', 'tab'])): ?>
                                <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>">
                            <?php endif; endforeach; ?>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0"><i
                                    class="fas fa-search text-muted"></i></span>
                            <input type="text" name="q" class="form-control border-start-0 ps-0"
                                placeholder="Cari No. Pemakaian / Item" value="<?= htmlspecialchars($filter_q) ?>"
                                style="border-radius: 0 8px 8px 0;">
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="tablePemakaianBHP" data-order-col="5">
                    <thead class="table-light">
                        <tr>
                            <th>No. Pemakaian</th>
                            <th>Klinik</th>
                            <th>Jenis</th>
                            <th>Tanggal Pemakaian BHP</th>
                            <th>Total Item</th>
                            <th>Tanggal Input</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()):
                            $status = $row['status'] ?? 'active';
                            $row_class = ($status === 'rejected') ? 'table-secondary opacity-75' : '';

                            // Hitung total item (dari detail DB atau dari JSON pending_data)
                            $total_items = (int) ($row['total_items_detail'] ?? 0);
                            if ($status === 'pending_add' && !empty($row['pending_data'])) {
                                $p_data = json_decode($row['pending_data'], true);
                                if (isset($p_data['items']) && is_array($p_data['items'])) {
                                    $total_items = count($p_data['items']);
                                }
                            }
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td>
                                    <div
                                        class="fw-bold <?= ($status === 'rejected') ? 'text-decoration-line-through text-muted' : '' ?>">
                                        <?= htmlspecialchars($row['nomor_pemakaian']) ?>
                                    </div>
                                    <?php if ($status === 'rejected'): ?>
                                        <span class="badge bg-secondary small" style="font-size: 0.6rem;">REJECTED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['nama_klinik'] ?? '-') ?></td>
                                <td>
                                    <?php if (stripos($row['jenis_pemakaian'], 'hc') !== false): ?>
                                        <span class="badge bg-warning <?= ($status === 'rejected') ? 'grayscale' : '' ?>">HC</span>
                                    <?php else: ?>
                                        <span class="badge bg-info <?= ($status === 'rejected') ? 'grayscale' : '' ?>">Klinik</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td><?= $total_items ?> item</td>
                                <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-info view-detail" data-id="<?= $row['id'] ?>"
                                            title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <?php
                                        $created_date = date('Y-m-d', strtotime($row['created_at']));
                                        $today = date('Y-m-d');
                                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                                        $two_days_ago = date('Y-m-d', strtotime('-2 days'));

                                        // H-0 and H-1 are considered within grace period (no approval needed)
                                        $is_today = ($created_date === $today || $created_date === $yesterday);

                                        // Rule: If > 2 days, DELETE is NOT allowed (only edit/request edit)
                                        // Stricter: Only H-0 (today) and H-1 (yesterday) are allowed to delete/request delete
                                        $is_over_2_days = !($created_date === $today || $created_date === $yesterday);

                                        $is_creator = $row['created_by'] == $_SESSION['user_id'];
                                        $is_admin_klinik = $user_role === 'admin_klinik';
                                        $is_spv_klinik = $user_role === 'spv_klinik';
                                        $is_super_admin = $user_role === 'super_admin';

                                        // Unified Access Logic
                                        $can_edit_direct = false;
                                        $can_request_edit = false;

                                        if ($is_super_admin) {
                                            $can_edit_direct = true;
                                        } elseif ($is_admin_klinik) {
                                            if ($is_today) {
                                                $can_edit_direct = true;
                                            } else {
                                                $can_request_edit = true;
                                            }
                                        } elseif ($is_today && $is_creator) {
                                            $can_edit_direct = true;
                                        }

                                        $is_pending = (strpos((string) $status, 'pending') !== false || (strpos((string) $row['nomor_pemakaian'], 'REQ-ADD-') !== false && $status !== 'active'));
                                        ?>

                                        <?php if ($status === 'active'): ?>
                                            <?php if ($can_edit_direct): ?>
                                                <button class="btn btn-sm btn-warning edit-pemakaian" data-id="<?= $row['id'] ?>"
                                                    data-created-at="<?= date('Y-m-d', strtotime($row['created_at'])) ?>" title="Edit"
                                                    data-bs-toggle="modal" data-bs-target="#modalEdit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if (!$is_over_2_days): ?>
                                                    <button class="btn btn-sm btn-danger delete-pemakaian" data-id="<?= $row['id'] ?>"
                                                        data-nomor="<?= htmlspecialchars($row['nomor_pemakaian']) ?>"
                                                        data-created-at="<?= date('Y-m-d', strtotime($row['created_at'])) ?>" title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php elseif ($can_request_edit): ?>
                                                <button class="btn btn-sm btn-outline-warning edit-pemakaian"
                                                    data-id="<?= $row['id'] ?>"
                                                    data-created-at="<?= date('Y-m-d', strtotime($row['created_at'])) ?>"
                                                    title="Edit (Lewat Hari)" data-bs-toggle="modal" data-bs-target="#modalEdit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if (!$is_over_2_days): ?>
                                                    <button class="btn btn-sm btn-outline-danger request-delete" data-id="<?= $row['id'] ?>"
                                                        data-nomor="<?= htmlspecialchars($row['nomor_pemakaian']) ?>"
                                                        data-created-at="<?= date('Y-m-d', strtotime($row['created_at'])) ?>"
                                                        title="Request Hapus (Lewat Hari)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php elseif ($is_pending): ?>
                                            <div class="d-flex align-items-center">
                                                <span
                                                    class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle px-3 py-1 me-2 text-center"
                                                    style="font-size: 0.65rem; line-height: 1.2;">
                                                    <i class="fas fa-clock me-1"></i> Menunggu<br>Approval
                                                </span>
                                                <?php if ($is_spv_klinik || $is_super_admin): ?>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-success approve-request"
                                                            data-id="<?= $row['id'] ?>" title="Approve">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger reject-request" data-id="<?= $row['id'] ?>"
                                                            title="Reject">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 px-3 pb-3">
                    <div class="small text-muted">
                        Menampilkan <?= $offset + 1 ?> - <?= min($offset + $items_per_page, $total_all) ?> dari
                        <?= $total_all ?> data
                    </div>
                    <nav>
                        <ul class="pagination pagination-circular mb-0">
                            <?php
                            $p_url = function ($page) use ($active_tab) {
                                $params = $_GET;
                                $params['p'] = $page;
                                $params['tab'] = $active_tab;
                                return 'index.php?' . http_build_query($params);
                            };
                            ?>
                            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $p_url($current_page - 1) ?>"><i
                                        class="fas fa-chevron-left"></i></a>
                            </li>
                            <?php
                            $start_p = max(1, $current_page - 2);
                            $end_p = min($total_pages, $start_p + 4);
                            if ($end_p - $start_p < 4)
                                $start_p = max(1, $end_p - 4);

                            for ($i = $start_p; $i <= $end_p; $i++):
                                ?>
                                <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $p_url($i) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $p_url($current_page + 1) ?>"><i
                                        class="fas fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: // TAB DATA OUT ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="d-flex justify-content-between p-3 bg-white border-bottom align-items-center">
                <div style="width: 300px;">
                    <form method="GET" action="index.php">
                        <input type="hidden" name="page" value="pemakaian_bhp_list">
                        <input type="hidden" name="tab" value="data_out">
                        <?php foreach ($_GET as $key => $val):
                            if (!in_array($key, ['q', 'p', 'page', 'tab'])): ?>
                                <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>">
                            <?php endif; endforeach; ?>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0"><i
                                    class="fas fa-search text-muted"></i></span>
                            <input type="text" name="q" class="form-control border-start-0 ps-0"
                                placeholder="Cari No. Pemakaian / Item" value="<?= htmlspecialchars($filter_q) ?>"
                                style="border-radius: 0 8px 8px 0;">
                        </div>
                    </form>
                </div>
                <div class="d-flex align-items-center">
                    <?php
                    // Build export URL with current filters
                    $export_url = "api/export_odoo_bhp.php?tab=data_out";
                    if (isset($_GET['start_date']))
                        $export_url .= "&start_date=" . urlencode($_GET['start_date']);
                    if (isset($_GET['end_date']))
                        $export_url .= "&end_date=" . urlencode($_GET['end_date']);
                    if (isset($_GET['klinik_id']))
                        $export_url .= "&klinik_id=" . urlencode($_GET['klinik_id']);
                    if (isset($_GET['q']))
                        $export_url .= "&q=" . urlencode($_GET['q']);
                    ?>
                    <a href="<?= $export_url ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel me-2"></i>Export Odoo (.xlsx)
                    </a>
                </div>
            </div>
            <div class="table-responsive rounded-top scrollbar-custom">
                <table class="table table-spreadsheet align-middle mb-0" id="tableDataOut">
                    <thead>
                        <tr>
                            <th width="120"><i class="far fa-clock me-1"></i> Tgl Input</th>
                            <th width="120"><i class="far fa-calendar-alt me-1"></i> Tgl Pemakaian BHP</th>
                            <th><i class="fas fa-user-md me-1"></i> PIC / Nakes / Klinik</th>
                            <th><i class="fas fa-box me-1"></i> Item</th>
                            <th width="80" class="text-center">Qty (Unit)</th>
                            <th width="100">UoM</th>
                            <th width="100" class="text-center bg-light">Qty Odoo</th>
                            <th width="80" class="bg-light">UoM Odoo</th>
                            <th><i class="fas fa-info-circle me-1"></i> Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result_out->fetch_assoc()):
                            $status_text = ($row['jenis_pemakaian'] === 'hc') ? 'Additional Used - From Tas HC' : 'Direct Used - On Site Klinik';
                            $pic_name = ($row['jenis_pemakaian'] === 'hc') ? $row['hc_name'] : $row['nama_klinik'];
                            $badge_class = ($row['jenis_pemakaian'] === 'hc') ? 'badge-hc' : 'badge-klinik';

                            // Calculate Odoo Qty
                            $ratio = (float) ($row['uom_ratio'] ?? 1);
                            $qty_odoo = $row['qty'] * $ratio;
                            $uom_odoo = $row['uom_odoo'] ?: $row['satuan'];
                            ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($row['created_at'] ?? '')) ?></td>
                                <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td>
                                    <div class="nakes-pill">
                                        <?= htmlspecialchars($pic_name) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="item-pill">
                                        <?= htmlspecialchars($row['nama_barang']) ?>
                                    </div>
                                </td>
                                <td class="text-center fw-bold text-primary"><?= fmt_qty($row['qty']) ?></td>
                                <td>
                                    <span class="uom-text">
                                        <?= htmlspecialchars($row['satuan']) ?>
                                    </span>
                                </td>
                                <td class="text-center fw-bold text-success bg-light"><?= fmt_qty($qty_odoo) ?></td>
                                <td class="text-muted small bg-light"><?= htmlspecialchars($uom_odoo) ?></td>
                                <td>
                                    <span class="status-pill <?= $badge_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 px-3 pb-3">
                    <div class="small text-muted">
                        Menampilkan <?= $offset + 1 ?> - <?= min($offset + $items_per_page, $total_all) ?> dari
                        <?= $total_all ?> data
                    </div>
                    <nav>
                        <ul class="pagination pagination-circular mb-0">
                            <?php
                            $p_url = function ($page) use ($active_tab) {
                                $params = $_GET;
                                $params['p'] = $page;
                                $params['tab'] = $active_tab;
                                return 'index.php?' . http_build_query($params);
                            };
                            ?>
                            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $p_url($current_page - 1) ?>"><i
                                        class="fas fa-chevron-left"></i></a>
                            </li>
                            <?php
                            $start_p = max(1, $current_page - 2);
                            $end_p = min($total_pages, $start_p + 4);
                            if ($end_p - $start_p < 4)
                                $start_p = max(1, $end_p - 4);

                            for ($i = $start_p; $i <= $end_p; $i++):
                                ?>
                                <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $p_url($i) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $p_url($current_page + 1) ?>"><i
                                        class="fas fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
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

        .bg-warning-subtle {
            background-color: #fff3cd !important;
        }

        .text-warning {
            color: #856404 !important;
        }

        .border-warning-subtle {
            border-color: #ffeeba !important;
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
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-info-circle me-2"></i>Detail Pemakaian BHP
                </h5>
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
<div class="modal fade" id="modalEdit" tabindex="-1" aria-labelledby="modalEditLabel" aria-hidden="true"
    data-bs-keyboard="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalEditLabel"><i class="fas fa-edit me-2"></i>Edit
                    Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <form id="formEditPemakaianBHP" method="POST" action="actions/process_pemakaian_bhp.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body p-4 bg-light">
                    <div id="editLoadingSpinner" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span
                                class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 text-muted">Memuat data...</p>
                    </div>
                    <div id="editFormContent" style="display:none;">
                        <!-- Form Header Info -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal Pemakaian BHP
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="tanggal" id="editTanggal" class="form-control"
                                            required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-tags text-primary me-1"></i>Jenis Pemakaian <span
                                                class="text-danger">*</span>
                                        </label>
                                        <div class="jenis-segmented mt-1" id="editJenisSegmented">
                                            <button type="button"
                                                class="jenis-segment-btn <?= ($user_role === 'petugas_hc') ? '' : 'active' ?>"
                                                data-value="klinik" <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>Clinic</button>
                                            <button type="button"
                                                class="jenis-segment-btn <?= ($user_role === 'petugas_hc') ? 'active' : '' ?>"
                                                data-value="hc" <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>HC</button>
                                        </div>
                                        <select name="jenis_pemakaian" id="editJenisPemakaian" class="d-none" required
                                            <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>
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
                                        <input type="text" class="form-control bg-light" id="editKlinikName" value=""
                                            readonly>
                                        <input type="hidden" name="klinik_id" id="editKlinikId" value="">
                                    </div>
                                    <div class="col-md-3" id="editPetugasHcWrap"
                                        style="<?= ($user_role === 'petugas_hc') ? 'display:block;' : 'display:none;' ?>">
                                        <label class="form-label fw-semibold small mb-1">
                                            <i class="fas fa-user-nurse text-primary me-1"></i>Petugas HC <span
                                                class="text-danger">*</span>
                                        </label>
                                        <?php if ($user_role === 'petugas_hc'): ?>
                                            <input type="text" class="form-control bg-light"
                                                value="<?= htmlspecialchars($_SESSION['nama_lengkap']) ?>" readonly>
                                            <input type="hidden" name="user_hc_id" id="editUserHcIdHidden"
                                                value="<?= (int) $_SESSION['user_id'] ?>">
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
                                        <textarea name="catatan_transaksi" id="editCatatan" class="form-control"
                                            rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item Table -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-boxes me-2"></i>Daftar Item
                                    Barang</h6>
                                <button type="button" class="btn btn-success btn-sm" id="editAddRowBtn">
                                    <i class="fas fa-plus-circle me-1"></i> Tambah Item
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="editItemTable">
                                        <thead class="bg-light">
                                            <tr>
                                                <th width="50%" class="py-3 text-muted small fw-bold">Item Barang <span
                                                        class="text-danger">*</span></th>
                                                <th width="15%" class="py-3 text-muted small fw-bold">Qty <span
                                                        class="text-danger">*</span></th>
                                                <th width="20%" class="py-3 text-muted small fw-bold">UoM</th>
                                                <th width="15%" class="py-3 text-muted small fw-bold">Perubahan</th>
                                                <th width="10%"
                                                    class="text-center py-3 text-muted small fw-bold border-start">Aksi
                                                </th>
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
                    <button type="submit" id="editSubmitBtn"
                        class="btn btn-primary px-5 shadow-sm rounded-pill py-2 fw-bold" style="display:none;">
                        <i class="fas fa-save me-2"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tambah Pemakaian -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-labelledby="modalTambahLabel" aria-hidden="true"
    data-bs-keyboard="true" data-bs-backdrop="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalTambahLabel"><i
                        class="fas fa-plus-circle me-2"></i>Tambah Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
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
                                        <i class="fas fa-clock text-muted me-1"></i>Tanggal Input
                                    </label>
                                    <input type="text" class="form-control bg-light" value="<?= date('d/m/Y H:i') ?>"
                                        readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal Pemakaian BHP <span
                                            class="text-danger">*</span>
                                    </label>
                                    <input type="date" name="tanggal" id="modalTambahTanggal" class="form-control"
                                        value="" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-tags text-primary me-1"></i>Jenis Pemakaian <span
                                            class="text-danger">*</span>
                                    </label>
                                    <div class="jenis-segmented mt-1" id="modalJenisSegmented">
                                        <button type="button"
                                            class="jenis-segment-btn <?= ($user_role === 'petugas_hc') ? '' : 'active' ?>"
                                            data-value="klinik" <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>Clinic</button>
                                        <button type="button"
                                            class="jenis-segment-btn <?= ($user_role === 'petugas_hc') ? 'active' : '' ?>"
                                            data-value="hc" <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>HC</button>
                                    </div>
                                    <select name="jenis_pemakaian" id="modalJenisPemakaian" class="d-none" required
                                        <?= ($user_role === 'petugas_hc') ? 'disabled' : '' ?>>
                                        <option value="klinik" <?= ($user_role !== 'petugas_hc') ? 'selected' : '' ?>>
                                            Pemakaian Klinik</option>
                                        <option value="hc" <?= ($user_role === 'petugas_hc') ? 'selected' : '' ?>>Pemakaian
                                            HC</option>
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
                                                <option value="<?= (int) $k['id'] ?>" <?= ((int) $k['id'] === (int) $default_modal_klinik_id) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($k['nama_klinik']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control bg-light"
                                            value="<?= htmlspecialchars($klinik_name) ?>" readonly>
                                        <input type="hidden" name="klinik_id" id="modalKlinikIdHidden"
                                            value="<?= (int) $user_klinik_id ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3" id="modalPetugasHcWrap"
                                    style="<?= ($user_role === 'petugas_hc') ? 'display:block;' : 'display:none;' ?>">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-user-nurse text-primary me-1"></i>Petugas HC <span
                                            class="text-danger">*</span>
                                    </label>
                                    <?php if ($user_role === 'petugas_hc'): ?>
                                        <input type="text" class="form-control bg-light"
                                            value="<?= htmlspecialchars($_SESSION['nama_lengkap']) ?>" readonly>
                                        <input type="hidden" name="user_hc_id" id="modalUserHcIdHidden"
                                            value="<?= (int) $_SESSION['user_id'] ?>">
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
                                    <textarea name="catatan_transaksi" class="form-control" rows="2"
                                        placeholder="Catatan keseluruhan (opsional)"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-boxes me-2"></i>Daftar Item Barang
                            </h6>
                            <button type="button" class="btn btn-success btn-sm" id="modalAddRowBtn">
                                <i class="fas fa-plus-circle me-1"></i> Tambah Item
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="modalItemTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="50%" class="py-3 text-muted small fw-bold">Item Barang <span
                                                    class="text-danger">*</span></th>
                                            <th width="15%" class="py-3 text-muted small fw-bold">Qty <span
                                                    class="text-danger">*</span></th>
                                            <th width="20%" class="py-3 text-muted small fw-bold">UoM</th>
                                            <th width="15%"
                                                class="text-center py-3 text-muted small fw-bold border-start">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalItemTableBody">
                                        <tr class="modal-item-row">
                                            <td class="p-2">
                                                <select name="items[0][barang_id]"
                                                    class="form-select form-select-sm modal-barang-select" required>
                                                    <option value="">-- Pilih Barang --</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <input type="number" name="items[0][qty]"
                                                    class="form-control form-control-sm" min="1" placeholder="0"
                                                    required>
                                            </td>
                                            <td class="p-2">
                                                <select name="items[0][uom_mode]"
                                                    class="form-select form-select-sm modal-uom-select" required>
                                                    <option value="oper">-</option>
                                                </select>
                                                <input type="hidden" name="items[0][satuan]"
                                                    class="modal-satuan-hidden">
                                                <input type="hidden" name="items[0][catatan_item]" value="">
                                            </td>
                                            <td class="text-center border-start">
                                                <button type="button"
                                                    class="btn btn-sm btn-link text-danger modal-remove-row" disabled>
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
        list.forEach(function (p) {
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

    function syncJenisSegmented(prefix) {
        const v = $('#' + prefix + 'JenisPemakaian').val() || 'klinik';
        const $wrap = $('#' + prefix + 'JenisSegmented');
        if (!$wrap.length) return;
        $wrap.find('.jenis-segment-btn').removeClass('active');
        $wrap.find('.jenis-segment-btn[data-value="' + v + '"]').addClass('active');
    }

    $(document).on('click', '#modalJenisSegmented .jenis-segment-btn', function () {
        if ($(this).prop('disabled')) return;
        const v = $(this).data('value');
        $('#modalJenisPemakaian').val(v).trigger('change');
    });

    $(document).on('click', '#editJenisSegmented .jenis-segment-btn', function () {
        if ($(this).prop('disabled')) return;
        const v = $(this).data('value');
        $('#editJenisPemakaian').val(v).trigger('change');
    });

    $(document).on('change', '#modalJenisPemakaian, #modalKlinikId', function () {
        togglePetugasHc('modal');
    });

    $(document).on('change', '#editJenisPemakaian, #editKlinikId', function () {
        togglePetugasHc('edit');
    });

    $(document).on('shown.bs.modal', '#modalTambah', function () {
        togglePetugasHc('modal');
    });
</script>

<!-- Modal Upload Excel -->
<div class="modal fade" id="modalUpload" tabindex="-1" aria-labelledby="modalUploadLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #28a745;">
                <h5 class="modal-title fw-bold text-white" id="modalUploadLabel"><i
                        class="fas fa-file-excel me-2"></i>Upload Excel Pemakaian BHP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <form id="formUploadExcel" method="POST" action="actions/process_pemakaian_bhp_upload.php"
                enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-body p-4 bg-light">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">
                                    <i class="fas fa-file-excel text-success me-1"></i>Pilih File Excel <span
                                        class="text-danger">*</span>
                                </label>
                                <input type="file" name="excel_file" id="excelFile" class="form-control" accept=".xlsx"
                                    required>
                                <div class="form-text small">Format wajib: <b>.xlsx</b> (Maksimal 5MB).</div>
                            </div>

                            <div class="alert alert-info border-0 shadow-sm small py-3">
                                <h6 class="fw-bold mb-2"><i class="fas fa-info-circle me-1"></i>Petunjuk Format Baru (10
                                    Kolom):</h6>
                                <ul class="mb-0 ps-3">
                                    <li>Gunakan <b>Template Baru</b> (xlsx) agar urutan kolom tepat (10 kolom).</li>
                                    <li><b>Format Tanggal:</b> dd Month yyyy, HH:mm (Contoh: 04 March 2026, 16:00) atau
                                        Month dd, yyyy, h:mm AM/PM (Contoh: April 14, 2026, 4:35 PM).</li>
                                    <li><b>Atomic Transaction:</b> Jika ada 1 baris salah, seluruh file akan ditolak.
                                    </li>
                                    <li><b>Duplikasi:</b> Sistem mengecek kombinasi ID Pasien + Tanggal + Kode Barang.
                                    </li>
                                    <li>Validasi ketat terhadap Master Item, Satuan (UoM), Nakes, dan Cabang.</li>
                                    <li><b>Onsite vs HC:</b> kolom <b>Nama Nakes</b> kosong = pemakaian <b>Klinik
                                            (onsite)</b>; diisi (sesuai master petugas HC) = pemakaian <b>HC</b>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div
                            class="card-body py-3 d-flex justify-content-between align-items-center bg-white border-start border-primary border-4 rounded">
                            <div>
                                <h6 class="mb-1 fw-bold text-primary">Template Excel</h6>
                                <p class="small text-muted mb-0">
                                    Template mengikuti filter <strong>Dari / Sampai Tanggal</strong> di atas.
                                    Jika ada peringatan gap (auto tanpa input manual), baris item dari pemakaian auto
                                    akan ikut diisi.
                                </p>
                            </div>
                            <a href="api/download_template_pemakaian.php?start_date=<?= urlencode($start_date) ?>&amp;end_date=<?= urlencode($end_date) ?>"
                                class="btn btn-outline-primary btn-sm rounded-pill px-3">
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

<!-- Modal Fix UOM -->
<div class="modal fade" id="modalFixUom" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 py-3 text-white" style="background-color: #f39c12;">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-exclamation-triangle me-2"></i>Sesuaikan
                    Satuan (UoM)</h5>
            </div>
            <div class="modal-body p-4">
                <p>Beberapa item dalam file Excel Anda memiliki satuan yang tidak terdaftar. Silakan pilih satuan yang
                    benar untuk melanjutkan proses upload.</p>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Barang</th>
                                <th>Satuan di Excel</th>
                                <th width="200">Sesuaikan Ke</th>
                            </tr>
                        </thead>
                        <tbody id="fixUomTableBody">
                            <!-- Items will be listed here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 py-3">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal
                    Upload</button>
                <button type="button" id="btnSaveUomFix"
                    class="btn btn-warning px-5 shadow-sm rounded-pill py-2 fw-bold">
                    <i class="fas fa-save me-2"></i>Simpan & Proses Ulang
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {
        // More robust fix for Select2 focus in Bootstrap 5 modals
        document.addEventListener('focusin', function (e) {
            if (e.target.closest(".select2-container") || e.target.closest(".swal2-container")) {
                e.stopImmediatePropagation();
            }
        }, true);

        const barangMaster = <?php
        $m = [];
        foreach ($barang_list as $b) {
            $m[(int) $b['id']] = [
                'name' => (string) $b['nama_barang'],
                'satuan' => (string) $b['satuan'],
                'uom_odoo' => (string) ($b['uom_odoo'] ?? ''),
                'uom_ratio' => (float) ($b['uom_ratio'] ?? 1)
            ];
        }
        echo json_encode($m, JSON_UNESCAPED_UNICODE);
        ?>;
        let availableItemsMap = {}; // Will be filled by AJAX

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
                allowClear: true,
                minimumResultsForSearch: 0,
                dropdownParent: $modal.length ? $modal : $(document.body)
            };

            $select.select2(opts);

            // Standard fix for Select2 search focus in Bootstrap modals
            $select.on('select2:open', function () {
                setTimeout(function () {
                    const searchField = document.querySelector('.select2-container--open .select2-search__field');
                    if (searchField) {
                        searchField.focus();
                    }
                }, 50);
            });
        }

        function fmtQty(v) {
            var n = parseFloat(v || 0);
            if (Math.abs(n - Math.round(n)) < 0.00005) return Math.round(n).toString();
            var s = n.toFixed(4).replace(/\.?0+$/, "");
            return s === "" ? "0" : s;
        }

        function buildOptionsHtml(klinikId, jenis, userHcId = null) {
            if (!jenis) return '<option value="">-- Pilih Jenis Pemakaian dahulu --</option>';
            if (jenis === 'klinik' && !klinikId) return '<option value="">-- Pilih Klinik dahulu --</option>';
            if (jenis === 'hc' && !userHcId) return '<option value="">-- Pilih Petugas dahulu --</option>';

            const items = Object.values(availableItemsMap || {}).sort((a, b) => a.name.localeCompare(b.name));
            if (!items || items.length === 0) return '<option value="">-- Tidak ada barang tersedia --</option>';

            let html = '<option value="">-- Pilih Barang --</option>';
            items.forEach(it => {
                const master = barangMaster[it.id] || {};
                const satuan = master.satuan || it.satuan || '';
                const ratio = Number(master.uom_ratio) || Number(it.uom_ratio) || 1;

                // Default: Satuan Operasional
                const displayQty = it.rawQty / ratio;

                const safeName = String(it.name).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const safeSatuan = String(satuan).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const qtyText = fmtQty(displayQty);

                html += `<option value="${it.id}" data-satuan="${safeSatuan}" data-raw-qty="${it.rawQty}">${safeName} (Stok: ${qtyText})</option>`;
            });
            return html;
        }

        function fillSelectOptions($select, klinikId, jenis, userHcId = null, keepValue = true) {
            const prev = keepValue ? $select.val() : '';
            $select.html(buildOptionsHtml(klinikId, jenis, userHcId));
            if (keepValue && prev && $select.find(`option[value="${prev}"]`).length) {
                $select.val(prev);
            } else {
                $select.val('');
            }
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.trigger('change.select2');
            } else {
                $select.trigger('change');
            }
        }

        function loadAvailableItems(callback) {
            const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int) $default_modal_klinik_id ?>';
            const jenis = $('#modalJenisPemakaian').val();
            let userHcId = $('#modalUserHcId').val();
            if (!userHcId && $('#modalUserHcIdHidden').length) {
                userHcId = $('#modalUserHcIdHidden').val();
            }

            if (!jenis || (jenis === 'klinik' && !klinikId) || (jenis === 'hc' && !userHcId)) {
                availableItemsMap = {};
                if (callback) callback();
                return;
            }

            $.ajax({
                url: 'api/ajax_pemakaian_items.php',
                method: 'POST',
                data: { klinik_id: klinikId, jenis: jenis, user_hc_id: userHcId, _csrf: PEMAKAIAN_CSRF },
                dataType: 'json',
                success: function (res) {
                    availableItemsMap = {};
                    if (res.success && Array.isArray(res.items)) {
                        res.items.forEach(it => {
                            availableItemsMap[it.barang_id] = {
                                id: it.barang_id,
                                name: it.nama_barang,
                                satuan: it.satuan,
                                uom_ratio: it.uom_ratio,
                                rawQty: it.qty
                            };
                        });
                    }
                    if (callback) callback();
                },
                error: function () {
                    availableItemsMap = {};
                    if (callback) callback();
                }
            });
        }

        function refreshModalBarangOptions(keepValue = true) {
            loadAvailableItems(function () {
                const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int) $default_modal_klinik_id ?>';
                const jenis = $('#modalJenisPemakaian').val();
                let userHcId = $('#modalUserHcId').val();
                $('.modal-barang-select').each(function () {
                    const $s = $(this);
                    fillSelectOptions($s, klinikId, jenis, userHcId, keepValue);
                    initBarangSelect2($s);
                });
            });
        }

        function refreshEditBarangOptions(keepValue = true) {
            // Edit modal uses a different logic for now, but we'll try to sync it if needed.
            // For now, let's just make sure the Add Modal is fast.
            const id = $('#editId').val();
            if (!id) return;

            const klinikId = $('#editKlinikId').val();
            const jenis = $('#editJenisPemakaian').val();
            let userHcId = $('#editUserHcId').val();
            if (!userHcId && $('#editUserHcIdHidden').length) {
                userHcId = $('#editUserHcIdHidden').val();
            }

            $.ajax({
                url: 'api/ajax_pemakaian_items.php',
                method: 'POST',
                data: { klinik_id: klinikId, jenis: jenis, user_hc_id: userHcId, _csrf: PEMAKAIAN_CSRF },
                dataType: 'json',
                success: function (res) {
                    availableItemsMap = {};
                    if (res.success && Array.isArray(res.items)) {
                        res.items.forEach(it => {
                            availableItemsMap[it.barang_id] = {
                                id: it.barang_id,
                                name: it.nama_barang,
                                satuan: it.satuan,
                                uom_ratio: it.uom_ratio,
                                rawQty: it.qty
                            };
                        });
                    }
                    $('.edit-barang-select').each(function () {
                        const $s = $(this);
                        const $row = $s.closest('tr');
                        const prevUom = $row.find('.edit-uom-select').val();

                        fillSelectOptions($s, klinikId, jenis, userHcId, keepValue);
                        initBarangSelect2($s);

                        if (prevUom) {
                            $row.find('.edit-uom-select').val(prevUom).trigger('change');
                        }
                    });
                }
            });
        }

        // Initialize DataOut explicitly to avoid double-init and set correct sort + empty message
        if ($('#tableDataOut').length && !$.fn.DataTable.isDataTable('#tableDataOut')) {
            $('#tableDataOut').DataTable({
                order: [[0, 'desc']], // Sort by Tanggal Input (kolom ke-0)
                paging: true,
                searching: false,
                language: {
                    emptyTable: 'Tidak ada data pemakaian ditemukan.'
                }
            });
        }

        // View detail
        $('.view-detail').on('click', function () {
            const id = $(this).data('id');
            $('#modalDetail').modal('show');
            $('#modalDetailContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>');

            $.ajax({
                url: 'api/get_pemakaian_bhp_detail.php',
                method: 'GET',
                data: { id: id },
                success: function (response) {
                    $('#modalDetailContent').html(response);
                },
                error: function () {
                    $('#modalDetailContent').html('<div class="p-3"><div class="alert alert-danger">Gagal memuat detail</div></div>');
                }
            });
        });

        // Delete pemakaian
        $('.delete-pemakaian').on('click', function () {
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
                        success: function (response) {
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
                        error: function () {
                            Swal.fire('Error', 'Gagal menghubungi server', 'error');
                        }
                    });
                }
            });
        });

        // --- VERIFIKASI SEBELUM SIMPAN ---
        $('#formPemakaianBHP').on('submit', function (e) {
            e.preventDefault();
            const form = $(this);
            const rowCount = $('.modal-item-row').length;
            if (rowCount === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Perhatian',
                    text: 'Minimal harus ada 1 item barang'
                });
                return false;
            }

            const tanggalStr = form.find('input[name="tanggal"]').val();
            let isPastDay = false;
            if (tanggalStr) {
                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                yesterday.setHours(0, 0, 0, 0);

                const selectedDate = new Date(tanggalStr);
                selectedDate.setHours(0, 0, 0, 0);

                isPastDay = selectedDate < yesterday;
            }

            const userRole = '<?= $_SESSION['role'] ?>';

            // UNIFIED CONFIRMATION WRAPPER
            const triggerFinalSubmit = (additionalData = {}) => {
                const tanggalBhp = $('#modalTambahTanggal').val();
                const jenisPemakaian = $('#modalJenisPemakaian').val();

                // Fix: Ambil nama klinik dengan lebih akurat
                let klinikName = 'Klinik';
                const $klinikSelect = $('#modalKlinikId');
                const $klinikHidden = $('#modalKlinikIdHidden');

                if ($klinikSelect.is(':visible') && $klinikSelect.val()) {
                    klinikName = $klinikSelect.find('option:selected').text();
                } else if ($klinikHidden.length) {
                    // Ambil dari input readonly sebelumnya
                    klinikName = $klinikHidden.prev('input').val() || 'Klinik';
                } else if ($klinikSelect.length && $klinikSelect.find('option:selected').text()) {
                    klinikName = $klinikSelect.find('option:selected').text();
                }

                // Jika jenisnya HC, tambahkan nama petugas jika ada
                let jenisDisplay = (jenisPemakaian === 'hc') ? 'Pemakaian HC' : 'Pemakaian Klinik';
                if (jenisPemakaian === 'hc') {
                    const $petugasSelect = $('#modalUserHcId');
                    const $petugasHidden = $('#modalUserHcIdHidden');
                    let petugasName = '';

                    if ($petugasSelect.is(':visible') && $petugasSelect.val()) {
                        petugasName = $petugasSelect.find('option:selected').text();
                    } else if ($petugasHidden.length) {
                        petugasName = $petugasHidden.prev('input').val();
                    }

                    if (petugasName) {
                        jenisDisplay += ` (${petugasName})`;
                    }
                }

                const itemCount = $('.modal-item-row').length;

                const d = new Date(tanggalBhp);
                const formattedDate = ("0" + d.getDate()).slice(-2) + "/" + ("0" + (d.getMonth() + 1)).slice(-2) + "/" + d.getFullYear();

                Swal.fire({
                    title: 'Konfirmasi Simpan',
                    html: `
                    <div class="text-start small">
                        <p class="mb-2">Apakah Anda yakin data pemakaian ini sudah benar?</p>
                        <table class="table table-sm table-bordered mb-0">
                        <tr><th width="40%">Klinik/Unit</th><td>${klinikName}</td></tr>
                        <tr><th>Jenis</th><td class="text-uppercase">${jenisDisplay}</td></tr>
                        <tr><th>Total Item</th><td>${itemCount} Item</td></tr>
                        <tr class="table-primary"><th class="fw-bold">Tgl Pemakaian</th><td class="fw-bold text-primary">${formattedDate}</td></tr>
                    </table>
                        <p class="mt-3 mb-0 text-center fw-bold text-danger">Peringatan: Pastikan Tanggal Pemakaian BHP sudah sesuai!</p>
                    </div>
                `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#204EAB',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Ya, Simpan Pemakaian',
                    cancelButtonText: 'Cek Kembali',
                    reverseButtons: true
                }).then((confirmRes) => {
                    if (confirmRes.isConfirmed) {
                        const formData = new FormData(form[0]);
                        // Append metadata from backdate popup if exists
                        Object.entries(additionalData).forEach(([key, val]) => {
                            formData.append(key, val);
                        });
                        submitFormDirect(form, formData);
                    }
                });
            };

            if (userRole === 'admin_klinik' && isPastDay) {
                const reasonMap = {
                    wrong_qty: 'Salah input jumlah/kuantitas item',
                    wrong_item: 'Salah memilih jenis barang/BHP',
                    wrong_date: 'Koreksi tanggal transaksi pemakaian',
                    wrong_nakes: 'Koreksi data petugas HC/Nakes',
                    wrong_klinik: 'Koreksi data klinik/lokasi',
                    admin_libur: 'Admin sedang libur',
                    other_admin: 'Lainnya (Koreksi Administrasi)'
                };
                const sourceMap = {
                    admin_logistik: 'Admin Logistik',
                    nakes: 'Nakes',
                    sistem_integrasi: 'Sistem/Integrasi'
                };

                Swal.fire({
                    title: 'Request Approval (Tambah Data Backdate)',
                    html: `
                    <div class="text-start p-2">
                        <div class="alert alert-warning small py-2 mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>Tanggal pemakaian lebih dari H-2 memerlukan approval SPV.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-info-circle me-1"></i> Alasan Penambahan <span class="text-danger">*</span>
                            </label>
                            <select id="swalReasonCodeAdd" class="form-select shadow-sm">
                                <option value="">-- Pilih Alasan --</option>
                                ${Object.entries(reasonMap).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-search me-1"></i> Sumber Informasi <span class="text-danger">*</span>
                            </label>
                            <select id="swalChangeSourceAdd" class="form-select shadow-sm">
                                <option value="">-- Pilih Sumber --</option>
                                ${Object.entries(sourceMap).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}
                            </select>
                        </div>
                        
                        <div class="mb-1">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-user-check me-1"></i> Pelaku / Asal Permintaan <span class="text-danger">*</span>
                            </label>
                            <div id="swalActorContainerAdd">
                                <select id="swalChangeActorAdd" class="form-select shadow-sm">
                                    <option value="">-- Pilih Pelaku --</option>
                                </select>
                            </div>
                            <div class="form-text small" style="font-size: 0.75rem;">Siapa yang meminta atau bertanggung jawab atas penambahan data terlambat ini?</div>
                        </div>
                    </div>
                `,
                    showCancelButton: true,
                    confirmButtonText: 'Lanjut',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#204EAB',
                    customClass: {
                        popup: 'rounded-4 shadow-lg border-0',
                        confirmButton: 'px-4 py-2 fw-bold rounded-pill',
                        cancelButton: 'px-4 py-2 fw-bold rounded-pill'
                    },
                    didOpen: () => {
                        const $source = $('#swalChangeSourceAdd');
                        const $container = $('#swalActorContainerAdd');
                        const currentKlinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : ($('#modalKlinikIdHidden').length ? $('#modalKlinikIdHidden').val() : '');

                        $source.on('change', function () {
                            const source = $(this).val();
                            if (!source) {
                                $container.html('<select id="swalChangeActorAdd" class="form-select shadow-sm"><option value="">-- Pilih Pelaku --</option></select>');
                                return;
                            }

                            if (source === 'sistem_integrasi') {
                                $container.html(`
                                <div class="input-group">
                                    <select id="swalChangeActorSelectAdd" class="form-select shadow-sm" style="width: 40%">
                                        <option value="sistem">Sistem</option>
                                        <option value="other">Lainnya...</option>
                                    </select>
                                    <input type="text" id="swalChangeActorTextAdd" class="form-control shadow-sm" placeholder="Nama..." style="display:none">
                                </div>
                            `);
                                $('#swalChangeActorSelectAdd').on('change', function () {
                                    if ($(this).val() === 'other') {
                                        $('#swalChangeActorTextAdd').show().focus();
                                    } else {
                                        $('#swalChangeActorTextAdd').hide().val('');
                                    }
                                });
                            } else {
                                $container.html('<select id="swalChangeActorAdd" class="form-select shadow-sm"><option value="">Memuat...</option></select>');
                                const $actor = $('#swalChangeActorAdd');
                                loadChangeActorUsers(source, currentKlinikId).then((res) => {
                                    let options = ['<option value="">-- Pilih Pelaku --</option>'];
                                    if (res && res.success && Array.isArray(res.items)) {
                                        res.items.forEach(it => options.push(`<option value="${it.id}">${it.nama_lengkap} (${it.role})</option>`));
                                    }
                                    options.push('<option value="other">-- Lainnya (Isi Manual) --</option>');
                                    $actor.html(options.join(''));

                                    $actor.on('change', function () {
                                        if ($(this).val() === 'other') {
                                            $container.html('<input type="text" id="swalChangeActorTextAdd" class="form-control shadow-sm" placeholder="Masukkan nama pelaku asal...">');
                                            $('#swalChangeActorTextAdd').focus();
                                        }
                                    });
                                });
                            }
                        });
                    },
                    preConfirm: () => {
                        const reasonCode = String($('#swalReasonCodeAdd').val() || '');
                        const changeSource = String($('#swalChangeSourceAdd').val() || '');
                        let actorId = 0;
                        let actorName = '';

                        if ($('#swalChangeActorTextAdd').is(':visible')) {
                            actorName = String($('#swalChangeActorTextAdd').val() || '').trim();
                            if (!actorName) {
                                Swal.showValidationMessage('Nama pelaku asal wajib diisi.');
                                return false;
                            }
                        } else if ($('#swalChangeActorSelectAdd').length) {
                            actorName = $('#swalChangeActorSelectAdd').val();
                        } else {
                            actorId = String($('#swalChangeActorAdd').val() || '');
                            if (!actorId) {
                                Swal.showValidationMessage('Pelaku asal wajib dipilih.');
                                return false;
                            }
                        }

                        if (!reasonCode) {
                            Swal.showValidationMessage('Alasan penambahan wajib dipilih.');
                            return false;
                        }
                        if (!changeSource) {
                            Swal.showValidationMessage('Sumber informasi wajib dipilih.');
                            return false;
                        }

                        return {
                            is_request_approval: '1',
                            reason_code: reasonCode,
                            reason: reasonMap[reasonCode] || reasonCode,
                            change_source: changeSource,
                            change_actor_user_id: actorId,
                            change_actor_name: actorName
                        };
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        triggerFinalSubmit(result.value);
                    }
                });
                return false;
            }

            // NORMAL CASE (Non-Backdate)
            triggerFinalSubmit();
            return false;
        });

        // --- MODAL TAMBAH LOGIC ---
        let modalRowIndex = 1;

        function stripAutoDeductionNote(s) {
            if (!s) return '';
            // Updated regex to handle horizontal format as well
            return s.replace(/(^|\n\s*\n)Auto Deduction(\s*\(.*?\))?:\s*[\s\S]*$/m, '').trim();
        }

        function clearModalAutoDeductionNote() {
            const $catatan = $('#modalTambah').find('textarea[name="catatan_transaksi"]');
            if (!$catatan.length) return;
            const before = String($catatan.val() || '');
            const after = stripAutoDeductionNote(before);
            if (after !== before.trim()) {
                $catatan.val(after);
            }
        }

        function resetModalItemRows() {
            const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int) $default_modal_klinik_id ?>';
            const jenis = $('#modalJenisPemakaian').val() || 'klinik';
            const optionsHtml = buildOptionsHtml(klinikId, jenis);
            const rowHtml = `
            <tr class="modal-item-row">
                <td class="p-2">
                    <select name="items[0][barang_id]" class="form-select form-select-sm modal-barang-select" required>
                        ${optionsHtml}
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
                    <input type="hidden" name="items[0][catatan_item]" value="">
                </td>
                <td class="text-center border-start">
                    <button type="button" class="btn btn-sm btn-link text-danger modal-remove-row" disabled>
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
            $('#modalItemTableBody').html(rowHtml);
            initBarangSelect2($('#modalItemTableBody tr:last').find('.modal-barang-select'));
            modalRowIndex = 1;
            updateModalRemoveButtons();
        }

        // Handle UOM selection change
        $(document).on('change', '.modal-uom-select, .edit-uom-select', function () {
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
        $(document).on('change', '.modal-barang-select', function () {
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
        $('#modalAddRowBtn').on('click', function () {
            const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : '<?= (int) $default_modal_klinik_id ?>';
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
                    <input type="hidden" name="items[${modalRowIndex}][catatan_item]" value="">
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
        $(document).on('click', '.modal-remove-row', function () {
            $(this).closest('tr').remove();
            updateModalRemoveButtons();
        });

        function updateModalRemoveButtons() {
            const rowCount = $('.modal-item-row').length;
            $('.modal-remove-row').prop('disabled', rowCount === 1);
        }

        function submitFormDirect(form, formData) {
            const $btn = form.find('button[type="submit"]');
            const oldHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                        $btn.prop('disabled', false).html(oldHtml);
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                    $btn.prop('disabled', false).html(oldHtml);
                }
            });
        }

        // --- MODAL EDIT LOGIC ---
        let editRowIndex = 0;

        function makeEditRow(idx, barangId, qty, satuan, catatan, uomMode, detailId, isExisting) {
            const formattedQty = qty ? fmtQty(qty) : '';
            const existing = isExisting ? 1 : 0;
            const lockExistingAttr = isExisting ? 'disabled' : '';
            // Task 1: Sembunyikan tombol hapus untuk item yang sudah ada (existing) agar tidak ambigu dengan dropdown Perubahan
            const removeBtn = isExisting ? '' : `
            <button type="button" class="btn btn-sm btn-link text-danger edit-remove-row">
                <i class="fas fa-trash-alt"></i>
            </button>
        `;
            return `
            <tr class="edit-item-row" data-existing="${existing}">
                <td class="p-2">
                    <select name="items[${idx}][barang_id]" class="form-select form-select-sm edit-barang-select" required ${lockExistingAttr}>
                        <option value="">-- Pilih Barang --</option>
                    </select>
                </td>
                <td class="p-2">
                    <input type="text" name="items[${idx}][qty]" class="form-control form-control-sm" value="${formattedQty}" placeholder="0" required ${lockExistingAttr}>
                </td>
                <td class="p-2">
                    <select name="items[${idx}][uom_mode]" class="form-select form-select-sm edit-uom-select" required ${lockExistingAttr}>
                        <option value="oper">-</option>
                    </select>
                    <input type="hidden" name="items[${idx}][satuan]" class="edit-satuan-hidden" value="${satuan || ''}">
                    <input type="hidden" name="items[${idx}][catatan_item]" value="${catatan || ''}">
                    <input type="hidden" class="edit-detail-id" value="${detailId || ''}">
                </td>
                <td class="p-2">
                    <select class="form-select form-select-sm edit-op-select">
                        <option value="">-- Pilih --</option>
                        ${isExisting ? '<option value="update">Ubah</option><option value="remove">Hapus</option>' : '<option value="add" selected>Tambah</option>'}
                    </select>
                </td>
                <td class="text-center border-start">
                    ${removeBtn}
                </td>
            </tr>
        `;
        }

        function resetEditItemRows() {
            editRowIndex = 0;
            $('#editItemTableBody').html(makeEditRow(0, null, '', '', '', 'oper', '', false));
            const $sel = $('#editItemTableBody tr:last').find('.edit-barang-select');
            let userHcId = $('#editUserHcId').val();
            if (!userHcId && $('#editUserHcIdHidden').length) {
                userHcId = $('#editUserHcIdHidden').val();
            }
            fillSelectOptions($sel, $('#editKlinikId').val(), $('#editJenisPemakaian').val(), userHcId, false);
            initBarangSelect2($sel);
            editRowIndex = 1;
            updateEditRemoveButtons();
        }

        function updateEditRemoveButtons() {
            const count = $('.edit-item-row').length;
            $('.edit-remove-row').prop('disabled', count === 1);
        }

        // Open edit modal - load data via AJAX
        $('.edit-pemakaian').on('click', function () {
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
                success: function (res) {
                    if (!res.success) {
                        $('#editLoadingSpinner').hide();
                        Swal.fire('Gagal', res.message, 'error');
                        return;
                    }
                    const h = res.header;
                    $('#editTanggal').val(h.tanggal);
                    $('#editKlinikId').val(h.klinik_id);
                    $('#editKlinikName').val(h.nama_klinik || '');
                    $('#editJenisPemakaian').val(h.jenis_pemakaian);
                    syncJenisSegmented('edit');

                    // Hide/show petugas HC and load available items
                    const userHcId = h.user_hc_id ? String(h.user_hc_id) : null;
                    const $petugasWrap = $('#editPetugasHcWrap');
                    const $petugasSelect = $('#editUserHcId');

                    if (h.jenis_pemakaian === 'hc') {
                        $petugasWrap.show();
                        renderPetugasOptions($petugasSelect, h.klinik_id);
                        $petugasSelect.val(userHcId);
                    } else {
                        $petugasWrap.hide();
                        $petugasSelect.val('');
                    }

                    // Keep transaction note clean: do not inject per-item auto notes here.
                    // Per-item notes are already stored in each item row (`catatan_item`).
                    $('#editCatatan').val(stripAutoDeductionNote(h.catatan_transaksi || ''));

                    // Load available items for this context first
                    $.ajax({
                        url: 'api/ajax_pemakaian_items.php',
                        method: 'POST',
                        data: { klinik_id: h.klinik_id, jenis: h.jenis_pemakaian, user_hc_id: userHcId, _csrf: PEMAKAIAN_CSRF },
                        dataType: 'json',
                        success: function (stockRes) {
                            availableItemsMap = {};
                            if (stockRes.success && Array.isArray(stockRes.items)) {
                                stockRes.items.forEach(it => {
                                    availableItemsMap[it.barang_id] = {
                                        id: it.barang_id,
                                        name: it.nama_barang,
                                        satuan: it.satuan,
                                        uom_ratio: it.uom_ratio,
                                        rawQty: it.qty
                                    };
                                });
                            }

                            // Ensure edited items are in the list (even if out of stock now)
                            res.details.forEach(function (d) {
                                if (!availableItemsMap[d.barang_id]) {
                                    availableItemsMap[d.barang_id] = {
                                        id: d.barang_id,
                                        name: d.nama_barang,
                                        satuan: d.satuan,
                                        uom_ratio: 1, // Will be updated by barangMaster if available
                                        rawQty: 0
                                    };
                                }
                            });

                            editRowIndex = 0;
                            res.details.forEach(function (d) {
                                const rowHtml = makeEditRow(editRowIndex, d.barang_id, d.qty, d.satuan, d.catatan_item, d.uom_mode, d.id, true);
                                $('#editItemTableBody').append(rowHtml);
                                const $row = $('#editItemTableBody tr:last');
                                const $barangSelect = $row.find('.edit-barang-select');

                                // Re-build options with the current availableItemsMap
                                $barangSelect.html(buildOptionsHtml(h.klinik_id, h.jenis_pemakaian, userHcId));
                                $barangSelect.val(String(d.barang_id));

                                initBarangSelect2($barangSelect);

                                // Trigger manual change to populate UOM options
                                const master = barangMaster[d.barang_id] || {};
                                const uomOdoo = master.uom_odoo || '';
                                const ratio = Number(master.uom_ratio) || 1;
                                const $uomSelect = $row.find('.edit-uom-select');

                                let uomOpts = '';
                                if (uomOdoo && ratio && ratio !== 1 && String(uomOdoo).toLowerCase() !== String(d.satuan).toLowerCase()) {
                                    uomOpts += `<option value="oper">${d.satuan}</option>`;
                                    uomOpts += `<option value="odoo">${uomOdoo}</option>`;
                                    $uomSelect.prop('disabled', false);
                                } else {
                                    uomOpts += `<option value="oper">${d.satuan || '-'}</option>`;
                                    $uomSelect.prop('disabled', true);
                                }
                                $uomSelect.html(uomOpts).val(d.uom_mode || 'oper');

                                editRowIndex++;
                            });

                            updateEditRemoveButtons();
                            $('#editLoadingSpinner').hide();
                            $('#editFormContent').show();
                            $('#editSubmitBtn').show();
                        }
                    });
                },
                error: function () {
                    $('#editLoadingSpinner').hide();
                    Swal.fire('Error', 'Gagal memuat data', 'error');
                }
            });
        });

        // Barang change in edit modal
        $(document).on('change', '.edit-barang-select', function () {
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
        $('#editAddRowBtn').on('click', function () {
            const rowHtml = makeEditRow(editRowIndex, null, '', '', '', 'oper', '', false);
            $('#editItemTableBody').append(rowHtml);
            const $sel = $('#editItemTableBody tr:last').find('.edit-barang-select');
            fillSelectOptions($sel, $('#editKlinikId').val(), $('#editJenisPemakaian').val(), false);
            initBarangSelect2($sel);
            editRowIndex++;
            updateEditRemoveButtons();
        });

        // Remove row in edit modal
        $(document).on('click', '.edit-remove-row', function () {
            $(this).closest('tr').remove();
            updateEditRemoveButtons();
        });

        $(document).on('click', '.approve-request', function () {
            const id = $(this).data('id');

            // Task 6: Tampilkan loading selagi mengambil detail perubahan
            Swal.fire({
                title: 'Memuat Detail...',
                html: '<div class="py-3 text-center"><div class="spinner-border text-primary" role="status"></div></div>',
                showConfirmButton: false,
                allowOutsideClick: false
            });

            $.ajax({
                url: 'api/get_pemakaian_bhp_detail.php',
                method: 'GET',
                data: { id: id },
                success: function (html) {
                    Swal.fire({
                        title: 'Review & Approve Request',
                        html: `
                        <div class="text-start mb-3 small alert alert-info py-2 border-0">
                            <i class="fas fa-info-circle me-2"></i>Silakan tinjau detail perubahan di bawah ini sebelum memberikan persetujuan.
                        </div>
                        <div class="approval-detail-container" style="max-height: 50vh; overflow-y: auto; text-align: left; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                            ${html}
                        </div>
                    `,
                        width: '950px',
                        showCancelButton: true,
                        confirmButtonText: 'Approve & Simpan',
                        denyButtonText: 'Tolak',
                        showDenyButton: true,
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#204EAB',
                        denyButtonColor: '#dc3545',
                        showLoaderOnConfirm: true,
                        customClass: {
                            popup: 'rounded-4 shadow-lg border-0',
                            confirmButton: 'px-4 py-2 fw-bold rounded-pill',
                            denyButton: 'px-4 py-2 fw-bold rounded-pill',
                            cancelButton: 'px-4 py-2 fw-bold rounded-pill'
                        },
                        preConfirm: () => {
                            return $.ajax({
                                url: 'actions/process_pemakaian_bhp_action.php',
                                method: 'POST',
                                data: { action: 'approve', id: id, _csrf: PEMAKAIAN_CSRF },
                                dataType: 'json'
                            }).then(res => {
                                if (!res.success) {
                                    throw new Error(res.message || 'Gagal menyetujui request');
                                }
                                return res;
                            }).catch(xhr => {
                                let msg = 'Request failed';
                                if (xhr.responseJSON && xhr.responseJSON.message) {
                                    msg = xhr.responseJSON.message;
                                } else if (xhr.statusText) {
                                    msg = xhr.statusText;
                                }
                                Swal.showValidationMessage(msg);
                            });
                        },
                        preDeny: () => {
                            return Swal.fire({
                                title: 'Tolak Permintaan',
                                input: 'textarea',
                                inputLabel: 'Alasan Penolakan',
                                inputPlaceholder: 'Masukkan alasan penolakan...',
                                inputAttributes: {
                                    'aria-label': 'Masukkan alasan penolakan'
                                },
                                showCancelButton: true,
                                confirmButtonText: 'Kirim Penolakan',
                                cancelButtonText: 'Batal',
                                confirmButtonColor: '#dc3545',
                                showLoaderOnConfirm: true,
                                customClass: {
                                    popup: 'rounded-4 shadow-lg border-0',
                                    confirmButton: 'px-4 py-2 fw-bold rounded-pill',
                                    cancelButton: 'px-4 py-2 fw-bold rounded-pill'
                                },
                                preConfirm: (reason) => {
                                    if (!reason) {
                                        Swal.showValidationMessage('Alasan penolakan wajib diisi');
                                        return false;
                                    }
                                    return $.ajax({
                                        url: 'actions/process_pemakaian_bhp_action.php',
                                        method: 'POST',
                                        data: { action: 'reject', id: id, reason: reason, _csrf: PEMAKAIAN_CSRF },
                                        dataType: 'json'
                                    }).then(res => {
                                        if (!res.success) {
                                            throw new Error(res.message || 'Gagal menolak request');
                                        }
                                        return res;
                                    }).catch(xhr => {
                                        let msg = 'Request failed';
                                        if (xhr.responseJSON && xhr.responseJSON.message) {
                                            msg = xhr.responseJSON.message;
                                        } else if (xhr.statusText) {
                                            msg = xhr.statusText;
                                        }
                                        Swal.showValidationMessage(msg);
                                    });
                                },
                                allowOutsideClick: () => !Swal.isLoading()
                            });
                        },
                        allowOutsideClick: () => !Swal.isLoading()
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Swal.fire('Berhasil', result.value.message, 'success').then(() => {
                                location.reload();
                            });
                        } else if (result.isDenied) {
                            if (result.value && result.value.success) {
                                Swal.fire('Berhasil', result.value.message, 'success').then(() => {
                                    location.reload();
                                });
                            } else if (result.value) {
                                Swal.fire('Gagal', result.value.message || 'Gagal menolak permintaan', 'error');
                            }
                        }
                    });
                },
                error: function () {
                    Swal.fire('Error', 'Gagal memuat detail perubahan', 'error');
                }
            });
        });

        $(document).on('click', '.reject-request', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Tolak Request',
                text: 'Pilih alasan penolakan:',
                input: 'select',
                inputOptions: {
                    'Data tidak sesuai': 'Data tidak sesuai',
                    'Alasan tidak valid': 'Alasan tidak valid',
                    'Lainnya': 'Lainnya'
                },
                inputPlaceholder: '-- Pilih Alasan --',
                showCancelButton: true,
                confirmButtonText: 'Tolak',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#d33',
                inputValidator: (value) => {
                    if (!value) return 'Alasan wajib dipilih!';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'actions/process_pemakaian_bhp_action.php',
                        method: 'POST',
                        data: { action: 'reject', id: id, reason: result.value, _csrf: PEMAKAIAN_CSRF },
                        dataType: 'json',
                        success: function (res) {
                            if (res.success) {
                                Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                            } else {
                                Swal.fire('Gagal', res.message, 'error');
                            }
                        }
                    });
                }
            });
        });

        $(document).on('click', '.request-delete', function () {
            const id = $(this).data('id');
            const nomor = $(this).data('nomor') || '';
            Swal.fire({
                title: 'Request Hapus (Lewat Hari)',
                text: `Data ${nomor} sudah lewat hari. Pilih alasan penghapusan:`,
                input: 'select',
                inputOptions: {
                    'Salah input / Data tidak valid': 'Salah input / Data tidak valid',
                    'Data pemakaian ganda (Double Input)': 'Data pemakaian ganda (Double Input)',
                    'Pembatalan transaksi oleh user': 'Pembatalan transaksi oleh user',
                    'Koreksi stok sistem': 'Koreksi stok sistem'
                },
                inputPlaceholder: '-- Pilih Alasan --',
                showCancelButton: true,
                confirmButtonText: 'Kirim Request',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#d33',
                inputValidator: (value) => {
                    if (!value) return 'Alasan wajib dipilih!';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'actions/process_pemakaian_bhp_delete.php',
                        method: 'POST',
                        data: { id: id, reason: result.value, _csrf: PEMAKAIAN_CSRF },
                        dataType: 'json',
                        success: function (res) {
                            if (res.success) {
                                Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                            } else {
                                Swal.fire('Gagal', res.message, 'error');
                            }
                        }
                    });
                }
            });
        });

        function collectEditOperations() {
            const ops = [];
            let invalid = '';
            $('#editItemTableBody .edit-item-row').each(function () {
                const $row = $(this);
                const op = String($row.find('.edit-op-select').val() || '');
                const isExisting = String($row.data('existing') || '0') === '1';
                const detailId = Number($row.find('.edit-detail-id').val() || 0);
                const barangId = Number($row.find('.edit-barang-select').val() || 0);
                const qty = Number(parseFloat(String($row.find('input[name*="[qty]"]').val() || '0').replace(',', '.')) || 0);
                const satuan = String($row.find('.edit-satuan-hidden').val() || '');
                const catatanItem = String($row.find('input[name*="[catatan_item]"]').val() || '');

                // Existing item without selected operation means "tetap / tidak diubah".
                if (isExisting && !op) {
                    // Task Fix: Include 'keep' to prevent deletion on backend
                    ops.push({ op: 'keep', detail_id: detailId, barang_id: barangId, qty: qty, satuan: satuan, catatan_item: catatanItem });
                    return true;
                }
                if (!isExisting && op !== 'add') {
                    invalid = 'Item baru hanya boleh bertipe Tambah.';
                    return false;
                }
                if (op === 'remove') {
                    if (detailId <= 0) {
                        invalid = 'Detail item hapus tidak valid.';
                        return false;
                    }
                    ops.push({ op: 'remove', detail_id: detailId });
                    return true;
                }
                if (barangId <= 0 || qty <= 0) {
                    invalid = 'Item dan qty wajib diisi pada perubahan tambah/ubah.';
                    return false;
                }
                if (op === 'update') {
                    if (detailId <= 0) {
                        invalid = 'Detail item ubah tidak valid.';
                        return false;
                    }
                    ops.push({ op: 'update', detail_id: detailId, barang_id: barangId, qty: qty, satuan: satuan, catatan_item: catatanItem });
                } else if (op === 'add') {
                    ops.push({ op: 'add', barang_id: barangId, qty: qty, satuan: satuan, catatan_item: catatanItem });
                } else {
                    invalid = 'Operasi item tidak valid.';
                    return false;
                }
                return true;
            });

            if (invalid) return { ok: false, message: invalid };
            if (ops.length === 0) return { ok: false, message: 'Pilih minimal 1 perubahan item (Tambah/Ubah/Hapus).' };
            return { ok: true, data: ops };
        }

        function loadChangeActorUsers(source, klinikId = null) {
            return $.ajax({
                url: 'api/ajax_change_actor_users.php',
                method: 'GET',
                dataType: 'json',
                data: { source: source, klinik_id: klinikId }
            });
        }

        $(document).on('change', '.edit-op-select', function () {
            const $row = $(this).closest('tr');
            const op = String($(this).val() || '');
            const isExisting = String($row.data('existing') || '0') === '1';
            // Existing rows: locked by default, only open if explicitly "Ubah".
            // New rows: always open (op add).
            let disabled = false;
            if (isExisting) {
                disabled = (op !== 'update');
            } else {
                disabled = false;
            }
            $row.find('.edit-barang-select, .edit-uom-select, input[name*="[qty]"]').prop('disabled', disabled);
        });

        // Modify form submit to include reason + actor metadata for past-day requests
        $('#formEditPemakaianBHP').on('submit', function (e) {
            e.preventDefault();
            const form = $(this);

            // Task 3: Pastikan semua item (termasuk yang disabled) ikut terkirim agar data lama tidak hilang saat edit
            const disabledFields = form.find(':disabled').prop('disabled', false);
            const formData = new FormData(this);
            disabledFields.prop('disabled', true);

            const editId = $('#modalEdit').find('input[name="id"]').val();
            const createdAtStr = $('.edit-pemakaian[data-id="' + editId + '"]').data('created-at');
            let isPastDay = false;
            if (createdAtStr) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                yesterday.setHours(0, 0, 0, 0);

                const createdDate = new Date(createdAtStr);
                createdDate.setHours(0, 0, 0, 0);

                // isPastDay is true only if createdDate is before yesterday (H-2 or older)
                isPastDay = createdDate < yesterday;
            }

            const userRole = '<?= $_SESSION['role'] ?>';
            // Task: Admin Klinik only requires reason/approval for past-day edits.
            // Super Admin or same-day edits should bypass the reason popup and use collectEditOperations() to unify logic.
            if (userRole === 'admin_klinik' && isPastDay) {
                const opsResult = collectEditOperations();
                if (!opsResult.ok) {
                    Swal.fire('Perhatian', opsResult.message, 'warning');
                    return;
                }
                const reasonMap = {
                    wrong_qty: 'Salah input jumlah/kuantitas item',
                    wrong_item: 'Salah memilih jenis barang/BHP',
                    wrong_date: 'Koreksi tanggal transaksi pemakaian',
                    wrong_nakes: 'Koreksi data petugas HC/Nakes',
                    wrong_klinik: 'Koreksi data klinik/lokasi',
                    admin_libur: 'Admin sedang libur',
                    other_admin: 'Lainnya (Koreksi Administrasi)'
                };
                const sourceMap = {
                    admin_logistik: 'Admin Logistik',
                    nakes: 'Nakes',
                    sistem_integrasi: 'Sistem/Integrasi'
                };
                Swal.fire({
                    title: 'Request Perubahan (Lewat Hari)',
                    html: `
                    <div class="text-start p-2">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-info-circle me-1"></i> Alasan Perubahan <span class="text-danger">*</span>
                            </label>
                            <select id="swalReasonCode" class="form-select shadow-sm">
                                <option value="">-- Pilih Alasan --</option>
                                ${Object.entries(reasonMap).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-search me-1"></i> Sumber Perubahan <span class="text-danger">*</span>
                            </label>
                            <select id="swalChangeSource" class="form-select shadow-sm">
                                <option value="">-- Pilih Sumber --</option>
                                ${Object.entries(sourceMap).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}
                            </select>
                        </div>
                        
                        <div class="mb-1">
                            <label class="form-label fw-bold small text-muted mb-1">
                                <i class="fas fa-user-check me-1"></i> Pelaku Asal <span class="text-danger">*</span>
                            </label>
                            <div id="swalActorContainer">
                                <select id="swalChangeActor" class="form-select shadow-sm">
                                    <option value="">-- Pilih Pelaku --</option>
                                </select>
                            </div>
                            <div class="form-text small" style="font-size: 0.75rem;">Siapa yang melakukan kesalahan input atau meminta perubahan ini?</div>
                        </div>
                    </div>
                `,
                    showCancelButton: true,
                    confirmButtonText: 'Kirim Request',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#204EAB',
                    customClass: {
                        popup: 'rounded-4 shadow-lg border-0',
                        confirmButton: 'px-4 py-2 fw-bold rounded-pill',
                        cancelButton: 'px-4 py-2 fw-bold rounded-pill'
                    },
                    didOpen: () => {
                        const $source = $('#swalChangeSource');
                        const $container = $('#swalActorContainer');
                        const currentKlinikId = $('#editKlinikId').val();

                        $source.on('change', function () {
                            const source = $(this).val();
                            if (!source) {
                                $container.html('<select id="swalChangeActor" class="form-select shadow-sm"><option value="">-- Pilih Pelaku --</option></select>');
                                return;
                            }

                            if (source === 'sistem_integrasi') {
                                $container.html(`
                                <div class="input-group">
                                    <select id="swalChangeActorSelect" class="form-select shadow-sm" style="width: 40%">
                                        <option value="sistem">Sistem</option>
                                        <option value="other">Lainnya...</option>
                                    </select>
                                    <input type="text" id="swalChangeActorText" class="form-control shadow-sm" placeholder="Nama..." style="display:none">
                                </div>
                            `);
                                $('#swalChangeActorSelect').on('change', function () {
                                    if ($(this).val() === 'other') {
                                        $('#swalChangeActorText').show().focus();
                                    } else {
                                        $('#swalChangeActorText').hide().val('');
                                    }
                                });
                            } else {
                                $container.html('<select id="swalChangeActor" class="form-select shadow-sm"><option value="">Memuat...</option></select>');
                                const $actor = $('#swalChangeActor');
                                loadChangeActorUsers(source, currentKlinikId).then((res) => {
                                    let options = ['<option value="">-- Pilih Pelaku --</option>'];
                                    if (res && res.success && Array.isArray(res.items)) {
                                        res.items.forEach(it => options.push(`<option value="${it.id}">${it.nama_lengkap} (${it.role})</option>`));
                                    }
                                    options.push('<option value="other">-- Lainnya (Isi Manual) --</option>');
                                    $actor.html(options.join(''));

                                    $actor.on('change', function () {
                                        if ($(this).val() === 'other') {
                                            $container.html('<input type="text" id="swalChangeActorText" class="form-control shadow-sm" placeholder="Masukkan nama pelaku asal...">');
                                            $('#swalChangeActorText').focus();
                                        }
                                    });
                                });
                            }
                        });
                    },
                    preConfirm: () => {
                        const reasonCode = String($('#swalReasonCode').val() || '');
                        const changeSource = String($('#swalChangeSource').val() || '');
                        let actorId = 0;
                        let actorName = '';

                        if ($('#swalChangeActorText').is(':visible')) {
                            actorName = String($('#swalChangeActorText').val() || '').trim();
                            if (!actorName) {
                                Swal.showValidationMessage('Nama pelaku asal wajib diisi.');
                                return false;
                            }
                        } else if ($('#swalChangeActorSelect').length) {
                            actorName = $('#swalChangeActorSelect').val();
                        } else {
                            actorId = String($('#swalChangeActor').val() || '');
                            if (!actorId) {
                                Swal.showValidationMessage('Pelaku asal wajib dipilih.');
                                return false;
                            }
                        }

                        if (!reasonCode) {
                            Swal.showValidationMessage('Alasan perubahan wajib dipilih.');
                            return false;
                        }
                        if (!changeSource) {
                            Swal.showValidationMessage('Sumber perubahan wajib dipilih.');
                            return false;
                        }

                        return {
                            reason_code: reasonCode,
                            reason: reasonMap[reasonCode] || reasonCode,
                            change_source: changeSource,
                            change_actor_user_id: actorId,
                            change_actor_name: actorName
                        };
                    }
                }).then((result) => {
                    if (!result.isConfirmed || !result.value) return;
                    formData.append('reason_code', result.value.reason_code);
                    formData.append('reason', result.value.reason);
                    formData.append('change_source', result.value.change_source);
                    formData.append('change_actor_user_id', result.value.change_actor_user_id);
                    formData.append('change_actor_name', result.value.change_actor_name); // Kirim nama pelaku (jika ada)
                    formData.append('request_items_json', JSON.stringify(opsResult.data));
                    submitFormWithReason(form, formData);
                });
                return;
            }

            // Use collectEditOperations for direct edits too (Same-day or Super Admin)
            const opsResult = collectEditOperations();
            if (!opsResult.ok) {
                Swal.fire('Perhatian', opsResult.message, 'warning');
                return;
            }
            formData.append('request_items_json', JSON.stringify(opsResult.data));
            submitFormWithReason(form, formData);
        });

        function submitFormWithReason(form, formData) {
            if ($('.edit-item-row').length === 0) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Minimal harus ada 1 item barang' });
                return false;
            }

            const $btn = form.find('button[type="submit"]');
            const oldHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                        $btn.prop('disabled', false).html(oldHtml);
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                    $btn.prop('disabled', false).html(oldHtml);
                }
            });
        }

        $('#modalJenisPemakaian').on('change', function () {
            syncJenisSegmented('modal');
            clearModalAutoDeductionNote();
            resetModalItemRows();
            refreshModalBarangOptions(false);
        });
        $('#modalUserHcId').on('change', function () {
            clearModalAutoDeductionNote();
            resetModalItemRows();
            refreshModalBarangOptions(false);
        });
        $('#editJenisPemakaian').on('change', function () {
            syncJenisSegmented('edit');
            resetEditItemRows();
            refreshEditBarangOptions(false);
        });
        $('#editUserHcId').on('change', function () {
            resetEditItemRows();
            refreshEditBarangOptions(false);
        });
        if ($('#modalKlinikId').length) {
            $('#modalKlinikId').on('change', function () {
                clearModalAutoDeductionNote();
                resetModalItemRows();
                refreshModalBarangOptions(false);
            });
        }
        refreshModalBarangOptions(false);

        function loadAutoDeductionIntoModal() {
            const $modal = $('#modalTambah');
            if (!$modal.length) return;

            const jenis = $('#modalJenisPemakaian').val() || 'klinik';
            const klinikId = $('#modalKlinikId').length ? $('#modalKlinikId').val() : ($('#modalKlinikIdHidden').length ? $('#modalKlinikIdHidden').val() : '');
            const tanggal = $modal.find('input[name="tanggal"]').val() || new Date().toISOString().slice(0, 10);

            // Selalu bersihkan alert dan catatan auto-deduction setiap kali fungsi ini dipanggil
            // agar tidak "nyangkut" saat parameter berubah
            $('#autoDeductionAlert').remove();
            clearModalAutoDeductionNote();

            if (!klinikId || !jenis) return;
            if (jenis !== 'klinik') {
                return;
            }

            // Ensure available items are loaded before building auto deduction rows
            loadAvailableItems(function () {
                $.ajax({
                    url: 'api/get_auto_deduction_items.php',
                    method: 'GET',
                    data: { klinik_id: klinikId, tanggal: tanggal, jenis: jenis },
                    dataType: 'json',
                    success: function (res) {
                        if (!res || !res.success || !Array.isArray(res.data) || res.data.length === 0) {
                            return;
                        }

                        if ($('#autoDeductionAlert').length === 0) {
                            $('#modalItemTable').before('<div id="autoDeductionAlert" class="alert alert-info small py-2 mb-3"><i class="fas fa-info-circle me-2"></i>Ditemukan <b>' + res.data.length + '</b> usulan pemakaian otomatis dari Booking yang sudah Completed di tanggal ini. Silakan sesuaikan jumlah aktualnya.</div>');
                        } else {
                            $('#autoDeductionAlert').html('<i class="fas fa-info-circle me-2"></i>Ditemukan <b>' + res.data.length + '</b> usulan pemakaian otomatis dari Booking yang sudah Completed di tanggal ini. Silakan sesuaikan jumlah aktualnya.');
                        }

                        $('#modalItemTableBody').empty();
                        modalRowIndex = 0;
                        const noteLines = [];

                        res.data.forEach(function (item) {
                            const idx = modalRowIndex;

                            const optionsHtmlBase = buildOptionsHtml(klinikId, jenis);
                            const hasOpt = optionsHtmlBase.indexOf('value="' + String(item.barang_id) + '"') !== -1;
                            const safeName = String(item.nama_barang || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                            let optionsHtml = optionsHtmlBase;
                            if (!hasOpt) {
                                if (optionsHtmlBase.startsWith('<option value="">')) {
                                    const firstEnd = optionsHtmlBase.indexOf('</option>') + 9;
                                    optionsHtml = optionsHtmlBase.substring(0, firstEnd) + '<option value="' + String(item.barang_id) + '">' + safeName + '</option>' + optionsHtmlBase.substring(firstEnd);
                                } else {
                                    optionsHtml = '<option value="' + String(item.barang_id) + '">' + safeName + '</option>' + optionsHtmlBase;
                                }
                            }

                            const qtyVal = fmtQty(item.qty || 0);
                            const cat = String(item.referensi || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            if (cat) noteLines.push(cat);

                            const newRow = `
                            <tr class="modal-item-row">
                                <td class="p-2">
                                    <select name="items[${idx}][barang_id]" class="form-select form-select-sm modal-barang-select" required>
                                        ${optionsHtml}
                                    </select>
                                </td>
                                <td class="p-2">
                                    <input type="number" name="items[${idx}][qty]" class="form-control form-control-sm" min="0" step="0.0001" value="${qtyVal}" placeholder="0" required>
                                </td>
                                <td class="p-2">
                                    <select name="items[${idx}][uom_mode]" class="form-select form-select-sm modal-uom-select" required>
                                        <option value="oper">-</option>
                                    </select>
                                    <input type="hidden" name="items[${idx}][satuan]" class="modal-satuan-hidden">
                                    <input type="hidden" name="items[${idx}][catatan_item]" value="${cat}">
                                </td>
                                <td class="text-center border-start">
                                    <button type="button" class="btn btn-sm btn-link text-danger modal-remove-row">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                            $('#modalItemTableBody').append(newRow);
                            const $row = $('#modalItemTableBody tr:last');
                            const $select = $row.find('.modal-barang-select');

                            // Set value BEFORE init select2 so it picks it up correctly
                            $select.val(String(item.barang_id)).trigger('change');
                            initBarangSelect2($select);

                            modalRowIndex++;
                        });

                        const $catatan = $modal.find('textarea[name="catatan_transaksi"]');
                        if ($catatan.length) {
                            const uniq = Array.from(new Set(noteLines.map(s => String(s || '').trim()).filter(Boolean)));
                            if (uniq.length > 0) {
                                const currentNote = $modal.find('textarea[name="catatan_transaksi"]').val();
                                const baseNote = stripAutoDeductionNote(currentNote);

                                // Compact horizontal format
                                const autoBlock = 'Auto Deduction (' + uniq.length + ' Bookings):\n' + uniq.join(', ');

                                const finalNote = baseNote ? (baseNote + '\n\n' + autoBlock) : autoBlock;
                                $modal.find('textarea[name="catatan_transaksi"]').val(finalNote);
                            }
                        }

                        updateModalRemoveButtons();
                    }
                });
            });
        }

        $('#modalTambah').on('shown.bs.modal', function () {
            loadAutoDeductionIntoModal();
        });
        $('#modalTambah').on('change', 'input[name="tanggal"]', function () {
            clearModalAutoDeductionNote();
            resetModalItemRows();
            loadAutoDeductionIntoModal();
        });
        $('#modalTambah').on('change', '#modalJenisPemakaian, #modalKlinikId', function () {
            loadAutoDeductionIntoModal();
        });

        // --- MODAL UPLOAD LOGIC ---
        $('#formUploadExcel').on('submit', function (e) {
            e.preventDefault();
            const fileInput = $('#excelFile')[0];

            if (!fileInput.files.length) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Silakan pilih file Excel terlebih dahulu' });
                return false;
            }

            const formData = new FormData(this);
            formData.append('ajax', '1');

            processUpload(formData);
        });

        function processUpload(formData) {
            Swal.fire({
                title: 'Memproses...',
                text: 'Mohon tunggu, file sedang diproses',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'actions/process_pemakaian_bhp_upload.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function (res) {
                    if (res.status === 'preview') {
                        const diffs = Array.isArray(res.diffs) ? res.diffs : [];
                        const diffCount = Number(res.diff_count || diffs.length || 0);

                        let html = `<div class="text-start small mb-2">${res.message || 'Preview perbedaan.'}</div>`;
                        html += `<div class="text-start small mb-2"><b>Total Perbedaan:</b> ${diffCount}</div>`;
                        html += `<div class="text-start small mb-3 p-2 bg-light rounded border-start border-4 border-primary">
                        <strong>Selisih</strong> = selisih antara <strong>jumlah aktual di file Excel</strong> dengan <strong>jumlah produk hasil mapping dari booking CS berstatus Completed</strong> yang sudah tercatat di sistem (pemakaian auto, per kombinasi tanggal &amp; cabang &amp; jenis).
                        Jika sudah pernah ada input manual untuk tanggal yang sama, angka &quot;Tercatat di sistem&quot; dapat memuat gabungan auto + manual.
                    </div>`;

                        if (diffs.length > 0) {
                            html += `<div style="max-height: 360px; overflow:auto; border:1px solid #eee; border-radius:8px;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Cabang</th>
                                        <th>Jenis</th>
                                        <th>Kode</th>
                                        <th>Item</th>
                                        <th class="text-end" title="Total pemakaian yang sudah ada di sistem (biasanya auto dari booking Completed)">Tercatat di sistem</th>
                                        <th class="text-end" title="Jumlah aktual dari file Excel">Excel (aktual)</th>
                                        <th class="text-end" title="Excel (aktual) − Tercatat di sistem">Selisih</th>
                                    </tr>
                                </thead>
                                <tbody>`;
                            diffs.forEach(d => {
                                const tgl = d.tanggal || '';
                                const cab = d.nama_klinik || d.klinik_id || '';
                                const jenis = d.jenis || '';
                                const kode = d.kode_barang || '';
                                const nama = d.nama_barang || '';
                                const ex = d.existing_qty ?? 0;
                                const up = d.upload_qty ?? 0;
                                const df = d.diff ?? 0;
                                html += `<tr>
                                <td>${tgl}</td>
                                <td>${cab}</td>
                                <td>${jenis}</td>
                                <td>${kode}</td>
                                <td>${nama}</td>
                                <td class="text-end">${ex}</td>
                                <td class="text-end">${up}</td>
                                <td class="text-end"><b>${df}</b></td>
                            </tr>`;
                            });
                            html += `</tbody></table></div>`;
                            if (diffCount > diffs.length) {
                                html += `<div class="text-muted small mt-2">Ditampilkan ${diffs.length} baris pertama dari ${diffCount} perbedaan.</div>`;
                            }
                        } else {
                            html += `<div class="alert alert-success small mb-0">Tidak ada perbedaan. Data upload sudah sama dengan data sistem.</div>`;
                        }

                        Swal.fire({
                            title: 'Preview BHP Harian',
                            html: html,
                            width: '90%',
                            showCancelButton: true,
                            confirmButtonText: 'Lanjutkan Proses',
                            cancelButtonText: 'Batal'
                        }).then((r) => {
                            if (!r.isConfirmed) return;
                            Swal.fire({
                                title: 'Memproses...',
                                text: 'Mohon tunggu, data sedang disimpan',
                                allowOutsideClick: false,
                                didOpen: () => { Swal.showLoading(); }
                            });
                            $.ajax({
                                url: 'actions/process_pemakaian_bhp_upload.php',
                                method: 'POST',
                                data: { ajax: '1', action: 'confirm_upload', token: res.token, _csrf: PEMAKAIAN_CSRF },
                                dataType: 'json',
                                success: function (res2) {
                                    if (res2.status === 'success') {
                                        Swal.fire('Berhasil', res2.message, 'success').then(() => { location.reload(); });
                                    } else {
                                        let debugMsg = res2.message || 'Gagal memproses upload';
                                        if (res2.debug) {
                                            debugMsg += '<br><small class="text-muted">Error in ' + res2.debug.file + ':' + res2.debug.line + '</small>';
                                        }
                                        Swal.fire({ icon: 'error', title: 'Gagal Simpan', html: debugMsg });
                                    }
                                },
                                error: function (xhr) {
                                    let errorMsg = 'Terjadi kesalahan sistem saat konfirmasi upload.';
                                    if (xhr.status === 403) {
                                        errorMsg = 'Sesi keamanan kadaluarsa (CSRF). Silakan refresh halaman dan coba lagi.';
                                    } else if (xhr.responseText) {
                                        // Try to extract error message from response if it contains text
                                        errorMsg += '<br><div class="text-start small mt-2 p-2 bg-light border">Status: ' + xhr.status + '<br>' + xhr.responseText.substring(0, 200) + '</div>';
                                    }
                                    Swal.fire({ icon: 'error', title: 'System Error', html: errorMsg });
                                }
                            });
                        });
                    } else if (res.status === 'success') {
                        Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                    } else if (res.status === 'error' && res.errors) {
                        const uomErrors = res.errors.filter(e => e.type === 'uom_mismatch');
                        if (uomErrors.length > 0) {
                            Swal.close();
                            showFixUomModal(uomErrors);
                        } else {
                            let msg = res.message + '<br><ul class="text-start mt-2 small">';
                            res.errors.slice(0, 10).forEach(e => { msg += '<li>' + e.message + '</li>'; });
                            if (res.errors.length > 10) msg += '<li>...dan ' + (res.errors.length - 10) + ' error lainnya</li>';
                            msg += '</ul>';
                            Swal.fire({ icon: 'error', title: 'Upload Ditolak', html: msg });
                        }
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                },
                error: function (xhr) {
                    let errorMsg = 'Terjadi kesalahan sistem saat upload.';
                    if (xhr.status === 403) {
                        errorMsg = 'Sesi keamanan kadaluarsa (CSRF) saat upload. Silakan refresh halaman.';
                    } else if (xhr.responseText) {
                        errorMsg += '<br><div class="text-start small mt-2 p-2 bg-light border">Status: ' + xhr.status + '<br>' + xhr.responseText.substring(0, 200) + '</div>';
                    }
                    Swal.fire({ icon: 'error', title: 'Upload Error', html: errorMsg });
                }
            });
        }

        function showFixUomModal(errors) {
            const $tbody = $('#fixUomTableBody');
            $tbody.empty();

            // Unique items to fix
            const uniqueItems = {};
            errors.forEach(e => {
                const key = e.data.kode_barang + '|' + e.data.invalid_uom;
                if (!uniqueItems[key]) {
                    uniqueItems[key] = e.data;
                }
            });

            Object.values(uniqueItems).forEach(item => {
                let opts = '';
                // Ensure allowed_uoms is treated as an array even if PHP sent it as associative object
                const allowedUoms = Array.isArray(item.allowed_uoms) ? item.allowed_uoms : Object.values(item.allowed_uoms || {});

                allowedUoms.forEach(u => {
                    opts += `<option value="${u}">${u}</option>`;
                });

                const row = `
                <tr>
                    <td>
                        <div class="fw-bold">${item.nama_item}</div>
                        <div class="text-muted small">Kode: ${item.kode_barang}</div>
                    </td>
                    <td><span class="badge bg-danger">${item.invalid_uom}</span></td>
                    <td>
                        <select class="form-select uom-fix-select" data-kode="${item.kode_barang}" data-from="${item.invalid_uom}">
                            ${opts}
                        </select>
                    </td>
                </tr>
            `;
                $tbody.append(row);
            });

            $('#modalUpload').modal('hide');
            $('#modalFixUom').modal('show');
        }

        $('#btnSaveUomFix').on('click', function () {
            const mappings = [];
            $('.uom-fix-select').each(function () {
                mappings.push({
                    kode_barang: $(this).data('kode'),
                    from_uom: $(this).data('from'),
                    to_uom: $(this).val()
                });
            });

            Swal.fire({
                title: 'Menyimpan Mapping...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'actions/process_pemakaian_bhp_upload.php',
                method: 'POST',
                data: {
                    ajax: '1',
                    action: 'fix_uom_mappings',
                    mappings: mappings,
                    _csrf: PEMAKAIAN_CSRF
                },
                dataType: 'json',
                success: function (res) {
                    if (res.status === 'success') {
                        // Success! Now re-trigger the original upload
                        $('#modalFixUom').modal('hide');
                        // We need to re-submit the form. 
                        // Since we can't easily re-use the FormData with the file without user interaction,
                        // the easiest way is to ask user to click "Upload & Proses" again or just show success and let them re-select file.
                        // BUT, actually we can just re-submit the form object if it's still in memory.

                        Swal.fire({
                            icon: 'success',
                            title: 'Mapping Tersimpan',
                            text: 'Silakan klik tombol "Proses Ulang" untuk melanjutkan upload.',
                            confirmButtonText: 'Proses Ulang'
                        }).then(() => {
                            $('#formUploadExcel').submit();
                        });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Gagal menyimpan mapping UOM', 'error');
                }
            });
        });

        if ($('#tablePemakaianBHP').length) {
            if ($.fn.DataTable.isDataTable('#tablePemakaianBHP')) {
                $('#tablePemakaianBHP').DataTable().destroy();
            }
            $('#tablePemakaianBHP').DataTable({
                "order": [[5, "desc"]], // Sort by Tanggal Input DESC
                "paging": false,
                "searching": false,
                "info": false,
                "language": {
                    "emptyTable": "Tidak ada data ditemukan"
                }
            });
        }
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
            { wch: 30 }, // Product
            { wch: 10 }, // Qty
            { wch: 15 }, // UoM
            { wch: 25 }, // Location
            { wch: 15 }, // Date
            { wch: 25 }, // Reference
            { wch: 30 }  // Type
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