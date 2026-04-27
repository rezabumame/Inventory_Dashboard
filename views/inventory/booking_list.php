<?php

check_role(['cs', 'super_admin', 'admin_klinik']);
require_once __DIR__ . '/../../lib/stock.php';





// Normalize nomor_booking to a shorter format (not critical identifier; ID is the primary key)
$need_norm = 0;
$r_norm = $conn->query("SELECT COUNT(*) AS cnt FROM inventory_booking_pemeriksaan WHERE (nomor_booking IS NULL OR nomor_booking = '' OR nomor_booking LIKE 'BOOK/%')");
if ($r_norm && $r_norm->num_rows > 0) $need_norm = (int)($r_norm->fetch_assoc()['cnt'] ?? 0);
if ($need_norm > 0) {
    $conn->query("
        UPDATE inventory_booking_pemeriksaan
        SET nomor_booking = CONCAT('BK-', LPAD(id, 6, '0'))
        WHERE (nomor_booking IS NULL OR nomor_booking = '' OR nomor_booking LIKE 'BOOK/%')
    ");
}

// Filter hari ini
$filter_today = isset($_GET['filter_today']) ? ($_GET['filter_today'] == '1') : false;
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$role = (string)($_SESSION['role'] ?? '');
$filter_tujuan = (string)($_GET['tujuan'] ?? '');
$filter_status = (string)($_GET['status'] ?? '');
$filter_tipe = (string)($_GET['tipe'] ?? '');
$filter_fu = (string)($_GET['fu'] ?? '');
$filter_start = (string)($_GET['start_date'] ?? '');
$filter_end = (string)($_GET['end_date'] ?? '');
$filter_q = trim((string)($_GET['q'] ?? ''));
$has_filters = ($show_all || isset($_GET['filter_today']) || $filter_tujuan !== '' || $filter_status !== '' || $filter_tipe !== '' || $filter_fu !== '' || $filter_start !== '' || $filter_end !== '' || $filter_q !== '');
if (!$has_filters) {
    if ($role === 'admin_klinik') {
        $filter_today = true;
    } else {
        $show_all = true;
        $filter_today = false;
    }
}
if ($show_all) $filter_today = false;
$reset_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';

// Fetch Bookings
$where = "1=1";
if ($_SESSION['role'] == 'admin_klinik') {
    $where .= " AND b.klinik_id = " . $_SESSION['klinik_id'];
    // Allow admin_klinik to see completed bookings as well
    $where .= " AND b.status IN ('booked', 'rescheduled', 'completed') AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
}
if ($filter_q !== '') {
    $esc_q = $conn->real_escape_string($filter_q);
    $where .= " AND (b.nama_pemesan LIKE '%$esc_q%' OR b.nomor_booking LIKE '%$esc_q%')";
}
if ($filter_today) {
    $where .= " AND b.tanggal_pemeriksaan = CURDATE()";
}
if ($filter_start !== '') {
    $where .= " AND b.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_start) . "'";
}
if ($filter_end !== '') {
    $where .= " AND b.tanggal_pemeriksaan <= '" . $conn->real_escape_string($filter_end) . "'";
}
if ($filter_tujuan === 'clinic') {
    $where .= " AND b.status_booking LIKE '%Clinic%'";
} elseif ($filter_tujuan === 'hc') {
    $where .= " AND b.status_booking LIKE '%HC%'";
}
if (in_array($filter_status, ['booked', 'rescheduled', 'completed', 'cancelled'], true)) {
    $where .= " AND b.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if (in_array($filter_tipe, ['keep', 'fixed', 'cancel'], true)) {
    $where .= " AND LOWER(COALESCE(b.booking_type, 'keep')) = '" . $conn->real_escape_string($filter_tipe) . "'";
}
if ($filter_fu === '1') {
    $where .= " AND b.status = 'booked' AND b.butuh_fu = 1";
}


// 1. Get total count
$count_query = "SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan b WHERE $where";
$total_all = (int)($conn->query($count_query)->fetch_assoc()['cnt'] ?? 0);
// $total_pages = ceil($total_all / $items_per_page);

$query = "SELECT b.*, k.nama_klinik, u.nama_lengkap as creator_name,
          (SELECT COUNT(DISTINCT bd.barang_id) FROM inventory_booking_detail bd WHERE bd.booking_id = b.id) as total_items,
          (SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ')
           FROM inventory_booking_pasien bp
           JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
           WHERE bp.booking_id = b.id) as jenis_pemeriksaan
          FROM inventory_booking_pemeriksaan b 
          JOIN inventory_klinik k ON b.klinik_id = k.id 
          LEFT JOIN inventory_users u ON b.created_by = u.id
          WHERE $where
          ORDER BY b.tanggal_pemeriksaan DESC, COALESCE(b.jam_layanan, '') DESC, b.id DESC";
$result = $conn->query($query);

// Pre-calculate current fulfillment status for displayed bookings
$bookings_data = [];
$booking_ids = [];
while ($row = $result->fetch_assoc()) {
    $bookings_data[] = $row;
    if (in_array($row['status'], ['booked', 'rescheduled'])) $booking_ids[] = (int)$row['id'];
}

$fulfillment_map = [];
if (!empty($booking_ids)) {
    $ids_str = implode(',', $booking_ids);
    // Get mandatory items needed for these bookings
    $res_need = $conn->query("
        SELECT bd.booking_id, bd.barang_id, SUM(bd.qty_gantung) as total_qty
        FROM inventory_booking_detail bd
        WHERE bd.booking_id IN ($ids_str)
        GROUP BY bd.booking_id, bd.barang_id
    ");
    
    $needs_by_booking = [];
    while ($rn = $res_need->fetch_assoc()) {
        $needs_by_booking[(int)$rn['booking_id']][] = [
            'barang_id' => (int)$rn['barang_id'],
            'qty' => (float)$rn['total_qty']
        ];
    }

    foreach ($bookings_data as $b) {
        $bid = (int)$b['id'];
        if ($b['status'] !== 'booked' || !isset($needs_by_booking[$bid])) continue;
        
        $is_hc = (stripos($b['status_booking'], 'HC') !== false);
        $klinik_id = (int)$b['klinik_id'];
        $is_still_short = false;
        $short_items = [];

        foreach ($needs_by_booking[$bid] as $item) {
            $ef = stock_effective($conn, $klinik_id, $is_hc, $item['barang_id']);
            if ($ef['ok'] && $ef['on_hand'] < $item['qty']) { // Menggunakan on_hand untuk cek pemenuhan booking
                $is_still_short = true;
                $short_items[] = $ef['barang_name'] . " (Butuh: " . fmt_qty($item['qty']) . ", Sisa: " . fmt_qty($ef['on_hand']) . ")";
            }
        }
        $fulfillment_map[$bid] = [
            'is_short' => $is_still_short,
            'short_items' => implode(', ', $short_items)
        ];
    }
}
?>

<div class="container-fluid">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-calendar-check me-2"></i>Booking & Stok Pending
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Booking</li>
                </ol>
            </nav>
        </div>
        <?php if (in_array($_SESSION['role'], ['super_admin', 'cs'])): ?>
        <div class="col-auto">
            <button type="button" class="btn shadow-sm text-white px-4" style="background-color: #204EAB;" data-bs-toggle="modal" data-bs-target="#modalBookingBaru">
                <i class="fas fa-plus me-2"></i>Booking Baru
            </button>
        </div>
        <?php endif; ?>
        <div class="col-auto">
            <?php 
                $all_url = 'index.php?page=booking&show_all=1';
                $today_url = 'index.php?page=booking&filter_today=1';
            ?>
            <?php if ($filter_today): ?>
                <a href="<?= $all_url ?>" class="btn btn-outline-secondary px-4">
                    <i class="fas fa-list me-2"></i>Tampilkan Semua
                </a>
            <?php else: ?>
                <a href="<?= $today_url ?>" class="btn shadow-sm px-4" style="background-color: #20AB5C; color: white;">
                    <i class="fas fa-calendar-day me-2"></i>Hari Ini Saja
                </a>
            <?php endif; ?>
        </div>
    </div>

<style>
    .booking-table-responsive { overflow-x: auto; }
    
    /* CIRCULAR PAGINATION STYLING */
    .pagination-circular .page-item { margin: 0 4px; }
    .pagination-circular .page-link {
        border-radius: 50% !important;
        width: 40px; height: 40px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid #e2e8f0; color: #64748b; font-weight: 500;
        transition: all 0.2s ease; background: #fff;
    }
    .pagination-circular .page-link:hover { background-color: #f8fafc; color: #204EAB; border-color: #204EAB; }
    .pagination-circular .page-item.active .page-link { background-color: #eff6ff !important; color: #204EAB !important; border-color: #bfdbfe !important; font-weight: 700; }
    .pagination-circular .page-item.disabled .page-link { background-color: #fff; color: #cbd5e1; border-color: #f1f5f9; opacity: 0.6; }

    #bookingTable_wrapper .dropdown-menu { z-index: 2000; max-height: 260px; overflow: auto; }
    .booking-filter-card { border: 1px solid rgba(0,0,0,.06); background: #ffffff; }
    .booking-filter-card .form-label { font-size: .78rem; letter-spacing: .02em; }
    .booking-chip { font-size: .78rem; border: 1px solid rgba(0,0,0,.08); }
    .booking-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; background: #f8fafc; }
    .booking-table td { vertical-align: top; }
    .booking-muted { color: #6c757d; font-size: .85rem; }
    .booking-primary { color: #204EAB; }
    .booking-no { max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: bottom; }
    .booking-table th:last-child { position: sticky; right: 0; background: #f8fafc; }
    .booking-table td:last-child { position: sticky; right: 0; background: #fff; vertical-align: middle; }
    .booking-table thead th:last-child { z-index: 7; }
    .booking-table tbody td:last-child { z-index: 6; }
    
    /* Remove old Action Button logic */
    .booking-table .dropdown-menu { 
        position: absolute;
        margin: 0;
    }

    /* Action Drawer Styling - Compact 2 Rows */
    .action-drawer {
        height: 0;
        overflow: hidden;
        transition: all 0.3s ease-out;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 0;
        justify-content: flex-end;
        width: 110px; /* Force wrapping into 2 rows */
    }
    .action-drawer.open {
        height: auto;
        margin-top: 8px;
        padding-bottom: 4px;
    }
    .btn-drawer-icon {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 0.85rem;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
        background: #fff;
    }
    .btn-drawer-icon:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .btn-aksi-toggle {
        transition: all 0.2s;
        width: 100%;
        display: block;
    }
    .btn-aksi-toggle.active {
        background-color: #64748b !important;
        border-color: #64748b !important;
    }
    .btn-aksi-toggle.active i {
        transform: rotate(180deg);
    }
    .btn-view-eye {
        margin-bottom: 6px;
        width: 100%;
    }

    /* Segmented Control Styling */
    .segmented-control {
        display: flex;
        background-color: #f1f3f5;
        border-radius: 8px;
        padding: 4px;
        border: 1px solid #dee2e6;
    }
    .segmented-control .btn-check:checked + .btn-segmented {
        background-color: #fff;
        color: #204EAB;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-color: transparent;
        font-weight: 600;
    }
    .btn-segmented {
        flex: 1;
        border: none;
        background: transparent;
        color: #6c757d;
        font-size: 0.8rem;
        padding: 4px 6px;
        border-radius: 6px;
        transition: all 0.2s ease;
        text-align: center;
        cursor: pointer;
        white-space: nowrap;
    }
    .btn-segmented:hover {
        background-color: rgba(0,0,0,0.03);
    }
    .text-reserve-hc { color: #ffc107 !important; }
    .exam-row-item td { padding: 12px 8px; vertical-align: middle; }
    .assign-checkbox-wrapper { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .assign-tag { 
        display: inline-flex; 
        align-items: center; 
        padding: 4px 10px; 
        background: #f8f9fa; 
        border: 1px solid #dee2e6; 
        border-radius: 20px; 
        font-size: 0.75rem; 
        cursor: pointer;
        transition: all 0.2s;
        user-select: none;
    }
    .assign-tag:hover { background: #e9ecef; }
    .assign-tag input { display: none; }
    .assign-tag.active { 
        background: #204EAB; 
        border-color: #204EAB; 
        color: white; 
    }
    .assign-tag.disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .x-small { font-size: 0.75rem !important; }

    /* REDESIGN: Patient Pax Cards */
    .pax-section-card {
        border: 1px solid #e2e8f0 !important;
        border-left: 5px solid #204EAB !important;
        border-radius: 12px !important;
        transition: transform 0.2s, box-shadow 0.2s;
        overflow: hidden;
    }
    .pax-section-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(32, 78, 171, 0.12) !important;
    }
    .pax-card-header {
        background: #fff !important;
        border-bottom: 1px solid #f1f5f9 !important;
        padding: 12px 16px !important;
    }
    .pax-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
    }
    .pax-title i {
        color: #204EAB;
        margin-right: 10px;
        font-size: 1rem;
    }
    .pax-label-minimal {
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: block;
    }
    .pax-exam-container {
        background: #f8fafc;
        border-radius: 10px;
        padding: 12px;
        border: 1px solid #f1f5f9;
    }
    .pax-exam-label {
        font-size: 0.75rem;
        font-weight: 800;
        color: #20AB5C;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .pax-exam-label i { margin-right: 6px; }

    /* Hide Spin Buttons */
    input::-webkit-outer-spin-button,
    input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
</style>

<div class="card booking-filter-card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="booking">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Cari Nama / No Booking</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Ketik nama atau nomor..." value="<?= htmlspecialchars($filter_q) ?>">
                </div>
            </div>
            <div class="col-xl-2 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Tujuan</label>
                <div class="segmented-control">
                    <input type="radio" class="btn-check" name="tujuan" id="filter_tujuan_all" value="" <?= $filter_tujuan === '' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tujuan_all">Semua</label>
                    
                    <input type="radio" class="btn-check" name="tujuan" id="filter_tujuan_clinic" value="clinic" <?= $filter_tujuan === 'clinic' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tujuan_clinic">Klinik</label>
                    
                    <input type="radio" class="btn-check" name="tujuan" id="filter_tujuan_hc" value="hc" <?= $filter_tujuan === 'hc' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tujuan_hc">HC</label>
                </div>
            </div>
            <div class="col-xl-4 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Status</label>
                <div class="segmented-control">
                    <input type="radio" class="btn-check" name="status" id="filter_status_all" value="" <?= $filter_status === '' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_all">Semua</label>
                    
                    <input type="radio" class="btn-check" name="status" id="filter_status_booked" value="booked" <?= $filter_status === 'booked' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_booked">Booked</label>
                    
                    <input type="radio" class="btn-check" name="status" id="filter_status_completed" value="completed" <?= $filter_status === 'completed' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_completed">Completed</label>
                    
                    <input type="radio" class="btn-check" name="status" id="filter_status_rescheduled" value="rescheduled" <?= $filter_status === 'rescheduled' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_rescheduled">Reschedule</label>

                    <input type="radio" class="btn-check" name="status" id="filter_status_cancelled" value="cancelled" <?= $filter_status === 'cancelled' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_cancelled">Cancel</label>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Tipe</label>
                <div class="segmented-control">
                    <input type="radio" class="btn-check" name="tipe" id="filter_tipe_all" value="" <?= $filter_tipe === '' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tipe_all">Semua</label>
                    
                    <input type="radio" class="btn-check" name="tipe" id="filter_tipe_keep" value="keep" <?= $filter_tipe === 'keep' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tipe_keep">Keep</label>
                    
                    <input type="radio" class="btn-check" name="tipe" id="filter_tipe_fixed" value="fixed" <?= $filter_tipe === 'fixed' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tipe_fixed">Fixed</label>
                    
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Follow-up</label>
                <div class="segmented-control">
                    <input type="radio" class="btn-check" name="fu" id="filter_fu_all" value="" <?= $filter_fu === '' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_fu_all">Semua</label>
                    
                    <input type="radio" class="btn-check" name="fu" id="filter_fu_ya" value="1" <?= $filter_fu === '1' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_fu_ya">Ya</label>
                </div>
            </div>
            <div class="col-12 w-100 mt-2 mb-1 border-top" style="border-top-color: rgba(0,0,0,0.05) !important;"></div>
            <div class="col-lg-5 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Range Tanggal</label>
                <div class="row g-2">
                    <div class="col-6">
                        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($filter_start) ?>">
                    </div>
                    <div class="col-6">
                        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($filter_end) ?>">
                    </div>
                </div>
            </div>
            <div class="col-lg-7 col-md-6 d-flex flex-wrap justify-content-md-end justify-content-start gap-2 h-100">
                <?php if ($has_filters): ?>
                    <a href="<?= $reset_url ?>" class="btn btn-outline-secondary px-3"><i class="fas fa-undo me-1"></i> Reset</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary px-4"><i class="fas fa-filter me-1"></i> Filter</button>
                <button type="button" class="btn btn-success px-3" onclick="exportExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export
                </button>
            </div>
        </form>
        
        <script>
        function exportExcel() {
            const form = document.querySelector('form[method="GET"]');
            const url = new URL('actions/export_booking.php', window.location.href);
            
            // Get values safely from radio buttons and other inputs
            const getVal = (name) => {
                const el = form.elements[name];
                if (el instanceof RadioNodeList) return el.value;
                return el ? el.value : '';
            };

            url.searchParams.append('start_date', getVal('start_date'));
            url.searchParams.append('end_date', getVal('end_date'));
            url.searchParams.append('tujuan', getVal('tujuan'));
            url.searchParams.append('status', getVal('status'));
            url.searchParams.append('tipe', getVal('tipe'));
            url.searchParams.append('fu', getVal('fu'));
            url.searchParams.append('q', getVal('q'));
            
            <?php if ($filter_today): ?>
            url.searchParams.append('filter_today', '1');
            <?php endif; ?>
            <?php if ($show_all): ?>
            url.searchParams.append('show_all', '1');
            <?php endif; ?>
            
            window.open(url.toString(), '_blank');
        }
        </script>




        <?php if ($has_filters): ?>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <?php if ($filter_today): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Hari ini</span><?php endif; ?>
                <?php if ($filter_tujuan === 'clinic'): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Tujuan: Klinik</span><?php endif; ?>
                <?php if ($filter_tujuan === 'hc'): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Tujuan: HC</span><?php endif; ?>
                <?php if ($filter_status !== ''): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Status: <?= htmlspecialchars($filter_status) ?></span><?php endif; ?>
                <?php if ($filter_tipe !== ''): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Tipe: <?= htmlspecialchars($filter_tipe) ?></span><?php endif; ?>
                <?php if ($filter_fu === '1'): ?><span class="badge rounded-pill booking-chip bg-light text-dark">Follow-up: Ya</span><?php endif; ?>
                <?php if ($filter_q !== ''): ?><span class="badge rounded-pill booking-chip bg-primary text-white">Cari: <?= htmlspecialchars($filter_q) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive booking-table-responsive">
            <table id="bookingTable" class="table table-hover table-striped booking-table align-middle">
                <thead>
                    <tr>
                        <th>Tipe</th>
                        <th>Pasien</th>
                        <th>Jenis Pemeriksaan</th>
                        <th>Tujuan</th>
                        <th>Jadwal</th>
                        <th>INPUT BY</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings_data as $row): ?>
                    <tr>
                        <td>
                            <?php
                            $bt = strtolower((string)($row['booking_type'] ?? 'keep'));
                            $bt_label = 'Keep';
                            $bt_badge = 'bg-light text-dark border';
                            if ($bt === 'fixed') {
                                $bt_label = 'Fixed';
                                $bt_badge = 'bg-primary';
                            } elseif ($bt === 'cancel') {
                                $bt_label = 'Dibatalkan (CS)';
                                $bt_badge = 'bg-secondary';
                            }
                            $jf = (int)($row['jotform_submitted'] ?? 0);
                            ?>
                            <div class="mb-1">
                                <span class="badge <?= $bt_badge ?>"><?= $bt_label ?></span>
                            </div>
                            <div class="mb-1">
                                <?php if ($jf === 1): ?>
                                    <span class="badge bg-success x-small"><i class="fas fa-check me-1"></i>Jotform</span>
                                <?php else: ?>
                                    <span class="badge bg-danger x-small"><i class="fas fa-times me-1"></i>Jotform</span>
                                <?php endif; ?>
                            </div>
                            <div class="x-small fw-bold text-primary mt-1 text-nowrap"><?= htmlspecialchars($row['nomor_booking'] ?? '-') ?></div>
                        </td>
                        <td>
                            <div class="fw-bold">
                                <?= htmlspecialchars($row['nama_pemesan'] ?? 'N/A') ?>
                                <?php 
                                    $bid = (int)$row['id'];
                                    $f = $fulfillment_map[$bid] ?? null;
                                    if ($row['status'] === 'booked' && $f):
                                        if ($f['is_short']):
                                ?>
                                            <span class="ms-1 text-danger" title="STOK KURANG: <?= htmlspecialchars($f['short_items']) ?>">
                                                <i class="fas fa-exclamation-triangle"></i>
                                            </span>
<?php   else: // If not currently short
?>
                                            <span class="ms-1 text-success" title="Stok sudah terpenuhi (Siap Layanan)">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                <?php 
                                        endif;
                                    endif; 
                                ?>
                            </div>
                            <?php if (!empty($row['nomor_tlp'])): ?>
                                <div class="booking-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($row['nomor_tlp']) ?></div>
                            <?php endif; ?>
                            <div class="booking-muted"><i class="fas fa-users me-1"></i>Pax: <?= (int)($row['jumlah_pax'] ?? 1) ?></div>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($row['jenis_pemeriksaan'] ?? '-') ?></small></td>
                        <td>
                            <?php 
                            $status_booking = $row['status_booking'] ?? 'Reserved - Clinic';
                            $is_hc = (strpos($status_booking, 'HC') !== false);
                            ?>
                            <div class="mb-1">
                                <?php if ($is_hc): ?>
                                    <span class="badge bg-info x-small"><i class="fas fa-home me-1"></i>HC</span>
                                <?php else: ?>
                                    <span class="badge bg-primary x-small"><i class="fas fa-hospital me-1"></i>Klinik</span>
                                <?php endif; ?>
                            </div>
                            <div class="fw-semibold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($row['nama_klinik']) ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= date('d M Y', strtotime($row['tanggal_pemeriksaan'])) ?></div>
                            <div class="booking-muted">
                                <i class="fas fa-clock me-1"></i>
                                <?php 
                                    $jam = (string)($row['jam_layanan'] ?? '-');
                                    // If time is just HH (like "12"), append ":00" for display
                                    if (preg_match('/^\d{1,2}$/', $jam)) $jam .= ':00';
                                    echo htmlspecialchars($jam);
                                ?>
                            </div>
                        </td>
                        <td>
                            <div class="booking-muted" style="font-size: 0.8rem;">
                                <div class="fw-bold text-dark" title="Input by: <?= htmlspecialchars($row['creator_name'] ?? '-') ?>">
                                    <i class="fas fa-user-edit me-1"></i><?= htmlspecialchars($row['creator_name'] ?? $row['cs_name'] ?? '-') ?>
                                </div>
                                <div class="text-muted small mt-1"><i class="fas fa-clock me-1"></i><?= date('d/m/y H:i', strtotime($row['created_at'])) ?></div>
                            </div>
                        </td>

                        <td>
                            <?php
                            $badge = 'bg-secondary';
                            $status_label = ucfirst($row['status']);
                            if ($row['status'] == 'booked' && (int)($row['butuh_fu'] ?? 0) === 1) {
                                $badge = 'bg-danger';
                                $status_label = 'FU Jadwal<br>Kedatangan';
                            } elseif ($row['status'] == 'booked') {
                                $badge = 'bg-warning';
                            } elseif ($row['status'] == 'completed') {
                                $badge = 'bg-success';
                            } elseif ($row['status'] == 'cancelled') {
                                $badge = 'bg-danger';
                            } elseif ($row['status'] == 'pending_edit') {
                                $badge = 'bg-info text-dark';
                                $status_label = 'Pending Edit (SPV)';
                            } elseif ($row['status'] == 'pending_delete') {
                                $badge = 'bg-danger';
                                $status_label = 'Pending Hapus (SPV)';
                            } elseif ($row['status'] == 'rescheduled') {
                                $badge = 'bg-info text-white';
                                $status_label = 'Rescheduled';
                            } elseif ($row['status'] == 'rejected') {
                                $badge = 'bg-dark';
                                $status_label = 'Ditolak SPV';
                            }
                            ?>
                            <span class="badge <?= $badge ?>"><?= $status_label ?></span>
                            <?php if (!empty($row['approval_reason'])): ?>
                                <div class="x-small text-muted mt-1" title="<?= htmlspecialchars($row['approval_reason']) ?>">
                                    <i class="fas fa-info-circle"></i> <?= substr(htmlspecialchars($row['approval_reason']), 0, 20) ?><?= strlen($row['approval_reason']) > 20 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="vertical-align: top; width: 120px;">
                            <!-- Always show Eye/Detail button at the top -->
                            <button type="button" class="btn btn-sm btn-outline-primary btn-view-eye px-3 rounded-pill mb-2" title="Lihat Detail" onclick="return openBookingDetail(<?= (int)$row['id'] ?>);">
                                <i class="fas fa-eye me-1"></i>Detail
                            </button>

                            <?php 
                            $is_today = date('Y-m-d', strtotime($row['tanggal_pemeriksaan'])) === date('Y-m-d');
                            $is_past = date('Y-m-d', strtotime($row['tanggal_pemeriksaan'])) < date('Y-m-d');
                            $is_admin_klinik = ($_SESSION['role'] ?? '') === 'admin_klinik';
                            $is_spv_klinik = ($_SESSION['role'] ?? '') === 'spv_klinik';
                            $is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
                            $is_cs = ($_SESSION['role'] ?? '') === 'cs';
                            ?>

                            <?php if (in_array($row['status'], ['booked', 'rescheduled', 'pending_edit', 'pending_delete', 'rejected'])): ?>
                                <button type="button" class="btn btn-sm btn-primary px-3 rounded-pill btn-aksi-toggle" onclick="toggleActionDrawer(this)">
                                    <i class="fas fa-chevron-down me-1"></i>Aksi
                                </button>
                                
                                <div class="action-drawer">
                                    <?php if (in_array($row['status'], ['booked', 'rescheduled'])): ?>
                                        <?php if ($is_super_admin || $is_admin_klinik): ?>
                                            <button type="button" class="btn-drawer-icon text-info" title="Move" onclick="openMoveModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['status_booking'] ?? 'Reserved - Clinic', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>');">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button type="button" class="btn-drawer-icon text-info" title="Adjust Pax" onclick="openAdjustModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>', <?= $row['jumlah_pax'] ?? 1 ?>, <?= (int)$row['klinik_id'] ?>, '<?= htmlspecialchars($row['status_booking'] ?? 'Reserved - Clinic', ENT_QUOTES) ?>');">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($is_super_admin || $is_cs): ?>
                                            <button type="button" class="btn-drawer-icon text-warning" title="Edit" onclick="openEditBooking(<?= (int)$row['id'] ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($is_admin_klinik || $is_super_admin): ?>
                                            <?php if ((int)($row['butuh_fu'] ?? 0) === 0): ?>
                                                <button type="button" class="btn-drawer-icon text-danger" title="FU" onclick="return confirmButuhFU(<?= (int)$row['id'] ?>);">
                                                    <i class="fas fa-phone"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-drawer-icon text-warning" title="Reschedule" onclick="openRescheduleModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>', '<?= $row['tanggal_pemeriksaan'] ?>');">
                                                <i class="fas fa-calendar-alt"></i>
                                            </button>
                                            <button type="button" class="btn-drawer-icon text-success" title="Done" onclick="return openCompletionModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>');">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($is_super_admin || $is_cs): ?>
                                            <button type="button" class="btn-drawer-icon text-danger" title="Cancel" onclick="return confirmCancel(<?= (int)$row['id'] ?>);">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php elseif (strpos($row['status'], 'pending') !== false): ?>
                                        <?php if ($is_super_admin): ?>
                                            <button type="button" class="btn-drawer-icon text-success" title="Approve" onclick="approveBookingRequest(<?= (int)$row['id'] ?>, '<?= $row['status'] ?>')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn-drawer-icon text-danger" title="Reject" onclick="rejectBookingRequest(<?= (int)$row['id'] ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

<!-- Modal Booking Baru -->
<div class="modal fade" id="modalBookingBaru" tabindex="-1" aria-labelledby="modalBookingBaruLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalBookingBaruLabel">
                    <i class="fas fa-calendar-plus me-2"></i>Buat Booking Baru
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formBooking" method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="client_request_id" id="client_request_id" value="">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="bookingStockWarning" class="alert alert-warning py-2 small mb-3 d-none">
                        <i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong: proses tetap lanjut sesuai kebijakan, mohon follow up restock.
                    </div>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i> Isi data utama, lalu pilih pemeriksaan. Qty pemeriksaan otomatis mengikuti Pax.
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                                    <div class="fw-bold"><i class="fas fa-tag me-2 text-primary-custom"></i>Info Booking</div>
                                    <div class="small text-muted">
                                        <i class="fas fa-user-tie me-1"></i>CS: <span class="fw-semibold text-primary"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? '') ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Status Booking <span class="text-danger">*</span></label>
                                            <div class="segmented-control">
                                                <input type="radio" class="btn-check" name="status_booking" id="status_clinic" value="Reserved - Clinic" checked>
                                                <label class="btn-segmented" for="status_clinic">Clinic</label>
                                                
                                                <input type="radio" class="btn-check" name="status_booking" id="status_hc" value="Reserved - HC">
                                                <label class="btn-segmented" for="status_hc">HC</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Klinik <span class="text-danger">*</span></label>
                                            <select name="klinik_id" id="klinik_id_modal" class="form-select" required>
                                                <option value="">Pilih Klinik...</option>
                                                <?php 
                                                $klinik_res = $conn->query("SELECT * FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik");
                                                while($k = $klinik_res->fetch_assoc()): 
                                                ?>
                                                    <option value="<?= $k['id'] ?>"><?= $k['nama_klinik'] ?></option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold">Jumlah Pax</label>
                                            <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control bg-light" min="1" max="10" value="1" readonly required>
                                        </div>
                                        <div class="col-md-3" id="order_id_container_modal" style="display: none;">
                                            <label class="form-label fw-semibold">Order ID</label>
                                            <input type="text" name="order_id" class="form-control" placeholder="Opsional (Contoh: B12345)">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Jadwal Pemeriksaan <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="date" name="tanggal" id="booking_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required title="Tanggal">
                                                <input type="time" name="jam_layanan" id="booking_jam" class="form-control" title="Jam Layanan" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Tipe (Fixed/Keep) <span class="text-danger">*</span></label>
                                            <div class="segmented-control">
                                                <input type="radio" class="btn-check" name="booking_type" id="type_keep" value="keep" checked>
                                                <label class="btn-segmented" for="type_keep">Keep</label>
                                                
                                                <input type="radio" class="btn-check" name="booking_type" id="type_fixed" value="fixed">
                                                <label class="btn-segmented" for="type_fixed">Fixed</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Jotform Submitted</label>
                                            <div class="segmented-control">
                                                <input type="radio" class="btn-check" name="jotform_submitted" id="jotform_no" value="0" checked>
                                                <label class="btn-segmented" for="jotform_no">Belum</label>
                                                
                                                <input type="radio" class="btn-check" name="jotform_submitted" id="jotform_yes" value="1">
                                                <label class="btn-segmented" for="jotform_yes">Sudah</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section Data Pasien (Dynamic per Pax) -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="fw-bold text-primary-custom"><i class="fas fa-users me-2"></i>Identitas Pasien</div>
                                <button type="button" class="btn btn-sm btn-primary" id="btnAddPatientModal">
                                    <i class="fas fa-plus me-1"></i> Tambah Pasien
                                </button>
                            </div>
                            <div id="paxSectionsWrapper"></div>

                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-bold">
                                    <i class="fas fa-sticky-note me-2 text-primary-custom"></i>Catatan
                                </div>
                                <div class="card-body">
                                    <textarea name="catatan" class="form-control" rows="3" placeholder="Catatan tambahan (opsional)"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-success" id="btnSubmitBooking">
                        <i class="fas fa-save"></i> Simpan Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var examOptionsModal = '<option value="">Pilih klinik dulu...</option>';
var examRowIndexModal = 0;

$(document).ready(function() {
    // Initialize DataTable (Consolidated)
    if ($.fn.DataTable.isDataTable('#bookingTable')) {
        $('#bookingTable').DataTable().destroy();
    }
    $('#bookingTable').DataTable({
        order: [], 
        paging: false,
        info: false,
        searching: true,
        ordering: true,
        fixedHeader: true,
        lengthChange: false,
        columnDefs: [{ orderable: false, targets: [7] }],
        language: {
            searchPlaceholder: "Cari..."
        }
    });

    $(document).on('change', '.assign-tag input', function() {
        var $tag = $(this).closest('.assign-tag');
        var val = $(this).val();
        var isChecked = $(this).is(':checked');
        var $row = $(this).closest('.exam-row-item');

        if (val === 'all') {
            if (isChecked) {
                $row.find('.assign-tag').not($tag).addClass('disabled').find('input').prop('checked', false).prop('disabled', true);
            } else {
                $row.find('.assign-tag').not($tag).removeClass('disabled').find('input').prop('disabled', false);
            }
        }
        if (isChecked) $tag.addClass('active');
        else $tag.removeClass('active');
    });

    $(document).on('show.bs.dropdown', '#bookingTable .dropdown', function() {
        $('#bookingTable td.booking-aksi-open').removeClass('booking-aksi-open');
        $(this).closest('td').addClass('booking-aksi-open');
    });

    // Modal Booking Baru Event Listeners
    $('#btnAddPatientModal').on('click', function() {
        var $input = $('#jumlah_pax');
        var curr = parseInt($input.val()) || 1;
        if (curr < 10) {
            $input.val(curr + 1);
            renderPaxSections(curr + 1);
        } else {
            Swal.fire('Info', 'Maksimal 10 pasien per booking!', 'info');
        }
    });

    $(document).on('click', '.btn-remove-patient-modal', function() {
        var $input = $('#jumlah_pax');
        var curr = parseInt($input.val()) || 1;
        if (curr > 1) {
            Swal.fire({
                title: 'Hapus Pasien?',
                text: "Data pasien ini akan dihapus dari form.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $input.val(curr - 1);
                    renderPaxSections(curr - 1);
                }
            });
        } else {
            Swal.fire('Info', 'Minimal 1 pasien per booking!', 'info');
        }
    });
    
    $('#modalBookingBaru').on('shown.bs.modal', function() {
        $('#client_request_id').val((window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2)));
        $('#btnSubmitBooking').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Booking');
        
        var klinikId = $('#klinik_id_modal').val();
        if (klinikId) loadExamOptions(klinikId);
        
        if ($('#paxSectionsWrapper').is(':empty')) {
            renderPaxSections($('#jumlah_pax').val());
        }
    });

    $('#modalBookingBaru').on('hidden.bs.modal', function() {
        $('#formBooking')[0].reset();
        $('#paxSectionsWrapper').empty();
        $('#client_request_id').val('');
        $('#btnSubmitBooking').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Booking');
    });

    $('#klinik_id_modal').on('change', function() {
        var klinikId = $('#klinik_id_modal').val();
        var statusBooking = $('input[name=\"status_booking\"]:checked').val();

        if (statusBooking === 'Reserved - HC') {
            $('#order_id_container_modal').show();
        } else {
            $('#order_id_container_modal').hide();
            $('#order_id_container_modal input').val('');
        }

        if (klinikId) {
            loadExamOptions(klinikId);
        } else {
            examOptionsModal = '<option value="">Pilih klinik dulu...</option>';
            updateAllExamSelects();
        }
    });
    $('input[name=\"status_booking\"]').on('change', function() {
        var statusBooking = $('input[name=\"status_booking\"]:checked').val();
        if (statusBooking === 'Reserved - HC') {
            $('#order_id_container_modal').show();
        } else {
            $('#order_id_container_modal').hide();
            $('#order_id_container_modal input').val('');
        }
        var klinikId = $('#klinik_id_modal').val();
        if (klinikId) loadExamOptions(klinikId);
    });

    $(document).on('change', '.patient-exam-select[data-patient-idx="0"]', function() {
        var $this = $(this);
        var changedRowIdx = $this.closest('.exam-row').data('row-idx');
        
        // Hanya sinkronkan jika baris pertama pasien 0 yang diubah
        if (changedRowIdx !== 0) return;

        var firstPatientExamId = $this.val();
        
        // Cari modal aktif dan ambil jumlah pax dari modal tersebut
        var $modal = $this.closest('.modal');
        var paxCount = parseInt($modal.find('input[name="jumlah_pax"]').val()) || 1;
        
        if (paxCount > 1) {
            // Hanya sinkronkan elemen di dalam modal yang sama
            $modal.find('.patient-exam-select').not(this).each(function() {
                var $select = $(this);
                var patientIdx = parseInt($select.attr('data-patient-idx') || $select.data('patient-idx'));
                var rowIdx = $select.closest('.exam-row').data('row-idx');
                
                // Hanya sinkronkan ke baris pertama pasien LAIN (patientIdx > 0)
                if (rowIdx === 0 && patientIdx > 0) {
                    $select.val(firstPatientExamId).trigger('change');
                }
            });
        }
    });

    // Real-time validation for date and time
    $('#booking_tanggal, #booking_jam').on('change', function() {
        const selectedDate = $('#booking_tanggal').val();
        const selectedTime = $('#booking_jam').val();
        if (!selectedDate) return;

        const now = new Date();
        const today = now.toISOString().split('T')[0];
        const currentTime = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

        if (selectedDate < today) {
            showError('Tanggal booking tidak boleh backdate!');
            $('#booking_tanggal').val(today);
        } else if (selectedDate === today && selectedTime && selectedTime < currentTime) {
            showError('Jam layanan tidak boleh mundur dari jam sekarang!');
            $('#booking_jam').val(currentTime);
        }
    });

    // Handle form submit
    $('#formBooking').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btnSubmitBooking');
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
        
        $.ajax({
            url: 'actions/process_booking.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#modalBookingBaru').modal('hide');
                    
                    // Reset form
                    $('#formBooking')[0].reset();
                    renderPaxSections(1);

                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        // Redirect with trigger_detail to show detail after reload
                        if (response.booking_id) {
                            location.href = 'index.php?page=booking&trigger_detail=' + response.booking_id;
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    showError(response.message);
                    $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Booking');
                }
            },
            error: function() {
                showError('Terjadi kesalahan. Silakan coba lagi.');
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Booking');
            }
        });
    });
});

// Global Functions
window.renderPaxSections = function(paxCount) {
    var $wrapper = $('#paxSectionsWrapper');
    var existingData = [];
    
    $wrapper.find('.pax-section-card').each(function(idx) {
        var exams = [];
        $(this).find('.patient-exam-select').each(function() { 
            var val = $(this).val();
            if (val) exams.push(val); 
        });
        existingData[idx] = {
            nama: $(this).find('input[name*="[nama]"]').val(),
            tlp: $(this).find('input[name*="[nomor_tlp]"]').val(),
            dob: $(this).find('input[name*="[tanggal_lahir]"]').val(),
            exams: exams
        };
    });

    var inheritExams = (existingData[0] && existingData[0].exams) ? existingData[0].exams : [];

    $wrapper.empty();
    for (var i = 0; i < paxCount; i++) {
        var num = i + 1;
        var data = existingData[i] || { nama: '', tlp: '', dob: '', exams: [] };
        
        var card = `
            <div class="card pax-section-card mb-4" data-patient-idx="${i}">
                <div class="pax-card-header d-flex justify-content-between align-items-center">
                    <div class="pax-title">
                        <i class="fas fa-user-circle"></i>
                        Pasien ${num} ${i === 0 ? '<span class="badge bg-primary-custom ms-2" style="font-size: 0.65rem;">UTAMA</span>' : ''}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        ${i > 0 ? `
                        <button type="button" class="btn btn-outline-danger btn-sm border-0 py-0 btn-remove-patient-modal" title="Hapus Pasien">
                            <i class="fas fa-trash-alt me-1"></i><span class="x-small fw-bold">HAPUS</span>
                        </button>` : ''}
                    </div>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="pax-label-minimal">Nama Pasien <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-id-card"></i></span>
                                <input type="text" name="patients[${i}][nama]" class="form-control ps-1 border-start-0" placeholder="Nama Lengkap" value="${data.nama}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="pax-label-minimal">Nomor Tlp</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-phone-alt"></i></span>
                                <input type="text" name="patients[${i}][nomor_tlp]" class="form-control ps-1 border-start-0" placeholder="08xxxxxxxx" value="${data.tlp}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="pax-label-minimal">Tanggal Lahir</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" name="patients[${i}][tanggal_lahir]" class="form-control ps-1 border-start-0" value="${data.dob}">
                            </div>
                        </div>
                    </div>
                    
                    <div class="pax-exam-container">
                        <label class="pax-exam-label"><i class="fas fa-microscope"></i> Paket Pemeriksaan</label>
                        <div class="patient-exams-list" data-patient-idx="${i}"></div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-link btn-sm text-success p-0 fw-bold x-small text-decoration-none" onclick="addPatientExamRow(${i})">
                                <i class="fas fa-plus-circle me-1"></i>TAMBAH PEMERIKSAAN
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        $wrapper.append(card);
        
        if (data.exams && data.exams.length > 0) {
            data.exams.forEach(function(examId) { addPatientExamRow(i, examId); });
        } else if (i > 0 && inheritExams.length > 0) {
            inheritExams.forEach(function(examId) { addPatientExamRow(i, examId); });
        } else {
            addPatientExamRow(i, '');
        }
    }
};

const BOOKING_CSRF = '<?= csrf_token() ?>';

window.addPatientExamRow = function(patientIdx, selectedId = '') {
    var $list = $(`.patient-exams-list[data-patient-idx="${patientIdx}"]`);
    var rowIdx = $list.find('.exam-row').length;
    var row = `
        <div class="row g-2 mb-1 exam-row" data-row-idx="${rowIdx}">
            <div class="col">
                <select name="patients[${patientIdx}][exams][]" class="form-select form-select-sm patient-exam-select" data-patient-idx="${patientIdx}" required>
                    ${examOptionsModal}
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-danger btn-sm border-0 py-0" onclick="removePatientExamRow(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>`;
    $list.append(row);
    var $select = $list.find('.exam-row').last().find('select');
    if (typeof $select.select2 === 'function') {
        var $modal = $('#modalBookingBaru');
        if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
        $select.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: ($modal.length ? $modal : $(document.body)) });
    }
    if (selectedId) {
        $select.val(selectedId).trigger('change');
    } else if (patientIdx > 0 && rowIdx === 0) {
        var $modal = $list.closest('.modal');
        var firstExam = $modal.find(`.patient-exams-list[data-patient-idx="0"] .patient-exam-select`).first().val();
        if (firstExam) $select.val(firstExam).trigger('change');
    }
};

window.removePatientExamRow = function(btn) {
    var $list = $(btn).closest('.patient-exams-list');
    if ($list.find('.exam-row').length > 1) $(btn).closest('.exam-row').remove();
    else showWarning('Setiap pasien minimal memiliki 1 pemeriksaan!');
};

window.loadExamOptions = function(klinikId, callback) {
    var statusBooking = $('input[name=\"status_booking\"]:checked').val() || $('#modalAdjust').data('status-booking') || '';
    $.ajax({
        url: 'api/get_exam_availability.php',
        method: 'GET',
        data: { klinik_id: klinikId, status_booking: statusBooking },
        dataType: 'json',
        success: function(data) {
            examOptionsModal = '<option value="">Pilih pemeriksaan...</option>';
            if (data && data.length > 0) {
                    data.forEach(function(exam) {
                        var readyText = '';
                        if (exam.no_mapping) {
                            readyText = '(Input Manual di BHP)';
                        } else {
                            readyText = exam.is_available ? `(Ready: ${exam.qty})` : '(STOK KOSONG)';
                        }
                        var textClass = exam.is_available ? '' : 'text-danger';
                        examOptionsModal += `<option value="${exam.id}" data-available="${exam.is_available ? 1 : 0}" class="${textClass}">${exam.name} ${readyText}</option>`;
                    });
                } else {
                examOptionsModal = '<option value="">Tidak ada pemeriksaan tersedia</option>';
            }
            updateAllExamSelects();
            if (typeof callback === 'function') callback();
        },
        error: function() {
            examOptionsModal = '<option value="">Error loading data</option>';
            updateAllExamSelects();
            if (typeof callback === 'function') callback();
        }
    });
};

window.updateAllExamSelects = function() {
    $('.patient-exam-select').each(function() {
        var currentVal = $(this).val();
        $(this).html(examOptionsModal);
        if (currentVal) $(this).val(currentVal);
        if (typeof $(this).select2 === 'function') {
            var $modal = $('#modalBookingBaru');
            if ($(this).hasClass('select2-hidden-accessible')) {
                $(this).trigger('change');
            } else {
                $(this).select2({ 
                    theme: 'bootstrap-5', 
                    width: '100%', 
                    dropdownParent: ($modal.length ? $modal : $(document.body)),
                    templateResult: formatExamOption,
                    templateSelection: formatExamOption
                });
            }
        }
    });
    checkSelectedStock();
};

function formatExamOption(state) {
    if (!state.id) return state.text;
    var isAvailable = $(state.element).data('available');
    var $state = $(
        '<span>' + state.text + '</span>'
    );
    if (isAvailable == 0) {
        $state.addClass('text-danger fw-bold');
    }
    return $state;
}

let __oosPreviewTimer = null;
let __oosPreviewXhr = null;
function checkSelectedStock() {
    const examIds = [];
    let hasOutOfStock = false;

    $('.patient-exam-select').each(function() {
        const $opt = $(this).find('option:selected');
        const exId = $opt.val() || '';
        if (exId !== '') examIds.push(exId);
        if ($opt.data('available') == 0) hasOutOfStock = true;
    });

    if (!hasOutOfStock) {
        $('#bookingStockWarning').addClass('d-none');
        return;
    }

    // Debounce preview calls while user is selecting
    if (__oosPreviewTimer) clearTimeout(__oosPreviewTimer);
    __oosPreviewTimer = setTimeout(function() {
        if (__oosPreviewXhr && __oosPreviewXhr.readyState !== 4) {
            try { __oosPreviewXhr.abort(); } catch (e) {}
        }
        const klinikId = parseInt($('#klinik_id_modal').val() || '0', 10);
        const statusBooking = $('input[name="status_booking"]:checked').val() || '';
        __oosPreviewXhr = $.ajax({
            url: 'api/get_core_oos_items.php',
            method: 'POST',
            dataType: 'json',
            data: { _csrf: BOOKING_CSRF, klinik_id: klinikId, status_booking: statusBooking, exam_ids: examIds }
        }).done(function(res) {
            const $w = $('#bookingStockWarning');
            if (!res || !res.success || !res.items || res.items.length === 0) {
                $w.html('<i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong: proses tetap lanjut sesuai kebijakan, mohon follow up restock.');
                $w.removeClass('d-none');
                return;
            }
            const list = res.items.map(function(x){ return '<li>' + $('<div>').text(x).html() + '</li>'; }).join('');
            $w.html(
                '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong (item):' +
                '<ul class="mb-1 mt-1 ps-4">' + list + '</ul>' +
                '<span class="small opacity-75">Proses tetap lanjut sesuai kebijakan, mohon follow up restock.</span>'
            );
            $w.removeClass('d-none');
        }).fail(function() {
            // Keep generic message if preview fails
            $('#bookingStockWarning').removeClass('d-none');
        });
    }, 250);
}

$(document).on('change', '.patient-exam-select', function() {
    checkSelectedStock();
});

window.postBookingAction = function(params) {
    const payload = Object.assign({ _csrf: BOOKING_CSRF }, params || {});
    
    // Tampilkan loading
    Swal.fire({
        title: 'Memproses...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    $.ajax({
        url: 'actions/process_booking_action.php',
        method: 'POST',
        data: payload,
        dataType: 'json',
        success: function(res) {
            Swal.close();
            if (res.success) {
                let redirectUrl = res.redirect || 'index.php?page=booking';
                if (res.trigger_detail_id) {
                    // Append trigger_detail to the redirect URL
                    const separator = redirectUrl.includes('?') ? '&' : '?';
                    redirectUrl += separator + 'trigger_detail=' + res.trigger_detail_id;
                }
                showSuccessRedirect(res.message, redirectUrl);
            } else {
                showError(res.message || 'Terjadi kesalahan');
            }
        },
        error: function() {
            Swal.close();
            showError('Gagal terhubung ke server');
        }
    });
};

window.openEditBooking = function(id, requestReason = '') {
    const containerId = 'modalEditBookingContainer';
    let $container = $('#' + containerId);
    if (!$container.length) {
        $container = $('<div id="' + containerId + '"></div>').appendTo('body');
    }
    
    // Tampilkan SweetAlert loading yang lebih clean daripada modal manual
    Swal.fire({
        title: 'Memuat Form Edit...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    $.ajax({
        url: 'index.php',
        method: 'GET',
        data: { page: 'booking_edit', id: id, layout: 'none', request_reason: requestReason },
        dataType: 'html',
        cache: false,
         success: function(html) {
            Swal.close();
            $container.html(html);
            
            // Tunggu sebentar agar DOM stabil, lalu cari modal di dalam html yang baru dimuat
            setTimeout(function() {
                const $newModal = $container.find('.modal');
                if ($newModal.length) {
                    const inst = new bootstrap.Modal($newModal[0], { backdrop: 'static' });
                    inst.show();
                    
                    // Pastikan saat modal ditutup, container dibersihkan
                    $newModal.on('hidden.bs.modal', function() {
                        $container.empty();
                        // Hapus sisa-sisa backdrop jika ada
                        $('.modal-backdrop').remove();
                        $('body').removeClass('modal-open').css('overflow', '');
                    });
                }
            }, 100);
        },
        error: function(xhr) {
            Swal.close();
            var msg = 'Gagal memuat form edit';
            try {
                var status = xhr && xhr.status ? (' [' + xhr.status + ']') : '';
                var snippet = (xhr && xhr.responseText) ? String(xhr.responseText).substr(0, 200) : '';
                if (snippet) msg += status + ' - ' + snippet.replace(/<[^>]+>/g,'').trim();
                else msg += status;
            } catch (e) {}
            showError(msg);
        }
    });
};

window.requestEditBooking = function(id) {
    Swal.fire({
        title: 'Request Edit Booking',
        text: 'Masukkan alasan perubahan data booking (lewat hari):',
        input: 'textarea',
        inputPlaceholder: 'Contoh: Koreksi data pasien atau pemeriksaan...',
        showCancelButton: true,
        confirmButtonText: 'Lanjut Edit',
        cancelButtonText: 'Batal',
        inputValidator: (value) => {
            if (!value) return 'Alasan wajib diisi!';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // We open edit modal, but we pass the reason
            window.openEditBooking(id, result.value);
        }
    });
};

window.requestDeleteBooking = function(id, nomor) {
    Swal.fire({
        title: 'Request Hapus Booking',
        html: `Yakin ingin menghapus booking <strong>${nomor}</strong>?<br><br>Masukkan alasan penghapusan (lewat hari):`,
        input: 'textarea',
        inputPlaceholder: 'Contoh: Pasien batal atau double input...',
        showCancelButton: true,
        confirmButtonText: 'Kirim Request',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#d33',
        inputValidator: (value) => {
            if (!value) return 'Alasan wajib diisi!';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'actions/process_booking_action.php',
                method: 'POST',
                data: {
                    action: 'request_delete',
                    id: id,
                    reason: result.value,
                    _csrf: $('input[name="_csrf"]').val()
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                }
            });
        }
    });
};

window.approveBookingRequest = function(id, status) {
    const actionText = status === 'pending_edit' ? 'menyetujui perubahan' : 'menyetujui penghapusan';
    Swal.fire({
        title: 'Approve Request',
        text: `Apakah Anda yakin ingin ${actionText} data ini?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Approve',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'actions/process_booking_action.php',
                method: 'POST',
                data: {
                    action: 'approve_request',
                    id: id,
                    _csrf: $('input[name="_csrf"]').val()
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                }
            });
        }
    });
};

window.rejectBookingRequest = function(id) {
    Swal.fire({
        title: 'Tolak Request',
        text: 'Masukkan alasan penolakan:',
        input: 'textarea',
        showCancelButton: true,
        confirmButtonText: 'Tolak',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#d33',
        inputValidator: (value) => {
            if (!value) return 'Alasan wajib diisi!';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'actions/process_booking_action.php',
                method: 'POST',
                data: {
                    action: 'reject_request',
                    id: id,
                    reason: result.value,
                    _csrf: $('input[name="_csrf"]').val()
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => { location.reload(); });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                }
            });
        }
    });
};

