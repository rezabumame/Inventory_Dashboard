<?php
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../lib/webhooks.php';
require_once __DIR__ . '/../includes/history_helper.php';

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
    require_csrf();
    $client_request_id = trim((string)($_POST['client_request_id'] ?? ''));
    if ($client_request_id !== '') {
        $cid_esc = $conn->real_escape_string($client_request_id);
        $uid_esc = (int)$_SESSION['user_id'];
        $exists = $conn->query("SELECT booking_id FROM inventory_booking_request_dedup WHERE client_request_id = '$cid_esc' AND created_by = $uid_esc LIMIT 1")->fetch_assoc();
        if ($exists) {
            echo json_encode(['success' => true, 'message' => 'Request sudah diproses.', 'booking_id' => (int)($exists['booking_id'] ?? 0)]);
            exit;
        }
        $conn->query("INSERT IGNORE INTO inventory_booking_request_dedup (client_request_id, created_by, booking_id) VALUES ('$cid_esc', $uid_esc, NULL)");
    }

    $status_booking = $_POST['status_booking'] ?? '';
    $klinik_id = intval($_POST['klinik_id'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? '';
    $order_id = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
    $booking_type = (string)($_POST['booking_type'] ?? 'keep');
    $jam_layanan = $_POST['jam_layanan'] ?? null;
    $jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;
    $jumlah_pax = intval($_POST['jumlah_pax'] ?? 1);
    if ($jumlah_pax > 10) {
        throw new Exception('Maksimal jumlah pax adalah 10!');
    }
    $catatan = !empty($_POST['catatan']) ? $_POST['catatan'] : null;
    $patients = $_POST['patients'] ?? [];
    $created_by = $_SESSION['user_id'];
    $cs_name = $_SESSION['nama_lengkap'] ?? '';

    if (empty($patients)) {
        throw new Exception('Data pasien tidak boleh kosong!');
    }

    // Set nama_pemesan from the first patient
    $nama_pemesan = $patients[0]['nama'] ?? '';
    $nomor_tlp = !empty($patients[0]['nomor_tlp']) ? trim((string)$patients[0]['nomor_tlp']) : null;
    $tanggal_lahir = !empty($patients[0]['tanggal_lahir']) ? (string)$patients[0]['tanggal_lahir'] : null;

    if (empty($status_booking) || empty($klinik_id) || empty($tanggal) || empty($nama_pemesan) || empty($jam_layanan)) {
        throw new Exception('Data tidak lengkap (Nama, Tanggal, dan Jam wajib diisi)!');
    }

    $is_hc = (stripos((string)$status_booking, 'HC') !== false);

    // 1. Calculate TOTAL needed items across ALL patients and THEIR SPECIFIC exams
    $total_needed = [];
    foreach ($patients as $p) {
        $p_exams = $p['exams'] ?? [];
        if (empty($p_exams)) {
            throw new Exception("Pasien " . ($p['nama'] ?: 'tanpa nama') . " belum memilih pemeriksaan!");
        }
        foreach ($p_exams as $pid) {
            $pid = trim((string)$pid);
            if ($pid === '') continue;
            
            $pid_esc = $conn->real_escape_string($pid);
            $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
            while($row = $res->fetch_assoc()) {
                $bid = intval($row['barang_id']);
                $qty = (float)$row['qty_per_pemeriksaan'];
                $total_needed[$bid] = ($total_needed[$bid] ?? 0) + $qty;
            }
        }
    }

    /* 
    if (empty($total_needed)) {
        throw new Exception("Tidak ada item yang perlu dibooking");
    }
    */

    // 2. Check Effective Availability (Core items only). Core = is_mandatory=1
    $out_of_stock_items = [];
    foreach ($total_needed as $bid => $qty_need) {
        $bid = (int)$bid;
        $qty_need = (float)$qty_need;
        $ef = stock_effective($conn, (int)$klinik_id, $is_hc, $bid);
        if (!$ef['ok']) continue;
        $available = (float)($ef['available'] ?? 0);
        if ($available < $qty_need) {
            $bname = (string)($ef['barang_name'] ?? ("ID:$bid"));
            $out_of_stock_items[] = "$bname (Sisa: $available, Butuh: $qty_need)";
        }
    }

    $conn->begin_transaction();

    // 3. Create Booking Header
    $nomor = "BK-TMP-" . time();
    $is_out_of_stock = !empty($out_of_stock_items) ? 1 : 0;
    $out_of_stock_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;
    
    $sql = "INSERT INTO inventory_booking_pemeriksaan 
            (nomor_booking, order_id, klinik_id, status_booking, booking_type, jam_layanan, jotform_submitted, cs_name, nama_pemesan, nomor_tlp, tanggal_lahir, jumlah_pax, catatan, tanggal_pemeriksaan, status, is_out_of_stock, out_of_stock_items, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssisssissssissisi", $nomor, $order_id, $klinik_id, $status_booking, $booking_type, $jam_layanan, $jotform_submitted, $cs_name, $nama_pemesan, $nomor_tlp, $tanggal_lahir, $jumlah_pax, $catatan, $tanggal, $is_out_of_stock, $out_of_stock_str, $created_by);
    
    if (!$stmt->execute()) {
        throw new Exception("Error insert booking: " . $stmt->error);
    }
    
    $book_id = $conn->insert_id;
    $nomor_final = "BK-" . str_pad((string)$book_id, 6, '0', STR_PAD_LEFT);
    $conn->query("UPDATE inventory_booking_pemeriksaan SET nomor_booking = '$nomor_final' WHERE id = $book_id");

    // 4. Insert Patients + Details
    $conn->query("ALTER TABLE inventory_booking_pasien ADD COLUMN IF NOT EXISTS nomor_tlp VARCHAR(30) NULL");
    $conn->query("ALTER TABLE inventory_booking_pasien ADD COLUMN IF NOT EXISTS tanggal_lahir DATE NULL");

    $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir) VALUES (?, ?, ?, ?, ?)");
    $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($patients as $idx => $p) {
        $pnama = !empty($p['nama']) ? $p['nama'] : "Pasien " . ($idx + 1);
        $ptlp  = !empty($p['nomor_tlp']) ? trim($p['nomor_tlp']) : null;
        $ptgl  = !empty($p['tanggal_lahir']) ? $p['tanggal_lahir'] : null;
        $p_exams = $p['exams'] ?? [];

        foreach ($p_exams as $pid) {
            $pid = trim((string)$pid);
            if ($pid === '') continue;

            // Insert patient-exam link
            $stmt_pasien->bind_param("issss", $book_id, $pnama, $pid, $ptlp, $ptgl);
            if (!$stmt_pasien->execute()) throw new Exception("Error saving patient exam: " . $stmt_pasien->error);
            $pasien_row_id = (int)$conn->insert_id;

            // Insert inventory details for this specific patient
            $pid_esc = $conn->real_escape_string($pid);
            $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
            while ($res_items && ($i_row = $res_items->fetch_assoc())) {
                $barang_id = (int)$i_row['barang_id'];
                $qty_unit = (float)$i_row['qty_per_pemeriksaan'];
                if ($qty_unit <= 0) continue;

                $qty_onsite = 0; $qty_hc = 0;
                if (stripos((string)$status_booking, 'Clinic') !== false) $qty_onsite = $qty_unit;
                elseif (stripos((string)$status_booking, 'HC') !== false) $qty_hc = $qty_unit;

                $stmt_detail->bind_param("iiiddd", $book_id, $pasien_row_id, $barang_id, $qty_unit, $qty_onsite, $qty_hc);
                if (!$stmt_detail->execute()) throw new Exception("Error saving detail: " . $stmt_detail->error);
            }
        }
    }

    $conn->commit();
    logBookingHistory($conn, $book_id, 'create', [], 'Booking dibuat pertama kali');

    // Notify Google Sheets
    notify_gsheet_booking($conn, $book_id, 'booking_created');

    echo json_encode([
        'success' => true, 
        'message' => 'Booking berhasil dibuat!', 
        'booking_id' => $book_id,
        'nomor_booking' => $nomor_final
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        if (isset($lock_esc) && $lock_esc !== '') {
            $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        }
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

