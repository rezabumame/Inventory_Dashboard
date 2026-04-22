<?php
require_once __DIR__ . '/../../lib/stock.php';
check_role(['petugas_hc', 'super_admin', 'admin_gudang', 'admin_klinik']);

$role = (string)($_SESSION['role'] ?? '');
$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$active_tab = (string)($_GET['tab'] ?? 'stok');
if (!in_array($active_tab, ['stok', 'history', 'summary'], true)) $active_tab = 'stok';

// Fetch data
$selected_klinik = 0;
if (in_array($role, ['petugas_hc', 'admin_klinik', 'spv_klinik'], true)) {
    $selected_klinik = $user_klinik_id;
} else {
    $selected_klinik = (int)($_GET['klinik_id'] ?? 0);
}

$petugas_list = [];
if ($selected_klinik > 0 && $role !== 'petugas_hc') {
    $res = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $selected_klinik ORDER BY nama_lengkap ASC");
    while ($res && ($row = $res->fetch_assoc())) $petugas_list[] = $row;
}

$petugas_user_id = 0;
if ($role === 'petugas_hc') $petugas_user_id = $user_id;
else $petugas_user_id = (int)($_GET['petugas_user_id'] ?? 0);
if ($role !== 'petugas_hc' && count($petugas_list) === 1 && $petugas_user_id === 0) $petugas_user_id = (int)$petugas_list[0]['id'];

$petugas_row = null;
$klinik_row = null;
if ($selected_klinik > 0) $klinik_row = $conn->query("SELECT id, nama_klinik, kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $selected_klinik LIMIT 1")->fetch_assoc();
if (in_array($role, ['admin_klinik', 'spv_klinik'], true) && $selected_klinik !== $user_klinik_id) $selected_klinik = $user_klinik_id;

