<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Mendapatkan rate penggunaan harian (Auto/Manual)
 */
function get_daily_usage_rate($klinik_id, $barang_id) {
    global $conn;
    $kid = (int)$klinik_id;
    $bid = (int)$barang_id;
    
    $res = $conn->query("SELECT id, mode, manual_value, last_calculated_rate FROM inventory_daily_usage_config WHERE klinik_id = $kid AND barang_id = $bid");
    if ($res && ($row = $res->fetch_assoc())) {
        if ($row['mode'] === 'manual') {
            return round((float)$row['manual_value'], 0);
        }
        return round((float)$row['last_calculated_rate'], 0);
    } else {
        // Create default entry if not exists
        $conn->query("INSERT IGNORE INTO inventory_daily_usage_config (klinik_id, barang_id, mode) VALUES ($kid, $bid, 'auto')");
    }
    
    return 0;
}

/**
 * Mengecek apakah sebuah tanggal adalah hari operasional untuk klinik tertentu
 */
function is_operational_day($klinik_id, $date) {
    global $conn;
    $kid = (int)$klinik_id;
    $d = $conn->real_escape_string($date);
    $day_of_week = (int)date('w', strtotime($date)); // 0=Sun, 6=Sat

    // 1. Cek Override Kalender (Libur/Event khusus)
    $res_cal = $conn->query("SELECT is_operational FROM inventory_operational_calendar WHERE klinik_id = $kid AND date = '$d'");
    if ($res_cal && ($row = $res_cal->fetch_assoc())) {
        return (bool)$row['is_operational'];
    }

    // 2. Cek Jadwal Rutin Mingguan
    $res_sch = $conn->query("SELECT is_open FROM inventory_operational_schedule WHERE klinik_id = $kid AND day_of_week = $day_of_week");
    if ($res_sch && ($row = $res_sch->fetch_assoc())) {
        return (bool)$row['is_open'];
    }

    // 3. Default: Buka
    return true;
}

/**
 * Menghitung akumulasi pemakaian harian yang belum ter-realisasi
 */