window.confirmButuhFU = function(id) {
    showConfirm('Tandai booking untuk FU jadwal kedatangan pasien (tanya mau datang kapan)?', 'Konfirmasi', function() {
        postBookingAction({ action: 'fu', id: id });
    });
    return false;
};

window.confirmCancel = function(id) {
    showConfirm('Batalkan booking? Stok pending akan dilepas.', 'Konfirmasi Pembatalan', function() {
        postBookingAction({ action: 'cancel', id: id });
    });
    return false;
};

window.openBookingDetail = function(id) {
    $('#bookingDetailBody').html('<div class="text-center text-muted py-3">Memuat...</div>');
    var $modalDetail = $('#modalBookingDetail');
    // Reset to Info Tab
    $modalDetail.find('#info-tab').tab('show');
    $('#bookingHistoryBody').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin d-block h4 mb-2"></i><span>Memuat riwayat...</span></div>');
    
    // Show modal
    var m = bootstrap.Modal.getOrCreateInstance($modalDetail[0]);
    m.show();

    // Store current booking ID globally for history loader
    window.currentDetailBookingId = id;

    $.ajax({
        url: 'api/ajax_booking_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { id: id },
        success: function(res) {
            if (!res || !res.success) {
                $('#bookingDetailBody').html('<div class="alert alert-danger mb-0">' + (res && res.message ? res.message : 'Gagal memuat') + '</div>');
                return;
            }
            const h = res.header || {};
            const items = Array.isArray(res.items) ? res.items : [];
            const esc = function(v) { return $('<div>').text(v == null ? '' : String(v)).html(); };
            const fmtQtyJs = function(v) {
                let n = parseFloat(v || 0);
                if (Math.abs(n - Math.round(n)) < 0.00005) return Math.round(n).toString();
                let s = n.toFixed(4).replace(/\.?0+$/, "");
                return s === "" ? "0" : s;
            };
            const fmtDateIdShort = function(v) {
                const raw = (v || '').toString().trim();
                if (!raw) return '-';
                const datePart = raw.split(' ')[0];
                const m = datePart.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (!m) return raw;
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                const yyyy = m[1];
                const mm = parseInt(m[2], 10);
                const dd = m[3];
                if (mm < 1 || mm > 12) return raw;
                return dd + ' ' + months[mm - 1] + ' ' + yyyy;
            };
            const formatOosItemsHtml = function(raw) {
                const text = (raw || '').toString().trim();
                if (!text) return '<div class="small mb-0">Beberapa item tidak tersedia saat booking.</div>';
                const parts = text
                    .split(/\)\s*,\s*/g)
                    .map(function(p) {
                        p = (p || '').trim();
                        if (p && !p.endsWith(')')) p += ')';
                        return p;
                    })
                    .filter(Boolean);
                if (!parts.length) return '<div class="small mb-0">' + esc(text) + '</div>';
                const lis = parts.map(function(p) {
                    return '<li class="booking-oos-item">' + esc(p) + '</li>';
                }).join('');
                return '<ul class="booking-oos-list mb-0">' + lis + '</ul>';
            };
            
            var isHistoryMode = items.length > 0 && items[0].is_history;
            var rows = items.length ? items.map(function(it) {
                var needed = parseFloat(it.qty || 0);
                var current = parseFloat(it.current_available || 0);
                var isShort = current < needed;
                
                var stockInfo = '';
                if (it.is_history) {
                    stockInfo = `<span class="badge bg-secondary ms-2" style="font-size: 0.65rem; opacity: 0.7; font-weight: normal;"><i class="fas fa-history me-1"></i>Histori</span>`;
                } else {
                    stockInfo = `<span class="badge ${isShort ? 'bg-danger' : 'bg-success'} ms-2" style="font-size: 0.65rem;">Stok: ${fmtQtyJs(current)}</span>`;
                }
                
                return `<tr style="font-size: 0.85rem;">
                    <td class="py-2">${esc(it.kode_barang + ' - ' + it.nama_barang)} ${stockInfo}</td>
                    <td class="text-end fw-bold py-2 pe-3">${fmtQtyJs(it.qty)}</td>
                </tr>`;
            }).join('') : '<tr><td colspan="2" class="text-center text-muted py-2">Tidak ada item</td></tr>';

            // Render Patient List
            let paxHtml = '';
            res.pasien_list.forEach((p, idx) => {
                let statusBadge = '';
                let rowStyle = '';
                if (p.status === 'done') {
                    statusBadge = '<span class="badge bg-success x-small ms-2">Completed</span>';
                } else if (p.status === 'rescheduled') {
                    statusBadge = '<span class="badge bg-info x-small ms-2">Rescheduled</span>';
                } else if (p.status === 'cancelled') {
                    statusBadge = `<span class="badge bg-danger x-small ms-2" title="Alasan: ${p.remark || '-'}">Cancelled</span>`;
                    rowStyle = 'background-color: rgba(220, 53, 69, 0.05);';
                }

                paxHtml += `
                    <div class="p-2 border-bottom d-flex justify-content-between align-items-center" style="${rowStyle}">
                        <div>
                            <div class="fw-bold text-dark ${p.status === 'cancelled' ? 'text-decoration-line-through text-muted' : ''}">${idx + 1}. ${p.nama_pasien} ${statusBadge}</div>
                            <div class="x-small text-muted ${p.status === 'cancelled' ? 'text-decoration-line-through' : ''}">${p.exams}</div>
                            ${p.remark ? `<div class="x-small ${p.status === 'rescheduled' ? 'text-primary' : 'text-info'} mt-1"><i class="fas fa-info-circle me-1"></i>${p.remark}</div>` : ''}
                        </div>
                    </div>
                `;
            });
            $('#detailPaxList').html(paxHtml);

            $('#bookingDetailTitle').text('Detail: ' + (h.nomor_booking || ''));
            var stockWarningHtml = '';
            if (parseInt(h.is_out_of_stock) === 1) {
                stockWarningHtml = `
                    <div class="col-12">
                        <div class="alert alert-danger mb-0 py-2 booking-oos-alert">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-exclamation-triangle mt-1"></i>
                                <div class="w-100">
                                    <div class="fw-bold mb-1">Stok Kosong</div>
                                    ${formatOosItemsHtml(h.out_of_stock_items)}
                                </div>
                            </div>
                        </div>
                    </div>`;
            }

            $('#bookingDetailBody').html(`
                <div class="row g-3">
                    ${stockWarningHtml}
                    
                    <!-- Header Info Bar -->
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between p-3 bg-light rounded-3 border">
                            <!-- Column 1: Klinik -->
                            <div class="d-flex align-items-center" style="flex: 1; min-width: 200px;">
                                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm me-3 flex-shrink-0" style="width: 42px; height: 42px;">
                                    <i class="fas fa-hospital text-primary"></i>
                                </div>
                                <div>
                                    <div class="x-small text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Klinik Tujuan</div>
                                    <div class="fw-bold text-dark">${esc(h.nama_klinik)}</div>
                                    <div class="x-small text-muted">${esc(h.status_booking)}</div>
                                </div>
                            </div>

                            <!-- Column 2: Jadwal -->
                            <div class="d-flex align-items-center" style="flex: 1.2; min-width: 250px; border-left: 1px solid #dee2e6; padding-left: 20px;">
                                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm me-3 flex-shrink-0" style="width: 42px; height: 42px;">
                                    <i class="fas fa-calendar-alt text-success"></i>
                                </div>
                                <div>
                                    <div class="x-small text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Jadwal Pemeriksaan</div>
                                    <div class="fw-bold text-dark">${esc(fmtDateIdShort(h.tanggal_pemeriksaan))} <span class="mx-1 opacity-50">|</span> ${esc(h.jam_layanan || '-')}</div>
                                    <div class="x-small text-muted d-flex align-items-center gap-2">
                                        <span>Tipe: <span class="text-capitalize fw-bold text-dark">${esc(h.booking_type)}</span></span>
                                        <span class="opacity-50">|</span>
                                        <span class="d-flex align-items-center">
                                            Jotform: ${parseInt(h.jotform_submitted) === 1 ? '<span class="text-success fw-bold ms-1"><i class="fas fa-check-circle me-1"></i>Sudah</span>' : '<span class="text-danger fw-bold ms-1"><i class="fas fa-times-circle me-1"></i>Belum</span>'}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Column 3: Status -->
                            <div class="d-flex align-items-center" style="flex: 1; min-width: 180px; border-left: 1px solid #dee2e6; padding-left: 20px;">
                                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm me-3 flex-shrink-0" style="width: 42px; height: 42px;">
                                    <i class="fas fa-info-circle text-info"></i>
                                </div>
                                <div>
                                    <div class="x-small text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Status Booking</div>
                                    <div class="fw-bold text-dark text-capitalize">${esc(h.status || h.status_booking)}</div>
                                    <div class="x-small text-muted">ID: #${esc(h.nomor_booking)}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Left: Patients -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
                                <span class="fw-bold text-dark"><i class="fas fa-users me-2 text-primary"></i>Daftar Pasien (${esc(h.jumlah_pax || 1)} Pax)</span>
                            </div>
                            <div class="card-body p-3" style="max-height: 500px; overflow-y: auto;">
                                ${paxHtml}
                            </div>
                        </div>
                    </div>

                    <!-- Right: Inventory & Notes -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white py-2 border-bottom">
                                <span class="fw-bold text-dark">
                                    <i class="fas fa-boxes me-2 ${isHistoryMode ? 'text-secondary' : 'text-primary'}"></i>
                                    ${isHistoryMode ? 'Estimasi Penggunaan Stok' : 'Kebutuhan Stok'}
                                </span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-3 py-2 x-small text-uppercase">Item / Barang</th>
                                                <th class="text-end pe-3 py-2 x-small text-uppercase">Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody>${rows}</tbody>
                                    </table>
                                </div>
                                <div class="p-3 border-top bg-light-subtle">
                                    <div class="x-small text-muted text-uppercase fw-bold mb-1" style="font-size: 0.65rem;">Paket Pemeriksaan</div>
                                    <div class="small fw-bold text-primary">${esc(res.jenis_pemeriksaan || '-')}</div>
                                </div>
                            </div>
                        </div>

                        ${h.catatan ? `
                        <div class="card border-0 shadow-sm" style="background-color: #fffbeb; border-left: 4px solid #f59e0b !important;">
                            <div class="card-body p-3">
                                <div class="x-small text-warning text-uppercase fw-bold mb-1" style="font-size: 0.65rem;"><i class="fas fa-sticky-note me-1"></i>Catatan Internal</div>
                                <div class="small text-dark fw-semibold" style="line-height: 1.4;">${esc(h.catatan)}</div>
                            </div>
                        </div>` : ''}
                    </div>
                </div>`);

            $('#bookingDetailCSBadge').html(`
                <div class="d-flex align-items-center text-white-50 x-small fw-bold bg-black bg-opacity-10 px-3 py-1 rounded-pill">
                    <i class="fas fa-user-edit me-1"></i>CS: <span class="text-white ms-1">${esc(h.cs_name || '-')}</span>
                </div>
            `);
        },
        error: function() { $('#bookingDetailBody').html('<div class="alert alert-danger mb-0">Gagal memuat</div>'); }
    });
    return false;
};

