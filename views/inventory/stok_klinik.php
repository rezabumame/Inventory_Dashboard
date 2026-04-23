<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'cs', 'petugas_hc']);

$can_filter_klinik = in_array($_SESSION['role'], ['super_admin', 'admin_gudang', 'cs']);
$is_cs = ($_SESSION['role'] == 'cs');
$can_view_monitoring = in_array($_SESSION['role'], ['super_admin', 'admin_gudang', 'admin_klinik']);
$active_tab = 'stok'; // Set default tab

$selected_klinik = '';
$include_gudang = isset($_GET['include_gudang']) && $_GET['include_gudang'] == '1';
$gudang_utama_loc = trim((string)get_setting('odoo_location_gudang_utama', ''));

if ($can_filter_klinik) {
    $selected_klinik = isset($_GET['klinik_id']) ? $_GET['klinik_id'] : '';
    if ($selected_klinik !== 'all' && $selected_klinik !== 'gudang_utama' && $selected_klinik !== '') $selected_klinik = (int)$selected_klinik;
} else {
    $selected_klinik = (int)$_SESSION['klinik_id'];
}

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
if ($can_filter_klinik) {
    $res = $conn->query("SELECT * FROM inventory_klinik WHERE status='active'");
    while ($row = $res->fetch_assoc()) $kliniks[] = $row;
} else {
    $res = $conn->query("SELECT * FROM inventory_klinik WHERE id = " . $_SESSION['klinik_id']);
    if ($res->num_rows > 0) $kliniks[] = $res->fetch_assoc();
}

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

// Fetch Pemeriksaan List (For Filter)
$pemeriksaan_list = [];
$res_pem = $conn->query("SELECT * FROM inventory_pemeriksaan_grup ORDER BY nama_pemeriksaan");
while($row = $res_pem->fetch_assoc()) $pemeriksaan_list[] = $row;