function calculate_accumulated_usage($klinik_id, $barang_id) {
    global $conn;
    $bid = (int)$barang_id;

    if ($klinik_id === 'all') {
        $total = 0;
        $res_k = $conn->query("SELECT id FROM inventory_klinik WHERE status='active'");
        while ($k = $res_k->fetch_assoc()) {
            $total += calculate_accumulated_usage($k['id'], $bid);
        }
        return $total;
    }

    $kid = (int)$klinik_id;
    if ($kid <= 0) return 0;
    
    // 1. Cari tanggal terakhir realisasi (Tanggal Pakai BHP Manual / Non-Reserve)
    // Kita abaikan pemakaian otomatis dari booking (is_auto=1)
    $res_last = $conn->query("SELECT MAX(tanggal) as last_date FROM inventory_pemakaian_bhp pb 
                             JOIN inventory_pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
                             WHERE pb.klinik_id = $kid AND pbd.barang_id = $bid AND pb.status = 'active'
                             AND (pb.is_auto = 0 OR pb.is_auto IS NULL)");
    $last_date = ($res_last && ($row = $res_last->fetch_assoc())) ? $row['last_date'] : null;

    // Jika belum pernah ada pemakaian, kita tidak bisa menghitung akumulasi (atau bisa di-set mulai dari awal bulan)
    if (!$last_date) {
        // Alternatif: Mulai dari awal bulan ini
        $last_date = date('Y-m-01', strtotime('-1 day'));
    }

    $rate = get_daily_usage_rate($kid, $bid);
    if ($rate <= 0) return 0;

    $today = date('Y-m-d');
    $accumulated = 0;
    
    // Iterasi dari H+1 realization sampai hari ini
    $current = date('Y-m-d', strtotime($last_date . ' +1 day'));
    
    while ($current <= $today) {
        if (is_operational_day($kid, $current)) {
            $accumulated += $rate;
        }
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }

    return (float)round($accumulated, 0);
}

/**
 * Sinkronisasi angka Auto Daily Usage berdasarkan data bulan lalu
 */
function sync_daily_usage_auto($target_month = null, $target_year = null) {
    global $conn;
    
    // Jika tidak ditentukan, ambil bulan lalu
    if (!$target_month) $target_month = (int)date('n', strtotime('first day of last month'));
    if (!$target_year) $target_year = (int)date('Y', strtotime('first day of last month'));

    $first_day = "$target_year-" . str_pad($target_month, 2, '0', STR_PAD_LEFT) . "-01";
    $last_day = date('Y-m-t', strtotime($first_day));

    // 1. Hitung total hari operasional per klinik di bulan tersebut
    $operational_days_map = [];
    $res_k = $conn->query("SELECT id FROM inventory_klinik WHERE status='active'");
    while($k = $res_k->fetch_assoc()) {
        $kid = (int)$k['id'];
        $days = 0;
        $curr = $first_day;
        while($curr <= $last_day) {
            if (is_operational_day($kid, $curr)) $days++;
            $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
        }
        $operational_days_map[$kid] = $days ?: 1; // Avoid division by zero
    }

    // 2. Ambil data pemakaian Non-Reserve bulan tersebut
    // Logic: Total Sellout - Reserve Sold (Sama dengan monthly_summary.php)
    
    // a. Total Sellout
    $sellout_query = "
        SELECT pb.klinik_id, pbd.barang_id, SUM(pbd.qty) as total_qty
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pbd.pemakaian_bhp_id = pb.id
        WHERE pb.status = 'active' AND pb.tanggal BETWEEN '$first_day' AND '$last_day'
        GROUP BY pb.klinik_id, pbd.barang_id
    ";
    $res_s = $conn->query($sellout_query);
    $data_map = [];
    while($row = $res_s->fetch_assoc()) {
        $data_map[$row['klinik_id']][$row['barang_id']]['sellout'] = (float)$row['total_qty'];
    }

    // b. Reserve Sold
    $reserve_query = "
        SELECT bp.klinik_id, pgd.barang_id, SUM(pgd.qty_per_pemeriksaan) as total_qty
        FROM inventory_booking_pasien p
        JOIN inventory_booking_pemeriksaan bp ON p.booking_id = bp.id
        JOIN inventory_pemeriksaan_grup_detail pgd ON p.pemeriksaan_grup_id = pgd.pemeriksaan_grup_id
        WHERE bp.tanggal_pemeriksaan BETWEEN '$first_day' AND '$last_day' AND p.status = 'done'
        GROUP BY bp.klinik_id, pgd.barang_id
    ";
    $res_r = $conn->query($reserve_query);
    while($row = $res_r->fetch_assoc()) {
        $data_map[$row['klinik_id']][$row['barang_id']]['reserve'] = (float)$row['total_qty'];
    }

    // 3. Update Config Table
    foreach($data_map as $kid => $items) {
        $op_days = $operational_days_map[$kid] ?? 1;
        foreach($items as $bid => $vals) {
            $sellout = $vals['sellout'] ?? 0;
            $reserve = $vals['reserve'] ?? 0;
            $non_reserve = max(0, $sellout - $reserve);
            $rate = round($non_reserve / $op_days, 2); // Simpan desimal sedikit di DB, baru di round(0) saat display

            $stmt = $conn->prepare("INSERT INTO inventory_daily_usage_config (klinik_id, barang_id, last_calculated_rate) 
                                   VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE last_calculated_rate = VALUES(last_calculated_rate)");
            $stmt->bind_param("iid", $kid, $bid, $rate);
            $stmt->execute();
        }
    }

    // Update last sync setting
    $sync_time = date('Y-m-d H:i:s');
    $conn->query("UPDATE inventory_app_settings SET v = '$sync_time' WHERE k = 'daily_usage_auto_last_sync'");
    
    return true;
}
function maybe_auto_sync() {
    require_once __DIR__ . '/../config/settings.php';
    
    $current_month = date('Y-m'); // e.g. "2026-05"
    $last_sync_month = get_setting('daily_usage_auto_last_sync_month', '');

    if ($last_sync_month !== $current_month) {
        // First, update the flag to prevent concurrent triggers if multiple users visit at once
        // (Though basic, this reduces redundant work)
        set_setting('daily_usage_auto_last_sync_month', $current_month);
        
        // Trigger sync for the previous month
        sync_daily_usage_auto();
        
        set_setting('daily_usage_auto_last_sync', date('Y-m-d H:i:s'));
    }
}
