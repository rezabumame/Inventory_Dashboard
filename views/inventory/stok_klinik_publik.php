<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';

// PUBLIC ACCESS CHECK
$token = $_GET['token'] ?? '';
$saved_token = get_setting('public_stok_token');
if ($token === '' || $token !== $saved_token) {
    die("Access Denied: Invalid Token");
}

// Mock session for logic compatibility
$can_filter_klinik = true;
$is_cs = false;
$can_view_monitoring = false;
$active_tab = $_GET['tab'] ?? 'stok';
if (!in_array($active_tab, ['stok', 'rekap'])) $active_tab = 'stok';

$selected_klinik = '';
$include_gudang = isset($_GET['include_gudang']) && $_GET['include_gudang'] == '1';
$gudang_utama_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));

$selected_klinik = isset($_GET['klinik_id']) ? $_GET['klinik_id'] : 'all';
if ($selected_klinik !== 'all' && $selected_klinik !== 'gudang_utama' && $selected_klinik !== '') $selected_klinik = (int)$selected_klinik;

$selected_pemeriksaan = isset($_GET['pemeriksaan_id']) ? (int)$_GET['pemeriksaan_id'] : '';
$today_date = date('Y-m-d');
$min_filter_date = date('Y-m-01');
$filter_date = isset($_GET['tanggal']) ? (string)$_GET['tanggal'] : $today_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) $filter_date = $today_date;
if (strtotime($filter_date) > strtotime($today_date)) $filter_date = $today_date;
if (strtotime($filter_date) < strtotime($min_filter_date)) $filter_date = $min_filter_date;
$month_start = date('Y-m-01', strtotime($filter_date));
$month_end = date('Y-m-t', strtotime($filter_date));
$month_start_ts = $month_start . ' 00:00:00';
$filter_end_ts = $filter_date . ' 23:59:59';
$is_history_date = ($filter_date !== $today_date);
$filter_date_label = date('d M Y', strtotime($filter_date));

// Fetch Kliniks
$kliniks = [];
$res = $conn->query("SELECT * FROM inventory_klinik WHERE status='active'");
while ($row = $res->fetch_assoc()) $kliniks[] = $row;

// Determine if HC columns should be shown
$show_hc = false;
if ($selected_klinik === 'all') {
    foreach ($kliniks as $k) {
        if (!empty($k['kode_homecare'])) {
            $show_hc = true;
            break;
        }
    }
} elseif ($selected_klinik === 'gudang_utama') {
    $show_hc = false;
} elseif ($selected_klinik) {
    foreach ($kliniks as $k) {
        if ($k['id'] == $selected_klinik) {
            if (!empty($k['kode_homecare'])) {
                $show_hc = true;
            }
            break;
        }
    }
}

// Normal View for others (Item List)
$rows = [];
$summary_stok = [
    'total_items' => 0,
    'total_qty' => 0,
    'total_qty_hc' => 0,
    'total_pending' => 0,
    'reserve_onsite' => 0,
    'reserve_hc' => 0,
    'total_sellout_klinik' => 0,
    'total_sellout_hc' => 0
];