window.openMoveModal = function(id, currentStatus, nomorBooking) {
    $('#moveBookingId').val(id);
    $('#moveNomorBooking').text(nomorBooking);
    $('#moveCurrentStatus').text(currentStatus);
    
    // Determine target status
    let target = '';
    let label = '';
    if (currentStatus.toLowerCase().includes('clinic')) {
        target = 'Reserved - HC';
        label = 'Homecare (HC)';
    } else {
        target = 'Reserved - Clinic';
        label = 'Klinik (Clinic)';
    }
    
    $('#moveNewStatus').val(target);
    $('#moveTargetLabel').text(label);
    
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMove')).show();
};

window.submitMove = function() {
    const id = $('#moveBookingId').val();
    const newStatus = $('#moveNewStatus').val();
    const targetLabel = $('#moveTargetLabel').text();

    showConfirm(`Pindahkan booking ini ke ${targetLabel}?`, 'Konfirmasi Perpindahan', function() {
        postBookingAction({ 
            action: 'move', 
            id: id, 
            new_status: newStatus 
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMove')).hide();
    });
};

window.openAdjustModal = function(id, nomorBooking, currentPax, klinikId, statusBooking) {
    $('#adjustBookingId').val(id);
    $('#adjustNomorBooking').text(nomorBooking);
    $('#adjustCurrentPax').text(currentPax);
    $('#adjustAdditionalPax').val(1);
    
    // Simpan context untuk load exam
    $('#modalAdjust').data('klinik-id', klinikId);
    $('#modalAdjust').data('status-booking', statusBooking);
    
    // Ambil jenis pemeriksaan pasien utama dari detail (via AJAX)
    $.ajax({
        url: 'api/ajax_booking_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { id: id },
        success: function(res) {
            var firstPatientExamId = '';
            if (res && res.success && res.pasien_list && res.pasien_list.length > 0) {
                // Ambil pemeriksaan pertama dari pasien pertama
                var firstP = res.pasien_list[0];
                if (firstP.exam_ids && firstP.exam_ids.length > 0) {
                    firstPatientExamId = firstP.exam_ids[0];
                }
            }
            $('#modalAdjust').data('primary-exam-id', firstPatientExamId);

            // Pre-load exam options before showing
            loadExamOptions(klinikId, function() {
                renderAdjustPaxSections();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAdjust')).show();
            });
        },
        error: function() {
            loadExamOptions(klinikId, function() {
                renderAdjustPaxSections();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAdjust')).show();
            });
        }
    });
};

window.renderAdjustPaxSections = function() {
    var $wrapper = $('#adjustPaxSectionsWrapper');
    var addCount = parseInt($('#adjustAdditionalPax').val()) || 1;
    var currentPax = parseInt($('#adjustCurrentPax').text()) || 0;
    var primaryExamId = $('#modalAdjust').data('primary-exam-id') || '';
    
    // Save existing data from inputs before re-rendering
    var existingData = [];
    $wrapper.find('.pax-section-card').each(function(i) {
        var exams = [];
        $(this).find('select[name*="[exams]"]').each(function() {
            if ($(this).val()) exams.push($(this).val());
        });
        existingData[i] = {
            nama: $(this).find('input[name*="[nama]"]').val(),
            exams: exams
        };
    });

    $wrapper.empty();
    for (var i = 0; i < addCount; i++) {
        var num = currentPax + i + 1;
        var data = existingData[i] || { nama: `Pasien ${num}`, exams: [primaryExamId] };
        var section = `
            <div class="card pax-section-card mb-3" data-idx="${i}">
                <div class="pax-card-header d-flex justify-content-between align-items-center">
                    <div class="pax-title">
                        <i class="fas fa-user-plus"></i>
                        Data Pasien Tambahan ${num}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-danger btn-sm border-0 py-0 btn-remove-adjust-pax" title="Hapus Pasien Tambahan ini">
                            <i class="fas fa-trash-alt me-1"></i><span class="x-small fw-bold">HAPUS</span>
                        </button>
                    </div>
                </div>
                <div class="card-body py-3">
                    <div class="mb-3">
                        <label class="pax-label-minimal">Nama Pasien <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-id-card"></i></span>
                            <input type="text" name="additional_patients[${i}][nama]" class="form-control ps-1 border-start-0" placeholder="Nama Pasien" value="${data.nama}" required>
                        </div>
                    </div>
                    <div class="pax-exam-container">
                        <label class="pax-exam-label"><i class="fas fa-microscope"></i> Pemeriksaan</label>
                        <div class="additional-exams-list" data-idx="${i}"></div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-link btn-sm text-success p-0 fw-bold x-small text-decoration-none" onclick="addAdditionalExamRow(${i})">
                                <i class="fas fa-plus-circle me-1"></i>TAMBAH PEMERIKSAAN
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        $wrapper.append(section);
        
        if (data.exams && data.exams.length > 0) {
            data.exams.forEach(function(eid) { addAdditionalExamRow(i, eid); });
        } else {
            addAdditionalExamRow(i, '');
        }
    }
};

window.addAdditionalExamRow = function(pIdx, selectedId = '') {
    var $list = $(`.additional-exams-list[data-idx="${pIdx}"]`);
    var row = `
        <div class="row g-2 mb-1 additional-exam-row align-items-center">
            <div class="col">
                <select name="additional_patients[${pIdx}][exams][]" class="form-select form-select-sm additional-exam-select" required>
                    ${examOptionsModal}
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-danger btn-sm border-0 py-0" onclick="$(this).closest('.additional-exam-row').remove()">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>`;
    $list.append(row);
    var $select = $list.find('.additional-exam-row').last().find('select');
    if (typeof $select.select2 === 'function') {
        var $modal = $('#modalAdjust');
        $select.select2({ 
            theme: 'bootstrap-5', 
            width: '100%', 
            dropdownParent: $modal,
            templateResult: formatExamOption,
            templateSelection: formatExamOption
        });
    }
    if (selectedId) {
        $select.val(selectedId).trigger('change');
    }
};

$(document).on('click', '#btnAddNewAdjustPatient', function() {
    var $input = $('#adjustAdditionalPax');
    var currentAdd = parseInt($input.val()) || 0;
    var currentPax = parseInt($('#adjustCurrentPax').text()) || 0;
    
    if (currentPax + currentAdd < 10) {
        $input.val(currentAdd + 1);
        renderAdjustPaxSections();
    } else {
        Swal.fire('Info', 'Total pax maksimal adalah 10.', 'info');
    }
});

$(document).on('click', '.btn-remove-adjust-pax', function() {
    var $input = $('#adjustAdditionalPax');
    var currentAdd = parseInt($input.val()) || 0;
    
    if (currentAdd > 1) {
        $input.val(currentAdd - 1);
        renderAdjustPaxSections();
    } else {
        Swal.fire('Info', 'Minimal 1 pax tambahan jika ingin menggunakan form ini.', 'info');
    }
});

window.submitAdjust = function() {
    const id = $('#adjustBookingId').val();
    const add = parseInt($('#adjustAdditionalPax').val());
    const current = parseInt($('#adjustCurrentPax').text()) || 0;
    if (!add || add < 1) { showWarning('Minimal 1!'); return; }
    if (current + add > 10) { showWarning('Total pax tidak boleh lebih dari 10!'); return; }
    
    // Collect data pasien tambahan
    var patients = [];
    $('#adjustPaxSectionsWrapper .card').each(function(i) {
        var exams = [];
        $(this).find('select[name*="[exams]"]').each(function() {
            if ($(this).val()) exams.push($(this).val());
        });
        patients.push({
            nama: $(this).find('input[name*="[nama]"]').val() || `Pasien ${parseInt($('#adjustCurrentPax').text()) + i + 1}`,
            exams: exams
        });
    });

    showConfirm(`Tambah ${add} pax?`, 'Konfirmasi', function() {
        postBookingAction({ 
            action: 'adjust', 
            id: id, 
            additional_pax: add,
            patients: JSON.stringify(patients)
        });
    });
};
</script>






<style>
    .booking-detail-card {
        border: 1px solid #dfe3ea;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }
    .booking-detail-label {
        color: #667085;
        font-size: .82rem;
        margin-bottom: .35rem;
    }
    .booking-detail-value {
        font-weight: 700;
        font-size: 1.05rem;
        color: #1d2939;
        line-height: 1.35;
    }
    .booking-detail-sep {
        color: #98a2b3;
        font-weight: 500;
        margin: 0 .1rem;
    }
    .booking-pasien-content .fw-semibold {
        font-size: 1.05rem;
        color: #1d2939;
    }
    .booking-pasien-content .small.text-success {
        font-size: .95rem;
        line-height: 1.3;
    }
    .booking-oos-alert {
        border-color: rgba(220, 53, 69, .35);
        background: #fff5f5;
    }
    .booking-oos-list {
        padding-left: 1.15rem;
        margin-top: .2rem;
        max-height: 180px;
        overflow-y: auto;
    }
    .booking-oos-item {
        margin-bottom: .2rem;
        line-height: 1.35;
    }
</style>

<script>
window.toggleActionDrawer = function(btn) {
    const $btn = $(btn);
    const $drawer = $btn.next('.action-drawer');
    
    // Close other drawers
    $('.action-drawer.open').not($drawer).removeClass('open');
    $('.btn-aksi-toggle.active').not($btn).removeClass('active');
    
    // Toggle current
    $drawer.toggleClass('open');
    $btn.toggleClass('active');
};

window.loadBookingHistory = function() {
    var id = window.currentDetailBookingId;
    if (!id) return;

    var $body = $('#bookingHistoryBody');
    $body.html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin d-block h4 mb-2"></i><span>Memuat riwayat...</span></div>');

    $.ajax({
        url: 'actions/get_booking_history.php',
        method: 'GET',
        data: { id: id },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $body.html('<div class="alert alert-danger mx-2">' + res.message + '</div>');
                return;
            }
            if (!res.data || res.data.length === 0) {
                $body.html('<div class="text-center py-5 text-muted"><i class="fas fa-history d-block h3 mb-2 opacity-50"></i>Belum ada riwayat perubahan.</div>');
                return;
            }

            var html = '<div class="px-2 pt-2">';
            var keyLabels = {
                'butuh_fu': 'Follow Up',
                'status': 'Status',
                'tanggal': 'Tanggal',
                'jam': 'Jam',
                'booking_type': 'Tipe Booking',
                'jotform_submitted': 'Jotform',
                'catatan': 'Catatan',
                'reschedule_split': 'Reschedule Split',
                'source': 'Sumber'
            };
            var formatVal = function(k, v) {
                if (k === 'butuh_fu') return parseInt(v) === 1 ? 'Aktif' : 'Nonaktif';
                if (k === 'jotform_submitted') return parseInt(v) === 1 ? 'Sudah' : 'Belum';
                if (v == null || v === '') return '-';
                return v;
            };

            res.data.forEach(function(item) {
                var changesHtml = '';
                if (item.changes) {
                    changesHtml = '<div class="mt-1 d-flex flex-wrap gap-1">';
                    for (var key in item.changes) {
                        var change = item.changes[key];
                        var label = keyLabels[key] || key;
                        var oldVal = formatVal(key, change.old);
                        var newVal = formatVal(key, change.new);
                        changesHtml += `<span class="change-badge"><i class="fas fa-edit me-1"></i>${label}: <span class="text-muted text-decoration-line-through x-small">${oldVal}</span> <i class="fas fa-long-arrow-alt-right mx-1 text-primary"></i> <strong>${newVal}</strong></span>`;
                    }
                    changesHtml += '</div>';
                }

                html += `
                <div class="history-item">
                    <div class="history-dot active"></div>
                    <div class="history-time">${item.created_at}</div>
                    <div class="history-user">${item.user_name} <span class="badge bg-light text-dark fw-normal border ms-1 x-small">${item.action}</span></div>
                    <div class="history-action">${item.notes || ''}</div>
                    ${changesHtml}
                </div>`;
            });
            html += '</div>';
            $body.html(html);
        },
        error: function() {
            $body.html('<div class="alert alert-danger mx-2">Gagal memuat riwayat.</div>');
        }
    });
};

// Auto-trigger new booking modal if URL contains trigger=new
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('trigger') === 'new') {
        const modalEl = document.getElementById('modalBookingBaru');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    }
    
    const triggerDetail = urlParams.get('trigger_detail');
    if (triggerDetail) {
        openBookingDetail(triggerDetail);
    }
});
</script>

