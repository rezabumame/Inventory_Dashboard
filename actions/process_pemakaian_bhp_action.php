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
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];
$user_klinik_id = isset($_SESSION['klinik_id']) ? (int)$_SESSION['klinik_id'] : 0;

if (!$id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

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
        } elseif ($header['status'] === 'pending_edit') {
            // Apply pending data (JSON)
            $pending_data = json_decode($header['pending_data'], true);
            if (!$pending_data) throw new Exception("Data perubahan tidak valid");

         // Task Fix: Persiapkan payload item SEBELUM menghapus detail lama dari database
            $items_payload = build_items_from_ops($conn, $id, $pending_data);
            if (!is_array($items_payload) || empty($items_payload)) throw new Exception('Item perubahan kosong');

            // 1. Reverse OLD stock
            $stmt_old = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_old->bind_param("i", $id);
            exec_or_throw($stmt_old, 'Fetch old details');
            $old_details = $stmt_old->get_result();
            while ($item = $old_details->fetch_assoc()) {
                $bid = (int)$item['barang_id']; $qty = (float)$item['qty'];
                if ($header['jenis_pemakaian'] === 'hc' && !empty($header['user_hc_id'])) {
                    $stmt_r = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $old_uid = (int)$header['user_hc_id'];
                    $old_kid = (int)$header['klinik_id'];
                    $stmt_r->bind_param("diiii", $qty, $user_id, $bid, $old_uid, $old_kid);
                    exec_or_throw($stmt_r, 'Reverse old stock HC');
                } else {
                    $old_kid = (int)$header['klinik_id'];
                    $stmt_r = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $stmt_r->bind_param("diii", $qty, $user_id, $bid, $old_kid);
                    exec_or_throw($stmt_r, 'Reverse old stock Klinik');
                }
            }

            // 2. Update Header
            // Task: Preserve pending_data for history display after approval
            $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp SET tanggal = ?, jenis_pemakaian = ?, klinik_id = ?, user_hc_id = ?, catatan_transaksi = ?, status = 'active', change_actor_name = ?, spv_approved_by = ?, spv_approved_at = NOW() WHERE id = ?");
            $tgl_new = (string)($pending_data['tanggal'] ?? $header['tanggal']);
            $jenis_new = (string)($pending_data['jenis_pemakaian'] ?? $header['jenis_pemakaian']);
            $klinik_new = (int)($pending_data['klinik_id'] ?? $header['klinik_id']);
            $user_hc_new = ($jenis_new === 'hc') ? (isset($pending_data['user_hc_id']) ? (int)$pending_data['user_hc_id'] : null) : null;
            $cat_new = (string)($pending_data['catatan_transaksi'] ?? $header['catatan_transaksi']);
            $actor_name_new = (string)($pending_data['meta']['change_actor_name'] ?? $header['change_actor_name'] ?? '');
            $stmt_u->bind_param("ssiissii", $tgl_new, $jenis_new, $klinik_new, $user_hc_new, $cat_new, $actor_name_new, $user_id, $id);
            exec_or_throw($stmt_u, 'Update header');

            // 3. Replace details
            $stmt_del_d = $conn->prepare("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt_del_d->bind_param("i", $id);
            exec_or_throw($stmt_del_d, 'Clear old details');

            $ref_type = 'pemakaian_bhp';
            $stmt_del_t = $conn->prepare("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = ? AND referensi_id = ?");
            $stmt_del_t->bind_param("si", $ref_type, $id);
            exec_or_throw($stmt_del_t, 'Clear old stock transactions');

            $stmt_ins = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) VALUES (?, ?, ?, ?, ?)");

            foreach ($items_payload as $it) {
                $bid = (int)($it['barang_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                if ($bid <= 0 || $qty <= 0) continue;

                // Pending payload can come from different sources; normalize keys.
                $satuan = (string)($it['satuan'] ?? $it['satuan_display'] ?? '');
                if ($satuan === '') {
                    $rb = $conn->query("SELECT satuan FROM inventory_barang WHERE id = " . (int)$bid . " LIMIT 1");
                    $satuan = (string)($rb && $rb->num_rows > 0 ? ($rb->fetch_assoc()['satuan'] ?? '') : '');
                }
                $catatan_item = (string)($it['catatan_item'] ?? $it['catatan'] ?? '');

                $stmt_ins->bind_param("iidss", $id, $bid, $qty, $satuan, $catatan_item);
                exec_or_throw($stmt_ins, 'Insert detail');

                // Get stock baseline before update for logging (mirror-based, same as Inventory Klinik)
                $eff = stock_effective($conn, (int)$klinik_new, $jenis_new === 'hc', (int)$bid);
                $qty_sebelum = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;
                if ($jenis_new === 'hc' && !empty($user_hc_new)) {
                    $uid = (int)$user_hc_new;
                    $kid = (int)$klinik_new;

                    $stmt_s = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $stmt_s->bind_param("diiii", $qty, $user_id, $bid, $uid, $kid);
                    exec_or_throw($stmt_s, 'Deduct stock HC');
                    $level = 'hc';
                    $level_id = $uid;
                } else {
                    $kid = (int)$klinik_new;

                    $stmt_s = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                    $stmt_s->bind_param("diii", $qty, $user_id, $bid, $kid);
                    exec_or_throw($stmt_s, 'Deduct stock Klinik');
                    $level = 'klinik';
                    $level_id = $kid;
                }
                
                $qty_sesudah = (float)$qty_sebelum - (float)$qty;

                // Log transaction
                $ref_type = 'pemakaian_bhp';
                $cat = 'Approve edit ' . (string)($header['nomor_pemakaian'] ?? 'BHP');
                $created_at = date('Y-m-d H:i:s', strtotime($tgl_new));
                $stmt_log = $conn->prepare("
                    INSERT INTO inventory_transaksi_stok
                    (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
                    VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_log->bind_param("isidddsisis", $bid, $level, $level_id, $qty, $qty_sebelum, $qty_sesudah, $ref_type, $id, $cat, $user_id, $created_at);
                exec_or_throw($stmt_log, 'Insert stock transaction');
            }
            $msg = "Permintaan perubahan disetujui. Data pemakaian telah diperbarui.";
        } elseif ($header['status'] === 'pending_add') {
            // Apply pending data (JSON) for NEW record
            $pending_data = json_decode($header['pending_data'], true);
            if (!$pending_data) throw new Exception("Data penambahan tidak valid");

            $items_payload = $pending_data['items'] ?? [];
            if (!is_array($items_payload) || empty($items_payload)) throw new Exception('Item penambahan kosong');

            // 1. Generate REAL nomor pemakaian (replace REQ-ADD)
            $tanggal = $pending_data['tanggal'];
            $date = date('Ymd', strtotime($tanggal));
            require_once __DIR__ . '/../lib/counter.php';
            $seq = next_sequence($conn, 'BHP', $date);
            $prefix = 'BHP-' . $date . '-';
            $nomor_pemakaian = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // 2. Update Header status to active and set real number
            $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp SET nomor_pemakaian = ?, status = 'active', spv_approved_by = ?, spv_approved_at = NOW() WHERE id = ?");
            $stmt_u->bind_param("sii", $nomor_pemakaian, $user_id, $id);
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
                $created_at_val = date('Y-m-d H:i:s', strtotime($tanggal));
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
        if (!in_array((string)($header['status'] ?? ''), ['pending_add', 'pending_edit', 'pending_delete'], true)) {
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
