<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/webhooks.php';
require_once __DIR__ . '/../includes/history_helper.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['cs', 'super_admin'], true)) {
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
if ($jumlah_pax > 10) {
    echo json_encode(['success' => false, 'message' => 'Maksimal jumlah pax adalah 10!']);
    exit;
}
$request_reason = trim((string)($_POST['request_reason'] ?? ''));

if (empty($patients)) {
    echo json_encode(['success' => false, 'message' => 'Data pasien tidak boleh kosong']);
    exit;
}

// Set nama_pemesan from the first patient
$nama_pemesan = trim((string)($patients[0]['nama'] ?? ''));
$nomor_tlp = !empty($patients[0]['nomor_tlp']) ? trim((string)$patients[0]['nomor_tlp']) : null;
$tanggal_lahir = !empty($patients[0]['tanggal_lahir']) ? (string)$patients[0]['tanggal_lahir'] : null;

if ($nama_pemesan === '' || $tanggal === '' || empty($jam_layanan)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap (Nama, Tanggal & Jam wajib diisi)']);
    exit;
}

// Validate Backdate & Backtime
$today = date('Y-m-d');
$is_backdate = ($tanggal < $today);
$is_backtime = ($tanggal === $today && !empty($jam_layanan) && $jam_layanan < date('H:i'));

