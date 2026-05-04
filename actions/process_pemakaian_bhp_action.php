<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

function exec_or_throw(mysqli_stmt $stmt, string $ctx): void {
    if (!$stmt->execute()) {
        throw new Exception($ctx . ': ' . $stmt->error);
    }
}

function build_items_from_ops(mysqli $conn, int $id, array $pending_data): array {
    $ops = $pending_data['items_ops'] ?? [];
    if (!is_array($ops) || empty($ops)) {
        $fallback = $pending_data['items'] ?? [];
        return is_array($fallback) ? $fallback : [];
    }

    $stmt_old = $conn->prepare("SELECT id, barang_id, qty, satuan, catatan_item FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
    $stmt_old->bind_param("i", $id);
    exec_or_throw($stmt_old, 'Fetch detail snapshot');
    $res_old = $stmt_old->get_result();
    $map = [];
    while ($row = $res_old->fetch_assoc()) {
        $map[(int)$row['id']] = [
            'barang_id' => (int)$row['barang_id'],
            'qty' => (float)$row['qty'],
            'satuan' => (string)$row['satuan'],
            'catatan_item' => (string)($row['catatan_item'] ?? '')
        ];
    }

    foreach ($ops as $op) {
        $opType = (string)($op['op'] ?? '');
        if ($opType === 'remove') {
            $did = (int)($op['detail_id'] ?? 0);
            unset($map[$did]);
            continue;
        }
        if ($opType === 'update') {
            $did = (int)($op['detail_id'] ?? 0);
            if (!isset($map[$did])) continue;
            $after = $op['after'] ?? [];
            $map[$did] = [
                'barang_id' => (int)($after['barang_id'] ?? 0),
                'qty' => (float)($after['qty'] ?? 0),
                'satuan' => (string)($after['satuan'] ?? ''),
                'catatan_item' => (string)($after['catatan_item'] ?? '')
            ];
            continue;
        }
        if ($opType === 'add') {
            $map[-(count($map) + 1)] = [
                'barang_id' => (int)($op['barang_id'] ?? 0),
                'qty' => (float)($op['qty'] ?? 0),
                'satuan' => (string)($op['satuan'] ?? ''),
                'catatan_item' => (string)($op['catatan_item'] ?? '')
            ];
        }
    }

    return array_values($map);
}

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');
    $user_id = $_SESSION['user_id'];
    $user_klinik_id = isset($_SESSION['klinik_id']) ? (int)$_SESSION['klinik_id'] : 0;

    if ($id <= 0 || $action === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request params']);
        exit;
    }

    // CSRF protection
    require_csrf();

$lock_esc = '';

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT * FROM inventory_pemakaian_bhp WHERE id = ?");
    $stmt->bind_param("i", $id);
    exec_or_throw($stmt, 'Fetch header');
    $header = $stmt->get_result()->fetch_assoc();

    if (!$header) throw new Exception("Data tidak ditemukan");

    if (!in_array($role, ['spv_klinik', 'super_admin'], true)) {
        throw new Exception("Hanya SPV Klinik atau Super Admin yang dapat memberikan approval");
    }
    if ($role === 'spv_klinik' && (int)($header['klinik_id'] ?? 0) !== $user_klinik_id) {
        throw new Exception("SPV Klinik hanya dapat memproses data kliniknya sendiri");
    }

    // Lock per clinic+jenis to prevent concurrent stock mutation conflicts.
    $jenis_lock = preg_replace('/[^a-z]/', '', (string)($header['jenis_pemakaian'] ?? ''));
    $lock_name = 'stock_pemakaian_bhp_' . (int)($header['klinik_id'] ?? 0) . '_' . $jenis_lock;
    $lock_esc = $conn->real_escape_string($lock_name);
    $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got_lock !== 1) {
        throw new Exception('Sistem sedang memproses stok klinik ini. Coba lagi sebentar.');
    }

    if ($action === 'approve') {
        if ($header['status'] === 'pending_delete') {
            // Re-use logic from process_pemakaian_bhp_delete.php (reverse stock)
            $jenis_pemakaian = $header['jenis_pemakaian'];
            $klinik_id = $header['klinik_id'];
            $user_hc_id = $header['user_hc_id'];

            $stmt_d = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_d->bind_param("i", $id);
            exec_or_throw($stmt_d, 'Fetch details');
            $details = $stmt_d->get_result();

            while ($item = $details->fetch_assoc()) {
                $bid = (int)$item['barang_id'];
                $qty = (float)$item['qty'];

                if ($jenis_pemakaian === 'hc' && !empty($user_hc_id)) {
                    $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $stmt_upd->bind_param("diiii", $qty, $user_id, $bid, $user_hc_id, $klinik_id);
                    exec_or_throw($stmt_upd, 'Reverse stock HC');
                } elseif ($jenis_pemakaian === 'klinik') {
                    $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $stmt_upd->bind_param("diii", $qty, $user_id, $bid, $klinik_id);
                    exec_or_throw($stmt_upd, 'Reverse stock Klinik');
                }
            }

            // Delete record
            $stmt_del1 = $conn->prepare("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_del1->bind_param("i", $id);
            exec_or_throw($stmt_del1, 'Delete details');

            $ref_type = 'pemakaian_bhp';
            $stmt_del2 = $conn->prepare("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = ? AND referensi_id = ?");
            $stmt_del2->bind_param("si", $ref_type, $id);
            exec_or_throw($stmt_del2, 'Delete stock transactions');

            $stmt_del3 = $conn->prepare("DELETE FROM inventory_pemakaian_bhp WHERE id = ?");
            $stmt_del3->bind_param("i", $id);
            exec_or_throw($stmt_del3, 'Delete header');
            
            $msg = "Permintaan penghapusan disetujui. Data telah dihapus dan stok dikembalikan.";
        } elseif ($header['status'] === 'pending_approval_spv') {
            $parent_id = $header['parent_id'];
            
            if ($parent_id) {
                // Logic for Revision Approval (Delta-based)
                // Parse pending_data to get delta_items
                $pending_data = json_decode($header['pending_data'], true);
                if (!$pending_data || !isset($pending_data['delta_items'])) {
                    throw new Exception("Data delta revisi tidak valid");
                }
                $delta_items = $pending_data['delta_items'];

                // Get original header details for logging context
                $stmt_original_header = $conn->prepare("SELECT nomor_pemakaian, jenis_pemakaian, klinik_id, user_hc_id FROM inventory_pemakaian_bhp WHERE id = ? LIMIT 1");
                $stmt_original_header->bind_param("i", $parent_id);
                $stmt_original_header->execute();
                $original_header = $stmt_original_header->get_result()->fetch_assoc();
                if (!$original_header) {
                    throw new Exception("Header original tidak ditemukan untuk revisi ini.");
                }

                $jenis_pemakaian_ctx = $original_header['jenis_pemakaian'];
                $klinik_id_ctx = (int)$original_header['klinik_id'];
                $user_hc_id_ctx = (int)$original_header['user_hc_id'];
                $original_nomor_ctx = $original_header['nomor_pemakaian'];

                // Apply deltas to stock
                foreach ($delta_items as $delta_item) {
                    $bid = (int)$delta_item['barang_id'];
                    $qty_delta = (float)$delta_item['qty']; // This is the delta
                    $satuan = (string)$delta_item['satuan'];
                    $catatan_item = (string)$delta_item['catatan_item'];

                    if (abs($qty_delta) < 0.000001) continue; // Skip zero deltas

                    $eff = stock_effective($conn, $klinik_id_ctx, $jenis_pemakaian_ctx === 'hc', $bid);
                    $qty_sebelum = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;

                    // Adjust stock based on delta
                    if ($jenis_pemakaian_ctx === 'hc' && !empty($user_hc_id_ctx)) {
                        $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                        $stmt_upd->bind_param("diiii", $qty_delta, $user_id, $bid, $user_hc_id_ctx, $klinik_id_ctx);
                        exec_or_throw($stmt_upd, 'Apply delta stock HC');
                        $level = 'hc';
                        $level_id = $user_hc_id_ctx;
                    } else {
                        $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                        $stmt_upd->bind_param("diii", $qty_delta, $user_id, $bid, $klinik_id_ctx);
                        exec_or_throw($stmt_upd, 'Apply delta stock Klinik');
                        $level = 'klinik';
                        $level_id = $klinik_id_ctx;
                    }

                    $qty_sesudah = $qty_sebelum - $qty_delta;
                    
                    // Logic: if delta is positive (more usage), it's 'out'. 
                    // If delta is negative (less usage), it's 'in'.
                    $tipe_transaksi = $qty_delta > 0 ? 'out' : 'in'; 
                    $log_qty = abs($qty_delta);

                    // Log transaction
                    $ref_type = 'pemakaian_bhp'; 
                    $cat = 'Revisi ' . $header['nomor_pemakaian'] . ' disetujui (Delta). Parent: ' . $original_nomor_ctx;
                    
                    $created_at_val = date('Y-m-d H:i:s'); 

                    $stmt_log = $conn->prepare("
                        INSERT INTO inventory_transaksi_stok
                        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_log->bind_param("isidddsissis", $bid, $level, $level_id, $tipe_transaksi, $log_qty, $qty_sebelum, $qty_sesudah, $ref_type, $id, $cat, $user_id, $created_at_val);
                    exec_or_throw($stmt_log, 'Insert stock transaction for delta revision');
                }

                // Mark the REVISION record as active
                $now_val = date('Y-m-d H:i:s');
                $stmt_upd = $conn->prepare("UPDATE inventory_pemakaian_bhp SET status = 'active', spv_approved_by = ?, spv_approved_at = NOW(), created_at = ? WHERE id = ?");
                $stmt_upd->bind_param("isi", $user_id, $now_val, $id);
                exec_or_throw($stmt_upd, 'Mark revision as active');

                $msg = "Revisi " . $header['nomor_pemakaian'] . " telah disetujui dan delta perubahan telah diterapkan.";
            } else {
                // Logic for New Backdated Record Approval (No parent_id)
                // Items are already in inventory_pemakaian_bhp_detail
                $stmt_d = $conn->prepare("SELECT barang_id, qty, satuan, catatan_item FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
                $stmt_d->bind_param("i", $id);
                exec_or_throw($stmt_d, 'Fetch details');
                $details = $stmt_d->get_result();

                $kid = (int)$header['klinik_id'];
                $jenis_pemakaian = $header['jenis_pemakaian'];
                $user_hc_id = (int)$header['user_hc_id'];

                while ($item = $details->fetch_assoc()) {
                    $bid = (int)$item['barang_id'];
                    $qty = (float)$item['qty'];
                    if ($bid <= 0 || $qty <= 0) continue;

                    // Stock deduction
                    $eff = stock_effective($conn, $kid, $jenis_pemakaian === 'hc', $bid);
                    $qty_sebelum = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;
                    
                    if ($jenis_pemakaian === 'hc' && !empty($user_hc_id)) {
                        $stmt_s = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                        $stmt_s->bind_param("diiii", $qty, $user_id, $bid, $user_hc_id, $kid);
                        exec_or_throw($stmt_s, 'Deduct stock HC');
                        $level = 'hc';
                        $level_id = $user_hc_id;
                    } else {
                        $stmt_s = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                        $stmt_s->bind_param("diii", $qty, $user_id, $bid, $kid);
                        exec_or_throw($stmt_s, 'Deduct stock Klinik');
                        $level = 'klinik';
                        $level_id = $kid;
                    }

                    $qty_sesudah = $qty_sebelum - $qty;

                    // Log transaction
                    $ref_type = 'pemakaian_bhp';
                    $cat = 'Approve backdate upload ' . $header['nomor_pemakaian'];
                    $created_at_val = date('Y-m-d H:i:s');
                    $stmt_log = $conn->prepare("
                        INSERT INTO inventory_transaksi_stok
                        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                        VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_log->bind_param("isidddsisis", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $ref_type, $id, $cat, $user_id, $created_at_val);
                    exec_or_throw($stmt_log, 'Insert stock transaction');
                }

                // Update Header status to active
                $now_val = date('Y-m-d H:i:s');
                $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp SET status = 'active', spv_approved_by = ?, spv_approved_at = NOW(), created_at = ? WHERE id = ?");
                $stmt_u->bind_param("isi", $user_id, $now_val, $id);
                exec_or_throw($stmt_u, 'Update header to active');

                $msg = "Permintaan data backdate " . $header['nomor_pemakaian'] . " disetujui.";
            }
        } elseif ($header['status'] === 'pending_add') {
            // Apply pending data (JSON) for NEW record
            $pending_data = json_decode($header['pending_data'], true);
            if (!$pending_data) throw new Exception("Data penambahan tidak valid");

            $items_payload = $pending_data['items'] ?? [];
            if (!is_array($items_payload) || empty($items_payload)) throw new Exception('Item penambahan kosong');

            $tanggal = $header['tanggal']; // Use original date from record
            $kid = (int)$header['klinik_id'];
            $jenis_pemakaian = $header['jenis_pemakaian'];
            
            // Get clinic code
            $res_k = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $kid LIMIT 1");
            $k_row = $res_k->fetch_assoc();
            $k_code_raw = ($jenis_pemakaian === 'hc') ? ($k_row['kode_homecare'] ?? 'HC') : ($k_row['kode_klinik'] ?? 'CLN');
            $k_code = explode('/', $k_code_raw)[0]; // Strip /Stock
            $date_ym = date('ym', strtotime($tanggal));

            require_once __DIR__ . '/../lib/counter.php';
            
            $prefix = 'BHP-' . $k_code . '-' . $date_ym . '-';
            $seq_prefix = 'BHP-' . $k_code;
            $max_retries = 10;
            $nomor_pemakaian = '';
            for ($i = 0; $i < $max_retries; $i++) {
                $seq = next_sequence($conn, $seq_prefix, $date_ym);
                $temp_nomor = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                
                $stmt_check = $conn->prepare("SELECT id FROM inventory_pemakaian_bhp WHERE nomor_pemakaian = ? LIMIT 1");
                $stmt_check->bind_param("s", $temp_nomor);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows === 0) {
                    $nomor_pemakaian = $temp_nomor;
                    break;
                }
            }
            
            if (empty($nomor_pemakaian)) {
                throw new Exception('Gagal membuat nomor pemakaian unik.');
            }

            // 2. Update Header status to active and set real number
            $now_val = date('Y-m-d H:i:s');
            $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp SET nomor_pemakaian = ?, status = 'active', spv_approved_by = ?, spv_approved_at = NOW(), created_at = ? WHERE id = ?");
            $stmt_u->bind_param("sisi", $nomor_pemakaian, $user_id, $now_val, $id);
            exec_or_throw($stmt_u, 'Update header to active');

            $jenis_new = $header['jenis_pemakaian'];
            $klinik_new = $header['klinik_id'];
            $user_hc_new = $header['user_hc_id'];

            // 3. Process Items
            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) VALUES (?, ?, ?, ?, ?)");

            foreach ($items_payload as $it) {
                $bid = (int)($it['barang_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                if ($bid <= 0 || $qty <= 0) continue;

                $satuan = (string)($it['satuan'] ?? '');
                if ($satuan === '') {
                    $rb = $conn->query("SELECT satuan FROM inventory_barang WHERE id = " . (int)$bid . " LIMIT 1");
                    $satuan = (string)($rb && $rb->num_rows > 0 ? ($rb->fetch_assoc()['satuan'] ?? '') : '');
                }
                $catatan_item = (string)($it['catatan_item'] ?? '');

                $stmt_ins->bind_param("iidss", $id, $bid, $qty, $satuan, $catatan_item);
                exec_or_throw($stmt_ins, 'Insert detail');

                // Stock deduction
                $eff = stock_effective($conn, (int)$klinik_new, $jenis_new === 'hc', (int)$bid);
                $qty_sebelum = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;
                
                if ($jenis_new === 'hc' && !empty($user_hc_new)) {
                    $uid = (int)$user_hc_new;
                    $stmt_s = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $stmt_s->bind_param("diiii", $qty, $user_id, $bid, $uid, $klinik_new);
                    exec_or_throw($stmt_s, 'Deduct stock HC');
                    $level = 'hc';
                    $level_id = $uid;
                } else {
                    $stmt_s = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $stmt_s->bind_param("diii", $qty, $user_id, $bid, $klinik_new);
                    exec_or_throw($stmt_s, 'Deduct stock Klinik');
                    $level = 'klinik';
                    $level_id = (int)$klinik_new;
                }

                $qty_sesudah = (float)$qty_sebelum - (float)$qty;

                // Log transaction
                $ref_type = 'pemakaian_bhp';
                $cat = 'Approve backdate add ' . $nomor_pemakaian;
                $created_at_val = date('Y-m-d H:i:s');
                $stmt_log = $conn->prepare("
                    INSERT INTO inventory_transaksi_stok
                    (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                    VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_log->bind_param("isidddsisis", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $ref_type, $id, $cat, $user_id, $created_at_val);
                exec_or_throw($stmt_log, 'Insert stock transaction');
            }
            $msg = "Permintaan penambahan data backdate disetujui.";
        } else {
            throw new Exception("Status tidak valid untuk approval");
        }
    } elseif ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (empty($reason)) throw new Exception("Alasan penolakan wajib diisi!");
        if (!in_array((string)($header['status'] ?? ''), ['pending_add', 'pending_edit', 'pending_delete', 'pending_approval_spv'], true)) {
            throw new Exception("Status tidak valid untuk penolakan");
        }

        $reason2 = ' | Ditolak: ' . $reason;
        $stmt_rj = $conn->prepare("UPDATE inventory_pemakaian_bhp SET status = 'rejected', approval_reason = CONCAT(COALESCE(approval_reason, ''), ?) WHERE id = ?");
        $stmt_rj->bind_param("si", $reason2, $id);
        exec_or_throw($stmt_rj, 'Reject request');
        $msg = "Permintaan telah ditolak.";
    }

    $conn->commit();
    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    $conn->rollback();
    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
