<?php
require_once __DIR__ . '/../../config/settings.php';

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
$active_tab = 'stok';

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

function fmt_qty($v) {
    $n = (float)($v ?? 0);
    if (abs($n - round($n)) < 0.00005) return (string)(int)round($n);
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
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
            $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . "'";
            $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . "'";
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
            $rb_sellout_klinik_sql = "(SELECT COALESCE(SUM(pbd.qty), 0) FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE pbd.barang_id = b.id AND $rb_pb_filter AND TRIM(pb.jenis_pemakaian) != 'hc' AND pb.created_at > '$rb_ts_start' AND pb.created_at <= '$rb_ts_end' AND pb.created_at >= '$rb_ts_min')";

            $rb_in_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND ts.level_id $klinik_filter_sql AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_out_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND ts.level_id $klinik_filter_sql AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            $rb_sellout_hc_sql = "(SELECT COALESCE(SUM(pbd.qty), 0) FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE pbd.barang_id = b.id AND pb.klinik_id $klinik_filter_sql AND TRIM(pb.jenis_pemakaian) = 'hc' AND pb.created_at > '$rb_ts_start' AND pb.created_at <= '$rb_ts_end' AND pb.created_at >= '$rb_ts_min')";
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
                         AND ts.level_id $klinik_filter_sql
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
                     AND ts.level_id $klinik_filter_sql
                     AND ts.tipe_transaksi = 'in'
                     AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as in_transfer_hc,
                    (SELECT COALESCE(SUM(ts.qty), 0)
                     FROM inventory_transaksi_stok ts
                     WHERE ts.barang_id = b.id
                     AND ts.level = 'hc'
                     AND ts.level_id $klinik_filter_sql
                     AND ts.tipe_transaksi = 'out'
                     AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as out_transfer_hc,
                    $rb_in_transfer_sql as rb_in_transfer,
                    $rb_out_transfer_sql as rb_out_transfer,
                    $rb_in_transfer_hc_sql as rb_in_transfer_hc,
                    $rb_out_transfer_hc_sql as rb_out_transfer_hc,
                    $rb_sellout_klinik_sql as rb_sellout_klinik,
                    $rb_sellout_hc_sql as rb_sellout_hc
                  FROM ($union_sql) p
                  LEFT JOIN (SELECT odoo_product_id, SUM(qty) as qty FROM inventory_stock_mirror WHERE TRIM(location_code) $loc_filter_sql GROUP BY odoo_product_id) sm_k ON sm_k.odoo_product_id = p.odoo_product_id
                  LEFT JOIN (SELECT odoo_product_id, SUM(qty) as qty FROM inventory_stock_mirror WHERE TRIM(location_code) $hc_loc_filter_sql GROUP BY odoo_product_id) sm_h ON sm_h.odoo_product_id = p.odoo_product_id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Klinik (Public) - <?= APP_NAME ?></title>
    <link rel="icon" type="image/x-icon" href="<?= base_url('assets/img/favicon.ico') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .text-primary-custom { color: #204EAB; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border-radius: 12px; }
        .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
        .breadcrumb-item active { color: #6c757d; }
        .last-update { font-size: 0.875rem; color: #6c757d; }
        .card-summary { border: 1px solid #eef0f2; transition: all 0.2s; }
        .card-summary:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .summary-label { font-size: 0.7rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-value { font-size: 1.5rem; font-weight: 700; color: #333; }
        .summary-icon { opacity: 0.4; font-size: 1.25rem; }
        .table thead th { background-color: #204EAB; color: white; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border: none; vertical-align: middle; white-space: nowrap; }
        .table td { vertical-align: middle; }
    </style>
</head>
<body>

<div class="container-fluid py-3 px-4">
    <div class="row mb-3 align-items-center">
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
        <div class="col-auto">
            <img src="<?= base_url('assets/img/favicon.ico') ?>" alt="Logo" height="35">
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1"><i class="fas fa-hospital text-primary me-1"></i>Klinik <span class="text-danger">*</span></label>
                    <form method="GET">
                        <input type="hidden" name="page" value="stok_klinik_publik">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <select name="klinik_id" class="form-select" onchange="this.form.submit()">
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
                    </form>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small mb-1"><i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal</label>
                    <form method="GET">
                        <input type="hidden" name="page" value="stok_klinik_publik">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="klinik_id" value="<?= htmlspecialchars($selected_klinik) ?>">
                        <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="col-md-5 text-end">
                    <div class="last-update mb-2">Terakhir update: <?= htmlspecialchars($last_update_text ?? '-') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Jenis Barang</div>
                        <div class="summary-value"><?= $summary_stok['total_items'] ?></div>
                    </div>
                    <i class="fas fa-boxes summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Stok On Site</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['total_qty'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-cubes summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Stok HC</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['total_qty_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Sellout Onsite</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['total_sellout_klinik'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-history summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Reserve Onsite</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['reserve_onsite'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-hospital summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Sellout HC</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['total_sellout_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card card-summary h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-start">
                    <div>
                        <div class="summary-label">Reserve HC</div>
                        <div class="summary-value"><?= fmt_qty($summary_stok['reserve_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse summary-icon text-primary-custom"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 datatable-stok">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Satuan</th>
                            <th>Stock On Site</th>
                            <?php if ($show_hc): ?><th>Stok HC</th><?php endif; ?>
                            <th>Sellout Onsite</th>
                            <?php if ($show_hc): ?><th>Sellout HC</th><?php endif; ?>
                            <th>Reserve Onsite</th>
                            <?php if ($show_hc): ?><th>Reserve HC</th><?php endif; ?>
                            <th>On Hand Stok</th>
                            <th>Available Stok</th>
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
                            
                            $on_hand = ($stok_onsite - (!$is_history_date ? $sellout : 0)) + ($show_hc ? ($stok_hc - (!$is_history_date ? $sellout_hc : 0)) : 0);
                            $available = $on_hand - ($reserve + ($show_hc ? $reserve_hc : 0));
                        ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars(!empty($row['kode_barang_master']) ? $row['kode_barang_master'] : ($row['kode_barang'] ?? '-')) ?></td>
                            <td class="small fw-medium"><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                            <td class="fw-bold"><?= fmt_qty($stok_onsite) ?></td>
                            <?php if ($show_hc): ?>
                            <td>
                                <?php if ($stok_hc > 0): ?>
                                    <a href="javascript:void(0)" class="text-primary fw-bold text-decoration-none"
                                       onclick="loadHCDetail(<?= $row['barang_id'] ?>, <?= $selected_klinik === 'all' ? 0 : $selected_klinik ?>, '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>'); return false;">
                                        <?= fmt_qty($stok_hc) ?> <i class="fas fa-user-nurse"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">0</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="<?= $sellout > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sellout) ?></td>
                            <?php if ($show_hc): ?><td class="<?= $sellout_hc > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sellout_hc) ?></td><?php endif; ?>
                            <td class="<?= $reserve > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?= fmt_qty($reserve) ?></td>
                            <?php if ($show_hc): ?><td class="<?= $reserve_hc > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?= fmt_qty($reserve_hc) ?></td><?php endif; ?>
                            <td class="<?= $on_hand < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($on_hand) ?></td>
                            <td class="<?= $available < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($available) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
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
    $('.datatable-stok').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 10,
        "language": {
            "search": "Cari:",
            "searchPlaceholder": "Cari...",
            "lengthMenu": "Tampilkan _MENU_ data",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "Selanjutnya",
                "previous": "Sebelumnya"
            }
        }
    });
});

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
</body>
</html>