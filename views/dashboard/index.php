<?php
$nama = (string)($_SESSION['nama_lengkap'] ?? 'User');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$klinik_id = (int)($_SESSION['klinik_id'] ?? 0);

$klinik_name = '';
if ($klinik_id > 0) {
    $rk = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
    if ($rk && $rk->num_rows > 0) $klinik_name = (string)($rk->fetch_assoc()['nama_klinik'] ?? '');
}

$total_barang = 0;
$low_stock = 0;
$low_stock_label = 'Low Stock (Gudang)';
$low_stock_breakdown = '';
$pending_requests = 0;
$today_bookings = 0;
$approval_klinik_cnt = 0;
$last_mirror_update = '';

$r = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror");
if ($r && $r->num_rows > 0) $last_mirror_update = (string)($r->fetch_assoc()['last_update'] ?? '');

if ($role === 'super_admin') {
    // GLOBAL STATS (ALL LOCATIONS)
    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_barang");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $low_stock_label = 'Low Stock (Semua Lokasi)';

    // Gudang utama + seluruh location mirror (onsite + HC)
    $low_gudang = 0;
    $low_onsite = 0;
    $low_hc = 0;

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stok_gudang_utama s JOIN inventory_barang b ON s.barang_id = b.id WHERE s.qty <= b.stok_minimum");
    if ($res) $low_gudang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stock_mirror sm JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) JOIN inventory_klinik k ON TRIM(sm.location_code) = TRIM(k.kode_klinik) WHERE sm.qty <= b.stok_minimum");
    if ($res) $low_onsite = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stock_mirror sm JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) JOIN inventory_klinik k ON TRIM(sm.location_code) = TRIM(k.kode_homecare) WHERE sm.qty <= b.stok_minimum");
    if ($res) $low_hc = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $low_stock = $low_gudang + $low_onsite + $low_hc;
    $low_stock_breakdown = "Onsite: $low_onsite • HC: $low_hc";

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE status IN ('pending', 'pending_gudang', 'pending_spv')");
    $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'admin_gudang') {
    // GUDANG UTAMA STATS
    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_barang");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stok_gudang_utama s JOIN inventory_barang b ON s.barang_id = b.id WHERE s.qty <= b.stok_minimum");
    $low_stock = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE status IN ('pending', 'pending_gudang', 'pending_spv')");
    $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif (in_array($role, ['admin_klinik', 'cs', 'spv_klinik'])) {
    // KLINIK STATS
    $kode_klinik = '';
    $res_klinik = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
    if ($res_klinik && $res_klinik->num_rows > 0) {
        $k_row = $res_klinik->fetch_assoc();
        $kode_klinik = $conn->real_escape_string(trim((string)$k_row['kode_klinik']));
        $kode_homecare = $conn->real_escape_string(trim((string)$k_row['kode_homecare']));
        
        $union_sql = "SELECT odoo_product_id FROM inventory_stock_mirror WHERE TRIM(location_code) = '$kode_klinik'";
        if ($kode_homecare !== '') {
            $union_sql .= " UNION SELECT odoo_product_id FROM inventory_stock_mirror WHERE TRIM(location_code) = '$kode_homecare'";
        }
        $res = $conn->query("SELECT COUNT(*) as cnt FROM ($union_sql) t");
        if ($res) $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);
        
        $low_onsite = 0;
        $low_hc = 0;
        $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stock_mirror sm JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) WHERE TRIM(sm.location_code) = '$kode_klinik' AND sm.qty <= b.stok_minimum");
        if ($res) $low_onsite = (int)($res->fetch_assoc()['cnt'] ?? 0);
        if ($kode_homecare !== '') {
            $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stock_mirror sm JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) WHERE TRIM(sm.location_code) = '$kode_homecare' AND sm.qty <= b.stok_minimum");
            if ($res) $low_hc = (int)($res->fetch_assoc()['cnt'] ?? 0);
        }
        $low_stock = $low_onsite + $low_hc;
        $low_stock_breakdown = "Onsite: $low_onsite • HC: $low_hc";
    }

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE ((dari_level = 'klinik' AND dari_id = $klinik_id) OR (ke_level = 'klinik' AND ke_id = $klinik_id)) AND status IN ('pending', 'pending_spv')");
    if ($res) $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE klinik_id = $klinik_id AND tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    if ($res) $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'petugas_hc') {
    // HC STATS
    $res = $conn->query("SELECT COUNT(DISTINCT barang_id) as cnt FROM inventory_stok_tas_hc WHERE user_id = $user_id AND qty > 0");
    if ($res) $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE created_by = $user_id AND status = 'pending'");
    if ($res) $pending_requests = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE tanggal_pemeriksaan = CURDATE() AND status = 'booked'");
    if ($res) $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
} elseif ($role === 'admin_hc') {
    // ADMIN HC STATS
    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_barang");
    $total_barang = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $low_stock_label = 'Low Stock (Semua HC)';
    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_stock_mirror sm JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang) JOIN inventory_klinik k ON TRIM(sm.location_code) = TRIM(k.kode_homecare) WHERE sm.qty <= b.stok_minimum");
    $low_stock = (int)($res->fetch_assoc()['cnt'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE tanggal_pemeriksaan = CURDATE() AND status = 'booked' AND status_booking LIKE '%HC%'");
    $today_bookings = (int)($res->fetch_assoc()['cnt'] ?? 0);
}

$upcoming_bookings = [];
$book_cond = "1=1";
// Schedule only specific for clinic roles
if (in_array($role, ['cs', 'admin_klinik', 'spv_klinik'], true) && $klinik_id > 0) {
    $book_cond = "b.klinik_id = $klinik_id";
} elseif ($role === 'admin_hc') {
    $book_cond = "b.status_booking LIKE '%HC%'";
}
$sql_book = "SELECT b.*, k.nama_klinik,
             (SELECT GROUP_CONCAT(bp.nama_pasien SEPARATOR ', ') FROM inventory_booking_pasien bp WHERE bp.booking_id = b.id) as nama_pasien_list
             FROM inventory_booking_pemeriksaan b
             LEFT JOIN inventory_klinik k ON b.klinik_id = k.id
             WHERE $book_cond
             AND b.tanggal_pemeriksaan BETWEEN CURDATE() AND (CURDATE() + INTERVAL 1 DAY)
             AND b.status = 'booked'
             ORDER BY b.tanggal_pemeriksaan ASC, b.created_at ASC";
$res_book = $conn->query($sql_book);
if ($res_book) while ($r = $res_book->fetch_assoc()) $upcoming_bookings[] = $r;

if (!function_exists('fmt_num_clean')) {
    function fmt_num_clean($value): string {
        $n = (float)$value;
        if (abs($n - round($n)) < 0.00001) {
            return number_format((float)round($n), 0, ',', '.');
        }
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}

$critical_low_stock = [];
if ($role === 'super_admin') {
    $sql_critical = "
        SELECT * FROM (
            SELECT
                b.kode_barang,
                b.nama_barang,
                s.qty AS stok_saat_ini,
                b.stok_minimum,
                'Gudang Utama' AS lokasi,
                'gudang' AS tipe_lokasi
            FROM inventory_stok_gudang_utama s
            JOIN inventory_barang b ON b.id = s.barang_id
            WHERE s.qty <= (b.stok_minimum * 0.5)
            UNION ALL
            SELECT
                b.kode_barang,
                b.nama_barang,
                sm.qty AS stok_saat_ini,
                b.stok_minimum,
                COALESCE(k1.nama_klinik, k2.nama_klinik, TRIM(sm.location_code), '-') AS lokasi,
                CASE WHEN k2.id IS NOT NULL THEN 'hc' ELSE 'onsite' END AS tipe_lokasi
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang)
            LEFT JOIN inventory_klinik k1 ON TRIM(sm.location_code) = TRIM(k1.kode_klinik)
            LEFT JOIN inventory_klinik k2 ON TRIM(sm.location_code) = TRIM(k2.kode_homecare)
            WHERE sm.qty <= (b.stok_minimum * 0.5)
        ) x
        ORDER BY (x.stok_saat_ini / NULLIF(x.stok_minimum, 0)) ASC, x.stok_saat_ini ASC
        LIMIT 3
    ";
    $r_critical = $conn->query($sql_critical);
    while ($r_critical && ($row = $r_critical->fetch_assoc())) $critical_low_stock[] = $row;
} elseif ($role === 'admin_gudang') {
    $sql_critical = "
        SELECT
            b.kode_barang,
            b.nama_barang,
            s.qty AS stok_saat_ini,
            b.stok_minimum,
            'Gudang Utama' AS lokasi,
            'gudang' AS tipe_lokasi
        FROM inventory_stok_gudang_utama s
        JOIN inventory_barang b ON b.id = s.barang_id
        WHERE s.qty <= (b.stok_minimum * 0.5)
        ORDER BY (s.qty / NULLIF(b.stok_minimum, 0)) ASC, s.qty ASC
        LIMIT 3
    ";
    $r_critical = $conn->query($sql_critical);
    while ($r_critical && ($row = $r_critical->fetch_assoc())) $critical_low_stock[] = $row;
} elseif (in_array($role, ['admin_klinik', 'cs', 'spv_klinik'], true) && $klinik_id > 0) {
    $res_k = $conn->query("SELECT kode_klinik, kode_homecare, nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
    $k = $res_k && $res_k->num_rows > 0 ? $res_k->fetch_assoc() : null;
    $kode_klinik2 = trim((string)($k['kode_klinik'] ?? ''));
    $kode_homecare2 = trim((string)($k['kode_homecare'] ?? ''));
    $nama_klinik2 = trim((string)($k['nama_klinik'] ?? 'Klinik'));
    if ($kode_klinik2 !== '') {
        $where_loc = "TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_klinik2) . "'";
        if ($kode_homecare2 !== '') {
            $where_loc .= " OR TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare2) . "'";
        }
        $sql_critical = "
            SELECT
                b.kode_barang,
                b.nama_barang,
                sm.qty AS stok_saat_ini,
                b.stok_minimum,
                CASE
                    WHEN '" . $conn->real_escape_string($kode_homecare2) . "' <> '' AND TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare2) . "'
                        THEN CONCAT('" . $conn->real_escape_string($nama_klinik2) . "', ' (HC)')
                    ELSE CONCAT('" . $conn->real_escape_string($nama_klinik2) . "', ' (Onsite)')
                END AS lokasi,
                CASE
                    WHEN '" . $conn->real_escape_string($kode_homecare2) . "' <> '' AND TRIM(sm.location_code) = '" . $conn->real_escape_string($kode_homecare2) . "'
                        THEN 'hc' ELSE 'onsite'
                END AS tipe_lokasi
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (sm.odoo_product_id = b.odoo_product_id OR sm.kode_barang = b.kode_barang)
            WHERE ($where_loc)
              AND sm.qty <= (b.stok_minimum * 0.5)
            ORDER BY (sm.qty / NULLIF(b.stok_minimum, 0)) ASC, sm.qty ASC
            LIMIT 3
        ";
        $r_critical = $conn->query($sql_critical);
        while ($r_critical && ($row = $r_critical->fetch_assoc())) $critical_low_stock[] = $row;
    }
}
?>
<div class="row mb-2 align-items-center">
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
            <?php if (in_array($role, ['cs', 'super_admin'], true)): ?>
                <a href="index.php?page=booking&trigger=new" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>Booking
                </a>
            <?php endif; ?>
            <?php if (!in_array($role, ['cs', 'admin_hc'])): ?>
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
                <div class="text-muted small text-uppercase fw-semibold mb-1">Total Items</div>
                <div class="h4 mb-0 fw-bold text-primary"><?= (int)$total_barang ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100" role="button" onclick="showLowStockDetails()" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1"><?= htmlspecialchars($low_stock_label) ?></div>
                <div class="h4 mb-0 fw-bold <?= $low_stock > 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$low_stock ?> <i class="fas fa-info-circle ms-1 small opacity-50"></i></div>
                <?php if ($low_stock_breakdown !== ''): ?>
                    <div class="small text-muted mt-1"><?= htmlspecialchars($low_stock_breakdown) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Pending Request</div>
                <div class="h4 mb-0 fw-bold <?= $pending_requests > 0 ? 'text-warning' : 'text-success' ?>"><?= (int)$pending_requests ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Booking Hari Ini</div>
                <div class="h4 mb-0 fw-bold text-info"><?= (int)$today_bookings ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Content Grid -->
<div class="row mb-4">
    <!-- Analytics Section -->
    <div class="col-lg-8 mb-4 mb-lg-0">
        <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center" style="border-radius:16px 16px 0 0;">
                <span><i class="fas fa-chart-line text-primary-custom me-2"></i> Trend Konsumsi BHP Harian</span>
                <span class="badge bg-light text-dark fw-normal">Onsite vs HC (7 Hari)</span>
            </div>
            <div class="card-body">
                <div style="height: 300px;">
                    <canvas id="bhpTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center" style="border-radius:16px 16px 0 0;">
                <span><i class="fas fa-fire text-danger me-2"></i> Top 10 Item Paling Cepat Habis</span>
                <span class="badge bg-light text-dark fw-normal">Qty Keluar (30 Hari)</span>
            </div>
            <div class="card-body">
                <div style="height: 340px;">
                    <canvas id="topItemsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Side Panels -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3" style="border-radius:16px 16px 0 0;">
                <i class="fas fa-calendar-alt text-primary-custom me-2"></i> Jadwal Pemeriksaan
                <?= in_array($role, ['cs', 'admin_klinik', 'spv_klinik'], true) ? '' : '<span class="badge bg-info ms-2">Semua Klinik</span>' ?>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($upcoming_bookings)): ?>
                    <div class="list-group list-group-flush">
                        <?php 
                        $display_bookings = array_slice($upcoming_bookings, 0, 3);
                        foreach ($display_bookings as $b): 
                        ?>
                        <?php
                            $sb = (string)($b['status_booking'] ?? '');
                            $is_hc_booking = (stripos($sb, 'HC') !== false);
                            $layanan_badge_class = $is_hc_booking ? 'bg-warning bg-opacity-25 text-dark' : 'bg-primary bg-opacity-10 text-primary';
                            $layanan_label = $is_hc_booking ? 'HC' : 'Onsite';
                        ?>
                        <div class="list-group-item border-0 px-3 py-3 border-bottom">
                            <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($b['nama_pasien_list'] ?? 'Tanpa Nama') ?></h6>
                                    <small class="text-secondary mt-1 d-block"><i class="fas fa-hospital me-1"></i> <?= htmlspecialchars($b['nama_klinik'] ?? '-') ?></small>
                                    <div class="mt-1">
                                        <span class="badge rounded-pill small <?= $layanan_badge_class ?>">
                                            <?= $is_hc_booking ? '<i class="fas fa-briefcase-medical me-1"></i>' : '<i class="fas fa-clinic-medical me-1"></i>' ?>
                                            <?= htmlspecialchars($layanan_label) ?>
                                        </span>
                                    </div>
                                </div>
                                <?php
                                $date = date('Y-m-d', strtotime($b['tanggal_pemeriksaan']));
                                if ($date == date('Y-m-d')) echo '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Hari Ini</span>';
                                elseif ($date == date('Y-m-d', strtotime('+1 day'))) echo '<span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3">Besok</span>';
                                else echo '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill">' . date('d M', strtotime($b['tanggal_pemeriksaan'])) . '</span>';
                                ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-end">
                                <div>
                                    <small class="text-muted d-block"><i class="fas fa-hashtag me-1"></i> <?= htmlspecialchars((string)($b['nomor_booking'] ?? '')) ?></small>
                                    <small class="text-muted"><i class="fas fa-clock me-1"></i> Status: <span class="text-primary"><?= ucfirst((string)($b['status'] ?? '')) ?></span></small>
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
                <a href="index.php?page=booking" class="text-decoration-none small fw-bold">Tampilkan Semua <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:16px;">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center" style="border-radius:16px 16px 0 0;">
                <span><i class="fas fa-radiation-alt text-danger me-2"></i> Low Stock Kritis (Top 3)</span>
                <span class="badge bg-light text-dark fw-normal">&le; 50% Min</span>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($critical_low_stock)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($critical_low_stock as $it): ?>
                            <?php
                            $tipe = strtolower((string)($it['tipe_lokasi'] ?? ''));
                            $badge = 'bg-secondary';
                            $label = 'Lainnya';
                            if ($tipe === 'hc') { $badge = 'bg-warning text-dark'; $label = 'HC'; }
                            elseif ($tipe === 'onsite') { $badge = 'bg-info text-dark'; $label = 'Onsite'; }
                            elseif ($tipe === 'gudang') { $badge = 'bg-primary'; $label = 'Gudang'; }
                            ?>
                            <div class="list-group-item border-0 px-3 py-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars((string)($it['nama_barang'] ?? '-')) ?></div>
                                    <span class="badge <?= $badge ?>"><?= $label ?></span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars((string)($it['lokasi'] ?? '-')) ?>
                                    <span class="mx-2">•</span>
                                    <span><?= htmlspecialchars((string)($it['kode_barang'] ?? '-')) ?></span>
                                </div>
                                <div class="d-flex justify-content-between small">
                                    <span class="text-danger fw-bold">Stok: <?= fmt_num_clean($it['stok_saat_ini'] ?? 0) ?></span>
                                    <span class="text-muted">Min: <?= fmt_num_clean($it['stok_minimum'] ?? 0) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-shield-alt fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0 small">Tidak ada item kritis saat ini.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-0 text-center py-3" style="border-radius:0 0 16px 16px;">
                <a href="index.php?page=stok_klinik" class="text-decoration-none small fw-bold">Lihat Stok Detail <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>
