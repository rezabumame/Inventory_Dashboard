<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized';
    redirect('index.php?page=stok_petugas_hc');
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['super_admin', 'admin_klinik'], true)) {
    $_SESSION['error'] = 'Access denied';
    redirect('index.php?page=stok_petugas_hc');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request';
    redirect('index.php?page=stok_petugas_hc');
}

require_csrf();

$created_by = (int)($_SESSION['user_id'] ?? 0);
$klinik_id = (int)($_POST['klinik_id'] ?? 0);
$petugas_user_id = (int)($_POST['petugas_user_id'] ?? 0);

if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    $_SESSION['error'] = 'Access denied';
    redirect('index.php?page=stok_petugas_hc');
}

if ($klinik_id <= 0) {
    $_SESSION['error'] = 'Klinik tidak valid';
    redirect('index.php?page=stok_petugas_hc');
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    $_SESSION['error'] = 'File belum dipilih';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$tmp = (string)($_FILES['file']['tmp_name'] ?? '');
if ($tmp === '' || !is_file($tmp)) {
    $_SESSION['error'] = 'Upload gagal';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$xlsx = SimpleXLSX::parse($tmp);
if (!$xlsx) {
    $_SESSION['error'] = 'File Excel tidak bisa dibaca';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$kl = $conn->query("SELECT id, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
$kode_homecare = trim((string)($kl['kode_homecare'] ?? ''));
if ($kode_homecare === '') {
    $_SESSION['error'] = 'Klinik belum memiliki kode_homecare';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$petugas_rows = [];
if ($petugas_user_id > 0) {
    $u = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE id = $petugas_user_id AND role='petugas_hc' AND status='active' AND klinik_id=$klinik_id LIMIT 1")->fetch_assoc();
    if (!$u) {
        $_SESSION['error'] = 'Petugas tidak valid';
        redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
    }
    $petugas_rows[] = $u;
} else {
    $r = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role='petugas_hc' AND status='active' AND klinik_id=$klinik_id");
    while ($r && ($row = $r->fetch_assoc())) $petugas_rows[] = $row;
}

if (empty($petugas_rows)) {
    $_SESSION['error'] = 'Belum ada petugas HC aktif di klinik ini';
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id);
}

$petugas_by_name = [];
foreach ($petugas_rows as $p) {
    $nm = trim((string)($p['nama_lengkap'] ?? ''));
    if ($nm === '') continue;
    $key = mb_strtolower($nm);
    if (!isset($petugas_by_name[$key])) $petugas_by_name[$key] = [];
    $petugas_by_name[$key][] = (int)$p['id'];
}

$barang_by_code = [];
$mirror_by_barang = [];
$uom_mult = [];
$uom_oper_map = [];
$uom_odoo_map = [];
$loc = $conn->real_escape_string($kode_homecare);
$r = $conn->query("
    SELECT
        b.id AS barang_id,
        TRIM(COALESCE(b.kode_barang, '')) AS kode_barang,
        TRIM(COALESCE(b.odoo_product_id, '')) AS odoo_product_id,
        TRIM(COALESCE(b.barcode, '')) AS barcode,
        COALESCE(uc.multiplier, 1) AS multiplier,
        COALESCE(MAX(sm.qty), 0) AS mirror_qty_raw
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    LEFT JOIN inventory_stock_mirror sm ON TRIM(sm.location_code) = '$loc'
        AND (
            (TRIM(COALESCE(b.odoo_product_id, '')) <> '' AND TRIM(sm.odoo_product_id) = TRIM(b.odoo_product_id))
            OR
            (TRIM(COALESCE(b.kode_barang, '')) <> '' AND TRIM(sm.kode_barang) = TRIM(b.kode_barang))
        )
    GROUP BY b.id, b.kode_barang, b.odoo_product_id, b.barcode, uc.multiplier, uc.to_uom, uc.from_uom
");
while ($r && ($row = $r->fetch_assoc())) {
    $bid = (int)($row['barang_id'] ?? 0);
    if ($bid <= 0) continue;
    $mult = (float)($row['multiplier'] ?? 1);
    if ($mult <= 0) $mult = 1;
    if ($mult <= 0) $mult = 1;
    $mirror = (float)($row['mirror_qty_raw'] ?? 0) / $mult;
    $mirror_by_barang[$bid] = $mirror;
    $uom_mult[$bid] = $mult;
    $uom_oper_map[$bid] = trim((string)($row['uom_oper'] ?? ''));
    $uom_odoo_map[$bid] = trim((string)($row['uom_odoo'] ?? ''));

    $kb = trim((string)($row['kode_barang'] ?? ''));
    $op = trim((string)($row['odoo_product_id'] ?? ''));
    $bc = trim((string)($row['barcode'] ?? ''));
    foreach ([$kb, $op, $bc] as $code) {
        if ($code === '') continue;
        $k = mb_strtolower($code);
        if (!isset($barang_by_code[$k])) $barang_by_code[$k] = $bid;
    }
}

$required_headers = ['kode barang', 'nama barang', 'stok gudang', 'stok tas', 'qty alokasi'];
$sheet_names = $xlsx->sheetNames();
$allocations = [];
$sum_by_barang = [];
$errors = [];

foreach ($sheet_names as $si => $sname) {
    $sheet_name = trim((string)$sname);
    if ($sheet_name === '') continue;
    $key = mb_strtolower($sheet_name);
    if (!isset($petugas_by_name[$key])) {
        $errors[] = "Sheet '$sheet_name': nama petugas tidak ditemukan di klinik ini";
        continue;
    }
    if (count($petugas_by_name[$key]) > 1) {
        $errors[] = "Sheet '$sheet_name': nama petugas duplikat, tidak bisa dipetakan";
        continue;
    }
    $uid = (int)$petugas_by_name[$key][0];
    $rows = $xlsx->rows($si);
    if (!is_array($rows) || count($rows) < 2) {
        $errors[] = "Sheet '$sheet_name': tidak ada data";
        continue;
    }

    $headers = array_map(function($h){ return mb_strtolower(trim((string)$h)); }, (array)$rows[0]);
    $hmap = [];
    foreach ($headers as $i => $h) $hmap[$h] = $i;
    foreach ($required_headers as $rh) {
        if (!array_key_exists($rh, $hmap)) {
            $errors[] = "Sheet '$sheet_name': header '$rh' tidak ditemukan";
        }
    }
    if (!empty($errors)) continue;

    $uom_col = -1;
    foreach (['satuan (uom)', 'konversi uom', 'uom'] as $k) {
        if (array_key_exists($k, $hmap)) { $uom_col = (int)$hmap[$k]; break; }
    }

    for ($ri = 1; $ri < count($rows); $ri++) {
        $row = (array)$rows[$ri];
        $code = trim((string)($row[$hmap['kode barang']] ?? ''));
        if ($code === '') continue;
        $code_key = mb_strtolower($code);
        $bid = (int)($barang_by_code[$code_key] ?? 0);
        if ($bid <= 0) {
            $errors[] = "Sheet '$sheet_name' Row " . ($ri + 1) . ": Kode Barang '$code' tidak ditemukan";
            continue;
        }
        $qty_new_raw = trim((string)($row[$hmap['qty alokasi']] ?? ''));
        if ($qty_new_raw === '') {
            $errors[] = "Sheet '$sheet_name' Row " . ($ri + 1) . ": Qty Alokasi wajib diisi (boleh 0)";
            continue;
        }
        if (!is_numeric(str_replace([','], ['.'], $qty_new_raw))) {
            $errors[] = "Sheet '$sheet_name' Row " . ($ri + 1) . ": Qty Alokasi tidak valid";
            continue;
        }
        $qty_new = (float)str_replace([','], ['.'], $qty_new_raw);
        if ($qty_new < 0) {
            $errors[] = "Sheet '$sheet_name' Row " . ($ri + 1) . ": Qty Alokasi tidak boleh negatif";
            continue;
        }

        // Auto-detect mode based on UOM text if provided; else default:
        // - if has conversion (to_uom present) => oper
        // - else => odoo
        $mode = 'oper';
        $uom_oper = (string)($uom_oper_map[$bid] ?? '');
        $uom_odoo = (string)($uom_odoo_map[$bid] ?? '');
        if ($uom_oper === '' && $uom_odoo !== '') $mode = 'odoo';
        if ($uom_col >= 0) {
            $uom_text = trim((string)($row[$uom_col] ?? ''));
            if ($uom_text !== '') {
                $lt = mb_strtolower($uom_text);
                // Check if it starts with the UOM name or contains it as a distinct word
                $check_uom = function($target, $input) {
                    $target = mb_strtolower($target);
                    if ($target === '') return false;
                    // Check if target is present as a distinct part (delimited by comma, space, brackets, or parentheses)
                    $pattern = '/(^|[,\s\(\)\[\]])' . preg_quote($target, '/') . '($|[,\s\(\)\[\]])/i';
                    return (bool)preg_match($pattern, $input);
                };

                if ($uom_odoo !== '' && $check_uom($uom_odoo, $lt)) $mode = 'odoo';
                if ($uom_oper !== '' && $check_uom($uom_oper, $lt)) $mode = 'oper';
            }
        }
        $ratio = (float)($uom_mult[$bid] ?? 1);
        if ($ratio <= 0) $ratio = 1;
        $qty_oper = ($mode === 'odoo') ? ($qty_new / $ratio) : $qty_new;
        $qty_oper = (float)round($qty_oper, 4);
        if ($qty_oper < 0) $qty_oper = 0;

        if (!isset($allocations[$uid])) $allocations[$uid] = [];
        $allocations[$uid][$bid] = $qty_oper;
        if (!isset($sum_by_barang[$bid])) $sum_by_barang[$bid] = 0.0;
        $sum_by_barang[$bid] += $qty_oper;
    }
}

if (!empty($errors)) {
    $_SESSION['error'] = 'Upload dibatalkan karena ada data tidak valid.';
    $_SESSION['warnings'] = $errors;
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id);
}

$diffs = [];
foreach ($sum_by_barang as $bid => $sum_new) {
    $mirror = (float)($mirror_by_barang[(int)$bid] ?? 0);
    if ($sum_new > $mirror + 0.0000001) {
        $diffs[(int)$bid] = $sum_new - $mirror;
    }
}

if (!empty($diffs) && !isset($_POST['confirm_over'])) {
    $_SESSION['hc_bulk_pending'] = [
        'klinik_id' => $klinik_id,
        'petugas_user_id' => $petugas_user_id,
        'created_by' => $created_by,
        'created_at' => time(),
        'allocations' => $allocations,
        'diffs' => $diffs
    ];
    redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id . '&bulk_confirm=1');
}

function apply_bulk_alloc(mysqli $conn, int $klinik_id, int $created_by, array $allocations): int {
    $count = 0;
    $stmt_up = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(updated_by), updated_at = NOW()");
    $stmt_del = $conn->prepare("DELETE FROM inventory_stok_tas_hc WHERE barang_id = ? AND user_id = ? AND klinik_id = ? LIMIT 1");
    foreach ($allocations as $uid => $items) {
        $uid = (int)$uid;
        if ($uid <= 0 || !is_array($items)) continue;
        foreach ($items as $bid => $qty_new) {
            $bid = (int)$bid;
            $qty_new = (float)$qty_new;
            if ($bid <= 0) continue;
            if ($qty_new <= 0.0000001) {
                $stmt_del->bind_param("iii", $bid, $uid, $klinik_id);
                $stmt_del->execute();
                $count++;
            } else {
                $stmt_up->bind_param("iiidi", $bid, $uid, $klinik_id, $qty_new, $created_by);
                $stmt_up->execute();
                $count++;
            }
        }
    }
    return $count;
}

$conn->begin_transaction();
try {
    $affected = apply_bulk_alloc($conn, $klinik_id, $created_by, $allocations);
    $conn->commit();
    unset($_SESSION['hc_bulk_pending']);
    $_SESSION['success'] = 'Upload alokasi berhasil disimpan (' . (int)$affected . ' baris).';
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Gagal menyimpan alokasi: ' . $e->getMessage();
}

redirect('index.php?page=stok_petugas_hc&klinik_id=' . (int)$klinik_id . '&petugas_user_id=' . (int)$petugas_user_id);


