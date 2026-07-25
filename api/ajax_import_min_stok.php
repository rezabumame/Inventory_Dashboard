<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File tidak terupload dengan benar.']);
    exit;
}

$xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
if (!$xlsx) {
    echo json_encode(['success' => false, 'message' => SimpleXLSX::parseError()]);
    exit;
}

$rows = $xlsx->rows();
if (count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'File Excel kosong atau tidak sesuai format.']);
    exit;
}

// Cek apakah kolom BIS tersedia di DB
$bis_check = $conn->query("SHOW COLUMNS FROM inventory_barang LIKE 'barcode_internal'");
$has_bis   = $bis_check && $bis_check->num_rows > 0;

// Kolom template:
// 0: ID | 1: Kode Barang | 2: Nama Barang | 3: Stok Minimum | 4: Tipe
// 5: BIS Barcode | 6: Track ED | 7: Label Print | 8: Label Placement
// Catatan: cell kosong di kolom 7/8 = "tidak diubah" (pertahankan nilai lama).
// Isi "-" di salah satu kolom 7/8 = reset paksa Konfigurasi Label item itu ke "Belum diset".

$valid_tipe      = ['Core', 'Support', ''];
$valid_lp        = ['none', 'physical', ''];
$valid_placement = ['unit', 'item', 'box', 'outer', 'catalogue', ''];

// Preload ID barang yang valid di DB ini — supaya baris dengan ID Barang yang tidak
// sinkron (mis. template diunduh dari environment lain) di-skip saja, bukan menggagalkan
// seluruh import lewat foreign key constraint error.
$valid_ids = [];
$qvid = $conn->query("SELECT id FROM inventory_barang");
while ($rvid = $qvid->fetch_assoc()) $valid_ids[(int)$rvid['id']] = true;

