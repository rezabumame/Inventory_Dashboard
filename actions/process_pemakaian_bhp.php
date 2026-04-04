<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/counter.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
}

// Check access
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'petugas_hc'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini. Role Anda: ' . $_SESSION['role'];
    error_log('Pemakaian BHP access denied for role: ' . $_SESSION['role']);
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=pemakaian_bhp_list');
}

require_csrf();
error_log('Pemakaian BHP - User: ' . $_SESSION['user_id'] . ', Role: ' . $_SESSION['role']);

$conn->begin_transaction();

try {
    // Get form data
    $edit_id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $tanggal = $_POST['tanggal'];
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

    if ($jenis_pemakaian === 'hc') {
        if ($_SESSION['role'] === 'petugas_hc') {
            $user_hc_id = (int)$created_by;
            $klinik_id = (int)($_SESSION['klinik_id'] ?? $klinik_id);
        }
        if (empty($user_hc_id)) throw new Exception('Petugas HC wajib dipilih');
        $r_u = $conn->query("SELECT id, klinik_id FROM users WHERE id = " . (int)$user_hc_id . " AND role = 'petugas_hc' AND status = 'active' LIMIT 1");
        if (!$r_u || $r_u->num_rows === 0) throw new Exception('Petugas HC tidak valid');
        $urow = $r_u->fetch_assoc();
        if ((int)($urow['klinik_id'] ?? 0) !== (int)$klinik_id) throw new Exception('Petugas HC tidak terdaftar di klinik ini');
    } else {
        $user_hc_id = null;
    }

    if ($edit_id) {
        // --- EDIT MODE ---
        // 1. Fetch old record for permission check and stock reversal
        $stmt = $conn->prepare("SELECT * FROM pemakaian_bhp WHERE id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $old_header = $stmt->get_result()->fetch_assoc();

        if (!$old_header) {
            throw new Exception("Data lama tidak ditemukan");
        }

        // Permission check
        $is_today_edit = date('Y-m-d', strtotime($old_header['created_at'])) === date('Y-m-d');
        if ($old_header['created_by'] != $created_by || !$is_today_edit) {
            throw new Exception("Anda tidak memiliki akses untuk mengubah data ini");
        }

        /* 
        // 2. Reverse stock for old items (DISABLED: Sellout is now handled at view level)
        $stmt = $conn->prepare("SELECT * FROM pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $old_details = $stmt->get_result();

        while ($old_item = $old_details->fetch_assoc()) {
            $obid = $old_item['barang_id'];
            $oqty = $old_item['qty'];

            if ($old_header['jenis_pemakaian'] === 'klinik') {
                $stmt_upd = $conn->prepare("UPDATE stok_gudang_klinik SET qty = qty + ? WHERE barang_id = ? AND klinik_id = ?");
                $stmt_upd->bind_param("iii", $oqty, $obid, $old_header['klinik_id']);
                $stmt_upd->execute();
            } else {
                $stmt_upd = $conn->prepare("UPDATE stok_tas_hc SET qty = qty + ? WHERE barang_id = ? AND user_id = ?");
                $stmt_upd->bind_param("iii", $oqty, $obid, $old_header['user_hc_id']);
                $stmt_upd->execute();
            }
        }
        */

        // 3. Delete old details
        if ($old_header['jenis_pemakaian'] === 'hc' && !empty($old_header['user_hc_id'])) {
            $old_uid = (int)$old_header['user_hc_id'];
            $stmt = $conn->prepare("SELECT barang_id, qty FROM pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
            $stmt->bind_param("i", $edit_id);
            $stmt->execute();
            $old_details = $stmt->get_result();
            while ($od = $old_details->fetch_assoc()) {
                $obid = (int)($od['barang_id'] ?? 0);
                $oqty = (float)($od['qty'] ?? 0);
                if ($obid > 0 && $oqty > 0) {
                    $stmt_up = $conn->prepare("UPDATE stok_tas_hc SET qty = qty + ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                    $stmt_up->bind_param("diiii", $oqty, $created_by, $obid, $old_uid, $old_header['klinik_id']);
                    $stmt_up->execute();
                }
            }
        }

        $stmt = $conn->prepare("DELETE FROM pemakaian_bhp_detail WHERE pemakaian_bhp_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();

        // 3b. Delete old transaction records
        $stmt = $conn->prepare("DELETE FROM transaksi_stok WHERE referensi_tipe = 'pemakaian_bhp' AND referensi_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();

        // 4. Update header
        $stmt = $conn->prepare("
            UPDATE pemakaian_bhp 
            SET tanggal = ?, jenis_pemakaian = ?, klinik_id = ?, user_hc_id = ?, catatan_transaksi = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssiisi", $tanggal, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $edit_id);
        $stmt->execute();

        $pemakaian_id = $edit_id;
        $nomor_pemakaian = $old_header['nomor_pemakaian'];

    } else {
        // --- CREATE MODE ---
        // Generate nomor pemakaian
        $date = date('Ymd', strtotime($tanggal));
        $seq = next_sequence($conn, 'PBH', $date);
        $prefix = 'PBH-' . $date . '-';
        $nomor_pemakaian = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        // Insert header
        $stmt = $conn->prepare("
            INSERT INTO pemakaian_bhp 
            (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssiisi", $nomor_pemakaian, $tanggal, $jenis_pemakaian, $klinik_id, $user_hc_id, $catatan_transaksi, $created_by);
        $stmt->execute();
        $pemakaian_id = $conn->insert_id;
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

        if ($jenis_pemakaian === 'hc') {
            $uid = (int)$user_hc_id;
            $r_t = $conn->query("SELECT COALESCE(qty,0) AS qty FROM stok_tas_hc WHERE barang_id = $barang_id AND user_id = $uid AND klinik_id = " . (int)$klinik_id . " LIMIT 1");
            $avail = (float)($r_t && $r_t->num_rows > 0 ? ($r_t->fetch_assoc()['qty'] ?? 0) : 0);
            if ((float)$qty > $avail + 0.00005) {
                throw new Exception('Stok tas HC tidak mencukupi untuk salah satu item.');
            }
        }
        $r_u = $conn->query("
            SELECT
                COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS satuan,
                COALESCE(NULLIF(uc.from_uom, ''), '') AS uom_odoo,
                COALESCE(uc.multiplier, 1) AS uom_ratio
            FROM barang b
            LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
            WHERE b.id = $barang_id
            LIMIT 1
        ");
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

        // Insert detail
        $stmt = $conn->prepare("
            INSERT INTO pemakaian_bhp_detail 
            (pemakaian_bhp_id, barang_id, qty, satuan, catatan_item) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iidss", $pemakaian_id, $barang_id, $qty, $satuan, $catatan_item);
        $stmt->execute();

        $level = ($jenis_pemakaian === 'hc') ? 'hc' : 'klinik';
        $level_id = (int)$klinik_id;
        $qty_before = 0;
        if ($level === 'klinik') {
            $stmt_q = $conn->prepare("SELECT qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ? LIMIT 1");
            $stmt_q->bind_param("ii", $barang_id, $klinik_id);
            $stmt_q->execute();
            $res_q = $stmt_q->get_result();
            if ($res_q && $res_q->num_rows > 0) $qty_before = (float)($res_q->fetch_assoc()['qty'] ?? 0);
        } else {
            $kode_homecare = '';
            $kode_barang = '';
            $stmt_k = $conn->prepare("SELECT kode_homecare FROM klinik WHERE id = ? LIMIT 1");
            $stmt_k->bind_param("i", $klinik_id);
            $stmt_k->execute();
            $res_k = $stmt_k->get_result();
            if ($res_k && $res_k->num_rows > 0) $kode_homecare = (string)($res_k->fetch_assoc()['kode_homecare'] ?? '');

            $stmt_b = $conn->prepare("SELECT kode_barang FROM barang WHERE id = ? LIMIT 1");
            $stmt_b->bind_param("i", $barang_id);
            $stmt_b->execute();
            $res_b = $stmt_b->get_result();
            if ($res_b && $res_b->num_rows > 0) $kode_barang = (string)($res_b->fetch_assoc()['kode_barang'] ?? '');

            if ($kode_homecare !== '' && $kode_barang !== '') {
                $stmt_sm = $conn->prepare("SELECT qty FROM stock_mirror WHERE location_code = ? AND kode_barang = ? ORDER BY updated_at DESC LIMIT 1");
                $stmt_sm->bind_param("ss", $kode_homecare, $kode_barang);
                $stmt_sm->execute();
                $res_sm = $stmt_sm->get_result();
                if ($res_sm && $res_sm->num_rows > 0) $qty_before = (float)floor((float)($res_sm->fetch_assoc()['qty'] ?? 0));
            }
        }
        $qty_after = (float)$qty_before - (float)$qty;
        if ($qty_after < 0) $qty_after = 0;

        $ref_type = 'pemakaian_bhp';
        $ref_id = (int)$pemakaian_id;
        $catatan = "PBH " . $nomor_pemakaian . " - " . ($level === 'klinik' ? 'Klinik' : 'HC');
        if (!empty($catatan_item)) $catatan .= " - " . $catatan_item;

        $stmt_trans = $conn->prepare("
            INSERT INTO transaksi_stok
            (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
            VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $created_at = date('Y-m-d H:i:s', strtotime($tanggal));
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
            $stmt_up = $conn->prepare("UPDATE stok_tas_hc SET qty = qty - ?, updated_by = ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt_up->bind_param("diiii", $q, $created_by, $barang_id, $uid, $klinik_id);
            $stmt_up->execute();

            // Check if stock is sufficient
            $stmt = $conn->prepare("SELECT qty FROM stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
            $stmt->bind_param("iii", $barang_id, $uid, $klinik_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $stok = $result->fetch_assoc();
                if ($stok['qty'] < 0) {
                    throw new Exception("Stok HC tidak mencukupi untuk barang ID: $barang_id");
                }
            }
        } elseif ($jenis_pemakaian === 'klinik') {
            // Potong stok klinik
            $stmt = $conn->prepare("
                UPDATE stok_gudang_klinik 
                SET qty = qty - ?, updated_by = ?, updated_at = NOW() 
                WHERE barang_id = ? AND klinik_id = ?
            ");
            $q = (float)$qty;
            $stmt->bind_param("diii", $q, $created_by, $barang_id, $klinik_id);
            $stmt->execute();

            // Check if stock is sufficient
            $stmt = $conn->prepare("SELECT qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ?");
            $stmt->bind_param("ii", $barang_id, $klinik_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $stok = $result->fetch_assoc();
                if ($stok['qty'] < 0) {
                    throw new Exception("Stok Gudang Klinik tidak mencukupi untuk barang ID: $barang_id");
                }
            }
        }
    }

    $conn->commit();
    $_SESSION['success'] = $edit_id ? "Pemakaian BHP ($nomor_pemakaian) berhasil diperbarui" : "Pemakaian BHP berhasil disimpan dengan nomor: $nomor_pemakaian";
    redirect('index.php?page=pemakaian_bhp_list');

} catch (Exception $e) {
    $conn->rollback();
    
    // Log error for debugging
    error_log('Pemakaian BHP Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    $_SESSION['error'] = 'Gagal menyimpan pemakaian: ' . $e->getMessage();
    redirect('index.php?page=pemakaian_bhp_list');
}

if (isset($conn)) {
    $conn->close();
}
