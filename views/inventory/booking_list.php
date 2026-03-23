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
$filter_tujuan = (string)($_GET['tujuan'] ?? '');
$filter_status = (string)($_GET['status'] ?? '');
$filter_tipe = (string)($_GET['tipe'] ?? '');
$filter_fu = (string)($_GET['fu'] ?? '');
$has_filters = (isset($_GET['filter_today']) || $filter_tujuan !== '' || $filter_status !== '' || $filter_tipe !== '' || $filter_fu !== '');
if (!$has_filters) $filter_today = true;
$reset_url = 'index.php?page=booking';

// Fetch Bookings
$where = "1=1";
if ($_SESSION['role'] == 'admin_klinik') {
    $where .= " AND b.klinik_id = " . $_SESSION['klinik_id'];
    $where .= " AND b.status = 'booked' AND LOWER(COALESCE(b.booking_type, 'keep')) IN ('keep','fixed')";
}
if ($filter_today) {
    $where .= " AND DATE(b.created_at) = CURDATE()";
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
          ORDER BY b.id DESC";
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
            <a href="index.php?page=booking" class="btn btn-outline-secondary px-4">
                <i class="fas fa-list me-2"></i>Tampilkan Semua
            </a>
            <?php else: ?>
            <a href="index.php?page=booking&filter_today=1" class="btn shadow-sm px-4" style="background-color: #20AB5C; color: white;">
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
</style>

<div class="card booking-filter-card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="booking">
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted mb-1">Tujuan</label>
                <select name="tujuan" class="form-select">
                    <option value="" <?= $filter_tujuan === '' ? 'selected' : '' ?>>Semua</option>
                    <option value="clinic" <?= $filter_tujuan === 'clinic' ? 'selected' : '' ?>>Klinik</option>
                    <option value="hc" <?= $filter_tujuan === 'hc' ? 'selected' : '' ?>>HC</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>Semua</option>
                    <option value="booked" <?= $filter_status === 'booked' ? 'selected' : '' ?>>Booked</option>
                    <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted mb-1">Tipe</label>
                <select name="tipe" class="form-select">
                    <option value="" <?= $filter_tipe === '' ? 'selected' : '' ?>>Semua</option>
                    <option value="keep" <?= $filter_tipe === 'keep' ? 'selected' : '' ?>>Keep</option>
                    <option value="fixed" <?= $filter_tipe === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                    <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                    <option value="cancel" <?= $filter_tipe === 'cancel' ? 'selected' : '' ?>>Dibatalkan (CS)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold text-muted mb-1">Follow-up</label>
                <select name="fu" class="form-select">
                    <option value="" <?= $filter_fu === '' ? 'selected' : '' ?>>Semua</option>
                    <option value="1" <?= $filter_fu === '1' ? 'selected' : '' ?>>FU Jadwal Kedatangan</option>
                </select>
            </div>
            <div class="col-md-1 d-grid gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($has_filters): ?>
                    <a href="<?= $reset_url ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </form>
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
                        <th>Tanggal</th>
                        <th>Jam Kunjungan</th>
                        <th>Klinik</th>
                        <th>Tujuan</th>
                        <th>Jotform</th>
                        <th>Nama CS</th>
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
                        <td><?= date('d M Y', strtotime($row['tanggal_pemeriksaan'])) ?></td>
                        <td><?= htmlspecialchars($row['jam_layanan'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['nama_klinik']) ?></td>
                        <td>
                            <?php 
                            $status_booking = $row['status_booking'] ?? 'Reserved - Clinic';
                            if (strpos($status_booking, 'HC') !== false): 
                            ?>
                                <span class="badge bg-info"><i class="fas fa-home"></i> HC</span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="fas fa-hospital"></i> Klinik</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $jf = (int)($row['jotform_submitted'] ?? 0); ?>
                            <?php if ($jf === 1): ?>
                                <span class="badge bg-success">Sudah</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Belum</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['cs_name'] ?? '-') ?></td>
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
                                <button type="button" class="btn btn-sm text-white dropdown-toggle" style="background-color: #204EAB;" data-bs-toggle="dropdown" data-bs-boundary="viewport">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="return openBookingDetail(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-list text-primary"></i> Detail
                                        </a>
                                    </li>
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
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" onclick="return confirmCancel(<?= (int)$row['id'] ?>);">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    </li>
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
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title text-white fw-bold" id="modalBookingBaruLabel">
                    <i class="fas fa-calendar-plus me-2"></i>Buat Booking Baru
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formBooking" method="POST">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Info Booking -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-tag text-primary"></i> Status Booking <span class="text-danger">*</span>
                            </label>
                            <select name="status_booking" id="status_booking" class="form-select" required>
                                <option value="">Pilih...</option>
                                <option value="Reserved - Clinic">Reserved - Clinic</option>
                                <option value="Reserved - HC">Reserved - HC</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-hospital text-primary"></i> Klinik <span class="text-danger">*</span>
                            </label>
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
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar text-primary"></i> Tanggal <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-hashtag text-primary"></i> Order ID
                            </label>
                            <input type="text" name="order_id" class="form-control" placeholder="Opsional">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-user text-primary"></i> Nama Pemesan/Pasien <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="nama_pemesan" class="form-control" placeholder="Masukkan nama" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-phone text-primary"></i> Nomor Tlp
                            </label>
                            <input type="text" name="nomor_tlp" class="form-control" placeholder="08xxxxxxxxxx">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-birthday-cake text-primary"></i> Tanggal Lahir
                            </label>
                            <input type="date" name="tanggal_lahir" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-users text-primary"></i> Jumlah Pax <span class="text-danger">*</span>
                            </label>
                            <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-user text-primary"></i> Nama CS
                            </label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['nama_lengkap'] ?? '') ?>" readonly style="background-color: #f8f9fa;">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-thumbtack text-primary"></i> Status (Fixed / Keep) <span class="text-danger">*</span>
                            </label>
                            <select name="booking_type" class="form-select" required>
                                <option value="keep" selected>Keep</option>
                                <option value="fixed">Fixed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-clock text-primary"></i> Jam Layanan
                            </label>
                            <input type="time" name="jam_layanan" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="fas fa-clipboard-check text-primary"></i> Jotform Submitted <span class="text-danger">*</span>
                            </label>
                            <select name="jotform_submitted" class="form-select" required>
                                <option value="0" selected>Belum</option>
                                <option value="1">Sudah</option>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <!-- Daftar Pemeriksaan -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0 fw-bold">
                            <i class="fas fa-notes-medical text-success"></i> Daftar Pemeriksaan
                        </h6>
                        <button type="button" class="btn btn-sm btn-success" onclick="addExamRow()">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th width="60%">Pemeriksaan</th>
                                    <th width="20%">Qty</th>
                                    <th width="20%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="examTableModal">
                                <!-- Rows will be added here -->
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i> 
                        <small>Qty akan otomatis masuk ke <strong>Reserved - On Site</strong> atau <strong>Reserved - HC</strong> sesuai status booking.</small>
                    </div>

                    <!-- Catatan -->
                    <div class="mt-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-sticky-note text-primary"></i> Catatan
                        </label>
                        <textarea name="catatan" class="form-control" rows="2" placeholder="Catatan tambahan (opsional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="submit" class="btn btn-success">
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
    // Initialize DataTable without initial sorting to preserve database order (ID DESC)
    $('#bookingTable').DataTable({
        order: [], // No initial sorting, preserve database order
        pageLength: 10,
        columnDefs: [
            { orderable: false, targets: [10] } // Disable sorting on Aksi column
        ],
        language: {
            emptyTable: "Tidak ada data yang tersedia pada tabel ini",
            info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri",
            infoFiltered: "(disaring dari _MAX_ entri keseluruhan)",
            lengthMenu: "Tampilkan _MENU_ entri",
            loadingRecords: "Sedang memuat...",
            processing: "Sedang memproses...",
            search: "Cari:",
            zeroRecords: "Tidak ditemukan data yang sesuai",
            thousands: "'",
            paginate: {
                first: "Pertama",
                last: "Terakhir",
                next: "Selanjutnya",
                previous: "Sebelumnya"
            },
            aria: {
                sortAscending: ": aktifkan untuk mengurutkan kolom ke atas",
                sortDescending: ": aktifkan untuk mengurutkan kolom menurun"
            }
        }
    });

    $(document).on('show.bs.dropdown', '#bookingTable .dropdown', function() {
        $('#bookingTable td.booking-aksi-open').removeClass('booking-aksi-open');
        $(this).closest('td').addClass('booking-aksi-open');
    });
    $(document).on('hide.bs.dropdown', '#bookingTable .dropdown', function() {
        $(this).closest('td').removeClass('booking-aksi-open');
    });
    
    // Auto-fill qty when jumlah_pax changes
    $('#jumlah_pax').on('input change', function() {
        var paxValue = $(this).val() || 1;
        // Update all qty inputs in exam table
        $('#examTableModal input[name^="exams"][name$="[qty]"]').val(paxValue);
    });
    
    // Load exam options when klinik changes
    $('#klinik_id_modal').on('change', function() {
        var klinikId = $(this).val();
        if (klinikId) {
            loadExamOptions(klinikId);
        } else {
            examOptionsModal = '<option value="">Pilih klinik dulu...</option>';
            updateAllExamSelects();
        }
    });
    
    // Add first row when modal opens
    $('#modalBookingBaru').on('shown.bs.modal', function() {
        if ($('#examTableModal tr').length === 0) {
            addExamRow();
        }
    });
    
    // Clear form when modal closes
    $('#modalBookingBaru').on('hidden.bs.modal', function() {
        $('#formBooking')[0].reset();
        $('#examTableModal').empty();
    });
    
    // Handle form submit
    $('#formBooking').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.ajax({
            url: 'actions/process_booking.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSuccessRedirect('Booking berhasil dibuat!', 'index.php?page=booking');
                } else {
                    showError(response.message);
                }
            },
            error: function() {
                showError('Terjadi kesalahan. Silakan coba lagi.');
            }
        });
    });
});