$conn->begin_transaction();
try {
    $updated = 0;
    $skipped = 0;
    $reset_count = 0;
    $errors  = [];

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $id  = (int)($row[0] ?? 0);
        if ($id <= 0) { $skipped++; continue; }

        // Ambil nilai dari Excel
        $stok_min_input = $row[3] ?? '';
        $tipe_input     = trim((string)($row[4] ?? ''));
        $track_ed_input = isset($row[6]) && $row[6] !== '' ? (int)$row[6] : null;
        $lp_raw         = trim((string)($row[7] ?? ''));
        $place_raw      = trim((string)($row[8] ?? ''));

        // "-" di kolom Label Print/Placement = perintah reset paksa Konfigurasi Label
        $reset_label = ($lp_raw === '-' || $place_raw === '-');
        $lp_input    = $reset_label ? '' : $lp_raw;
        $place_input = $reset_label ? '' : $place_raw;

        // Validasi nilai — "item" di template = "unit" di DB
        if ($place_input === 'item') $place_input = 'unit';
        if (!in_array($tipe_input, $valid_tipe, true))      $tipe_input = '';
        if (!in_array($lp_input, $valid_lp, true))          $lp_input = '';
        if (!in_array($place_input, $valid_placement, true)) $place_input = '';
        if ($track_ed_input !== null) $track_ed_input = $track_ed_input ? 1 : 0;

        // Ambil data saat ini dari DB
        $stmt = $conn->prepare($has_bis
            ? "SELECT stok_minimum, tipe, track_ed, label_print, label_placement, label_config_set FROM inventory_barang WHERE id = ?"
            : "SELECT stok_minimum, tipe FROM inventory_barang WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $curr = $stmt->get_result()->fetch_assoc();
        if (!$curr) { $skipped++; continue; }

        // Tentukan nilai final (hanya update jika ada perubahan)
        $final_min   = ($stok_min_input !== '' && $stok_min_input !== null)
                       ? max(0, (int)$stok_min_input) : (int)$curr['stok_minimum'];
        $final_tipe  = $tipe_input !== '' ? $tipe_input : (string)($curr['tipe'] ?? '');

        $changed = ($final_min !== (int)$curr['stok_minimum'])
                || ($final_tipe !== (string)($curr['tipe'] ?? ''));

        if ($has_bis) {
            $final_ted = $track_ed_input !== null ? $track_ed_input : (int)($curr['track_ed'] ?? 0);

            if ($reset_label) {
                $final_lp    = 'none';
                $final_place = '';
                $final_cfg   = 0;
            } else {
                $final_lp    = $lp_input !== '' ? $lp_input : (string)($curr['label_print'] ?? 'none');
                $final_place = $place_input !== '' ? $place_input : (string)($curr['label_placement'] ?? '');
                // label_config_set = 1 jika label sudah dikonfigurasi di Excel atau sudah ada
                $final_cfg   = ($lp_input !== '' || (int)($curr['label_config_set'] ?? 0)) ? 1 : (int)($curr['label_config_set'] ?? 0);
                // Jika label_print none dan placement dikosongkan, ikuti yang di Excel
                if ($final_lp === 'none') $final_place = '';
            }

            $changed = $changed
                || ($final_ted   !== (int)($curr['track_ed'] ?? 0))
                || ($final_lp    !== (string)($curr['label_print'] ?? 'none'))
                || ($final_place !== (string)($curr['label_placement'] ?? ''))
                || ($final_cfg   !== (int)($curr['label_config_set'] ?? 0));
        }

        if (!$changed) { $skipped++; continue; }
        if ($reset_label) $reset_count++;

        if ($has_bis) {
            $tipe_val  = $final_tipe !== '' ? $final_tipe : null;
            $place_val = $final_place !== '' ? $final_place : null;
            $stmt_upd = $conn->prepare($reset_label
                ? "UPDATE inventory_barang SET
                    stok_minimum = ?, tipe = ?, track_ed = ?,
                    label_print = ?, label_placement = ?, label_config_set = ?,
                    wizard_answers = NULL, recommended_category = NULL, final_category = NULL,
                    override_status = 0, override_reason = NULL, override_notes = NULL,
                    override_by = NULL, override_at = NULL
                    WHERE id = ?"
                : "UPDATE inventory_barang SET
                    stok_minimum = ?, tipe = ?, track_ed = ?,
                    label_print = ?, label_placement = ?, label_config_set = ?
                    WHERE id = ?");
            $stmt_upd->bind_param("isissii",
                $final_min, $tipe_val, $final_ted,
                $final_lp, $place_val, $final_cfg, $id);
        } else {
            $tipe_val = $final_tipe !== '' ? $final_tipe : null;
            $stmt_upd = $conn->prepare("UPDATE inventory_barang SET stok_minimum = ?, tipe = ? WHERE id = ?");
            $stmt_upd->bind_param("isi", $final_min, $tipe_val, $id);
        }
        $stmt_upd->execute();
        $updated++;
    }

    // ── Sheet 2: Barcode Vendor ────────────────────────────────────────────────
    $vendor_added   = 0;
    $vendor_deleted = 0;
    $vendor_skipped = 0;
    $sheet2_rows = $xlsx->rows(1); // sheet index 1

    if ($sheet2_rows && count($sheet2_rows) > 1) {
        // Ambil semua barcode vendor yang sudah ada untuk cek duplikat
        $existing_barcodes = [];
        $qe = $conn->query("SELECT barcode FROM inventory_barcode_vendor");
        while ($re = $qe->fetch_assoc()) $existing_barcodes[$re['barcode']] = true;

        $stmt_vend = $conn->prepare(
            "INSERT INTO inventory_barcode_vendor (barang_id, barcode, keterangan) VALUES (?, ?, ?)"
        );
        $stmt_vend_del = $conn->prepare(
            "DELETE FROM inventory_barcode_vendor WHERE barang_id = ? AND barcode = ?"
        );

        for ($j = 1; $j < count($sheet2_rows); $j++) {
            $vrow     = $sheet2_rows[$j];
            $bid      = (int)($vrow[0] ?? 0);
            $barcode  = trim((string)($vrow[3] ?? ''));
            $ket      = trim((string)($vrow[4] ?? ''));
            $hapus    = strtoupper(trim((string)($vrow[5] ?? ''))) === 'X';

            if ($bid <= 0 || $barcode === '' || !isset($valid_ids[$bid])) { $vendor_skipped++; continue; }

            if ($hapus) {
                $stmt_vend_del->bind_param("is", $bid, $barcode);
                $stmt_vend_del->execute();
                if ($stmt_vend_del->affected_rows > 0) {
                    unset($existing_barcodes[$barcode]);
                    $vendor_deleted++;
                } else {
                    $vendor_skipped++;
                }
                continue;
            }

            if (isset($existing_barcodes[$barcode])) { $vendor_skipped++; continue; }

            $ket_val = $ket !== '' ? $ket : null;
            $stmt_vend->bind_param("iss", $bid, $barcode, $ket_val);
            $stmt_vend->execute();
            $existing_barcodes[$barcode] = true; // cegah duplikat dalam file yang sama
            $vendor_added++;
        }
    }

    // ── Sheet 3: Batch ED ────────────────────────────────────────────────────────
    $ed_added   = 0;
    $ed_deleted = 0;
    $ed_skipped = 0;
    $sheet3_rows = $xlsx->rows(2); // sheet index 2

    if ($sheet3_rows && count($sheet3_rows) > 1) {
        $stmt_ed_ins = $conn->prepare(
            "INSERT INTO inventory_barang_ed (barang_id, klinik_id, ed_month, keterangan, created_by) VALUES (?, NULL, ?, ?, ?)"
        );
        $stmt_ed_del_id = $conn->prepare("DELETE FROM inventory_barang_ed WHERE id = ?");
        $stmt_ed_del_match = $conn->prepare(
            "DELETE FROM inventory_barang_ed WHERE barang_id = ? AND ed_month = ? AND klinik_id IS NULL"
        );
        $stmt_ed_exists = $conn->prepare(
            "SELECT id FROM inventory_barang_ed WHERE barang_id = ? AND ed_month = ? AND klinik_id IS NULL LIMIT 1"
        );

        for ($k = 1; $k < count($sheet3_rows); $k++) {
            $erow    = $sheet3_rows[$k];
            $ed_id   = (int)($erow[0] ?? 0);
            $bid_ed  = (int)($erow[1] ?? 0);
            // Excel kadang menyimpan tanggal sebagai datetime penuh ("2027-01-15 00:00:00") — ambil tanggalnya saja
            $ed_date_raw = trim((string)($erow[4] ?? ''));
            $ed_date = substr($ed_date_raw, 0, 10);
            $ed_ket  = trim((string)($erow[5] ?? ''));
            $hapus   = strtoupper(trim((string)($erow[6] ?? ''))) === 'X';

            if ($hapus) {
                if ($ed_id > 0) {
                    $stmt_ed_del_id->bind_param("i", $ed_id);
                    $stmt_ed_del_id->execute();
                    if ($stmt_ed_del_id->affected_rows > 0) $ed_deleted++; else $ed_skipped++;
                } elseif ($bid_ed > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed_date)) {
                    $stmt_ed_del_match->bind_param("is", $bid_ed, $ed_date);
                    $stmt_ed_del_match->execute();
                    if ($stmt_ed_del_match->affected_rows > 0) $ed_deleted++; else $ed_skipped++;
                } else {
                    $ed_skipped++;
                }
                continue;
            }

            if ($bid_ed <= 0 || !isset($valid_ids[$bid_ed]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed_date)) { $ed_skipped++; continue; }

            $stmt_ed_exists->bind_param("is", $bid_ed, $ed_date);
            $stmt_ed_exists->execute();
            if ($stmt_ed_exists->get_result()->num_rows > 0) { $ed_skipped++; continue; }

            $ket_val = $ed_ket !== '' ? $ed_ket : null;
            $import_user_id = (int)$_SESSION['user_id'];
            $stmt_ed_ins->bind_param("issi", $bid_ed, $ed_date, $ket_val, $import_user_id);
            $stmt_ed_ins->execute();
            $ed_added++;
        }
    }

    $conn->commit();

    $msg = "Import selesai.<br>"
         . "• <strong>$updated item konfigurasi diperbarui</strong>, $skipped diabaikan"
         . ($reset_count > 0 ? " (termasuk <strong>$reset_count Konfigurasi Label direset</strong>)" : "") . ".<br>"
         . "• <strong>$vendor_added barcode vendor ditambahkan</strong>, <strong>$vendor_deleted dihapus</strong>, $vendor_skipped diabaikan.<br>"
         . "• <strong>$ed_added batch ED ditambahkan</strong>, <strong>$ed_deleted dihapus</strong>, $ed_skipped diabaikan.";
    if (count($errors)) $msg .= '<br>Error: ' . implode(', ', $errors);
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
