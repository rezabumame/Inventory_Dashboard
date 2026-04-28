<?php
session_start();
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/counter.php';
require_once __DIR__ . '/../lib/stock.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
}

// Check access
check_role(['admin_klinik', 'super_admin', 'admin_gudang', 'spv_klinik', 'petugas_hc']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=pemakaian_bhp_list');
}

require_csrf();
error_log('Pemakaian BHP - User: ' . $_SESSION['user_id'] . ', Role: ' . $_SESSION['role']);

function parse_request_items_from_json(string $json): array {
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function validate_change_actor(array $user_row, string $source): bool {
    $role = (string)($user_row['role'] ?? '');
    if ($source === 'admin_logistik') return $role === 'admin_klinik';
    if ($source === 'nakes') return $role === 'petugas_hc';
    return true; // sistem_integrasi => semua user aktif
}

$dedup_key = sha1('process_pemakaian_bhp|' . (int)($_SESSION['user_id'] ?? 0) . '|' . json_encode(array_diff_key($_POST, ['_csrf' => true])));
if (!isset($_SESSION['_dedup'])) $_SESSION['_dedup'] = [];
$now = time();
if (!empty($_SESSION['_dedup'][$dedup_key]) && ($now - (int)$_SESSION['_dedup'][$dedup_key]) < 8) {
    $msg_error = 'Request duplikat terdeteksi. Silakan tunggu beberapa detik dan coba lagi.';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => $msg_error]);
        exit;
    }
    $_SESSION['error'] = $msg_error;
    redirect('index.php?page=pemakaian_bhp_list');
}
$_SESSION['_dedup'][$dedup_key] = $now;

$lock_esc = '';

$conn->begin_transaction();