// TAB STOK KLINIK
if ($active_tab == 'stok') {
    // IF CS and Klinik selected (or view mode changed), show Exam Availability (REMOVED: CS now uses standard view)
    if (false) {
        // 1. Fetch Stock for this clinic (Efficient Bulk Query)
        $stok_map = [];
        if ($selected_klinik === 'all') {
            $active_ids = array_map(function($k){return (int)$k['id'];}, $kliniks);
            $ids_str = implode(',', $active_ids);
            
            $res_stok = $conn->query("SELECT barang_id, SUM(qty) as qty, SUM(qty_gantung) as qty_gantung FROM (
                SELECT barang_id, qty, qty_gantung FROM inventory_stok_gudang_klinik WHERE klinik_id IN ($ids_str)
                " . ($include_gudang ? "UNION ALL SELECT barang_id, qty, reserved_qty as qty_gantung FROM inventory_stok_gudang_utama" : "") . "
            ) combined GROUP BY barang_id");
        } elseif ($selected_klinik === 'gudang_utama') {
            $res_stok = $conn->query("SELECT barang_id, qty, reserved_qty as qty_gantung FROM inventory_stok_gudang_utama");
        } else {
            $res_stok = $conn->query("SELECT barang_id, qty, qty_gantung FROM inventory_stok_gudang_klinik WHERE klinik_id = $selected_klinik");
        }
        while($row = $res_stok->fetch_assoc()) {
            $stok_map[$row['barang_id']] = (float)$row['qty'] - (float)$row['qty_gantung'];
        }

        // 2. Fetch All Exams
        $exams_data = [];
        $res_exams = $conn->query("SELECT * FROM inventory_pemeriksaan_grup ORDER BY nama_pemeriksaan");
        while($ex = $res_exams->fetch_assoc()) {
            $ex['ingredients'] = [];
            $exams_data[$ex['id']] = $ex;
        }

        // 3. Fetch All Details (Efficient Bulk Query)
        $res_det = $conn->query("SELECT pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan, is_mandatory FROM inventory_pemeriksaan_grup_detail");
        while($d = $res_det->fetch_assoc()) {
            if (isset($exams_data[$d['pemeriksaan_grup_id']])) {
                $exams_data[$d['pemeriksaan_grup_id']]['ingredients'][] = $d;
            }
        }

        // 4. Calculate Availability
        $exams = []; // Final list for display
        foreach ($exams_data as $ex) {
            $max_possible = 999999;
            $is_ready = true;
            
            if (empty($ex['ingredients'])) {
                $max_possible = 0; // No ingredients defined = Not ready
                $is_ready = false;
            } else {
                foreach ($ex['ingredients'] as $ing) {
                    $bid = $ing['barang_id'];
                    $req = $ing['qty_per_pemeriksaan'];
                    $is_m = (int)($ing['is_mandatory'] ?? 1);
                    
                    $avail = isset($stok_map[$bid]) ? $stok_map[$bid] : 0;
                    
                    if ($avail < $req) {
                        if ($is_m) {
                            $max_possible = 0;
                            $is_ready = false;
                            break;
                        }
                    } else {
                        $possible = floor($avail / $req);
                        if ($possible < $max_possible) $max_possible = $possible;
                    }
                }
            }
        }
    }
    
    // Normal View for all roles (including CS)
    if (true) {
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
                    $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.created_at <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
                    $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . " 00:00:00' AND pb.created_at <= '" . $conn->real_escape_string($filter_date) . " 23:59:59'";
                    $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at <= '" . $conn->real_escape_string($filter_end_ts) . "'";
                    $filter_ts_hc = $filter_ts_klinik;
                } else {
                    if ($last_update_general !== '') {
                        $last_update_time = date('H:i:s', strtotime($last_update_general));
                        // Task Fix: Bookings are always local and never synced to Odoo.
                        // We should subtract ALL active bookings for the current period, regardless of Odoo sync time.
                        $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($today_date) . "'";
                        
                        // Sellout (Pemakaian) should only subtract what's NOT in Odoo yet.
                        // We use a 5-minute buffer to account for the sync duration (race condition).
                        $sync_buffer_ts = date('Y-m-d H:i:s', strtotime($last_update_general));
                        $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.created_at > '" . $conn->real_escape_string($sync_buffer_ts) . "'";
                        $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at > '" . $conn->real_escape_string($sync_buffer_ts) . "'";
                        
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
                          " . (($selected_klinik === 'all' || $selected_klinik === 'gudang_utama') ? "" : "JOIN inventory_klinik k ON k.id = $selected_klinik_id") . "
                          WHERE 1=1";

                if ($selected_pemeriksaan) {
                    $item_ids = [];
                    $res_ids = $conn->query("SELECT barang_id FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = " . (int)$selected_pemeriksaan);
                    while($r = $res_ids->fetch_assoc()) $item_ids[] = (int)$r['barang_id'];
                    if (!empty($item_ids)) {
                        $ids_str = implode(',', $item_ids);
                        $query .= " AND (b.id IN ($ids_str))";
                    } else {
                        $query .= " AND 1=0";
                    }
                }

                $query .= " ORDER BY kode_barang_master ASC, nama_barang ASC";
                try {
                    $conn->query("SET SQL_BIG_SELECTS=1");
                    $result = $conn->query($query);
                    while ($r = $result->fetch_assoc()) {
                        if ($is_history_date) {
                            // In history mode, we keep sellout/transfer for display purposes,
                            // but they don't affect the reconstructed 'qty' (Odoo On Hand)
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
                    }
                } catch (Exception $e) {
                    die("Query Error: " . $e->getMessage() . "<br>Query: <pre>" . $query . "</pre>");
                }
            }
        }
        
    }
}


?>

<div class="container-fluid">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-hospital me-2"></i>Inventory Klinik
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Stok Klinik</li>
                </ol>
            </nav>
        </div>
    </div>