if ($selected_klinik) {
    if ($selected_klinik === 'all') {
        $selected_klinik_id_sql = "0";
        $selected_klinik_row = ['nama_klinik' => 'Semua Klinik', 'kode_klinik' => '', 'kode_homecare' => ''];
        $active_ids = array_map(function($k){return (int)$k['id'];}, $kliniks);
        $ids_str = implode(',', $active_ids);
        
        $active_codes = [];
        $active_hc_codes = [];
        foreach ($kliniks as $k) {
            if (!empty($k['kode_klinik'])) $active_codes[] = "'" . $conn->real_escape_string(trim($k['kode_klinik'])) . "'";
            if (!empty($k['kode_homecare'])) $active_hc_codes[] = "'" . $conn->real_escape_string(trim($k['kode_homecare'])) . "'";
        }
        
        if ($include_gudang) {
            $gudang_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
            if ($gudang_loc !== '') {
                $active_codes[] = "'" . $conn->real_escape_string($gudang_loc) . "'";
            }
        }
        
        $codes_str = implode(',', $active_codes);
        $hc_codes_str = !empty($active_hc_codes) ? implode(',', $active_hc_codes) : "''";
        
        $klinik_filter_sql = "IN ($ids_str)";
        $loc_filter_sql = "IN ($codes_str)";
        $hc_loc_filter_sql = "IN ($hc_codes_str)";
        
        $kode_klinik = '';
        $kode_homecare = '';
        $kode_klinik_esc = '';
        $kode_homecare_esc = '';
    } elseif ($selected_klinik === 'gudang_utama') {
        $selected_klinik_id_sql = "0";
        $selected_klinik_row = ['nama_klinik' => 'Gudang Utama', 'kode_klinik' => $gudang_utama_loc, 'kode_homecare' => ''];
        $kode_klinik = $gudang_utama_loc;
        $kode_homecare = '';
        $kode_klinik_esc = $conn->real_escape_string($kode_klinik);
        $kode_homecare_esc = '';
        
        $klinik_filter_sql = "= 0"; // No numeric ID for main warehouse
        $loc_filter_sql = "= '$kode_klinik_esc'";
        $hc_loc_filter_sql = "= ''";
    } else {
        $selected_klinik_id = (int)$selected_klinik;
        $selected_klinik_id_sql = (string)$selected_klinik_id;
        $selected_klinik_row = null;
        foreach ($kliniks as $k) {
            if ((int)$k['id'] === $selected_klinik_id) {
                $selected_klinik_row = $k;
                break;
            }
        }
        if (!$selected_klinik_row) {
            $res_one = $conn->query("SELECT * FROM inventory_klinik WHERE id = $selected_klinik_id LIMIT 1");
            if ($res_one && $res_one->num_rows > 0) $selected_klinik_row = $res_one->fetch_assoc();
        }

        $kode_klinik = trim((string)($selected_klinik_row['kode_klinik'] ?? ''));
        $kode_homecare = trim((string)($selected_klinik_row['kode_homecare'] ?? ''));
        $kode_klinik_esc = $conn->real_escape_string($kode_klinik);
        $kode_homecare_esc = $conn->real_escape_string($kode_homecare);
        
        $klinik_filter_sql = "= $selected_klinik_id";
        $loc_filter_sql = "= '$kode_klinik_esc'";
        $hc_loc_filter_sql = "= '$kode_homecare_esc'";
    }

    $last_update_text = '-';
    $last_update_general = '';
    
    // Get MAX update across all relevant locations
    $locs_esc_list = [];
    if ($selected_klinik === 'all') {
        $locs_esc_list[] = $codes_str;
        if ($show_hc && $hc_codes_str !== "''") $locs_esc_list[] = $hc_codes_str;
    } else {
        if ($kode_klinik !== '') $locs_esc_list[] = "'$kode_klinik_esc'";
        if ($show_hc && $kode_homecare !== '') $locs_esc_list[] = "'$kode_homecare_esc'";
    }
    
    if (!empty($locs_esc_list)) {
        $locs_str = implode(',', $locs_esc_list);
        $res_u = $conn->query("SELECT MAX(updated_at) as last_update FROM inventory_stock_mirror WHERE TRIM(location_code) IN ($locs_str)");
        if ($res_u && $res_u->num_rows > 0) {
            $urow = $res_u->fetch_assoc();
            if (!empty($urow['last_update'])) $last_update_general = (string)$urow['last_update'];
        }
    }

    // If mirror is empty, try global sync setting
    if ($last_update_general === '') {
        $res_gs = $conn->query("SELECT v FROM inventory_app_settings WHERE k = 'odoo_sync_last_run' LIMIT 1");
        if ($res_gs && ($gs = $res_gs->fetch_assoc())) {
            $last_update_general = date('Y-m-d H:i:s', (int)$gs['v']);
        }
    }

    if ($last_update_general !== '') $last_update_text = date('d M Y H:i', strtotime($last_update_general));

    if ($selected_klinik === 'all' || $kode_klinik !== '') {
        $last_update_date = $last_update_general !== '' ? date('Y-m-d', strtotime($last_update_general)) : '';

        if ($is_history_date) {
            $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
            $filter_bp_hc = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
            $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
            $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
            $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at <= '" . $conn->real_escape_string($filter_end_ts) . "'";
            $filter_ts_hc = $filter_ts_klinik;
        } else {
            if ($last_update_general !== '') {
                $last_update_time = date('H:i:s', strtotime($last_update_general));
                $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($month_start) . "' 
                    AND (
                        bp.created_at > '" . $conn->real_escape_string($last_update_general) . "' 
                        OR bp.tanggal_pemeriksaan > '" . $conn->real_escape_string($last_update_date) . "'
                        OR (bp.tanggal_pemeriksaan = '" . $conn->real_escape_string($last_update_date) . "' AND bp.jam_layanan >= '$last_update_time')
                    )";
                $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.created_at > '" . $conn->real_escape_string($last_update_general) . "'";
                $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at > '" . $conn->real_escape_string($last_update_general) . "'";
                
                $filter_bp_hc = $filter_bp_onsite;
                $filter_pb_hc = $filter_pb_klinik;
                $filter_ts_hc = $filter_ts_klinik;
            } else {
                $filter_bp_onsite = " AND 1=0";
                $filter_pb_klinik = " AND 1=0";
                $filter_ts_klinik = " AND 1=0";
                $filter_bp_hc = " AND 1=0";
                $filter_pb_hc = " AND 1=0";
                $filter_ts_hc = " AND 1=0";
            }
        }

        $filter_pb2_klinik = str_replace("pb.", "pb2.", $filter_pb_klinik);
        $filter_pb2_hc = str_replace("pb.", "pb2.", $filter_pb_hc);

        $rb_in_transfer_sql = "0";
        $rb_out_transfer_sql = "0";
        $rb_in_transfer_hc_sql = "0";
        $rb_out_transfer_hc_sql = "0";
        $rb_sellout_klinik_sql = "0";
        $rb_sellout_hc_sql = "0";
        $hc_user_filter_sql = "EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id $klinik_filter_sql)";
        
        // Siapkan timestamp untuk subquery rollback (selalu siapkan jika mode history)
        $rb_ts_start = $conn->real_escape_string($filter_end_ts);
        $rb_ts_min = $conn->real_escape_string($month_start_ts);
        
        if ($is_history_date) {
            // General Rollback SQL using last_update_general
            $rb_ts_end = $last_update_general !== '' ? $conn->real_escape_string($last_update_general) : $conn->real_escape_string(date('Y-m-d H:i:s'));
            
            $rb_level_filter = "((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ")";
            $rb_pb_filter = "(pb.klinik_id $klinik_filter_sql" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (pb.jenis_pemakaian = 'gudang_utama')" : "") . ")"; // assuming jenis_pemakaian exists for gudang

            $rb_in_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND $rb_level_filter AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_out_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND $rb_level_filter AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_sellout_klinik_sql = "(SELECT COALESCE(SUM(pbd.qty), 0) FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE pbd.barang_id = b.id AND $rb_pb_filter AND TRIM(pb.jenis_pemakaian) != 'hc' AND pb.tanggal > '$rb_ts_start' AND pb.tanggal <= '$rb_ts_end' AND pb.tanggal >= '$rb_ts_min')";

            $rb_in_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_out_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_sellout_hc_sql = "(SELECT COALESCE(SUM(pbd.qty), 0) FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE pbd.barang_id = b.id AND pb.klinik_id $klinik_filter_sql AND TRIM(pb.jenis_pemakaian) = 'hc' AND pb.tanggal > '$rb_ts_start' AND pb.tanggal <= '$rb_ts_end' AND pb.tanggal >= '$rb_ts_min')";
        }

        $union_sql = "SELECT odoo_product_id, kode_barang FROM inventory_stock_mirror WHERE TRIM(location_code) $loc_filter_sql";
        if ($show_hc && ($selected_klinik === 'all' ? $hc_codes_str !== "''" : $kode_homecare !== '')) {
            $union_sql .= " UNION SELECT odoo_product_id, kode_barang FROM inventory_stock_mirror WHERE TRIM(location_code) $hc_loc_filter_sql";
        }

        $query = "SELECT 
                    $selected_klinik_id_sql as klinik_id,
                    '$kode_klinik_esc' as kode_klinik,
                    '$kode_homecare_esc' as kode_homecare,
                    " . ($selected_klinik === 'all' ? "'Semua Klinik' as nama_klinik," : ($selected_klinik === 'gudang_utama' ? "'Gudang Utama' as nama_klinik," : "k.nama_klinik,")) . "
                    p.odoo_product_id,
                    p.kode_barang,
                    b.id as barang_id,
                    b.kode_barang as kode_barang_master,
                    COALESCE(b.nama_barang, p.kode_barang) as nama_barang,
                    COALESCE(uc.to_uom, b.satuan) as satuan,
                    COALESCE(uc.from_uom, '') as uom_odoo,
                    COALESCE(uc.multiplier, 1) as uom_multiplier,
                    COALESCE(sm_k.qty, 0) / NULLIF(COALESCE(uc.multiplier, 1), 0) as qty,
                    COALESCE(sm_h.qty, 0) / NULLIF(COALESCE(uc.multiplier, 1), 0) as stok_hc,
                    (SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END), 0)
                     FROM inventory_booking_detail bd 
                     JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id 
                     WHERE bd.barang_id = b.id 
                     AND bp.klinik_id $klinik_filter_sql
                     AND bp.status = 'booked'
                     AND bp.status_booking LIKE '%Clinic%'$filter_bp_onsite) as reserve_onsite,
                    (SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END), 0)
                     FROM inventory_booking_detail bd
                     JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
                     WHERE bd.barang_id = b.id
                     AND bp.klinik_id $klinik_filter_sql
                     AND bp.status = 'booked'
                     AND bp.status_booking LIKE '%HC%'$filter_bp_hc) as reserve_hc,
                    COALESCE(
                        NULLIF(
                            (SELECT COALESCE(SUM(pbd.qty), 0) 
                             FROM inventory_pemakaian_bhp_detail pbd 
                             JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id 
                             WHERE pbd.barang_id = b.id AND pb.klinik_id $klinik_filter_sql AND pb.jenis_pemakaian != 'hc'$filter_pb_klinik),
                            0
                        ),
                        (SELECT COALESCE(SUM(ts.qty), 0)
                         FROM inventory_transaksi_stok ts
                         JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                         WHERE ts.barang_id = b.id
                         AND ts.level = 'klinik'
                         AND ts.level_id $klinik_filter_sql
                         AND ts.tipe_transaksi = 'out'
                         AND ts.referensi_tipe = 'pemakaian_bhp'
                         AND pb2.klinik_id $klinik_filter_sql
                         AND pb2.jenis_pemakaian != 'hc'$filter_pb2_klinik),
                        0
                    ) as sellout_klinik,
                    COALESCE(
                        NULLIF(
                            (SELECT COALESCE(SUM(pbd.qty), 0) 
                             FROM inventory_pemakaian_bhp_detail pbd 
                             JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id 
                             WHERE pbd.barang_id = b.id AND pb.klinik_id $klinik_filter_sql AND pb.jenis_pemakaian = 'hc'$filter_pb_hc),
                            0
                        ),
                        (SELECT COALESCE(SUM(ts.qty), 0)
                         FROM inventory_transaksi_stok ts
                         JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                         WHERE ts.barang_id = b.id
                         AND ts.level = 'hc'
                         AND $hc_user_filter_sql
                         AND ts.tipe_transaksi = 'out'
                         AND ts.referensi_tipe = 'pemakaian_bhp'
                         AND pb2.klinik_id $klinik_filter_sql
                         AND pb2.jenis_pemakaian = 'hc'$filter_pb2_hc),
                        0
                    ) as sellout_hc,
                    (SELECT COALESCE(SUM(ts.qty), 0)
                     FROM inventory_transaksi_stok ts
                     WHERE ts.barang_id = b.id
                     AND ((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ")
                     AND ts.tipe_transaksi = 'in'
                     AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik) as in_transfer,
                    (SELECT COALESCE(SUM(ts.qty), 0)
                     FROM inventory_transaksi_stok ts
                     WHERE ts.barang_id = b.id
                     AND ((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ")
                     AND ts.tipe_transaksi = 'out'
                     AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik) as out_transfer,
                    (SELECT COALESCE(SUM(ts.qty), 0)
                     FROM inventory_transaksi_stok ts
                     WHERE ts.barang_id = b.id
                     AND ts.level = 'hc'
                     AND $hc_user_filter_sql
                     AND ts.tipe_transaksi = 'in'
                     AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as in_transfer_hc,
                    (SELECT COALESCE(SUM(ts.qty), 0)
                     FROM inventory_transaksi_stok ts
                     WHERE ts.barang_id = b.id
                     AND ts.level = 'hc'
                     AND $hc_user_filter_sql
                     AND ts.tipe_transaksi = 'out'
                     AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as out_transfer_hc,
                    $rb_in_transfer_sql as rb_in_transfer,
                    $rb_out_transfer_sql as rb_out_transfer,
                    $rb_in_transfer_hc_sql as rb_in_transfer_hc,
                    $rb_out_transfer_hc_sql as rb_out_transfer_hc,
                    $rb_sellout_klinik_sql as rb_sellout_klinik,
                    $rb_sellout_hc_sql as rb_sellout_hc
                  FROM ($union_sql) p
                  LEFT JOIN (
                    SELECT sm1.odoo_product_id, SUM(sm1.qty) as qty
                    FROM inventory_stock_mirror sm1
                    JOIN (
                        SELECT odoo_product_id, TRIM(location_code) AS loc, MAX(updated_at) AS max_updated
                        FROM inventory_stock_mirror
                        WHERE TRIM(location_code) $loc_filter_sql
                        GROUP BY odoo_product_id, TRIM(location_code)
                    ) last_sm ON last_sm.odoo_product_id = sm1.odoo_product_id AND last_sm.loc = TRIM(sm1.location_code) AND last_sm.max_updated = sm1.updated_at
                    WHERE TRIM(sm1.location_code) $loc_filter_sql
                    GROUP BY sm1.odoo_product_id
                  ) sm_k ON sm_k.odoo_product_id = p.odoo_product_id
                  LEFT JOIN (
                    SELECT sm1.odoo_product_id, SUM(sm1.qty) as qty
                    FROM inventory_stock_mirror sm1
                    JOIN (
                        SELECT odoo_product_id, TRIM(location_code) AS loc, MAX(updated_at) AS max_updated
                        FROM inventory_stock_mirror
                        WHERE TRIM(location_code) $hc_loc_filter_sql
                        GROUP BY odoo_product_id, TRIM(location_code)
                    ) last_sm ON last_sm.odoo_product_id = sm1.odoo_product_id AND last_sm.loc = TRIM(sm1.location_code) AND last_sm.max_updated = sm1.updated_at
                    WHERE TRIM(sm1.location_code) $hc_loc_filter_sql
                    GROUP BY sm1.odoo_product_id
                  ) sm_h ON sm_h.odoo_product_id = p.odoo_product_id
                  LEFT JOIN inventory_barang b ON (b.odoo_product_id = p.odoo_product_id OR b.kode_barang = p.kode_barang)
                  LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
                  " . (($selected_klinik === 'all' || $selected_klinik === 'gudang_utama') ? "" : "JOIN inventory_klinik k ON k.id = $selected_klinik_id") . "
                  WHERE 1=1";

        $query .= " ORDER BY kode_barang_master ASC, nama_barang ASC";
        try {
            $conn->query("SET SQL_BIG_SELECTS=1");
            $result = $conn->query($query);
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
                $summary_stok['total_items']++;
                if ($is_history_date) {
                    $adj_qty = (float)($r['qty'] ?? 0) + (float)($r['rb_out_transfer'] ?? 0) - (float)($r['rb_in_transfer'] ?? 0) + (float)($r['rb_sellout_klinik'] ?? 0);
                    $adj_hc = (float)($r['stok_hc'] ?? 0) + (float)($r['rb_out_transfer_hc'] ?? 0) - (float)($r['rb_in_transfer_hc'] ?? 0) + (float)($r['rb_sellout_hc'] ?? 0);
                    $summary_stok['total_qty'] += $adj_qty;
                    $summary_stok['total_qty_hc'] += $adj_hc;
                } else {
                    $eff_qty = (float)($r['qty'] ?? 0) + (float)($r['in_transfer'] ?? 0) - (float)($r['out_transfer'] ?? 0);
                    $eff_hc = (float)($r['stok_hc'] ?? 0) + (float)($r['in_transfer_hc'] ?? 0) - (float)($r['out_transfer_hc'] ?? 0);
                    $summary_stok['total_qty'] += $eff_qty;
                    $summary_stok['total_qty_hc'] += $eff_hc;
                }
                $summary_stok['reserve_onsite'] += (float)($r['reserve_onsite'] ?? 0);
                $summary_stok['reserve_hc'] += (float)($r['reserve_hc'] ?? 0);
                $summary_stok['total_sellout_klinik'] += (float)($r['sellout_klinik'] ?? 0);
                $summary_stok['total_sellout_hc'] += (float)($r['sellout_hc'] ?? 0);
            }
        } catch (Exception $e) {
            die("Query Error: " . $e->getMessage());
        }
    }
}

// TAB REKAPITULASI (Monthly Summary) Logic
if ($active_tab == 'rekap') {
    $selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    $selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

    // Date Calculations
    $first_day = "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . "-01";
    $last_day = date('Y-m-t', strtotime($first_day));
    $total_days = (int)date('t', strtotime($first_day));

    $today_y = date('Y-m-d');
    $current_month = (int)date('n');
    $current_year = (int)date('Y');

    if ($selected_year < $current_year || ($selected_year == $current_year && $selected_month < $current_month)) {
        $days_passed = $total_days;
    } elseif ($selected_year == $current_year && $selected_month == $current_month) {
        $days_passed = (int)date('j');
    } else {
        $days_passed = 0;
    }

    $time_gone_percent = round(($days_passed / ($total_days ?: 1)) * 100, 1);
    $mtd_date = date('d M', strtotime("$selected_year-$selected_month-" . ($days_passed ?: 1)));
    $mtd_label = date('M Y', strtotime($first_day));

    // Data Fetching
    $where_pb = "pb.status = 'active' AND pb.tanggal BETWEEN '$first_day' AND '$last_day'";
    $where_bp_completed = "bp.status = 'completed' AND bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";
    $where_bp_all_status = "bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";

    if ($selected_klinik && $selected_klinik !== 'all' && $selected_klinik !== 'gudang_utama') {
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
    $summary_rekap = [
        'sellout_onsite' => 0,
        'sellout_hc' => 0,
        'reserve_onsite' => 0,
        'reserve_hc' => 0,
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
            if ($s_onsite < $rs_onsite) $s_onsite = $rs_onsite;
            if ($s_hc < $rs_hc) $s_hc = $rs_hc;
            $s_total = $s_onsite + $s_hc;
            $rb_onsite = (float)($reserve_booked_data[$bid]['onsite'] ?? 0);
            $rb_hc = (float)($reserve_booked_data[$bid]['hc'] ?? 0);
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

            $summary_rekap['sellout_onsite'] += $s_onsite;
            $summary_rekap['sellout_hc'] += $s_hc;
            $summary_rekap['reserve_onsite'] += $rs_onsite;
            $summary_rekap['reserve_hc'] += $rs_hc;
            $summary_rekap['reserve_booked_onsite'] += $rb_onsite;
            $summary_rekap['reserve_booked_hc'] += $rb_hc;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Klinik (Public) - <?= APP_NAME ?></title>
    <link rel="icon" type="image/x-icon" href="<?= base_url('assets/img/favicon.ico') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        .text-primary-custom { color: #204EAB; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border-radius: 12px; }
        .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
        .breadcrumb-item active { color: #6c757d; }
        .stat-card-public {
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            border-left: 4px solid #204EAB;
        }
        .stat-card-public:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08); }
        .stat-card-public .stat-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 2px; letter-spacing: 0.4px; }
        .stat-card-public .stat-value { font-size: 1.3rem; font-weight: 800; color: #1e293b; margin-top: auto; line-height: 1.2; }
        .stat-card-public .stat-icon { font-size: 1.2rem; opacity: 0.15; position: absolute; right: 0.9rem; top: 0.9rem; color: #204EAB; }
        .stat-card-public.stat-blue { border-left-color: #bae6fd; }
        .stat-card-public.stat-blue .stat-label, .stat-card-public.stat-blue .stat-value, .stat-card-public.stat-blue .stat-icon { color: #0369a1; }

        .table thead th { background-color: #204EAB; color: white; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border: 1px solid rgba(255,255,255,0.1); vertical-align: middle; white-space: nowrap; }
        .table td { vertical-align: middle; border: 1px solid #f1f5f9; }

        /* Pagination Circular Styling */
        .dataTables_wrapper .dataTables_paginate { padding-top: 1.25rem !important; }
        .dataTables_wrapper .pagination { display: flex !important; gap: 6px !important; align-items: center !important; }
        .dataTables_wrapper .pagination .page-item .page-link { 
            width: 32px !important; height: 32px !important; border-radius: 50% !important; 
            display: flex !important; align-items: center !important; justify-content: center !important; 
            padding: 0 !important; font-size: 13px !important; border: 1px solid #e5e7eb !important; 
            color: #6b7280 !important; background-color: transparent !important; transition: all 0.2s ease !important; 
        }
        .dataTables_wrapper .pagination .page-item.active .page-link { background-color: #eff6ff !important; border-color: #dbeafe !important; color: #204EAB !important; font-weight: 600 !important; }
        .dataTables_wrapper .pagination .page-item.disabled .page-link { background-color: transparent !important; border-color: #f3f4f6 !important; color: #d1d5db !important; opacity: 0.5 !important; }
        .dataTables_wrapper .pagination .page-item .page-link:focus { box-shadow: none !important; }
        .dataTables_wrapper .pagination .page-item .page-link i { font-size: 10px !important; }
        .active-blue { background-color: #204EAB !important; color: white !important; box-shadow: 0 4px 6px rgba(32, 78, 171, 0.2); }
        
        /* Rekap Styles (Mirroring monthly_summary.php) */
        .rekap-tab-content { font-family: 'Poppins', sans-serif; }
        :root {
            --primary-rekap: #204EAB;
            --bg-onsite: #f0fdf4;
            --text-onsite: #166534;
            --bg-hc: #fff7ed;
            --text-hc: #9a3412;
            --bg-reference: #e0f2fe;
            --text-reference: #0369a1;
        }
        .stat-info-badge { background: #fff; padding: 0.6rem 1rem; border-radius: 12px; display: inline-flex; align-items: center; gap: 0.75rem; border: 1px solid #e2e8f0; }
        .stat-info-label { font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
        .stat-info-value { font-size: 1rem; font-weight: 800; color: #1e293b; }
        .stat-info-badge i { font-size: 1rem; color: var(--primary-rekap); background: #f0f4ff; padding: 0.4rem; border-radius: 8px; }

        .table-recap thead th { background-color: var(--primary-rekap); color: #fff; font-size: 0.75rem; text-transform: uppercase; border: 1px solid rgba(255,255,255,0.2); padding: 0.65rem 0.75rem; vertical-align: middle; }
        .table-recap td { padding: 0.65rem 0.75rem; border: 1px solid #cbd5e1; font-size: 0.85rem; vertical-align: middle; }
        .bg-onsite-rekap { background-color: var(--bg-onsite) !important; color: var(--text-onsite) !important; }
        .bg-hc-rekap { background-color: var(--bg-hc) !important; color: var(--text-hc) !important; }
        .bg-reference-rekap { background-color: var(--bg-reference) !important; color: var(--text-reference) !important; }
        .val-zero { color: #94a3b8; font-weight: 400; }
        .val-nonzero { font-weight: 700; color: #1e293b; }
    </style>
</head>
<body>

<div class="container-fluid py-2 px-4">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold text-primary-custom">
                <i class="fas fa-hospital me-2"></i>Inventory Klinik
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="#" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Stok Klinik</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-pills mb-3 gap-2" id="stokKlinikTabs">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'stok' ? 'active-blue' : 'bg-white text-dark border' ?> fw-bold px-4 py-2 rounded-pill" href="?page=stok_klinik_publik&token=<?= $token ?>&tab=stok<?= $selected_klinik ? '&klinik_id='.$selected_klinik : '' ?><?= $filter_date ? '&tanggal='.$filter_date : '' ?>">
                <i class="fas fa-boxes me-2"></i>Stok Barang
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'rekap' ? 'active-blue' : 'bg-white text-dark border' ?> fw-bold px-4 py-2 rounded-pill" href="?page=stok_klinik_publik&token=<?= $token ?>&tab=rekap<?= $selected_klinik ? '&klinik_id='.$selected_klinik : '' ?>">
                <i class="fas fa-history me-2"></i>Rekapitulasi Aktivitas Bulanan
            </a>
        </li>
    </ul>

    <!-- TAB CONTENT: STOK BARANG -->
    <?php if ($active_tab == 'stok'): ?>

    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-3">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="stok_klinik_publik">
                <input type="hidden" name="tab" value="stok">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-clinic-medical me-1" style="color: #204EAB;"></i> Klinik</label>
                    <select name="klinik_id" class="form-select border-1" onchange="this.form.submit()">
                        <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                        <?php if ($gudang_utama_loc !== ''): ?>
                            <option value="gudang_utama" <?= $selected_klinik === 'gudang_utama' ? 'selected' : '' ?>>Gudang Utama</option>
                        <?php endif; ?>
                        <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>>
                                <?= $k['nama_klinik'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-calendar-alt me-1" style="color: #204EAB;"></i> Tanggal</label>
                    <input type="date" name="tanggal" class="form-control border-1" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
                </div>

                <div class="col-md-4 text-end">
                    <div class="last-update mb-0 text-muted" style="font-size: 0.75rem;">Terakhir update: <?= htmlspecialchars($last_update_text ?? '-') ?></div>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-3 g-2">
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Jenis Barang</div>
                <i class="fas fa-boxes stat-icon"></i>
                <div class="stat-value"><?= $summary_stok['total_items'] ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Stok On Site</div>
                <i class="fas fa-cubes stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['total_qty'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Stok HC</div>
                <i class="fas fa-user-nurse stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['total_qty_hc'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Sellout Onsite</div>
                <i class="fas fa-history stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['total_sellout_klinik'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Reserve Onsite</div>
                <i class="fas fa-hospital stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['reserve_onsite'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Sellout HC</div>
                <i class="fas fa-user-nurse stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['total_sellout_hc'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card-public">
                <div class="stat-label">Reserve HC</div>
                <i class="fas fa-user-nurse stat-icon"></i>
                <div class="stat-value"><?= fmt_qty($summary_stok['reserve_hc'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0 datatable-stok">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Satuan</th>
                            <th class="text-center">Stock On Site</th>
                            <?php if ($show_hc): ?><th class="text-center">Stok HC</th><?php endif; ?>
                            <th class="text-center">Sellout Onsite</th>
                            <?php if ($show_hc): ?><th class="text-center">Sellout HC</th><?php endif; ?>
                            <th class="text-center">Reserve Onsite</th>
                            <?php if ($show_hc): ?><th class="text-center">Reserve HC</th><?php endif; ?>
                            <th class="text-center">On Hand Stok</th>
                            <th class="text-center">Available Stok</th>
                            <?php if ($is_history_date): ?><th>Detail</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): 
                            $stok_onsite = (float)($row['qty'] ?? 0);
                            $sellout = (float)($row['sellout_klinik'] ?? 0);
                            $reserve = (float)($row['reserve_onsite'] ?? 0);
                            $stok_hc = (float)($row['stok_hc'] ?? 0);
                            $sellout_hc = (float)($row['sellout_hc'] ?? 0);
                            $reserve_hc = (float)($row['reserve_hc'] ?? 0);
                            $in_transfer = (float)($row['in_transfer'] ?? 0);
                            $out_transfer = (float)($row['out_transfer'] ?? 0);
                            $in_transfer_hc = (float)($row['in_transfer_hc'] ?? 0);
                            $out_transfer_hc = (float)($row['out_transfer_hc'] ?? 0);

                            if ($is_history_date) {
                                $stok_onsite = $stok_onsite + (float)($row['rb_out_transfer'] ?? 0) - (float)($row['rb_in_transfer'] ?? 0) + (float)($row['rb_sellout_klinik'] ?? 0);
                                $stok_hc = $stok_hc + (float)($row['rb_out_transfer_hc'] ?? 0) - (float)($row['rb_in_transfer_hc'] ?? 0) + (float)($row['rb_sellout_hc'] ?? 0);
                            } else {
                                $stok_onsite = $stok_onsite + $in_transfer - $out_transfer;
                                $stok_hc = $stok_hc + $in_transfer_hc - $out_transfer_hc;
                            }
                            
                            // Sellout always reduces On Hand, including history date mode.
                            $on_hand = ((float)$stok_onsite + ($show_hc ? (float)$stok_hc : 0.0)) - ((float)$sellout + ($show_hc ? (float)$sellout_hc : 0.0));
                            $available = (float)$on_hand - ((float)$reserve + ($show_hc ? (float)$reserve_hc : 0.0));
                        ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars(!empty($row['kode_barang_master']) ? $row['kode_barang_master'] : ($row['kode_barang'] ?? '-')) ?></td>
                            <td class="small fw-medium"><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="small text-muted">
                                <div><?= htmlspecialchars($row['satuan']) ?></div>
                                <?php if (!empty($row['uom_odoo']) && (float)($row['uom_multiplier'] ?? 1) != 1.0): ?>
                                    <div class="text-muted small" style="font-size: 0.65rem;">1 <?= htmlspecialchars($row['satuan']) ?> = <?= htmlspecialchars(fmt_qty($row['uom_multiplier'])) ?> <?= htmlspecialchars($row['uom_odoo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold">
                                <div><?= fmt_qty($stok_onsite) ?></div>
                                <?php if (!$is_history_date && (((float)$in_transfer) > 0 || ((float)$out_transfer) > 0)): ?>
                                    <div class="d-flex align-items-center justify-content-center gap-2 small mt-1" style="font-size: 0.7rem;">
                                        <div class="d-flex align-items-center gap-1">
                                            <i class="fas fa-arrow-down text-success"></i>
                                            <span class="<?= (float)$in_transfer > 0 ? 'fw-bold' : 'text-muted' ?>"><?= fmt_qty($in_transfer) ?></span>
                                        </div>
                                        <div class="text-muted" style="opacity: 0.3;">|</div>
                                        <div class="d-flex align-items-center gap-1">
                                            <i class="fas fa-arrow-up text-danger"></i>
                                            <span class="<?= (float)$out_transfer > 0 ? 'fw-bold' : 'text-muted' ?>"><?= fmt_qty($out_transfer) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="text-center">
                                <?php if ($stok_hc != 0): ?>
                                    <?php $hc_class = $stok_hc < 0 ? 'text-danger fw-bold' : 'text-primary fw-bold'; ?>
                                    <a href="javascript:void(0)" class="<?= $hc_class ?> text-decoration-none"
                                       onclick="loadHCDetail(<?= $row['barang_id'] ?>, <?= $selected_klinik === 'all' ? 0 : $selected_klinik ?>, '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>'); return false;">
                                        <?= fmt_qty($stok_hc) ?> <i class="fas fa-user-nurse"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">0</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center <?= $sellout > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sellout) ?></td>
                            <?php if ($show_hc): ?><td class="text-center <?= $sellout_hc > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sellout_hc) ?></td><?php endif; ?>
                            <td class="text-center <?= $reserve > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?= fmt_qty($reserve) ?></td>
                            <?php if ($show_hc): ?><td class="text-center <?= $reserve_hc > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?= fmt_qty($reserve_hc) ?></td><?php endif; ?>
                            <td class="text-center <?= $on_hand < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($on_hand) ?></td>
                            <td class="text-center <?= $available < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($available) ?></td>
                            <?php if ($is_history_date): ?>
                            <td class="text-center">
                                <a href="javascript:void(0)" class="text-decoration-none" onclick="openStokBreakdown(<?= (int)$row['barang_id'] ?>, '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>'); return false;">
                                    <i class="fas fa-info-circle"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; // End TAB STOK ?>

    <!-- TAB CONTENT: REKAPITULASI AKTIVITAS BULANAN -->
    <?php if ($active_tab == 'rekap'): ?>
    <div class="rekap-tab-content">
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="row align-items-center">
                    <div class="col">
                        <form action="" method="GET" class="row g-3 align-items-end">
                            <input type="hidden" name="page" value="stok_klinik_publik">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="tab" value="rekap">
                            
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-clinic-medical me-1" style="color: var(--primary-rekap);"></i> Klinik</label>
                                <select name="klinik_id" class="form-select border-1" onchange="this.form.submit()">
                                    <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                                    <?php foreach ($kliniks as $k): ?>
                                        <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-calendar-alt me-1" style="color: var(--primary-rekap);"></i> Bulan</label>
                                <select name="month" class="form-select border-1" onchange="this.form.submit()">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $selected_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-calendar-alt me-1" style="color: var(--primary-rekap);"></i> Tahun</label>
                                <select name="year" class="form-select border-1" onchange="this.form.submit()">
                                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                        <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="col-auto d-flex gap-3">
                        <div class="stat-info-badge">
                            <i class="fas fa-hourglass-half"></i>
                            <div>
                                <div class="stat-info-label">Time Gone</div>
                                <div class="stat-info-value"><?= $time_gone_percent ?>%</div>
                            </div>
                        </div>
                        <div class="stat-info-badge">
                            <i class="fas fa-calendar-check"></i>
                            <div>
                                <div class="stat-info-label">MTD</div>
                                <div class="stat-info-value"><?= $mtd_date ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stat Recap -->
        <div class="row g-2 mb-3">
            <div class="col">
                <div class="stat-card-public">
                    <div class="stat-label">Sellout Onsite</div>
                    <i class="fas fa-history stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['sellout_onsite']) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card-public">
                    <div class="stat-label">Sellout HC</div>
                    <i class="fas fa-user-md stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['sellout_hc']) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card-public">
                    <div class="stat-label">Reserve Sold Onsite</div>
                    <i class="fas fa-city stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['reserve_onsite']) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card-public">
                    <div class="stat-label">Reserve Sold HC</div>
                    <i class="fas fa-user-nurse stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['reserve_hc']) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card-public stat-blue">
                    <div class="stat-label">Booked Onsite</div>
                    <i class="fas fa-calendar-alt stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['reserve_booked_onsite']) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="stat-card-public stat-blue">
                    <div class="stat-label">Booked HC</div>
                    <i class="fas fa-calendar-check stat-icon"></i>
                    <div class="stat-value"><?= fmt_qty($summary_rekap['reserve_booked_hc']) ?></div>
                </div>
            </div>
        </div>

        <!-- Table Recap -->
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold" id="btnExportExcelRekap">
                        <i class="fas fa-file-excel me-1"></i> Export Excel
                    </button>
                </div>
                <div class="d-flex align-items-center">
                    <label class="small fw-bold text-muted me-2 mb-0">Cari:</label>
                    <input type="text" id="customSearchRekap" class="form-control form-control-sm" style="width: 180px;">
                </div>
            </div>
            <div class="table-recap-container">
                <div class="table-responsive">
                    <table class="table table-recap mb-0" id="tableSummaryRekap">
                        <thead>
                            <tr>
                                <th rowspan="2" class="text-center" style="width: 100px;">Kode Barang</th>
                                <th rowspan="2" class="text-center">Nama Barang</th>
                                <th rowspan="2" class="text-center">Satuan</th>
                                <th colspan="3" class="text-center">Sellout</th>
                                <th colspan="2" class="text-center">Non-Reserve<br><small style="text-transform: none; opacity: 0.8;">(Incl. Adjustment)</small></th>
                                <th colspan="2" class="text-center">Reserve-Sold</th>
                                <th colspan="2" class="text-center">Reserve-Booked</th>
                            </tr>
                            <tr>
                                <th class="text-center">Total</th>
                                <th class="text-center">Onsite</th>
                                <th class="text-center">HC</th>
                                <th class="text-center">Onsite</th>
                                <th class="text-center">HC</th>
                                <th class="text-center">Onsite</th>
                                <th class="text-center">HC</th>
                                <th class="text-center">Onsite</th>
                                <th class="text-center">HC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($final_data as $row): ?>
                            <tr>
                                <td class="small text-muted"><?= htmlspecialchars($row['kode_barang']) ?></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td class="text-center small text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                                <td class="text-center bg-light fw-bold"><?= fmt_qty($row['sellout_total']) ?></td>
                                <td class="text-center bg-onsite-rekap <?= $row['sellout_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['sellout_onsite']) ?></td>
                                <td class="text-center bg-hc-rekap <?= $row['sellout_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['sellout_hc']) ?></td>
                                <td class="text-center bg-onsite-rekap <?= $row['non_reserve_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['non_reserve_onsite']) ?></td>
                                <td class="text-center bg-hc-rekap <?= $row['non_reserve_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['non_reserve_hc']) ?></td>
                                <td class="text-center bg-onsite-rekap <?= $row['reserve_sold_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_sold_onsite']) ?></td>
                                <td class="text-center bg-hc-rekap <?= $row['reserve_sold_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_sold_hc']) ?></td>
                                <td class="text-center bg-reference-rekap <?= $row['reserve_booked_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_booked_onsite']) ?></td>
                                <td class="text-center bg-reference-rekap <?= $row['reserve_booked_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_booked_hc']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; // End TAB REKAP ?>
</div>

<!-- Modal HC Detail -->
<div class="modal fade" id="modalHCDetail" tabindex="-1" aria-labelledby="modalHCDetailLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title text-white" id="modalHCDetailLabel">
                    <i class="fas fa-briefcase-medical"></i> Detail Stok Petugas HC
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom">
                    <h6 class="mb-0 text-primary">
                        <i class="fas fa-box"></i> <span id="modalBarangName" class="fw-bold"></span>
                    </h6>
                </div>
                <div id="hcDetailContent" class="p-3">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2">Memuat data...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('.datatable-stok')) {
        $('.datatable-stok').DataTable().destroy();
    }
    $('.datatable-stok').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 10,
        "dom": "frtip",
        "language": {
            "search": "Cari:",
            "searchPlaceholder": "Cari...",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "<i class='fas fa-chevron-right'></i>",
                "previous": "<i class='fas fa-chevron-left'></i>"
            }
        }
    });

    var tableRekap = $('#tableSummaryRekap').DataTable({
        "order": [[ 1, "asc" ]],
        "pageLength": 10,
        "dom": "rtip", // Hide default search box
        "language": {
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "paginate": {
                "next": "<i class='fas fa-chevron-right'></i>",
                "previous": "<i class='fas fa-chevron-left'></i>"
            }
        }
    });

    $('#customSearchRekap').on('keyup', function() {
        tableRekap.search($(this).val()).draw();
    });
});

function openStokBreakdown(barangId, namaBarang) {
    var modalEl = document.getElementById('modalStokBreakdown');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    var body = document.getElementById('stokBreakdownBody');
    body.innerHTML = '<div class="text-muted text-center py-4">Memuat detail rekon...</div>';

    $.ajax({
        url: 'api/ajax_stok_breakdown_publik.php',
        method: 'POST',
        dataType: 'json',
        data: { 
            token: '<?= $token ?>',
            klinik_id: '<?= $selected_klinik ?>', 
            barang_id: barangId, 
            tanggal: '<?= $filter_date ?>' 
        }
    }).then(function(res) {
        if (!res || !res.success) {
            body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail. '+(res.message || '')+'</div>';
            return;
        }
        
        function fmtNum(v) {
            var n = parseFloat(v || 0);
            if (Math.abs(n - Math.round(n)) < 0.00005) return Math.round(n).toString();
            var s = n.toFixed(4).replace(/\.?0+$/, "");
            return s === "" ? "0" : s;
        }

        var b = res.barang || {};
        var lastU = (res.last_update && res.last_update.text) ? res.last_update.text : '-';
        var baseOn = (res.baseline && res.baseline.onsite) ? res.baseline.onsite : 0;
        var baseHc = (res.baseline && res.baseline.hc) ? res.baseline.hc : 0;
        var rb = res.rollback || {};
        var result = res.result || {};
        var reserve = res.reserve || {};
        var reserveOn = reserve.onsite || 0;
        var reserveHc = reserve.hc || 0;

        body.innerHTML = `
            <div class="mb-3 text-center">
                <div class="small text-muted mb-1 text-uppercase fw-bold">Barang</div>
                <div class="h5 fw-bold text-primary mb-0">${(b.kode_barang || '-') + ' - ' + (b.nama_barang || namaBarang)}</div>
            </div>

            <div class="row g-2 mb-4">
                <div class="col-md-6">
                    <div class="p-3 bg-light border rounded text-center h-100">
                        <div class="small text-muted mb-1 fw-semibold text-uppercase">Stok Akhir (On Hand)</div>
                        <div class="h3 mb-0 fw-bold text-dark">${fmtNum(result.stock_total || 0)}</div>
                        <div class="small text-muted mt-1">Per <?= $filter_date_label ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded text-center h-100">
                        <div class="small text-primary mb-1 fw-semibold text-uppercase">Tersedia (Siap Pakai)</div>
                        <div class="h3 mb-0 fw-bold text-primary">${fmtNum(result.tersedia || 0)}</div>
                        <div class="small text-primary mt-1">Setelah dikurangi Reservasi</div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded bg-light-subtle">
                            <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-hospital me-2"></i>Stok Onsite</h6>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Baseline (Odoo):</span>
                                <span>${fmtNum(baseOn)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small text-success">
                                <span>Rollback:</span>
                                <span class="fw-bold">+ ${fmtNum((rb.out_transfer || 0) - (rb.in_transfer || 0) + (rb.sellout_klinik || 0))}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top fw-bold text-dark">
                                <span>Total Onsite:</span>
                                <span>${fmtNum(result.stock_onsite || 0)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded bg-light-subtle">
                            <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-user-nurse me-2"></i>Stok HC</h6>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Baseline (Odoo):</span>
                                <span>${fmtNum(baseHc)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small text-success">
                                <span>Rollback:</span>
                                <span class="fw-bold">+ ${fmtNum((rb.out_transfer_hc || 0) - (rb.in_transfer_hc || 0) + (rb.sellout_hc || 0))}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top fw-bold text-dark">
                                <span>Total HC:</span>
                                <span>${fmtNum(result.stock_hc || 0)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-none border rounded mb-3">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-calendar-check me-2"></i>Reservasi Booking</h6>
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="text-muted small">Onsite</div>
                            <div class="fw-bold text-danger">-${fmtNum(reserveOn)}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">HC</div>
                            <div class="fw-bold text-danger">-${fmtNum(reserveHc)}</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-center">
                <div class="x-small text-muted">Sync terakhir: ${lastU}</div>
            </div>
        `;
    }).catch(function() {
        body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail. Koneksi bermasalah.</div>';
    });
}

function loadHCDetail(barangId, klinikId, namaBarang) {
    $('#modalBarangName').text(namaBarang);
    $('#hcDetailContent').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Memuat data...</p></div>');
    
    var modalEl = document.getElementById('modalHCDetail');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    
    $.ajax({
        url: 'api/ajax_hc_detail.php',
        method: 'POST',
        data: {
            barang_id: barangId,
            klinik_id: klinikId,
            token: '<?= $token ?>'
        },
        success: function(response) {
            $('#hcDetailContent').html(response);
        },
        error: function() {
            $('#hcDetailContent').html('<div class="alert alert-danger mb-0">Gagal memuat data.</div>');
        }
    });
    return false;
}
</script>

<div class="modal fade" id="modalStokBreakdown" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2 text-primary"></i>Detail Rekonstruksi Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="stokBreakdownBody">
                <div class="text-muted">Memuat...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
</script>
</body>
</html>