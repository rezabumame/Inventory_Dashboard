<?php
session_start();
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/webhooks.php';
require_once __DIR__ . '/../includes/history_helper.php';

header('Content-Type: application/json');

// Check role access
check_role(['super_admin', 'admin_klinik', 'cs']);

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($action === '' || $id <= 0) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=booking');
}

require_csrf();

$role = (string)($_SESSION['role'] ?? '');

$booking = $conn->query("SELECT * FROM inventory_booking_pemeriksaan WHERE id = $id")->fetch_assoc();

if (!$booking || !in_array($booking['status'], ['booked', 'rescheduled', 'pending_edit', 'pending_delete', 'rejected'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan atau sudah diproses']);
        exit;
    }
    $_SESSION['error'] = 'Booking tidak ditemukan atau sudah diproses';
    redirect('index.php?page=booking');
}
if ($role === 'admin_klinik') {
    $userKlinik = (int)($_SESSION['klinik_id'] ?? 0);
    if ((int)($booking['klinik_id'] ?? 0) !== $userKlinik) {
        $_SESSION['error'] = 'Access denied';
        redirect('index.php?page=booking');
    }
}

$conn->begin_transaction();
try {
    // Get all items from details
    $sql_items = "SELECT bd.* FROM inventory_booking_detail bd WHERE bd.booking_id = $id";
    $details = $conn->query($sql_items);
    
    $items_to_process = [];
    while($d = $details->fetch_assoc()) {
        $items_to_process[] = $d;
    }

    $msg = "";

    switch ($action) {
        case 'cancel':
            if (!in_array($role, ['cs', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $setType = ($role === 'cs') ? ", booking_type = 'cancel'" : "";
            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'cancelled', butuh_fu = 0 $setType WHERE id = $id");
            logBookingHistory($conn, $id, 'cancel', [], 'Booking dibatalkan');
            $msg = "Booking dibatalkan.";
            break;
        
        case 'done':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }

            // 1. Ambil data booking untuk status dan klinik
            $klinik_id = (int)$booking['klinik_id'];
            $is_hc = (stripos($booking['status_booking'], 'HC') !== false);
            $jenis_pemakaian = $is_hc ? 'hc' : 'klinik';
            $created_by = (int)$_SESSION['user_id'];
            
            // Perbaikan format nomor BHP agar menggunakan YYMMDD (6 digit)
            $date_ymd = date('ymd', strtotime($booking['tanggal_pemeriksaan'] ?? date('Y-m-d')));
            require_once __DIR__ . '/../lib/counter.php';
            $seq = next_sequence($conn, 'BHP', $date_ymd);
            $nomor_pemakaian = 'BHP-' . $date_ymd . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // 2. Hitung total qty per barang_id dari Master Pemeriksaan (Core & Support)
            $items_to_deduct = [];
            
            // Ambil semua pasien dan pemeriksaan yang ada di booking ini
            $res_pasien = $conn->query("SELECT pemeriksaan_grup_id FROM inventory_booking_pasien WHERE booking_id = $id");
            while ($pasien = $res_pasien->fetch_assoc()) {
                $pid = trim((string)$pasien['pemeriksaan_grup_id']);
                
                // Ambil SEMUA item dari master grup (Core & Support)
                $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid'");
                while ($item = $res_items->fetch_assoc()) {
                    $bid = (int)$item['barang_id'];
                    $qty = (float)$item['qty_per_pemeriksaan'];
                    
                    if ($qty > 0) {
                        if (!isset($items_to_deduct[$bid])) {
                            $items_to_deduct[$bid] = 0;
                        }
                        $items_to_deduct[$bid] += $qty;
                    }
                }
            }

            // 3. Jika ada item yang perlu didekduk, buat data pemakaian_bhp (Auto)
            if (!empty($items_to_deduct)) {
                $bhp_tanggal = (string)($booking['tanggal_pemeriksaan'] ?? '');
                if ($bhp_tanggal === '') $bhp_tanggal = date('Y-m-d');
                $bhp_tanggal .= ' 00:00:00';

                // Insert header
                $stmt_h = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, catatan_transaksi, created_by, created_at, status, is_auto, booking_id) VALUES (?, ?, ?, ?, 'Auto-deduction from Booking Completed', ?, NOW(), 'active', 1, ?)");
                $stmt_h->bind_param("sssiii", $nomor_pemakaian, $bhp_tanggal, $jenis_pemakaian, $klinik_id, $created_by, $id);
                $stmt_h->execute();
                $pemakaian_id = $conn->insert_id;

                // Insert details and record transactions
                $stmt_d = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
                
                $level = $is_hc ? 'hc' : 'klinik';
                $level_id = $klinik_id; // For klinik, level_id is klinik_id. For HC, it should be user_id, but booking doesn't have it yet.
                
                // If HC, try to find a default user_id for this clinic to satisfy the dashboard query
                if ($is_hc) {
                    $res_u = $conn->query("SELECT id FROM inventory_users WHERE klinik_id = $klinik_id AND role = 'petugas_hc' AND status = 'active' LIMIT 1");
                    if ($res_u && $u_row = $res_u->fetch_assoc()) {
                        $level_id = (int)$u_row['id'];
                    }
                }

                foreach ($items_to_deduct as $bid => $qty) {
                    $bid = (int)$bid;
                    $qty = (float)$qty;

                    // --- RACE CONDITION FIX: LOCK THE STOCK ROW ---
                    if ($is_hc && $level_id !== $klinik_id) {
                        // Lock HC Stock
                        $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($bid, $level_id, $klinik_id, 0)");
                        $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $bid AND user_id = $level_id AND klinik_id = $klinik_id FOR UPDATE");
                    } else {
                        // Lock Clinic Stock
                        $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($bid, $klinik_id, 0)");
                        $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $bid AND klinik_id = $klinik_id FOR UPDATE");
                    }

                    // Cari satuan barang
                    $res_b = $conn->query("SELECT satuan FROM inventory_barang WHERE id = $bid LIMIT 1");
                    $satuan = $res_b->num_rows > 0 ? $res_b->fetch_assoc()['satuan'] : 'PCS';
                    
                    $stmt_d->bind_param("iids", $pemakaian_id, $bid, $qty, $satuan);
                    $stmt_d->execute();

                    // Record Transaction for Dashboard Sellout
                    $eff = stock_effective($conn, $klinik_id, $is_hc, $bid);
                    $qty_before = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;
                    $qty_after = $qty_before - $qty;

                    $ref_type = 'pemakaian_bhp';
                    $ref_id = (int)$pemakaian_id;
                    $catatan = "BHP (Auto-Booking) " . $nomor_pemakaian;
                    $created_at_str = date('Y-m-d H:i:s');

                    $stmt_trans = $conn->prepare("
                        INSERT INTO inventory_transaksi_stok
                        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                        VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_trans->bind_param("isidddsisis", $bid, $level, $level_id, $qty, $qty_before, $qty_after, $ref_type, $ref_id, $catatan, $created_by, $created_at_str);
                    $stmt_trans->execute();

                    // Update Internal Stock Tables
                    if ($is_hc && $level_id !== $klinik_id) {
                        $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                        $stmt_upd->bind_param("diiii", $qty, $created_by, $bid, $level_id, $klinik_id);
                        $stmt_upd->execute();
                    } else {
                        $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                        $stmt_upd->bind_param("diii", $qty, $created_by, $bid, $klinik_id);
                        $stmt_upd->execute();
                    }
                }
            }

            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'completed' WHERE id = $id");
            // Mark all patients as done if they were not already handled
            $conn->query("UPDATE inventory_booking_pasien SET status = 'done', done_at = NOW() WHERE booking_id = $id AND status = 'booked'");
            
            logBookingHistory($conn, $id, 'complete', [], 'Pemeriksaan Selesai (BHP Created: ' . $nomor_pemakaian . ')');
            $msg = "Booking selesai dan stok telah dipotong ($nomor_pemakaian).";
            break;

        case 'reschedule':
            if (!in_array($role, ['admin_klinik', 'super_admin', 'cs'], true)) {
                throw new Exception('Access denied');
            }
            $new_date = $_POST['new_date'] ?? '';
            $new_time = $_POST['new_time'] ?? null;
            $reason = $_POST['reason'] ?? '';
            if (empty($new_date) || empty($reason)) {
                throw new Exception('Tanggal dan alasan wajib diisi');
            }
            
            $old_date = $booking['tanggal_pemeriksaan'];
            $stmt_u = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET tanggal_pemeriksaan = ?, jam_layanan = ?, reschedule_reason = ?, status = 'rescheduled', butuh_fu = 0 WHERE id = ?");
            $stmt_u->bind_param("sssi", $new_date, $new_time, $reason, $id);
            $stmt_u->execute();

            // Sync patient status
            $conn->query("UPDATE inventory_booking_pasien SET status = 'rescheduled', remark = 'Reschedule masal ke $new_date' WHERE booking_id = $id AND status = 'booked'");
            
            logBookingHistory($conn, $id, 'reschedule', ['tanggal_pemeriksaan' => ['old' => $old_date, 'new' => $new_date]], "Reschedule ke $new_date. Alasan: $reason");
            $msg = "Booking berhasil di-reschedule ke tanggal $new_date " . ($new_time ? "jam $new_time" : "") . ".";
            break;

        case 'done_partial':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $done_ids = $_POST['done_ids'] ?? []; // Array of ids
            $fallback = $_POST['fallback'] ?? []; // Map of id => action
            $res_date = $_POST['reschedule_date'] ?? null;
            $res_time = $_POST['reschedule_time'] ?? null;
            $res_reason = $_POST['reschedule_reason'] ?? '';

            if (empty($done_ids) && empty($fallback)) {
                throw new Exception("Tidak ada pasien yang dipilih");
            }

            $klinik_id = (int)$booking['klinik_id'];
            $is_hc = (stripos($booking['status_booking'], 'HC') !== false);
            $jenis_pemakaian = $is_hc ? 'hc' : 'klinik';
            $created_by = (int)$_SESSION['user_id'];

            // 1. Process DONE patients and calculate items to deduct
            $items_to_deduct = [];
            foreach ($done_ids as $pid) {
                $pid = (int)$pid;
                $res_p = $conn->query("SELECT pemeriksaan_grup_id FROM inventory_booking_pasien WHERE id = $pid AND booking_id = $id");
                if ($p_row = $res_p->fetch_assoc()) {
                    $gid = $p_row['pemeriksaan_grup_id'];
                    $gid_esc = $conn->real_escape_string($gid);
                    $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$gid_esc'");
                    while ($i_row = $res_items->fetch_assoc()) {
                        $bid = (int)$i_row['barang_id'];
                        $qty = (float)$i_row['qty_per_pemeriksaan'];
                        if ($qty > 0) {
                            $items_to_deduct[$bid] = ($items_to_deduct[$bid] ?? 0) + $qty;
                        }
                    }
                    $conn->query("UPDATE inventory_booking_pasien SET status = 'done', done_at = NOW() WHERE id = $pid");
                }
            }

            // 2. Create BHP if needed
            $nomor_pemakaian = '';
            if (!empty($items_to_deduct)) {
                $date_ymd = date('ymd', strtotime($booking['tanggal_pemeriksaan'] ?? date('Y-m-d')));
                require_once __DIR__ . '/../lib/counter.php';
                $seq = next_sequence($conn, 'BHP', $date_ymd);
                $nomor_pemakaian = 'BHP-' . $date_ymd . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                
                $bhp_tanggal = (string)($booking['tanggal_pemeriksaan'] ?? date('Y-m-d')) . ' 00:00:00';
                $stmt_h = $conn->prepare("INSERT INTO inventory_pemakaian_bhp (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, catatan_transaksi, created_by, created_at, status, is_auto, booking_id) VALUES (?, ?, ?, ?, 'Partial-deduction from Booking', ?, NOW(), 'active', 1, ?)");
                $stmt_h->bind_param("sssiii", $nomor_pemakaian, $bhp_tanggal, $jenis_pemakaian, $klinik_id, $created_by, $id);
                $stmt_h->execute();
                $pemakaian_id = $conn->insert_id;

                $level = $is_hc ? 'hc' : 'klinik';
                $level_id = $klinik_id;
                if ($is_hc) {
                    $res_u = $conn->query("SELECT id FROM inventory_users WHERE klinik_id = $klinik_id AND role = 'petugas_hc' AND status = 'active' LIMIT 1");
                    if ($res_u && $u_row = $res_u->fetch_assoc()) $level_id = (int)$u_row['id'];
                }

                $stmt_d = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
                $stmt_trans = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, ?, ?, 'out', ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, NOW())");

                foreach ($items_to_deduct as $bid => $qty) {
                    // --- RACE CONDITION FIX: LOCK THE STOCK ROW ---
                    if ($is_hc && $level_id !== $klinik_id) {
                        $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($bid, $level_id, $klinik_id, 0)");
                        $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $bid AND user_id = $level_id AND klinik_id = $klinik_id FOR UPDATE");
                    } else {
                        $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($bid, $klinik_id, 0)");
                        $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $bid AND klinik_id = $klinik_id FOR UPDATE");
                    }

                    $res_b = $conn->query("SELECT satuan FROM inventory_barang WHERE id = $bid LIMIT 1");
                    $satuan = $res_b->num_rows > 0 ? $res_b->fetch_assoc()['satuan'] : 'PCS';
                    $stmt_d->bind_param("iids", $pemakaian_id, $bid, $qty, $satuan);
                    $stmt_d->execute();

                    $eff = stock_effective($conn, $klinik_id, $is_hc, $bid);
                    $qty_before = $eff['ok'] ? (float)$eff['on_hand'] : 0;
                    $qty_after = $qty_before - $qty;
                    $cat = "BHP (Partial-Booking) " . $nomor_pemakaian;
                    $stmt_trans->bind_param("isidddisi", $bid, $level, $level_id, $qty, $qty_before, $qty_after, $pemakaian_id, $cat, $created_by);
                    $stmt_trans->execute();

                    if ($is_hc && $level_id !== $klinik_id) {
                        $conn->query("UPDATE inventory_stok_tas_hc SET qty = qty - $qty, updated_at = NOW(), updated_by = $created_by WHERE barang_id = $bid AND user_id = $level_id AND klinik_id = $klinik_id");
                    } else {
                        $conn->query("UPDATE inventory_stok_gudang_klinik SET qty = qty - $qty, updated_at = NOW(), updated_by = $created_by WHERE barang_id = $bid AND klinik_id = $klinik_id");
                    }
                }
            }

            // 3. Handle Fallback patients (Cancel / Reschedule)
            $reschedule_pids = [];
            foreach ($fallback as $pid => $act) {
                $pid = (int)$pid;
                if ($act === 'cancel') {
                    $conn->query("UPDATE inventory_booking_pasien SET status = 'cancelled', remark = 'Batal saat proses penyelesaian' WHERE id = $pid");
                } elseif ($act === 'reschedule') {
                    $reschedule_pids[] = $pid;
                }
            }

            // 4. Split Rescheduled patients into NEW booking
            if (!empty($reschedule_pids)) {
                // Get info for the first rescheduled patient to use as header
                $first_pid = (int)$reschedule_pids[0];
                $res_pinfo = $conn->query("SELECT nama_pasien, nomor_tlp, tanggal_lahir FROM inventory_booking_pasien WHERE id = $first_pid");
                $pinfo = $res_pinfo->fetch_assoc();
                $new_nama = $pinfo['nama_pasien'] ?? 'Patient';
                $new_tlp = $pinfo['nomor_tlp'] ?? null;
                $new_tgl_lahir = $pinfo['tanggal_lahir'] ?? null;

                // Generate new nomor_booking with -R suffix
                $orig_nomor = (string)($booking['nomor_booking'] ?? '');
                if (preg_match('/-R(\d+)$/', $orig_nomor, $m)) {
                    $next_r = (int)$m[1] + 1;
                    $new_nomor = preg_replace('/-R\d+$/', '-R' . $next_r, $orig_nomor);
                } else {
                    $new_nomor = $orig_nomor . '-R1';
                }
                
                $stmt_clone = $conn->prepare("INSERT INTO inventory_booking_pemeriksaan 
                    (nomor_booking, order_id, klinik_id, status_booking, nama_pemesan, jumlah_pax, nakes_hc, catatan, tanggal_pemeriksaan, jam_layanan, status, created_by, created_at, booking_type, jotform_submitted, cs_name, nomor_tlp, tanggal_lahir, reschedule_reason)
                    SELECT ?, order_id, klinik_id, status_booking, ?, ?, nakes_hc, catatan, ?, ?, 'rescheduled', ?, NOW(), booking_type, jotform_submitted, cs_name, ?, ?, ?
                    FROM inventory_booking_pemeriksaan WHERE id = ?");
                $pax_count = count($reschedule_pids);
                $stmt_clone->bind_param("ssisissssi", $new_nomor, $new_nama, $pax_count, $res_date, $res_time, $created_by, $new_tlp, $new_tgl_lahir, $res_reason, $id);
                $stmt_clone->execute();
                $new_id = $conn->insert_id;

                foreach ($reschedule_pids as $pid) {
                    // 1. Mark original patient as rescheduled in the current booking
                    $conn->query("UPDATE inventory_booking_pasien SET status = 'rescheduled', remark = 'Rescheduled ke #$new_nomor' WHERE id = $pid");
                    
                    // 2. Clone patient to the NEW booking
                    $stmt_p_clone = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir, status) SELECT ?, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir, 'booked' FROM inventory_booking_pasien WHERE id = ?");
                    $stmt_p_clone->bind_param("ii", $new_id, $pid);
                    $stmt_p_clone->execute();
                    $new_pid = $conn->insert_id;

                    // 3. Recalculate details for new booking using the CLONED patient ID
                    $res_p = $conn->query("SELECT pemeriksaan_grup_id FROM inventory_booking_pasien WHERE id = $new_pid");
                    if ($p_row = $res_p->fetch_assoc()) {
                        $gid = $p_row['pemeriksaan_grup_id'];
                        $gid_esc = $conn->real_escape_string($gid);
                        $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$gid_esc'");
                        while($i_row = $res_items->fetch_assoc()) {
                            $bid = (int)$i_row['barang_id']; $qty = (float)$i_row['qty_per_pemeriksaan'];
                            $q_onsite = $is_hc ? 0 : $qty; $q_hc = $is_hc ? $qty : 0;
                            $conn->query("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES ($new_id, $new_pid, $bid, $qty, $q_onsite, $q_hc)");
                        }
                    }
                }
                logBookingHistory($conn, $id, 'reschedule', [], "Pasien split ke booking baru $new_nomor karena reschedule.");
                logBookingHistory($conn, $new_id, 'create', [], "Booking hasil reschedule/split dari #" . $booking['nomor_booking']);
            }

            // 5. Finalize main booking
            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'completed' WHERE id = $id");
            $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $id"); // Release remaining stock for this ID
            
            $msg = "Proses penyelesaian booking berhasil diperbarui.";
            break;

        case 'fu':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $conn->query("UPDATE inventory_booking_pemeriksaan SET butuh_fu = 1 WHERE id = $id");
            $msg = "Booking ditandai FU jadwal kedatangan.";
            break;
            
        case 'request_delete':
            throw new Exception('Akses pengajuan penghapusan dinonaktifkan untuk Admin Klinik.');
            break;

        case 'approve_request':
            if ($role !== 'super_admin') {
                throw new Exception('Hanya Super Admin yang dapat memberikan approval');
            }
            
            if ($booking['status'] === 'pending_delete') {
                // Execute deletion (cancel)
                $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'cancelled', butuh_fu = 0, spv_approved_by = " . (int)$_SESSION['user_id'] . ", spv_approved_at = NOW() WHERE id = $id");
                // Clear details (stock released)
                $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $id");
                $msg = "Permintaan penghapusan disetujui. Booking telah dibatalkan.";
            } elseif ($booking['status'] === 'pending_edit') {
                // Apply pending data
                $pending_data = json_decode($booking['pending_data'], true);
                if (!$pending_data) throw new Exception("Data perubahan tidak valid");
                
                $nama_pemesan = $pending_data['nama_pemesan'];
                $nomor_tlp = $pending_data['nomor_tlp'];
                $tanggal_lahir = $pending_data['tanggal_lahir'];
                $tanggal = $pending_data['tanggal'];
                $booking_type = $pending_data['booking_type'];
                $jam_layanan = $pending_data['jam_layanan'];
                $jotform_submitted = $pending_data['jotform_submitted'];
                $target_klinik_id = $pending_data['klinik_id'];
                $target_status_booking = $pending_data['status_booking'];
                $jumlah_pax = $pending_data['jumlah_pax'];
                $patients = $pending_data['patients'];

                $butuh_fu = (int)($booking['butuh_fu'] ?? 0);
                if ($tanggal !== ($booking['tanggal_pemeriksaan'] ?? '') || $jam_layanan !== ($booking['jam_layanan'] ?? '')) {
                    $butuh_fu = 0;
                }

                // Re-calculate OOS for the new data
                $total_needed = [];
                foreach ($patients as $p) {
                    $p_exams = $p['exams'] ?? [];
                    foreach ($p_exams as $pid) {
                        $pid_esc = $conn->real_escape_string(trim((string)$pid));
                        $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc' AND is_mandatory = 1");
                        while($row = $res->fetch_assoc()) {
                            $bid = intval($row['barang_id']);
                            $qty = (float)$row['qty_per_pemeriksaan'];
                            $total_needed[$bid] = ($total_needed[$bid] ?? 0) + $qty;
                        }
                    }
                }
                
                $out_of_stock_items = [];
                $is_hc = (stripos($target_status_booking, 'HC') !== false);
                foreach ($total_needed as $bid => $qty_need) {
                    $ef = stock_effective($conn, (int)$target_klinik_id, $is_hc, $bid);
                    if (!$ef['ok']) continue;
                    $avail = (float)($ef['available'] ?? 0);
                    if ($avail < $qty_need) {
                        $nm = (string)($ef['barang_name'] ?? ("ID:$bid"));
                        $out_of_stock_items[] = "$nm (Sisa: $avail, Butuh: $qty_need)";
                    }
                }
                $is_oos = !empty($out_of_stock_items) ? 1 : 0;
                $oos_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;

                // Update Header
                $stmt_u = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET 
                    nama_pemesan = ?, nomor_tlp = ?, tanggal_lahir = ?, tanggal_pemeriksaan = ?, 
                    booking_type = ?, jam_layanan = ?, jotform_submitted = ?, status = 'booked', 
                    butuh_fu = ?, klinik_id = ?, status_booking = ?, jumlah_pax = ?, is_out_of_stock = ?, 
                    out_of_stock_items = ?, spv_approved_by = ?, spv_approved_at = NOW(), 
                    pending_data = NULL 
                    WHERE id = ?");
                $stmt_u->bind_param("ssssssiiisiisi", 
                    $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, 
                    $booking_type, $jam_layanan, $jotform_submitted, 
                    $butuh_fu, $target_klinik_id, $target_status_booking, $jumlah_pax, $is_oos, 
                    $oos_str, $_SESSION['user_id'], $id);
                $stmt_u->execute();

                // Clear and re-insert details
                $conn->query("DELETE FROM inventory_booking_detail WHERE booking_id = $id");
                $conn->query("DELETE FROM inventory_booking_pasien WHERE booking_id = $id");

                $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir) VALUES (?, ?, ?, ?, ?)");
                $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($patients as $idx => $p) {
                    $pnama = !empty($p['nama']) ? $p['nama'] : "Pasien " . ($idx + 1);
                    $ptlp  = !empty($p['nomor_tlp']) ? trim($p['nomor_tlp']) : null;
                    $ptgl  = !empty($p['tanggal_lahir']) ? $p['tanggal_lahir'] : null;
                    $p_exams = $p['exams'] ?? [];

                    foreach ($p_exams as $pid) {
                        $pid = trim((string)$pid);
                        $stmt_pasien->bind_param("issss", $id, $pnama, $pid, $ptlp, $ptgl);
                        $stmt_pasien->execute();
                        $pasien_id = $conn->insert_id;

                        $pid_esc = $conn->real_escape_string($pid);
                        $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc' AND is_mandatory = 1");
                        while ($i_row = $res_items->fetch_assoc()) {
                            $bid = (int)$i_row['barang_id'];
                            $qty_unit = (float)$i_row['qty_per_pemeriksaan'];
                            $qty_onsite = $is_hc ? 0 : $qty_unit;
                            $qty_hc = $is_hc ? $qty_unit : 0;

                            $stmt_detail->bind_param("iiiddd", $id, $pasien_id, $bid, $qty_unit, $qty_onsite, $qty_hc);
                            $stmt_detail->execute();
                        }
                    }
                }
                $msg = "Permintaan perubahan disetujui. Data booking telah diperbarui.";
            } else {
                throw new Exception("Status booking tidak valid untuk approval");
            }
            break;

        case 'reject_request':
            if ($role !== 'super_admin') {
                throw new Exception('Hanya Super Admin yang dapat menolak request');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (empty($reason)) throw new Exception('Alasan penolakan wajib diisi!');
            
            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'rejected', approval_reason = CONCAT(COALESCE(approval_reason, ''), ' | Ditolak: ', '" . $conn->real_escape_string($reason) . "') WHERE id = $id");
            $msg = "Request telah ditolak.";
            break;
            
        case 'move':
            // Move booking
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $new_status = (string)($_POST['new_status'] ?? ($_GET['new_status'] ?? ''));
            $new_status = trim($new_status);
            if (!in_array($new_status, ['Reserved - Clinic', 'Reserved - HC'], true)) {
                throw new Exception("Status baru tidak valid");
            }
            $target_is_hc = (stripos($new_status, 'HC') !== false);
            $lock_name = 'booking_action_' . (int)($booking['klinik_id'] ?? 0);
            $lock_esc = $conn->real_escape_string($lock_name);
            $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
            $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
            if ($got_lock !== 1) throw new Exception('Sistem sedang memproses booking lain. Coba lagi sebentar.');
            try {
                // Moving booking should check stock but allow even if empty (following new policy)
                $out_of_stock_items = [];
                foreach ($items_to_process as $item) {
                    $barang_id = (int)($item['barang_id'] ?? 0);
                    $need = (float)($item['qty_gantung'] ?? 0);
                    if ($barang_id <= 0 || $need <= 0) continue;
                    $ef = stock_effective($conn, (int)($booking['klinik_id'] ?? 0), $target_is_hc, $barang_id);
                    if (!$ef['ok']) continue;
                    $avail = (float)($ef['available'] ?? 0);
                    if ($avail < $need) {
                        $nm = (string)($ef['barang_name'] ?? ("ID:$barang_id"));
                        $out_of_stock_items[] = "$nm (Sisa: $avail, Butuh: $need)";
                    }
                }

                $is_oos = !empty($out_of_stock_items) ? 1 : 0;
                $oos_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;

                $conn->query("UPDATE inventory_booking_pemeriksaan SET 
                                status_booking = '" . $conn->real_escape_string($new_status) . "',
                                is_out_of_stock = $is_oos,
                                out_of_stock_items = " . ($oos_str ? "'" . $conn->real_escape_string($oos_str) . "'" : "NULL") . "
                              WHERE id = $id");
                
                if ($target_is_hc) {
                    $conn->query("UPDATE inventory_booking_detail SET qty_reserved_hc = qty_gantung, qty_reserved_onsite = 0 WHERE booking_id = $id");
                } else {
                    $conn->query("UPDATE inventory_booking_detail SET qty_reserved_onsite = qty_gantung, qty_reserved_hc = 0 WHERE booking_id = $id");
                }
                $msg = "Booking berhasil dipindahkan ke $new_status." . ($is_oos ? " (Peringatan: Stok tidak mencukupi di lokasi tujuan)" : "");
            } finally {
                $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
            }
            break;
            
        case 'adjust':
            // Adjust pax - add additional pax with specific exams
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $additional_pax = (int)($_POST['additional_pax'] ?? 0);
            $patients_json = $_POST['patients'] ?? '[]';
            $additional_patients = json_decode($patients_json, true) ?: [];
            
            if ($additional_pax <= 0 || empty($additional_patients)) {
                throw new Exception("Data tambahan pax tidak valid");
            }
            
            $old_pax = (int)($booking['jumlah_pax'] ?? 0);
            $new_total_pax = $old_pax + $additional_pax;
            $is_hc = (stripos((string)$booking['status_booking'], 'HC') !== false);
            $target_klinik_id = (int)($booking['klinik_id'] ?? 0);

            // 1. Calculate total items needed for all NEW patients
            $total_needed = [];
            foreach ($additional_patients as $p) {
                $p_exams = $p['exams'] ?? [];
                foreach ($p_exams as $pid) {
                    $pid_esc = $conn->real_escape_string(trim((string)$pid));
                    $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
                    while($row = $res->fetch_assoc()) {
                        $bid = (int)$row['barang_id'];
                        $qty = (float)$row['qty_per_pemeriksaan'];
                        $total_needed[$bid] = ($total_needed[$bid] ?? 0) + $qty;
                    }
                }
            }

            // 2. Check stock for ALL needed items (allow even if empty following new policy)
            $out_of_stock_items = [];
            foreach ($total_needed as $bid => $qty_need) {
                $ef = stock_effective($conn, $target_klinik_id, $is_hc, $bid);
                if (!$ef['ok']) continue;
                $avail = (float)($ef['available'] ?? 0);
                if ($avail < $qty_need) {
                    $nm = (string)($ef['barang_name'] ?? ("ID:$bid"));
                    $out_of_stock_items[] = "$nm (Sisa: $avail, Butuh: $qty_need)";
                }
            }

            // 3. Process insertion
            $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id) VALUES (?, ?, ?)");
            $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($additional_patients as $p) {
                $p_nama = $p['nama'];
                $p_exams = $p['exams'] ?? [];

                foreach ($p_exams as $pid) {
                    $pid = trim((string)$pid);
                    $stmt_pasien->bind_param("iss", $id, $p_nama, $pid);
                    $stmt_pasien->execute();
                    $pasien_row_id = (int)$conn->insert_id;

                    $pid_esc = $conn->real_escape_string($pid);
                    $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = '$pid_esc'");
                    while ($i_row = $res_items->fetch_assoc()) {
                        $bid = (int)$i_row['barang_id'];
                        $qty_unit = (float)$i_row['qty_per_pemeriksaan'];
                        
                        $qty_onsite = 0; $qty_hc = 0;
                        if (!$is_hc) $qty_onsite = $qty_unit;
                        else $qty_hc = $qty_unit;

                        $stmt_detail->bind_param("iiiddd", $id, $pasien_row_id, $bid, $qty_unit, $qty_onsite, $qty_hc);
                        $stmt_detail->execute();
                    }
                }
            }

            $is_oos = !empty($out_of_stock_items) ? 1 : 0;
            $oos_str = !empty($out_of_stock_items) ? implode(", ", $out_of_stock_items) : null;
            
            // Update OOS status on header if now OOS
            if ($is_oos) {
                $conn->query("UPDATE inventory_booking_pemeriksaan SET 
                                is_out_of_stock = 1,
                                out_of_stock_items = CONCAT(COALESCE(out_of_stock_items, ''), ', ', '" . $conn->real_escape_string($oos_str) . "'),
                                jumlah_pax = $new_total_pax 
                              WHERE id = $id");
            } else {
                $conn->query("UPDATE inventory_booking_pemeriksaan SET jumlah_pax = $new_total_pax WHERE id = $id");
            }
            
            $msg = "Berhasil menambah $additional_pax pax (total: $new_total_pax)." . ($is_oos ? " (Peringatan: Stok tambahan tidak mencukupi)" : "");
            break;
            
        default:
            throw new Exception("Aksi tidak valid");
    }

    $conn->commit();

    // Notify Google Sheets
    notify_gsheet_booking($conn, $id, 'booking_updated');

    $return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => true, 'message' => $msg, 'redirect' => $return_url]);
        exit;
    }
    
    $_SESSION['success'] = $msg;
    redirect($return_url);

} catch (Exception $e) {
    $conn->rollback();
    $msg_err = $e->getMessage();
    $return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => $msg_err]);
        exit;
    }
    
    $_SESSION['error'] = $msg_err;
    redirect($return_url);
}

$conn->close();
?>

