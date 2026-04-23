<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';

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
        'total_sellout_hc' => 0
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
            
            $rb_in_transfer_sql = $rb_out_transfer_sql = $rb_in_transfer_hc_sql = $rb_out_transfer_hc_sql = $rb_sellout_klinik_sql = $rb_sellout_hc_sql = "0";
            $rb_ts_start = $conn->real_escape_string($filter_end_ts);
            $rb_ts_min = $conn->real_escape_string($month_start_ts);
            
            if ($is_history_date) {
                $rb_ts_end = $last_update_general !== '' ? $conn->real_escape_string($last_update_general) : $conn->real_escape_string(date('Y-m-d H:i:s'));
                $rb_level_filter = "((ts.level = 'klinik' AND ts.level_id $klinik_filter_sql)" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (ts.level = 'gudang_utama')" : "") . ")";
                $rb_pb_filter = "(pb.klinik_id $klinik_filter_sql" . (($include_gudang || $selected_klinik === 'gudang_utama') ? " OR (pb.jenis_pemakaian = 'gudang_utama')" : "") . ")"; 

                $rb_in_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND $rb_level_filter AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
                $rb_out_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND $rb_level_filter AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
                $rb_sellout_klinik_sql = "(SELECT COALESCE(SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END), 0) FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb ON pb.id = ts.referensi_id WHERE ts.barang_id = b.id AND ts.referensi_tipe = 'pemakaian_bhp' AND $rb_pb_filter AND TRIM(pb.jenis_pemakaian) != 'hc' AND pb.status = 'active' AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";

                $rb_in_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'in' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
                $rb_out_transfer_hc_sql = "(SELECT COALESCE(SUM(ts.qty), 0) FROM inventory_transaksi_stok ts WHERE ts.barang_id = b.id AND ts.level = 'hc' AND $hc_user_filter_sql AND ts.tipe_transaksi = 'out' AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer') AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
                $rb_sellout_hc_sql = "(SELECT COALESCE(SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END), 0) FROM inventory_transaksi_stok ts JOIN inventory_pemakaian_bhp pb ON pb.id = ts.referensi_id WHERE ts.barang_id = b.id AND ts.referensi_tipe = 'pemakaian_bhp' AND pb.klinik_id $klinik_filter_sql AND TRIM(pb.jenis_pemakaian) = 'hc' AND pb.status = 'active' AND ts.created_at > '$rb_ts_start' AND ts.created_at <= '$rb_ts_end' AND ts.created_at >= '$rb_ts_min')";
            }

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
                        (SELECT COALESCE(SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END), 0)
                             FROM inventory_transaksi_stok ts
                             JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'klinik'
                             AND ts.level_id $klinik_filter_sql
                             AND ts.referensi_tipe = 'pemakaian_bhp'
                             AND pb2.klinik_id $klinik_filter_sql
                             AND pb2.jenis_pemakaian != 'hc'$filter_pb2_klinik
                             AND pb2.status = 'active') as sellout_klinik,
                        (SELECT COALESCE(SUM(CASE WHEN ts.tipe_transaksi = 'out' THEN ts.qty ELSE -ts.qty END), 0)
                             FROM inventory_transaksi_stok ts
                             JOIN inventory_pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'hc'
                             AND $hc_user_filter_sql
                             AND ts.referensi_tipe = 'pemakaian_bhp'
                             AND pb2.klinik_id $klinik_filter_sql
                             AND pb2.jenis_pemakaian = 'hc'$filter_pb2_hc
                             AND pb2.status = 'active') as sellout_hc,
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
                      WHERE 1=1 ORDER BY kode_barang_master ASC, nama_barang ASC";

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
    $where_bp_completed = "bp.status = 'completed' AND bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";
    $where_bp_all_status = "bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day'";
    
    if ($selected_klinik && $selected_klinik !== 'all') {
        $kid = (int)$selected_klinik;
        $where_pb .= " AND pb.klinik_id = $kid";
        $where_bp_completed .= " AND bp.klinik_id = $kid";
        $where_bp_all_status .= " AND bp.klinik_id = $kid";
    }

    $sellout_query = "SELECT pbd.barang_id, SUM(CASE WHEN pb.jenis_pemakaian = 'klinik' THEN pbd.qty ELSE 0 END) as onsite, SUM(CASE WHEN pb.jenis_pemakaian = 'hc' THEN pbd.qty ELSE 0 END) as hc FROM inventory_pemakaian_bhp_detail pbd JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id WHERE $where_pb GROUP BY pbd.barang_id";
    $res_sellout = $conn->query($sellout_query);
    $sellout_data = [];
    while ($row = $res_sellout->fetch_assoc()) $sellout_data[$row['barang_id']] = $row;

    $reserve_sold_query = "SELECT bd.barang_id, SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE (CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as onsite, SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE (CASE WHEN bp.status_booking LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as hc FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id WHERE $where_bp_completed GROUP BY bd.barang_id";
    $res_reserve_sold = $conn->query($reserve_sold_query);
    $reserve_sold_data = [];
    while ($row = $res_reserve_sold->fetch_assoc()) $reserve_sold_data[$row['barang_id']] = $row;

    $reserve_booked_query = "SELECT bd.barang_id, SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE (CASE WHEN bp.status_booking NOT LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as onsite, SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE (CASE WHEN bp.status_booking LIKE '%HC%' THEN bd.qty_gantung ELSE 0 END) END) as hc FROM inventory_booking_detail bd JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id WHERE $where_bp_all_status GROUP BY bd.barang_id";
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
        .summary-label { font-size: 0.7rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-value { font-size: 1.5rem; font-weight: 700; color: #333; }
        .summary-icon { opacity: 0.4; font-size: 1.25rem; }
        .table thead th { background-color: #204EAB; color: white; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border: 1px solid rgba(255,255,255,0.1); vertical-align: middle; white-space: nowrap; }
        .table td { vertical-align: middle; border: 1px solid #f0f0f0; }
        .table-bordered { border: 1px solid #f0f0f0 !important; }
        
        /* Nav Tabs Custom */
        .nav-tabs { border-bottom: 2px solid #e2e8f0; }
        .nav-link { font-weight: 600; color: #64748b; border: none !important; padding: 1rem 1.5rem; }
        .nav-link.active { color: #204EAB !important; border-bottom: 2px solid #204EAB !important; background: transparent !important; }
        
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

        /* Pagination Circular Styling */
        .dataTables_wrapper .pagination .page-item .page-link { width: 32px; height: 32px; border-radius: 50% !important; display: flex; align-items: center; justify-content: center; padding: 0; font-size: 13px; border: 1px solid #e5e7eb; color: #6b7280; background-color: transparent; }
        .dataTables_wrapper .pagination .page-item.active .page-link { background-color: #eff6ff !important; color: #204EAB !important; font-weight: 600; border-color: #dbeafe !important; }
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
            <a class="nav-link <?= $active_tab === 'stok' ? 'active' : '' ?>" href="?token=<?= $token ?>&tab=stok&klinik_id=<?= $selected_klinik ?>">
                <i class="fas fa-cubes me-2"></i>Monitor Stok
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'rekap' ? 'active' : '' ?>" href="?token=<?= $token ?>&tab=rekap&klinik_id=<?= $selected_klinik ?>">
                <i class="fas fa-chart-line me-2"></i>Rekapitulasi Aktivitas Bulanan
            </a>
        </li>
    </ul>

    <!-- --- TAB CONTENT: STOK --- -->
    <?php if ($active_tab === 'stok'): ?>
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1"><i class="fas fa-hospital text-primary me-1"></i>Klinik</label>
                    <form method="GET">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="tab" value="stok">
                        <select name="klinik_id" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                            <?php if (!empty($gudang_utama_loc)): ?>
                                <option value="gudang_utama" <?= $selected_klinik === 'gudang_utama' ? 'selected' : '' ?>>Gudang Utama</option>
                            <?php endif; ?>
                            <?php foreach ($kliniks as $k): ?>
                                <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= $k['nama_klinik'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small mb-1"><i class="fas fa-calendar-alt text-primary me-1"></i>Tanggal</label>
                    <form method="GET">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="tab" value="stok">
                        <input type="hidden" name="klinik_id" value="<?= htmlspecialchars($selected_klinik) ?>">
                        <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="col-md-5 text-end"><div class="last-update mb-2">Terakhir update: <?= htmlspecialchars($last_update_text ?? '-') ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($is_history_date): ?>
        <div class="alert alert-warning mb-4 d-flex align-items-start gap-2">
            <i class="fas fa-history mt-1"></i>
            <div><div class="fw-semibold">Mode Rekonstruksi Stok</div><div class="small">Menampilkan estimasi stok per tanggal <?= htmlspecialchars(date('d M Y', strtotime($filter_date))) ?>.</div></div>
        </div>
    <?php endif; ?>

    <div class="row mb-4 g-3">
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Items</div><div class="summary-value"><?= $summary_stok['total_items'] ?></div></div><i class="fas fa-boxes summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">On Site</div><div class="summary-value"><?= fmt_qty($summary_stok['total_qty']) ?></div></div><i class="fas fa-cubes summary-icon text-primary-custom"></i></div></div></div>
        <?php if ($show_hc): ?><div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">HC</div><div class="summary-value"><?= fmt_qty($summary_stok['total_qty_hc']) ?></div></div><i class="fas fa-user-nurse summary-icon text-primary-custom"></i></div></div></div><?php endif; ?>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Sellout</div><div class="summary-value"><?= fmt_qty($summary_stok['total_sellout_klinik']) ?></div></div><i class="fas fa-history summary-icon text-primary-custom"></i></div></div></div>
        <div class="col"><div class="card card-summary"><div class="card-body p-3 d-flex justify-content-between"><div><div class="summary-label">Reserve</div><div class="summary-value"><?= fmt_qty($summary_stok['reserve_onsite']) ?></div></div><i class="fas fa-hospital summary-icon text-primary-custom"></i></div></div></div>
    </div>

    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover table-bordered mb-0 datatable-stok">
            <thead><tr>
                <th class="text-center">Kode</th><th class="text-center">Nama Barang</th><th class="text-center">Satuan</th><th class="text-center">On Site</th>
                <?php if ($show_hc): ?><th class="text-center">HC</th><?php endif; ?>
                <th class="text-center">Sellout</th><?php if ($show_hc): ?><th class="text-center">Sellout HC</th><?php endif; ?>
                <th class="text-center">Reserve</th><?php if ($show_hc): ?><th class="text-center">Reserve HC</th><?php endif; ?>
                <th class="text-center">On Hand</th><th class="text-center">Available</th>
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
                    $on_h = $is_history_date ? ($s_on + ($show_hc ? $s_hc : 0)) : (($s_on + ($show_hc ? $s_hc : 0)) - ($sell + ($show_hc ? $sell_hc : 0)));
                    $avail = $on_h - ($res + ($show_hc ? $res_hc : 0));
                ?>
                <tr>
                    <td class="small text-muted text-center"><?= htmlspecialchars($r['kode_barang_master'] ?: $r['kode_barang']) ?></td>
                    <td class="small fw-medium"><?= htmlspecialchars($r['nama_barang']) ?></td>
                    <td class="small text-muted text-center"><?= htmlspecialchars($r['satuan']) ?></td>
                    <td class="fw-bold text-center"><?= fmt_qty($s_on) ?></td>
                    <?php if ($show_hc): ?><td class="text-center"><?php if($s_hc != 0): ?><a href="#" class="text-primary fw-bold text-decoration-none" onclick="loadHCDetail(<?= $r['barang_id'] ?>, <?= $selected_klinik === 'all' ? 0 : $selected_klinik ?>, '<?= addslashes($r['nama_barang']) ?>'); return false;"><?= fmt_qty($s_hc) ?></a><?php else: ?>0<?php endif; ?></td><?php endif; ?>
                    <td class="text-center <?= $sell > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sell) ?></td>
                    <?php if ($show_hc): ?><td class="text-center <?= $sell_hc > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>"><?= fmt_qty($sell_hc) ?></td><?php endif; ?>
                    <td class="text-center <?= $res > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?php if($res > 0 && $selected_klinik !== 'all' && $selected_klinik !== 'gudang_utama'): ?><a href="#" class="text-warning fw-bold text-decoration-none" onclick="openStokBreakdown(<?= $r['barang_id'] ?>, '<?= addslashes($r['nama_barang']) ?>'); return false;"><?= fmt_qty($res) ?></a><?php else: ?><?= fmt_qty($res) ?><?php endif; ?></td>
                    <?php if ($show_hc): ?><td class="text-center <?= $res_hc > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>"><?= fmt_qty($res_hc) ?></td><?php endif; ?>
                    <td class="text-center <?= $on_h < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($on_h) ?></td>
                    <td class="text-center <?= $avail < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>"><?= fmt_qty($avail) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
    <?php endif; ?>

    <!-- --- TAB CONTENT: REKAP --- -->
    <?php if ($active_tab === 'rekap'): ?>
    <div class="card mb-4"><div class="card-body p-4"><div class="row align-items-center">
        <div class="col"><form class="row g-3 align-items-end" method="GET">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="tab" value="rekap">
            <div class="col-md-5"><label class="form-label small fw-bold text-muted mb-1">Klinik</label><select name="klinik_id" class="form-select border-1"><option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option><?php foreach ($kliniks as $k): ?><option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= $k['nama_klinik'] ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold text-muted mb-1">Bulan</label><select name="month" class="form-select border-1"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $selected_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?></select></div>
            <div class="col-md-2"><label class="form-label small fw-bold text-muted mb-1">Tahun</label><select name="year" class="form-select border-1"><?php for ($y = date('Y'); $y >= 2024; $y--): ?><option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
            <div class="col-md-auto"><button type="submit" class="btn btn-primary px-4 fw-bold"><i class="fas fa-filter me-2"></i>Filter</button></div>
        </form></div>
        <div class="col-auto d-flex gap-2">
            <div class="stat-info-badge"><i class="fas fa-hourglass-half text-primary"></i><div><div class="stat-info-label">Time Gone</div><div class="stat-info-value"><?= $time_gone_percent ?>%</div></div></div>
            <div class="stat-info-badge"><i class="fas fa-calendar-check text-primary"></i><div><div class="stat-info-label">Date MTD</div><div class="stat-info-value"><?= $mtd_date ?></div></div></div>
        </div>
    </div></div></div>

    <div class="row g-2 mb-4">
        <div class="col"><div class="stat-card"><div class="stat-label">Sellout Onsite</div><div class="stat-value"><?= fmt_qty($summary_rekap['sellout_onsite']) ?></div></div></div>
        <div class="col"><div class="stat-card"><div class="stat-label">Sellout HC</div><div class="stat-value"><?= fmt_qty($summary_rekap['sellout_hc']) ?></div></div></div>
        <div class="col"><div class="stat-card"><div class="stat-label">Reserve Sold Onsite</div><div class="stat-value"><?= fmt_qty($summary_rekap['reserve_onsite']) ?></div></div></div>
        <div class="col"><div class="stat-card"><div class="stat-label">Reserve Sold HC</div><div class="stat-value"><?= fmt_qty($summary_rekap['reserve_hc']) ?></div></div></div>
        <div class="col"><div class="stat-card bg-reference"><div class="stat-label" style="color:#0369a1">Booked Onsite</div><div class="stat-value" style="color:#0369a1"><?= fmt_qty($summary_rekap['reserve_booked_onsite']) ?></div></div></div>
        <div class="col"><div class="stat-card bg-reference"><div class="stat-label" style="color:#0369a1">Booked HC</div><div class="stat-value" style="color:#0369a1"><?= fmt_qty($summary_rekap['reserve_booked_hc']) ?></div></div></div>
    </div>

    <div class="card border-0 shadow-sm p-3"><div class="d-flex justify-content-between align-items-center mb-3">
        <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold" id="btnExportExcel"><i class="fas fa-file-excel me-1"></i> Export Excel</button>
        <div class="d-flex align-items-center"><label class="small fw-bold text-muted me-2 mb-0">Cari:</label><input type="text" id="customSearch" class="form-control form-control-sm" style="width: 200px;"></div>
    </div><div class="table-responsive"><table class="table table-bordered mb-0" id="tableSummary" style="font-size:0.85rem">
        <thead>
            <tr><th rowspan="2" class="text-center">Kode</th><th rowspan="2">Nama Barang</th><th rowspan="2" class="text-center">Satuan</th><th colspan="3" class="text-center">Sellout</th><th colspan="2" class="text-center">Non-Reserve</th><th colspan="2" class="text-center">Reserve-Sold</th><th colspan="2" class="text-center bg-reference">Reserve-Booked</th></tr>
            <tr><th class="text-center bg-total">Total</th><th class="text-center bg-onsite">Onsite</th><th class="text-center bg-hc">HC</th><th class="text-center bg-onsite">Onsite</th><th class="text-center bg-hc">HC</th><th class="text-center bg-onsite">Onsite</th><th class="text-center bg-hc">HC</th><th class="text-center bg-reference-onsite">Onsite</th><th class="text-center bg-reference-hc">HC</th></tr>
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
    <?php if ($active_tab === 'stok'): ?>
    $('.datatable-stok').DataTable({ "order": [[ 0, "asc" ]], "pageLength": 10, "language": { "search": "Cari:", "paginate": { "next": '<i class="fas fa-chevron-right"></i>', "previous": '<i class="fas fa-chevron-left"></i>' } } });
    <?php endif; ?>

    <?php if ($active_tab === 'rekap'): ?>
    var table = $('#tableSummary').DataTable({ "dom": 'rtp', "pageLength": -1, "ordering": false });
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
function openStokBreakdown(bId, name) { var m = new bootstrap.Modal(document.getElementById('modalStokBreakdown')); m.show(); $('#stokBreakdownBody').html('...'); $.ajax({ url: 'api/ajax_stok_klinik_breakdown.php', method: 'POST', dataType: 'json', data: { klinik_id: <?= (int)$selected_klinik ?>, barang_id: bId, tanggal: "<?= $filter_date ?? '' ?>", token: "<?= $token ?>" } }).then(function(res) { if(!res || !res.success) { $('#stokBreakdownBody').html('Err'); return; } var html = '<h5>' + name + '</h5><p>Rekonstruksi selesai. Stok tersedia: ' + res.result.tersedia + '</p>'; $('#stokBreakdownBody').html(html); }); }
function fmt_qty(v) { var n = parseFloat(v || 0); return (n % 1 === 0) ? n : n.toFixed(2); }
</script>
</body>
</html>