function loadExamOptions(klinikId) {
    var statusBooking = $('#status_booking').val() || '';
    $.ajax({
        url: 'api/get_exam_availability.php',
        method: 'GET',
        data: { klinik_id: klinikId, status_booking: statusBooking },
        dataType: 'json',
        success: function(data) {
            examOptionsModal = '<option value="">Pilih pemeriksaan...</option>';
            if (data.length > 0) {
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
}

function updateAllExamSelects() {
    $('.exam-select-modal').each(function() {
        var currentVal = $(this).val();
        $(this).html(examOptionsModal);
        if (currentVal) {
            $(this).val(currentVal);
        }
    });
}

function addExamRow() {
    var idx = examRowIndexModal++;
    var paxValue = $('#jumlah_pax').val() || 1; // Get current pax value
    var row = `<tr>
        <td>
            <select name="exams[${idx}][pemeriksaan_id]" class="form-select form-select-sm exam-select-modal" required>
                ${examOptionsModal}
            </select>
        </td>
        <td>
            <input type="number" name="exams[${idx}][qty]" class="form-control form-control-sm" min="1" value="${paxValue}" required>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeExamRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#examTableModal').append(row);
}

function removeExamRow(btn) {
    if ($('#examTableModal tr').length > 1) {
        $(btn).closest('tr').remove();
    } else {
        showWarning('Minimal 1 pemeriksaan!');
    }
}
</script>


<script>
// Cancel Booking
function confirmCancel(id) {
    showConfirm('Batalkan booking? Stok pending akan dilepas.', 'Konfirmasi Pembatalan', function() {
        window.location = 'actions/process_booking_action.php?action=cancel&id=' + id;
    });
    return false;
}

function confirmComplete(id) {
    showConfirm('Tandai booking sebagai Completed?', 'Konfirmasi', function() {
        window.location = 'actions/process_booking_action.php?action=done&id=' + id;
    });
    return false;
}

function confirmButuhFU(id) {
    showConfirm('Tandai booking untuk FU jadwal kedatangan pasien (tanya mau datang kapan)?', 'Konfirmasi', function() {
        window.location = 'actions/process_booking_action.php?action=fu&id=' + id;
    });
    return false;
}

function openBookingDetail(id) {
    $('#bookingDetailBody').html('<div class="text-center text-muted py-3">Memuat...</div>');
    const m = new bootstrap.Modal(document.getElementById('modalBookingDetail'));
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
            const jenis = res.jenis_pemeriksaan || '-';
            const jf = parseInt(h.jotform_submitted || 0, 10) === 1 ? 'Sudah' : 'Belum';
            const bt = (h.booking_type || 'keep') === 'fixed' ? 'Fixed' : 'Keep';
            const tgl = h.tanggal_pemeriksaan ? h.tanggal_pemeriksaan : '-';
            const jam = h.jam_layanan ? h.jam_layanan : '-';
            const tlp = h.nomor_tlp ? h.nomor_tlp : '-';
            const lahir = h.tanggal_lahir ? h.tanggal_lahir : '-';
            const items = Array.isArray(res.items) ? res.items : [];
            const esc = function(v) { return $('<div>').text(v == null ? '' : String(v)).html(); };
            const rows = items.length ? items.map(function(it) {
                const txt = (it.kode_barang ? it.kode_barang : '') + ' - ' + (it.nama_barang ? it.nama_barang : '') + (it.satuan ? ' (' + it.satuan + ')' : '');
                return '<tr><td>' + esc(txt) + '</td><td class="text-end fw-semibold">' + esc(it.qty) + '</td></tr>';
            }).join('') : '<tr><td colspan="2" class="text-center text-muted py-2">Tidak ada item</td></tr>';

            $('#bookingDetailTitle').text('Detail: ' + (h.nomor_booking || ''));
            $('#bookingDetailBody').html(
                '<div class="row g-3">' +
                    '<div class="col-12">' +
                        '<div class="d-flex flex-wrap gap-2 align-items-center">' +
                            '<span class="badge bg-light text-dark border">Booking: ' + esc(bt) + '</span>' +
                            '<span class="badge bg-light text-dark border">Jotform: ' + esc(jf) + '</span>' +
                            '<span class="badge bg-light text-dark border">Status: ' + esc(h.status_booking || '-') + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<div class="border rounded-3 p-3 h-100">' +
                            '<div class="text-muted small">Tanggal</div>' +
                            '<div class="fw-semibold">' + esc(tgl) + '</div>' +
                            '<div class="text-muted small mt-2">Jam</div>' +
                            '<div class="fw-semibold">' + esc(jam) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<div class="border rounded-3 p-3 h-100">' +
                            '<div class="text-muted small">Klinik</div>' +
                            '<div class="fw-semibold">' + esc(h.nama_klinik || '-') + '</div>' +
                            '<div class="text-muted small mt-2">CS</div>' +
                            '<div class="fw-semibold">' + esc(h.cs_name || '-') + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<div class="border rounded-3 p-3 h-100">' +
                            '<div class="text-muted small">Pasien</div>' +
                            '<div class="fw-semibold">' + esc(h.nama_pemesan || '-') + '</div>' +
                            '<div class="text-muted small mt-2">Kontak</div>' +
                            '<div class="small text-muted">Tlp: ' + esc(tlp) + '</div>' +
                            '<div class="small text-muted">Lahir: ' + esc(lahir) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<div class="border rounded-3 p-3">' +
                            '<div class="text-muted small">Jenis Pemeriksaan</div>' +
                            '<div class="fw-semibold">' + esc(jenis) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<div class="border rounded-3 overflow-hidden">' +
                            '<div class="px-3 py-2 bg-light d-flex justify-content-between align-items-center">' +
                                '<div class="fw-semibold">Detail Item</div>' +
                                '<div class="text-muted small">Qty per item</div>' +
                            '</div>' +
                            '<div class="table-responsive">' +
                                '<table class="table table-sm mb-0">' +
                                    '<thead class="table-light"><tr><th>Item</th><th class="text-end" width="120">Qty</th></tr></thead>' +
                                    '<tbody>' + rows + '</tbody>' +
                                '</table>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        },
        error: function() {
            $('#bookingDetailBody').html('<div class="alert alert-danger mb-0">Gagal memuat</div>');
        }
    });
    return false;
}

// Move Modal
function openMoveModal(id, currentStatus, nomorBooking) {
    $('#moveBookingId').val(id);
    $('#moveNomorBooking').text(nomorBooking);
    $('#moveCurrentStatus').text(currentStatus);
    
    // Set new status options
    if (currentStatus.includes('Clinic')) {
        $('#moveNewStatus').val('Reserved - HC');
    } else {
        $('#moveNewStatus').val('Reserved - Clinic');
    }
    
    var modal = new bootstrap.Modal(document.getElementById('modalMove'));
    modal.show();
}

// Adjust Pax Modal
function openAdjustModal(id, nomorBooking, currentPax) {
    $('#adjustBookingId').val(id);
    $('#adjustNomorBooking').text(nomorBooking);
    $('#adjustCurrentPax').text(currentPax);
    $('#adjustAdditionalPax').val(1);
    
    var modal = new bootstrap.Modal(document.getElementById('modalAdjust'));
    modal.show();
}

// Submit Move
function submitMove() {
    const id = $('#moveBookingId').val();
    const newStatus = $('#moveNewStatus').val();
    
    showConfirm('Pindahkan booking ke ' + newStatus + '?', 'Konfirmasi Pemindahan', function() {
        window.location = 'actions/process_booking_action.php?action=move&id=' + id + '&new_status=' + encodeURIComponent(newStatus);
    });
}

// Submit Adjust
function submitAdjust() {
    const id = $('#adjustBookingId').val();
    const additionalPax = parseInt($('#adjustAdditionalPax').val());
    const currentPax = parseInt($('#adjustCurrentPax').text());
    
    if (!additionalPax || additionalPax < 1) {
        showWarning('Jumlah pax tambahan minimal 1!');
        return;
    }
    
    const newTotal = currentPax + additionalPax;
    const confirmMsg = `Tambah ${additionalPax} pax (total menjadi ${newTotal})?`;
    
    showConfirm(confirmMsg, 'Konfirmasi Perubahan', function() {
        window.location = 'actions/process_booking_action.php?action=adjust&id=' + id + '&additional_pax=' + additionalPax;
    });
}
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