if ($role === 'petugas_hc') {
    $petugas_row = ['id' => $user_id, 'nama_lengkap' => (string)($_SESSION['nama_lengkap'] ?? 'Petugas HC')];
} elseif ($petugas_user_id > 0) {
    $petugas_row = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE id = $petugas_user_id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
    if ($petugas_row && $selected_klinik > 0 && (int)($conn->query("SELECT klinik_id FROM inventory_users WHERE id = $petugas_user_id LIMIT 1")->fetch_assoc()['klinik_id'] ?? 0) !== $selected_klinik) {
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
    $loc_hc = $conn->real_escape_string((string)$klinik_row['kode_homecare']);
    $loc_onsite = $conn->real_escape_string((string)($klinik_row['kode_klinik'] ?? ''));
    
    // Get general last update across all clinic locations
    $locs = ["'$loc_hc'"];
    if ($loc_onsite !== '') $locs[] = "'$loc_onsite'";
    $locs_str = implode(',', $locs);

    $res_u = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE location_code IN ($locs_str)");
    $u = '';
    if ($res_u && $res_u->num_rows > 0) {
        $u = (string)($res_u->fetch_assoc()['last_update'] ?? '');
    }
    
    // Fallback to global setting if mirror is empty
    if ($u === '') {
        $res_gs = $conn->query("SELECT v FROM inventory_app_settings WHERE k = 'odoo_sync_last_run' LIMIT 1");
        if ($res_gs && ($gs = $res_gs->fetch_assoc())) {
            $u = date('Y-m-d H:i:s', (int)$gs['v']);
        }
    }

    if ($u !== '') $last_update_text = date('d M Y H:i', strtotime($u));
    
    // Perbaikan perhitungan hc_total (Mirror HC): konversi ke satuan operasional
    $hc_total = 0.0;
    $res_hc_conv = $conn->query("
        SELECT 
            sm.qty AS mirror_qty,
            COALESCE(uc.multiplier, 1) AS ratio
        FROM inventory_stock_mirror sm
        LEFT JOIN inventory_barang b ON (b.odoo_product_id = sm.odoo_product_id OR b.kode_barang = sm.kode_barang)
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE sm.location_code = '$loc_hc'
    ");
    while ($res_hc_conv && ($hc_conv = $res_hc_conv->fetch_assoc())) {
        $m_qty = (float)$hc_conv['mirror_qty'];
        $ratio = (float)$hc_conv['ratio'];
        if ($ratio <= 0) $ratio = 1;
        $hc_total += ($m_qty / $ratio);
    }
}

if ($selected_klinik > 0) {
    $res_sum = $conn->query("SELECT COALESCE(SUM(qty),0) AS total FROM inventory_stok_tas_hc WHERE klinik_id = $selected_klinik");
    if ($res_sum && $res_sum->num_rows > 0) $allocated_total = (float)($res_sum->fetch_assoc()['total'] ?? 0);
    
    // Perbaikan perhitungan unallocated: jumlahkan sisa per item dengan mempertimbangkan transaksi tertunda (effective stock)
    $unallocated_total = 0;
    if ($klinik_row && !empty($klinik_row['kode_homecare'])) {
        $loc_hc = trim((string)$klinik_row['kode_homecare']);
        $loc_esc = $conn->real_escape_string($loc_hc);
        $last_update = stock_last_update($conn, $loc_hc);
        
        // 1. Get allocated map
        $allocated_map = [];
        $res_st = $conn->query("SELECT barang_id, SUM(qty) AS total_allocated FROM inventory_stok_tas_hc WHERE klinik_id = $selected_klinik GROUP BY barang_id");
        while($rst = $res_st->fetch_assoc()) $allocated_map[(int)$rst['barang_id']] = (float)$rst['total_allocated'];

        // 2. Get sellout map
        $sellout_map = [];
        if ($last_update !== '') {
            $res_s = $conn->query("
                SELECT pbd.barang_id, SUM(pbd.qty) AS qty
                FROM inventory_pemakaian_bhp_detail pbd
                JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
                WHERE pb.klinik_id = $selected_klinik AND pb.jenis_pemakaian = 'hc' AND pb.created_at > '$last_update'
                GROUP BY pbd.barang_id
            ");
            while($rs = $res_s->fetch_assoc()) $sellout_map[(int)$rs['barang_id']] = (float)$rs['qty'];
        }

        // 3. Get pending map
        $pending_map = [];
        if ($last_update !== '') {
            $res_p = $conn->query("
                SELECT barang_id, 
                       SUM(CASE WHEN tipe_transaksi='in' THEN qty ELSE 0 END) as qin,
                       SUM(CASE WHEN tipe_transaksi='out' THEN qty ELSE 0 END) as qout
                FROM inventory_transaksi_stok
                WHERE level = 'hc' AND level_id = $selected_klinik AND created_at > '$last_update'
                  AND referensi_tipe IN ('hc_petugas_transfer')
                GROUP BY barang_id
            ");
            while($rp = $res_p->fetch_assoc()) $pending_map[(int)$rp['barang_id']] = ['in' => (float)$rp['qin'], 'out' => (float)$rp['qout']];
        }

        // 4. Get reserve map
        $reserve_map = [];
        $today_esc = $conn->real_escape_string(date('Y-m-d'));
        $res_r = $conn->query("
            SELECT bd.barang_id, SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END) AS qty
            FROM inventory_booking_detail bd
            JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
            WHERE bp.klinik_id = $selected_klinik AND bp.status = 'booked' AND bp.status_booking LIKE '%HC%' AND bp.tanggal_pemeriksaan >= '$today_esc'
            GROUP BY bd.barang_id
        ");
        while($rr = $res_r->fetch_assoc()) $reserve_map[(int)$rr['barang_id']] = (float)$rr['qty'];

        // 5. Calculate Unallocated
        $res_un = $conn->query("
            SELECT 
                b.id AS barang_id,
                sm.qty AS mirror_qty,
                COALESCE(uc.multiplier, 1) AS ratio
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (b.odoo_product_id = sm.odoo_product_id OR b.kode_barang = sm.kode_barang)
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE sm.location_code = '$loc_esc'
        ");
        while ($res_un && ($un = $res_un->fetch_assoc())) {
            $bid = (int)$un['barang_id'];
            $ratio = (float)$un['ratio']; if($ratio <= 0) $ratio = 1;
            $m_oper = (float)$un['mirror_qty'] / $ratio;
            
            $p = $pending_map[$bid] ?? ['in'=>0,'out'=>0];
            $s = $sellout_map[$bid] ?? 0;
            $r = $reserve_map[$bid] ?? 0;
            
            $avail_hc = $m_oper + $p['in'] - $p['out'] - $s - $r;
            $a_qty = $allocated_map[$bid] ?? 0;
            $diff = $avail_hc - $a_qty;
            if ($diff > 0.00005) $unallocated_total += $diff;
        }
    }
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
        FROM inventory_stok_tas_hc st
        JOIN inventory_barang b ON b.id = st.barang_id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE st.user_id = $uid AND st.klinik_id = $selected_klinik AND st.qty <> 0
        ORDER BY b.nama_barang ASC
    ");
    while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
}

if ($selected_klinik > 0 && $role !== 'petugas_hc') {
    $res_ps = $conn->query("
        SELECT u.id, u.nama_lengkap, COALESCE(SUM(st.qty), 0) AS total_qty
        FROM inventory_users u
        LEFT JOIN inventory_stok_tas_hc st ON st.user_id = u.id AND st.klinik_id = " . (int)$selected_klinik . "
        WHERE u.role = 'petugas_hc' AND u.status = 'active' AND u.klinik_id = " . (int)$selected_klinik . "
        GROUP BY u.id, u.nama_lengkap
        ORDER BY u.nama_lengkap ASC
    ");
    while ($res_ps && ($rps = $res_ps->fetch_assoc())) $petugas_summary[] = $rps;
}

if ($selected_klinik > 0 && in_array($role, ['admin_klinik', 'super_admin', 'spv_klinik'], true)) {
    $where_parts = [];
    $where_parts[] = "x.klinik_id = " . (int)$selected_klinik;
    if (in_array($role, ['admin_klinik', 'spv_klinik'], true)) $where_parts[] = "x.klinik_id = " . (int)$user_klinik_id;
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
            FROM inventory_hc_petugas_transfer t
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
            FROM inventory_hc_tas_allocation a
        ) x
        WHERE $where
        ORDER BY x.created_at DESC
        LIMIT 500
    ");
    while ($res_h && ($rh = $res_h->fetch_assoc())) $history[] = $rh;
}

$petugas_name_map = [];
$barang_name_map = [];
$summary_rows = [];

if ($selected_klinik > 0 && $active_tab === 'summary') {
    // 1. Get all barang IDs from mirror and tas
    $all_barang_ids = [];
    
    // From Mirror
    if ($klinik_row && !empty($klinik_row['kode_homecare'])) {
        $loc_esc = $conn->real_escape_string($klinik_row['kode_homecare']);
        $res = $conn->query("
            SELECT b.id 
            FROM inventory_stock_mirror sm
            JOIN inventory_barang b ON (b.odoo_product_id = sm.odoo_product_id OR b.kode_barang = sm.kode_barang)
            WHERE sm.location_code = '$loc_esc'
        ");
        while ($res && ($r = $res->fetch_assoc())) $all_barang_ids[(int)$r['id']] = true;
    }
    
    // From Tas
    $res = $conn->query("SELECT DISTINCT barang_id FROM inventory_stok_tas_hc WHERE klinik_id = $selected_klinik");
    while ($res && ($r = $res->fetch_assoc())) $all_barang_ids[(int)$r['barang_id']] = true;

    if (!empty($all_barang_ids)) {
        $ids_str = implode(',', array_keys($all_barang_ids));
        
        // Fetch detailed data for each barang
        $res = $conn->query("
            SELECT 
                b.id, b.kode_barang, b.nama_barang,
                COALESCE(uc.to_uom, b.satuan) AS uom_oper,
                COALESCE(uc.multiplier, 1) AS ratio,
                (SELECT COALESCE(SUM(qty), 0) FROM inventory_stok_tas_hc WHERE klinik_id = $selected_klinik AND barang_id = b.id) AS total_allocated,
                (SELECT COALESCE(MAX(qty), 0) FROM inventory_stock_mirror WHERE (TRIM(kode_barang) = TRIM(b.kode_barang) OR TRIM(odoo_product_id) = TRIM(b.odoo_product_id)) AND TRIM(location_code) = '" . ($klinik_row ? $conn->real_escape_string($klinik_row['kode_homecare']) : '') . "') AS mirror_qty
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE b.id IN ($ids_str)
            ORDER BY b.nama_barang ASC
        ");
        
        while ($res && ($row = $res->fetch_assoc())) {
            $ratio = (float)$row['ratio']; if ($ratio <= 0) $ratio = 1;
            $mirror_oper = (float)$row['mirror_qty'] / $ratio;
            $allocated = (float)$row['total_allocated'];
            $diff = $allocated - $mirror_oper;
            
            $indicator = 'OK';
            $indicator_class = 'text-success';
            
            if (abs($diff) < 0.00005) {
                $indicator = 'OK';
                $indicator_class = 'text-success';
            } elseif ($diff > 0) {
                $indicator = 'Over';
                $indicator_class = 'text-danger';
            } else {
                $indicator = 'Under';
                $indicator_class = 'text-warning';
            }

            // Check if mapped (in this context, if it exists in mirror, it's mapped to Odoo)
            $is_mapped = ((float)$row['mirror_qty'] > 0 || (int)$conn->query("SELECT 1 FROM inventory_stock_mirror WHERE (TRIM(kode_barang) = TRIM('".$conn->real_escape_string($row['kode_barang'])."') OR TRIM(odoo_product_id) = TRIM('".$conn->real_escape_string((string)$row['id'])."')) AND TRIM(location_code) = '".($klinik_row ? $conn->real_escape_string($klinik_row['kode_homecare']) : '')."' LIMIT 1")->num_rows > 0);
            
            // Re-evaluating Unmapped: If it has tas stock but no mirror record at all
            if ((float)$row['mirror_qty'] <= 0) {
                 $indicator = 'Unmapped';
                 $indicator_class = 'text-muted';
            }

            $summary_rows[] = [
                'kode' => $row['kode_barang'],
                'nama' => $row['nama_barang'],
                'uom' => $row['uom_oper'],
                'stok_odoo' => $mirror_oper,
                'total_alokasi' => $allocated,
                'selisih' => $diff,
                'indicator' => $indicator,
                'indicator_class' => $indicator_class
            ];
        }
    }
}

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
        $r = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE id IN ($ids)");
        while ($r && ($row = $r->fetch_assoc())) $petugas_name_map[(int)$row['id']] = (string)$row['nama_lengkap'];
    }
    if (!empty($barang_ids)) {
        $ids = implode(',', array_map('intval', array_keys($barang_ids)));
        $r = $conn->query("SELECT id, kode_barang, nama_barang FROM inventory_barang WHERE id IN ($ids)");
        while ($r && ($row = $r->fetch_assoc())) {
            $kode = trim((string)($row['kode_barang'] ?? ''));
            $nama = (string)($row['nama_barang'] ?? '-');
            $barang_name_map[(int)$row['id']] = ($kode !== '' ? ($kode . ' - ') : '') . $nama;
        }
    }
}

$kliniks = [];
if (in_array($role, ['super_admin', 'admin_gudang'], true)) {
    $res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $kliniks[] = $row;
}

$bulk_confirm = isset($_GET['bulk_confirm']) && $_GET['bulk_confirm'] === '1';
$bulk_cancel = isset($_GET['bulk_cancel']) && $_GET['bulk_cancel'] === '1';
$pending_bulk = $_SESSION['hc_bulk_pending'] ?? null;
$pending_for_page = (is_array($pending_bulk) && (int)($pending_bulk['klinik_id'] ?? 0) === (int)$selected_klinik);

if ($bulk_cancel) {
    unset($_SESSION['hc_bulk_pending']);
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$selected_klinik . '&petugas_user_id=' . (int)$petugas_user_id);
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
                <div class="card h-100 shadow-sm" id="cardUnallocated" role="button" title="Lihat item belum teralokasi">
                    <div class="card-body">
                        <div class="text-muted small">Unallocated</div>
                        <div class="fw-bold text-success" style="font-size:1.25rem;"><?= fmt_qty($unallocated_total) ?></div>
                    </div>
                </div>
            </div>
            <?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true) && $role !== 'petugas_hc'): ?>
            <div class="col-md-auto">
                <div class="d-flex gap-2">
                    <?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true)): ?>
                        <button type="button" class="btn btn-outline-primary shadow-sm h-100" onclick="openMassAllocationSO()">
                            <i class="fas fa-redo me-2"></i>Alokasi Ulang (Mass/All)
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary shadow-sm h-100" data-bs-toggle="modal" data-bs-target="#modalUploadAlokasiMirrorHC">
                        <i class="fas fa-file-excel me-2"></i>Upload Alokasi
                    </button>
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

        <ul class="nav nav-pills mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 px-4 me-2 <?= $active_tab == 'stok' ? 'active-blue' : 'text-muted border' ?>" 
                   href="index.php?page=stok_petugas_hc&tab=stok&klinik_id=<?= $selected_klinik ?>&petugas_user_id=<?= $petugas_user_id ?>">
                    <i class="fas fa-briefcase-medical me-2"></i>Stok Tas
                </a>
            </li>
            <?php if (in_array($role, ['admin_klinik', 'super_admin', 'spv_klinik'], true)): ?>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 px-4 me-2 <?= $active_tab == 'summary' ? 'active-blue' : 'text-muted border' ?>" 
                   href="index.php?page=stok_petugas_hc&tab=summary&klinik_id=<?= $selected_klinik ?>&petugas_user_id=<?= $petugas_user_id ?>">
                    <i class="fas fa-list-alt me-2"></i>Summary
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 px-4 <?= $active_tab == 'history' ? 'active-blue' : 'text-muted border' ?>" 
                   href="index.php?page=stok_petugas_hc&tab=history&klinik_id=<?= $selected_klinik ?>&petugas_user_id=<?= $petugas_user_id ?>">
                    <i class="fas fa-history me-2"></i>History Transfer
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <style>
            .active-blue {
                background-color: #204EAB !important;
                color: white !important;
                box-shadow: 0 4px 6px rgba(32, 78, 171, 0.2);
            }
            /* Soft borders for tables */
            .table {
                border-collapse: separate;
                border-spacing: 0;
                border: 1px solid #f1f5f9 !important;
                border-radius: 8px;
                overflow: hidden;
            }
            .table th, .table td {
                border: 1px solid #f1f5f9 !important;
                padding: 10px 12px !important;
            }
            .table thead th {
                background-color: #f8fafc !important;
                color: #475569 !important;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.7rem;
                letter-spacing: 0.025em;
                border-bottom: 2px solid #e2e8f0 !important;
            }
        </style>

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
                                        <table class="table table-hover datatable align-middle">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Kode Barang</th>
                                                    <th>Nama Barang</th>
                                                    <th>Satuan</th>
                                                    <th class="text-end">Qty</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rows as $r): 
                                                    $bid = (int)($r['barang_id'] ?? 0);
                                                    $transfer_in_qty = 0;
                                                    if ($bid > 0 && $selected_klinik > 0 && $petugas_user_id > 0) {
                                                        $sql_tr = "SELECT COALESCE(SUM(qty), 0) AS total_in 
                                                            FROM inventory_hc_petugas_transfer 
                                                            WHERE klinik_id = $selected_klinik 
                                                            AND user_hc_id = $petugas_user_id 
                                                            AND barang_id = $bid";
                                                        if ($u !== '') {
                                                            $sql_tr .= " AND created_at > '" . $conn->real_escape_string($u) . "'";
                                                        }
                                                        $res_tr = $conn->query($sql_tr);
                                                        if ($res_tr && ($r_tr = $res_tr->fetch_assoc())) {
                                                            $transfer_in_qty = (float)$r_tr['total_in'];
                                                        }
                                                    }
                                                ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($r['kode_barang'] ?? '-') ?></td>
                                                        <td><?= htmlspecialchars($r['nama_barang'] ?? '-') ?></td>
                                                        <td class="small">
                                                            <div><?= htmlspecialchars($r['satuan'] ?? '-') ?></div>
                                                            <?php if (!empty($r['uom_odoo']) && (float)($r['uom_multiplier'] ?? 1) != 1.0): ?>
                                                                <div class="text-muted small">1 <?= htmlspecialchars($r['satuan'] ?? '-') ?> = <?= htmlspecialchars(fmt_qty($r['uom_multiplier'])) ?> <?= htmlspecialchars($r['uom_odoo']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="fw-semibold"><?= fmt_qty($r['qty'] ?? 0) ?></div>
                                                            <?php if ($transfer_in_qty > 0): ?>
                                                                <div class="text-muted small" style="font-size: 0.75rem;">
                                                                    <i class="fas fa-arrow-down text-success me-1"></i><?= fmt_qty($transfer_in_qty) ?> dari transfer onsite
                                                                </div>
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

            <?php if (in_array($role, ['admin_klinik', 'super_admin', 'spv_klinik'], true)): ?>
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
                                <table class="table table-hover datatable align-middle mb-0">
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
                                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
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

            <div class="tab-pane fade <?= $active_tab === 'summary' ? 'show active' : '' ?>" id="tabpane-summary" role="tabpanel" aria-labelledby="tab-summary">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="fw-semibold">Summary — Validasi Stok Odoo vs Alokasi Petugas</div>
                                <div class="text-muted small">Menampilkan stok tarikan Odoo, total alokasi per barang, dan selisih.</div>
                            </div>
                        </div>

                        <?php if ($selected_klinik <= 0): ?>
                            <div class="alert alert-warning mb-0">Pilih Klinik terlebih dahulu untuk melihat summary.</div>
                        <?php elseif (empty($summary_rows)): ?>
                            <div class="alert alert-info mb-0">Tidak ada data summary untuk filter saat ini.</div>
                        <?php else: ?>
                            <?php
                            $ok_count = 0; $over_count = 0; $under_count = 0; $unmapped_count = 0;
                            foreach ($summary_rows as $sr) {
                                if ($sr['indicator'] === 'OK') $ok_count++;
                                elseif ($sr['indicator'] === 'Over') $over_count++;
                                elseif ($sr['indicator'] === 'Under') $under_count++;
                                elseif ($sr['indicator'] === 'Unmapped') $unmapped_count++;
                            }
                            ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <div class="card bg-success bg-opacity-10 border-success">
                                        <div class="card-body py-2 text-center">
                                            <div class="fw-bold text-success fs-5"><?= $ok_count ?></div>
                                            <div class="small text-muted">Sesuai (OK)</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-danger bg-opacity-10 border-danger">
                                        <div class="card-body py-2 text-center">
                                            <div class="fw-bold text-danger fs-5"><?= $over_count ?></div>
                                            <div class="small text-muted">Selisih Lebih (Over)</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-warning bg-opacity-10 border-warning">
                                        <div class="card-body py-2 text-center">
                                            <div class="fw-bold text-warning fs-5"><?= $under_count ?></div>
                                            <div class="small text-muted">Selisih Kurang (Under)</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-secondary bg-opacity-10 border-secondary">
                                        <div class="card-body py-2 text-center">
                                            <div class="fw-bold text-secondary fs-5"><?= $unmapped_count ?></div>
                                            <div class="small text-muted">Belum Mapping</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Kode</th>
                                            <th>Nama Barang</th>
                                            <th class="text-center">UOM</th>
                                            <th class="text-end">Stok Odoo</th>
                                            <th class="text-end">Total Alokasi</th>
                                            <th class="text-end">Selisih</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($summary_rows as $row): ?>
                                            <tr>
                                                <td class="small"><?= htmlspecialchars($row['kode']) ?></td>
                                                <td><?= htmlspecialchars($row['nama']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['uom']) ?></td>
                                                <td class="text-end fw-semibold"><?= fmt_qty($row['stok_odoo']) ?></td>
                                                <td class="text-end fw-semibold"><?= fmt_qty($row['total_alokasi']) ?></td>
                                                <td class="text-end fw-semibold <?= $row['selisih'] > 0.00005 ? 'text-danger' : ($row['selisih'] < -0.00005 ? 'text-warning' : 'text-muted') ?>">
                                                    <?= fmt_qty($row['selisih']) ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-opacity-10 <?= str_replace('text-', 'bg-', $row['indicator_class']) ?> <?= $row['indicator_class'] ?>">
                                                        <?= $row['indicator'] ?>
                                                    </span>
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

        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalUnallocated" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-dolly-flatbed me-2"></i>Unallocated HC — Barang Belum Teralokasi / Belum Terpeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="unallocAlert"></div>
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-6">
                        <div class="text-muted small">Cari</div>
                        <input type="text" class="form-control form-control-sm" id="unallocSearch" placeholder="Cari kode / nama / odoo product">
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="text-muted small">Tips</div>
                        <div class="small text-muted">Klik Distribusi untuk pilih petugas dan qty</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="unallocTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:110px;">Odoo</th>
                                <th>Barang Odoo</th>
                                <th class="text-end">Mirror</th>
                                <th class="text-end">Allocated</th>
                                <th class="text-end">Sisa</th>
                                <th style="width:220px;">Barang Lokal</th>
                                <th width="140">Distribusi</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
    </div>

<div class="modal fade" id="modalDistribusi" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sitemap me-2"></i>Distribusi ke Petugas HC</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="distAlert"></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted small">Odoo Product</div>
                            <div class="fw-semibold" id="distOdooPid">-</div>
                            <div class="text-muted small mt-2">Kode</div>
                            <div class="fw-semibold" id="distKode">-</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="row g-2">
                                <div class="col-3">
                                    <div class="text-muted small">Sisa</div>
                                    <div class="fw-semibold text-success" id="distUnalloc">0</div>
                                </div>
                                <div class="col-3">
                                    <div class="text-muted small">Total</div>
                                    <div class="fw-semibold" id="distTotal">0</div>
                                </div>
                                <div class="col-3">
                                    <div class="text-muted small">Barang</div>
                                    <select class="form-select form-select-sm" id="distBarangSel"></select>
                                </div>
                                <div class="col-3">
                                    <div class="text-muted small">UOM</div>
                                    <select class="form-select form-select-sm" id="distUomMode" disabled>
                                        <option value="oper">Operasional</option>
                                        <option value="odoo">Odoo (Base)</option>
                                    </select>
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSaveOdooMap">Simpan Mapping Odoo</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBagiRata">Bagi rata</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetQty">Reset</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive border rounded-3">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Petugas</th>
                                <th width="160" class="text-end">Qty</th>
                            </tr>
                        </thead>
                        <tbody id="distPetugasBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary" id="btnSubmitDistribusi">Simpan Distribusi</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    window.HC_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
    var card = document.getElementById('cardUnallocated');
    if (card) {
        card.addEventListener('click', function() {
            openUnallocatedModal();
        });
    }

    // Global barang options for Mass Allocation
    window.BARANG_OPTIONS_SO = <?= json_encode((function() use ($conn) {
        $ops = [];
        $r = $conn->query("
            SELECT
                b.id, b.kode_barang, b.nama_barang,
                COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom_oper,
                COALESCE(NULLIF(uc.from_uom,''), b.uom) AS uom_odoo,
                COALESCE(uc.multiplier, 1) AS uom_ratio
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            ORDER BY b.nama_barang ASC
        ");
        while ($r && ($row = $r->fetch_assoc())) {
            $ops[] = [
                'id' => (int)$row['id'],
                'text' => (trim((string)$row['kode_barang']) !== '' ? $row['kode_barang'] . ' - ' : '') . (string)$row['nama_barang'],
                'uom_oper' => (string)($row['uom_oper'] ?? ''),
                'uom_odoo' => (string)($row['uom_odoo'] ?? ''),
                'uom_ratio' => (float)($row['uom_ratio'] ?? 1)
            ];
        }
        return $ops;
    })()) ?>;

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl)
    });

    document.getElementById('massAllocAddRowBtn').onclick = function() {
        addMassAllocRow();
    };

    document.getElementById('formMassAllocationSO').onsubmit = function(e) {
        var btn = document.getElementById('btnSubmitMassSO');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    };
});

