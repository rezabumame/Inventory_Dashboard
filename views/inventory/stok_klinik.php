<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'cs', 'petugas_hc']);

$can_filter_klinik = in_array($_SESSION['role'], ['super_admin', 'admin_gudang', 'cs']);
$is_cs = ($_SESSION['role'] == 'cs');
$can_view_monitoring = in_array($_SESSION['role'], ['super_admin', 'admin_gudang', 'admin_klinik']);
$active_tab = 'stok'; // Set default tab

$selected_klinik = '';
if ($can_filter_klinik) {
    $selected_klinik = isset($_GET['klinik_id']) ? $_GET['klinik_id'] : '';
} else {
    $selected_klinik = $_SESSION['klinik_id'];
}

$selected_pemeriksaan = isset($_GET['pemeriksaan_id']) ? $_GET['pemeriksaan_id'] : '';
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
    $res = $conn->query("SELECT * FROM klinik WHERE status='active'");
    while ($row = $res->fetch_assoc()) $kliniks[] = $row;
} else {
    $res = $conn->query("SELECT * FROM klinik WHERE id = " . $_SESSION['klinik_id']);
    if ($res->num_rows > 0) $kliniks[] = $res->fetch_assoc();
}

// Determine if HC columns should be shown
$show_hc = false;
if ($selected_klinik) {
    foreach ($kliniks as $k) {
        if ($k['id'] == $selected_klinik) {
            if (!empty($k['kode_homecare'])) {
                $show_hc = true;
            }
            break;
        }
    }
}

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