<!-- Tabs Navigation removed: only Stok Barang is shown -->
<style>
    .active-blue {
        background-color: #204EAB !important;
        color: white !important;
        box-shadow: 0 4px 6px rgba(32, 78, 171, 0.2);
    }
    .text-primary-custom { color: #204EAB; }
    .breadcrumb-item.active { color: #6c757d; }
    .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
    .refresh-btn { border-width: 2px; border-radius: 10px; padding: 0.6rem 1rem; }
    .last-update { font-size: 0.875rem; color: #6c757d; }
    .text-sellout-hc { color: #dc3545 !important; }
    .text-reserve-onsite { color: #0891b2 !important; }
    .text-reserve-hc { color: #0891b2 !important; }
    
    /* Soft borders for datatable-stok */
    .datatable-stok {
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px !important;
        overflow: hidden !important;
    }
    .datatable-stok th,
    .datatable-stok td {
        border: 1px solid #f1f5f9 !important;
        padding: 12px 10px !important;
        vertical-align: middle !important;
    }
    .datatable-stok thead th {
        background-color: #204EAB !important;
        color: white !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.025em;
    }
    .datatable-stok tbody tr:hover {
        background-color: #f8fafc !important;
    }
</style>

<!-- TAB CONTENT: STOK KLINIK -->
<?php if ($active_tab == 'stok'): ?>

<!-- Filter untuk Tab Stok -->
<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <input type="hidden" name="page" value="stok_klinik">
            <input type="hidden" name="tab" value="stok">
            
            <?php if ($can_filter_klinik): ?>
            <div class="col-md-4">
                <label class="form-label fw-bold small mb-1">
                    <i class="fas fa-hospital text-primary"></i> Klinik <span class="text-danger">*</span>
                </label>
                <select name="klinik_id" class="form-select" onchange="this.form.submit()">
                    <option value="">- Pilih Klinik -</option>
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
                <?php if ($selected_klinik === 'all'): ?>
                <div class="mt-1">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="include_gudang" id="includeGudang" value="1" <?= $include_gudang ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label small" for="includeGudang">Include Gudang Utama</label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label fw-bold small mb-1">
                    <i class="fas fa-calendar-alt text-primary"></i> Tanggal
                </label>
                <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
            </div>
            <?php if (!in_array($_SESSION['role'], ['cs','admin_klinik','spv_klinik'])): ?>
            <div class="col-md-6">
                <div class="d-flex flex-column align-items-end">
                    <button type="button" class="btn btn-outline-primary refresh-btn d-flex align-items-center justify-content-center gap-2" onclick="confirmSync(this)" <?= $is_history_date ? 'disabled' : '' ?>>
                        <i class="fas fa-sync-alt"></i><span>Refresh dari Odoo</span>
                    </button>
                    <div class="last-update mt-1" id="lastUpdateText">Terakhir update: <?= htmlspecialchars($last_update_text ?? '-') ?></div>
                    <small class="text-muted d-block" id="syncStatus" style="min-height: 1rem;"></small>
                </div>
            </div>
            <?php endif; ?>
        </form>
        <?php if ($selected_klinik && $is_history_date): ?>
            <div class="alert alert-warning mt-3 mb-0 d-flex align-items-start gap-2">
                <i class="fas fa-history mt-1"></i>
                <div>
                    <div class="fw-semibold">Mode Rekonstruksi Stok (Per Tanggal)</div>
                    <div class="small">Menampilkan estimasi stok per tanggal <?= htmlspecialchars(date('d M Y', strtotime($filter_date))) ?> berdasarkan data transaksi <?= htmlspecialchars(date('d M Y', strtotime($month_start))) ?> s/d <?= htmlspecialchars(date('d M Y', strtotime($filter_date))) ?>. Kolom IN/OUT/Sellout ditampilkan 0 karena stok sudah direkonstruksi; Reserve dihitung dari tanggal reservasi (tanggal_pemeriksaan) mulai <?= htmlspecialchars(date('d M Y', strtotime($filter_date))) ?> hingga akhir bulan.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Cards for Stok Barang -->
<?php if ($selected_klinik): ?>
<div class="row mb-3 g-2">
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Jenis Barang</div>
                        <div class="h4 mb-0 fw-bold"><?= $summary_stok['total_items'] ?></div>
                    </div>
                    <i class="fas fa-boxes fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Stok On Site</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['total_qty'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-cubes fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <?php if ($show_hc): ?>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Stok HC</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['total_qty_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Sellout Onsite</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['total_sellout_klinik'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-history fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Reserve Onsite</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['reserve_onsite'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-hospital fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <?php if ($show_hc): ?>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Sellout HC</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['total_sellout_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Reserve HC</div>
                        <div class="h4 mb-0 fw-bold"><?= fmt_qty($summary_stok['reserve_hc'] ?? 0) ?></div>
                    </div>
                    <i class="fas fa-user-nurse fa-lg text-primary-custom" style="opacity: 0.6;"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($selected_klinik && $is_history_date && !$is_cs): ?>
<div class="modal fade" id="modalStokBreakdown" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2 text-primary-custom"></i>Detail Rekonstruksi Stok</h5>
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

<script>
window.__stokKlinikContext = {
    klinikId: <?= (int)$selected_klinik ?>,
    tanggal: "<?= htmlspecialchars($filter_date) ?>",
    tanggalLabel: "<?= htmlspecialchars($filter_date_label) ?>"
};

function fmtNum(v) {
    var n = parseFloat(v || 0);
    if (Math.abs(n - Math.round(n)) < 0.00005) return Math.round(n).toString();
    var s = n.toFixed(4).replace(/\.?0+$/, "");
    return s === "" ? "0" : s;
}

function openStokBreakdown(barangId, namaBarang) {
    var modalEl = document.getElementById('modalStokBreakdown');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    var body = document.getElementById('stokBreakdownBody');
    body.innerHTML = '<div class="text-muted">Memuat...</div>';

    $.ajax({
        url: 'api/ajax_stok_klinik_breakdown.php',
        method: 'POST',
        dataType: 'json',
        data: { klinik_id: window.__stokKlinikContext.klinikId, barang_id: barangId, tanggal: window.__stokKlinikContext.tanggal }
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

        var periodRows = periodPem.length ? periodPem.map(function(p) {
            var jenis = (p.jenis_pemakaian === 'hc') ? 'HC' : 'Klinik';
            return '<tr><td>' + $('<div>').text(p.nomor_pemakaian || '').html() + '</td><td>' + $('<div>').text(p.tanggal || '').html() + '</td><td>' + jenis + '</td><td class="text-end fw-semibold">' + fmtNum(p.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada</td></tr>';

        var trfRows = trf.length ? trf.map(function(t) {
            var level = (t.level === 'hc') ? '<span class="badge bg-info x-small">HC</span>' : '<span class="badge bg-light text-dark x-small border">Klinik</span>';
            return '<tr><td class="ps-3 text-muted">#' + (t.transfer_id || '-') + '</td><td>' + (t.tipe_transaksi || '-') + ' ' + level + '</td><td>' + $('<div>').text(t.last_at || '').html() + '</td><td class="text-end pe-3 fw-semibold">' + fmtNum(t.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada transaksi transfer</td></tr>';

        body.innerHTML = `
            <div class="mb-3 text-center">
                <div class="small text-muted mb-1 text-uppercase fw-bold letter-spacing-05">Barang</div>
                <div class="h5 fw-bold text-primary-custom mb-0">${$('<div>').text((b.kode_barang || '-') + ' - ' + (b.nama_barang || namaBarang)).html()}</div>
            </div>

            <!-- Hasil Akhir (To the point) -->
            <div class="row g-2 mb-4">
                <div class="col-md-6">
                    <div class="p-3 bg-light border rounded text-center h-100">
                        <div class="small text-muted mb-1 fw-semibold text-uppercase">Stok Akhir (On Hand)</div>
                        <div class="h3 mb-0 fw-bold text-dark">${fmtNum(result.stock_total || 0)}</div>
                        <div class="small text-muted mt-1">Per ${$('<div>').text(window.__stokKlinikContext.tanggalLabel).html()}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-primary-light border border-primary-custom rounded text-center h-100">
                        <div class="small text-primary-custom mb-1 fw-semibold text-uppercase">Tersedia (Siap Pakai)</div>
                        <div class="h3 mb-0 fw-bold text-primary-custom">${fmtNum(result.tersedia || 0)}</div>
                        <div class="small text-primary-custom mt-1">Setelah dikurangi Reservasi</div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <!-- Breakdown Onsite -->
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded bg-light-subtle">
                            <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-hospital me-2"></i>Stok Onsite</h6>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Stok Odoo Sekarang:</span>
                                <span>${fmtNum(baseOn)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small text-success">
                                <span>Penyesuaian (Rollback):</span>
                                <span class="fw-bold">+ ${fmtNum((rb.out_transfer || 0) - (rb.in_transfer || 0) + (rb.sellout_klinik || 0))}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top fw-bold text-dark">
                                <span>Total Onsite:</span>
                                <span>${fmtNum(result.stock_onsite || 0)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Breakdown HC -->
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded bg-light-subtle">
                            <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-user-nurse me-2"></i>Stok Home Care (HC)</h6>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Stok Odoo Sekarang:</span>
                                <span>${fmtNum(baseHc)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small text-success">
                                <span>Penyesuaian (Rollback):</span>
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

            <div class="row g-3 mb-4">
                <!-- Reservasi Detail -->
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded">
                            <h6 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-calendar-check me-2"></i>Reservasi Booking</h6>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Booking Onsite:</span>
                                <span class="fw-bold text-danger">-${fmtNum(reserveOn)}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small text-muted">
                                <span>Booking HC:</span>
                                <span class="fw-bold text-danger">-${fmtNum(reserveHc)}</span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top fw-bold text-danger">
                                <span>Total Reservasi:</span>
                                <span>-${fmtNum(reserveOn + reserveHc)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Info Sync -->
                <div class="col-md-6">
                    <div class="card shadow-none border-0 h-100">
                        <div class="card-body p-3 border rounded d-flex flex-column justify-content-center">
                            <div class="text-center">
                                <i class="fas fa-sync-alt fa-2x text-muted mb-2"></i>
                                <div class="small text-muted">Data Odoo terakhir diupdate pada:</div>
                                <div class="fw-bold text-dark">${lastU}</div>
                                <div class="x-small text-muted mt-1 fst-italic">Sync otomatis berjalan setiap 1 jam atau manual.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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
        `;
    }).catch(function() {
        body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail.</div>';
    });
}
</script>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (!$selected_klinik): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Silakan pilih klinik terlebih dahulu untuk melihat data stok.
            </div>
        <?php else: ?>
            <!-- Normal Table for All Roles -->
            <?php if (empty($rows)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Data stok dari Odoo belum tersedia. Klik <b>Refresh dari Odoo</b> untuk menarik data.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover datatable-stok">
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
                            $stok_onsite = $row['qty'];
                            $sellout = $row['sellout_klinik'] ?? 0;
                            $reserve = $row['reserve_onsite'] ?? 0;
                            $stok_hc = $row['stok_hc'] ?? 0;
                            $sellout_hc = $row['sellout_hc'] ?? 0;
                            $reserve_hc = $row['reserve_hc'] ?? 0;
                            $reserve_total = $reserve + ($show_hc ? $reserve_hc : 0);
                            $in_transfer = $row['in_transfer'] ?? 0;
                            $out_transfer = $row['out_transfer'] ?? 0;
                            $in_transfer_hc = $row['in_transfer_hc'] ?? 0;
                            $out_transfer_hc = $row['out_transfer_hc'] ?? 0;
                            if ($is_history_date) {
                                $stok_onsite = $stok_onsite + (float)($row['rb_out_transfer'] ?? 0) - (float)($row['rb_in_transfer'] ?? 0) + (float)($row['rb_sellout_klinik'] ?? 0);
                                $stok_hc = $stok_hc + (float)($row['rb_out_transfer_hc'] ?? 0) - (float)($row['rb_in_transfer_hc'] ?? 0) + (float)($row['rb_sellout_hc'] ?? 0);
                            } else {
                                $stok_onsite = $stok_onsite + (float)$in_transfer - (float)$out_transfer;
                                $stok_hc = $stok_hc + (float)$in_transfer_hc - (float)$out_transfer_hc;
                            }
                            
                            $total_stok = $stok_onsite + ($show_hc ? $stok_hc : 0);
                            
                            // Task Fix: In history mode, $stok_onsite is already the reconstructed "End of Day" stock.
                            // The historical sellout is NOT subtracted again because the baseline (Current Odoo) already reflects it.
                            // In normal mode (Today), $total_sellout is the local pemakaian since the last sync, so it MUST be subtracted.
                            $total_sellout = $sellout + ($show_hc ? $sellout_hc : 0);
                            if ($is_history_date) {
                                $on_hand = $total_stok;
                            } else {
                                $on_hand = $total_stok - $total_sellout;
                            }
                            $available = $on_hand - $reserve_total;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(!empty($row['kode_barang_master']) ? $row['kode_barang_master'] : ($row['kode_barang'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="small">
                                <div><?= htmlspecialchars($row['satuan']) ?></div>
                                <?php if (!empty($row['uom_odoo']) && (float)($row['uom_multiplier'] ?? 1) != 1.0): ?>
                                    <div class="text-muted small">1 <?= htmlspecialchars($row['satuan']) ?> = <?= htmlspecialchars(fmt_qty($row['uom_multiplier'])) ?> <?= htmlspecialchars($row['uom_odoo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                    <?php
                                        $onsite_class = ((float)$stok_onsite) < 0 ? 'text-danger fw-bold' : 'fw-bold';
                                        echo '<div class="'.$onsite_class.'">' . fmt_qty($stok_onsite) . '</div>';
                                    ?>
                                </div>
                                <?php if (!$is_history_date && (((float)$in_transfer) > 0 || ((float)$out_transfer) > 0)): ?>
                                    <div class="d-flex align-items-center justify-content-center gap-2 small mt-1">
                                        <div class="d-flex align-items-center gap-1">
                                            <i class="fas fa-arrow-down text-success" style="font-size: 0.7rem;"></i>
                                            <?php if ((float)$in_transfer > 0): ?>
                                                <a href="javascript:void(0)" class="text-decoration-none text-dark fw-semibold" style="cursor: pointer; font-size: 0.75rem;" onclick="loadStockTransactionDetail(<?= (int)$row['barang_id'] ?>, '<?= $selected_klinik ?>', 'in', <?= htmlspecialchars(json_encode($row['nama_barang']), ENT_QUOTES) ?>)">
                                                    <?= fmt_qty($in_transfer) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.75rem;">0</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.7rem; opacity: 0.3;">|</div>
                                        <div class="d-flex align-items-center gap-1">
                                            <i class="fas fa-arrow-up text-danger" style="font-size: 0.7rem;"></i>
                                            <?php if ((float)$out_transfer > 0): ?>
                                                <a href="javascript:void(0)" class="text-decoration-none text-dark fw-semibold" style="cursor: pointer; font-size: 0.75rem;" onclick="loadStockTransactionDetail(<?= (int)$row['barang_id'] ?>, '<?= $selected_klinik ?>', 'out', <?= htmlspecialchars(json_encode($row['nama_barang']), ENT_QUOTES) ?>)">
                                                    <?= fmt_qty($out_transfer) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.75rem;">0</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="text-center">
                                <?php if ($stok_hc != 0): ?>
                                    <div class="d-flex flex-column align-items-center">
                                        <?php 
                                            $hc_class = ((float)$stok_hc) < 0 ? 'text-danger fw-bold' : 'text-primary fw-bold';
                                        ?>
                                        <a href="javascript:void(0)" class="<?= $hc_class ?> text-decoration-none"
                                           onclick="loadHCDetail(<?= $row['barang_id'] ?>, <?= $row['klinik_id'] ?>, '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>'); return false;">
                                            <?= fmt_qty($stok_hc) ?> <i class="fas fa-user-nurse"></i>
                                        </a>
                                        <?php if ((float)($row['in_transfer_hc'] ?? 0) > 0): ?>
                                              <div class="text-success small" style="font-size: 0.7rem; font-weight: bold;">
                                                  <i class="fas fa-arrow-down me-1"></i><?= fmt_qty($row['in_transfer_hc']) ?>
                                              </div>
                                          <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">0</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center <?= $sellout > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($sellout) ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="text-center <?= $sellout_hc > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($sellout_hc) ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center <?= $reserve > 0 ? 'text-reserve-onsite fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($reserve) ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="text-center <?= $reserve_hc > 0 ? 'text-reserve-hc fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($reserve_hc) ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center <?= $on_hand < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                                <?= fmt_qty($on_hand) ?>
                            </td>
                            <td class="text-center <?= $available < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                                <?= fmt_qty($available) ?>
                            </td>
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
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php endif; // End TAB STOK ?>

<!-- Monitoring HC tab content removed by request -->

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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap-5'
    });

    // Local DataTable init for Stok Klinik (sort by Kode Barang - index 0)
    if ($.fn.DataTable.isDataTable('.datatable-stok')) {
        $('.datatable-stok').DataTable().destroy();
    }
    $('.datatable-stok').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 10
    });
    
    // Simple search functionality for monitoring tab
    $('#searchTable').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('#tableBody tr.data-row').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
});

function loadHCDetail(barangId, klinikId, namaBarang) {
    // Set barang name
    $('#modalBarangName').text(namaBarang);
    
    // Show loading
    $('#hcDetailContent').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="text-muted mt-2">Memuat data...</p></div>');
    
    // Show modal
    var modalEl = document.getElementById('modalHCDetail');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    
    // Load data via AJAX
    $.ajax({
        url: 'api/ajax_hc_detail.php',
        method: 'POST',
        data: {
            barang_id: barangId,
            klinik_id: klinikId
        },
        success: function(response) {
            $('#hcDetailContent').html(response);
        },
        error: function(xhr, status, error) {
            $('#hcDetailContent').html('<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle"></i> Gagal memuat data. Silakan coba lagi.</div>');
            console.error('AJAX Error:', status, error);
        }
    });
    
    return false;
}

// removed: loadSelloutDetail (not used)

function confirmSync(btn) {
    Swal.fire({
        title: 'Konfirmasi Sinkronisasi',
        text: 'Apakah Anda yakin ingin melakukan sinkronisasi stok dari Odoo sekarang? Proses ini mungkin memakan waktu beberapa saat.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#204EAB',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Sync Sekarang',
        cancelButtonText: 'Batal',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            syncFromOdoo(btn);
        }
    });
}

async function syncFromOdoo(btn) {
    const statusEl = document.getElementById('syncStatus');
    const lastEl = document.getElementById('lastUpdateText');
    statusEl.textContent = 'Sinkronisasi berjalan...';
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
        const res = await fetch('api/sync_odoo.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                title: 'Berhasil!',
                text: `Sinkronisasi selesai. Produk: ${data.products}, Lokasi: ${data.locations}, Baris: ${data.rows}`,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
            if (lastEl) {
                const now = new Date();
                const pad = n => n.toString().padStart(2, '0');
                const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                const text = `Terakhir update: ${pad(now.getDate())} ${months[now.getMonth()]} ${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
                lastEl.textContent = text;
            }
            setTimeout(() => { window.location.reload(); }, 800);
        } else {
            statusEl.textContent = 'Gagal: ' + (data.message || 'Unknown error');
        }
    } catch (e) {
        statusEl.textContent = 'Gagal: ' + e.message;
    } finally {
        btn.disabled = false;
    }
}

// Export to Excel function
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    
    // Specify file name
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    
    // Create download link element
    downloadLink = document.createElement("a");
    
    document.body.appendChild(downloadLink);
    
    if(navigator.msSaveOrOpenBlob){
        var blob = new Blob(['\ufeff', tableHTML], {
            type: dataType
        });
        navigator.msSaveOrOpenBlob( blob, filename);
    }else{
        // Create a link to the file
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
    
        // Setting the file name
        downloadLink.download = filename;
        
        //triggering the function
        downloadLink.click();
    }
}

function loadStockTransactionDetail(barangId, klinikId, tipe, namaBarang) {
    $('#modalTransactionType').text(tipe);
    $('#modalTransactionBarangName').text(namaBarang);
    
    $('#transactionDetailContent').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="text-muted mt-2">Memuat data...</p></div>');
    
    var modalEl = document.getElementById('modalStockTransactionDetail');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    
    $.ajax({
        url: 'api/ajax_stock_transaction_detail.php',
        method: 'POST',
        data: {
            barang_id: barangId,
            klinik_id: klinikId,
            tipe: tipe,
            tanggal: '<?= $filter_date ?>'
        },
        success: function(res) {
            if (res.success && res.data && res.data.length > 0) {
                var html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">';
                html += '<thead class="table-light"><tr><th>Waktu</th><th>Referensi</th><th>Detail</th><th>Qty</th><th>Petugas</th></tr></thead>';
                html += '<tbody>';
                res.data.forEach(function(item) {
                    html += '<tr>';
                    html += '<td class="small">' + item.tanggal + '</td>';
                    html += '<td><span class="badge bg-light text-dark border">' + item.referensi + '</span></td>';
                    html += '<td class="small">' + item.detail + '</td>';
                    html += '<td class="fw-bold text-primary">' + item.qty + '</td>';
                    html += '<td class="small text-muted">' + item.petugas + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
                $('#transactionDetailContent').html(html);
            } else {
                $('#transactionDetailContent').html('<div class="alert alert-info mb-0 text-center"><i class="fas fa-info-circle me-1"></i> Tidak ada data histori untuk periode ini.</div>');
            }
        },
        error: function() {
            $('#transactionDetailContent').html('<div class="alert alert-danger mb-0 text-center"><i class="fas fa-exclamation-triangle me-1"></i> Gagal memuat data histori.</div>');
        }
    });
}
</script>

<!-- Modal Stock Transaction Detail -->
<div class="modal fade" id="modalStockTransactionDetail" tabindex="-1" aria-labelledby="modalStockTransactionDetailLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #204EAB;">
                <h5 class="modal-title text-white" id="modalStockTransactionDetailLabel">
                    <i class="fas fa-history me-2"></i> Detail Histori <span id="modalTransactionType" class="text-uppercase"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom">
                    <h6 class="mb-0 text-primary">
                        <i class="fas fa-box me-1"></i> <span id="modalTransactionBarangName" class="fw-bold"></span>
                    </h6>
                </div>
                <div id="transactionDetailContent" class="p-3">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2">Memuat data...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Tutup
                </button>
            </div>
        </div>
    </div>
</div>