function openMassAllocationSO() {
    var modalEl = document.getElementById('modalMassAllocationSO');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    
    var petugasSel = document.getElementById('massAllocPetugas');
    if (petugasSel.value) {
        fetchPetugasStockForSO();
    }
}

function fetchPetugasStockForSO() {
    var klinikId = <?= (int)$selected_klinik ?>;
    var petugasId = document.getElementById('massAllocPetugas').value;
    var tbody = document.getElementById('massAllocItemBody');
    var btnSubmit = document.getElementById('btnSubmitMassSO');

    if (!petugasId) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Pilih petugas terlebih dahulu.</td></tr>';
        btnSubmit.disabled = true;
        return;
    }

    // Keep manually added rows
    var manualRows = Array.from(tbody.querySelectorAll('.mass-alloc-row.manually-added'));
    
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>Memuat stok petugas...</td></tr>';
    btnSubmit.disabled = true;

    fetch('api/ajax_hc_petugas_stock.php?klinik_id=' + klinikId + '&user_hc_id=' + petugasId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">' + escapeHtml(data.message) + '</td></tr>';
                return;
            }
            
            tbody.innerHTML = '';
            
            // Re-append manually added rows first
            manualRows.forEach(row => tbody.appendChild(row));
            
            if (data.items.length === 0 && manualRows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Petugas belum memiliki stok tas. Klik "Tambah Item Baru" untuk memulai.</td></tr>';
            } else {
                data.items.forEach(it => {
                    // Check if item already exists in manual rows to avoid duplicates
                    var exists = manualRows.some(row => row.querySelector('.so-barang-select').value == it.barang_id);
                    if (!exists) {
                        addMassAllocRow(it);
                    }
                });
            }
            btnSubmit.disabled = false;
            filterMassAllocTable(); // Re-apply current filter
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">Gagal memuat data stok.</td></tr>';
        });
}