function fmt_qty($v) {
    $n = (float)($v ?? 0);
    if (abs($n - round($n)) < 0.00005) return (string)(int)round($n);
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

// Fetch Pemeriksaan List (For Filter)
$pemeriksaan_list = [];
$res_pem = $conn->query("SELECT * FROM pemeriksaan_grup ORDER BY nama_pemeriksaan");
while($row = $res_pem->fetch_assoc()) $pemeriksaan_list[] = $row;

// TAB STOK KLINIK
if ($active_tab == 'stok') {
    // IF CS and Klinik selected (or view mode changed), show Exam Availability
    if ($is_cs && $selected_klinik) {
        // 1. Fetch Stock for this clinic (Efficient Bulk Query)
        $stok_map = [];
        $res_stok = $conn->query("SELECT barang_id, qty, qty_gantung FROM stok_gudang_klinik WHERE klinik_id = $selected_klinik");
        while($row = $res_stok->fetch_assoc()) {
            $stok_map[$row['barang_id']] = $row['qty'] - $row['qty_gantung'];
        }

        // 2. Fetch All Exams
        $exams_data = [];
        $res_exams = $conn->query("SELECT * FROM pemeriksaan_grup ORDER BY nama_pemeriksaan");
        while($ex = $res_exams->fetch_assoc()) {
            $ex['ingredients'] = [];
            $exams_data[$ex['id']] = $ex;
        }

        // 3. Fetch All Details (Efficient Bulk Query)
        $res_det = $conn->query("SELECT pemeriksaan_grup_id, barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail");
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
                    
                    $avail = isset($stok_map[$bid]) ? $stok_map[$bid] : 0;
                    
                    if ($avail < $req) {
                        $max_possible = 0;
                        $is_ready = false;
                        break;
                    } else {
                        $possible = floor($avail / $req);
                        if ($possible < $max_possible) $max_possible = $possible;
                    }
                }
            }

            // User request: "hanya menampilkan jenis pemeriksaan yang available"
            if ($is_ready && $max_possible > 0) {
                $ex['max_qty'] = $max_possible;
                $exams[] = $ex;
            }
        }
    } elseif (!$is_cs) {
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
            $selected_klinik_id = (int)$selected_klinik;
            $selected_klinik_row = null;
            foreach ($kliniks as $k) {
                if ((int)$k['id'] === $selected_klinik_id) {
                    $selected_klinik_row = $k;
                    break;
                }
            }
            if (!$selected_klinik_row) {
                $res_one = $conn->query("SELECT * FROM klinik WHERE id = $selected_klinik_id LIMIT 1");
                if ($res_one && $res_one->num_rows > 0) $selected_klinik_row = $res_one->fetch_assoc();
            }

            $kode_klinik = $selected_klinik_row['kode_klinik'] ?? '';
            $kode_homecare = $selected_klinik_row['kode_homecare'] ?? '';
            $kode_klinik_esc = $conn->real_escape_string($kode_klinik);
            $kode_homecare_esc = $conn->real_escape_string($kode_homecare);
            $last_update_text = '-';
            $last_update_klinik = '';
            $last_update_hc = '';
            if ($kode_klinik !== '') {
                $res_uk = $conn->query("SELECT MAX(updated_at) as last_update FROM stock_mirror WHERE location_code = '$kode_klinik_esc'");
                if ($res_uk && $res_uk->num_rows > 0) {
                    $urow = $res_uk->fetch_assoc();
                    if (!empty($urow['last_update'])) $last_update_klinik = (string)$urow['last_update'];
                }
                if ($show_hc && $kode_homecare !== '') {
                    $res_uh = $conn->query("SELECT MAX(updated_at) as last_update FROM stock_mirror WHERE location_code = '$kode_homecare_esc'");
                    if ($res_uh && $res_uh->num_rows > 0) {
                        $urow = $res_uh->fetch_assoc();
                        if (!empty($urow['last_update'])) $last_update_hc = (string)$urow['last_update'];
                    }
                }
                $max_u = $last_update_klinik;
                if ($last_update_hc !== '' && ($max_u === '' || strtotime($last_update_hc) > strtotime($max_u))) $max_u = $last_update_hc;
                if ($max_u !== '') $last_update_text = date('d M Y H:i', strtotime($max_u));
            }

            if ($kode_klinik !== '') {
                $last_update_klinik_date = $last_update_klinik !== '' ? date('Y-m-d', strtotime($last_update_klinik)) : '';
                $last_update_hc_date = $last_update_hc !== '' ? date('Y-m-d', strtotime($last_update_hc)) : '';

                if ($is_history_date) {
                    $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
                    $filter_bp_hc = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($filter_date) . "' AND bp.tanggal_pemeriksaan <= '" . $conn->real_escape_string($month_end) . "'";
                    $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . "'";
                    $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "' AND pb.tanggal <= '" . $conn->real_escape_string($filter_date) . "'";
                    $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "' AND ts.created_at <= '" . $conn->real_escape_string($filter_end_ts) . "'";
                    $filter_ts_hc = $filter_ts_klinik;
                } else {
                    $filter_bp_onsite = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($month_start) . "'";
                    if ($last_update_klinik_date !== '') $filter_bp_onsite .= " AND bp.tanggal_pemeriksaan > '" . $conn->real_escape_string($last_update_klinik_date) . "'";

                    $filter_bp_hc = " AND bp.tanggal_pemeriksaan >= '" . $conn->real_escape_string($month_start) . "'";
                    if ($last_update_hc_date !== '') $filter_bp_hc .= " AND bp.tanggal_pemeriksaan > '" . $conn->real_escape_string($last_update_hc_date) . "'";

                    $filter_pb_klinik = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "'";
                    if ($last_update_klinik_date !== '') $filter_pb_klinik .= " AND pb.tanggal > '" . $conn->real_escape_string($last_update_klinik_date) . "'";

                    $filter_pb_hc = " AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "'";
                    if ($last_update_hc_date !== '') $filter_pb_hc .= " AND pb.tanggal > '" . $conn->real_escape_string($last_update_hc_date) . "'";

                    $filter_ts_klinik = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "'";
                    if ($last_update_klinik !== '') $filter_ts_klinik .= " AND ts.created_at > '" . $conn->real_escape_string($last_update_klinik) . "'";
                    $filter_ts_hc = " AND ts.created_at >= '" . $conn->real_escape_string($month_start_ts) . "'";
                    if ($last_update_hc !== '') $filter_ts_hc .= " AND ts.created_at > '" . $conn->real_escape_string($last_update_hc) . "'";
                }

                $filter_pb2_klinik = str_replace("pb.", "pb2.", $filter_pb_klinik);
                $filter_pb2_hc = str_replace("pb.", "pb2.", $filter_pb_hc);

                $rb_in_transfer_sql = "0";
                $rb_out_transfer_sql = "0";
                $rb_sellout_klinik_sql = "0";
                $rb_sellout_hc_sql = "0";
                if ($is_history_date && $last_update_klinik !== '' && strtotime($filter_end_ts) < strtotime($last_update_klinik)) {
                    $rb_ts_start = $conn->real_escape_string($filter_end_ts);
                    $rb_ts_end = $conn->real_escape_string($last_update_klinik);
                    $rb_ts_min = $conn->real_escape_string($month_start_ts);
                    $rb_in_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0)
                                            FROM transaksi_stok ts
                                            WHERE ts.barang_id = b.id
                                            AND ts.level = 'klinik'
                                            AND ts.level_id = $selected_klinik_id
                                            AND ts.tipe_transaksi = 'in'
                                            AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')
                                            AND ts.created_at > '$rb_ts_start'
                                            AND ts.created_at <= '$rb_ts_end'
                                            AND ts.created_at >= '$rb_ts_min')";
                    $rb_out_transfer_sql = "(SELECT COALESCE(SUM(ts.qty), 0)
                                             FROM transaksi_stok ts
                                             WHERE ts.barang_id = b.id
                                             AND ts.level = 'klinik'
                                             AND ts.level_id = $selected_klinik_id
                                             AND ts.tipe_transaksi = 'out'
                                             AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')
                                             AND ts.created_at > '$rb_ts_start'
                                             AND ts.created_at <= '$rb_ts_end'
                                             AND ts.created_at >= '$rb_ts_min')";
                    $rb_sellout_klinik_sql = "(SELECT COALESCE(SUM(pbd.qty), 0)
                                              FROM pemakaian_bhp_detail pbd
                                              JOIN pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
                                              WHERE pbd.barang_id = b.id
                                              AND pb.klinik_id = $selected_klinik_id
                                              AND pb.jenis_pemakaian != 'hc'
                                              AND pb.tanggal > '" . $conn->real_escape_string($filter_date) . "'
                                              AND pb.tanggal <= '" . $conn->real_escape_string($last_update_klinik_date) . "'
                                              AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "')";
                }
                if ($is_history_date && $last_update_hc !== '' && strtotime($filter_end_ts) < strtotime($last_update_hc)) {
                    $rb_sellout_hc_sql = "(SELECT COALESCE(SUM(pbd.qty), 0)
                                          FROM pemakaian_bhp_detail pbd
                                          JOIN pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
                                          WHERE pbd.barang_id = b.id
                                          AND pb.klinik_id = $selected_klinik_id
                                          AND pb.jenis_pemakaian = 'hc'
                                          AND pb.tanggal > '" . $conn->real_escape_string($filter_date) . "'
                                          AND pb.tanggal <= '" . $conn->real_escape_string($last_update_hc_date) . "'
                                          AND pb.tanggal >= '" . $conn->real_escape_string($month_start) . "')";
                }

                $union_sql = "SELECT odoo_product_id, kode_barang FROM stock_mirror WHERE location_code = '$kode_klinik_esc'";
                if ($show_hc && $kode_homecare !== '') {
                    $union_sql .= " UNION SELECT odoo_product_id, kode_barang FROM stock_mirror WHERE location_code = '$kode_homecare_esc'";
                }

                $query = "SELECT 
                            $selected_klinik_id as klinik_id,
                            '$kode_klinik_esc' as kode_klinik,
                            '$kode_homecare_esc' as kode_homecare,
                            k.nama_klinik,
                            p.odoo_product_id,
                            p.kode_barang,
                            b.id as barang_id,
                            b.kode_barang as kode_barang_master,
                            COALESCE(b.nama_barang, p.kode_barang) as nama_barang,
                            COALESCE(b.satuan, '') as satuan,
                            COALESCE(uc.from_uom, '') as uom_odoo,
                            COALESCE(uc.multiplier, 1) as uom_multiplier,
                            COALESCE(sm_k.qty, 0) * COALESCE(uc.multiplier, 1) as qty,
                            COALESCE(sm_h.qty, 0) * COALESCE(uc.multiplier, 1) as stok_hc,
                            (SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END), 0)
                             FROM booking_detail bd 
                             JOIN booking_pemeriksaan bp ON bd.booking_id = bp.id 
                             WHERE bd.barang_id = b.id 
                             AND bp.klinik_id = $selected_klinik_id
                             AND bp.status = 'booked'
                             AND bp.status_booking LIKE '%Clinic%'$filter_bp_onsite) as reserve_onsite,
                            (SELECT COALESCE(SUM(CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END), 0)
                             FROM booking_detail bd
                             JOIN booking_pemeriksaan bp ON bd.booking_id = bp.id
                             WHERE bd.barang_id = b.id
                             AND bp.klinik_id = $selected_klinik_id
                             AND bp.status = 'booked'
                             AND bp.status_booking LIKE '%HC%'$filter_bp_hc) as reserve_hc,
                            COALESCE(
                                NULLIF(
                                    (SELECT COALESCE(SUM(pbd.qty), 0) 
                                     FROM pemakaian_bhp_detail pbd 
                                     JOIN pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id 
                                     WHERE pbd.barang_id = b.id AND pb.klinik_id = $selected_klinik_id AND pb.jenis_pemakaian != 'hc'$filter_pb_klinik),
                                    0
                                ),
                                (SELECT COALESCE(SUM(ts.qty), 0)
                                 FROM transaksi_stok ts
                                 JOIN pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                                 WHERE ts.barang_id = b.id
                                 AND ts.level = 'klinik'
                                 AND ts.level_id = $selected_klinik_id
                                 AND ts.tipe_transaksi = 'out'
                                 AND ts.referensi_tipe = 'pemakaian_bhp'
                                 AND pb2.klinik_id = $selected_klinik_id
                                 AND pb2.jenis_pemakaian != 'hc'$filter_pb2_klinik),
                                0
                            ) as sellout_klinik,
                            COALESCE(
                                NULLIF(
                                    (SELECT COALESCE(SUM(pbd.qty), 0) 
                                     FROM pemakaian_bhp_detail pbd 
                                     JOIN pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id 
                                     WHERE pbd.barang_id = b.id AND pb.klinik_id = $selected_klinik_id AND pb.jenis_pemakaian = 'hc'$filter_pb_hc),
                                    0
                                ),
                                (SELECT COALESCE(SUM(ts.qty), 0)
                                 FROM transaksi_stok ts
                                 JOIN pemakaian_bhp pb2 ON pb2.id = ts.referensi_id
                                 WHERE ts.barang_id = b.id
                                 AND ts.level = 'hc'
                                 AND ts.level_id = $selected_klinik_id
                                 AND ts.tipe_transaksi = 'out'
                                 AND ts.referensi_tipe = 'pemakaian_bhp'
                                 AND pb2.klinik_id = $selected_klinik_id
                                 AND pb2.jenis_pemakaian = 'hc'$filter_pb2_hc),
                                0
                            ) as sellout_hc,
                            (SELECT COALESCE(SUM(ts.qty), 0)
                             FROM transaksi_stok ts
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'klinik'
                             AND ts.level_id = $selected_klinik_id
                             AND ts.tipe_transaksi = 'in'
                             AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik) as in_transfer,
                            (SELECT COALESCE(SUM(ts.qty), 0)
                             FROM transaksi_stok ts
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'klinik'
                             AND ts.level_id = $selected_klinik_id
                             AND ts.tipe_transaksi = 'out'
                             AND ts.referensi_tipe IN ('transfer','hc_petugas_transfer')$filter_ts_klinik) as out_transfer,
                            (SELECT COALESCE(SUM(ts.qty), 0)
                             FROM transaksi_stok ts
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'hc'
                             AND ts.level_id = $selected_klinik_id
                             AND ts.tipe_transaksi = 'in'
                             AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as in_transfer_hc,
                            (SELECT COALESCE(SUM(ts.qty), 0)
                             FROM transaksi_stok ts
                             WHERE ts.barang_id = b.id
                             AND ts.level = 'hc'
                             AND ts.level_id = $selected_klinik_id
                             AND ts.tipe_transaksi = 'out'
                             AND ts.referensi_tipe = 'hc_petugas_transfer'$filter_ts_hc) as out_transfer_hc,
                            $rb_in_transfer_sql as rb_in_transfer,
                            $rb_out_transfer_sql as rb_out_transfer,
                            $rb_sellout_klinik_sql as rb_sellout_klinik,
                            $rb_sellout_hc_sql as rb_sellout_hc
                          FROM ($union_sql) p
                          LEFT JOIN stock_mirror sm_k ON sm_k.odoo_product_id = p.odoo_product_id AND sm_k.location_code = '$kode_klinik_esc'
                          LEFT JOIN stock_mirror sm_h ON sm_h.odoo_product_id = p.odoo_product_id AND sm_h.location_code = '$kode_homecare_esc'
                          LEFT JOIN barang b ON (b.odoo_product_id = p.odoo_product_id OR b.kode_barang = p.kode_barang)
                          LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
                          JOIN klinik k ON k.id = $selected_klinik_id
                          WHERE 1=1";

                if ($selected_pemeriksaan) {
                    $item_ids = [];
                    $res_ids = $conn->query("SELECT barang_id FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = " . (int)$selected_pemeriksaan);
                    while($r = $res_ids->fetch_assoc()) $item_ids[] = (int)$r['barang_id'];
                    if (!empty($item_ids)) {
                        $ids_str = implode(',', $item_ids);
                        $query .= " AND (b.id IN ($ids_str))";
                    } else {
                        $query .= " AND 1=0";
                    }
                }

                $query .= " ORDER BY nama_barang ASC";
                $result = $conn->query($query);
                while ($r = $result->fetch_assoc()) {
                    if ($is_history_date) {
                        $r['sellout_klinik'] = 0;
                        $r['sellout_hc'] = 0;
                        $r['in_transfer'] = 0;
                        $r['out_transfer'] = 0;
                        $r['in_transfer_hc'] = 0;
                        $r['out_transfer_hc'] = 0;
                    }
                    $rows[] = $r;
                    $summary_stok['total_items']++;
                    if ($is_history_date) {
                        $adj_qty = (float)($r['qty'] ?? 0) + (float)($r['rb_out_transfer'] ?? 0) - (float)($r['rb_in_transfer'] ?? 0) + (float)($r['rb_sellout_klinik'] ?? 0);
                        $adj_hc = (float)($r['stok_hc'] ?? 0) + (float)($r['rb_sellout_hc'] ?? 0);
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
            }
        }
        
    }
}


