<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';

// Access Control
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik']);

$user_role = $_SESSION['role'];
$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$can_filter_klinik = in_array($user_role, ['super_admin', 'admin_gudang']);

// Filters
$selected_klinik = $can_filter_klinik ? (isset($_GET['klinik_id']) ? $_GET['klinik_id'] : '') : $user_klinik_id;
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Date Calculations
$first_day = "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . "-01";
$last_day = date('Y-m-t', strtotime($first_day));
$total_days = (int)date('t', strtotime($first_day));

$today = date('Y-m-d');
$current_month = (int)date('n');
$current_year = (int)date('Y');

if ($selected_year < $current_year || ($selected_year == $current_year && $selected_month < $current_month)) {
    $days_passed = $total_days;
} elseif ($selected_year == $current_year && $selected_month == $current_month) {
    $days_passed = (int)date('j');
} else {
    $days_passed = 0;
}

$time_gone_percent = round(($days_passed / $total_days) * 100, 1);
$time_gone_days = "$days_passed" . "d of " . "$total_days" . "d";
$mtd_date = date('d M', strtotime("$selected_year-$selected_month-" . ($days_passed ?: 1)));
$mtd_label = date('M Y', strtotime($first_day));

// Fetch Clinics for dropdown
$cliniks = [];
if ($can_filter_klinik) {
    $res_k = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik");
    while ($row = $res_k->fetch_assoc()) $cliniks[] = $row;
}

// Data Fetching
$where_pb = "pb.status = 'active' AND pb.tanggal BETWEEN '$first_day' AND '$last_day'";
$where_bp_completed = "bp.status = 'completed' AND bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";
$where_bp_all_status = "bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";

if ($selected_klinik && $selected_klinik !== 'all') {
    $kid = (int)$selected_klinik;
    $where_pb .= " AND pb.klinik_id = $kid";
    $where_bp_completed .= " AND bp.klinik_id = $kid";
    $where_bp_all_status .= " AND bp.klinik_id = $kid";
}

// 1. Sellout Data
$sellout_query = "
    SELECT
        pbd.barang_id,
        SUM(CASE WHEN pb.jenis_pemakaian = 'klinik' THEN pbd.qty ELSE 0 END) as onsite,
        SUM(CASE WHEN pb.jenis_pemakaian = 'hc' THEN pbd.qty ELSE 0 END) as hc
    FROM inventory_pemakaian_bhp_detail pbd
    JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
    WHERE $where_pb
    GROUP BY pbd.barang_id
";
$res_sellout = $conn->query($sellout_query);
$sellout_data = [];
while ($row = $res_sellout->fetch_assoc()) {
    $sellout_data[$row['barang_id']] = $row;
}

