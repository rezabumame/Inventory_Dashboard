<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';
require_once __DIR__ . '/../../lib/usage.php';

// PUBLIC ACCESS CHECK
$token = $_GET['token'] ?? '';
$saved_token = get_setting('public_stok_token');
if ($token === '' || $token !== $saved_token) {
    die("Access Denied: Invalid Token");
}

$active_tab = $_GET['tab'] ?? 'stok';
$selected_klinik = isset($_GET['klinik_id']) ? $_GET['klinik_id'] : 'all';

// Shared Data
$kliniks = [];
$res = $conn->query("SELECT * FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik");
while ($row = $res->fetch_assoc()) $kliniks[] = $row;

// --- LOGIC FOR TAB: STOK ---
if ($active_tab === 'stok') {
    $include_gudang = isset($_GET['include_gudang']) && $_GET['include_gudang'] == '1';
    $gudang_utama_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));
    
    if ($selected_klinik !== 'all' && $selected_klinik !== 'gudang_utama' && $selected_klinik !== '') $selected_klinik = (int)$selected_klinik;

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
                if (!empty($k['kode_homecare'])) $show_hc = true;
                break;
            }
        }
    }

    $rows = [];
    $summary_stok = [
        'total_items' => 0,
        'total_qty' => 0,
        'total_qty_hc' => 0,
        'total_pending' => 0,
        'reserve_onsite' => 0,
        'reserve_hc' => 0,
        'total_sellout_klinik' => 0,
        'total_sellout_hc' => 0,
        'total_daily_usage' => 0
    ];

    if ($selected_klinik !== '') {
        if ($selected_klinik === 'all') {
            $selected_klinik_id_sql = "0";
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
                if ($gudang_loc !== '') $active_codes[] = "'" . $conn->real_escape_string($gudang_loc) . "'";
            }
            
            $codes_str = implode(',', $active_codes);
            $hc_codes_str = !empty($active_hc_codes) ? implode(',', $active_hc_codes) : "''";
            
            $klinik_filter_sql = "IN ($ids_str)";
            $loc_filter_sql = "IN ($codes_str)";
            $hc_loc_filter_sql = "IN ($hc_codes_str)";
            $kode_klinik_esc = '';
            $kode_homecare_esc = '';
        } elseif ($selected_klinik === 'gudang_utama') {
            $selected_klinik_id_sql = "0";
            $kode_klinik = $gudang_utama_loc;
            $kode_klinik_esc = $conn->real_escape_string($kode_klinik);
            $klinik_filter_sql = "= 0";
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
            if ($res_u && ($urow = $res_u->fetch_assoc())) {
                if (!empty($urow['last_update'])) $last_update_general = (string)$urow['last_update'];
            }
        }

        if ($last_update_general === '') {
            $res_gs = $conn->query("SELECT v FROM inventory_app_settings WHERE k = 'odoo_sync_last_run' LIMIT 1");
            if ($res_gs && ($gs = $res_gs->fetch_assoc())) $last_update_general = date('Y-m-d H:i:s', (int)$gs['v']);
        }

        if ($last_update_general !== '') $last_update_text = date('d M Y H:i', strtotime($last_update_general));

        if ($selected_klinik === 'all' || $kode_klinik !== '') {
            if ($is_history_date) {
                $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
                $filter_bp_hc = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
                $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.created_at <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
                $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.created_at <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
                $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at <= '" . $conn->real_escape_string($filter_end_ts) . "'";
                $filter_ts_hc = $filter_ts_klinik;
            } else {
                if ($last_update_general !== '') {
                    $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($today_date) . "'";
                    $sync_buffer_ts = date('Y-m-d H:i:s', strtotime($last_update_general));
                    $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.created_at > '" . $conn->real_escape_string($sync_buffer_ts) . "'";
                    $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at > '" . $conn->real_escape_string($sync_buffer_ts) . "'";
                    $filter_bp_hc = $filter_bp_onsite;
                    $filter_pb_hc = $filter_pb_klinik;
                    $filter_ts_hc = $filter_ts_klinik;
                } else {
                    $filter_bp_onsite = $filter_pb_klinik = $filter_ts_klinik = $filter_bp_hc = $filter_pb_hc = $filter_ts_hc = " AND 1=0";
                }
            }

            $filter_pb2_klinik = str_replace("pb.", "pb2.", $filter_pb_klinik);
            $filter_pb2_hc = str_replace("pb.", "pb2.", $filter_pb_hc);
            $hc_user_filter_sql = "EXISTS (SELECT 1 FROM inventory_users u_hc WHERE u_hc.id = ts.level_id AND u_hc.klinik_id $klinik_filter_sql)";

            // --- OPTIMASI START ---
            // 1. Pre-fetch Daily Usage Data (Eager Loading)
            $usage_map = [];
            if (!$is_history_date && $selected_klinik !== 'gudang_utama' && $selected_klinik !== '') {
                $res_rates = $conn->query("SELECT barang_id, mode, manual_value, last_calculated_rate FROM inventory_daily_usage_config WHERE klinik_id $klinik_filter_sql");
                $usage_rate_map = [];
                while ($rr = $res_rates->fetch_assoc()) {
                    $rate = ($rr['mode'] === 'manual') ? (float)$rr['manual_value'] : (float)$rr['last_calculated_rate'];
                    $usage_rate_map[(int)$rr['barang_id']] = round($rate, 0);
                }
                
                $res_last_all = $conn->query("SELECT pbd.barang_id, MAX(pb.tanggal) as last_date 
                                            FROM inventory_pemakaian_bhp pb 
                                            JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
                                            WHERE pb.klinik_id $klinik_filter_sql AND pb.status = 'active'
                                            AND (pb.is_auto = 0 OR pb.is_auto IS NULL)
                                            GROUP BY pbd.barang_id");
                $last_dates_map = [];
                while ($rl = $res_last_all->fetch_assoc()) $last_dates_map[(int)$rl['barang_id']] = $rl['last_date'];

                $today = date('Y-m-d');
                foreach ($usage_rate_map as $bid => $rate) {
                    if ($rate <= 0) continue;
                    $last_date = $last_dates_map[$bid] ?? date('Y-m-01', strtotime('-1 day'));
                    $accumulated = 0;
                    $current = date('Y-m-d', strtotime($last_date . ' +1 day'));
                    while ($current <= $today) {
                        if (is_operational_day($selected_klinik, $current)) $accumulated += $rate;
                        $current = date('Y-m-d', strtotime($current . ' +1 day'));
                    }
                    $usage_map[$bid] = (float)round($accumulated, 0);
                }
            }

            // 2. Aggregate Joins (Phase 2)
            $res_agg_onsite = $conn->query("SELECT bd.barang_id, SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END) as total 
                FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id 
                WHERE bp.klinik_id $klinik_filter_sql AND bp.status = 'booked' AND bp.status_booking LIKE '%Clinic%'$filter_bp_onsite GROUP BY bd.barang_id");
            $agg_reserve_onsite = []; while($r = $res_agg_onsite->fetch_assoc()) $agg_reserve_onsite[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_hc = $conn->query("SELECT bd.barang_id, SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END) as total 
                FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id 
                WHERE bp.klinik_id $klinik_filter_sql AND bp.status = 'booked' AND bp.status_booking LIKE '%HC%'$filter_bp_hc GROUP BY bd.barang_id");
            $agg_reserve_hc = []; while($r = $res_agg_hc->fetch_assoc()) $agg_reserve_hc[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_sell_k = $conn->query("SELECT ts.barang_id, SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) as total 
                FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id 
                WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.level = 'klinik' AND ts.level_id $klinik_filter_sql AND pb2.klinik_id $klinik_filter_sql AND pb2.jenis_pemakaian != 'hc'$filter_pb2_klinik AND pb2.status = 'active' GROUP BY ts.barang_id");
            $agg_sellout_k = []; while($r = $res_agg_sell_k->fetch_assoc()) $agg_sellout_k[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_sell_h = $conn->query("SELECT ts.barang_id, SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) as total 
                FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id 
                WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.level = 'hc' AND $hc_user_filter_sql AND pb2.klinik_id $klinik_filter_sql AND pb2.jenis_pemakaian = 'hc'$filter_pb2_hc AND pb2.status = 'active' GROUP BY ts.barang_id");
            $agg_sellout_h = []; while($r = $res_agg_sell_h->fetch_assoc()) $agg_sellout_h[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_trf_k_in = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ") AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik GROUP BY ts.barang_id");
            $agg_trf_k_in = []; while($r = $res_agg_trf_k_in->fetch_assoc()) $agg_trf_k_in[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_trf_k_out = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ") AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik GROUP BY ts.barang_id");
            $agg_trf_k_out = []; while($r = $res_agg_trf_k_out->fetch_assoc()) $agg_trf_k_out[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_trf_hc_in = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc GROUP BY ts.barang_id");
            $agg_trf_hc_in = []; while($r = $res_agg_trf_hc_in->fetch_assoc()) $agg_trf_hc_in[(int)$r['barang_id']] = (float)$r['total'];

            $res_agg_trf_hc_out = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc GROUP BY ts.barang_id");
            $agg_trf_hc_out = []; while($r = $res_agg_trf_hc_out->fetch_assoc()) $agg_trf_hc_out[(int)$r['barang_id']] = (float)$r['total'];

            $agg_rb = ['in'=>[], 'out'=>[], 'in_hc'=>[], 'out_hc'=>[], 'sell_k'=>[], 'sell_h'=>[]];
            if ($is_history_date) {
                $res_rb_in = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE $rb_level_filter AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_in->fetch_assoc()) $agg_rb['in'][(int)$r['barang_id']] = (float)$r['total'];
                $res_rb_out = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE $rb_level_filter AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_out->fetch_assoc()) $agg_rb['out'][(int)$r['barang_id']] = (float)$r['total'];
                $res_rb_sell_k = $conn->query("SELECT ts.barang_id, SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) as total FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb ON pb.id = ts.referensi_id WHERE ts.referensi_tipe = 'pemakaian_bhp' AND $rb_pb_filter AND TRIM(pb.jenis_pemakaian) != 'hc' AND pb.status = 'active' AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_sell_k->fetch_assoc()) $agg_rb['sell_k'][(int)$r['barang_id']] = (float)$r['total'];
                $res_rb_in_hc = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_in_hc->fetch_assoc()) $agg_rb['in_hc'][(int)$r['barang_id']] = (float)$r['total'];
                $res_rb_out_hc = $conn->query("SELECT ts.barang_id, SUM(ts.qty) as total FROM inventory_transaksi_stok ts WHERE ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_out_hc->fetch_assoc()) $agg_rb['out_hc'][(int)$r['barang_id']] = (float)$r['total'];
                $res_rb_sell_h = $conn->query("SELECT ts.barang_id, SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END) as total FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb ON pb.id = ts.referensi_id WHERE ts.referensi_tipe = 'pemakaian_bhp' AND pb.klinik_id $klinik_filter_sql AND TRIM(pb.jenis_pemakaian) = 'hc' AND pb.status = 'active' AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min' GROUP BY ts.barang_id");
                while($r = $res_rb_sell_h->fetch_assoc()) $agg_rb['sell_h'][(int)$r['barang_id']] = (float)$r['total'];
            }
            // --- OPTIMASI END ---

            $rb_in_transfer_sql = "0";

            $union_sql = "SELECT odoo_product_id, kode_barang FROM inventory_stock_mirror WHERE TRIM(location_code) $loc_filter_sql";
            if ($show_hc && ($selected_klinik === 'all' ? $hc_codes_str !== "''" : $kode_homecare !== '')) {
                $union_sql .= " UNION SELECT odoo_product_id, kode_barang FROM inventory_stock_mirror WHERE TRIM(location_code) $hc_loc_filter_sql";
            }

            $query = "SELECT 
                        $selected_klinik_id_sql as klinik_id,
                        '$kode_klinik_esc' as kode_klinik,
                        '$kode_homecare_esc' as kode_homecare,
                        p.odoo_product_id,
                        p.kode_barang,
                        b.id as barang_id,
                        b.kode_barang as kode_barang_master,
                        COALESCE(b.nama_barang, p.kode_barang) as nama_barang,
                        COALESCE(uc.to_uom, b.satuan) as satuan,
                        COALESCE(uc.multiplier, 1) as uom_multiplier,
                        COALESCE(sm_k.qty, 0) / NULLIF(COALESCE(uc.multiplier, 1), 0) as qty,
                        COALESCE(sm_h.qty, 0) / NULLIF(COALESCE(uc.multiplier, 1), 0) as stok_hc,
                        0 as reserve_onsite,
                        0 as reserve_hc,
                        0 as sellout_klinik,
                        0 as sellout_hc,
                        0 as in_transfer,
                        0 as out_transfer,
                        0 as in_transfer_hc,
                        0 as out_transfer_hc,
                        0 as rb_in_transfer,
                        0 as rb_out_transfer,
                        0 as rb_in_transfer_hc,
                        0 as rb_out_transfer_hc,
                        0 as rb_sellout_klinik,
                        0 as rb_sellout_hc
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
                      WHERE 1=1 ORDER BY kode_barang_master ASC, nama_barang ASC";

            try {
                $conn->query("SET SQL_BIG_SELECTS=1");
                $result = $conn->query($query);
                while ($r = $result->fetch_assoc()) {
                    $bid = (int)$r['barang_id'];
                    // Inject optimized aggregate values
                    $r['reserve_onsite'] = $agg_reserve_onsite[$bid] ?? 0;
                    $r['reserve_hc'] = $agg_reserve_hc[$bid] ?? 0;
                    $r['sellout_klinik'] = $agg_sellout_k[$bid] ?? 0;
                    $r['sellout_hc'] = $agg_sellout_h[$bid] ?? 0;
                    $r['in_transfer'] = $agg_trf_k_in[$bid] ?? 0;
                    $r['out_transfer'] = $agg_trf_k_out[$bid] ?? 0;
                    $r['in_transfer_hc'] = $agg_trf_hc_in[$bid] ?? 0;
                    $r['out_transfer_hc'] = $agg_trf_hc_out[$bid] ?? 0;
                    
                    if ($is_history_date) {
                        $r['rb_in_transfer'] = $agg_rb['in'][$bid] ?? 0;
                        $r['rb_out_transfer'] = $agg_rb['out'][$bid] ?? 0;
                        $r['rb_in_transfer_hc'] = $agg_rb['in_hc'][$bid] ?? 0;
                        $r['rb_out_transfer_hc'] = $agg_rb['out_hc'][$bid] ?? 0;
                        $r['rb_sellout_klinik'] = $agg_rb['sell_k'][$bid] ?? 0;
                        $r['rb_sellout_hc'] = $agg_rb['sell_h'][$bid] ?? 0;
                    }

                    if ($selected_klinik !== 'gudang_utama') {
                        $acc_daily_usage = $is_history_date ? 0 : calculate_accumulated_usage($selected_klinik, (int)$r['barang_id']);
                        $r['acc_daily_usage'] = $acc_daily_usage;
                    }

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
                    if ($selected_klinik !== 'gudang_utama') {
                        $summary_stok['total_daily_usage'] += $acc_daily_usage;
                    }
                }
            } catch (Exception $e) { die("Query Error: " . $e->getMessage()); }
        }
    }
}

// --- LOGIC FOR TAB: REKAP ---
if ($active_tab === 'rekap') {
    $selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    $selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    
    $first_day = "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . "-01";
    $last_day = date('Y-m-t', strtotime($first_day));
    $total_days = (int)date('t', strtotime($first_day));
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    
    if ($selected_year < $current_year || ($selected_year == $current_year && $selected_month < $current_month)) $days_passed = $total_days;
    elseif ($selected_year == $current_year && $selected_month == $current_month) $days_passed = (int)date('j');
    else $days_passed = 0;
    
    $time_gone_percent = round(($days_passed / $total_days) * 100, 1);
    $mtd_date = date('d M', strtotime("$selected_year-$selected_month-" . ($days_passed ?: 1)));

    $where_pb = "pb.status = 'active' AND pb.tanggal BETWEEN '$first_day' AND '$last_day'";
    $where_bp_all_status = "bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";
    
    if ($selected_klinik && $selected_klinik !== 'all') {
        $kid = (int)$selected_klinik;
        $where_pb .= " AND pb.klinik_id = $kid";
        $where_bp_all_status .= " AND bp.klinik_id = $kid";
    }

    $sellout_query = "SELECT pbd.barang_id, SUM(CASE WHEN pb.jenis_pemakaian = 'klinik' THEN pbd.qty ELSE 0 END) as onsite, SUM(CASE WHEN pb.jenis_pemakaian = 'hc' THEN pbd.qty ELSE 0 END) as hc FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE $where_pb GROUP BY pbd.barang_id";
    $res_sellout = $conn->query($sellout_query);
    $sellout_data = [];
    while ($row = $res_sellout->fetch_assoc()) $sellout_data[$row['barang_id']] = $row;

    // Reserve Sold Data (Berdasarkan Pasien yang sudah DONE)
    $reserve_sold_query = "SELECT pgd.barang_id, SUM(CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN pgd.qty_per_pemeriksaan ELSE 0 END) as onsite, SUM(CASE WHEN bp.status_booking LIKE '%HC%' THEN pgd.qty_per_pemeriksaan ELSE 0 END) as hc FROM inventory_booking_pasien p JOIN inventory_booking_pemeriksaan bp ON p.booking_id = bp.id JOIN inventory_pemeriksaan_grup_detail pgd ON p.pemeriksaan_grup_id = pgd.pemeriksaan_grup_id WHERE $where_bp_all_status AND p.status = 'done' GROUP BY pgd.barang_id";
    $res_reserve_sold = $conn->query($reserve_sold_query);
    $reserve_sold_data = [];
    while ($row = $res_reserve_sold->fetch_assoc()) $reserve_sold_data[$row['barang_id']] = $row;

    // Reserve Booked Data (Total Rencana Awal - Semua status)
    $reserve_booked_query = "SELECT pgd.barang_id, SUM(CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN pgd.qty_per_pemeriksaan ELSE 0 END) as onsite, SUM(CASE WHEN bp.status_booking LIKE '%HC%' THEN pgd.qty_per_pemeriksaan ELSE 0 END) as hc FROM inventory_booking_pasien p JOIN inventory_booking_pemeriksaan bp ON p.booking_id = bp.id JOIN inventory_pemeriksaan_grup_detail pgd ON p.pemeriksaan_grup_id = pgd.pemeriksaan_grup_id WHERE $where_bp_all_status AND bp.status != 'rejected' GROUP BY pgd.barang_id";
    $res_reserve_booked = $conn->query($reserve_booked_query);
    $reserve_booked_data = [];
    while ($row = $res_reserve_booked->fetch_assoc()) $reserve_booked_data[$row['barang_id']] = $row;

    $all_barang_ids = array_unique(array_merge(array_keys($sellout_data), array_keys($reserve_sold_data), array_keys($reserve_booked_data)));
    $final_data = [];
    $summary_rekap = ['sellout_onsite' => 0, 'sellout_hc' => 0, 'reserve_onsite' => 0, 'reserve_hc' => 0, 'reserve_booked_onsite' => 0, 'reserve_booked_hc' => 0];
    
    if (!empty($all_barang_ids)) {
        $ids_str = implode(',', $all_barang_ids);
        $res_b = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM inventory_barang WHERE id IN ($ids_str) ORDER BY nama_barang");
        while ($b = $res_b->fetch_assoc()) {
            $bid = $b['id'];
            $s_onsite = (float)($sellout_data[$bid]['onsite'] ?? 0);
            $s_hc = (float)($sellout_data[$bid]['hc'] ?? 0);
            $rs_onsite = (float)($reserve_sold_data[$bid]['onsite'] ?? 0);
            $rs_hc = (float)($reserve_sold_data[$bid]['hc'] ?? 0);
            
            // Penguncian: Pastikan Sellout minimal sama dengan Reserve-Sold agar tidak minus
            if ($s_onsite < $rs_onsite) $s_onsite = $rs_onsite;
            if ($s_hc < $rs_hc) $s_hc = $rs_hc;

            $s_total = $s_onsite + $s_hc;
            $rb_onsite = (float)($reserve_booked_data[$bid]['onsite'] ?? 0);
            $rb_hc = (float)($reserve_booked_data[$bid]['hc'] ?? 0);
            $nr_onsite = $s_onsite - $rs_onsite;
            $nr_hc = $s_hc - $rs_hc;

            $final_data[] = [
                'kode_barang' => $b['kode_barang'], 'nama_barang' => $b['nama_barang'], 'satuan' => $b['satuan'],
                'sellout_total' => $s_total, 'sellout_onsite' => $s_onsite, 'sellout_hc' => $s_hc,
                'non_reserve_onsite' => $nr_onsite, 'non_reserve_hc' => $nr_hc,
                'reserve_sold_onsite' => $rs_onsite, 'reserve_sold_hc' => $rs_hc,
                'reserve_booked_onsite' => $rb_onsite, 'reserve_booked_hc' => $rb_hc
            ];
            $summary_rekap['sellout_onsite'] += $s_onsite; $summary_rekap['sellout_hc'] += $s_hc;
            $summary_rekap['reserve_onsite'] += $rs_onsite; $summary_rekap['reserve_hc'] += $rs_hc;
            $summary_rekap['reserve_booked_onsite'] += $rb_onsite; $summary_rekap['reserve_booked_hc'] += $rb_hc;
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #204EAB; --onsite-color: #1e293b; --hc-color: #1e293b; --reserve-color: #0891b2; --bg-light: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .text-primary-custom { color: #204EAB; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border-radius: 12px; }
        .last-update { font-size: 0.875rem; color: #6c757d; }
        .card-summary { border: 1px solid #eef0f2; transition: all 0.2s; }
        .card-summary:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .summary-label { font-size: 0.65rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-value { font-size: 1.5rem; font-weight: 700; color: #333; }
        .summary-icon { opacity: 0.4; font-size: 1.25rem; }
        .table thead th { background-color: #204EAB; color: white; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border: 1px solid rgba(255,255,255,0.1); vertical-align: middle; white-space: nowrap; }
        .table td { vertical-align: middle; border: 1px solid #f0f0f0; }
        .table-bordered { border: 1px solid #f0f0f0 !important; }
        
        /* Nav Tabs Custom */
        .nav-tabs { border-bottom: 2px solid #e2e8f0; }
        .nav-link { font-weight: 600; color: #64748b; border: none !important; padding: 1rem 1.5rem; }
        .nav-link.active { color: #204EAB !important; border-bottom: 2px solid #204EAB !important; background: transparent !important; }
        .text-reserve-onsite { color: #0891b2 !important; }
        .text-reserve-hc { color: #0891b2 !important; }
        
        /* Recap Specific Styles */
        .stat-card { border: none; border-radius: 16px; padding: 1.25rem; background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); transition: transform 0.2s; position: relative; overflow: hidden; height: 100%; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary-color); opacity: 0.5; }
        .stat-card .stat-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 1.75rem; font-weight: 800; color: #1e293b; }
        .stat-info-badge { background: #ffffff; padding: 0.5rem 1rem; border-radius: 12px; display: inline-flex; align-items: center; gap: 0.75rem; border: 1px solid #e2e8f0; }
        .stat-info-label { font-size: 0.7rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
        .stat-info-value { font-size: 1rem; font-weight: 800; color: #1e293b; }
        .table-recap thead th { border: 1px solid rgba(255,255,255,0.2); }
        .bg-reference { background-color: #e0f2fe !important; color: #0369a1 !important; }
        .bg-reference-onsite { background-color: #e0f2fe !important; color: #0369a1 !important; }
        .bg-reference-hc { background-color: #dbeafe !important; color: #1e40af !important; }
        .bg-onsite { background-color: #f0fdf4 !important; color: #166534 !important; }
        .bg-hc { background-color: #fff7ed !important; color: #9a3412 !important; }
        .bg-total { background-color: #f8fafc !important; color: #1e293b !important; }
        .val-zero { color: #94a3b8; font-weight: 400; }
        .val-nonzero { font-weight: 700; color: #1e293b; }

        /* Rounded Pagination */
        .dataTables_wrapper .pagination { gap: 6px; padding-top: 15px; justify-content: flex-end; }
        .dataTables_wrapper .pagination .page-item .page-link { 
            border-radius: 50% !important; 
            width: 36px; 
            height: 36px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border: 1px solid #e2e8f0; 
            color: #64748b; 
            font-size: 0.85rem; 
            font-weight: 500; 
            margin: 0 2px;
            padding: 0;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .dataTables_wrapper .pagination .page-item.active .page-link { 
            background-color: #eff6ff !important; 
            color: #204EAB !important; 
            border-color: #dbeafe !important; 
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(32, 78, 171, 0.12);
        }
        .dataTables_wrapper .pagination .page-item:hover:not(.active):not(.disabled) .page-link {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
            transform: translateY(-1px);
        }
        .dataTables_wrapper .pagination .page-item.disabled .page-link {
            opacity: 0.35;
            background: #f8fafc;
            border-color: #f1f5f9;
        }
        .dataTables_info { font-size: 0.8rem; color: #94a3b8; padding-top: 15px; }
        
        /* Form Switch Custom */
        .form-check-input:checked { background-color: #204EAB; border-color: #204EAB; }
    </style>
</head>
<body>

<div class="container-fluid py-3 px-4">
    <div class="row mb-3 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold text-primary-custom"><i class="fas fa-hospital me-2"></i>Inventory Dashboard</h1>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item active">Public Access</li></ol></nav>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'stok' ? 'active' : '' ?>" href="?page=stok_klinik_publik&token=<?= $token ?>&tab=stok&klinik_id=<?= $selected_klinik ?>">
                <i class="fas fa-cubes me-2"></i>Monitor Stok
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'rekap' ? 'active' : '' ?>" href="?page=stok_klinik_publik&token=<?= $token ?>&tab=rekap&klinik_id=<?= $selected_klinik ?>">
                <i class="fas fa-chart-line me-2"></i>Rekapitulasi Pemakaian Bulanan
            </a>
        </li>
    </ul>

    <!-- --- TAB CONTENT: STOK --- -->
    <?php if ($active_tab === 'stok'): ?>
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="page" value="stok_klinik_publik">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                        
                        <div class="d-flex align-items-end gap-3">
                            <div style="flex: 1;">
                                <label class="form-label fw-bold small mb-1"><i class="fas fa-hospital text-primary me-1"></i>Klinik</label>
                                <select name="klinik_id" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                                    <?php if (!empty($gudang_utama_loc)): ?>
                                        <option value="gudang_utama" <?= $selected_klinik === 'gudang_utama' ? 'selected' : '' ?>>Gudang Utama</option>
                                    <?php endif; ?>
                                    <?php foreach ($kliniks as $k): ?>
                                        <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= $k['nama_klinik'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if ($selected_klinik === 'all' && !empty($gudang_utama_loc)): ?>
                                <div class="pb-1">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" name="include_gudang" value="1" id="includeGudang" <?= $include_gudang ? 'checked' : '' ?> onchange="this.form.submit()">
                                        <label class="form-check-label small fw-bold" for="includeGudang">Include Gudang Utama</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small mb-1"><i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal</label>
                    <form method="GET">
                        <input type="hidden" name="page" value="stok_klinik_publik">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="tab" value="stok">
                        <input type="hidden" name="klinik_id" value="<?= htmlspecialchars($selected_klinik) ?>">
                        <?php if ($include_gudang): ?><input type="hidden" name="include_gudang" value="1"><?php endif; ?>
                        <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="col-md-7 text-end"><div class="last-update mb-2">Terakhir update: <?= htmlspecialchars($last_update_text ?? '-') ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($is_history_date): ?>
        <div class="alert alert-warning mb-4 d-flex align-items-start gap-2">
            <i class="fas fa-history mt-1"></i>
            <div><div class="fw-semibold">Mode Rekonstruksi Stok</div><div class="small">Menampilkan estimasi stok per tanggal <?= htmlspecialchars(date('d M Y', strtotime($filter_date))) ?>.</div></div>
        </div>
    <?php endif; ?>

    <div class="row mb-4 g-2">
        <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Jenis Barang</div><div class="summary-value"><?= $summary_stok['total_items'] ?></div></div><i class="fas fa-boxes summary-icon text-primary-custom"></i></div></div></div>
        <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Stock Clinic</div><div class="summary-value"><?= fmt_qty($summary_stok['total_qty']) ?></div></div><i class="fas fa-cubes summary-icon text-primary-custom"></i></div></div></div>
        <?php if ($show_hc): ?><div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Stock HC</div><div class="summary-value"><?= fmt_qty($summary_stok['total_qty_hc']) ?></div></div><i class="fas fa-user-nurse summary-icon text-primary-custom"></i></div></div></div><?php endif; ?>
        <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Sellout Clinic</div><div class="summary-value"><?= fmt_qty($summary_stok['total_sellout_klinik']) ?></div></div><i class="fas fa-history summary-icon text-primary-custom"></i></div></div></div>
        <?php if ($show_hc): ?>
            <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Sellout HC</div><div class="summary-value"><?= fmt_qty($summary_stok['total_sellout_hc']) ?></div></div><i class="fas fa-user-nurse summary-icon text-primary-custom"></i></div></div></div>
        <?php endif; ?>
        <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Reserve Clinic</div><div class="summary-value"><?= fmt_qty($summary_stok['reserve_onsite']) ?></div></div><i class="fas fa-hospital summary-icon text-primary-custom"></i></div></div></div>
        <?php if ($show_hc): ?>
            <div class="col-md"><div class="card card-summary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label">Reserve HC</div><div class="summary-value"><?= fmt_qty($summary_stok['reserve_hc']) ?></div></div><i class="fas fa-user-nurse summary-icon text-primary-custom"></i></div></div></div>
        <?php endif; ?>
        <div class="col-md"><div class="card card-summary h-100" style="background-color: #f1f5f9;"><div class="card-body p-3 d-flex justify-content-between align-items-center"><div><div class="summary-label" style="color: #64748b;">Est. Usage</div><div class="summary-value" style="color: #334155; font-weight: 700;"><?= fmt_qty($summary_stok['total_daily_usage']) ?></div></div><i class="fas fa-clock summary-icon" style="color: #94a3b8; opacity: 0.5;"></i></div></div></div>
    </div>

    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover table-bordered mb-0 datatable-stok">
            <thead><tr>
                <th class="text-center">Kode Barang</th><th class="text-center">Nama Barang</th><th class="text-center">Satuan</th><th class="text-center">Stock Clinic</th>
                <?php if ($show_hc): ?><th class="text-center">Stock HC</th><?php endif; ?>
                <th class="text-center">Sellout Clinic</th><?php if ($show_hc): ?><th class="text-center">Sellout HC</th><?php endif; ?>
                <th class="text-center">Reserve Clinic</th><?php if ($show_hc): ?><th class="text-center">Reserve HC</th><?php endif; ?>
                <th class="text-center" style="font-size:0.55em;">Est. Usage</th>
                <th class="text-center">On Hand Stok</th><th class="text-center">Available Stok</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $s_on = (float)($r['qty'] ?? 0); $s_hc = (float)($r['stok_hc'] ?? 0);
                    if ($is_history_date) {
                        $s_on += (float)$r['rb_out_transfer'] - (float)$r['rb_in_transfer'] + (float)$r['rb_sellout_klinik'];
                        $s_hc += (float)$r['rb_out_transfer_hc'] - (float)$r['rb_in_transfer_hc'] + (float)$r['rb_sellout_hc'];
                    } else {
                        $s_on += (float)$r['in_transfer'] - (float)$r['out_transfer'];
                        $s_hc += (float)$r['in_transfer_hc'] - (float)$r['out_transfer_hc'];
                    }
                    $sell = (float)$r['sellout_klinik']; $sell_hc = (float)$r['sellout_hc'];
                    $res = (float)$r['reserve_onsite']; $res_hc = (float)$r['reserve_hc'];
                    
                    $acc_daily_usage = (float)($r['acc_daily_usage'] ?? 0);
                    $total_stok = $s_on + ($show_hc ? $s_hc : 0);
                    $total_sellout = $sell + ($show_hc ? $sell_hc : 0);
                    $on_h = $total_stok - $total_sellout;
                    $avail = $on_h - ($res + ($show_hc ? $res_hc : 0)) - $acc_daily_usage;
                ?>
                <tr>
                    <td class="small text-muted text-center"><?= htmlspecialchars($r['kode_barang_master'] ?: $r['kode_barang']) ?></td>
                    <td class="small fw-medium"><?= htmlspecialchars($r['nama_barang']) ?></td>
                    <td class="small text-muted text-center">
                        <div><?= htmlspecialchars($r['satuan']) ?></div>
                        <?php if (!empty($r['uom_odoo']) && (float)($r['uom_multiplier'] ?? 1) != 1.0): ?>
                            <div class="text-muted" style="font-size: 0.6rem; line-height: 1.1;">1 <?= htmlspecialchars($r['satuan']) ?> = <?= htmlspecialchars(fmt_qty($r['uom_multiplier'])) ?> <?= htmlspecialchars($r['uom_odoo']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold text-center">
                        <?php $on_class = $s_on < 0 ? 'text-danger fw-bold' : 'fw-bold'; ?>
                        <div class="<?= $on_class ?>"><?= fmt_qty($s_on) ?></div>
                        <?php if (!$is_history_date && ((float)($r['in_transfer'] ?? 0) > 0 || (float)($r['out_transfer'] ?? 0) > 0)): ?>
                            <div class="d-flex align-items-center justify-content-center gap-2 small mt-1">
                                <div class="d-flex align-items-center gap-1">
                                    <i class="fas fa-arrow-down text-success" style="font-size: 0.7rem;"></i>
                                    <span class="fw-semibold" style="font-size: 0.75rem;"><?= fmt_qty($r['in_transfer'] ?? 0) ?></span>
                                </div>
                                <div class="text-muted" style="font-size: 0.7rem; opacity: 0.3;">|</div>
                                <div class="d-flex align-items-center gap-1">
                                    <i class="fas fa-arrow-up text-danger" style="font-size: 0.7rem;"></i>
                                    <span class="fw-semibold" style="font-size: 0.75rem;"><?= fmt_qty($r['out_transfer'] ?? 0) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php if ($show_hc): ?>
                        <td class="text-center">
                            <?php if($s_hc != 0 || (float)($r['in_transfer_hc'] ?? 0) > 0 || (float)($r['out_transfer_hc'] ?? 0) > 0): ?>
                                <a href="#" class="fw-bold text-dark text-decoration-none" onclick="loadHCDetail(<?= $r['barang_id'] ?>, <?= $selected_klinik === 'all' ? 0 : $selected_klinik ?>, '<?= addslashes($r['nama_barang']) ?>'); return false;">
                                    <?= fmt_qty($s_hc) ?>
                                </a>
                                <?php if ((float)($r['in_transfer_hc'] ?? 0) > 0 || (float)($r['out_transfer_hc'] ?? 0) > 0): ?>
                                    <div class="d-flex align-items-center justify-content-center gap-1 small mt-1" style="font-size: 0.65rem;">
                                        <?php if ((float)($r['in_transfer_hc'] ?? 0) > 0): ?>
                                            <span class="text-success fw-bold"><i class="fas fa-arrow-down"></i> <?= fmt_qty($r['in_transfer_hc']) ?></span>
                                        <?php endif; ?>
                                        <?php if ((float)($r['in_transfer_hc'] ?? 0) > 0 && (float)($r['out_transfer_hc'] ?? 0) > 0): ?>
                                            <span class="text-muted">|</span>
                                        <?php endif; ?>
                                        <?php if ((float)($r['out_transfer_hc'] ?? 0) > 0): ?>
                                            <span class="text-danger fw-bold"><i class="fas fa-arrow-up"></i> <?= fmt_qty($r['out_transfer_hc']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="text-center <?= $sell > 0 ? 'text-danger fw-bold' : ($sell < 0 ? 'text-primary fw-bold' : 'text-muted small') ?>"><?= fmt_qty($sell) ?></td>
                    <?php if ($show_hc): ?><td class="text-center <?= $sell_hc > 0 ? 'text-danger fw-bold' : ($sell_hc < 0 ? 'text-primary fw-bold' : 'text-muted small') ?>"><?= fmt_qty($sell_hc) ?></td><?php endif; ?>
                    <td class="text-center <?= $res > 0 ? 'text-reserve-onsite fw-bold' : 'text-muted small' ?>"><?= fmt_qty($res) ?></td>
                    <?php if ($show_hc): ?><td class="text-center <?= $res_hc > 0 ? 'text-reserve-hc fw-bold' : 'text-muted small' ?>"><?= fmt_qty($res_hc) ?></td><?php endif; ?>
                    <td class="text-center" style="background-color: #f1f5f9;">
                        <div style="color: #334155;" class="<?= $acc_daily_usage > 0 ? 'fw-bold' : '' ?>"><?= fmt_qty($acc_daily_usage) ?></div>
                    </td>
                    <td class="text-center <?= $on_h < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>" style="cursor: pointer;" onclick="openStokBreakdown(<?= (int)$r['barang_id'] ?>, '<?= addslashes($r['nama_barang']) ?>', 'onhand', <?= (float)$acc_daily_usage ?>, <?= (float)$on_h ?>)"><?= fmt_qty($on_h) ?></td>
                    <td class="text-center <?= $avail < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>" style="cursor: pointer;" onclick="openStokBreakdown(<?= (int)$r['barang_id'] ?>, '<?= addslashes($r['nama_barang']) ?>', 'available', <?= (float)$acc_daily_usage ?>, <?= (float)$on_h ?>)"><?= fmt_qty($avail) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
    <?php endif; ?>

    <!-- --- TAB CONTENT: REKAP --- -->
    <?php if ($active_tab === 'rekap'): ?>
    <div class="card mb-4"><div class="card-body py-3">
        <form class="row g-3 align-items-end" method="GET">
            <input type="hidden" name="page" value="stok_klinik_publik">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="tab" value="rekap">
            <div class="col-md-4"><label class="form-label small fw-bold mb-1"><i class="fas fa-hospital text-primary me-1"></i>Klinik</label><select name="klinik_id" class="form-select" onchange="this.form.submit()"><option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option><?php foreach ($kliniks as $k): ?><option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= $k['nama_klinik'] ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-1"><i class="fas fa-calendar-alt text-primary me-1"></i>Bulan</label><select name="month" class="form-select" onchange="this.form.submit()"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $selected_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?></select></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-1"><i class="fas fa-calendar text-primary me-1"></i>Tahun</label><select name="year" class="form-select" onchange="this.form.submit()"><?php for ($y = date('Y'); $y >= 2024; $y--): ?><option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
            <div class="col-md-3 text-end">
                <div class="stat-info-badge me-2"><i class="fas fa-hourglass-half text-primary"></i><div><div class="stat-info-label">Time Gone</div><div class="stat-info-value" style="font-size:0.8rem"><?= $time_gone_percent ?>%</div></div></div>
                <div class="stat-info-badge"><i class="fas fa-calendar-check text-primary"></i><div><div class="stat-info-label">MTD</div><div class="stat-info-value" style="font-size:0.8rem"><?= $mtd_date ?></div></div></div>
            </div>
        </form>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Sellout Clinic</div><div class="summary-value"><?= fmt_qty($summary_rekap['sellout_onsite']) ?></div></div><i class="fas fa-hospital summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Sellout HC</div><div class="summary-value"><?= fmt_qty($summary_rekap['sellout_hc']) ?></div></div><i class="fas fa-user-nurse summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Res-Sold Clinic</div><div class="summary-value"><?= fmt_qty($summary_rekap['reserve_onsite']) ?></div></div><i class="fas fa-check-circle summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Res-Sold HC</div><div class="summary-value"><?= fmt_qty($summary_rekap['reserve_hc']) ?></div></div><i class="fas fa-check-double summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary" style="background-color: #f0f9ff;"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Booked Clinic</div><div class="summary-value" style="color:#0369a1"><?= fmt_qty($summary_rekap['reserve_booked_onsite']) ?></div></div><i class="fas fa-calendar-alt summary-icon text-info"></i></div></div></div>
        <div class="col"><div class="card card-summary" style="background-color: #f0f9ff;"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Booked HC</div><div class="summary-value" style="color:#0369a1"><?= fmt_qty($summary_rekap['reserve_booked_hc']) ?></div></div><i class="fas fa-calendar-check summary-icon text-info"></i></div></div></div>
    </div>

    <div class="card border-0 shadow-sm p-3"><div class="d-flex justify-content-between align-items-center mb-3">
        <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold" id="btnExportExcel"><i class="fas fa-file-excel me-1"></i> Export Excel</button>
        <div class="d-flex align-items-center"><label class="small fw-bold text-muted me-2 mb-0">Cari:</label><input type="text" id="customSearch" class="form-control form-control-sm" style="width: 200px;"></div>
    </div><div class="table-responsive"><table class="table table-bordered mb-0" id="tableSummary" style="font-size:0.85rem">
        <thead>
            <tr><th rowspan="2" class="text-center">Kode Barang</th><th rowspan="2">Nama Barang</th><th rowspan="2" class="text-center">Satuan</th><th colspan="3" class="text-center">Sellout</th><th colspan="2" class="text-center">Non-Reserve</th><th colspan="2" class="text-center">Reserve-Sold</th><th colspan="2" class="text-center">Reserve-Booked</th></tr>
            <tr><th class="text-center">Total</th><th class="text-center">Clinic</th><th class="text-center">HC</th><th class="text-center">Clinic</th><th class="text-center">HC</th><th class="text-center">Clinic</th><th class="text-center">HC</th><th class="text-center">Clinic</th><th class="text-center">HC</th></tr>
        </thead>
        <tbody>
            <?php foreach ($final_data as $row): ?>
            <tr>
                <td class="small text-muted text-center"><?= htmlspecialchars($row['kode_barang']) ?></td><td class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_barang']) ?></td><td class="text-center small text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                <td class="text-center bg-total <?= $row['sellout_total'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['sellout_total']) ?></td>
                <td class="text-center bg-onsite <?= $row['sellout_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['sellout_onsite']) ?></td>
                <td class="text-center bg-hc <?= $row['sellout_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['sellout_hc']) ?></td>
                <td class="text-center bg-onsite <?= $row['non_reserve_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['non_reserve_onsite']) ?></td>
                <td class="text-center bg-hc <?= $row['non_reserve_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['non_reserve_hc']) ?></td>
                <td class="text-center bg-onsite <?= $row['reserve_sold_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_sold_onsite']) ?></td>
                <td class="text-center bg-hc <?= $row['reserve_sold_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_sold_hc']) ?></td>
                <td class="text-center bg-reference-onsite <?= $row['reserve_booked_onsite'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_booked_onsite']) ?></td>
                <td class="text-center bg-reference-hc <?= $row['reserve_booked_hc'] > 0 ? 'val-nonzero' : 'val-zero' ?>"><?= fmt_qty($row['reserve_booked_hc']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <?php endif; ?>
</div>

<!-- Modals -->
<div class="modal fade" id="modalHCDetail" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Detail Stok HC</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="hcDetailContent" class="p-3"></div></div></div></div></div>
<div class="modal fade" id="modalStokBreakdown" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Rekonstruksi Stok</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="stokBreakdownBody"></div></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
$(document).ready(function() {
    window.__stokKlinikContext = {
        klinikId: <?= json_encode($selected_klinik === 'all' ? 0 : (int)$selected_klinik) ?>,
        tanggal: <?= json_encode($filter_date ?? date('Y-m-d')) ?>,
        tanggalLabel: <?= json_encode($filter_date_label ?? date('d M Y')) ?>,
        includeGudang: <?= $include_gudang ? 'true' : 'false' ?>,
        token: <?= json_encode($token) ?>
    };

    <?php if ($active_tab === 'stok'): ?>
    $('.datatable-stok').DataTable({ 
        "order": [[ 0, "asc" ]], 
        "pageLength": 10, 
        "lengthChange": false, 
        "language": { "search": "Cari:", "paginate": { "next": '<i class="fas fa-chevron-right"></i>', "previous": '<i class="fas fa-chevron-left"></i>' } } 
    });
    <?php endif; ?>

    <?php if ($active_tab === 'rekap'): ?>
    var table = $('#tableSummary').DataTable({ 
        "dom": 'rtp', 
        "pageLength": 10, 
        "lengthChange": false, 
        "ordering": true,
        "order": [[ 0, "asc" ]],
        "language": { "paginate": { "next": '<i class="fas fa-chevron-right"></i>', "previous": '<i class="fas fa-chevron-left"></i>' } } 
    });
    $('#customSearch').on('keyup', function() { table.search(this.value).draw(); });
    $('#btnExportExcel').on('click', function() {
        const data = <?= json_encode($final_data ?? []) ?>;
        const ws_data = [
            ["Kode", "Nama Barang", "Satuan", "Sellout Total", "Sellout Onsite", "Sellout HC", "Non-Res Onsite", "Non-Res HC", "Res-Sold Onsite", "Res-Sold HC", "Booked Onsite", "Booked HC"]
        ];
        data.forEach(r => ws_data.push([r.kode_barang, r.nama_barang, r.satuan, r.sellout_total, r.sellout_onsite, r.sellout_hc, r.non_reserve_onsite, r.non_reserve_hc, r.reserve_sold_onsite, r.reserve_sold_hc, r.reserve_booked_onsite, r.reserve_booked_hc]));
        const ws = XLSX.utils.aoa_to_sheet(ws_data);
        const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, "Rekap"); XLSX.writeFile(wb, "Rekap_Aktivitas_Bulanan.xlsx");
    });
    <?php endif; ?>
});

function loadHCDetail(bId, kId, name) { $('#hcDetailContent').html('...'); var m = new bootstrap.Modal(document.getElementById('modalHCDetail')); m.show(); $.ajax({ url: 'api/ajax_hc_detail.php', method: 'POST', data: { barang_id: bId, klinik_id: kId, token: '<?= $token ?>' }, success: function(r) { $('#hcDetailContent').html(r); } }); }

function fmtNum(n) {
    if (n === null || n === undefined) return "0";
    var num = parseFloat(n);
    if (isNaN(num)) return "0";
    if (Math.abs(num - Math.round(num)) < 0.00005) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    var s = num.toFixed(4).replace(/0+$/, '').replace(/,$/, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.join(',');
}

function openStokBreakdown(barangId, namaBarang, type = 'onhand', accDailyUsage = 0, stockTotal = 0) {
    var modalEl = document.getElementById('modalStokBreakdown');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    var body = document.getElementById('stokBreakdownBody');
    body.innerHTML = '<div class="text-muted">Memuat...</div>';

    $.ajax({
        url: 'api/ajax_stok_klinik_breakdown.php',
        method: 'POST',
        dataType: 'json',
        data: {
            klinik_id: window.__stokKlinikContext.klinikId,
            barang_id: barangId,
            tanggal: window.__stokKlinikContext.tanggal,
            include_gudang: window.__stokKlinikContext.includeGudang ? 1 : 0,
            token: window.__stokKlinikContext.token
        }
    }).then(function(res) {
        if (!res || !res.success) {
            body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail.</div>';
            return;
        }
        var b = res.barang || {};
        var lastU = (res.last_update && res.last_update.text) ? res.last_update.text : '-';
        var baseOn = (res.baseline && res.baseline.onsite) ? res.baseline.onsite : 0;
        var baseHc = (res.baseline && res.baseline.hc) ? res.baseline.hc : 0;
        var rb = res.rollback || {};
        var rbe = rb.events || {};
        var pem = Array.isArray(rbe.pemakaian) ? rbe.pemakaian : [];
        var trf = Array.isArray(rbe.transfers) ? rbe.transfers : [];
        var periodPem = Array.isArray(res.period_usage) ? res.period_usage : [];
        var reserve = res.reserve || {};
        var reserveOn = reserve.onsite || 0;
        var reserveHc = reserve.hc || 0;
        var result = res.result || {};
        var dailyUsage = accDailyUsage > 0 ? accDailyUsage : parseFloat(res.daily_usage || 0);
        var stockTotalVal = stockTotal > 0 ? stockTotal : (result.stock_total || 0);
        var perKlinik = Array.isArray(res.per_klinik) ? res.per_klinik : [];

        var pemRows = pem.length ? pem.map(function(p) {
            var jenis = (p.jenis_pemakaian === 'hc') ? '<span class="badge bg-info x-small">HC</span>' : '<span class="badge bg-light text-dark x-small border">Klinik</span>';
            var tgl = p.tanggal || '';
            if (p.created_at) {
                var dt = new Date(p.created_at);
                if (!isNaN(dt)) {
                    var pad = function(n) { return n.toString().padStart(2, '0'); };
                    tgl = pad(dt.getDate()) + '/' + pad(dt.getMonth()+1) + '/' + dt.getFullYear() + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
                }
            }
            return '<tr><td class="ps-3">' + $('<div>').text(p.nomor_pemakaian || '').html() + '</td><td>' + $('<div>').text(tgl).html() + '</td><td>' + jenis + '</td><td class="text-end pe-3 fw-semibold">' + fmtNum(p.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada transaksi pemakaian</td></tr>';

        var trfRows = trf.length ? trf.map(function(t) {
            var level = (t.level === 'hc') ? '<span class="badge bg-info x-small">HC</span>' : '<span class="badge bg-light text-dark x-small border">Klinik</span>';
            return '<tr><td class="ps-3 text-muted">#' + (t.transfer_id || '-') + '</td><td>' + (t.tipe_transaksi || '-') + ' ' + level + '</td><td>' + $('<div>').text(t.last_at || '').html() + '</td><td class="text-end pe-3 fw-semibold">' + fmtNum(t.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada transaksi transfer</td></tr>';

        var isHistory = (window.__stokKlinikContext.tanggal !== new Date().toISOString().split('T')[0]);
        var adjLabel = isHistory ? 'Penyesuaian (Rollback):' : 'Sellout:';
        var adjClass = isHistory ? 'text-success' : 'text-danger';

        var titleIcon = type === 'onhand' ? 'fas fa-box' : 'fas fa-calendar-check';
        var titleText = type === 'onhand' ? 'Detail Stok On Hand' : 'Detail Stok Available';
        document.querySelector('#modalStokBreakdown .modal-title').innerHTML = `<i class="${titleIcon} me-2 text-primary-custom"></i>${titleText}`;

        body.innerHTML = `
            <!-- Ultra Compact Header -->
            <div class="border rounded bg-light p-2 mb-2">
                <div class="row g-0 align-items-center">
                    <div class="col-8 border-end pe-2">
                        <div class="x-small text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Barang</div>
                        <div class="fw-bold text-dark text-truncate small" title="${$('<div>').text((b.kode_barang || '-') + ' - ' + (b.nama_barang || namaBarang)).html()}">
                            ${$('<div>').text((b.kode_barang || '-') + ' - ' + (b.nama_barang || namaBarang)).html()}
                        </div>
                    </div>
                    <div class="col-4 ps-2 text-center">
                        ${type === 'onhand' ? `
                            <div class="x-small text-dark text-uppercase fw-bold" style="font-size: 0.65rem;">On Hand Total</div>
                            <div class="h5 mb-0 fw-bold text-success">${fmtNum(stockTotalVal)}</div>
                        ` : `
                            <div class="x-small text-dark text-uppercase fw-bold" style="font-size: 0.65rem;">Available Stok</div>
                            <div class="h5 mb-0 fw-bold text-success">${fmtNum((result.tersedia || 0) - dailyUsage)}</div>
                            ${dailyUsage > 0 ? `<div class="x-small text-muted mt-1">${fmtNum(result.tersedia || 0)} &minus; <span class="fw-semibold">${fmtNum(dailyUsage)}</span> est. usage</div>` : ''}
                        `}
                    </div>
                </div>
            </div>

            ${type === 'onhand' ? `
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <div class="card shadow-none border rounded bg-light-subtle">
                        <div class="card-body p-2">
                            <h6 class="fw-bold mb-2 small border-bottom pb-1"><i class="fas fa-hospital me-1"></i>Stok Clinic</h6>
                            <div class="d-flex justify-content-between mb-1 x-small text-muted">
                                <span>Stok Odoo:</span>
                                <span>${fmtNum(baseOn)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 x-small ${adjClass}">
                                <span>${adjLabel}</span>
                                <span class="fw-bold">
                                    ${(function(){
                                        var val = (rb.out_transfer || 0) - (rb.in_transfer || 0) + (rb.sellout_klinik || 0);
                                        if (isHistory) return (val >= 0 ? '+' : '-') + ' ' + fmtNum(Math.abs(val));
                                        else return (val >= 0 ? '-' : '+') + ' ' + fmtNum(Math.abs(val));
                                    })()}
                                </span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold small text-dark">
                                <span>Total Clinic:</span>
                                <span>${fmtNum(result.stock_onsite || 0)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-none border rounded bg-light-subtle">
                        <div class="card-body p-2">
                            <h6 class="fw-bold mb-2 small border-bottom pb-1"><i class="fas fa-user-nurse me-1"></i>Stock HC</h6>
                            <div class="d-flex justify-content-between mb-1 x-small text-muted">
                                <span>Stok Odoo:</span>
                                <span>${fmtNum(baseHc)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 x-small ${adjClass}">
                                <span>${adjLabel}</span>
                                <span class="fw-bold">
                                    ${(function(){
                                        var val = (rb.out_transfer_hc || 0) - (rb.in_transfer_hc || 0) + (rb.sellout_hc || 0);
                                        if (isHistory) return (val >= 0 ? '+' : '-') + ' ' + fmtNum(Math.abs(val));
                                        else return (val >= 0 ? '-' : '+') + ' ' + fmtNum(Math.abs(val));
                                    })()}
                                </span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold small text-dark">
                                <span>Total HC:</span>
                                <span>${fmtNum(result.stock_hc || 0)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ` : `
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <div class="card shadow-none border rounded bg-light-subtle">
                        <div class="card-body p-2">
                            <h6 class="fw-bold mb-2 small border-bottom pb-1"><i class="fas fa-hospital me-1"></i>Available Clinic</h6>
                            <div class="d-flex justify-content-between mb-1 x-small text-muted">
                                <span>On Hand Clinic:</span>
                                <span>${fmtNum(result.stock_onsite || 0)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 x-small text-primary">
                                <span>Reservasi:</span>
                                <span class="fw-bold">-${fmtNum(reserveOn)}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold small text-dark">
                                <span>Total Available:</span>
                                <span>${fmtNum((result.stock_onsite || 0) - reserveOn)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-none border rounded bg-light-subtle">
                        <div class="card-body p-2">
                            <h6 class="fw-bold mb-2 small border-bottom pb-1"><i class="fas fa-user-nurse me-1"></i>Available HC</h6>
                            <div class="d-flex justify-content-between mb-1 x-small text-muted">
                                <span>On Hand HC:</span>
                                <span>${fmtNum(result.stock_hc || 0)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 x-small text-primary">
                                <span>Reservasi:</span>
                                <span class="fw-bold">-${fmtNum(reserveHc)}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold small text-dark">
                                <span>Total Available:</span>
                                <span>${fmtNum((result.stock_hc || 0) - reserveHc)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `}

            <!-- Per-Klinik Breakdown (only when all) -->
            ${perKlinik.length > 1 ? `
            <div class="accordion mb-2" id="accordionPerKlinik">
                <div class="accordion-item border rounded overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePerKlinik">
                            <i class="fas fa-hospital-alt me-2"></i> Breakdown Per Klinik
                        </button>
                    </h2>
                    <div id="collapsePerKlinik" class="accordion-collapse collapse">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">Klinik</th>
                                            <th class="text-center">${type === 'available' ? 'On Hand Clinic' : 'Stok Clinic'}</th>
                                            <th class="text-center">${type === 'available' ? 'On Hand HC' : 'Stok HC'}</th>
                                            <th class="text-center">${type === 'available' ? 'Reserve Clinic' : 'Sellout Clinic'}</th>
                                            <th class="text-center">${type === 'available' ? 'Reserve HC' : 'Sellout HC'}</th>
                                            <th class="text-center">Est. Usage</th>
                                            <th class="text-center">${type === 'available' ? 'Available' : 'On Hand'}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small">
                                        ${perKlinik.map(function(k) {
                                            var onHand = k.on_hand !== undefined ? k.on_hand : (k.stock_on + k.stock_hc);
                                            var isGudang = k.is_gudang === true;
                                            var rowBg = isGudang ? ' style="background:#f8fafc;"' : '';
                                            return '<tr' + rowBg + '>' +
                                                '<td class="ps-3 fw-medium">' + $('<div>').text(k.nama_klinik).html() + '</td>' +
                                                '<td class="text-center text-dark">' + fmtNum(type === 'available' ? k.stock_on - (k.sellout_on || 0) : k.stock_on) + '</td>' +
                                                '<td class="text-center text-dark">' + ((type === 'available' ? k.stock_hc - (k.sellout_hc || 0) : k.stock_hc) > 0 ? fmtNum(type === 'available' ? k.stock_hc - (k.sellout_hc || 0) : k.stock_hc) : '<span class="text-muted">-</span>') + '</td>' +
                                                (type === 'available'
                                                    ? '<td class="text-center text-primary">' + (k.reserve_on > 0 ? fmtNum(k.reserve_on) : '<span class="text-muted">-</span>') + '</td>' +
                                                      '<td class="text-center text-primary">' + (k.reserve_hc > 0 ? fmtNum(k.reserve_hc) : '<span class="text-muted">-</span>') + '</td>'
                                                    : '<td class="text-center text-danger">' + (k.sellout_on > 0 ? fmtNum(k.sellout_on) : '<span class="text-muted">-</span>') + '</td>' +
                                                      '<td class="text-center text-danger">' + (k.sellout_hc > 0 ? fmtNum(k.sellout_hc) : '<span class="text-muted">-</span>') + '</td>'
                                                ) +
                                                '<td class="text-center text-danger">' + (!isGudang && k.daily_usage > 0 ? fmtNum(k.daily_usage) : '<span class="text-muted">-</span>') + '</td>' +
                                                '<td class="text-center fw-bold text-success">' + fmtNum(type === 'available' ? (k.available !== undefined ? k.available : onHand) : onHand) + '</td>' +
                                                '</tr>';
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}

            ${type === 'onhand' ? `
            <div class="accordion" id="accordionDetailTrans">
                <div class="accordion-item border rounded mb-2 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePemakaian">
                            <i class="fas fa-list-ul me-2"></i> Lihat Daftar Transaksi Pemakaian
                        </button>
                    </h2>
                    <div id="collapsePemakaian" class="accordion-collapse collapse" data-bs-parent="#accordionDetailTrans">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="bg-light"><tr><th class="ps-3">No. Pemakaian</th><th>Tanggal</th><th>Jenis</th><th class="text-end pe-3">Qty</th></tr></thead>
                                    <tbody class="small">${pemRows}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="accordion-item border rounded mb-2 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTransfer">
                            <i class="fas fa-exchange-alt me-2"></i> Lihat Daftar Transaksi Transfer
                        </button>
                    </h2>
                    <div id="collapseTransfer" class="accordion-collapse collapse" data-bs-parent="#accordionDetailTrans">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="bg-light"><tr><th class="ps-3">Referensi</th><th>Tipe</th><th>Waktu</th><th class="text-end pe-3">Qty</th></tr></thead>
                                    <tbody class="small">${trfRows}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            ` : `
            <div class="accordion" id="accordionReserve">
                <div class="accordion-item border rounded mb-2 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseReserve">
                            <i class="fas fa-history me-2"></i> Lihat Daftar Reservasi Booking
                        </button>
                    </h2>
                    <div id="collapseReserve" class="accordion-collapse collapse" data-bs-parent="#accordionReserve">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">No. Booking</th>
                                            <th>Tgl Periksa</th>
                                            <th>Status</th>
                                            <th class="text-end pe-3">Qty</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small">
                                        ${reserve.events && reserve.events.length ? reserve.events.map(function(ev){
                                            return `<tr>
                                                <td class="ps-3">${$('<div>').text(ev.nomor_booking || '-').html()}</td>
                                                <td>${$('<div>').text(ev.tanggal_pemeriksaan || '-').html()}</td>
                                                <td><span class="badge bg-info-subtle text-info border-info-subtle x-small">${$('<div>').text(ev.status_booking || '-').html()}</span></td>
                                                <td class="text-end pe-3 fw-bold">${fmtNum(ev.qty || 0)}</td>
                                            </tr>`;
                                        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada reservasi aktif</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `}

            <div class="mt-2 py-2 border-top text-center">
                <div class="x-small text-muted">
                    ${type === 'onhand' ? `Per ${$('<div>').text(window.__stokKlinikContext.tanggalLabel).html()}` : 'Sudah dikurangi Reservasi'}
                    <span class="mx-2">|</span>
                    Sync Odoo: <span class="fw-bold text-dark">${lastU}</span>
                </div>
            </div>
        `;
    }).catch(function() {
        body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail.</div>';
    });
}
function fmt_qty(v) {
    var n = parseFloat(v || 0);
    if (isNaN(n)) return "0";
    if (Math.abs(n - Math.round(n)) < 0.00005) {
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    var s = n.toFixed(4).replace(/0+$/, '').replace(/,$/, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.join(',');
}
</script>
</body>
</html>