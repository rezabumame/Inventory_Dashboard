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
    $_SESSION['error'] = 'Unauthorized';
    redirect('index.php');
}

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($action === '' || $id <= 0) {
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=booking');
}

require_csrf();

$role = (string)($_SESSION['role'] ?? '');

$booking = $conn->query("SELECT * FROM inventory_booking_pemeriksaan WHERE id = $id")->fetch_assoc();

if (!$booking || !in_array($booking['status'], ['booked', 'pending_edit', 'pending_delete', 'rejected'])) {
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
            $msg = "Booking dibatalkan.";
            break;
        
        case 'done':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'completed', butuh_fu = 0 WHERE id = $id");
            $msg = "Booking selesai.";
            break;

        case 'fu':
            if (!in_array($role, ['admin_klinik', 'super_admin'], true)) {
                throw new Exception('Access denied');
            }
            $conn->query("UPDATE inventory_booking_pemeriksaan SET butuh_fu = 1 WHERE id = $id");
            $msg = "Booking ditandai FU jadwal kedatangan.";
            break;
            
        case 'request_delete':
            if ($role !== 'admin_klinik') {
                throw new Exception('Hanya Admin Klinik yang dapat mengajukan penghapusan lewat hari');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (empty($reason)) throw new Exception('Alasan wajib diisi!');
            
            $conn->query("UPDATE inventory_booking_pemeriksaan SET status = 'pending_delete', approval_reason = '" . $conn->real_escape_string($reason) . "' WHERE id = $id");
            $msg = "Permintaan penghapusan telah dikirim ke SPV Klinik.";
            break;

        case 'approve_request':
            if (!in_array($role, ['spv_klinik', 'super_admin'], true)) {
                throw new Exception('Hanya SPV Klinik atau Super Admin yang dapat memberikan approval');
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

                // Re-calculate OOS for the new data
                $total_needed = [];
                foreach ($patients as $p) {
                    $p_exams = $p['exams'] ?? [];
                    foreach ($p_exams as $pid) {
                        $pid = intval($pid);
                        $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid AND is_mandatory = 1");
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
                    klinik_id = ?, status_booking = ?, jumlah_pax = ?, is_out_of_stock = ?, 
                    out_of_stock_items = ?, spv_approved_by = ?, spv_approved_at = NOW(), 
                    pending_data = NULL 
                    WHERE id = ?");
                $stmt_u->bind_param("ssssssisiisiisi", 
                    $nama_pemesan, $nomor_tlp, $tanggal_lahir, $tanggal, 
                    $booking_type, $jam_layanan, $jotform_submitted, 
                    $target_klinik_id, $target_status_booking, $jumlah_pax, $is_oos, 
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
                        $pid = intval($pid);
                        $stmt_pasien->bind_param("isiis", $id, $pnama, $pid, $ptlp, $ptgl);
                        $stmt_pasien->execute();
                        $pasien_id = $conn->insert_id;

                        $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid AND is_mandatory = 1");
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
            if (!in_array($role, ['spv_klinik', 'super_admin'], true)) {
                throw new Exception('Hanya SPV Klinik atau Super Admin yang dapat menolak request');
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
                    $pid = intval($pid);
                    $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
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
                    $pid = intval($pid);
                    $stmt_pasien->bind_param("isi", $id, $p_nama, $pid);
                    $stmt_pasien->execute();
                    $pasien_row_id = (int)$conn->insert_id;

                    $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
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

