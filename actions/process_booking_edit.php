<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

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

$booking_id = intval($_POST['booking_id']);
$exams = $_POST['exams'] ?? [];
$nama_pemesan = trim((string)($_POST['nama_pemesan'] ?? ''));
$nomor_tlp = !empty($_POST['nomor_tlp']) ? trim((string)$_POST['nomor_tlp']) : null;
$tanggal_lahir = !empty($_POST['tanggal_lahir']) ? (string)$_POST['tanggal_lahir'] : null;
$tanggal = (string)($_POST['tanggal'] ?? '');
$booking_type = (string)($_POST['booking_type'] ?? 'keep');
$jam_layanan = $_POST['jam_layanan'] ?? null;
$jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;

if (empty($exams)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada pemeriksaan yang dipilih']);
    exit;
}
if ($nama_pemesan === '' || $tanggal === '') {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
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
    $booking = $conn->query("SELECT * FROM booking_pemeriksaan WHERE id = $booking_id AND status = 'booked'")->fetch_assoc();
    
    if (!$booking) {
        throw new Exception('Booking tidak ditemukan atau sudah diproses');
    }
    
    $klinik_id = $booking['klinik_id'];
    $status_booking = $booking['status_booking'];
    if ($role === 'admin_klinik') {
        $userKlinik = (int)($_SESSION['klinik_id'] ?? 0);
        if ((int)$klinik_id !== $userKlinik) {
            throw new Exception('Access denied');
        }
    }

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
    $ensure_col('booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL");
    $ensure_col('booking_pemeriksaan', 'tanggal_lahir', "DATE NULL");
    $ensure_col('booking_pemeriksaan', 'butuh_fu', "TINYINT(1) NOT NULL DEFAULT 0");

    $new_status = ($booking_type === 'cancel') ? 'cancelled' : 'booked';
    $butuh_fu = ($new_status === 'cancelled') ? 0 : (int)($booking['butuh_fu'] ?? 0);
    $stmt_u = $conn->prepare("UPDATE booking_pemeriksaan SET nama_pemesan = ?, nomor_tlp = ?, tanggal_lahir = ?, tanggal_pemeriksaan = ?, booking_type = ?, jam_layanan = ?, jotform_submitted = ?, status = ?, butuh_fu = ? WHERE id = ?");
    $stmt_u->bind_param("ssssssissi", $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, $booking_type, $jam_layanan, $jotform_submitted, $new_status, $butuh_fu, $booking_id);
    $stmt_u->execute();

    // Clear existing details
    $conn->query("DELETE FROM booking_detail WHERE booking_id = $booking_id");
    $conn->query("DELETE FROM booking_pasien WHERE booking_id = $booking_id");

    if ($new_status === 'cancelled') {
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Booking dibatalkan (CS)']);
        exit;
    }

    // Calculate total needed items across all exams
    $total_needed = [];
    foreach ($exams as $exam) {
        if (empty($exam['pemeriksaan_id']) || empty($exam['qty'])) continue;
        $pid = intval($exam['pemeriksaan_id']);
        $qty_multiplier = intval($exam['qty']);
        if ($pid <= 0 || $qty_multiplier <= 0) continue;

        $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
        while ($res && ($row = $res->fetch_assoc())) {
            $bid = (int)$row['barang_id'];
            $qty = (int)$row['qty_per_pemeriksaan'] * $qty_multiplier;
            if (!isset($total_needed[$bid])) $total_needed[$bid] = 0;
            $total_needed[$bid] += $qty;
        }
    }
    if (empty($total_needed)) throw new Exception("Tidak ada item yang perlu dibooking (cek master pemeriksaan).");

    // Stock check using Odoo mirror
    $klin = $conn->query("SELECT kode_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    $kode_klinik = (string)($klin['kode_klinik'] ?? '');
    $kode_homecare = (string)($klin['kode_homecare'] ?? '');
    $is_hc = (stripos((string)$status_booking, 'HC') !== false);
    $location_code = $is_hc ? $kode_homecare : $kode_klinik;
    if ($location_code === '') throw new Exception("Kode lokasi Odoo untuk klinik ini belum diisi.");
    $loc_esc = $conn->real_escape_string($location_code);

    foreach ($total_needed as $bid => $qty_need) {
        $b = $conn->query("SELECT nama_barang, odoo_product_id FROM barang WHERE id = " . (int)$bid . " LIMIT 1")->fetch_assoc();
        $bname = (string)($b['nama_barang'] ?? ("ID:$bid"));
        $odoo_pid = (string)($b['odoo_product_id'] ?? '');
        if ($odoo_pid === '') throw new Exception("Produk $bname belum punya odoo_product_id.");
        $pid_esc = $conn->real_escape_string($odoo_pid);
        $rq = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE odoo_product_id = '$pid_esc' AND location_code = '$loc_esc' LIMIT 1");
        $rowq = $rq ? $rq->fetch_assoc() : null;
        $available = (int)floor((float)($rowq['qty'] ?? 0));
        if ($available < (int)$qty_need) {
            throw new Exception("Stok tidak cukup untuk $bname. Tersedia: $available, Butuh Total: $qty_need");
        }
    }

    // Insert again
    $stmt_pasien = $conn->prepare("INSERT INTO booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id) VALUES (?, ?, ?)");
    $stmt_detail = $conn->prepare("INSERT INTO booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($exams as $exam) {
        $pid = intval($exam['pemeriksaan_id'] ?? 0);
        $qty_multiplier = intval($exam['qty'] ?? 0);
        if ($pid <= 0 || $qty_multiplier <= 0) continue;

        $stmt_pasien->bind_param("isi", $booking_id, $nama_pemesan, $pid);
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

            $stmt_detail->bind_param("iiiiii", $booking_id, $pasien_id, $barang_id, $qty_total, $qty_reserved_onsite, $qty_reserved_hc);
            $stmt_detail->execute();
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Pemeriksaan berhasil diperbarui']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
