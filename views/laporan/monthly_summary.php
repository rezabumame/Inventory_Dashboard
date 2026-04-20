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
$where_pb = "pb.status = 'active' AND pb.created_at BETWEEN '$first_day 00:00:00' AND '$last_day 23:59:59'";
$where_bp = "bp.status = 'completed' AND bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";

if ($selected_klinik && $selected_klinik !== 'all') {
    $kid = (int)$selected_klinik;
    $where_pb .= " AND pb.klinik_id = $kid";
    $where_bp .= " AND bp.klinik_id = $kid";
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

// 2. Reserve Data
$reserve_query = "
    SELECT 
        bd.barang_id,
        SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE (CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as onsite,
        SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE (CASE WHEN bp.status_booking LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as hc
    FROM inventory_booking_detail bd
    JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
    WHERE $where_bp
    GROUP BY bd.barang_id
";
$res_reserve = $conn->query($reserve_query);
$reserve_data = [];
while ($row = $res_reserve->fetch_assoc()) {
    $reserve_data[$row['barang_id']] = $row;
}

// 3. Combine Data with Barang Master
$all_barang_ids = array_unique(array_merge(array_keys($sellout_data), array_keys($reserve_data)));
$final_data = [];
$summary = [
    'sellout_onsite' => 0,
    'sellout_hc' => 0,
    'reserve_onsite' => 0,
    'reserve_hc' => 0
];

if (!empty($all_barang_ids)) {
    $ids_str = implode(',', $all_barang_ids);
    $res_b = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang WHERE id IN ($ids_str) ORDER BY nama_barang");
    while ($b = $res_b->fetch_assoc()) {
        $bid = $b['id'];
        $s_onsite = (float)($sellout_data[$bid]['onsite'] ?? 0);
        $s_hc = (float)($sellout_data[$bid]['hc'] ?? 0);
        $r_onsite = (float)($reserve_data[$bid]['onsite'] ?? 0);
        $r_hc = (float)($reserve_data[$bid]['hc'] ?? 0);

        $final_data[] = [
            'kode_barang' => $b['kode_barang'],
            'nama_barang' => $b['nama_barang'],
            'satuan' => $b['satuan'],
            'sellout_onsite' => $s_onsite,
            'sellout_hc' => $s_hc,
            'reserve_onsite' => $r_onsite,
            'reserve_hc' => $r_hc
        ];

        $summary['sellout_onsite'] += $s_onsite;
        $summary['sellout_hc'] += $s_hc;
        $summary['reserve_onsite'] += $r_onsite;
        $summary['reserve_hc'] += $r_hc;
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
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.85rem 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        background: #fff;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: flex-start; /* Push content to top */
    }

    .stat-card .stat-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.5rem;
    }

    .stat-card .stat-value {
        font-size: 1.75rem; /* Larger font size */
        font-weight: 700;
        line-height: 1;
        color: #1e293b;
    }

    .stat-card .stat-icon {
        font-size: 1.1rem;
        opacity: 0.4;
        color: var(--primary-color);
    }

    /* Table Styling */
    .table-recap-container {
        border: 1px solid #e2e8f0;
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
        border: none;
        padding: 0.85rem 1rem;
    }

    .table-recap tbody tr:hover {
        background-color: #f8fafc;
    }

    .table-recap td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-size: 0.9rem;
    }

    .val-zero { color: #cbd5e1; font-weight: 400; }
    .val-nonzero { font-weight: 700; color: #1e293b; }

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
                <div class="col-auto text-end">
                    <button type="button" class="btn btn-refresh-odoo px-4 py-2 mb-1" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Data
                    </button>
                    <div class="small text-muted" style="font-size: 0.7rem;">Terakhir update: <?= date('d M Y H:i') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat Totals -->
    <div class="row g-2 mb-4">
        <!-- Time Gone -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Time Gone</div>
                    <i class="fas fa-hourglass-half stat-icon"></i>
                </div>
                <div class="stat-value"><?= $time_gone_percent ?>%</div>
                <div class="small text-muted mt-1" style="font-size: 0.75rem;"><?= $time_gone_days ?></div>
            </div>
        </div>
        <!-- MTD -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">MTD (Month To Date)</div>
                    <i class="fas fa-calendar-check stat-icon"></i>
                </div>
                <div class="stat-value"><?= $mtd_date ?></div>
            </div>
        </div>
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
        <!-- Total Reserve Onsite -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve Onsite</div>
                    <i class="fas fa-city stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['reserve_onsite']) ?></div>
            </div>
        </div>
        <!-- Total Reserve HC -->
        <div class="col">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="stat-label">Reserve HC</div>
                    <i class="fas fa-user-nurse stat-icon"></i>
                </div>
                <div class="stat-value"><?= fmt_qty($summary['reserve_hc']) ?></div>
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
                            <th style="width: 100px;">Kode Barang</th>
                            <th>Nama Barang</th>
                            <th class="text-center">Satuan</th>
                            <th class="text-center">Sellout Onsite</th>
                            <th class="text-center">Sellout HC</th>
                            <th class="text-center">Reserve Onsite</th>
                            <th class="text-center">Reserve HC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($final_data as $row): ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars($row['kode_barang']) ?></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="text-center small text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                            
                            <!-- Sellout Onsite -->
                            <td class="text-center <?= $row['sellout_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['sellout_onsite']) ?>
                            </td>
                            
                            <!-- Sellout HC -->
                            <td class="text-center <?= $row['sellout_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                                <?= fmt_qty($row['sellout_hc']) ?>
                            </td>
                            
                            <!-- Reserve Onsite -->
                        <td class="text-center <?= $row['reserve_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                            <?= fmt_qty($row['reserve_onsite']) ?>
                        </td>
                        
                        <!-- Reserve HC -->
                        <td class="text-center <?= $row['reserve_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>">
                            <?= fmt_qty($row['reserve_hc']) ?>
                        </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
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
        const table = document.getElementById("tableSummary");
        
        // Custom transformation for export to keep it clean
        const wb = XLSX.utils.table_to_book(table, { sheet: "Monthly Summary" });
        const fileName = "Monthly_Summary_<?= str_replace(' ', '_', $mtd_label) ?>_<?= date('His') ?>.xlsx";
        XLSX.writeFile(wb, fileName);
    });
});
</script>
