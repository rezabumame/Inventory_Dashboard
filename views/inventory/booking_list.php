<?php
check_role(['cs', 'super_admin', 'admin_klinik']);

function ensure_booking_col($column, $definition) {
    global $conn;
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `booking_pemeriksaan` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `booking_pemeriksaan` ADD COLUMN `$column` $definition");
    }
}

ensure_booking_col('booking_type', "VARCHAR(10) NULL");
ensure_booking_col('jam_layanan', "VARCHAR(10) NULL");
ensure_booking_col('jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_booking_col('cs_name', "VARCHAR(100) NULL");
ensure_booking_col('butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_booking_col('nomor_tlp', "VARCHAR(30) NULL");
ensure_booking_col('tanggal_lahir', "DATE NULL");

// Normalize nomor_booking to a shorter format (not critical identifier; ID is the primary key)
$need_norm = 0;
$r_norm = $conn->query("SELECT COUNT(*) AS cnt FROM booking_pemeriksaan WHERE (nomor_booking IS NULL OR nomor_booking = '' OR nomor_booking LIKE 'BOOK/%')");
if ($r_norm && $r_norm->num_rows > 0) $need_norm = (int)($r_norm->fetch_assoc()['cnt'] ?? 0);
if ($need_norm > 0) {
    $conn->query("
        UPDATE booking_pemeriksaan
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
$has_filters = ($show_all || isset($_GET['filter_today']) || $filter_tujuan !== '' || $filter_status !== '' || $filter_tipe !== '' || $filter_fu !== '' || $filter_start !== '' || $filter_end !== '');
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
    $where .= " AND b.status = 'booked' AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
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
if (in_array($filter_status, ['booked', 'completed', 'cancelled'], true)) {
    $where .= " AND b.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if (in_array($filter_tipe, ['keep', 'fixed', 'cancel'], true)) {
    $where .= " AND LOWER(COALESCE(b.booking_type, 'keep')) = '" . $conn->real_escape_string($filter_tipe) . "'";
}
if ($filter_fu === '1') {
    $where .= " AND b.status = 'booked' AND b.butuh_fu = 1";
}

$query = "SELECT b.*, k.nama_klinik,
          (SELECT COUNT(DISTINCT bd.barang_id) FROM booking_detail bd WHERE bd.booking_id = b.id) as total_items,
          (SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ')
           FROM booking_pasien bp
           JOIN pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
           WHERE bp.booking_id = b.id) as jenis_pemeriksaan
          FROM booking_pemeriksaan b 
          JOIN klinik k ON b.klinik_id = k.id 
          WHERE $where
          ORDER BY b.tanggal_pemeriksaan DESC, COALESCE(b.jam_layanan, '') DESC, b.id DESC";
$result = $conn->query($query);
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
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
            <?php if ($filter_today): ?>
            <a href="index.php?page=booking&show_all=1" class="btn btn-outline-secondary px-4">
                <i class="fas fa-list me-2"></i>Tampilkan Semua
            </a>
            <?php elseif ($role === 'admin_klinik'): ?>
            <a href="<?= $reset_url ?>" class="btn shadow-sm px-4" style="background-color: #20AB5C; color: white;">
                <i class="fas fa-calendar-day me-2"></i>Hari Ini Saja
            </a>
            <?php endif; ?>
        </div>
    </div>

<style>
    .booking-table-responsive { overflow-x: auto; }
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
    .booking-table td:last-child { position: sticky; right: 0; background: #fff; vertical-align: top; }
    .booking-table thead th:last-child { z-index: 7; }
    .booking-table tbody td:last-child { z-index: 6; }
    .booking-table tbody td:last-child .dropdown { position: relative; z-index: 8; }
    .booking-table tbody td.booking-aksi-open { z-index: 9999 !important; }

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
        font-size: 0.85rem;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.2s ease;
        text-align: center;
        cursor: pointer;
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
</style>

<div class="card booking-filter-card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="booking">
            <div class="col-xl-3 col-lg-6 col-md-6">
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
            <div class="col-xl-3 col-lg-6 col-md-6">
                <label class="form-label fw-bold text-muted mb-1">Status</label>
                <div class="segmented-control">
                    <input type="radio" class="btn-check" name="status" id="filter_status_all" value="" <?= $filter_status === '' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_all">Semua</label>
                    
                    <input type="radio" class="btn-check" name="status" id="filter_status_booked" value="booked" <?= $filter_status === 'booked' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_booked">Booked</label>
                    
                    <input type="radio" class="btn-check" name="status" id="filter_status_completed" value="completed" <?= $filter_status === 'completed' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_status_completed">Done</label>
                    
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
                    
                    <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                    <input type="radio" class="btn-check" name="tipe" id="filter_tipe_cancel" value="cancel" <?= $filter_tipe === 'cancel' ? 'checked' : '' ?>>
                    <label class="btn-segmented px-1" for="filter_tipe_cancel">Dibatalkan</label>
                    <?php endif; ?>
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
            const form = document.querySelector('form');
            const url = new URL('actions/export_booking.php', window.location.href);
            url.searchParams.append('start_date', form.start_date.value);
            url.searchParams.append('end_date', form.end_date.value);
            url.searchParams.append('tujuan', form.tujuan.value);
            url.searchParams.append('status', form.status.value);
            url.searchParams.append('tipe', form.tipe.value);
            url.searchParams.append('fu', form.fu.value);
            <?php if ($filter_today): ?>
            url.searchParams.append('filter_today', '1');
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
                        <th>Klinik</th>
                        <th>Jadwal</th>
                        <th>Tujuan / Jotform</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
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
                            ?>
                            <span class="badge <?= $bt_badge ?>"><?= $bt_label ?></span>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['nama_pemesan'] ?? 'N/A') ?></div>
                            <?php if (!empty($row['nomor_tlp'])): ?>
                                <div class="booking-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($row['nomor_tlp']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['tanggal_lahir'])): ?>
                                <div class="booking-muted"><i class="fas fa-birthday-cake me-1"></i><?= htmlspecialchars(date('d M Y', strtotime($row['tanggal_lahir']))) ?></div>
                            <?php endif; ?>
                            <div class="booking-muted"><i class="fas fa-users me-1"></i>Pax: <?= (int)($row['jumlah_pax'] ?? 1) ?> <span class="mx-1">•</span> Items: <?= (int)($row['total_items'] ?? 0) ?></div>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($row['jenis_pemeriksaan'] ?? '-') ?></small></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['nama_klinik']) ?></div>
                            <div class="booking-muted"><i class="fas fa-user me-1"></i><span class="small">CS: </span><?= htmlspecialchars($row['cs_name'] ?? '-') ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= date('d M Y', strtotime($row['tanggal_pemeriksaan'])) ?></div>
                            <div class="booking-muted"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($row['jam_layanan'] ?? '-') ?></div>
                        </td>
                        <td>
                            <?php 
                            $status_booking = $row['status_booking'] ?? 'Reserved - Clinic';
                            $is_hc = (strpos($status_booking, 'HC') !== false);
                            $jf = (int)($row['jotform_submitted'] ?? 0);
                            ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($is_hc): ?>
                                    <span class="badge bg-info"><i class="fas fa-home me-1"></i>HC</span>
                                <?php else: ?>
                                    <span class="badge bg-primary"><i class="fas fa-hospital me-1"></i>Klinik</span>
                                <?php endif; ?>
                                <?php if ($jf === 1): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Jotform</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fas fa-minus me-1"></i>Belum</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $badge = 'bg-secondary';
                            if ($row['status'] == 'booked' && (int)($row['butuh_fu'] ?? 0) === 1) $badge = 'bg-danger';
                            elseif ($row['status'] == 'booked') $badge = 'bg-warning';
                            if ($row['status'] == 'completed') $badge = 'bg-success';
                            if ($row['status'] == 'cancelled') $badge = 'bg-danger';
                            ?>
                            <?php if ($row['status'] == 'booked' && (int)($row['butuh_fu'] ?? 0) === 1): ?>
                                <span class="badge <?= $badge ?>">FU Jadwal Kedatangan</span>
                            <?php else: ?>
                                <span class="badge <?= $badge ?>"><?= ucfirst($row['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($row['status'] == 'booked'): ?>
                            <div class="dropdown d-flex justify-content-end align-items-start">
                                <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" data-bs-boundary="viewport">
                                    <i class="fas fa-sliders-h me-1"></i>Aksi
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="return openBookingDetail(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-list text-primary"></i> Detail
                                        </a>
                                    </li>
                                    <?php if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_klinik'], true)): ?>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="openMoveModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['status_booking'] ?? 'Reserved - Clinic', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>'); return false;">
                                            <i class="fas fa-exchange-alt text-info"></i> Move
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="openAdjustModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nomor_booking'], ENT_QUOTES) ?>', <?= $row['jumlah_pax'] ?? 1 ?>); return false;">
                                            <i class="fas fa-user-plus text-info"></i> Adjust Pax
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php endif; ?>
                                    <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                                    <li>
                                        <a class="dropdown-item" href="index.php?page=booking_edit&id=<?= (int)$row['id'] ?>">
                                            <i class="fas fa-edit text-warning"></i> Edit Booking
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (in_array($_SESSION['role'] ?? '', ['admin_klinik', 'super_admin'], true) && (int)($row['butuh_fu'] ?? 0) === 0): ?>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="return confirmButuhFU(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-phone text-danger"></i> FU Jadwal Kedatangan
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (in_array($_SESSION['role'] ?? '', ['admin_klinik', 'super_admin'], true)): ?>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="return confirmComplete(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-check text-success"></i> Completed
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" onclick="return confirmCancel(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <?php else: ?>
                                <a href="#" class="btn btn-sm btn-outline-primary" onclick="return openBookingDetail(<?= (int)$row['id'] ?>);">
                                    <i class="fas fa-list"></i> Detail
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Booking Baru -->
<div class="modal fade" id="modalBookingBaru" tabindex="-1" aria-labelledby="modalBookingBaruLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="modalBookingBaruLabel">
                        <i class="fas fa-calendar-plus me-2 text-primary-custom"></i>Buat Booking Baru
                    </h5>
                    <div class="small text-muted">Isi data utama, lalu pilih pemeriksaan. Qty pemeriksaan otomatis mengikuti Pax.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formBooking" method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="client_request_id" id="client_request_id" value="">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
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
                                            <select name="status_booking" id="status_booking" class="form-select" required>
                                                <option value="">Pilih...</option>
                                                <option value="Reserved - Clinic">Reserved - Clinic</option>
                                                <option value="Reserved - HC">Reserved - HC</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Klinik <span class="text-danger">*</span></label>
                                            <select name="klinik_id" id="klinik_id_modal" class="form-select" required>
                                                <option value="">Pilih Klinik...</option>
                                                <?php 
                                                $klinik_res = $conn->query("SELECT * FROM klinik WHERE status = 'active' ORDER BY nama_klinik");
                                                while($k = $klinik_res->fetch_assoc()): 
                                                ?>
                                                    <option value="<?= $k['id'] ?>"><?= $k['nama_klinik'] ?></option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Jumlah Pax <span class="text-danger">*</span></label>
                                            <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control" min="1" value="1" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Order ID</label>
                                            <input type="text" name="order_id" class="form-control" placeholder="Opsional (Contoh: B12345)">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Jadwal Pemeriksaan <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="date" name="tanggal" id="booking_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required title="Tanggal">
                                                <input type="time" name="jam_layanan" id="booking_jam" class="form-control" title="Jam Layanan">
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
                                            <label class="form-label fw-semibold">Jotform Submitted <span class="text-danger">*</span></label>
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
    // Initialize DataTable
    $('#bookingTable').DataTable({
        order: [], 
        pageLength: 10,
        columnDefs: [{ orderable: false, targets: [7] }]
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
    $('#jumlah_pax').on('input change', function() {
        var paxValue = parseInt($(this).val()) || 1;
        renderPaxSections(paxValue);
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

    $('#klinik_id_modal, #status_booking').on('change', function() {
        var klinikId = $('#klinik_id_modal').val();
        if (klinikId) {
            loadExamOptions(klinikId);
        } else {
            examOptionsModal = '<option value="">Pilih klinik dulu...</option>';
            updateAllExamSelects();
        }
    });

    $(document).on('change', '.patient-exam-select[data-patient-idx="0"]', function() {
        var firstPatientExamId = $(this).val();
        var paxCount = parseInt($('#jumlah_pax').val()) || 1;
        
        if (paxCount > 1) {
            $('.patient-exam-select').not(this).each(function() {
                var $select = $(this);
                var rowIdx = $select.closest('.exam-row').data('row-idx');
                if (rowIdx === 0) {
                    $select.val(firstPatientExamId);
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
                    showSuccessRedirect('Booking berhasil dibuat!', 'index.php?page=booking');
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

    var inheritExamId = (existingData[0] && existingData[0].exams && existingData[0].exams.length > 0) ? existingData[0].exams[0] : '';

    $wrapper.empty();
    for (var i = 0; i < paxCount; i++) {
        var num = i + 1;
        var data = existingData[i] || { nama: '', tlp: '', dob: '', exams: [] };
        
        var card = `
            <div class="card border-0 shadow-sm mb-3 pax-section-card" data-patient-idx="${i}">
                <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center py-2">
                    <span class="small"><i class="fas fa-user me-2 text-primary"></i>Pasien ${num} ${i === 0 ? '(Utama)' : ''}</span>
                    ${i > 0 ? '<span class="badge bg-light text-muted fw-normal x-small border">Opsional</span>' : ''}
                </div>
                <div class="card-body py-2">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label x-small fw-semibold mb-1">Nama Pasien ${i === 0 ? '<span class="text-danger">*</span>' : ''}</label>
                            <input type="text" name="patients[${i}][nama]" class="form-control form-control-sm" placeholder="Nama Pasien ${num}" value="${data.nama}" ${i === 0 ? 'required' : ''}>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label x-small fw-semibold mb-1">Nomor Tlp</label>
                            <input type="text" name="patients[${i}][nomor_tlp]" class="form-control form-control-sm" placeholder="08xxxxxxxxxx" value="${data.tlp}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label x-small fw-semibold mb-1">Tanggal Lahir</label>
                            <input type="date" name="patients[${i}][tanggal_lahir]" class="form-control form-control-sm" value="${data.dob}">
                        </div>
                    </div>
                    
                    <div class="pemeriksaan-section">
                        <label class="form-label x-small fw-bold text-success mb-1"><i class="fas fa-notes-medical me-1"></i>Pemeriksaan</label>
                        <div class="patient-exams-list" data-patient-idx="${i}"></div>
                        <button type="button" class="btn btn-link btn-sm text-success p-0 mt-0 x-small" onclick="addPatientExamRow(${i})">
                            <i class="fas fa-plus-circle me-1"></i>Tambah Pemeriksaan
                        </button>
                    </div>
                </div>
            </div>`;
        $wrapper.append(card);
        
        if (data.exams && data.exams.length > 0) {
            data.exams.forEach(function(examId) { addPatientExamRow(i, examId); });
        } else {
            addPatientExamRow(i, (i > 0 ? inheritExamId : ''));
        }
    }
};

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
    if (selectedId) {
        $select.val(selectedId);
    } else if (patientIdx > 0 && rowIdx === 0) {
        var firstExam = $(`.patient-exams-list[data-patient-idx="0"] .patient-exam-select`).first().val();
        if (firstExam) $select.val(firstExam);
    }
};

window.removePatientExamRow = function(btn) {
    var $list = $(btn).closest('.patient-exams-list');
    if ($list.find('.exam-row').length > 1) $(btn).closest('.exam-row').remove();
    else showWarning('Setiap pasien minimal memiliki 1 pemeriksaan!');
};

window.loadExamOptions = function(klinikId) {
    var statusBooking = $('#status_booking').val() || '';
    $.ajax({
        url: 'api/get_exam_availability.php',
        method: 'GET',
        data: { klinik_id: klinikId, status_booking: statusBooking },
        dataType: 'json',
        success: function(data) {
            examOptionsModal = '<option value="">Pilih pemeriksaan...</option>';
            if (data && data.length > 0) {
                data.forEach(function(exam) {
                    examOptionsModal += `<option value="${exam.id}">${exam.name} (Ready: ${exam.qty})</option>`;
                });
            } else {
                examOptionsModal = '<option value="">Tidak ada pemeriksaan available</option>';
            }
            updateAllExamSelects();
        },
        error: function() {
            examOptionsModal = '<option value="">Error loading data</option>';
            updateAllExamSelects();
        }
    });
};

window.updateAllExamSelects = function() {
    $('.patient-exam-select').each(function() {
        var currentVal = $(this).val();
        $(this).html(examOptionsModal);
        if (currentVal) $(this).val(currentVal);
    });
};

window.postBookingAction = function(params) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'actions/process_booking_action.php';
    const payload = Object.assign({ _csrf: BOOKING_CSRF }, params || {});
    Object.keys(payload).forEach(function(k) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = k;
        inp.value = String(payload[k] == null ? '' : payload[k]);
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
};

window.confirmCancel = function(id) {
    showConfirm('Batalkan booking? Stok pending akan dilepas.', 'Konfirmasi Pembatalan', function() {
        postBookingAction({ action: 'cancel', id: id });
    });
    return false;
};

window.confirmComplete = function(id) {
    showConfirm('Tandai booking sebagai Completed?', 'Konfirmasi', function() {
        postBookingAction({ action: 'done', id: id });
    });
    return false;
};

window.confirmButuhFU = function(id) {
    showConfirm('Tandai booking untuk FU jadwal kedatangan pasien (tanya mau datang kapan)?', 'Konfirmasi', function() {
        postBookingAction({ action: 'fu', id: id });
    });
    return false;
};

window.openBookingDetail = function(id) {
    $('#bookingDetailBody').html('<div class="text-center text-muted py-3">Memuat...</div>');
    const mEl = document.getElementById('modalBookingDetail');
    const m = bootstrap.Modal.getOrCreateInstance(mEl);
    m.show();
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
            const pasienList = Array.isArray(res.pasien_list) ? res.pasien_list : [];
            const esc = function(v) { return $('<div>').text(v == null ? '' : String(v)).html(); };
            
            var rows = items.length ? items.map(function(it) {
                return '<tr><td>' + esc(it.kode_barang + ' - ' + it.nama_barang) + '</td><td class="text-end fw-semibold">' + esc(it.qty) + '</td></tr>';
            }).join('') : '<tr><td colspan="2" class="text-center text-muted py-2">Tidak ada item</td></tr>';

            var pasienHtml = pasienList.length ? pasienList.map(function(p, i) {
                return `<div class="${i > 0 ? 'mt-2 pt-2 border-top' : ''}">
                    <div class="fw-semibold">${esc(p.nama_pasien)}</div>
                    <div class="small text-muted">Tlp: ${esc(p.nomor_tlp || '-')} · Lahir: ${esc(p.tanggal_lahir || '-')}</div>
                    <div class="small text-success fw-bold mt-1">Pemeriksaan: ${esc(p.exams || '-')}</div>
                </div>`;
            }).join('') : `<div class="fw-semibold">${esc(h.nama_pemesan || '-')}</div><div class="small text-muted">Tlp: ${esc(h.nomor_tlp || '-')} · Lahir: ${esc(h.tanggal_lahir || '-')}</div>`;

            $('#bookingDetailTitle').text('Detail: ' + (h.nomor_booking || ''));
            $('#bookingDetailBody').html(`
                <div class="row g-3">
                    <div class="col-12"><div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark border">Booking: ${esc(h.booking_type || 'keep')}</span>
                        <span class="badge bg-light text-dark border">Jotform: ${parseInt(h.jotform_submitted) === 1 ? 'Sudah' : 'Belum'}</span>
                        <span class="badge bg-light text-dark border">Status: ${esc(h.status_booking || '-')}</span>
                    </div></div>
                    <div class="col-md-4"><div class="border rounded-3 p-3 h-100">
                        <div class="text-muted small">Tanggal/Jam</div><div class="fw-semibold">${esc(h.tanggal_pemeriksaan)} / ${esc(h.jam_layanan || '-')}</div>
                    </div></div>
                    <div class="col-md-4"><div class="border rounded-3 p-3 h-100">
                        <div class="text-muted small">Klinik/CS</div><div class="fw-semibold">${esc(h.nama_klinik)} / ${esc(h.cs_name || '-')}</div>
                    </div></div>
                    <div class="col-md-4"><div class="border rounded-3 p-3 h-100">
                        <div class="text-muted small">Pasien (${esc(h.jumlah_pax || 1)} pax)</div>${pasienHtml}
                    </div></div>
                    <div class="col-12"><div class="border rounded-3 p-3">
                        <div class="text-muted small">Pemeriksaan</div><div class="fw-semibold">${esc(res.jenis_pemeriksaan || '-')}</div>
                    </div></div>
                    <div class="col-12"><div class="border rounded-3 overflow-hidden">
                        <div class="px-3 py-2 bg-light d-flex justify-content-between"><strong>Detail Item</strong><small>Qty per item</small></div>
                        <div class="table-responsive"><table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table></div>
                    </div></div>
                </div>`);
        },
        error: function() { $('#bookingDetailBody').html('<div class="alert alert-danger mb-0">Gagal memuat</div>'); }
    });
    return false;
};

window.openMoveModal = function(id, currentStatus, nomorBooking) {
    $('#moveBookingId').val(id);
    $('#moveNomorBooking').text(nomorBooking);
    $('#moveCurrentStatus').text(currentStatus);
    $('#moveNewStatus').val(currentStatus.includes('Clinic') ? 'Reserved - HC' : 'Reserved - Clinic');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMove')).show();
};

window.openAdjustModal = function(id, nomorBooking, currentPax) {
    $('#adjustBookingId').val(id);
    $('#adjustNomorBooking').text(nomorBooking);
    $('#adjustCurrentPax').text(currentPax);
    $('#adjustAdditionalPax').val(1);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAdjust')).show();
};

window.submitMove = function() {
    const id = $('#moveBookingId').val();
    const newStatus = $('#moveNewStatus').val();
    showConfirm('Pindahkan booking ke ' + newStatus + '?', 'Konfirmasi', function() {
        postBookingAction({ action: 'move', id: id, new_status: newStatus });
    });
};

window.submitAdjust = function() {
    const id = $('#adjustBookingId').val();
    const add = parseInt($('#adjustAdditionalPax').val());
    if (!add || add < 1) { showWarning('Minimal 1!'); return; }
    showConfirm(`Tambah ${add} pax?`, 'Konfirmasi', function() {
        postBookingAction({ action: 'adjust', id: id, additional_pax: add });
    });
};
</script>

<!-- Modal Booking Detail -->
<div class="modal fade" id="modalBookingDetail" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="bookingDetailTitle">
                    <i class="fas fa-list me-2 text-primary-custom"></i>Detail Booking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bookingDetailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

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
                
                <div class="alert alert-info">
                    <strong>Booking:</strong> <span id="moveNomorBooking"></span><br>
                    <strong>Status Saat Ini:</strong> <span id="moveCurrentStatus"></span>
                </div>
                
                <label class="form-label fw-bold">
                    <i class="fas fa-map-marker-alt"></i> Pindah Ke
                </label>
                <select id="moveNewStatus" class="form-select">
                    <option value="Reserved - Clinic">Reserved - Clinic</option>
                    <option value="Reserved - HC">Reserved - HC</option>
                </select>
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
    <div class="modal-dialog">
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
                    <strong>Booking:</strong> <span id="adjustNomorBooking"></span><br>
                    <strong>Pax Saat Ini:</strong> <span id="adjustCurrentPax"></span>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <i class="fas fa-user-plus"></i> Tambahan Pax <span class="text-danger">*</span>
                    </label>
                    <input type="number" id="adjustAdditionalPax" class="form-control" min="1" value="1" required>
                    <small class="text-muted">Masukkan jumlah pax tambahan (bukan total baru)</small>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i> 
                    <small>Semua pemeriksaan yang ada akan ditambahkan untuk pax tambahan ini.</small>
                </div>
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