?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-hospital me-2"></i><?= $is_cs ? 'Ketersediaan Pemeriksaan' : 'Inventory Klinik' ?>
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
                    <?php foreach ($kliniks as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>>
                            <?= $k['nama_klinik'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label fw-bold small mb-1">
                    <i class="fas fa-calendar-alt text-primary"></i> Tanggal
                </label>
                <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" min="<?= htmlspecialchars($min_filter_date) ?>" max="<?= htmlspecialchars($today_date) ?>" onchange="this.form.submit()">
            </div>
            <?php if (!in_array($_SESSION['role'], ['cs','admin_klinik'])): ?>
            <div class="col-md-6">
                <div class="d-flex flex-column align-items-end">
                    <button type="button" class="btn btn-outline-primary refresh-btn d-flex align-items-center justify-content-center gap-2" onclick="syncFromOdoo(this)" <?= $is_history_date ? 'disabled' : '' ?>>
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
<?php if (!$is_cs && $selected_klinik): ?>
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
    <?php if ($show_hc && !empty($selected_klinik_row['kode_homecare'])): ?>
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
    <?php if ($show_hc && !empty($selected_klinik_row['kode_homecare'])): ?>
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
    var n = Number(v || 0);
    if (Number.isInteger(n)) return String(n);
    var s = n.toFixed(4);
    s = s.replace(/0+$/,'').replace(/\.$/,'');
    return s === '' ? '0' : s;
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
        var reserve = res.reserve || {};
        var reserveOn = reserve.onsite || 0;
        var reserveHc = reserve.hc || 0;
        var result = res.result || {};

        var pemRows = pem.length ? pem.map(function(p) {
            var jenis = (p.jenis_pemakaian === 'hc') ? 'HC' : 'Klinik';
            return '<tr><td>' + $('<div>').text(p.nomor_pemakaian || '').html() + '</td><td>' + $('<div>').text(p.tanggal || '').html() + '</td><td>' + jenis + '</td><td class="text-end fw-semibold">' + fmtNum(p.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada</td></tr>';

        var trfRows = trf.length ? trf.map(function(t) {
            return '<tr><td class="text-muted">Transfer #' + (t.transfer_id || '-') + '</td><td>' + (t.tipe_transaksi || '-') + '</td><td>' + $('<div>').text(t.last_at || '').html() + '</td><td class="text-end fw-semibold">' + fmtNum(t.qty || 0) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-2">Tidak ada</td></tr>';

        body.innerHTML = `
            <div class="mb-2">
                <div class="small text-muted">Barang</div>
                <div class="fw-semibold">${$('<div>').text((b.kode_barang || '-') + ' - ' + (b.nama_barang || namaBarang)).html()}</div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <div class="small text-muted">Tanggal yang ditampilkan</div>
                        <div class="fw-semibold">${$('<div>').text(window.__stokKlinikContext.tanggalLabel).html()}</div>
                        <div class="small text-muted mt-2">Terakhir update Odoo</div>
                        <div class="fw-semibold">${$('<div>').text(lastU).html()}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <div class="small text-muted">Rumus (Onsite)</div>
                        <div class="fw-semibold">${fmtNum(result.stock_onsite || 0)}</div>
                        <div class="small text-muted mt-2">Baseline Odoo (Onsite)</div>
                        <div class="fw-semibold">${fmtNum(baseOn)}</div>
                        <div class="small text-muted mt-2">Rollback setelah ${$('<div>').text(window.__stokKlinikContext.tanggal).html()}</div>
                        <div class="small">
                            + Out Transfer: <span class="fw-semibold">${fmtNum(rb.out_transfer || 0)}</span><br>
                            - In Transfer: <span class="fw-semibold">${fmtNum(rb.in_transfer || 0)}</span><br>
                            + Sellout Klinik: <span class="fw-semibold">${fmtNum(rb.sellout_klinik || 0)}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <div class="small text-muted">HC</div>
                        <div class="small">
                            Baseline Odoo (HC): <span class="fw-semibold">${fmtNum(baseHc)}</span><br>
                            + Sellout HC (rollback): <span class="fw-semibold">${fmtNum(rb.sellout_hc || 0)}</span><br>
                            Stock HC hasil: <span class="fw-semibold">${fmtNum(result.stock_hc || 0)}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <div class="small text-muted">Reserve (berdasarkan tanggal reservasi)</div>
                        <div class="small">
                            Onsite: <span class="fw-semibold">${fmtNum(reserveOn)}</span><br>
                            HC: <span class="fw-semibold">${fmtNum(reserveHc)}</span><br>
                            Tersedia: <span class="fw-semibold">${fmtNum(result.tersedia || 0)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-2 fw-semibold">Transaksi pemakaian yang dibalik (tanggal > ${$('<div>').text(window.__stokKlinikContext.tanggal).html()} sampai last update)</div>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>No. Pemakaian</th><th>Tanggal</th><th>Jenis</th><th class="text-end">Qty</th></tr></thead>
                    <tbody>${pemRows}</tbody>
                </table>
            </div>

            <div class="mb-2 fw-semibold">Transfer yang dibalik (created_at > tanggal sampai last update)</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>Referensi</th><th>Tipe</th><th>Waktu</th><th class="text-end">Qty</th></tr></thead>
                    <tbody>${trfRows}</tbody>
                </table>
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
        <?php if ($is_cs): ?>
            <?php if (!$selected_klinik): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Silakan pilih klinik terlebih dahulu untuk melihat ketersediaan pemeriksaan.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Nama Pemeriksaan</th>
                                <th>Keterangan</th>
                                <th>Status</th>
                                <th>Kapasitas (Pasien)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($exams)): ?>
                                <tr><td colspan="4" class="text-center">Tidak ada pemeriksaan yang available saat ini.</td></tr>
                            <?php else: ?>
                                <?php foreach ($exams as $ex): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ex['nama_pemeriksaan']) ?></td>
                                    <td><?= htmlspecialchars($ex['keterangan']) ?></td>
                                    <td><span class="badge bg-success">Available</span></td>
                                    <td class="fw-bold fs-5"><?= $ex['max_qty'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        
        <?php else: ?>
            <!-- Normal Table for Non-CS -->
            <?php if (!$selected_klinik): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Silakan pilih klinik terlebih dahulu untuk melihat data stok.
                </div>
            <?php else: ?>
            <?php if (empty($rows)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Data stok dari Odoo belum tersedia. Klik <b>Refresh dari Odoo</b> untuk menarik data.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover datatable">
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
                            <th>Tersedia</th>
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
                                $stok_hc = $stok_hc + (float)($row['rb_sellout_hc'] ?? 0);
                            } else {
                                $stok_onsite = $stok_onsite + (float)$in_transfer - (float)$out_transfer;
                                $stok_hc = $stok_hc + (float)$in_transfer_hc - (float)$out_transfer_hc;
                            }
                            
                            $total_stok = $stok_onsite + ($show_hc ? $stok_hc : 0);
                            if ($is_history_date) {
                                $tersedia = $total_stok - $reserve_total;
                            } else {
                                $total_sellout = $sellout + ($show_hc ? $sellout_hc : 0);
                                $tersedia = $total_stok - $total_sellout - $reserve_total;
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(!empty($row['kode_barang_master']) ? $row['kode_barang_master'] : ($row['kode_barang'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($row['nama_barang']) ?></td>
                            <td class="small">
                                <div><?= htmlspecialchars($row['satuan']) ?></div>
                                <?php if (!empty($row['uom_odoo']) && (float)($row['uom_multiplier'] ?? 1) != 1.0): ?>
                                    <div class="text-muted small">Odoo: <?= htmlspecialchars($row['uom_odoo']) ?> × <?= htmlspecialchars(fmt_qty($row['uom_multiplier'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?= fmt_qty($stok_onsite) ?></div>
                                <?php if (!$is_history_date && (((float)$in_transfer) > 0 || ((float)$out_transfer) > 0)): ?>
                                    <div class="text-muted small">IN: <?= fmt_qty($in_transfer) ?> | OUT: <?= fmt_qty($out_transfer) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td>
                                <?php if ($stok_hc > 0): ?>
                                    <a href="javascript:void(0)" class="text-primary fw-bold text-decoration-none"
                                       onclick="loadHCDetail(<?= $row['barang_id'] ?>, <?= $row['klinik_id'] ?>, '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>'); return false;">
                                        <?= fmt_qty($stok_hc) ?> <i class="fas fa-user-nurse"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">0</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="<?= $sellout > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($sellout) ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="<?= $sellout_hc > 0 ? 'text-danger fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($sellout_hc) ?>
                            </td>
                            <?php endif; ?>
                            <td class="<?= $reserve > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($reserve) ?>
                            </td>
                            <?php if ($show_hc): ?>
                            <td class="<?= $reserve_hc > 0 ? 'text-warning fw-bold' : 'text-muted small' ?>">
                                <?= fmt_qty($reserve_hc) ?>
                            </td>
                            <?php endif; ?>
                            <td class="<?= $tersedia < 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                                <?= fmt_qty($tersedia) ?>
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
    var modal = new bootstrap.Modal(document.getElementById('modalHCDetail'));
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

async function syncFromOdoo(btn) {
    const statusEl = document.getElementById('syncStatus');
    const lastEl = document.getElementById('lastUpdateText');
    statusEl.textContent = 'Sinkronisasi berjalan...';
    btn.disabled = true;
    try {
        const res = await fetch('api/sync_odoo.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            statusEl.textContent = `Selesai. Produk: ${data.products}, Lokasi: ${data.locations}, Baris: ${data.rows}`;
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
</script>
