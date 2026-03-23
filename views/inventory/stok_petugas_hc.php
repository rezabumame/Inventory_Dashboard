<?php
check_role(['petugas_hc', 'super_admin', 'admin_gudang', 'admin_klinik']);

$role = (string)($_SESSION['role'] ?? '');
$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$active_tab = (string)($_GET['tab'] ?? 'stok');
if (!in_array($active_tab, ['stok', 'history'], true)) $active_tab = 'stok';

$conn->query("
    CREATE TABLE IF NOT EXISTS stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        from_uom VARCHAR(20) NULL,
        to_uom VARCHAR(20) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

function fmt_qty($v) {
    $n = (float)($v ?? 0);
    if (abs($n - round($n)) < 0.00005) return (string)(int)round($n);
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

$selected_klinik = 0;
if (in_array($role, ['petugas_hc', 'admin_klinik'], true)) {
    $selected_klinik = $user_klinik_id;
} else {
    $selected_klinik = (int)($_GET['klinik_id'] ?? 0);
}

$petugas_list = [];
if ($selected_klinik > 0 && $role !== 'petugas_hc') {
    $res = $conn->query("SELECT id, nama_lengkap FROM users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $selected_klinik ORDER BY nama_lengkap ASC");
    while ($res && ($row = $res->fetch_assoc())) $petugas_list[] = $row;
}

$petugas_user_id = 0;
if ($role === 'petugas_hc') $petugas_user_id = $user_id;
else $petugas_user_id = (int)($_GET['petugas_user_id'] ?? 0);
if ($role !== 'petugas_hc' && count($petugas_list) === 1 && $petugas_user_id === 0) $petugas_user_id = (int)$petugas_list[0]['id'];

$petugas_row = null;
$klinik_row = null;
if ($selected_klinik > 0) $klinik_row = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM klinik WHERE id = $selected_klinik LIMIT 1")->fetch_assoc();
if ($role === 'admin_klinik' && $selected_klinik !== $user_klinik_id) $selected_klinik = $user_klinik_id;

if ($role === 'petugas_hc') {
    $petugas_row = ['id' => $user_id, 'nama_lengkap' => (string)($_SESSION['nama_lengkap'] ?? 'Petugas HC')];
} elseif ($petugas_user_id > 0) {
    $petugas_row = $conn->query("SELECT id, nama_lengkap FROM users WHERE id = $petugas_user_id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
    if ($petugas_row && $selected_klinik > 0 && (int)($conn->query("SELECT klinik_id FROM users WHERE id = $petugas_user_id LIMIT 1")->fetch_assoc()['klinik_id'] ?? 0) !== $selected_klinik) {
        $petugas_row = null;
    }
}

$last_update_text = '-';
$rows = [];
$hc_total = 0.0;
$allocated_total = 0.0;
$unallocated_total = 0.0;
$petugas_summary = [];
$history = [];
$history_from = (string)($_GET['history_from'] ?? '');
$history_to = (string)($_GET['history_to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $history_from)) $history_from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $history_to)) $history_to = '';

if ($klinik_row && !empty($klinik_row['kode_homecare'])) {
    $loc = $conn->real_escape_string((string)$klinik_row['kode_homecare']);
    $res_u = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror WHERE location_code = '$loc'");
    if ($res_u && $res_u->num_rows > 0) {
        $u = (string)($res_u->fetch_assoc()['last_update'] ?? '');
        if ($u !== '') $last_update_text = date('d M Y H:i', strtotime($u));
    }
    $res_hc = $conn->query("SELECT COALESCE(SUM(qty),0) AS total FROM stock_mirror WHERE location_code = '$loc'");
    if ($res_hc && $res_hc->num_rows > 0) $hc_total = (float)($res_hc->fetch_assoc()['total'] ?? 0);
}

if ($selected_klinik > 0) {
    $res_sum = $conn->query("SELECT COALESCE(SUM(qty),0) AS total FROM stok_tas_hc WHERE klinik_id = $selected_klinik");
    if ($res_sum && $res_sum->num_rows > 0) $allocated_total = (float)($res_sum->fetch_assoc()['total'] ?? 0);
    $unallocated_total = $hc_total - $allocated_total;
    if ($unallocated_total < 0) $unallocated_total = 0;
}
$overallocated_total = $allocated_total - $hc_total;
if ($overallocated_total < 0) $overallocated_total = 0;

if ($petugas_row && $selected_klinik > 0) {
    $uid = (int)$petugas_row['id'];
    $res = $conn->query("
        SELECT
            b.id AS barang_id,
            b.kode_barang,
            b.nama_barang,
            COALESCE(uc.to_uom, b.satuan) AS satuan,
            COALESCE(uc.from_uom, '') AS uom_odoo,
            COALESCE(uc.multiplier, 1) AS uom_multiplier,
            COALESCE(st.qty, 0) AS qty
        FROM stok_tas_hc st
        JOIN barang b ON b.id = st.barang_id
        LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
        WHERE st.user_id = $uid AND st.klinik_id = $selected_klinik AND st.qty <> 0
        ORDER BY b.nama_barang ASC
    ");
    while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
}

if ($selected_klinik > 0 && $role !== 'petugas_hc') {
    $res_ps = $conn->query("
        SELECT u.id, u.nama_lengkap, COALESCE(SUM(st.qty), 0) AS total_qty
        FROM users u
        LEFT JOIN stok_tas_hc st ON st.user_id = u.id AND st.klinik_id = " . (int)$selected_klinik . "
        WHERE u.role = 'petugas_hc' AND u.status = 'active' AND u.klinik_id = " . (int)$selected_klinik . "
        GROUP BY u.id, u.nama_lengkap
        ORDER BY u.nama_lengkap ASC
    ");
    while ($res_ps && ($rps = $res_ps->fetch_assoc())) $petugas_summary[] = $rps;
}

if ($selected_klinik > 0 && in_array($role, ['admin_klinik', 'super_admin'], true)) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS hc_petugas_transfer (
            id INT AUTO_INCREMENT PRIMARY KEY,
            klinik_id INT NOT NULL,
            user_hc_id INT NOT NULL,
            barang_id INT NOT NULL,
            qty INT NOT NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_klinik (klinik_id),
            KEY idx_user (user_hc_id),
            KEY idx_barang (barang_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS hc_tas_allocation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            klinik_id INT NOT NULL,
            user_hc_id INT NOT NULL,
            barang_id INT NOT NULL,
            qty INT NOT NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_klinik (klinik_id),
            KEY idx_user (user_hc_id),
            KEY idx_barang (barang_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $where_parts = [];
    $where_parts[] = "x.klinik_id = " . (int)$selected_klinik;
    if ($role === 'admin_klinik') $where_parts[] = "x.klinik_id = " . (int)$user_klinik_id;
    if ($petugas_user_id > 0) $where_parts[] = "x.user_hc_id = " . (int)$petugas_user_id;
    if ($history_from !== '') {
        $from_ts = $conn->real_escape_string($history_from . ' 00:00:00');
        $where_parts[] = "x.created_at >= '$from_ts'";
    }
    if ($history_to !== '') {
        $to_ts = $conn->real_escape_string($history_to . ' 23:59:59');
        $where_parts[] = "x.created_at <= '$to_ts'";
    }
    $where = implode(' AND ', $where_parts);

    $res_h = $conn->query("
        SELECT *
        FROM (
            SELECT 
                'transfer' AS tipe,
                t.id,
                t.klinik_id,
                t.user_hc_id,
                t.barang_id,
                t.qty,
                t.catatan,
                t.created_by,
                t.created_at
            FROM hc_petugas_transfer t
            UNION ALL
            SELECT
                'allocasi' AS tipe,
                a.id,
                a.klinik_id,
                a.user_hc_id,
                a.barang_id,
                a.qty,
                a.catatan,
                a.created_by,
                a.created_at
            FROM hc_tas_allocation a
        ) x
        WHERE $where
        ORDER BY x.created_at DESC
        LIMIT 200
    ");
    while ($res_h && ($rh = $res_h->fetch_assoc())) $history[] = $rh;
}

$petugas_name_map = [];
$barang_name_map = [];
if (!empty($history)) {
    $user_ids = [];
    $barang_ids = [];
    foreach ($history as $h) {
        $uid = (int)($h['user_hc_id'] ?? 0);
        $bid = (int)($h['barang_id'] ?? 0);
        if ($uid > 0) $user_ids[$uid] = true;
        if ($bid > 0) $barang_ids[$bid] = true;
    }
    if (!empty($user_ids)) {
        $ids = implode(',', array_map('intval', array_keys($user_ids)));
        $r = $conn->query("SELECT id, nama_lengkap FROM users WHERE id IN ($ids)");
        while ($r && ($row = $r->fetch_assoc())) $petugas_name_map[(int)$row['id']] = (string)$row['nama_lengkap'];
    }
    if (!empty($barang_ids)) {
        $ids = implode(',', array_map('intval', array_keys($barang_ids)));
        $r = $conn->query("SELECT id, kode_barang, nama_barang FROM barang WHERE id IN ($ids)");
        while ($r && ($row = $r->fetch_assoc())) {
            $kode = trim((string)($row['kode_barang'] ?? ''));
            $nama = (string)($row['nama_barang'] ?? '-');
            $barang_name_map[(int)$row['id']] = ($kode !== '' ? ($kode . ' - ') : '') . $nama;
        }
    }
}

$kliniks = [];
if (in_array($role, ['super_admin', 'admin_gudang'], true)) {
    $res = $conn->query("SELECT id, nama_klinik FROM klinik WHERE status='active' ORDER BY nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $kliniks[] = $row;
}
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;">
                <i class="fas fa-briefcase-medical me-2"></i>Stok Petugas HC
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Stok Petugas HC</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <div class="text-muted small">Terakhir update: <?= htmlspecialchars($last_update_text) ?></div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="stok_petugas_hc">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                <?php if (in_array($role, ['super_admin', 'admin_gudang'], true)): ?>
                    <div class="col-md-5">
                        <label class="form-label fw-bold small text-muted mb-1">Klinik</label>
                        <select class="form-select" name="klinik_id" onchange="this.form.submit()">
                            <option value="0">- Pilih Klinik -</option>
                            <?php foreach ($kliniks as $k): ?>
                                <option value="<?= (int)$k['id'] ?>" <?= (int)$selected_klinik === (int)$k['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($k['nama_klinik']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if ($role !== 'petugas_hc'): ?>
                    <div class="col-md-5">
                        <label class="form-label fw-bold small text-muted mb-1">Petugas</label>
                        <select class="form-select" name="petugas_user_id" onchange="this.form.submit()">
                            <option value="0">- Pilih Petugas -</option>
                            <?php foreach ($petugas_list as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$petugas_user_id === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nama_lengkap']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (!$klinik_row || $selected_klinik <= 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Pilih klinik terlebih dahulu.
        </div>
    <?php else: ?>
        <div class="row g-2 mb-3 align-items-stretch">
            <div class="col-md">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Mirror HC (Total Klinik)</div>
                        <div class="fw-bold" style="color:#204EAB; font-size:1.25rem;"><?= fmt_qty($hc_total) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Allocated (Semua Petugas)</div>
                        <div class="fw-bold <?= $overallocated_total > 0 ? 'text-danger' : 'text-warning' ?>" style="font-size:1.25rem;"><?= fmt_qty($allocated_total) ?></div>
                        <?php if ($overallocated_total > 0): ?>
                            <div class="small text-danger">Local adjustment: +<?= fmt_qty($overallocated_total) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Unallocated</div>
                        <div class="fw-bold text-success" style="font-size:1.25rem;"><?= fmt_qty($unallocated_total) ?></div>
                    </div>
                </div>
            </div>
            <?php if (in_array($role, ['super_admin', 'admin_klinik'], true) && $role !== 'petugas_hc'): ?>
            <div class="col-md-auto">
                <div class="d-flex gap-2">
                    <?php if ($role === 'super_admin'): ?>
                        <button type="button" class="btn btn-outline-primary shadow-sm h-100" data-bs-toggle="modal" data-bs-target="#modalAllocateMirrorHC">
                            <i class="fas fa-sitemap me-2"></i>Allocasi dari Mirror HC
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary shadow-sm h-100" data-bs-toggle="modal" data-bs-target="#modalTransferHC">
                        <i class="fas fa-exchange-alt me-2"></i>Transfer Onsite → Petugas
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($overallocated_total > 0): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2">
                <i class="fas fa-exclamation-triangle mt-1"></i>
                <div class="small">
                    <div class="fw-semibold">Allocated melebihi Mirror</div>
                    <div>Mirror HC adalah snapshot dari Odoo. Stok tas petugas disimpan lokal (alokasi/transfer), jadi tidak ter-overwrite saat refresh. Selisih ini biasanya berasal dari Transfer Onsite → Petugas yang belum tercermin di Odoo atau koreksi lokal.</div>
                </div>
            </div>
        <?php endif; ?>

        <ul class="nav nav-pills mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'stok' ? 'active' : '' ?>" id="tab-stok" data-bs-toggle="pill" data-bs-target="#tabpane-stok" type="button" role="tab">
                    Stok Tas
                </button>
            </li>
            <?php if (in_array($role, ['admin_klinik', 'super_admin'], true)): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'history' ? 'active' : '' ?>" id="tab-history" data-bs-toggle="pill" data-bs-target="#tabpane-history" type="button" role="tab">
                    History Transfer
                </button>
            </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade <?= $active_tab === 'stok' ? 'show active' : '' ?>" id="tabpane-stok" role="tabpanel" aria-labelledby="tab-stok">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <div class="fw-semibold">Stok Tas Petugas</div>
                                        <div class="text-muted small"><?= htmlspecialchars($klinik_row['nama_klinik'] ?? '-') ?> • Mirror: <?= htmlspecialchars($klinik_row['kode_homecare'] ?? '-') ?></div>
                                    </div>
                                </div>

                                <?php if (!$petugas_row && $role !== 'petugas_hc'): ?>
                                    <div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Pilih petugas untuk melihat item stok tas.</div>
                                <?php else: ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-semibold"><?= htmlspecialchars($petugas_row['nama_lengkap'] ?? '') ?></div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Kode Barang</th>
                                                    <th>Nama Barang</th>
                                                    <th>Satuan</th>
                                                    <th class="text-end">Qty</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($rows)): ?>
                                                    <tr><td colspan="4" class="text-center text-muted py-4">Belum ada stok tas untuk petugas ini.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($rows as $r): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($r['kode_barang'] ?? '-') ?></td>
                                                            <td><?= htmlspecialchars($r['nama_barang'] ?? '-') ?></td>
                                                            <td class="small">
                                                                <div><?= htmlspecialchars($r['satuan'] ?? '-') ?></div>
                                                                <?php if (!empty($r['uom_odoo']) && (float)($r['uom_multiplier'] ?? 1) != 1.0): ?>
                                                                    <div class="text-muted small">Odoo: <?= htmlspecialchars($r['uom_odoo']) ?> × <?= htmlspecialchars(fmt_qty($r['uom_multiplier'])) ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end fw-semibold"><?= fmt_qty($r['qty'] ?? 0) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="fw-semibold mb-2">Rekap Petugas</div>
                                <?php if ($role === 'petugas_hc'): ?>
                                    <div class="text-muted small">Role petugas HC bersifat read-only.</div>
                                <?php else: ?>
                                    <?php if (empty($petugas_summary)): ?>
                                        <div class="text-muted small">Belum ada petugas HC aktif di klinik ini.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Petugas</th>
                                                        <th class="text-end">Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($petugas_summary as $ps): ?>
                                                        <tr>
                                                            <td>
                                                                <a class="text-decoration-none" href="index.php?page=stok_petugas_hc&klinik_id=<?= (int)$selected_klinik ?>&petugas_user_id=<?= (int)$ps['id'] ?>">
                                                                    <?= htmlspecialchars($ps['nama_lengkap'] ?? '-') ?>
                                                                </a>
                                                            </td>
                                                            <td class="text-end fw-semibold"><?= fmt_qty($ps['total_qty'] ?? 0) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (in_array($role, ['admin_klinik', 'super_admin'], true)): ?>
            <div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="tabpane-history" role="tabpanel" aria-labelledby="tab-history">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="fw-semibold">History Transfer</div>
                                <div class="text-muted small">Ikuti filter Klinik/Petugas di atas. Limit 200 data.</div>
                            </div>
                        </div>

                        <form method="GET" class="row g-2 align-items-end mb-3">
                            <input type="hidden" name="page" value="stok_petugas_hc">
                            <input type="hidden" name="tab" value="history">
                            <?php if (in_array($role, ['super_admin', 'admin_gudang'], true)): ?>
                                <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                            <?php endif; ?>
                            <?php if ($role !== 'petugas_hc'): ?>
                                <input type="hidden" name="petugas_user_id" value="<?= (int)$petugas_user_id ?>">
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted mb-1">Dari Tanggal</label>
                                <input type="date" class="form-control" name="history_from" value="<?= htmlspecialchars($history_from) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted mb-1">Sampai Tanggal</label>
                                <input type="date" class="form-control" name="history_to" value="<?= htmlspecialchars($history_to) ?>">
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-primary">Filter</button>
                            </div>
                            <div class="col-md-auto">
                                <a class="btn btn-outline-secondary" href="index.php?page=stok_petugas_hc&tab=history<?= $role !== 'petugas_hc' ? ('&petugas_user_id=' . (int)$petugas_user_id) : '' ?><?= (in_array($role, ['super_admin', 'admin_gudang'], true) ? ('&klinik_id=' . (int)$selected_klinik) : '') ?>">Reset</a>
                            </div>
                        </form>

                        <?php if (empty($history)): ?>
                            <div class="text-muted small">Belum ada history untuk filter saat ini.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Waktu</th>
                                            <th>Tipe</th>
                                            <th>Petugas</th>
                                            <th>Barang</th>
                                            <th class="text-end">Qty</th>
                                            <th>Catatan</th>
                                            <th class="text-end">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td class="small text-muted"><?= date('d M Y H:i', strtotime((string)$h['created_at'])) ?></td>
                                                <td>
                                                    <?php if (($h['tipe'] ?? '') === 'transfer'): ?>
                                                        <span class="badge bg-primary">Transfer</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-outline-primary border text-primary">Allocasi</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars((string)($petugas_name_map[(int)($h['user_hc_id'] ?? 0)] ?? '-')) ?></td>
                                                <td><?= htmlspecialchars((string)($barang_name_map[(int)($h['barang_id'] ?? 0)] ?? '-')) ?></td>
                                                <td class="text-end fw-semibold"><?= fmt_qty($h['qty'] ?? 0) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars((string)($h['catatan'] ?? '')) ?></td>
                                                <td class="text-end">
                                                    <?php if (($h['tipe'] ?? '') === 'transfer' && (int)($h['qty'] ?? 0) > 0): ?>
                                                        <form method="POST" action="actions/reverse_hc_transfer.php" class="d-inline">
                                                            <input type="hidden" name="transfer_id" value="<?= (int)$h['id'] ?>">
                                                            <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                                                            <input type="hidden" name="petugas_user_id" value="<?= (int)$petugas_user_id ?>">
                                                            <input type="hidden" name="tab" value="history">
                                                            <input type="hidden" name="history_from" value="<?= htmlspecialchars($history_from) ?>">
                                                            <input type="hidden" name="history_to" value="<?= htmlspecialchars($history_to) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Batalkan transfer ini? Stok tas petugas akan dikurangi dan transaksi onsite/HC akan direkonstruksi sebagai reversal.');">
                                                                Batal
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($role === 'super_admin' && $role !== 'petugas_hc' && $selected_klinik > 0): ?>
<div class="modal fade" id="modalAllocateMirrorHC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold"><i class="fas fa-sitemap me-2"></i>Allocasi dari Mirror HC (Initial Mapping)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_hc_allocate_from_mirror.php" class="modal-body bg-light">
                <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                <div class="alert alert-info mb-3">
                    <div class="fw-semibold">Catatan</div>
                    <div class="small">Fitur ini hanya membagi stok HC mirror klinik ke “stok tas” petugas (lokal) dan tidak mengurangi stok Onsite/HC. Gunakan untuk mapping awal setelah refresh Odoo.</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Petugas HC</label>
                        <select name="user_hc_id" class="form-select" required>
                            <option value="">- Pilih Petugas -</option>
                            <?php foreach ($petugas_list as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nama_lengkap']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                <div class="fw-semibold">Daftar Item</div>
                                <button type="button" class="btn btn-success btn-sm" id="allocateAddRowBtn">
                                    <i class="fas fa-plus-circle me-1"></i>Tambah Item
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Barang</th>
                                                <th class="text-end" style="width:140px;">Qty</th>
                                                <th class="text-center" style="width:60px;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="allocateItemBody">
                                            <tr class="allocate-item-row">
                                                <td class="p-2">
                                                    <select name="barang_id[]" class="form-select allocate-barang-select" required>
                                                        <option value="">- Pilih Barang -</option>
                                                        <?php
                                                            $res_b2 = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM barang ORDER BY nama_barang ASC");
                                                            while ($res_b2 && ($bb2 = $res_b2->fetch_assoc())):
                                                        ?>
                                                            <option value="<?= (int)$bb2['id'] ?>"><?= htmlspecialchars(($bb2['kode_barang'] ?? '-') . ' - ' . ($bb2['nama_barang'] ?? '-') . ' (' . ($bb2['satuan'] ?? '-') . ')') ?></option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </td>
                                                <td class="p-2">
                                                    <input type="number" name="qty[]" class="form-control" min="1" step="1" required>
                                                </td>
                                                <td class="text-center border-start">
                                                    <button type="button" class="btn btn-sm btn-link text-danger allocate-remove-row">
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
                    <div class="col-12">
                        <label class="form-label fw-bold small">Catatan</label>
                        <input type="text" name="catatan" class="form-control" placeholder="opsional">
                    </div>
                </div>
                <div class="modal-footer border-0 px-0 pb-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-outline-primary">Simpan Allocasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (in_array($role, ['super_admin', 'admin_klinik'], true) && $role !== 'petugas_hc' && $selected_klinik > 0): ?>
<div class="modal fade" id="modalTransferHC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold"><i class="fas fa-exchange-alt me-2"></i>Transfer Onsite → Petugas HC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_hc_transfer.php" class="modal-body bg-light">
                <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Petugas HC</label>
                        <select name="user_hc_id" class="form-select" required>
                            <option value="">- Pilih Petugas -</option>
                            <?php foreach ($petugas_list as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nama_lengkap']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                <div class="fw-semibold">Daftar Item</div>
                                <button type="button" class="btn btn-success btn-sm" id="transferAddRowBtn">
                                    <i class="fas fa-plus-circle me-1"></i>Tambah Item
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Barang</th>
                                                <th class="text-end" style="width:140px;">Qty</th>
                                                <th class="text-center" style="width:60px;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transferItemBody">
                                            <tr class="transfer-item-row">
                                                <td class="p-2">
                                                    <select name="barang_id[]" class="form-select transfer-barang-select" required>
                                                        <option value="">- Pilih Barang -</option>
                                                        <?php
                                                            $res_b = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM barang ORDER BY nama_barang ASC");
                                                            while ($res_b && ($bb = $res_b->fetch_assoc())):
                                                        ?>
                                                            <option value="<?= (int)$bb['id'] ?>"><?= htmlspecialchars(($bb['kode_barang'] ?? '-') . ' - ' . ($bb['nama_barang'] ?? '-') . ' (' . ($bb['satuan'] ?? '-') . ')') ?></option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </td>
                                                <td class="p-2">
                                                    <input type="number" name="qty[]" class="form-control" min="1" step="1" required>
                                                </td>
                                                <td class="text-center border-start">
                                                    <button type="button" class="btn btn-sm btn-link text-danger transfer-remove-row">
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
                    <div class="col-12">
                        <label class="form-label fw-bold small">Catatan</label>
                        <input type="text" name="catatan" class="form-control" placeholder="opsional">
                    </div>
                </div>
                <div class="modal-footer border-0 px-0 pb-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    if (!window.jQuery) return;
    var $ = window.jQuery;

    function initSelect2($el, $modal, extra) {
        if (!$el || !$el.length) return;
        if (!$.fn || typeof $.fn.select2 !== 'function') return;
        if ($el.hasClass('select2-hidden-accessible')) {
            $el.select2('destroy');
        }
        var opts = $.extend({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $modal
        }, extra || {});
        $el.select2(opts);
    }

    function updateTransferRemoveButtons() {
        var $rows = $('#transferItemBody .transfer-item-row');
        $rows.find('.transfer-remove-row').prop('disabled', $rows.length === 1);
    }

    function initTransferModalSelects() {
        var $modal = $('#modalTransferHC');
        if (!$modal.length) return;
        initSelect2($modal.find('select[name="user_hc_id"]'), $modal, { placeholder: '- Pilih Petugas -', allowClear: true });
        $modal.find('.transfer-barang-select').each(function() {
            initSelect2($(this), $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        });
        updateTransferRemoveButtons();
    }

    function initAllocateModalSelects() {
        var $modal = $('#modalAllocateMirrorHC');
        if (!$modal.length) return;
        initSelect2($modal.find('select[name="user_hc_id"]'), $modal, { placeholder: '- Pilih Petugas -', allowClear: true });
        $modal.find('.allocate-barang-select').each(function() {
            initSelect2($(this), $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        });
        updateAllocateRemoveButtons();
    }

    function updateAllocateRemoveButtons() {
        var $rows = $('#allocateItemBody .allocate-item-row');
        $rows.find('.allocate-remove-row').prop('disabled', $rows.length === 1);
    }

    function addTransferRow() {
        var $modal = $('#modalTransferHC');
        var $tbody = $('#transferItemBody');
        if (!$modal.length || !$tbody.length) return;
        var $tpl = $tbody.find('tr.transfer-item-row:first').clone();
        var $sel = $tpl.find('select.transfer-barang-select');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $sel.val('');
        $tpl.find('input[name="qty[]"]').val('');
        $tbody.append($tpl);
        initSelect2($sel, $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        updateTransferRemoveButtons();
    }

    $(document).on('shown.bs.modal', '#modalTransferHC', function() {
        initTransferModalSelects();
    });
    $(document).on('shown.bs.modal', '#modalAllocateMirrorHC', function() {
        initAllocateModalSelects();
    });

    function addAllocateRow() {
        var $modal = $('#modalAllocateMirrorHC');
        var $tbody = $('#allocateItemBody');
        if (!$modal.length || !$tbody.length) return;
        var $tpl = $tbody.find('tr.allocate-item-row:first').clone();
        var $sel = $tpl.find('select.allocate-barang-select');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $sel.val('');
        $tpl.find('input[name="qty[]"]').val('');
        $tbody.append($tpl);
        initSelect2($sel, $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        updateAllocateRemoveButtons();
    }

    $(document).on('click', '#transferAddRowBtn', function() {
        addTransferRow();
    });

    $(document).on('click', '#allocateAddRowBtn', function() {
        addAllocateRow();
    });

    $(document).on('click', '.allocate-remove-row', function() {
        var $tbody = $('#allocateItemBody');
        var $rows = $tbody.find('tr.allocate-item-row');
        if ($rows.length <= 1) return;
        var $row = $(this).closest('tr');
        var $sel = $row.find('select.allocate-barang-select');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $row.remove();
        updateAllocateRemoveButtons();
    });

    $(document).on('click', '.transfer-remove-row', function() {
        var $tbody = $('#transferItemBody');
        var $rows = $tbody.find('tr.transfer-item-row');
        if ($rows.length <= 1) return;
        var $row = $(this).closest('tr');
        var $sel = $row.find('select.transfer-barang-select');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $row.remove();
        updateTransferRemoveButtons();
    });
})();
</script>
