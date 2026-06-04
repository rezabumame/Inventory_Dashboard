<?php
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

check_role(['admin_klinik', 'super_admin', 'spv_klinik']);
require_csrf();

$action     = trim((string)($_POST['action'] ?? ''));
$request_id = (int)($_POST['request_id'] ?? 0);
$reviewer   = (int)$_SESSION['user_id'];
$role       = (string)$_SESSION['role'];
$s_klinik   = (int)($_SESSION['klinik_id'] ?? 0);

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'request_id tidak valid.']); exit;
}

// Ambil request
$req = $conn->query("SELECT r.*, u.nama_lengkap AS nakes_name, u.klinik_id AS nakes_klinik_id
    FROM inventory_hc_transfer_request r
    JOIN inventory_users u ON u.id = r.user_hc_id
    WHERE r.id = $request_id LIMIT 1")->fetch_assoc();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Request tidak ditemukan.']); exit;
}

// Admin klinik hanya bisa akses kliniknya sendiri
if ($role === 'admin_klinik' && (int)$req['klinik_id'] !== $s_klinik) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}
if ($role === 'spv_klinik' && (int)$req['klinik_id'] !== $s_klinik) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}

$klinik_id   = (int)$req['klinik_id'];
$user_hc_id  = (int)$req['user_hc_id'];

// ── UPLOAD FOTO ──────────────────────────────────────────────────────────────
if ($action === 'upload_foto') {
    if (empty($_FILES['foto']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'File foto tidak ada.']); exit;
    }
    $upload_dir = __DIR__ . '/../uploads/hc_request/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ftype   = mime_content_type($_FILES['foto']['tmp_name']);
    if (!in_array($ftype, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Format foto tidak didukung.']); exit;
    }
    if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Foto maksimal 5MB.']); exit;
    }
    $ext      = pathinfo((string)($_FILES['foto']['name'] ?? ''), PATHINFO_EXTENSION);
    $filename = 'hcreq_' . $request_id . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto.']); exit;
    }
    // Hapus foto lama
    if (!empty($req['foto_path']) && file_exists(__DIR__ . '/../' . $req['foto_path'])) {
        @unlink(__DIR__ . '/../' . $req['foto_path']);
    }
    $new_path = $conn->real_escape_string('uploads/hc_request/' . $filename);
    $conn->query("UPDATE inventory_hc_transfer_request SET foto_path='$new_path' WHERE id=$request_id");
    echo json_encode(['success' => true, 'foto_url' => base_url('uploads/hc_request/' . $filename)]);
    exit;
}

// ── REJECT ───────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    if ($req['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Request sudah diproses.']); exit;
    }
    $conn->query("UPDATE inventory_hc_transfer_request SET status='rejected', reviewed_by=$reviewer, reviewed_at=NOW() WHERE id=$request_id");
    echo json_encode(['success' => true, 'message' => 'Request ditolak.']);
    exit;
}