</div>

<?php include 'chart_script.php'; ?>

<!-- Modal Low Stock Details -->
<div class="modal fade" id="modalLowStock" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-primary-custom">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Detail Item Low Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-4">Berikut adalah daftar item yang stoknya berada di bawah atau sama dengan batas minimum.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="lowStockTable">
                        <thead class="table-light">
                            <tr>
                                <th>Kategori</th>
                                <th>Lokasi</th>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th class="text-end">Stok Sekarang</th>
                                <th class="text-end">Stok Minimum</th>
                            </tr>
                        </thead>
                        <tbody id="lowStockBody">
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-primary me-2"></div> Memuat data...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
function showLowStockDetails() {
    const modal = new bootstrap.Modal(document.getElementById('modalLowStock'));
    modal.show();
    
    const body = document.getElementById('lowStockBody');
    body.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div> Memuat data...</td></tr>';

    fetch('api/ajax_low_stock_details.php')
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                if (res.data.length === 0) {
                    body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Tidak ada item low stock. Aman!</td></tr>';
                    return;
                }
                
                let html = '';
                res.data.forEach(item => {
                    const jenis = String(item.tipe_lokasi || '').toLowerCase();
                    let badge = '<span class="badge bg-secondary">Lainnya</span>';
                    if (jenis === 'hc') badge = '<span class="badge bg-warning text-dark">HC</span>';
                    else if (jenis === 'onsite') badge = '<span class="badge bg-info text-dark">Onsite</span>';
                    else if (jenis === 'gudang') badge = '<span class="badge bg-primary">Gudang</span>';
                    html += `
                        <tr>
                            <td>${badge}</td>
                            <td class="text-muted small">${item.lokasi || '-'}</td>
                            <td class="fw-semibold">${item.kode_barang}</td>
                            <td>${item.nama_barang}</td>
                            <td class="text-end fw-bold text-danger">${parseFloat(item.stok_saat_ini).toLocaleString()}</td>
                            <td class="text-end text-muted">${parseFloat(item.stok_minimum).toLocaleString()}</td>
                        </tr>
                    `;
                });
                body.innerHTML = html;
            } else {
                body.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Error: ${res.message}</td></tr>`;
            }
        })
        .catch(err => {
            body.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Gagal mengambil data.</td></tr>`;
            console.error(err);
        });
}
</script>