if (($is_backdate || $is_backtime) && !in_array($role, ['super_admin', 'cs'], true)) {
    echo json_encode(['success' => false, 'message' => 'Perubahan lewat hari/waktu hanya dapat dilakukan oleh Super Admin atau CS!']);
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
    
    if (!$booking || !in_array($booking['status'], ['booked', 'rescheduled', 'pending_edit', 'rejected'])) {
        throw new Exception('Booking tidak ditemukan atau status tidak valid');
    }

    // Fetch old exams summary for history
    $old_exams_res = $conn->query("
        SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ') as exams
        FROM inventory_booking_pasien bp
        JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
        WHERE bp.booking_id = $booking_id
    ");
    $old_exams_row = $old_exams_res->fetch_assoc();
    $old_exams_str = $old_exams_row['exams'] ?? '';
    
    $is_past_day = date('Y-m-d', strtotime($booking['tanggal_pemeriksaan'])) < $today;
    
    // Admin Klinik and SPV Klinik are no longer allowed to edit or request edit.
    if ($role === 'admin_klinik' || $role === 'spv_klinik') {
        throw new Exception('Akses edit booking dinonaktifkan untuk role Anda.');
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

        logBookingHistory($conn, $booking_id, 'cancel', [], 'Dibatalkan oleh CS');

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
            $res = $conn->query("SELECT barang_id, is_lokal, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
            while($row = $res->fetch_assoc()) {
                $bid = intval($row['barang_id']);
                $isl = intval($row['is_lokal']);
                $qty = (float)$row['qty_per_pemeriksaan'];
                $key = "$bid|$isl";
                $total_needed[$key] = ($total_needed[$key] ?? 0) + $qty;
            }
        }
    }
    /*
    if (empty($total_needed)) throw new Exception("Tidak ada item yang perlu dibooking (cek master pemeriksaan).");
    */

    // Identify out-of-stock items (Core only) and keep as warning flag
    $out_of_stock_items = [];
    foreach ($total_needed as $key => $qty_need) {
        list($bid, $isl) = explode('|', $key);
        $bid = (int)$bid;
        $isl = (int)$isl;
        $qty_need = (float)$qty_need;

        if (!$isl) {
            $ef = stock_effective($conn, (int)$target_klinik_id, $is_hc, $bid);
            if (!$ef['ok']) continue;
            $available = (float)($ef['available'] ?? 0);
            if ($available < $qty_need) {
                $bname = (string)($ef['barang_name'] ?? ("ID:$bid"));
                $out_of_stock_items[] = "$bname (Sisa: $available, Butuh: $qty_need)";
            }
        }
    }

    $catatan = trim((string)($_POST['catatan'] ?? ''));

    $is_out_of_stock = !empty($out_of_stock_items) ? 1 : 0;
    $out_of_stock_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;

    $stmt_u = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET nama_pemesan = ?, nomor_tlp = ?, tanggal_lahir = ?, tanggal_pemeriksaan = ?, booking_type = ?, jam_layanan = ?, jotform_submitted = ?, status = ?, butuh_fu = ?, klinik_id = ?, status_booking = ?, jumlah_pax = ?, is_out_of_stock = ?, out_of_stock_items = ?, catatan = ? WHERE id = ?");
    $stmt_u->bind_param("ssssssisiisiissi", $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, $booking_type, $jam_layanan, $jotform_submitted, $new_status, $butuh_fu, $target_klinik_id, $target_status_booking, $jumlah_pax, $is_out_of_stock, $out_of_stock_str, $catatan, $booking_id);
    $stmt_u->execute();

    // Clear existing details
    $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $booking_id");
    $conn->query("DELETE FROM inventory_booking_pasien WHERE booking_id = $booking_id");

    // Insert again
    $conn->query("ALTER TABLE inventory_booking_pasien ADD COLUMN IF NOT EXISTS nomor_tlp VARCHAR(30) NULL");
    $conn->query("ALTER TABLE inventory_booking_pasien ADD COLUMN IF NOT EXISTS tanggal_lahir DATE NULL");

    $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir) VALUES (?, ?, ?, ?, ?)");
    $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, is_lokal, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?, ?)");

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
            $res_items = $conn->query("SELECT barang_id, is_lokal, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
            while ($res_items && ($i_row = $res_items->fetch_assoc())) {
                $barang_id = (int)$i_row['barang_id'];
                $isl = (int)$i_row['is_lokal'];
                $qty_unit = (float)$i_row['qty_per_pemeriksaan'];
                if ($qty_unit <= 0) continue;

                $qty_onsite = 0; $qty_hc = 0;
                if (stripos((string)$target_status_booking, 'Clinic') !== false) $qty_onsite = $qty_unit;
                elseif (stripos((string)$target_status_booking, 'HC') !== false) $qty_hc = $qty_unit;

                $stmt_detail->bind_param("iiiiddd", $booking_id, $pasien_row_id, $barang_id, $isl, $qty_unit, $qty_onsite, $qty_hc);
                if (!$stmt_detail->execute()) throw new Exception("Error saving detail: " . $stmt_detail->error);
            }
        }
    }

    // Log changes
    $changes = [];
    $notes_parts = [];
    if ($booking['nama_pemesan'] != $nama_pemesan) {
        $changes['nama'] = ['old' => $booking['nama_pemesan'], 'new' => $nama_pemesan];
        $notes_parts[] = "Mengubah nama";
    }
    if ($booking['tanggal_pemeriksaan'] != $tanggal) {
        $changes['tanggal'] = ['old' => $booking['tanggal_pemeriksaan'], 'new' => $tanggal];
        $notes_parts[] = "Mengubah tanggal";
    }
    if ($booking['jam_layanan'] != $jam_layanan) {
        $changes['jam'] = ['old' => $booking['jam_layanan'], 'new' => $jam_layanan];
        $notes_parts[] = "Mengubah jam";
    }
    if ($booking['jumlah_pax'] != $jumlah_pax) {
        $changes['pax'] = ['old' => $booking['jumlah_pax'], 'new' => $jumlah_pax];
        $notes_parts[] = "Mengubah jumlah pax (" . $booking['jumlah_pax'] . " -> " . $jumlah_pax . ")";
    }
    if ($booking['klinik_id'] != $target_klinik_id) {
        $old_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = " . $booking['klinik_id'])->fetch_assoc();
        $new_k = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = " . $target_klinik_id)->fetch_assoc();
        $changes['lokasi'] = ['old' => ($old_k['nama_klinik'] ?? $booking['klinik_id']), 'new' => ($new_k['nama_klinik'] ?? $target_klinik_id)];
        $notes_parts[] = "Pindah lokasi";
    }
    if ($booking['status_booking'] != $target_status_booking) {
        $changes['type'] = ['old' => $booking['status_booking'], 'new' => $target_status_booking];
        $notes_parts[] = "Ubah layanan jadi " . $target_status_booking;
    }
    if ($booking['booking_type'] != $booking_type) {
        $changes['booking_type'] = ['old' => ($booking['booking_type'] ?? 'keep'), 'new' => $booking_type];
        $notes_parts[] = "Mengubah tipe (" . ucfirst($booking['booking_type'] ?? 'Keep') . " -> " . ucfirst($booking_type) . ")";
    }
    if ((int)$booking['jotform_submitted'] != (int)$jotform_submitted) {
        $changes['jotform'] = ['old' => $booking['jotform_submitted'] ? 'Sudah' : 'Belum', 'new' => $jotform_submitted ? 'Sudah' : 'Belum'];
        $notes_parts[] = "Update status Jotform (" . ($booking['jotform_submitted'] ? 'Sudah' : 'Belum') . " -> " . ($jotform_submitted ? 'Sudah' : 'Belum') . ")";
    }
    if (trim((string)($booking['catatan'] ?? '')) != $catatan) {
        $changes['catatan'] = ['old' => $booking['catatan'], 'new' => $catatan];
        $notes_parts[] = "Memperbarui catatan";
    }
    
    // Fetch new exams summary
    $new_exams_res = $conn->query("
        SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ') as exams
        FROM inventory_booking_pasien bp
        JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
        WHERE bp.booking_id = $booking_id
    ");
    $new_exams_row = $new_exams_res->fetch_assoc();
    $new_exams_str = $new_exams_row['exams'] ?? '';

    if ($old_exams_str !== $new_exams_str) {
        $changes['pemeriksaan'] = ['old' => $old_exams_str, 'new' => $new_exams_str];
        $notes_parts[] = "Update jenis pemeriksaan";
    }
    
    if (empty($notes_parts)) {
        $notes_parts[] = "Pembaruan data pasien/pemeriksaan";
    }
    
    logBookingHistory($conn, $booking_id, 'edit', $changes, $request_reason ?: implode(", ", $notes_parts));
    
    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    notify_gsheet_booking($conn, $booking_id, 'booking_updated');
    echo json_encode(['success' => true, 'message' => 'Pemeriksaan berhasil diperbarui', 'booking_id' => $booking_id]);
} catch (Exception $e) {
    if (isset($lock_esc) && $lock_esc !== '') {
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    }
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();


