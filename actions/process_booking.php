<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check role
if (!in_array($_SESSION['role'], ['cs', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $status_booking = $_POST['status_booking'] ?? '';
    $klinik_id = intval($_POST['klinik_id'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? '';
    $order_id = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
    $booking_type = (string)($_POST['booking_type'] ?? 'keep');
    $jam_layanan = $_POST['jam_layanan'] ?? null;
    $jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;
    $nama_pemesan = $_POST['nama_pemesan'] ?? '';
    $nomor_tlp = !empty($_POST['nomor_tlp']) ? trim((string)$_POST['nomor_tlp']) : null;
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? (string)$_POST['tanggal_lahir'] : null;
    $jumlah_pax = intval($_POST['jumlah_pax'] ?? 1);
    $catatan = !empty($_POST['catatan']) ? $_POST['catatan'] : null;
    $exams = $_POST['exams'] ?? [];
    $created_by = $_SESSION['user_id'];

    if (empty($exams)) {
        throw new Exception('Minimal 1 pemeriksaan!');
    }

    if (empty($status_booking) || empty($klinik_id) || empty($tanggal) || empty($nama_pemesan)) {
        throw new Exception('Data tidak lengkap!');
    }

    $uid = (int)$created_by;
    $cs_name = '';
    $ruser = $conn->query("SELECT nama_lengkap FROM users WHERE id = $uid LIMIT 1");
    if ($ruser && $ruser->num_rows > 0) $cs_name = (string)($ruser->fetch_assoc()['nama_lengkap'] ?? '');

    $ensure_col = function(string $table, string $column, string $definition) use ($conn) {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
        }
    };
    $ensure_col('booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL");
    $ensure_col('booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL");
    $ensure_col('booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
    $ensure_col('booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL");
    $ensure_col('booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL");
    $ensure_col('booking_pemeriksaan', 'tanggal_lahir', "DATE NULL");

    $conn->begin_transaction();

    // 1. Calculate TOTAL needed items
    $total_needed = [];
    foreach ($exams as $exam) {
        if (empty($exam['pemeriksaan_id']) || empty($exam['qty'])) continue;
        
        $pid = intval($exam['pemeriksaan_id']);
        $qty_multiplier = intval($exam['qty']);
        
        $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
        if (!$res) {
            throw new Exception("Error query pemeriksaan: " . $conn->error);
        }
        
        while($row = $res->fetch_assoc()) {
            $bid = intval($row['barang_id']);
            $qty = intval($row['qty_per_pemeriksaan']) * $qty_multiplier;
            if (!isset($total_needed[$bid])) $total_needed[$bid] = 0;
            $total_needed[$bid] += $qty;
        }
    }

    if (empty($total_needed)) {
        throw new Exception("Tidak ada item yang perlu dibooking");
    }

    // 2. Check Availability from Odoo mirror (stock_mirror)
    $klin = $conn->query("SELECT kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    $kode_klinik = (string)($klin['kode_klinik'] ?? '');
    $kode_homecare = (string)($klin['kode_homecare'] ?? '');
    $is_hc = (stripos((string)$status_booking, 'HC') !== false);
    $location_code = $is_hc ? $kode_homecare : $kode_klinik;
    if ($location_code === '') {
        throw new Exception('Kode lokasi Odoo untuk klinik ini belum diisi');
    }
    $loc_esc = $conn->real_escape_string($location_code);

    foreach ($total_needed as $bid => $qty_need) {
        $b = $conn->query("SELECT nama_barang, odoo_product_id FROM barang WHERE id = $bid LIMIT 1")->fetch_assoc();
        $bname = (string)($b['nama_barang'] ?? ("ID:$bid"));
        $odoo_pid = (string)($b['odoo_product_id'] ?? '');
        if ($odoo_pid === '') {
            throw new Exception("Produk $bname belum punya odoo_product_id");
        }
        $pid_esc = $conn->real_escape_string($odoo_pid);
        $rq = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE odoo_product_id = '$pid_esc' AND location_code = '$loc_esc' LIMIT 1");
        $rowq = $rq ? $rq->fetch_assoc() : null;
        $available = (int)floor((float)($rowq['qty'] ?? 0));
        if ($available < (int)$qty_need) {
            throw new Exception("Stok $bname tidak cukup. Tersedia: $available, Butuh: $qty_need");
        }
    }

    // 3. Create Booking Header
    $nomor = "BK-TMP-" . time();
    
    $sql = "INSERT INTO booking_pemeriksaan 
            (nomor_booking, order_id, klinik_id, status_booking, booking_type, jam_layanan, jotform_submitted, cs_name, nama_pemesan, nomor_tlp, tanggal_lahir, jumlah_pax, catatan, tanggal_pemeriksaan, status, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error prepare booking: " . $conn->error);
    }
    
    $stmt->bind_param("ssisssissssissi", $nomor, $order_id, $klinik_id, $status_booking, $booking_type, $jam_layanan, $jotform_submitted, $cs_name, $nama_pemesan, $nomor_tlp, $tanggal_lahir, $jumlah_pax, $catatan, $tanggal, $created_by);
    
    if (!$stmt->execute()) {
        throw new Exception("Error insert booking: " . $stmt->error);
    }
    
    $book_id = $conn->insert_id;
    $nomor_final = "BK-" . str_pad((string)$book_id, 6, '0', STR_PAD_LEFT);
    $stmt_up = $conn->prepare("UPDATE booking_pemeriksaan SET nomor_booking = ? WHERE id = ?");
    if (!$stmt_up) throw new Exception("Error prepare update nomor: " . $conn->error);
    $stmt_up->bind_param("si", $nomor_final, $book_id);
    if (!$stmt_up->execute()) throw new Exception("Error update nomor: " . $stmt_up->error);

    // 4. Insert Patients + Details (per exam)
    $stmt_pasien = $conn->prepare("INSERT INTO booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id) VALUES (?, ?, ?)");
    if (!$stmt_pasien) {
        throw new Exception("Error prepare pasien: " . $conn->error);
    }
    $stmt_detail = $conn->prepare("INSERT INTO booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt_detail) {
        throw new Exception("Error prepare detail: " . $conn->error);
    }

    foreach ($exams as $exam) {
        $pid = intval($exam['pemeriksaan_id'] ?? 0);
        $qty_multiplier = intval($exam['qty'] ?? 0);
        if ($pid <= 0 || $qty_multiplier <= 0) continue;

        $stmt_pasien->bind_param("isi", $book_id, $nama_pemesan, $pid);
        $stmt_pasien->execute();
        $pasien_id = (int)$conn->insert_id;

        $items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
        while ($items && ($row = $items->fetch_assoc())) {
            $barang_id = (int)$row['barang_id'];
            $qty_total = (int)$row['qty_per_pemeriksaan'] * $qty_multiplier;
            if ($qty_total <= 0) continue;

            $qty_reserved_onsite = 0;
            $qty_reserved_hc = 0;
            if (stripos((string)$status_booking, 'Clinic') !== false) {
                $qty_reserved_onsite = $qty_total;
            } elseif (stripos((string)$status_booking, 'HC') !== false) {
                $qty_reserved_hc = $qty_total;
            }

            $stmt_detail->bind_param("iiiiii", $book_id, $pasien_id, $barang_id, $qty_total, $qty_reserved_onsite, $qty_reserved_hc);
            $stmt_detail->execute();
        }
    }

    $conn->commit();

    // Async notify Google Sheets (Apps Script) if configured
    try {
        $webhook = trim((string)get_setting('gsheet_booking_webhook_url', ''));
        if ($webhook !== '') {
            $klin_nm = '';
            $rk = $conn->query("SELECT nama_klinik FROM klinik WHERE id = $klinik_id LIMIT 1");
            if ($rk && $rk->num_rows > 0) $klin_nm = (string)($rk->fetch_assoc()['nama_klinik'] ?? '');
            $payload = [
                'event' => 'booking_created',
                'nomor_booking' => $nomor_final,
                'tanggal_pemeriksaan' => $tanggal,
                'jam_layanan' => $jam_layanan,
                'status_booking' => $status_booking,
                'booking_type' => $booking_type,
                'klinik_id' => (int)$klinik_id,
                'klinik_nama' => $klin_nm,
                'cs_name' => $cs_name,
                'nama_pemesan' => $nama_pemesan,
                'nomor_tlp' => $nomor_tlp,
                'tanggal_lahir' => $tanggal_lahir,
                'jumlah_pax' => (int)$jumlah_pax,
                'jotform_submitted' => (int)$jotform_submitted,
                'created_by' => (int)$created_by,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            // Include exams summary
            $ex_summary = [];
            foreach ($exams as $exam) {
                if (empty($exam['pemeriksaan_id']) || empty($exam['qty'])) continue;
                $pid = (int)$exam['pemeriksaan_id'];
                $qty = (int)$exam['qty'];
                $nm = '';
                $re = $conn->query("SELECT nama_pemeriksaan FROM pemeriksaan_grup WHERE id = $pid LIMIT 1");
                if ($re && $re->num_rows > 0) $nm = (string)($re->fetch_assoc()['nama_pemeriksaan'] ?? '');
                $ex_summary[] = ['id' => $pid, 'nama' => $nm, 'qty' => $qty];
            }
            $payload['exams'] = $ex_summary;
            $payload['exams_text'] = implode(' | ', array_map(function($x) {
                $nm = trim((string)($x['nama'] ?? ''));
                $qty = (int)($x['qty'] ?? 0);
                if ($nm === '') $nm = 'Pemeriksaan';
                return $nm . ' x' . $qty;
            }, $ex_summary));

            // Fire-and-forget
            $ch = curl_init($webhook);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1800); // 1.8s
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (\Throwable $e) {
        // ignore webhook errors
    }
    echo json_encode([
        'success' => true, 
        'message' => 'Booking berhasil dibuat!', 
        'booking_id' => $book_id,
        'nomor_booking' => $nomor_final
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>
