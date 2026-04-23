<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/webhooks.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['cs', 'super_admin', 'admin_klinik'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

require_csrf();

$booking_id = intval($_POST['booking_id']);
$patients = $_POST['patients'] ?? [];
$tanggal = (string)($_POST['tanggal'] ?? '');
$booking_type = (string)($_POST['booking_type'] ?? 'keep');
$jam_layanan = $_POST['jam_layanan'] ?? null;
$jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;
$new_klinik_id = (int)($_POST['new_klinik_id'] ?? 0);
$new_status_booking = trim((string)($_POST['new_status_booking'] ?? ''));
$jumlah_pax = (int)($_POST['jumlah_pax'] ?? 0);
$request_reason = trim((string)($_POST['request_reason'] ?? ''));

if (empty($patients)) {
    echo json_encode(['success' => false, 'message' => 'Data pasien tidak boleh kosong']);
    exit;
}

// Set nama_pemesan from the first patient
$nama_pemesan = trim((string)($patients[0]['nama'] ?? ''));
$nomor_tlp = !empty($patients[0]['nomor_tlp']) ? trim((string)$patients[0]['nomor_tlp']) : null;
$tanggal_lahir = !empty($patients[0]['tanggal_lahir']) ? (string)$patients[0]['tanggal_lahir'] : null;

if ($nama_pemesan === '' || $tanggal === '') {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap (Nama Pasien Utama & Tanggal wajib diisi)']);
    exit;
}

// Validate Backdate & Backtime
$today = date('Y-m-d');
$is_backdate = ($tanggal < $today);
$is_backtime = ($tanggal === $today && !empty($jam_layanan) && $jam_layanan < date('H:i'));

if (($is_backdate || $is_backtime) && !in_array($role, ['super_admin', 'cs'], true) && empty($request_reason)) {
    echo json_encode(['success' => false, 'message' => 'Perubahan lewat hari/waktu wajib menyertakan alasan untuk approval SPV!']);
    exit;
}
if (!in_array($booking_type, ['keep', 'fixed', 'cancel'], true)) {
    echo json_encode(['success' => false, 'message' => 'Tipe booking tidak valid']);
    exit;
}
if ($booking_type === 'cancel' && !in_array($role, ['cs', 'super_admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$conn->begin_transaction();

try {
    // Get booking info
    $booking = $conn->query("SELECT * FROM inventory_booking_pemeriksaan WHERE id = $booking_id")->fetch_assoc();
    
    if (!$booking || !in_array($booking['status'], ['booked', 'pending_edit', 'rejected'])) {
        throw new Exception('Booking tidak ditemukan atau status tidak valid');
    }
    
    $is_past_day = date('Y-m-d', strtotime($booking['tanggal_pemeriksaan'])) < $today;
    $is_admin_klinik = ($role === 'admin_klinik');
    $is_request = ($is_admin_klinik && ($is_past_day || !empty($request_reason)));

    if ($is_request) {
        // Just save to pending_data and update status
        $pending_payload = [
            'nama_pemesan' => $nama_pemesan,
            'nomor_tlp' => $nomor_tlp,
            'tanggal_lahir' => $tanggal_lahir,
            'tanggal' => $tanggal,
            'booking_type' => $booking_type,
            'jam_layanan' => $jam_layanan,
            'jotform_submitted' => $jotform_submitted,
            'klinik_id' => $new_klinik_id ?: $booking['klinik_id'],
            'status_booking' => $new_status_booking ?: $booking['status_booking'],
            'jumlah_pax' => $jumlah_pax,
            'patients' => $patients
        ];

        $stmt_req = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET status = 'pending_edit', approval_reason = ?, pending_data = ? WHERE id = ?");
        $json_data = json_encode($pending_payload);
        $stmt_req->bind_param("ssi", $request_reason, $json_data, $booking_id);
        $stmt_req->execute();
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Permintaan perubahan telah dikirim ke SPV Klinik']);
        exit;
    }

    $klinik_id = (int)($booking['klinik_id'] ?? 0);
    $status_booking = (string)($booking['status_booking'] ?? '');
    $target_klinik_id = $klinik_id;
    $target_status_booking = $status_booking;
    if (in_array($role, ['cs', 'super_admin'], true)) {
        if ($new_klinik_id > 0) $target_klinik_id = $new_klinik_id;
        if (in_array($new_status_booking, ['Reserved - Clinic', 'Reserved - HC'], true)) $target_status_booking = $new_status_booking;
    }
    if ($target_klinik_id <= 0) throw new Exception('Klinik tidak valid');
    $is_hc = (stripos((string)$target_status_booking, 'HC') !== false);
    if (stripos((string)$target_status_booking, 'Clinic') !== false) $is_hc = false;
    if (!in_array($target_status_booking, ['Reserved - Clinic', 'Reserved - HC'], true)) {
        $target_status_booking = $is_hc ? 'Reserved - HC' : 'Reserved - Clinic';
    }
    if ($jumlah_pax <= 0) $jumlah_pax = (int)($booking['jumlah_pax'] ?? 1);
    if ($role === 'admin_klinik') {
        $userKlinik = (int)($_SESSION['klinik_id'] ?? 0);
        if ((int)$klinik_id !== $userKlinik) {
            throw new Exception('Access denied');
        }
        $target_klinik_id = $klinik_id;
        $target_status_booking = $status_booking;
        $is_hc = (stripos((string)$target_status_booking, 'HC') !== false);
        if (stripos((string)$target_status_booking, 'Clinic') !== false) $is_hc = false;
    }

    $rk = $conn->query("SELECT id FROM inventory_klinik WHERE id = " . (int)$target_klinik_id . " LIMIT 1");
    if (!$rk || $rk->num_rows === 0) throw new Exception('Klinik tidak ditemukan');

    $lock_name = 'booking_' . (int)$target_klinik_id . '_' . ($is_hc ? 'hc' : 'clinic');
    $lock_esc = $conn->real_escape_string($lock_name);
    $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got_lock !== 1) throw new Exception('Sistem sedang memproses booking lain. Coba lagi sebentar.');


    $new_status = ($booking_type === 'cancel') ? 'cancelled' : 'booked';
    $butuh_fu = (int)($booking['butuh_fu'] ?? 0);
    if ($new_status === 'cancelled') {
        $butuh_fu = 0;
    } elseif ($tanggal !== $booking['tanggal_pemeriksaan'] || $jam_layanan !== $booking['jam_layanan']) {
        // If schedule changed, clear FU Jadwal Kedatangan status
        $butuh_fu = 0;
    }

    if ($new_status === 'cancelled') {
        $stmt_u = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET nama_pemesan = ?, nomor_tlp = ?, tanggal_lahir = ?, tanggal_pemeriksaan = ?, booking_type = ?, jam_layanan = ?, jotform_submitted = ?, status = ?, butuh_fu = ?, klinik_id = ?, status_booking = ?, jumlah_pax = ? WHERE id = ?");
        $stmt_u->bind_param("ssssssisiisii", $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, $booking_type, $jam_layanan, $jotform_submitted, $new_status, $butuh_fu, $target_klinik_id, $target_status_booking, $jumlah_pax, $booking_id);
        $stmt_u->execute();

        $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $booking_id");
        $conn->query("DELETE FROM inventory_booking_pasien WHERE booking_id = $booking_id");

        $conn->commit();
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        notify_gsheet_booking($conn, $booking_id, 'booking_updated');
        echo json_encode(['success' => true, 'message' => 'Booking dibatalkan (CS)']);
        exit;
    }

    // Calculate total needed items across ALL patients and THEIR SPECIFIC exams
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
    if (empty($total_needed)) throw new Exception("Tidak ada item yang perlu dibooking (cek master pemeriksaan).");
    */

    // Identify out-of-stock items (Core only) and keep as warning flag
    $out_of_stock_items = [];
    foreach ($total_needed as $bid => $qty_need) {
        $bid = (int)$bid;
        $qty_need = (float)$qty_need;
        $ef = stock_effective($conn, (int)$target_klinik_id, $is_hc, $bid);
        if (!$ef['ok']) continue;
        $available = (float)($ef['available'] ?? 0);
        if ($available < $qty_need) {
            $bname = (string)($ef['barang_name'] ?? ("ID:$bid"));
            $out_of_stock_items[] = "$bname (Sisa: $available, Butuh: $qty_need)";
        }
    }

    $is_out_of_stock = !empty($out_of_stock_items) ? 1 : 0;
    $out_of_stock_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;

    $stmt_u = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET nama_pemesan = ?, nomor_tlp = ?, tanggal_lahir = ?, tanggal_pemeriksaan = ?, booking_type = ?, jam_layanan = ?, jotform_submitted = ?, status = ?, butuh_fu = ?, klinik_id = ?, status_booking = ?, jumlah_pax = ?, is_out_of_stock = ?, out_of_stock_items = ? WHERE id = ?");
    $stmt_u->bind_param("ssssssisiisiisi", $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, $booking_type, $jam_layanan, $jotform_submitted, $new_status, $butuh_fu, $target_klinik_id, $target_status_booking, $jumlah_pax, $is_out_of_stock, $out_of_stock_str, $booking_id);
    $stmt_u->execute();

    // Clear existing details
    $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $booking_id");
    $conn->query("DELETE FROM inventory_booking_pasien WHERE booking_id = $booking_id");

    // Insert again
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
            $stmt_pasien->bind_param("issss", $booking_id, $pnama, $pid, $ptlp, $ptgl);
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
                if (stripos((string)$target_status_booking, 'Clinic') !== false) $qty_onsite = $qty_unit;
                elseif (stripos((string)$target_status_booking, 'HC') !== false) $qty_hc = $qty_unit;

                $stmt_detail->bind_param("iiiddd", $booking_id, $pasien_row_id, $barang_id, $qty_unit, $qty_onsite, $qty_hc);
                if (!$stmt_detail->execute()) throw new Exception("Error saving detail: " . $stmt_detail->error);
            }
        }
    }
    
    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    notify_gsheet_booking($conn, $booking_id, 'booking_updated');
    echo json_encode(['success' => true, 'message' => 'Pemeriksaan berhasil diperbarui']);
} catch (Exception $e) {
    if (isset($lock_esc) && $lock_esc !== '') {
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    }
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();


