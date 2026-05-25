<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id'])) die('Unauthorized');
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['admin_klinik', 'super_admin', 'spv_klinik'], true)) die('Access denied');

$selected_klinik  = (int)($_GET['klinik_id'] ?? 0);
$petugas_user_id  = (int)($_GET['petugas_user_id'] ?? 0);
$history_from     = (string)($_GET['history_from'] ?? '');
$history_to       = (string)($_GET['history_to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $history_from)) $history_from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $history_to))   $history_to   = '';

// Admin klinik dibatasi ke kliniknya sendiri
if (in_array($role, ['admin_klinik', 'spv_klinik'], true)) {
    $selected_klinik = (int)($_SESSION['klinik_id'] ?? 0);
}
if ($selected_klinik <= 0) die('Pilih klinik terlebih dahulu');

// Ambil info klinik
$kl_row = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id=$selected_klinik LIMIT 1")->fetch_assoc();
$nama_klinik = $kl_row['nama_klinik'] ?? 'Klinik';

// Build WHERE — filter by tanggal request (COALESCE fallback ke approved date)
$where = ["t.klinik_id = $selected_klinik"];
if ($petugas_user_id > 0) $where[] = "t.user_hc_id = $petugas_user_id";
if ($history_from !== '') $where[] = "COALESCE(r.created_at, t.created_at) >= '" . $conn->real_escape_string($history_from . ' 00:00:00') . "'";
if ($history_to   !== '') $where[] = "COALESCE(r.created_at, t.created_at) <= '" . $conn->real_escape_string($history_to . ' 23:59:59') . "'";
$where_sql = implode(' AND ', $where);

// Query — join barang, request untuk tgl request & approved
$sql = "
    SELECT
        t.created_at                                    AS tgl_approved,
        COALESCE(r.created_at, t.created_at)            AS tgl_request_dt,
        DATE(COALESCE(r.created_at, t.created_at))      AS tgl,
        u.nama_lengkap                                  AS petugas,
        b.nama_barang,
        COALESCE(NULLIF(uc.to_uom,''), b.satuan, '-')  AS uom,
        t.qty
    FROM inventory_hc_petugas_transfer t
    JOIN inventory_users  u  ON u.id = t.user_hc_id
    JOIN inventory_barang b  ON b.id = t.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    LEFT JOIN inventory_hc_transfer_request r ON r.id = t.request_id
    WHERE $where_sql AND t.qty <> 0
    ORDER BY tgl_request_dt ASC, u.nama_lengkap ASC, b.nama_barang ASC
";
$res = $conn->query($sql);
$rows = [];
while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;

// ── Sheet 0: Summary Per Item (tanpa breakdown tanggal) ──────────────────────
$summary = [];
foreach ($rows as $r) {
    $barang = $r['nama_barang'];
    $uom    = $r['uom'];
    if (!isset($summary[$barang])) {
        $summary[$barang] = ['barang' => $barang, 'uom' => $uom, 'qty' => 0];
    }
    $summary[$barang]['qty'] += (float)$r['qty'];
}
usort($summary, fn($a, $b) => strcmp($a['barang'], $b['barang']));

$sheet0 = [['Nama Barang', 'UOM', 'Total Qty']];
foreach ($summary as $s) {
    $sheet0[] = [$s['barang'], $s['uom'], $s['qty']];
}
if (count($sheet0) === 1) $sheet0[] = ['-', '-', 0];

// ── Sheet 1: Rekap Per Hari ───────────────────────────────────────────────────
// Group by date → item only (all nakes merged), sum qty
$daily = [];
foreach ($rows as $r) {
    $tgl    = $r['tgl'];
    $barang = $r['nama_barang'];
    $uom    = $r['uom'];
    $key    = $tgl . '||' . $barang;
    if (!isset($daily[$key])) {
        $daily[$key] = ['tgl' => $tgl, 'barang' => $barang, 'uom' => $uom, 'qty' => 0];
    }
    $daily[$key]['qty'] += (float)$r['qty'];
}

$sheet1 = [['Tgl Request', 'Nama Barang', 'UOM', 'Qty']];
foreach ($daily as $d) {
    $sheet1[] = [
        date('d M Y', strtotime($d['tgl'])),
        $d['barang'],
        $d['uom'],
        $d['qty'],
    ];
}
if (count($sheet1) === 1) $sheet1[] = ['-', '-', '-', 0];

// ── Sheet 2: Per Nakes (sorted petugas → date → item) ────────────────────────
$nakes = [];
foreach ($rows as $r) {
    $petugas = $r['petugas'];
    $tgl     = $r['tgl'];
    $barang  = $r['nama_barang'];
    $uom     = $r['uom'];
    $key     = $petugas . '||' . $tgl . '||' . $barang;
    if (!isset($nakes[$key])) {
        $nakes[$key] = ['petugas' => $petugas, 'tgl' => $tgl, 'barang' => $barang, 'uom' => $uom, 'qty' => 0];
    }
    $nakes[$key]['qty'] += (float)$r['qty'];
}
// Sort by petugas → date
usort($nakes, fn($a, $b) => [$a['petugas'], $a['tgl']] <=> [$b['petugas'], $b['tgl']]);

$sheet2 = [['Petugas', 'Tgl Request', 'Nama Barang', 'UOM', 'Qty']];
foreach ($nakes as $n) {
    $sheet2[] = [
        $n['petugas'],
        date('d M Y', strtotime($n['tgl'])),
        $n['barang'],
        $n['uom'],
        $n['qty'],
    ];
}
if (count($sheet2) === 1) $sheet2[] = ['-', '-', '-', '-', 0];

// ── Generate filename & download ─────────────────────────────────────────────
$range = '';
if ($history_from !== '') $range .= '_' . str_replace('-', '', $history_from);
if ($history_to   !== '') $range .= '_sd_' . str_replace('-', '', $history_to);
$filename = 'HistoryTransfer_' . preg_replace('/[^A-Za-z0-9_]/', '_', $nama_klinik) . $range . '_' . date('YmdHis') . '.xlsx';

SimpleXLSXGen::fromArray($sheet0, 'Summary')
    ->addSheet($sheet1, 'Rekap Per Hari')
    ->addSheet($sheet2, 'Per Nakes')
    ->downloadAs($filename);