try {
    // Get form data
    $edit_id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');

    // If today, use full current timestamp to show time in UI
    if (date('Y-m-d', strtotime($tanggal)) === date('Y-m-d')) {
        $tanggal = date('Y-m-d H:i:s');
    }
    $jenis_pemakaian = $_POST['jenis_pemakaian'];
    $klinik_id = $_POST['klinik_id'] ?? $_SESSION['klinik_id'];
    $user_hc_id = isset($_POST['user_hc_id']) ? (int)$_POST['user_hc_id'] : null;
    $catatan_transaksi = $_POST['catatan_transaksi'] ?? null;
    $created_by = $_SESSION['user_id'];
    $items = $_POST['items'] ?? [];

    // Validation
    if (empty($items)) {
        throw new Exception('Tidak ada item yang dipilih');
    }

    if (empty($klinik_id)) {
        throw new Exception('Klinik tidak valid');
    }

    // --- BACKDATE CHECK FOR NEW RECORD (H-2) ---
    $is_request_approval = isset($_POST['is_request_approval']) && $_POST['is_request_approval'] === '1';
    
    // Check if backdate (H-2)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $selected_date = date('Y-m-d', strtotime($tanggal));
    $is_backdate = ($selected_date < $yesterday);

    if (!$edit_id && $is_backdate && !$is_request_approval) {
        // If backdate but not via approval flow, check role
        if ($_SESSION['role'] === 'admin_klinik') {
            throw new Exception("Tanggal pemakaian lebih dari H-2 memerlukan approval SPV");
        }
    }

    if ($jenis_pemakaian === 'hc') {
        if ($_SESSION['role'] === 'petugas_hc') {
            $user_hc_id = (int)$created_by;
            $klinik_id = (int)($_SESSION['klinik_id'] ?? $klinik_id);
        }
        if (empty($user_hc_id)) throw new Exception('Petugas HC wajib dipilih');
        $r_u = $conn->query("SELECT id, klinik_id FROM inventory_users WHERE id = " . (int)$user_hc_id . " AND role = 'petugas_hc' AND status = 'active' LIMIT 1");
        if (!$r_u || $r_u->num_rows === 0) throw new Exception('Petugas HC tidak valid');
        $urow = $r_u->fetch_assoc();
        
        // Ownership Check
        if ($_SESSION['role'] !== 'super_admin' && (int)($urow['klinik_id'] ?? 0) !== (int)$_SESSION['klinik_id']) {
            throw new Exception('Anda tidak memiliki otoritas untuk memproses petugas HC dari klinik lain');
        }
        if ((int)($urow['klinik_id'] ?? 0) !== (int)$klinik_id) throw new Exception('Petugas HC tidak terdaftar di klinik ini');
    } else {
        $user_hc_id = null;
        // Ownership Check for Clinic
        if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'admin_gudang' && (int)$klinik_id !== (int)$_SESSION['klinik_id']) {
            throw new Exception('Akses Ditolak: Anda hanya diperbolehkan memproses data untuk klinik Anda sendiri');
        }
    }

    // Lock per clinic+jenis to prevent concurrent stock mutation conflicts
    $lock_name = 'stock_pemakaian_bhp_' . (int)$klinik_id . '_' . preg_replace('/[^a-z]/', '', (string)$jenis_pemakaian);
    $lock_esc = $conn->real_escape_string($lock_name);
    $rl = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got_lock = (int)($rl && $rl->num_rows > 0 ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got_lock !== 1) {
        throw new Exception('Sistem sedang memproses stok klinik ini. Coba lagi sebentar.');
    }

    if ($edit_id) {
        // --- EDIT MODE ---
        // 1. Fetch old record for permission check and stock reversal
        $stmt = $conn->prepare("SELECT * FROM inventory_pemakaian_bhp WHERE id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $old_header = $stmt->get_result()->fetch_assoc();

        if (!$old_header) {
            throw new Exception("Data lama tidak ditemukan");
        }

        // Permission check
        $created_date = date('Y-m-d', strtotime($old_header['created_at']));
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // H-0 and H-1 are considered within grace period (no approval needed)
        $is_today_edit = ($created_date === $today || $created_date === $yesterday);
        
        $is_admin_klinik = $_SESSION['role'] === 'admin_klinik';
        $is_super_admin = $_SESSION['role'] === 'super_admin';
        $reason = $_POST['reason'] ?? '';

        // Super Admin can always edit directly
        if ($is_super_admin) {
            // No special handling, proceed to direct edit
        } else {
            if ($is_today_edit) {
                // Same day: Creator or Admin Klinik of the same clinic
                if ($old_header['created_by'] != $created_by && !($is_admin_klinik && (int)$old_header['klinik_id'] === (int)$_SESSION['klinik_id'])) {
                    throw new Exception("Anda tidak memiliki akses untuk mengubah data ini");
                }
            } else {
                // Past day: Admin Klinik can request edit
                if ($is_admin_klinik && (int)$old_header['klinik_id'] === (int)$_SESSION['klinik_id']) {
                    if (empty($reason)) {
                        throw new Exception("Alasan wajib diisi untuk perubahan lewat hari");
                    }
                    $change_source = trim((string)($_POST['change_source'] ?? ''));
                    $change_actor_user_id = isset($_POST['change_actor_user_id']) && (int)$_POST['change_actor_user_id'] > 0 ? (int)$_POST['change_actor_user_id'] : null;
                    $change_actor_name = trim((string)($_POST['change_actor_name'] ?? ''));
                    $valid_sources = ['admin_logistik', 'nakes', 'sistem_integrasi'];
                    if (!in_array($change_source, $valid_sources, true)) {
                        throw new Exception("Sumber perubahan wajib dipilih");
                    }

                    if ($change_source === 'sistem_integrasi') {
                        if (empty($change_actor_name)) {
                            throw new Exception("Nama pelaku asal wajib diisi untuk sumber sistem/integrasi");
                        }
                    } else {
                        // Task: Validasi pelaku asal dari master user diubah menjadi non-blocking
                        if ($change_actor_user_id > 0) {
                            $stmt_actor = $conn->prepare("SELECT id, role, status FROM inventory_users WHERE id = ? LIMIT 1");
                            $stmt_actor->bind_param("i", $change_actor_user_id);
                            $stmt_actor->execute();
                            $actor = $stmt_actor->get_result()->fetch_assoc();
                            
                            if ($actor && (string)($actor['status'] ?? '') === 'active') {
                                if (!validate_change_actor($actor, $change_source)) {
                                    // Non-blocking: Tetap izinkan jika role tidak sesuai, tapi mungkin log atau biarkan saja
                                }
                            } else {
                                // Non-blocking: Jika user tidak ditemukan atau tidak aktif, biarkan change_actor_user_id NULL
                                $change_actor_user_id = null;
                            }
                        }
                        
                        // Fallback ke change_actor_name jika user id tidak valid/tidak ada
                        if (empty($change_actor_user_id) && empty($change_actor_name)) {
                            // Jika benar-benar kosong, baru throw error (opsional, tapi sebaiknya ada identitas)
                            // throw new Exception("Pelaku asal wajib diisi (Master User atau Nama Free Text)");
                        }
                    }

                    $ops_json = (string)($_POST['request_items_json'] ?? '');
                    $request_ops = parse_request_items_from_json($ops_json);
                    if (empty($request_ops)) {
                        throw new Exception("Detail perubahan item wajib dipilih (Tambah/Ubah/Hapus)");
                    }

                    $stmt_old_items = $conn->prepare("SELECT id, barang_id, qty, satuan, catatan_item FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
                    $stmt_old_items->bind_param("i", $edit_id);
                    $stmt_old_items->execute();
                    $old_items_res = $stmt_old_items->get_result();
                    $old_items_map = [];
                    while ($r = $old_items_res->fetch_assoc()) {
                        $old_items_map[(int)$r['id']] = $r;
                    }

                    $validated_ops = [];
                    foreach ($request_ops as $op) {
                        $op_type = trim((string)($op['op'] ?? ''));
                        if (!in_array($op_type, ['add', 'update', 'remove', 'keep'], true)) {
                            throw new Exception("Tipe operasi item tidak valid");
                        }
                        if ($op_type === 'add') {
                            $bid = (int)($op['barang_id'] ?? 0);
                            $qty_op = (float)($op['qty'] ?? 0);
                            $satuan_op = trim((string)($op['satuan'] ?? ''));
                            if ($bid <= 0 || $qty_op <= 0) {
                                throw new Exception("Data item tambah tidak valid");
                            }
                            if ($satuan_op === '') {
                                $rb = $conn->query("SELECT satuan FROM inventory_barang WHERE id = " . (int)$bid . " LIMIT 1");
                                $satuan_op = (string)($rb && $rb->num_rows > 0 ? ($rb->fetch_assoc()['satuan'] ?? '') : '');
                            }
                            $validated_ops[] = [
                                'op' => 'add',
                                'barang_id' => $bid,
                                'qty' => $qty_op,
                                'satuan' => $satuan_op,
                                'catatan_item' => (string)($op['catatan_item'] ?? '')
                            ];
                            continue;
                        }

                        $detail_id = (int)($op['detail_id'] ?? 0);
                        if ($detail_id <= 0 || !isset($old_items_map[$detail_id])) {
                            throw new Exception("Detail item tidak valid untuk operasi perubahan");
                        }
                        $before = $old_items_map[$detail_id];
                        if ($op_type === 'remove') {
                            $validated_ops[] = [
                                'op' => 'remove',
                                'detail_id' => $detail_id,
                                'before' => [
                                    'barang_id' => (int)$before['barang_id'],
                                    'qty' => (float)$before['qty'],
                                    'satuan' => (string)$before['satuan'],
                                    'catatan_item' => (string)($before['catatan_item'] ?? '')
                                ]
                            ];
                            continue;
                        }

                        $new_bid = (int)($op['barang_id'] ?? 0);
                        $new_qty = (float)($op['qty'] ?? 0);
                        $new_satuan = trim((string)($op['satuan'] ?? ''));
                        if ($new_bid <= 0 || $new_qty <= 0) {
                            throw new Exception("Data item ubah tidak valid");
                        }
                        if ($new_satuan === '') {
                            $rb = $conn->query("SELECT satuan FROM inventory_barang WHERE id = " . (int)$new_bid . " LIMIT 1");
                            $new_satuan = (string)($rb && $rb->num_rows > 0 ? ($rb->fetch_assoc()['satuan'] ?? '') : '');
                        }
                        $validated_ops[] = [
                            'op' => 'update',
                            'detail_id' => $detail_id,
                            'before' => [
                                'barang_id' => (int)$before['barang_id'],
                                'qty' => (float)$before['qty'],
                                'satuan' => (string)$before['satuan'],
                                'catatan_item' => (string)($before['catatan_item'] ?? '')
                            ],
                            'after' => [
                                'barang_id' => $new_bid,
                                'qty' => $new_qty,
                                'satuan' => $new_satuan,
                                'catatan_item' => (string)($op['catatan_item'] ?? '')
                            ]
                        ];
                    }

                    // Task: Mekanisme revisi No BHP (R1, R2, dst)
                    $original_id = $old_header['parent_id'] ?? $edit_id;
                    $original_nomor = $old_header['no_bhp_parent'] ?? $old_header['nomor_pemakaian'];
                    
                    // Get latest revision number for this parent
                    $stmt_rev = $conn->prepare("SELECT MAX(revision) as max_rev FROM inventory_pemakaian_bhp WHERE parent_id = ? OR id = ?");
                    $stmt_rev->bind_param("ii", $original_id, $original_id);
                    $stmt_rev->execute();
                    $max_rev = (int)($stmt_rev->get_result()->fetch_assoc()['max_rev'] ?? 0);
                    $next_rev = $max_rev + 1;
                    $nomor_revisi = $original_nomor . "-R" . $next_rev;

                    // Fetch current active items for the original record
                    // TASK: Logic must account for all PRIOR revisions that are still PENDING approval
                    $stmt_current_active_items = $conn->prepare("SELECT barang_id, qty, satuan, catatan_item FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
                    $stmt_current_active_items->bind_param("i", $original_id); 
                    $stmt_current_active_items->execute();
                    $current_active_items_res = $stmt_current_active_items->get_result();
                    $current_active_items_map = [];
                    while ($row = $current_active_items_res->fetch_assoc()) {
                        $current_active_items_map[(int)$row['barang_id']] = [
                            'qty' => (float)$row['qty'],
                            'satuan' => (string)$row['satuan'],
                            'catatan_item' => (string)$row['catatan_item']
                        ];
                    }

                    // APPLY PENDING DELTAS FROM PREVIOUS UNAPPROVED REVISIONS
                    $stmt_pending_deltas = $conn->prepare("
                        SELECT d.barang_id, d.qty 
                        FROM inventory_pemakaian_bhp_detail d
                        JOIN inventory_pemakaian_bhp h ON d.pemakaian_bhp_id = h.id
                        WHERE h.parent_id = ? AND h.status = 'pending_approval_spv' AND h.id < ?
                    ");
                    // We only want to look at R1, R2, R3 etc that were created BEFORE this new revision but NOT YET APPROVED
                    // Since this logic runs BEFORE inserting the new R record, we don't have its ID yet, but we can pass a dummy large ID or handle it.
                    // Actually, let's just get all pending revisions for this parent.
                    $stmt_pending_deltas->bind_param("ii", $original_id, $edit_id); // using $edit_id here is tricky if it's a new rev
                    // Let's refine the query:
                    $stmt_pending_deltas = $conn->prepare("
                        SELECT d.barang_id, d.qty 
                        FROM inventory_pemakaian_bhp_detail d
                        JOIN inventory_pemakaian_bhp h ON d.pemakaian_bhp_id = h.id
                        WHERE h.parent_id = ? AND h.status = 'pending_approval_spv'
                    ");
                    $stmt_pending_deltas->bind_param("i", $original_id);
                    $stmt_pending_deltas->execute();
                    $pending_deltas_res = $stmt_pending_deltas->get_result();
                    while ($p_row = $pending_deltas_res->fetch_assoc()) {
                        $bid_p = (int)$p_row['barang_id'];
                        if (isset($current_active_items_map[$bid_p])) {
                            $current_active_items_map[$bid_p]['qty'] += (float)$p_row['qty'];
                        } else {
                            // If it's a new item added in a pending revision
                            $current_active_items_map[$bid_p] = [
                                'qty' => (float)$p_row['qty'],
                                'satuan' => '', // will be filled from $items
                                'catatan_item' => ''
                            ];
                        }
                    }

                    $delta_items = [];
                    $processed_bids = [];

                    // Process proposed items (from $items)
                    foreach ($items as $it) {
                        $bid = (int)($it['barang_id'] ?? 0);
                        $new_qty = (float)($it['qty'] ?? 0);
                        $satuan = (string)($it['satuan'] ?? '');
                        $catatan_item = (string)($it['catatan_item'] ?? '');

                        if ($bid <= 0) continue; // Skip invalid items

                        $old_qty = $current_active_items_map[$bid]['qty'] ?? 0.0;
                        $delta_qty = $new_qty - $old_qty;

                        if (abs($delta_qty) > 0.000001) { // Only add if there's a significant change
                            $delta_items[] = [
                                'barang_id' => $bid,
                                'qty' => $delta_qty,
                                'satuan' => $satuan,
                                'catatan_item' => $catatan_item
                            ];
                        }
                        $processed_bids[] = $bid;
                    }

                    // Process items that were in original but are not in proposed (i.e., removed)
                    foreach ($current_active_items_map as $bid => $old_item) {
                        if (!in_array($bid, $processed_bids)) {
                            // Item was removed, so delta is negative of its original quantity
                            $delta_items[] = [
                                'barang_id' => $bid,
                                'qty' => -$old_item['qty'],
                                'satuan' => $old_item['satuan'],
                                'catatan_item' => $old_item['catatan_item'] . ' (removed)'
                            ];
                        }
                    }

                    // Capture new data into pending_data JSON (now storing DELTAS)
                    $tanggal_date = date('Y-m-d', strtotime($tanggal));
                    $pending_data = json_encode([
                        'version' => 4, // Increment version for delta logic
                        'meta' => [
                            'reason_label' => $reason,
                            'change_source' => $change_source,
                            'change_actor_user_id' => $change_actor_user_id,
                            'change_actor_name' => $change_actor_name
                        ],
                        'tanggal' => $tanggal_date,
                        'jenis_pemakaian' => $jenis_pemakaian,
                        'klinik_id' => $klinik_id,
                        'user_hc_id' => $user_hc_id,
                        'catatan_transaksi' => $catatan_transaksi,
                        'delta_items' => $delta_items, // Store deltas here
                        'items_ops' => $validated_ops, // Keep items_ops for audit/display if needed
                        'original_id' => $original_id,
                        'original_nomor' => $original_nomor,
                        'revision' => $next_rev
                    ]);
                    
                    // Insert NEW record as pending revision
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_pemakaian_bhp 
                        (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, approval_reason, pending_data, change_source, change_actor_user_id, change_actor_name, change_reason_code, parent_id, revision, no_bhp_parent, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval_spv')
                    ");
                    $reason_code = (string)($_POST['reason_code'] ?? '');
                    $stmt->bind_param("sssiisississsiis", 
                        $nomor_revisi, $tanggal_date, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $created_by, 
                        $reason, $pending_data, $change_source, $change_actor_user_id, $change_actor_name, $reason_code, 
                        $original_id, $next_rev, $original_nomor
                    );
                    $stmt->execute();
                    $new_rev_id = $conn->insert_id;

                    // Insert details for the revision record (now storing DELTAS as requested by user)
                    $stmt_det = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) VALUES (?, ?, ?, ?, ?)");
                    
                    foreach ($delta_items as $d_it) {
                        $bid = (int)($d_it['barang_id'] ?? 0);
                        $qty_delta = (float)($d_it['qty'] ?? 0);
                        $sat_it = (string)($d_it['satuan'] ?? '');
                        $cat_it = (string)($d_it['catatan_item'] ?? '');
                        
                        if ($bid <= 0 || abs($qty_delta) < 0.000001) continue;

                        $stmt_det->bind_param("iidss", $new_rev_id, $bid, $qty_delta, $sat_it, $cat_it);
                        $stmt_det->execute();
                    }
                    
                    // Note: We DO NOT deduct stock here. Stock deduction happens during APPROVAL.
                    
                    $conn->commit();
                    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
                    echo json_encode(['success' => true, 'message' => 'Revisi (' . $nomor_revisi . ') telah diajukan dan menunggu approval SPV']);
                    exit;
                } else {
                    throw new Exception("Perubahan lewat hari memerlukan approval SPV dan hanya dapat diajukan oleh Admin Klinik");
                }
            }
        }

        // 2. Reverse stock for old items
        $stmt = $conn->prepare("SELECT barang_id, qty FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $old_details = $stmt->get_result();

        while ($old_item = $old_details->fetch_assoc()) {
            $obid = (int)$old_item['barang_id'];
            $oqty = (float)$old_item['qty'];

            if ($old_header['jenis_pemakaian'] === 'hc' && !empty($old_header['user_hc_id'])) {
                // Return to HC Bag
                $old_uid = (int)$old_header['user_hc_id'];
                $stmt_upd = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diiii", $oqty, $created_by, $obid, $old_uid, $old_header['klinik_id']);
                $stmt_upd->execute();
            } elseif ($old_header['jenis_pemakaian'] === 'klinik') {
                // Return to Clinic Stock
                $stmt_upd = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("diii", $oqty, $created_by, $obid, $old_header['klinik_id']);
                $stmt_upd->execute();
            }
        }

        // 3. Delete old details
        $stmt = $conn->prepare("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();

        // 3b. Delete old transaction records
        $stmt = $conn->prepare("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();

        // Update header
        $tanggal_date = date('Y-m-d', strtotime($tanggal));
        $stmt = $conn->prepare("
            UPDATE inventory_pemakaian_bhp 
            SET tanggal = ?, jenis_pemakaian = ?, klinik_id = ?, user_hc_id = ?, catatan_transaksi = ?, status = 'active', pending_data = NULL
            WHERE id = ?
        ");
        $stmt->bind_param("ssiisi", $tanggal_date, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $edit_id);
        $stmt->execute();

        $pemakaian_id = $edit_id;
        $nomor_pemakaian = $old_header['nomor_pemakaian'];

        // Task: Handle items from request_items_json if provided (Unified Edit Logic)
        $ops_json = (string)($_POST['request_items_json'] ?? '');
        $request_ops = parse_request_items_from_json($ops_json);
        if (!empty($request_ops)) {
            $items = [];
            foreach ($request_ops as $op) {
                if ($op['op'] === 'add' || $op['op'] === 'update' || $op['op'] === 'keep') {
                    $items[] = [
                        'barang_id' => $op['barang_id'],
                        'qty' => $op['qty'],
                        'satuan' => $op['satuan'],
                        'uom_mode' => 'oper',
                        'catatan_item' => $op['catatan_item']
                    ];
                }
            }
        }

    } else {
        // --- CREATE MODE ---
        if ($is_request_approval) {
            $reason = $_POST['reason'] ?? '';
            $reason_code = $_POST['reason_code'] ?? '';
            $change_source = trim((string)($_POST['change_source'] ?? ''));
            $change_actor_user_id = isset($_POST['change_actor_user_id']) && (int)$_POST['change_actor_user_id'] > 0 ? (int)$_POST['change_actor_user_id'] : null;
            $change_actor_name = $_POST['change_actor_name'] ?? '';

            if (empty($reason)) throw new Exception("Alasan wajib diisi");
            
            // Capture data into pending_data for NEW record
            $tanggal_date = date('Y-m-d', strtotime($tanggal));
            $pending_data = json_encode([
                'version' => 2,
                'action' => 'create',
                'meta' => [
                    'reason_label' => $reason,
                    'change_source' => $change_source,
                    'change_actor_user_id' => $change_actor_user_id,
                    'change_actor_name' => $change_actor_name
                ],
                'tanggal' => $tanggal_date,
                'jenis_pemakaian' => $jenis_pemakaian,
                'klinik_id' => $klinik_id,
                'user_hc_id' => $user_hc_id,
                'catatan_transaksi' => $catatan_transaksi,
                'items' => $items
            ]);

            // Generate temporary nomor with prefix REQ-ADD
             $date = date('ymd', strtotime($tanggal)); // ymd (260413) instead of Ymd
             $seq = next_sequence($conn, 'BHP-REQ', $date);
            $nomor_pemakaian = 'REQ-ADD-' . $date . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("
                INSERT INTO inventory_pemakaian_bhp 
                (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, status, approval_reason, pending_data, change_source, change_actor_user_id, change_actor_name, change_reason_code) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_add', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssiisississs", $nomor_pemakaian, $tanggal_date, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $created_by, $reason, $pending_data, $change_source, $change_actor_user_id, $change_actor_name, $reason_code);
            $stmt->execute();

            $conn->commit();
            if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
            echo json_encode(['success' => true, 'message' => 'Permintaan penambahan data backdate telah dikirim ke SPV']);
            exit;
        }

        // --- NEW NUMBERING SYSTEM WITH CLINIC CODES ---
        $res_k = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
        $k_row = $res_k->fetch_assoc();
        $k_code_raw = ($jenis_pemakaian === 'hc') ? ($k_row['kode_homecare'] ?? 'HC') : ($k_row['kode_klinik'] ?? 'CLN');
        $k_code = explode('/', $k_code_raw)[0]; // Strip /Stock
        
        $date = date('ymd', strtotime($tanggal));
        $prefix_full = 'BHP-' . $k_code . '-' . $date . '-';
        $seq_prefix = 'BHP-' . $k_code;
        
        // Loop to prevent duplicate nomor_pemakaian
        require_once __DIR__ . '/../lib/counter.php';
        $max_retries = 10;
        $nomor_pemakaian = '';
        for ($i = 0; $i < $max_retries; $i++) {
            $seq = next_sequence($conn, $seq_prefix, $date);
            $temp_nomor = $prefix_full . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            
            // Check if this number already exists in database
            $stmt_check = $conn->prepare("SELECT id FROM inventory_pemakaian_bhp WHERE nomor_pemakaian = ? LIMIT 1");
            $stmt_check->bind_param("s", $temp_nomor);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows === 0) {
                $nomor_pemakaian = $temp_nomor;
                break;
            }
        }
        
        if (empty($nomor_pemakaian)) {
            throw new Exception('Gagal membuat nomor pemakaian unik setelah beberapa kali percobaan. Silakan coba lagi.');
        }

        // Insert header
        $tanggal_date = date('Y-m-d', strtotime($tanggal));
        $stmt = $conn->prepare("
            INSERT INTO inventory_pemakaian_bhp 
            (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssiisi", $nomor_pemakaian, $tanggal_date, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $created_by);
        $stmt->execute();
        $pemakaian_id = $conn->insert_id;
    }

    // Delete temporary auto-deduction data if any (for the same clinic and date)
    if (!$edit_id) {
        $date_only = date('Y-m-d', strtotime($tanggal));
        
        // Cari ID pemakaian temporary
        $stmt_find_temp = $conn->prepare("SELECT id FROM inventory_pemakaian_bhp WHERE klinik_id = ? AND jenis_pemakaian = ? AND is_auto = 1 AND tanggal LIKE ?");
        $tgl_like = $date_only . '%';
        $stmt_find_temp->bind_param("iss", $klinik_id, $jenis_pemakaian, $tgl_like);
        $stmt_find_temp->execute();
        $res_temp = $stmt_find_temp->get_result();
        
        while ($row_temp = $res_temp->fetch_assoc()) {
            $temp_id = (int)$row_temp['id'];
            
            // --- SKIP REVERSE STOCK ---
            // (We no longer deduct stock for Auto-BHP, so we don't need to return it)
            
            // Clear details and transaction history
            $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE pemakaian_bhp_id = $temp_id");
            $conn->query("DELETE FROM inventory_transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = $temp_id");

            $stmt_del_temp = $conn->prepare("DELETE FROM inventory_pemakaian_bhp WHERE id = ?");
            $stmt_del_temp->bind_param("i", $temp_id);
            $stmt_del_temp->execute();
        }
    }

    // Process items (Common for both Create and Edit)
    foreach ($items as $item) {
        $barang_id = $item['barang_id'] ?? null;
        $qty_in = (float)($item['qty'] ?? 0);
        $uom_mode = trim((string)($item['uom_mode'] ?? 'oper'));
        $qty = $qty_in;
        $satuan = '';
        $catatan_item = $item['catatan_item'] ?? null;

        if (empty($barang_id) || empty($qty_in) || $qty_in <= 0) {
            continue;
        }

        $barang_id = (int)$barang_id;

        /* 
        // Task: Hilangkan pembatasan stok agar tetap bisa sellout meskipun stok 0 (allow negative)
        if ($jenis_pemakaian === 'hc') {
            $uid = (int)$user_hc_id;
            $stmt_av = $conn->prepare("SELECT COALESCE(qty,0) AS qty FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ? LIMIT 1");
            $stmt_av->bind_param("iii", $barang_id, $uid, $klinik_id);
            $stmt_av->execute();
            $r_t = $stmt_av->get_result();
            $avail = (float)($r_t && $r_t->num_rows > 0 ? ($r_t->fetch_assoc()['qty'] ?? 0) : 0);
            if ((float)$qty > $avail + 0.00005) {
                throw new Exception('Stok tas HC tidak mencukupi untuk salah satu item.');
            }
        }
        */

        $stmt_u = $conn->prepare("
            SELECT
                COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS satuan,
                COALESCE(NULLIF(uc.from_uom, ''), '') AS uom_odoo,
                COALESCE(uc.multiplier, 1) AS uom_ratio
            FROM inventory_barang b
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmt_u->bind_param("i", $barang_id);
        $stmt_u->execute();
        $r_u = $stmt_u->get_result();
        $ratio = 1.0;
        if ($r_u && $r_u->num_rows > 0) {
            $u = $r_u->fetch_assoc();
            $satuan = (string)($u['satuan'] ?? '');
            $ratio = (float)($u['uom_ratio'] ?? 1);
        }
        if ($ratio <= 0) $ratio = 1;
        if ($uom_mode === 'odoo') {
            $qty = $qty_in / $ratio;
        } else {
            $qty = $qty_in;
        }

        // --- RACE CONDITION FIX: LOCK THE STOCK ROW ---
        if ($jenis_pemakaian === 'hc') {
            $uid = (int)$user_hc_id;
            $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($barang_id, $uid, $klinik_id, 0)");
            $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = $barang_id AND user_id = $uid AND klinik_id = $klinik_id FOR UPDATE");
        } else {
            $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($barang_id, $klinik_id, 0)");
            $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = $barang_id AND klinik_id = $klinik_id FOR UPDATE");
        }

        // Insert detail
        $stmt = $conn->prepare("
            INSERT INTO inventory_pemakaian_bhp_detail 
            (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iidss", $pemakaian_id, $barang_id, $qty, $satuan, $catatan_item);
        $stmt->execute();

        $level = ($jenis_pemakaian === 'hc') ? 'hc' : 'klinik';
        $level_id = ($level === 'hc') ? (int)$user_hc_id : (int)$klinik_id;
        $eff = stock_effective($conn, (int)$klinik_id, $level === 'hc', (int)$barang_id);
        $qty_before = $eff['ok'] ? (float)($eff['on_hand'] ?? 0) : 0.0;
        $qty_after = (float)$qty_before - (float)$qty;

        $ref_type = 'pemakaian_bhp';
        $ref_id = (int)$pemakaian_id;
        $catatan = "BHP " . $nomor_pemakaian . " - " . ($level === 'klinik' ? 'Klinik' : 'HC');
        if (!empty($catatan_item)) $catatan .= " - " . $catatan_item;

        $stmt_trans = $conn->prepare("
            INSERT INTO inventory_transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $created_at = date('Y-m-d H:i:s');
        $stmt_trans->bind_param(
            "isidddsisis",
            $barang_id,
            $level,
            $level_id,
            $qty,
            $qty_before,
            $qty_after,
            $ref_type,
            $ref_id,
            $catatan,
            $created_by,
            $created_at
        );
        $stmt_trans->execute();

        if ($jenis_pemakaian === 'hc') {
            $uid = (int)$user_hc_id;
            $q = (float)$qty;
            $stmt_up = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt_up->bind_param("diiii", $q, $created_by, $barang_id, $uid, $klinik_id);
            $stmt_up->execute();

            /*
            // Task: Hilangkan pengecekan stok negatif agar tetap bisa sellout meskipun stok 0
            // Check if stock is sufficient
            $stmt = $conn->prepare("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt->bind_param("iii", $barang_id, $uid, $klinik_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $stok = $result->fetch_assoc();
                if ($stok['qty'] < 0) {
                    throw new Exception("Stok HC tidak mencukupi untuk barang ID: $barang_id");
                }
            }
            */
        } elseif ($jenis_pemakaian === 'klinik') {
            // Potong stok klinik
            $stmt = $conn->prepare("
                UPDATE inventory_stok_gudang_klinik 
                SET qty = qty - ?, updated_by = ?, updated_at = NOW() 
                WHERE barang_id = ? AND klinik_id = ?
            ");
            $q = (float)$qty;
            $stmt->bind_param("diii", $q, $created_by, $barang_id, $klinik_id);
            $stmt->execute();

            /*
            // Task: Hilangkan pengecekan stok negatif agar tetap bisa sellout meskipun stok 0
            // Check if stock is sufficient
            $stmt = $conn->prepare("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ?");
            $stmt->bind_param("ii", $barang_id, $klinik_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $stok = $result->fetch_assoc();
                if ($stok['qty'] < 0) {
                    throw new Exception("Stok Gudang Klinik tidak mencukupi untuk barang ID: $barang_id");
                }
            }
            */
        }
    }

    $conn->commit();
    if ($lock_esc !== '') $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    $msg_success = $edit_id ? "Pemakaian BHP ($nomor_pemakaian) berhasil diperbarui" : "Pemakaian BHP berhasil disimpan dengan nomor: $nomor_pemakaian";
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => true, 'message' => $msg_success]);
        exit;
    }
    
    $_SESSION['success'] = $msg_success;
    redirect('index.php?page=pemakaian_bhp_list');

} catch (Exception $e) {
    $conn->rollback();
    if (!empty($lock_esc)) $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
    
    $msg_error = 'Gagal menyimpan pemakaian: ' . $e->getMessage();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => $msg_error]);
        exit;
    }
    
    $_SESSION['error'] = $msg_error;
    redirect('index.php?page=pemakaian_bhp_list');
}

if (isset($conn)) {
    $conn->close();
}