function addMassAllocRow(data = null) {
    var tbody = document.getElementById('massAllocItemBody');
    // Clear placeholder
    var placeholder = tbody.querySelector('td[colspan="6"]');
    if (placeholder) placeholder.closest('tr').remove();

    var isManual = (data === null);
    var row = document.createElement('tr');
    row.className = 'mass-alloc-row' + (isManual ? ' manually-added' : '');
    row.dataset.rowIndex = Date.now() + Math.random().toString(36).substr(2, 9);
    
    var barangId = data ? data.barang_id : '';
    var qtyLama = data ? data.qty_lama : 0;
    var uomOper = data ? data.uom_oper : '';
    var uomOdoo = data ? data.uom_odoo : '';
    var uomRatio = data ? data.uom_ratio : 1;

    var optionsHtml = '<option value="">- Pilih Barang -</option>';
    window.BARANG_OPTIONS_SO.forEach(opt => {
        var selected = (opt.id == barangId) ? 'selected' : '';
        optionsHtml += `<option value="${opt.id}" ${selected} 
            data-uom-oper="${escapeAttr(opt.uom_oper)}" 
            data-uom-odoo="${escapeAttr(opt.uom_odoo)}" 
            data-uom-ratio="${opt.uom_ratio}">${escapeHtml(opt.text)}</option>`;
    });

    row.innerHTML = `
        <td class="p-1 ps-3" style="min-width: 250px;">
            <select name="barang_id[]" class="form-select form-select-sm so-barang-select" required onchange="handleSoBarangChange(this)">
                ${optionsHtml}
            </select>
        </td>
        <td class="p-1 text-end fw-bold text-muted small" style="width: 100px;">
            <span class="so-qty-lama-text">${fmtQty(qtyLama)}</span>
            <input type="hidden" name="qty_lama[]" value="${qtyLama}" class="so-qty-lama-input">
        </td>
        <td class="p-1" style="width: 120px;">
            <input type="number" name="qty_so[]" class="form-control form-control-sm text-end so-qty-input fw-bold" 
                step="0.0001" min="0" value="" oninput="calculateSoDiff(this)">
        </td>
        <td class="p-1 text-end fw-bold so-diff-text small" style="width: 100px;">0</td>
        <td class="p-1" style="width: 120px;">
            <select name="uom_mode[]" class="form-select form-select-sm so-uom-select small" onchange="handleSoUomChange(this)">
                <option value="oper">${escapeHtml(uomOper || 'Satuan')}</option>
                ${(uomOdoo && uomOdoo !== uomOper) ? `<option value="odoo">${escapeHtml(uomOdoo)}</option>` : ''}
            </select>
        </td>
        <td class="p-1 text-center" style="width: 50px;">
            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove(); updateSubmitBtnState();">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    
    var select = row.querySelector('.so-barang-select');
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $(select).select2({
            dropdownParent: $('#modalMassAllocationSO'),
            width: '100%',
            theme: 'bootstrap-5',
            selectionCssClass: 'select2--small',
            dropdownCssClass: 'select2--small'
        }).on('change', function() {
            handleSoBarangChange(this);
            filterMassAllocTable();
        });
    }

    if (isManual) {
        // Clear filter when adding manual row so user can see it
        var searchInput = document.getElementById('massAllocSearch');
        if (searchInput.value !== '') {
            searchInput.value = '';
            filterMassAllocTable();
        }
        // Focus the new select
        setTimeout(() => {
            if (typeof $ !== 'undefined' && $.fn.select2) $(select).select2('open');
            else select.focus();
        }, 100);
    }

    calculateSoDiff(row.querySelector('.so-qty-input'));
    updateSubmitBtnState();
}

function handleSoBarangChange(select) {
    var row = select.closest('tr');
    var opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) return;

    var uomOper = opt.getAttribute('data-uom-oper');
    var uomOdoo = opt.getAttribute('data-uom-odoo');
    
    var uomSelect = row.querySelector('.so-uom-select');
    uomSelect.innerHTML = `<option value="oper">${escapeHtml(uomOper)}</option>`;
    if (uomOdoo && uomOdoo !== uomOper) {
        uomSelect.innerHTML += `<option value="odoo">${escapeHtml(uomOdoo)}</option>`;
    }
    
    calculateSoDiff(row.querySelector('.so-qty-input'));
}

function handleSoUomChange(select) {
    var row = select.closest('tr');
    calculateSoDiff(row.querySelector('.so-qty-input'));
}

function calculateSoDiff(input) {
    var row = input.closest('tr');
    var qtyLama = parseFloat(row.querySelector('.so-qty-lama-input').value) || 0;
    var qtySo = parseFloat(input.value) || 0;
    
    var uomSelect = row.querySelector('.so-uom-select');
    var uomMode = uomSelect.value;
    
    var barangSelect = row.querySelector('.so-barang-select');
    var opt = barangSelect.options[barangSelect.selectedIndex];
    var ratio = opt ? (parseFloat(opt.getAttribute('data-uom-ratio')) || 1) : 1;

    // Convert SO to operational if needed
    var qtySoOper = (uomMode === 'odoo') ? (qtySo / ratio) : qtySo;
    
    var diff = qtySoOper - qtyLama;
    var diffText = row.querySelector('.so-diff-text');
    diffText.textContent = (diff > 0 ? '+' : '') + fmtQty(diff);
    
    if (diff > 0.00001) {
        diffText.className = 'p-1 text-end fw-bold so-diff-text small text-success';
    } else if (diff < -0.00001) {
        diffText.className = 'p-1 text-end fw-bold so-diff-text small text-danger';
    } else {
        diffText.className = 'p-1 text-end fw-bold so-diff-text small text-muted';
    }
}

function filterMassAllocTable() {
    var input = document.getElementById('massAllocSearch');
    var filter = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#massAllocItemBody .mass-alloc-row');
    
    rows.forEach(row => {
        var barangSelect = row.querySelector('.so-barang-select');
        var text = '';
        if (barangSelect.selectedIndex >= 0) {
            text = barangSelect.options[barangSelect.selectedIndex].text.toLowerCase();
        }
        
        if (text.indexOf(filter) > -1) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}

function updateSubmitBtnState() {
    var tbody = document.getElementById('massAllocItemBody');
    var btnSubmit = document.getElementById('btnSubmitMassSO');
    var rows = tbody.querySelectorAll('.mass-alloc-row');
    btnSubmit.disabled = (rows.length === 0);
}

function openUnallocatedModal() {
    var klinikId = <?= (int)$selected_klinik ?>;
    if (!klinikId) return;
    
    // Gunakan getOrCreateInstance agar tidak membuat instance baru setiap kali (mencegah layar menghitam)
    var modalEl = document.getElementById('modalUnallocated');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    
    document.querySelector('#unallocTable tbody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Memuat...</td></tr>';
    document.getElementById('unallocAlert').innerHTML = '';
    var q = document.getElementById('unallocSearch');
    if (q) q.value = '';
    
    modal.show();
    fetch('api/ajax_hc_unallocated.php?klinik_id=' + encodeURIComponent(klinikId), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                document.querySelector('#unallocTable tbody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">' + (data && data.message ? data.message : 'Gagal memuat') + '</td></tr>';
                return;
            }
            var items = data.items || [];
            if (!items.length) {
                document.querySelector('#unallocTable tbody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Semua barang sudah teralokasi / tidak ada data.</td></tr>';
                return;
            }
            renderUnallocatedRows(items);
            var q = document.getElementById('unallocSearch');
            if (q) {
                q.oninput = function() {
                    applyUnallocFilter();
                };
            }
        })
        .catch(() => {
            document.querySelector('#unallocTable tbody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Gagal memuat</td></tr>';
        });
}

function renderUnallocatedRows(items) {
    var tbody = document.querySelector('#unallocTable tbody');
    window.__unalloc_items = items || [];
    window.__unalloc_items_all = items || [];
    tbody.innerHTML = items.map(function(it, idx) {
        var kode = it.kode_barang || '-';
        var suggestedName = it.suggested_barang_id > 0 ? ((it.suggested_kode_barang ? it.suggested_kode_barang + ' - ' : '') + (it.suggested_nama_barang || '')) : '';
        var odooNameCell = '<div class="fw-semibold">' + escapeHtml(kode) + '</div>' + (suggestedName ? '<div class="small text-muted">' + escapeHtml(suggestedName) + '</div>' : '');

        var mappedName = it.mapped_barang_id > 0 ? ((it.mapped_kode_barang ? it.mapped_kode_barang + ' - ' : '') + (it.mapped_nama_barang || '')) : '';
        var localCell = it.needs_mapping === 1
            ? '<span class="badge bg-warning text-dark mb-1">Belum dipetakan</span><div class="small text-muted">Pilih barang saat Distribusi</div>'
            : '<span class="badge bg-success mb-1">Mapped</span><div class="small text-muted">' + escapeHtml(mappedName || '-') + '</div>';

        var btnCell = `<button class="btn btn-sm btn-primary" onclick="openDistribusi(${idx})">Distribusi</button>`;
        var uom = it.uom_oper ? (' ' + escapeHtml(it.uom_oper)) : '';
        return `
            <tr>
                <td class="small text-muted">${escapeHtml(it.odoo_product_id || '-')}</td>
                <td>${odooNameCell}</td>
                <td class="text-end fw-semibold">${fmtQty(it.mirror_qty)}${uom}</td>
                <td class="text-end">${fmtQty(it.allocated_qty)}${uom}</td>
                <td class="text-end text-success">${fmtQty(it.unallocated_qty)}${uom}</td>
                <td>${localCell}</td>
                <td class="text-center">${btnCell}</td>
            </tr>
        `;
    }).join('');
}

function applyUnallocFilter() {
    var q = document.getElementById('unallocSearch');
    var term = q ? (q.value || '').toLowerCase().trim() : '';
    var items = window.__unalloc_items_all || [];
    if (!term) {
        renderUnallocatedRows(items);
        return;
    }
    var filtered = items.filter(function(it) {
        var parts = [
            it.odoo_product_id || '',
            it.kode_barang || '',
            it.mapped_kode_barang || '',
            it.mapped_nama_barang || '',
            it.suggested_kode_barang || '',
            it.suggested_nama_barang || ''
        ].join(' ').toLowerCase();
        return parts.indexOf(term) !== -1;
    });
    renderUnallocatedRows(filtered);
}

function openDistribusi(idx) {
    var items = window.__unalloc_items || [];
    var it = items[idx];
    if (!it) return;
    window.__dist_it = it;

    document.getElementById('distAlert').innerHTML = '';
    document.getElementById('distOdooPid').textContent = it.odoo_product_id || '-';
    document.getElementById('distKode').textContent = it.kode_barang || '-';
    document.getElementById('distUnalloc').textContent = fmtQty(it.unallocated_qty);
    document.getElementById('distTotal').textContent = '0';

    var barangSel = document.getElementById('distBarangSel');
    var options = <?= json_encode((function() use ($conn) {
        $ops = [];
        $r = $conn->query("
            SELECT
                b.id, b.kode_barang, b.nama_barang,
                COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom_oper,
                COALESCE(NULLIF(uc.from_uom,''), b.uom) AS uom_odoo,
                COALESCE(uc.multiplier, 1) AS uom_ratio
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            ORDER BY b.nama_barang ASC
        ");
        while ($r && ($row = $r->fetch_assoc())) {
            $ops[] = [
                'id' => (int)$row['id'],
                'text' => (trim((string)$row['kode_barang']) !== '' ? $row['kode_barang'] . ' - ' : '') . (string)$row['nama_barang'],
                'uom_oper' => (string)($row['uom_oper'] ?? ''),
                'uom_odoo' => (string)($row['uom_odoo'] ?? ''),
                'uom_ratio' => (float)($row['uom_ratio'] ?? 1)
            ];
        }
        return $ops;
    })()) ?>;
    barangSel.innerHTML = '<option value="">Pilih barang…</option>' + options.map(o => `<option value="${o.id}" data-uom-oper="${escapeAttr(o.uom_oper)}" data-uom-odoo="${escapeAttr(o.uom_odoo)}" data-uom-ratio="${escapeAttr(o.uom_ratio)}">${escapeHtml(o.text)}</option>`).join('');

    var pre = it.mapped_barang_id > 0 ? it.mapped_barang_id : it.suggested_barang_id;
    if (pre) barangSel.value = String(pre);
    var uomModeSel = document.getElementById('distUomMode');
    if (uomModeSel) {
        uomModeSel.value = 'oper';
    }

    var canSaveMap = <?= json_encode(in_array($role, ['super_admin', 'admin_gudang'], true)) ?>;
    document.getElementById('btnSaveOdooMap').style.display = canSaveMap ? '' : 'none';

    var petugas = <?= json_encode($petugas_list) ?>;
    var body = document.getElementById('distPetugasBody');
    body.innerHTML = petugas.length ? petugas.map(p => `
        <tr>
            <td>${escapeHtml(p.nama_lengkap)}</td>
            <td class="text-end"><input type="number" min="0" step="0.0001" class="form-control form-control-sm text-end distQty" data-user-id="${p.id}" value="0"></td>
        </tr>
    `).join('') : '<tr><td colspan="2" class="text-center text-muted py-3">Tidak ada petugas HC untuk klinik ini.</td></tr>';

    document.querySelectorAll('.distQty').forEach(function(inp) {
        inp.oninput = function() {
            updateDistTotal(it);
        };
    });

    document.getElementById('btnResetQty').onclick = function() {
        document.querySelectorAll('.distQty').forEach(i => i.value = 0);
        updateDistTotal(it);
    };
    document.getElementById('btnBagiRata').onclick = function() {
        var max = getDistMax(it);
        var inputs = Array.from(document.querySelectorAll('.distQty'));
        if (!inputs.length || max <= 0) return;
        var base = max / inputs.length;
        base = Math.floor(base * 10000) / 10000;
        inputs.forEach((inp) => { inp.value = String(base); });
        updateDistTotal(it);
    };

    document.getElementById('btnSaveOdooMap').onclick = function() {
        var bid = barangSel.value;
        if (!bid) {
            document.getElementById('distAlert').innerHTML = '<div class="alert alert-warning py-2">Pilih barang terlebih dahulu.</div>';
            return;
        }
        var fd = new FormData();
        fd.append('_csrf', window.HC_CSRF || '');
        fd.append('odoo_product_id', it.odoo_product_id);
        fd.append('barang_id', bid);
        fd.append('force', '1');
        fetch('api/ajax_hc_map_odoo_product.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    // Beri notifikasi sebentar lalu reload untuk update stok di background
                    document.getElementById('distAlert').innerHTML = '<div class="alert alert-success py-2">Mapping Odoo tersimpan.</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    document.getElementById('distAlert').innerHTML = '<div class="alert alert-danger py-2">' + escapeHtml(d && d.message ? d.message : 'Gagal menyimpan mapping') + '</div>';
                }
            })
            .catch(() => {
                document.getElementById('distAlert').innerHTML = '<div class="alert alert-danger py-2">Gagal menyimpan mapping.</div>';
            });
    };

    document.getElementById('btnSubmitDistribusi').onclick = function() {
        var klinikId = <?= (int)$selected_klinik ?>;
        var bid = parseInt(barangSel.value || '0', 10);
        if (!bid) {
            document.getElementById('distAlert').innerHTML = '<div class="alert alert-warning py-2">Pilih barang terlebih dahulu.</div>';
            return;
        }
        var allocations = {};
        var total = 0;
        document.querySelectorAll('.distQty').forEach(inp => {
            var uid = inp.getAttribute('data-user-id');
            var q = parseFloat(String(inp.value || '0').replace(',', '.')) || 0;
            if (q > 0.0000001) {
                allocations[uid] = q;
                total += q;
            }
        });
        if (total <= 0) {
            document.getElementById('distAlert').innerHTML = '<div class="alert alert-warning py-2">Isi qty distribusi minimal 1.</div>';
            return;
        }
        var max = getDistMax(it);
        if (total > max + 0.00005) {
            document.getElementById('distAlert').innerHTML = '<div class="alert alert-danger py-2">Qty melebihi Unallocated. Unallocated: ' + fmtQty(max) + ', Request: ' + fmtQty(total) + '</div>';
            return;
        }
        var fd = new FormData();
        fd.append('_csrf', window.HC_CSRF || '');
        fd.append('klinik_id', String(klinikId));
        fd.append('barang_id', String(bid));
        fd.append('odoo_product_id', it.odoo_product_id);
        fd.append('allocations', JSON.stringify(allocations));
        fd.append('uom_mode', (uomModeSel ? uomModeSel.value : 'oper'));
        fd.append('catatan', 'Distribusi dari Unallocated HC');
        fetch('api/ajax_hc_distribute_unallocated.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    // Beri notifikasi sebentar lalu reload untuk update stok di background
                    document.getElementById('distAlert').innerHTML = '<div class="alert alert-success py-2">Distribusi berhasil disimpan.</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    document.getElementById('distAlert').innerHTML = '<div class="alert alert-danger py-2">' + escapeHtml(d && d.message ? d.message : 'Gagal menyimpan distribusi') + '</div>';
                }
            })
            .catch(() => {
                document.getElementById('distAlert').innerHTML = '<div class="alert alert-danger py-2">Gagal menyimpan distribusi.</div>';
            });
    };

    var modalEl = document.getElementById('modalDistribusi');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    refreshDistUom(it);
}

function updateDistTotal(it) {
    var total = 0;
    document.querySelectorAll('.distQty').forEach(function(inp) {
        var q = parseFloat(String(inp.value || '0').replace(',', '.')) || 0;
        if (q > 0.0000001) total += q;
    });
    document.getElementById('distTotal').textContent = fmtQty(total);
    var max = getDistMax(it);
    var remain = (max - total);
    if (remain < 0) remain = 0;
    document.getElementById('distUnalloc').textContent = fmtQty(remain);
}

function refreshDistUom(it) {
    var barangSel = document.getElementById('distBarangSel');
    var uomModeSel = document.getElementById('distUomMode');
    if (!barangSel || !uomModeSel) return;
    var opt = barangSel.options[barangSel.selectedIndex];
    var oper = opt ? String(opt.getAttribute('data-uom-oper') || '') : '';
    var odoo = opt ? String(opt.getAttribute('data-uom-odoo') || '') : '';
    var ratio = opt ? (parseFloat(String(opt.getAttribute('data-uom-ratio') || '1').replace(',', '.')) || 1) : 1;
    if (ratio <= 0) ratio = 1;
    if (odoo && Math.abs(ratio - 1) > 0.0000001 && oper && oper.toLowerCase() !== odoo.toLowerCase()) {
        uomModeSel.disabled = false;
    } else {
        uomModeSel.value = 'oper';
        uomModeSel.disabled = true;
    }
    document.querySelectorAll('.distQty').forEach(i => i.value = 0);
    updateDistTotal(it);
}

function getDistMax(it) {
    var uomModeSel = document.getElementById('distUomMode');
    var mode = uomModeSel ? uomModeSel.value : 'oper';
    var maxOper = parseFloat(it.unallocated_qty || 0) || 0;
    if (mode === 'odoo') {
        var barangSel = document.getElementById('distBarangSel');
        var opt = barangSel ? barangSel.options[barangSel.selectedIndex] : null;
        var ratio = opt ? (parseFloat(String(opt.getAttribute('data-uom-ratio') || '1').replace(',', '.')) || 1) : 1;
        if (ratio <= 0) ratio = 1;
        return maxOper * ratio;
    }
    return maxOper;
}

document.addEventListener('change', function(e) {
    if (e && e.target && e.target.id === 'distBarangSel') {
        var it = window.__dist_it || null;
        if (it) refreshDistUom(it);
    }
    if (e && e.target && e.target.id === 'distUomMode') {
        var it2 = window.__dist_it || null;
        if (it2) {
            document.querySelectorAll('.distQty').forEach(i => i.value = 0);
            updateDistTotal(it2);
        }
    }
});

function escapeHtml(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
function escapeAttr(s){ return String(s||'').replace(/"/g,'&quot;'); }
function fmtQty(v) {
    var n = parseFloat(v || 0);
    if (Math.abs(n - Math.round(n)) < 0.00005) return Math.round(n).toString();
    var s = n.toFixed(4).replace(/\.?0+$/, "");
    return s === "" ? "0" : s;
}
</script>

<?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true) && $role !== 'petugas_hc' && $selected_klinik > 0): ?>
<div class="modal fade" id="modalMassAllocationSO" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white py-2" style="background-color:#204EAB;">
                <h6 class="modal-title fw-bold text-white mb-0"><i class="fas fa-redo me-2"></i>Alokasi Ulang (Mass/All) - Stock Opname</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_hc_mass_allocation_so.php" class="modal-body bg-light p-2" id="formMassAllocationSO">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                
                <div id="massAllocationErrorContainer" class="alert alert-danger d-none mb-2 py-2 small"></div>
                
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-3">
                        <div style="min-width: 200px;">
                            <label class="form-label fw-bold small mb-1">Petugas HC</label>
                            <select name="user_hc_id" id="massAllocPetugas" class="form-select form-select-sm" required onchange="fetchPetugasStockForSO()">
                                <option value="">- Pilih Petugas -</option>
                                <?php foreach ($petugas_list as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (int)$petugas_user_id === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_lengkap']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mt-4">
                            <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-3" data-bs-toggle="popover" data-bs-trigger="focus" title="Mekanisme Alokasi Ulang (SO)" data-bs-html="true" data-bs-content="<ul class='small mb-0 ps-3'><li>Sistem menampilkan data stok sebelumnya (Stok Tas) sebagai acuan.</li><li>Input hasil **Stock Opname (SO)** pada masing-masing item.</li><li>Selisih akan dihitung otomatis (**SO - Stok Lama**).</li><li>Klik 'Tambah Item' jika ada barang baru yang belum tercatat di tas petugas.</li></ul>">
                                <i class="fas fa-info-circle me-1"></i>Petunjuk SO
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-1 d-flex justify-content-between align-items-center">
                        <div class="fw-semibold small">Daftar Item & Hasil SO</div>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="input-group input-group-sm" style="width: 250px;">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" id="massAllocSearch" class="form-control border-start-0" placeholder="Cari item..." onkeyup="filterMassAllocTable()">
                            </div>
                            <button type="button" class="btn btn-success btn-sm" id="massAllocAddRowBtn">
                                <i class="fas fa-plus-circle me-1"></i>Tambah Item
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 350px;">
                            <table class="table table-sm table-hover align-middle mb-0" id="massAllocTable">
                                <thead class="bg-light sticky-top">
                                    <tr class="small">
                                        <th class="ps-3">Barang</th>
                                        <th class="text-end" style="width:100px;">Stok Lama</th>
                                        <th class="text-end" style="width:120px;">Hasil SO</th>
                                        <th class="text-end" style="width:100px;">Selisih</th>
                                        <th class="text-center" style="width:120px;">UOM</th>
                                        <th class="text-center" style="width:50px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="massAllocItemBody">
                                    <tr><td colspan="6" class="text-center text-muted py-3 small">Pilih petugas terlebih dahulu.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-2">
                    <label class="form-label fw-bold small mb-1">Catatan</label>
                    <input type="text" name="catatan" class="form-control form-control-sm" placeholder="Contoh: Stock Opname Rutin">
                </div>

                <div class="modal-footer border-0 p-0 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitMassSO" disabled>Simpan Alokasi Ulang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true) && $role !== 'petugas_hc' && $selected_klinik > 0): ?>
<div class="modal fade" id="modalUploadAlokasiMirrorHC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-file-excel me-2"></i>Upload Excel — Alokasi dari Mirror HC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-info mb-3">
                    <div class="fw-semibold">Petunjuk</div>
                    <div class="small">Download template sesuai klinik/petugas, isi kolom Qty Baru pada setiap baris (boleh 0), lalu upload kembali.</div>
                </div>

                <?php if ($petugas_user_id <= 0 && !empty($petugas_list)): ?>
                    <div class="mb-3 border rounded p-3 bg-white">
                        <label class="form-label fw-bold small text-muted mb-2">Pilih Petugas untuk Download Template:</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="selectAllPetugas" checked>
                            <label class="form-check-label fw-bold small" for="selectAllPetugas">Pilih Semua</label>
                        </div>
                        <div class="row g-2" id="petugasCheckboxList">
                            <?php foreach ($petugas_list as $p): ?>
                                <div class="col-md-4 col-6">
                                    <div class="form-check">
                                        <input class="form-check-input petugas-check" type="checkbox" value="<?= (int)$p['id'] ?>" id="checkP_<?= (int)$p['id'] ?>" checked>
                                        <label class="form-check-label small" for="checkP_<?= (int)$p['id'] ?>">
                                            <?= htmlspecialchars($p['nama_lengkap']) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <a class="btn btn-outline-primary" id="btnDownloadTemplateHC"
                       href="api/download_template_alokasi_mirror_hc.php?klinik_id=<?= (int)$selected_klinik ?>&petugas_user_id=<?= (int)$petugas_user_id ?>"
                       target="_blank">
                        <i class="fas fa-download me-2"></i>Download Template Alokasi
                    </a>
                </div>
                <form method="POST" action="actions/process_hc_bulk_upload.php" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                    <input type="hidden" name="petugas_user_id" value="<?= (int)$petugas_user_id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">File Excel <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx" required>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-2"></i>Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($pending_for_page && $bulk_confirm): ?>
<div class="modal fade" id="modalBulkConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi — Selisih Mirror vs Alokasi</h5>
                <a href="index.php?page=stok_petugas_hc&klinik_id=<?= (int)$selected_klinik ?>&petugas_user_id=<?= (int)$petugas_user_id ?>" class="btn-close btn-close-white" aria-label="Close"></a>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-warning">
                    <div class="fw-semibold">Total alokasi melebihi stok Mirror HC.</div>
                    <div class="small">Terdapat selisih antara stok Mirror dan alokasi petugas. Anda bisa lanjutkan simpan atau batalkan.</div>
                </div>
                <div class="table-responsive bg-white border rounded">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Barang</th>
                                <th class="text-end">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $diffs = (array)($pending_bulk['diffs'] ?? []);
                            arsort($diffs);
                            $ids = array_keys($diffs);
                            $ids = array_slice($ids, 0, 200);
                            $name_map = [];
                            if (!empty($ids)) {
                                $id_sql = implode(',', array_map('intval', $ids));
                                $r = $conn->query("SELECT id, kode_barang, nama_barang FROM inventory_barang WHERE id IN ($id_sql)");
                                while ($r && ($row = $r->fetch_assoc())) {
                                    $kode = trim((string)($row['kode_barang'] ?? ''));
                                    $nama = (string)($row['nama_barang'] ?? '-');
                                    $name_map[(int)$row['id']] = ($kode !== '' ? ($kode . ' - ') : '') . $nama;
                                }
                            }
                            foreach ($ids as $bid):
                                $bid = (int)$bid;
                                $label = $name_map[$bid] ?? ('ID:' . $bid);
                                $d = (float)($diffs[$bid] ?? 0);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td class="text-end text-danger fw-bold"><?= fmt_qty($d) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-white">
                <a href="index.php?page=stok_petugas_hc&klinik_id=<?= (int)$selected_klinik ?>&petugas_user_id=<?= (int)$petugas_user_id ?>&bulk_cancel=1" class="btn btn-outline-secondary">Batalkan</a>
                <form method="POST" action="actions/process_hc_bulk_upload_confirm.php" class="m-0">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-primary">Lanjutkan Simpan</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.bootstrap) {
        var m = document.getElementById('modalBulkConfirm');
        if (m) bootstrap.Modal.getOrCreateInstance(m, { backdrop: 'static' }).show();
    }
});
</script>
<?php endif; ?>

<?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'], true) && $role !== 'petugas_hc' && $selected_klinik > 0): ?>
<div class="modal fade" id="modalTransferHC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-exchange-alt me-2"></i>Transfer Onsite → Petugas HC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="actions/process_hc_transfer.php" class="modal-body bg-light" id="formTransferHC">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="klinik_id" value="<?= (int)$selected_klinik ?>">
                <input type="hidden" name="is_ajax" value="1">
                
                <div id="transferErrorContainer" class="alert alert-danger d-none mb-3"></div>
                
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
                                                <th class="text-center" style="width:140px;">UOM</th>
                                                <th class="text-center" style="width:60px;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transferItemBody">
                                            <tr class="transfer-item-row">
                                                <td class="p-2">
                                                    <select name="barang_id[]" class="form-select transfer-barang-select" required>
                                                        <option value="">- Pilih Barang -</option>
                                                        <?php
                                                            require_once __DIR__ . '/../../lib/stock.php';
                                                            $res_b = $conn->query("
                                                                SELECT
                                                                    b.id,
                                                                    b.kode_barang,
                                                                    b.nama_barang,
                                                                    COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom_oper,
                                                                    COALESCE(NULLIF(uc.from_uom,''), '') AS uom_odoo,
                                                                    COALESCE(uc.multiplier, 1) AS uom_ratio
                                                                FROM inventory_barang b
                                                                LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
                                                                ORDER BY b.nama_barang ASC
                                                            ");
                                                            while ($res_b && ($bb = $res_b->fetch_assoc())):
                                                                // Optimized: removed heavy stock_effective loop
                                                        ?>
                                                            <option value="<?= (int)$bb['id'] ?>"
                                                                    data-uom-oper="<?= htmlspecialchars((string)($bb['uom_oper'] ?? ''), ENT_QUOTES) ?>"
                                                                    data-uom-odoo="<?= htmlspecialchars((string)($bb['uom_odoo'] ?? ''), ENT_QUOTES) ?>"
                                                                    data-uom-ratio="<?= htmlspecialchars((string)($bb['uom_ratio'] ?? '1'), ENT_QUOTES) ?>">
                                                                    <?= htmlspecialchars(($bb['kode_barang'] ?? '-') . ' - ' . ($bb['nama_barang'] ?? '-')) ?>
                                                            </option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </td>
                                                <td class="p-2">
                                                    <input type="number" name="qty[]" class="form-control" min="0.0001" step="0.0001" required>
                                                </td>
                                                <td class="p-2">
                                                    <select name="uom_mode[]" class="form-select form-select-sm transfer-uom-select" required>
                                                        <option value="oper">-</option>
                                                    </select>
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
        $modal.find('#transferItemBody tr.transfer-item-row').each(function() { refreshUom($(this), 'transfer'); });
        updateTransferRemoveButtons();
    }

    function initAllocateModalSelects() {
        var $modal = $('#modalAllocateMirrorHC');
        if (!$modal.length) return;
        initSelect2($modal.find('select[name="user_hc_id"]'), $modal, { placeholder: '- Pilih Petugas -', allowClear: true });
        $modal.find('.allocate-barang-select').each(function() {
            initSelect2($(this), $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        });
        $modal.find('#allocateItemBody tr.allocate-item-row').each(function() { refreshUom($(this), 'allocate'); });
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
        
        // Cari baris pertama untuk dijadikan template
        var $firstRow = $tbody.find('tr.transfer-item-row:first');
        var $tpl = $firstRow.clone();
        
        // Bersihkan Select2 dari elemen clone (penting agar tidak terjadi error destroy)
        $tpl.find('.select2-container').remove();
        $tpl.find('.select2-hidden-accessible').removeClass('select2-hidden-accessible').removeAttr('data-select2-id');
        $tpl.find('option').removeAttr('data-select2-id');
        
        var $sel = $tpl.find('select.transfer-barang-select');
        
        // Reset values
        $sel.val('');
        $tpl.find('input[name="qty[]"]').val('');
        $tpl.find('select.transfer-uom-select').html('<option value="oper">-</option>').val('oper').prop('disabled', true);
        
        $tbody.append($tpl);
        
        // Inisialisasi Select2 hanya untuk baris baru
        initSelect2($sel, $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        updateTransferRemoveButtons();
    }

    function addAllocateRow() {
        var $modal = $('#modalAllocateMirrorHC');
        var $tbody = $('#allocateItemBody');
        if (!$modal.length || !$tbody.length) return;
        
        var $firstRow = $tbody.find('tr.allocate-item-row:first');
        var $tpl = $firstRow.clone();
        
        // Bersihkan Select2 dari elemen clone
        $tpl.find('.select2-container').remove();
        $tpl.find('.select2-hidden-accessible').removeClass('select2-hidden-accessible').removeAttr('data-select2-id');
        $tpl.find('option').removeAttr('data-select2-id');
        
        var $sel = $tpl.find('select.allocate-barang-select');
        
        // Reset values
        $sel.val('');
        $tpl.find('input[name="qty[]"]').val('');
        $tpl.find('select.allocate-uom-select').html('<option value="oper">-</option>').val('oper').prop('disabled', true);
        
        $tbody.append($tpl);
        
        // Inisialisasi Select2 hanya untuk baris baru
        initSelect2($sel, $modal, { placeholder: '- Pilih Barang -', allowClear: true, minimumInputLength: 2 });
        updateAllocateRemoveButtons();
    }

    $(document).on('shown.bs.modal', '#modalTransferHC', function() {
        initTransferModalSelects();
    });
    $(document).on('shown.bs.modal', '#modalAllocateMirrorHC', function() {
        initAllocateModalSelects();
    });

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

    function refreshUom($row, kind) {
        var $barangSel = (kind === 'allocate') ? $row.find('select.allocate-barang-select') : $row.find('select.transfer-barang-select');
        var $uomSel = (kind === 'allocate') ? $row.find('select.allocate-uom-select') : $row.find('select.transfer-uom-select');
        if (!$barangSel.length || !$uomSel.length) return;
        var $opt = $barangSel.find('option:selected');
        var oper = String($opt.attr('data-uom-oper') || '').trim();
        var odoo = String($opt.attr('data-uom-odoo') || '').trim();
        var ratio = parseFloat(String($opt.attr('data-uom-ratio') || '1').replace(',', '.')) || 1;
        if (!oper) oper = '-';
        var html = '';
        if (odoo && ratio && Math.abs(ratio - 1) > 0.0000001 && oper.toLowerCase() !== odoo.toLowerCase()) {
            html += '<option value="oper">' + escapeHtml(oper) + '</option>';
            html += '<option value="odoo">' + escapeHtml(odoo) + '</option>';
            $uomSel.prop('disabled', false);
        } else {
            html += '<option value="oper">' + escapeHtml(oper) + '</option>';
            $uomSel.prop('disabled', true);
        }
        $uomSel.html(html).val('oper');
    }

    $(document).on('change', '.transfer-barang-select', function() { refreshUom($(this).closest('tr'), 'transfer'); });
    $(document).on('change', '.allocate-barang-select', function() { refreshUom($(this).closest('tr'), 'allocate'); });

    // Handle Petugas Multi-Select for Template Download
    function updateTemplateDownloadLink() {
        var $btn = $('#btnDownloadTemplateHC');
        if (!$btn.length) return;
        var klinikId = <?= (int)$selected_klinik ?>;
        var petugasUserId = <?= (int)$petugas_user_id ?>;
        var base = 'api/download_template_alokasi_mirror_hc.php?klinik_id=' + klinikId;
        
        if (petugasUserId > 0) {
            $btn.attr('href', base + '&petugas_user_id=' + petugasUserId);
            return;
        }

        var selectedIds = [];
        $('.petugas-check:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            $btn.attr('href', 'javascript:void(0)').addClass('disabled');
        } else {
            $btn.attr('href', base + '&petugas_ids=' + selectedIds.join(',')).removeClass('disabled');
        }
    }

    $(document).on('change', '#selectAllPetugas', function() {
        $('.petugas-check').prop('checked', this.checked);
        updateTemplateDownloadLink();
    });

    $(document).on('change', '.petugas-check', function() {
        var total = $('.petugas-check').length;
        var checked = $('.petugas-check:checked').length;
        $('#selectAllPetugas').prop('checked', total === checked);
        updateTemplateDownloadLink();
    });

    $(document).on('shown.bs.modal', '#modalUploadAlokasiMirrorHC', function() {
        updateTemplateDownloadLink();
    });

    $(document).on('submit', '#formTransferHC', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $err = $('#transferErrorContainer');
        
        $err.addClass('d-none').text('');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...');

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    if (res.redirect) {
                        window.location.href = res.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    $err.removeClass('d-none').text(res.message || 'Gagal menyimpan transfer.');
                    $btn.prop('disabled', false).html('Simpan Transfer');
                }
            },
            error: function() {
                $err.removeClass('d-none').text('Terjadi kesalahan sistem. Silakan coba lagi.');
                $btn.prop('disabled', false).html('Simpan Transfer');
            }
        });
    });

    $(document).on('submit', '#formAllocateHC', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $err = $('#allocateErrorContainer');
        
        $err.addClass('d-none').text('');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...');

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    if (res.redirect) {
                        window.location.href = res.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    $err.removeClass('d-none').text(res.message || 'Gagal menyimpan alokasi.');
                    $btn.prop('disabled', false).html('Simpan Allocasi');
                }
            },
            error: function() {
                $err.removeClass('d-none').text('Terjadi kesalahan sistem. Silakan coba lagi.');
                $btn.prop('disabled', false).html('Simpan Allocasi');
            }
        });
    });
})();
</script>
