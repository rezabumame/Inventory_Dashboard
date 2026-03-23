<?php
$nama = (string)($_SESSION['nama_lengkap'] ?? 'User');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$klinik_name = '';
if ($klinik_id > 0) {
    $rk = $conn->query("SELECT nama_klinik FROM klinik WHERE id = $klinik_id LIMIT 1");
    if ($rk && $rk->num_rows > 0) $klinik_name = (string)($rk->fetch_assoc()['nama_klinik'] ?? '');
}

$total_barang = 0;
$low_stock = 0;
$pending_requests = 0;
$today_bookings = 0;
$my_pending_requests = 0;
$today_hc_usage = 0;
$last_mirror_update = '';
$approval_klinik_cnt = 0;

$r = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror");
if ($r && $r->num_rows > 0) $last_mirror_update = (string)($r->fetch_assoc()['last_update'] ?? '');

if ($role === 'super_admin' || $role === 'admin_gudang') {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM barang");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM stok_gudang_utama s JOIN barang b ON s.barang_id = b.id WHERE s.qty <= b.stok_minimum");
    $low_stock = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE (ke_level = 'gudang_utama' AND status = 'pending') OR status = 'pending_gudang'");
    $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE status = 'pending_gudang'");
    $approval_klinik_cnt = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'admin_klinik') {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM stok_gudang_klinik WHERE klinik_id = $klinik_id AND qty > 0");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM stok_gudang_klinik s JOIN barang b ON s.barang_id = b.id WHERE s.klinik_id = $klinik_id AND s.qty <= b.stok_minimum");
    $low_stock = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE ke_level = 'klinik' AND ke_id = $klinik_id AND status = 'pending'");
    $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE dari_level = 'klinik' AND dari_id = $klinik_id AND status = 'pending'");
    $my_pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM booking_pemeriksaan WHERE klinik_id = $klinik_id AND tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'cs') {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM stok_gudang_klinik WHERE klinik_id = $klinik_id AND qty > 0");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM booking_pemeriksaan WHERE klinik_id = $klinik_id AND tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'petugas_hc') {
    $res = $conn->query("SELECT COUNT(DISTINCT barang_id) as cnt FROM stok_tas_hc WHERE user_id = $user_id AND qty > 0");
    if ($res && $res->num_rows > 0) $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE created_by = $user_id AND status = 'pending'");
    if ($res && $res->num_rows > 0) $my_pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM pemakaian_bhp WHERE jenis_pemakaian = 'hc' AND user_hc_id = $user_id AND tanggal = CURDATE()");
    if ($res && $res->num_rows > 0) $today_hc_usage = (int)($res->fetch_assoc()['cnt'] ?? 0);
}

$upcoming_bookings = [];
if (in_array($role, ['cs', 'admin_klinik'], true) && $klinik_id > 0) {
    $sql_book = "SELECT b.*,
                 (SELECT GROUP_CONCAT(bp.nama_pasien SEPARATOR ', ') FROM booking_pasien bp WHERE bp.booking_id = b.id) as nama_pasien_list
                 FROM booking_pemeriksaan b
                 WHERE b.klinik_id = $klinik_id
                 AND b.tanggal_pemeriksaan BETWEEN CURDATE() AND (CURDATE() + INTERVAL 1 DAY)
                 AND b.status = 'booked'
                 ORDER BY b.tanggal_pemeriksaan ASC, b.created_at ASC";
    $res_book = $conn->query($sql_book);
    if ($res_book) while ($r = $res_book->fetch_assoc()) $upcoming_bookings[] = $r;
}
?>
<div class="row mb-4 align-items-center">
    <div class="col">
        <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
        <div class="text-muted small mt-1">
            <?php if ($last_mirror_update !== ''): ?>
                Last sync Odoo: <?= htmlspecialchars(date('d M Y H:i', strtotime($last_mirror_update))) ?>
            <?php else: ?>
                Last sync Odoo: -
            <?php endif; ?>
            <?php if ($klinik_name !== ''): ?>
                • <?= htmlspecialchars($klinik_name) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-auto">
        <div class="d-flex flex-wrap gap-2">
            <?php if (in_array($role, ['cs', 'admin_klinik'], true)): ?>
                <a href="index.php?page=booking_create" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>Booking
                </a>
            <?php endif; ?>
            <?php if ($role !== 'cs'): ?>
                <a href="index.php?page=request" class="btn btn-outline-primary">
                    <i class="fas fa-exchange-alt me-2"></i>Request
                </a>
            <?php endif; ?>
            <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'cs'], true)): ?>
                <a href="index.php?page=stok_klinik" class="btn btn-outline-primary">
                    <i class="fas fa-hospital me-2"></i>Inventory
                </a>
            <?php endif; ?>
            <?php if ($role === 'petugas_hc' || in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik'], true)): ?>
                <a href="index.php?page=stok_petugas_hc" class="btn btn-outline-primary">
                    <i class="fas fa-briefcase-medical me-2"></i>Stok HC
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Items</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$total_barang ?></div>
            </div>
        </div>
    </div>
    <?php if ($role !== 'petugas_hc'): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Low Stock</div>
                <div class="h4 mb-0 fw-bold <?= $low_stock > 0 ? 'text-danger' : '' ?>"><?= (int)$low_stock ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Pending Masuk</div>
                <div class="h4 mb-0 fw-bold <?= $pending_requests > 0 ? 'text-warning' : '' ?>"><?= (int)$pending_requests ?></div>
            </div>
        </div>
    </div>
    <?php if (in_array($role, ['super_admin', 'admin_gudang'], true)): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Approval K-K</div>
                <div class="h4 mb-0 fw-bold <?= $approval_klinik_cnt > 0 ? 'text-warning' : '' ?>"><?= (int)$approval_klinik_cnt ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($role, ['cs', 'admin_klinik'], true)): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Booking Hari Ini</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$today_bookings ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'admin_klinik' || $role === 'petugas_hc'): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Pending Saya</div>
                <div class="h4 mb-0 fw-bold <?= $my_pending_requests > 0 ? 'text-warning' : '' ?>"><?= (int)$my_pending_requests ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'petugas_hc'): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Pemakaian HC</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$today_hc_usage ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Content Grid -->