// 2. Reserve Sold Data (status completed)
$reserve_sold_query = "
    SELECT
        bd.barang_id,
        SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE (CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as onsite,
        SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE (CASE WHEN bp.status_booking LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as hc
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE $where_bp_completed
    GROUP BY bd.barang_id
";
$res_reserve_sold = $conn->query($reserve_sold_query);
$reserve_sold_data = [];
while ($row = $res_reserve_sold->fetch_assoc()) {
    $reserve_sold_data[$row['barang_id']] = $row;
}

// 3. Reserve Booked Data (all statuses)
$reserve_booked_query = "
    SELECT
        bd.barang_id,
        SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE (CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as onsite,
        SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE (CASE WHEN bp.status_booking LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as hc
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE $where_bp_all_status
    GROUP BY bd.barang_id
";
$res_reserve_booked = $conn->query($reserve_booked_query);
$reserve_booked_data = [];
while ($row = $res_reserve_booked->fetch_assoc()) {
    $reserve_booked_data[$row['barang_id']] = $row;
}

// 4. Combine Data with Barang Master
$all_barang_ids = array_unique(array_merge(array_keys($sellout_data), array_keys($reserve_sold_data), array_keys($reserve_booked_data)));
$final_data = [];
$summary = [
    'sellout_onsite' => 0,
    'sellout_hc' => 0,
    'reserve_onsite' => 0, // This will be reserve_sold_onsite
    'reserve_hc' => 0,     // This will be reserve_sold_hc
    'reserve_booked_onsite' => 0,
    'reserve_booked_hc' => 0
];

if (!empty($all_barang_ids)) {
    $ids_str = implode(',', $all_barang_ids);
    $res_b = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang WHERE id IN ($ids_str) ORDER BY nama_barang");
    while ($b = $res_b->fetch_assoc()) {
        $bid = $b['id'];
        $s_onsite = (float)($sellout_data[$bid]['onsite'] ?? 0);
        $s_hc = (float)($sellout_data[$bid]['hc'] ?? 0);

        $rs_onsite = (float)($reserve_sold_data[$bid]['onsite'] ?? 0);
        $rs_hc = (float)($reserve_sold_data[$bid]['hc'] ?? 0);

        // Ensure sellout is at least equal to reserve_sold to avoid negative Non-Reserve values
        // If sellout is less than reserve_sold, it means the BHP record might be missing or date mismatch
        if ($s_onsite < $rs_onsite) $s_onsite = $rs_onsite;
        if ($s_hc < $rs_hc) $s_hc = $rs_hc;

        $s_total = $s_onsite + $s_hc;

        $rb_onsite = (float)($reserve_booked_data[$bid]['onsite'] ?? 0);
        $rb_hc = (float)($reserve_booked_data[$bid]['hc'] ?? 0);

        // Non-Reserve (Incl. Adjustment) = total sellout - Reserve-Sold
        $nr_onsite = $s_onsite - $rs_onsite;
        $nr_hc = $s_hc - $rs_hc;

        $final_data[] = [
            'kode_barang' => $b['kode_barang'],
            'nama_barang' => $b['nama_barang'],
            'satuan' => $b['satuan'],
            'sellout_total' => $s_total,
            'sellout_onsite' => $s_onsite,
            'sellout_hc' => $s_hc,
            'non_reserve_onsite' => $nr_onsite,
            'non_reserve_hc' => $nr_hc,
            'reserve_sold_onsite' => $rs_onsite,
            'reserve_sold_hc' => $rs_hc,
            'reserve_booked_onsite' => $rb_onsite,
            'reserve_booked_hc' => $rb_hc
        ];

        $summary['sellout_onsite'] += $s_onsite;
        $summary['sellout_hc'] += $s_hc;
        $summary['reserve_onsite'] += $rs_onsite; // This is now reserve_sold_onsite
        $summary['reserve_hc'] += $rs_hc;     // This is now reserve_sold_hc
        $summary['reserve_booked_onsite'] += $rb_onsite;
        $summary['reserve_booked_hc'] += $rb_hc;
    }
}
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --primary-color: #204EAB;
        --onsite-color: #1e293b;
        --hc-color: #1e293b;
        --reserve-color: #0891b2; /* Deep Cyan/Teal for high contrast */
        --bg-light: #f8fafc;
        --card-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }

    .monthly-summary-container {
        font-family: 'Poppins', sans-serif;
        color: #334155;
    }

    .page-header h1 {
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1.5rem;
    }

    /* Filter Bar */
    .filter-card {
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }

    /* Stat Cards */
    .stat-card {
        border: none;
        border-radius: 16px;
        padding: 1.25rem;
        background: #ffffff;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        height: 100%;
        display: flex;
        flex-direction: column;
        transition: transform 0.2s, box-shadow 0.2s;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-color);
        opacity: 0.5;
    }

    .stat-card.bg-reference::before {
        background: #0369a1;
    }

    .stat-card .stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.75rem;
        letter-spacing: 0.025em;
    }

    .stat-card .stat-value {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        color: #1e293b;
        margin-top: auto;
    }

    /* Enhanced Info Badges */
    .stat-info-badge {
        background: #ffffff;
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        transition: all 0.2s;
    }

    .stat-info-badge:hover {
        border-color: var(--primary-color);
        box-shadow: 0 4px 6px rgba(32, 78, 171, 0.1);
    }

    .stat-info-label {
        font-size: 0.7rem;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .stat-info-value {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e293b;
    }

    .stat-info-badge i {
        font-size: 1.25rem;
        color: var(--primary-color);
        background: #f0f4ff;
        padding: 0.5rem;
        border-radius: 8px;
    }

    .stat-card .stat-icon {
        font-size: 1.5rem;
        opacity: 0.2;
        color: var(--primary-color);
        position: absolute;
        right: 1.25rem;
        top: 1.25rem;
    }

    /* Table Styling */
    .table-recap-container {
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    .table-recap thead th {
        background-color: var(--primary-color);
        color: #ffffff;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        border: 1px solid rgba(255,255,255,0.2); /* Clearer border for header */
        padding: 0.85rem 1rem;
    }

    .table-recap tbody tr:hover {
        background-color: #f1f5f9;
    }

    .table-recap td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid #cbd5e1;
        border-right: 1px solid #cbd5e1; /* Darker vertical line */
        vertical-align: middle;
        font-size: 0.9rem;
    }

    .table-recap td:first-child {
        border-left: 1px solid #cbd5e1;
    }

    .bg-reference {
        background-color: #e0f2fe !important; /* Light blue for reference */
        color: #0369a1 !important; /* Dark blue text for data rows */
    }

    .bg-reference-onsite {
        background-color: #e0f2fe !important;
        color: #0369a1 !important;
    }

    .bg-reference-hc {
        background-color: #dbeafe !important; /* Slightly darker blue for HC reference */
        color: #1e40af !important;
    }

    .bg-onsite {
        background-color: #f0fdf4 !important; /* Soft Green for Onsite */
        color: #166534 !important;
    }

    .bg-hc {
        background-color: #fff7ed !important; /* Soft Orange for HC */
        color: #9a3412 !important;
    }

    .bg-total {
        background-color: #f8fafc !important; /* Soft Grey for Total */
        color: #1e293b !important;
    }

    .table-recap thead th.bg-onsite,
    .table-recap thead th.bg-hc,
    .table-recap thead th.bg-total,
    .table-recap thead th.bg-reference-onsite,
    .table-recap thead th.bg-reference-hc {
        color: #ffffff !important;
        border: 1px solid rgba(255,255,255,0.2) !important;
    }

    .val-zero { color: #94a3b8; font-weight: 400; }
    .val-nonzero { font-weight: 700; color: #1e293b; }

    .col-data-width {
        width: 85px !important;
        min-width: 85px !important;
        max-width: 85px !important;
    }

    .btn-refresh-odoo {
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        font-weight: 600;
        border-radius: 8px;
        background: #fff;
        transition: all 0.2s;
    }
    .btn-refresh-odoo:hover {
        background: var(--primary-color);
        color: #fff;
    }

    .input-group-text {
        background: transparent;
        border-right: none;
        color: var(--primary-color);
    }
    .form-control-with-icon {
        border-left: none;
    }
</style>

<div class="monthly-summary-container p-3">
    <!-- Page Header -->
    <div class="row mb-3 align-items-start">
        <div class="col page-header">
            <h1 class="mb-0">
                <i class="fas fa-hospital-user me-2"></i>Rekapitulasi Aktivitas Bulanan
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Rekap Bulanan</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card filter-card mb-4 border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col">
                    <form action="index.php" method="GET" class="row g-3 align-items-end">
                        <input type="hidden" name="page" value="monthly_summary">
                        
                        <?php if ($can_filter_klinik): ?>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-clinic-medical me-1 text-primary"></i> Klinik <span class="text-danger">*</span></label>
                            <select name="klinik_id" class="form-select select2 border-1">
                                <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                                <?php foreach ($cliniks as $k): ?>
                                    <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-calendar-alt me-1 text-primary"></i> Bulan</label>
                            <select name="month" class="form-select border-1">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $selected_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-calendar me-1 text-primary"></i> Tahun</label>
                            <select name="year" class="form-select border-1">
                                <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                    <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-auto">
                            <button type="submit" class="btn btn-primary px-4 fw-bold">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-auto d-flex gap-3">
                    <div class="stat-info-badge">
                        <i class="fas fa-hourglass-half"></i>
                        <div>
                            <div class="stat-info-label">Time Gone</div>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="stat-info-value"><?= $time_gone_percent ?>%</span>
                                <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;"><?= $days_passed ?> / <?= $total_days ?> days</span>
                            </div>
                        </div>
                    </div>
                    <div class="stat-info-badge">
                        <i class="fas fa-calendar-check"></i>
                        <div>
                            <div class="stat-info-label">Current Date (MTD)</div>
                            <div class="stat-info-value"><?= $mtd_date ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat Totals -->
    <div class="row g-2 mb-4">
        <!-- Total Sellout Onsite -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Sellout Onsite</div>
                    <i class="fas fa-history stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['sellout_onsite']) ?></div>
            </div>
        </div>
        <!-- Total Sellout HC -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Sellout HC</div>
                    <i class="fas fa-user-md stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['sellout_hc']) ?></div>
            </div>
        </div>
        <!-- Total Reserve Sold Onsite -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve Sold Onsite</div>
                    <i class="fas fa-city stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['reserve_onsite']) ?></div>
            </div>
        </div>
        <!-- Total Reserve Sold HC -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve Sold HC</div>
                    <i class="fas fa-user-nurse stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['reserve_hc']) ?></div>
            </div>
        </div>
        <!-- Total Reserve Booked Onsite -->
        <div class="col">
            <div class="stat-card bg-reference" style="border-color: #bae6fd;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve Booked Onsite</div>
                    <i class="fas fa-calendar-alt stat-icon" style="color: #0369a1;"></i>
                </div>
                <div class="stat-value" style="color: #0369a1;"><?= fmt_qty($summary['reserve_booked_onsite']) ?></div>
            </div>
        </div>
        <!-- Total Reserve Booked HC -->
        <div class="col">
            <div class="stat-card bg-reference" style="border-color: #bae6fd;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve Booked HC</div>
                    <i class="fas fa-calendar-check stat-icon" style="color: #0369a1;"></i>
                </div>
                <div class="stat-value" style="color: #0369a1;"><?= fmt_qty($summary['reserve_booked_hc']) ?></div>
            </div>
        </div>
    </div>

    <!-- Table Recap -->
    <div class="card border-0 shadow-sm p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold" id="btnExportExcel">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
            </div>
            <div class="d-flex align-items-center">
                <label class="small fw-bold text-muted me-2 mb-0">Cari:</label>
                <input type="text" id="customSearch" class="form-control form-control-sm" style="width: 200px;">
            </div>
        </div>
        <div class="table-recap-container">
            <div class="table-responsive">
                <table class="table table-recap mb-0" id="tableSummary">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 100px;">Kode Barang</th>
                            <th rowspan="2">Nama Barang</th>
                            <th rowspan="2" class="text-center">Satuan</th>
                            <th colspan="3" class="text-center">Sellout</th>
                            <th colspan="2" class="text-center">Non-Reserve<br><small style="text-transform: none; opacity: 0.8;">(Incl. Adjustment)</small></th>
                            <th colspan="2" class="text-center">Reserve-Sold</th>
                            <th colspan="2" class="text-center bg-reference">Reserve-Booked</th>
                        </tr>
                        <tr>
                            <th class="text-center bg-total col-data-width">Total</th>
                            <th class="text-center bg-onsite col-data-width">Onsite</th>
                            <th class="text-center bg-hc col-data-width">HC</th>
                            <th class="text-center bg-onsite col-data-width">Onsite</th>
                            <th class="text-center bg-hc col-data-width">HC</th>
                            <th class="text-center bg-onsite col-data-width">Onsite</th>
                            <th class="text-center bg-hc col-data-width">HC</th>
                            <th class="text-center bg-reference-onsite col-data-width">Onsite</th>
                            <th class="text-center bg-reference-hc col-data-width">HC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($final_data as $row): ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars($row['kode_barang']) ?></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="text-center small text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                            
                            <!-- Sellout Total -->
                            <td class="text-center bg-total col-data-width <?= $row['sellout_total'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['sellout_total']) ?>
                            </td>
                            <!-- Sellout Onsite -->
                            <td class="text-center bg-onsite col-data-width <?= $row['sellout_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['sellout_onsite']) ?>
                            </td>
                            <!-- Sellout HC -->
                            <td class="text-center bg-hc col-data-width <?= $row['sellout_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['sellout_hc']) ?>
                            </td>
                            
                            <!-- Non-Reserve Onsite -->
                            <td class="text-center bg-onsite col-data-width <?= $row['non_reserve_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['non_reserve_onsite']) ?>
                            </td>
                            <!-- Non-Reserve HC -->
                            <td class="text-center bg-hc col-data-width <?= $row['non_reserve_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['non_reserve_hc']) ?>
                            </td>
                
                            <!-- Reserve Sold Onsite -->
                            <td class="text-center bg-onsite col-data-width <?= $row['reserve_sold_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['reserve_sold_onsite']) ?>
                            </td>
                            <!-- Reserve Sold HC -->
                            <td class="text-center bg-hc col-data-width <?= $row['reserve_sold_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['reserve_sold_hc']) ?>
                            </td>
                
                            <!-- Reserve Booked Onsite -->
                            <td class="text-center bg-reference-onsite col-data-width <?= $row['reserve_booked_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['reserve_booked_onsite']) ?>
                            </td>
                            <!-- Reserve Booked HC -->
                            <td class="text-center bg-reference-hc col-data-width <?= $row['reserve_booked_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['reserve_booked_hc']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Excel Export Library with Style Support -->
<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
$(document).ready(function() {
    // Initialise Select2 if available
    if ($.fn.select2) {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    }

    $('#btnExportExcel').on('click', function() {
        const data = <?= json_encode($final_data) ?>;
        const ws_data = [];

        // Add main header row
        ws_data.push([
            "Kode Barang", "Nama Barang", "Satuan",
            "Sellout", "", "", // Sellout (Total, Onsite, HC)
            "Non-Reserve (Incl. Adjustment)", "", // Non-Reserve (Onsite, HC)
            "Reserve-Sold", "", // Reserve-Sold (Onsite, HC)
            "Reserve-Booked", "" // Reserve-Booked (Onsite, HC)
        ]);

        // Add sub-header row
        ws_data.push([
            "", "", "",
            "Total", "Onsite", "HC",
            "Onsite", "HC",
            "Onsite", "HC",
            "Onsite", "HC"
        ]);

        // Add data rows
        data.forEach(row => {
            ws_data.push([
                row.kode_barang,
                row.nama_barang,
                row.satuan,
                row.sellout_total,
                row.sellout_onsite,
                row.sellout_hc,
                row.non_reserve_onsite,
                row.non_reserve_hc,
                row.reserve_sold_onsite,
                row.reserve_sold_hc,
                row.reserve_booked_onsite,
                row.reserve_booked_hc
            ]);
        });

        const ws = XLSX.utils.aoa_to_sheet(ws_data);

        // Styling and formatting
        const range = XLSX.utils.decode_range(ws['!ref']);
        
        // Merge cells for header
        ws['!merges'] = [
            { s: { r: 0, c: 0 }, e: { r: 1, c: 0 } }, // Kode Barang
            { s: { r: 0, c: 1 }, e: { r: 1, c: 1 } }, // Nama Barang
            { s: { r: 0, c: 2 }, e: { r: 1, c: 2 } }, // Satuan
            { s: { r: 0, c: 3 }, e: { r: 0, c: 5 } }, // Sellout
            { s: { r: 0, c: 6 }, e: { r: 0, c: 7 } }, // Non-Reserve
            { s: { r: 0, c: 8 }, e: { r: 0, c: 9 } }, // Reserve-Sold
            { s: { r: 0, c: 10 }, e: { r: 0, c: 11 } } // Reserve-Booked
        ];

        // Apply styles to all cells
        for (let R = range.s.r; R <= range.e.r; ++R) {
            for (let C = range.s.c; C <= range.e.c; ++C) {
                const cell_address = { c: C, r: R };
                const cell_ref = XLSX.utils.encode_cell(cell_address);
                if (!ws[cell_ref]) continue;

                // Default style
                ws[cell_ref].s = {
                    font: { name: "Arial", sz: 10 },
                    alignment: { vertical: "center", horizontal: "center" },
                    border: {
                        top: { style: "thin", color: { rgb: "cbd5e1" } },
                        bottom: { style: "thin", color: { rgb: "cbd5e1" } },
                        left: { style: "thin", color: { rgb: "cbd5e1" } },
                        right: { style: "thin", color: { rgb: "cbd5e1" } }
                    }
                };

                // Header styles (Row 0 and 1)
                if (R <= 1) {
                    ws[cell_ref].s.fill = { fgColor: { rgb: "204EAB" } };
                    ws[cell_ref].s.font = { color: { rgb: "FFFFFF" }, bold: true, sz: 10 };
                    
                    // Specific sub-header colors (Row 1)
                    if (R === 1) {
                        // Total
                        if (C === 3) ws[cell_ref].s.fill = { fgColor: { rgb: "64748B" } }; // Soft Grey
                        // Onsite
                        if ([4, 6, 8, 10].includes(C)) ws[cell_ref].s.fill = { fgColor: { rgb: "166534" } }; // Soft Green
                        // HC
                        if ([5, 7, 9, 11].includes(C)) ws[cell_ref].s.fill = { fgColor: { rgb: "9A3412" } }; // Soft Orange
                    }
                }

                // Color coding for data rows (R > 1)
                if (R > 1) {
                    // Total Column
                    if (C === 3) ws[cell_ref].s.fill = { fgColor: { rgb: "F8FAFC" } };
                    
                    // Onsite Columns (Non-reference)
                    if ([4, 6, 8].includes(C)) ws[cell_ref].s.fill = { fgColor: { rgb: "F0FDF4" } };
                    
                    // HC Columns (Non-reference)
                    if ([5, 7, 9].includes(C)) ws[cell_ref].s.fill = { fgColor: { rgb: "FFF7ED" } };

                    // Reserve-Booked (Reference) Columns
                    if (C === 10) ws[cell_ref].s.fill = { fgColor: { rgb: "E0F2FE" } }; // Onsite Reference
                    if (C === 11) ws[cell_ref].s.fill = { fgColor: { rgb: "DBEAFE" } }; // HC Reference
                    
                    // Font colors for nonzero values
                    const val = ws[cell_ref].v;
                    if (C >= 3 && typeof val === 'number' && val > 0) {
                        ws[cell_ref].s.font.bold = true;
                        if ([4, 6, 8, 10].includes(C)) ws[cell_ref].s.font.color = { rgb: "166534" }; // Onsite Text
                        if ([5, 7, 9, 11].includes(C)) ws[cell_ref].s.font.color = { rgb: "9A3412" }; // HC Text
                        if (C >= 10) ws[cell_ref].s.font.color = { rgb: "0369A1" }; // Reference Text
                    } else if (C >= 3) {
                        ws[cell_ref].s.font.color = { rgb: "94A3B8" }; // Zero Text
                    }
                }

                // Align Nama Barang to left
                if (C === 1 && R > 1) {
                    ws[cell_ref].s.alignment.horizontal = "left";
                }
            }
        }

        // Set column widths
        ws['!cols'] = [
            { wch: 15 }, // Kode Barang
            { wch: 40 }, // Nama Barang
            { wch: 10 }, // Satuan
            { wch: 10 }, // Sellout Total
            { wch: 10 }, // Sellout Onsite
            { wch: 10 }, // Sellout HC
            { wch: 15 }, // Non-Reserve Onsite
            { wch: 15 }, // Non-Reserve HC
            { wch: 15 }, // Reserve-Sold Onsite
            { wch: 15 }, // Reserve-Sold HC
            { wch: 15 }, // Reserve-Booked Onsite
            { wch: 15 }  // Reserve-Booked HC
        ];

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Monthly Summary");
        const fileName = "Monthly_Summary_<?= str_replace(' ', '_', $mtd_label) ?>_<?= date('His') ?>.xlsx";
        
        // Use the XLSX object from xlsx-js-style library which supports .s (style)
        XLSX.writeFile(wb, fileName);
    });
});
</script>