<style>
    /* CIRCULAR PAGINATION STYLING */
    .pagination-circular .page-item { margin: 0 4px; }
    .pagination-circular .page-link {
        border-radius: 50% !important;
        width: 40px; height: 40px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid #e2e8f0; color: #64748b; font-weight: 500;
        transition: all 0.2s ease; background: #fff;
    }
    .pagination-circular .page-link:hover { background-color: #f8fafc; color: #204EAB; border-color: #204EAB; }
    .pagination-circular .page-item.active .page-link { background-color: #eff6ff !important; color: #204EAB !important; border-color: #bfdbfe !important; font-weight: 700; }
    .pagination-circular .page-item.disabled .page-link { background-color: #fff; color: #cbd5e1; border-color: #f1f5f9; opacity: 0.6; }
</style>




<!-- Action Hub Modal -->
<div class="modal fade" id="modalActionHub" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" style="max-width: 340px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold text-muted">Pilih Aksi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" id="actionHubBody">
                <!-- Actions populated via JS -->
            </div>
        </div>
    </div>
</div>

<script>
window.openActionHub = function(data) {
    let html = '';
    const canSuperAdmin = (data.role === 'super_admin');
    const canAdminKlinik = (data.role === 'admin_klinik');
    const canCS = (data.role === 'cs');

    // 1. Detail (Always)
    html += `
        <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); openBookingDetail(${data.id}); return false;">
            <div class="action-hub-icon bg-light-primary text-primary"><i class="fas fa-list"></i></div>
            <div class="action-hub-text">
                <span class="action-hub-label">Lihat Detail</span>
                <span class="action-hub-desc">Cek rincian item & pasien</span>
            </div>
        </a>`;

    if (data.status === 'booked' || data.status === 'rescheduled') {
        // 2. Move (Super/Admin Klinik)
        if (canSuperAdmin || canAdminKlinik) {
            html += `
                <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); openMoveModal(${data.id}, '${data.status_booking}', '${data.nomor_booking}'); return false;">
                    <div class="action-hub-icon bg-light-info text-info"><i class="fas fa-exchange-alt"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label">Pindahkan Lokasi</span>
                        <span class="action-hub-desc">Ubah ke Klinik / HC</span>
                    </div>
                </a>`;
            
            html += `
                <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); openAdjustModal(${data.id}, '${data.nomor_booking}', ${data.jumlah_pax}, ${data.klinik_id}, '${data.status_booking}'); return false;">
                    <div class="action-hub-icon bg-light-info text-info"><i class="fas fa-user-plus"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label">Sesuaikan Pax</span>
                        <span class="action-hub-desc">Tambah jumlah pasien</span>
                    </div>
                </a>`;
        }

        // 3. Edit (Super/CS)
        if (canSuperAdmin || canCS) {
            html += `
                <a href="index.php?page=booking_edit&id=${data.id}" class="action-hub-item">
                    <div class="action-hub-icon bg-light-warning text-warning"><i class="fas fa-edit"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label">Edit Booking</span>
                        <span class="action-hub-desc">Ubah data inputan</span>
                    </div>
                </a>`;
        }

        // 4. FU & Complete (Super/Admin Klinik)
        if (canSuperAdmin || canAdminKlinik) {
            if (data.butuh_fu === 0) {
                html += `
                    <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); confirmButuhFU(${data.id}); return false;">
                        <div class="action-hub-icon bg-light-danger text-danger"><i class="fas fa-phone"></i></div>
                        <div class="action-hub-text">
                            <span class="action-hub-label">Butuh Follow Up</span>
                            <span class="action-hub-desc">Tanya jadwal kedatangan</span>
                        </div>
                    </a>`;
            }

            html += `
                <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); openCompletionModal(${data.id}, '${data.nomor_booking}'); return false;">
                    <div class="action-hub-icon bg-light-success text-success"><i class="fas fa-check-double"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label text-success">Selesaikan Booking</span>
                        <span class="action-hub-desc">Pilih pasien yang selesai</span>
                    </div>
                </a>`;
        }

        // 5. Reschedule (Super/Admin Klinik/CS)
        if (canSuperAdmin || canAdminKlinik || canCS) {
            html += `
                <a href="#" class="action-hub-item" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); openRescheduleModal(${data.id}, '${data.nomor_booking}', '${data.tanggal_pemeriksaan}'); return false;">
                    <div class="action-hub-icon bg-light-warning text-warning"><i class="fas fa-calendar-alt"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label text-warning">Reschedule</span>
                        <span class="action-hub-desc">Ubah tanggal & alasan</span>
                    </div>
                </a>`;
        }

        // 6. Cancel (Super/CS)
        if (canSuperAdmin || canCS) {
            html += `
                <a href="#" class="action-hub-item text-danger" onclick="bootstrap.Modal.getInstance(document.getElementById('modalActionHub')).hide(); confirmCancel(${data.id}); return false;">
                    <div class="action-hub-icon bg-light-danger text-danger"><i class="fas fa-times"></i></div>
                    <div class="action-hub-text">
                        <span class="action-hub-label text-danger">Batalkan Booking</span>
                        <span class="action-hub-desc text-danger">Batalkan & lepas stok</span>
                    </div>
                </a>`;
        }
    }

    $('#actionHubBody').html(html);
    new bootstrap.Modal(document.getElementById('modalActionHub')).show();
};
</script>






<style>
    .bg-light-primary { background-color: rgba(32, 78, 171, 0.1); }
    .bg-light-info { background-color: rgba(13, 202, 240, 0.1); }
    .bg-light-warning { background-color: rgba(255, 193, 7, 0.1); }
    .bg-light-success { background-color: rgba(25, 135, 84, 0.1); }
    .bg-light-danger { background-color: rgba(220, 53, 69, 0.1); }
</style>

<!-- Modal Booking Detail -->
<div class="modal fade" id="modalBookingDetail" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header pb-0 border-0" style="background-color: #204EAB !important;">
                <div class="d-flex flex-column w-100">
                    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                        <h5 class="modal-title fw-bold text-white" id="bookingDetailTitle">
                            <i class="fas fa-list me-2"></i>Detail Booking
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="d-flex justify-content-between align-items-end px-2" style="margin-bottom: -1px; padding-top: 8px;">
                        <ul class="nav nav-tabs border-0" id="bookingDetailTabs" role="tablist" style="gap: 4px;">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active py-2 px-4 fw-bold border-0 rounded-top" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-pane" type="button" role="tab" style="font-size: 0.85rem; transition: all 0.2s;">
                                    <i class="fas fa-info-circle me-1"></i>Informasi
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link py-2 px-4 fw-bold border-0 rounded-top" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab" style="font-size: 0.85rem; transition: all 0.2s;" onclick="loadBookingHistory()">
                                    <i class="fas fa-history me-1"></i>History
                                </button>
                            </li>
                        </ul>
                        <div id="bookingDetailCSBadge" class="mb-1"></div>
                    </div>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="tab-content" id="bookingDetailTabContent">
                    <div class="tab-pane fade show active p-3" id="info-pane" role="tabpanel">
                        <div id="bookingDetailBody"></div>
                    </div>
                    <div class="tab-pane fade p-3" id="history-pane" role="tabpanel">
                        <div id="bookingHistoryBody">
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin d-block h4 mb-2"></i>
                                <span>Memuat riwayat...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    #bookingDetailTabs .nav-link { 
        color: rgba(255,255,255,0.7); 
        background: rgba(0,0,0,0.15);
        border: none !important;
        margin-bottom: 0;
    }
    #bookingDetailTabs .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: #fff;
    }
    #bookingDetailTabs .nav-link.active { 
        color: #204EAB !important; 
        background: #ffffff !important;
        box-shadow: none;
    }
    .bg-light-subtle { background-color: #f8fafc !important; }
    .history-item { 
        position: relative; 
        padding-left: 25px; 
        padding-bottom: 20px; 
        border-left: 2px solid #e2e8f0; 
    }
    .history-item:last-child { border-left-color: transparent; }
    .history-dot { 
        position: absolute; 
        left: -7px; 
        top: 0; 
        width: 12px; 
        height: 12px; 
        border-radius: 50%; 
        background: #cbd5e1; 
        border: 2px solid #fff; 
    }
    .history-dot.active { background: #204EAB; }
    .history-time { font-size: 0.75rem; color: #94a3b8; margin-bottom: 2px; }
    .history-user { font-size: 0.85rem; font-weight: 700; color: #334155; }
    .history-action { font-size: 0.85rem; color: #64748b; }
    .change-badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; }
</style>

<!-- Modal Move -->
<div class="modal fade" id="modalMove" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title text-white">
                    <i class="fas fa-exchange-alt"></i> Pindahkan Booking
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="moveBookingId">
                <input type="hidden" id="moveNewStatus">
                
                <div class="alert alert-info mb-0">
                    <div class="mb-2"><strong>Booking:</strong> <span id="moveNomorBooking"></span></div>
                    <div class="mb-2"><strong>Status Saat Ini:</strong> <span id="moveCurrentStatus" class="badge bg-light text-dark border"></span></div>
                    <hr>
                    <div class="d-flex align-items-center text-primary">
                        <i class="fas fa-info-circle me-2 fa-lg"></i>
                        <div>
                            Booking ini akan dipindahkan ke <strong><span id="moveTargetLabel"></span></strong>.
                            <br><small class="text-muted">Stok akan dialokasikan ulang sesuai lokasi baru.</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="button" class="btn btn-info" onclick="submitMove()">
                    <i class="fas fa-exchange-alt"></i> Pindahkan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Adjust Pax -->
<div class="modal fade" id="modalAdjust" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title text-white">
                    <i class="fas fa-edit"></i> Adjust Jumlah Pax
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adjustBookingId">
                
                <div class="alert alert-info">
                    <div class="mb-1"><strong>Booking:</strong> <span id="adjustNomorBooking"></span></div>
                    <div><strong>Pax Saat Ini:</strong> <span id="adjustCurrentPax"></span></div>
                </div>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-bold mb-0">
                            <i class="fas fa-user-plus"></i> Tambahan Pax
                        </label>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAddNewAdjustPatient">
                            <i class="fas fa-plus me-1"></i> Tambah Pasien
                        </button>
                    </div>
                    <input type="number" id="adjustAdditionalPax" class="form-control bg-light" value="1" readonly required>
                    <small class="text-muted">Gunakan tombol "Tambah Pasien" untuk menambah data.</small>
                </div>
                
                <div id="adjustPaxSectionsWrapper" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="button" class="btn btn-warning" onclick="submitAdjust()">
                    <i class="fas fa-save"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Completion -->
<div class="modal fade" id="modalCompletion" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-double me-2"></i>Selesaikan Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="completionBookingId">
                <div class="alert alert-info py-2 mb-3">
                    <div class="fw-bold">Booking: <span id="completionNomorBooking"></span></div>
                    <small>Pilih pasien yang benar-benar telah selesai melakukan pemeriksaan.</small>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="50" class="text-center">
                                    <input type="checkbox" class="form-check-input" id="checkAllPasien" checked>
                                </th>
                                <th>Nama Pasien</th>
                                <th>Pemeriksaan</th>
                                <th width="200">Status Akhir</th>
                            </tr>
                        </thead>
                        <tbody id="completionPasienWrapper"></tbody>
                    </table>
                </div>

                <div id="completionRescheduleSection" class="mt-3 p-3 border rounded bg-light" style="display:none;">
                    <h6 class="fw-bold text-warning"><i class="fas fa-calendar-alt me-2"></i>Detail Reschedule</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="x-small fw-bold text-muted text-uppercase">Tanggal Baru</label>
                            <input type="date" id="completionRescheduleDate" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="x-small fw-bold text-muted text-uppercase">Jam Baru</label>
                            <input type="time" id="completionRescheduleTime" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-5">
                            <label class="x-small fw-bold text-muted text-uppercase">Alasan Reschedule</label>
                            <input type="text" id="completionRescheduleReason" class="form-control form-control-sm" placeholder="Contoh: Tensi tinggi">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success px-4" onclick="submitCompletion()">
                    <i class="fas fa-check-circle me-1"></i> Proses Selesai
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Reschedule -->
<div class="modal fade" id="modalReschedule" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Reschedule Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rescheduleBookingId">
                <div class="alert alert-warning py-2 mb-3">
                    <div class="fw-bold">Booking: <span id="rescheduleNomorBooking"></span></div>
                </div>
                
                <div class="row">
                    <div class="col-md-7">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tanggal Pemeriksaan Baru</label>
                            <input type="date" id="rescheduleDate" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jam Baru</label>
                            <input type="time" id="rescheduleTime" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Alasan Reschedule <span class="text-danger">*</span></label>
                    <textarea id="rescheduleReason" class="form-control" rows="3" placeholder="Contoh: Kondisi pasien tidak memungkinkan (tensi tinggi), permintaan pasien, dsb."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning px-4" onclick="submitReschedule()">
                    <i class="fas fa-save me-1"></i> Simpan Reschedule
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.openCompletionModal = function(id, nomorBooking) {
    $('#completionBookingId').val(id);
    $('#completionNomorBooking').text(nomorBooking);
    $('#completionPasienWrapper').html('<tr><td colspan="4" class="text-center py-3"><i class="fas fa-spinner fa-spin me-2"></i>Memuat data pasien...</td></tr>');
    $('#completionRescheduleSection').hide();
    $('#completionRescheduleDate').val('');
    $('#completionRescheduleReason').val('');
    
    $.post('api/ajax_booking_detail.php', { id: id }, function(res) {
        if (res.success) {
            let html = '';
            res.pasien_list.forEach(function(p, i) {
                html += `
                <tr>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input cb-pasien-done" value="${p.id}" checked data-index="${i}">
                    </td>
                    <td><div class="fw-bold">${p.nama_pasien}</div></td>
                    <td><div class="x-small text-muted">${p.exams}</div></td>
                    <td>
                        <select class="form-select form-select-sm sel-pasien-fallback" data-index="${i}" disabled>
                            <option value="done" selected>Completed</option>
                            <option value="reschedule">Reschedule</option>
                            <option value="cancel">Cancel</option>
                        </select>
                    </td>
                </tr>`;
            });
            $('#completionPasienWrapper').html(html);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCompletion')).show();
        } else {
            Swal.fire('Gagal', res.message, 'error');
        }
    });
};

$(document).on('change', '#checkAllPasien', function() {
    $('.cb-pasien-done').prop('checked', $(this).prop('checked')).trigger('change');
});

$(document).on('change', '.cb-pasien-done', function() {
    let idx = $(this).data('index');
    let isChecked = $(this).is(':checked');
    let $sel = $(`.sel-pasien-fallback[data-index="${idx}"]`);
    
    if (isChecked) {
        $sel.val('done').prop('disabled', true);
    } else {
        $sel.val('reschedule').prop('disabled', false);
    }
    
    checkNeedReschedule();
});

$(document).on('change', '.sel-pasien-fallback', function() {
    checkNeedReschedule();
});

function checkNeedReschedule() {
    let needReschedule = false;
    $('.cb-pasien-done').each(function() {
        if (!$(this).is(':checked')) {
            let idx = $(this).data('index');
            if ($(`.sel-pasien-fallback[data-index="${idx}"]`).val() === 'reschedule') {
                needReschedule = true;
            }
        }
    });
    
    if (needReschedule) $('#completionRescheduleSection').show();
    else $('#completionRescheduleSection').hide();
}

window.submitCompletion = function() {
    const id = $('#completionBookingId').val();
    const doneIds = [];
    const fallback = {};
    
    $('.cb-pasien-done:checked').each(function() {
        doneIds.push($(this).val());
    });
    
    $('.cb-pasien-done:not(:checked)').each(function() {
        let idx = $(this).data('index');
        fallback[$(this).val()] = $(`.sel-pasien-fallback[data-index="${idx}"]`).val();
    });
    
    if (doneIds.length === 0 && Object.keys(fallback).length === 0) {
        Swal.fire('Error', 'Pilih setidaknya satu tindakan untuk pasien.', 'error');
        return;
    }
    
    const rescheduleDate = $('#completionRescheduleDate').val();
    const rescheduleTime = $('#completionRescheduleTime').val();
    const rescheduleReason = $('#completionRescheduleReason').val();
    
    // Validate reschedule
    let needReschedule = false;
    for (let pid in fallback) {
        if (fallback[pid] === 'reschedule') needReschedule = true;
    }
    
    if (needReschedule && (!rescheduleDate || !rescheduleReason)) {
        Swal.fire('Error', 'Tanggal baru dan Alasan Reschedule wajib diisi.', 'warning');
        return;
    }

    showConfirm('Proses status penyelesaian booking ini?', 'Konfirmasi', function() {
        postBookingAction({
            action: 'done_partial',
            id: id,
            done_ids: doneIds,
            fallback: fallback,
            reschedule_date: rescheduleDate,
            reschedule_time: rescheduleTime,
            reschedule_reason: rescheduleReason
        });
        bootstrap.Modal.getInstance(document.getElementById('modalCompletion')).hide();
    });
};

window.openRescheduleModal = function(id, nomorBooking, currentDate) {
    $('#rescheduleBookingId').val(id);
    $('#rescheduleNomorBooking').text(nomorBooking);
    $('#rescheduleDate').val(currentDate);
    $('#rescheduleReason').val('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalReschedule')).show();
};

window.submitReschedule = function() {
    const id = $('#rescheduleBookingId').val();
    const date = $('#rescheduleDate').val();
    const time = $('#rescheduleTime').val();
    const reason = $('#rescheduleReason').val();
    
    if (!date || !reason) {
        Swal.fire('Error', 'Tanggal dan Alasan Reschedule wajib diisi.', 'warning');
        return;
    }
    
    postBookingAction({
        action: 'reschedule',
        id: id,
        new_date: date,
        new_time: time,
        reason: reason
    });
    bootstrap.Modal.getInstance(document.getElementById('modalReschedule')).hide();
};

$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#bookingTable')) {
        $('#bookingTable').DataTable().destroy();
    }
    $('#bookingTable').DataTable({
        "order": [[ 4, "desc" ]], // Sort by Jadwal DESC by default
        "pageLength": 10,
        "language": {
            "search": "Filter di tabel:",
            "lengthMenu": "Tampilkan _MENU_ data",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "paginate": {
                "first": '<i class="fas fa-angle-double-left"></i>',
                "last": '<i class="fas fa-angle-double-right"></i>',
                "next": '<i class="fas fa-chevron-right"></i>',
                "previous": '<i class="fas fa-chevron-left"></i>'
            }
        }
    });
});
</script>

<style>
/* DATA TABLES CIRCULAR PAGINATION */
.dataTables_wrapper .dataTables_paginate {
    padding-top: 1.5rem;
}
.dataTables_wrapper .dataTables_paginate .paginate_button {
    border-radius: 50% !important;
    width: 38px !important;
    height: 38px !important;
    padding: 0 !important;
    margin: 0 3px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid #e2e8f0 !important;
    background: #fff !important;
    color: #64748b !important;
    font-weight: 500 !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #f8fafc !important;
    color: #204EAB !important;
    border-color: #204EAB !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current, 
.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #eff6ff !important;
    color: #204EAB !important;
    border-color: #bfdbfe !important;
    font-weight: 700 !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled:active {
    background: #fff !important;
    color: #cbd5e1 !important;
    border-color: #f1f5f9 !important;
    opacity: 0.5 !important;
    cursor: default !important;
}
/* Hide the default hover state of datatables */
.dataTables_wrapper .dataTables_paginate .paginate_button:active {
    box-shadow: none !important;
    background: #eff6ff !important;
}
</style>