<div class="row mb-4">
    <!-- Chart Section -->
    <div class="col-lg-8 mb-4 mb-lg-0">
        <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center" style="border-radius:16px 16px 0 0;">
                <span><i class="fas fa-chart-line text-primary-custom me-2"></i> Statistik Ringkas</span>
                <span class="badge bg-light text-dark fw-normal">Odoo + Lokal</span>
            </div>
            <div class="card-body">
                <div style="height: 350px;">
                    <canvas id="dashboardChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming Bookings Section -->
    <?php if (in_array($role, ['cs', 'admin_klinik'])): ?>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3" style="border-radius:16px 16px 0 0;">
                <i class="fas fa-calendar-alt text-primary-custom me-2"></i> Jadwal Pemeriksaan
            </div>
            <div class="card-body p-0">
                <?php if (!empty($upcoming_bookings)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcoming_bookings as $b): ?>
                        <div class="list-group-item border-0 px-3 py-3 border-bottom">
                            <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($b['nama_pasien_list'] ?? 'Tanpa Nama') ?></h6>
                                <?php 
                                $date = date('Y-m-d', strtotime($b['tanggal_pemeriksaan']));
                                if ($date == date('Y-m-d')) echo '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Hari Ini</span>';
                                elseif ($date == date('Y-m-d', strtotime('+1 day'))) echo '<span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3">Besok</span>';
                                else echo '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill">' . date('d M', strtotime($b['tanggal_pemeriksaan'])) . '</span>';
                                ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-end">
                                <div>
                                    <small class="text-muted d-block"><i class="fas fa-hashtag me-1"></i> <?= $b['nomor_booking'] ?></small>
                                    <small class="text-muted"><i class="fas fa-clock me-1"></i> Status: <span class="text-primary"><?= ucfirst($b['status']) ?></span></small>
                                </div>
                                <a href="index.php?page=booking&id=<?= $b['id'] ?>" class="btn btn-sm btn-light rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-chevron-right text-muted small"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-calendar-times fa-3x mb-3 opacity-25"></i>
                        <p class="mb-0">Tidak ada jadwal pemeriksaan hari ini.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($upcoming_bookings)): ?>
            <div class="card-footer bg-white border-0 text-center py-3" style="border-radius:0 0 16px 16px;">
                <a href="index.php?page=booking" class="text-decoration-none small fw-bold">Lihat Semua Jadwal <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($role == 'super_admin' || $role == 'admin_gudang'): ?>
    <!-- Alternative for Admin Gudang: Low Stock List -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3 text-danger" style="border-radius:16px 16px 0 0;">
                <i class="fas fa-exclamation-triangle me-2"></i> Stok Menipis
            </div>
            <div class="card-body p-0">
                <?php
                $low_q = $conn->query("SELECT b.nama_barang, s.qty, b.stok_minimum FROM stok_gudang_utama s JOIN barang b ON s.barang_id = b.id WHERE s.qty <= b.stok_minimum LIMIT 5");
                if ($low_q->num_rows > 0):
                ?>
                <ul class="list-group list-group-flush">
                    <?php while($row = $low_q->fetch_assoc()): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-3 border-bottom border-light">
                        <div>
                            <div class="fw-bold text-dark"><?= $row['nama_barang'] ?></div>
                            <small class="text-danger">Min: <?= $row['stok_minimum'] ?></small>
                        </div>
                        <span class="badge bg-danger rounded-pill"><?= $row['qty'] ?></span>
                    </li>
                    <?php endwhile; ?>
                </ul>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-25"></i>
                    <p class="mb-0">Stok aman.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'chart_script.php'; ?>