// ── SAVE ITEMS (edit item sebelum approve) ───────────────────────────────────
if ($action === 'save_items') {
    if ($req['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Request sudah diproses.']); exit;
    }
    $item_ids    = $_POST['item_id']    ?? [];
    $barang_ids  = $_POST['barang_id']  ?? [];
    $qty_approveds = $_POST['qty_approved'] ?? [];
    if (!is_array($item_ids)) $item_ids = [$item_ids];
    if (!is_array($barang_ids)) $barang_ids = [$barang_ids];
    if (!is_array($qty_approveds)) $qty_approveds = [$qty_approveds];

    $conn->begin_transaction();
    try {
        // Hapus semua item lama lalu insert ulang
        $conn->query("DELETE FROM inventory_hc_transfer_request_items WHERE request_id=$request_id");
        $stmt = $conn->prepare("INSERT INTO inventory_hc_transfer_request_items (request_id, barang_id, qty_request, qty_approved) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < count($barang_ids); $i++) {
            $bid  = (int)($barang_ids[$i] ?? 0);
            $qa   = (float)($qty_approveds[$i] ?? 0);
            $qr   = $qa; // qty_request = qty_approved untuk item baru dari admin
            if ($bid <= 0 || $qa <= 0) continue;
            // Kalau item lama, pakai qty_request aslinya (jika ada di item_ids)
            $iid = (int)($item_ids[$i] ?? 0);
            if ($iid > 0) {
                $old = $conn->query("SELECT qty_request FROM inventory_hc_transfer_request_items WHERE id=$iid AND request_id=$request_id LIMIT 1")->fetch_assoc();
                if ($old) $qr = (float)$old['qty_request'];
            }
            $stmt->bind_param("iidd", $request_id, $bid, $qr, $qa);
            $stmt->execute();
        }
        // Kalau semua item di-delete → auto reject
        $remaining = (int)($conn->query("SELECT COUNT(*) AS c FROM inventory_hc_transfer_request_items WHERE request_id=$request_id")->fetch_assoc()['c'] ?? 0);
        if ($remaining === 0) {
            $conn->query("UPDATE inventory_hc_transfer_request SET status='rejected', reviewed_by=$reviewer, reviewed_at=NOW() WHERE id=$request_id");
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Semua item dihapus, request otomatis ditolak.', 'auto_rejected' => true]);
            exit;
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Item berhasil disimpan.']);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

// ── APPROVE ──────────────────────────────────────────────────────────────────
if ($action === 'approve') {
    if ($req['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Request sudah diproses.']); exit;
    }

    // Baca items dari POST (live DOM state yang dikirim frontend)
    $post_barang_ids  = $_POST['barang_id']    ?? [];
    $post_qty_approveds = $_POST['qty_approved'] ?? [];
    if (!is_array($post_barang_ids))    $post_barang_ids    = [$post_barang_ids];
    if (!is_array($post_qty_approveds)) $post_qty_approveds = [$post_qty_approveds];

    $items = [];
    for ($i = 0; $i < count($post_barang_ids); $i++) {
        $bid = (int)($post_barang_ids[$i] ?? 0);
        $qty = (float)($post_qty_approveds[$i] ?? 0);
        if ($bid > 0 && $qty > 0) {
            $items[] = ['barang_id' => $bid, 'qty' => $qty];
        }
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item untuk di-approve.']); exit;
    }

    // Simpan foto jika ada yang diupload bersamaan
    if (!empty($_FILES['foto']['tmp_name'])) {
        $upload_dir = __DIR__ . '/../uploads/hc_request/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ftype   = mime_content_type($_FILES['foto']['tmp_name']);
        if (in_array($ftype, $allowed, true) && $_FILES['foto']['size'] <= 5 * 1024 * 1024) {
            $ext      = pathinfo((string)($_FILES['foto']['name'] ?? 'foto.jpg'), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'hcreq_' . $request_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $filename)) {
                if (!empty($req['foto_path']) && file_exists(__DIR__ . '/../' . $req['foto_path'])) {
                    @unlink(__DIR__ . '/../' . $req['foto_path']);
                }
                $new_path = $conn->real_escape_string('uploads/hc_request/' . $filename);
                $conn->query("UPDATE inventory_hc_transfer_request SET foto_path='$new_path' WHERE id=$request_id");
            }
        }
    }

    // Simpan item terbaru ke DB (replace lama)
    $conn->query("DELETE FROM inventory_hc_transfer_request_items WHERE request_id=$request_id");
    $stmt_item = $conn->prepare("INSERT INTO inventory_hc_transfer_request_items (request_id, barang_id, qty_request, qty_approved) VALUES (?, ?, ?, ?)");
    foreach ($items as $it) {
        $bid = $it['barang_id'];
        $qty = $it['qty'];
        $stmt_item->bind_param("iidd", $request_id, $bid, $qty, $qty);
        $stmt_item->execute();
    }

    // Ambil info klinik
    $kl = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id=$klinik_id LIMIT 1")->fetch_assoc();
    if (!$kl) {
        echo json_encode(['success' => false, 'message' => 'Klinik tidak ditemukan.']); exit;
    }

    $loc_onsite = stock_resolve_location($conn, (string)($kl['kode_klinik'] ?? ''));
    if ($loc_onsite === '') {
        echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_klinik.']); exit;
    }
    $loc_hc = stock_resolve_location($conn, (string)($kl['kode_homecare'] ?? ''));
    if ($loc_hc === '') {
        echo json_encode(['success' => false, 'message' => 'Klinik belum memiliki kode_homecare.']); exit;
    }
    $loc_onsite_esc = $conn->real_escape_string($loc_onsite);
    $loc_hc_esc     = $conn->real_escape_string($loc_hc);

    $lock_name = 'stock_hc_transfer_' . $klinik_id;
    $lock_esc  = $conn->real_escape_string($lock_name);
    $rl  = $conn->query("SELECT GET_LOCK('$lock_esc', 10) AS got");
    $got = (int)(($rl && $rl->num_rows > 0) ? ($rl->fetch_assoc()['got'] ?? 0) : 0);
    if ($got !== 1) {
        echo json_encode(['success' => false, 'message' => 'Sistem sedang memproses stok klinik ini. Coba lagi.']); exit;
    }

    $conn->begin_transaction();
    try {
        $catatan_base = 'Approve Request #' . $request_id . ' oleh ' . (string)($_SESSION['nama_lengkap'] ?? '');

        foreach ($items as $item) {
            $barang_id = $item['barang_id'];
            $qty_oper  = $item['qty'];

            $b = $conn->query("SELECT id, nama_barang FROM inventory_barang WHERE id=$barang_id LIMIT 1")->fetch_assoc();
            if (!$b) throw new Exception('Barang tidak ditemukan: #' . $barang_id);

            // Lock rows
            $conn->query("INSERT IGNORE INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty) VALUES ($barang_id, $klinik_id, 0)");
            $conn->query("SELECT qty FROM inventory_stok_gudang_klinik WHERE barang_id=$barang_id AND klinik_id=$klinik_id FOR UPDATE");
            $conn->query("INSERT IGNORE INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES ($barang_id, $user_hc_id, $klinik_id, 0)");
            $conn->query("SELECT qty FROM inventory_stok_tas_hc WHERE barang_id=$barang_id AND user_id=$user_hc_id AND klinik_id=$klinik_id FOR UPDATE");

            $ef_on = stock_effective($conn, $klinik_id, false, $barang_id);
            if (!$ef_on['ok']) throw new Exception((string)$ef_on['message']);
            $onsite_before = (float)($ef_on['on_hand'] ?? 0);

            $ef_hc = stock_effective($conn, $klinik_id, true, $barang_id);
            if (!$ef_hc['ok']) throw new Exception((string)$ef_hc['message']);
            $hc_before = (float)($ef_hc['on_hand'] ?? 0);

            // Transfer record
            $stmt_t = $conn->prepare("INSERT INTO inventory_hc_petugas_transfer (request_id, klinik_id, user_hc_id, barang_id, qty, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_t->bind_param("iiiidsi", $request_id, $klinik_id, $user_hc_id, $barang_id, $qty_oper, $catatan_base, $reviewer);
            $stmt_t->execute();
            $transfer_id = (int)$conn->insert_id;

            // Update stok tas hc
            $stmt_s = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty), updated_by=VALUES(updated_by)");
            $stmt_s->bind_param("iiidi", $barang_id, $user_hc_id, $klinik_id, $qty_oper, $reviewer);
            $stmt_s->execute();

            // Kurangi stok klinik
            $stmt_u = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty=qty-?, updated_by=?, updated_at=NOW() WHERE barang_id=? AND klinik_id=?");
            $stmt_u->bind_param("diii", $qty_oper, $reviewer, $barang_id, $klinik_id);
            $stmt_u->execute();

            $cat = $catatan_base . ' - Transfer #' . $transfer_id;

            $qty_after_onsite = $onsite_before - $qty_oper;
            $stmt_log = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, 'klinik', ?, 'out', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())");
            $stmt_log->bind_param("iidddisi", $barang_id, $klinik_id, $qty_oper, $onsite_before, $qty_after_onsite, $transfer_id, $cat, $reviewer);
            $stmt_log->execute();

            $qty_after_hc = $hc_before + $qty_oper;
            $stmt_log2 = $conn->prepare("INSERT INTO inventory_transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at) VALUES (?, 'hc', ?, 'in', ?, ?, ?, 'hc_petugas_transfer', ?, ?, ?, NOW())");
            $stmt_log2->bind_param("iidddisi", $barang_id, $user_hc_id, $qty_oper, $hc_before, $qty_after_hc, $transfer_id, $cat, $reviewer);
            $stmt_log2->execute();
        }

        // Update request status
        $conn->query("UPDATE inventory_hc_transfer_request SET status='approved', reviewed_by=$reviewer, reviewed_at=NOW() WHERE id=$request_id");

        $conn->commit();
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        echo json_encode(['success' => true, 'message' => 'Request disetujui dan stok berhasil dipindahkan.']);
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('$lock_esc')");
        echo json_encode(['success' => false, 'message' => 'Gagal approve: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
