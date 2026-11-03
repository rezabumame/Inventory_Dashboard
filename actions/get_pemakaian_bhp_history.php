<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

function fmt_qty_simple($v) {
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
}

// Histori dibangun dari data yang SUDAH ADA (tidak ada tabel history terpisah):
// - inventory_transaksi_stok: setiap pergerakan stok (item baru, koreksi qty, reversal delete)
//   sudah tercatat lengkap dengan qty, catatan, waktu, dan siapa yang melakukannya. Baris-baris
//   dari 1x proses auto-sync (1x run) dikelompokkan jadi 1 entri histori, bukan 1 baris per item --
//   biar tidak banjir puluhan baris identik tiap jam.
// - Field single-value di header (created_at/by, spv_approved_at/by, approval_reason) dipakai
//   sebagai snapshot status saat ini (bukan multi-siklus penuh kalau record sempat reject->edit
//   berkali-kali -- itu keterbatasan yang disadari, bukan dianggap lengkap 100%).
$stmt_h = $conn->prepare("
    SELECT pb.*, uc.nama_lengkap AS created_by_name, us.nama_lengkap AS spv_name
    FROM inventory_pemakaian_bhp pb
    LEFT JOIN inventory_users uc ON uc.id = pb.created_by
    LEFT JOIN inventory_users us ON us.id = pb.spv_approved_by
    WHERE pb.id = ?
");
$stmt_h->bind_param("i", $id);
$stmt_h->execute();
$header = $stmt_h->get_result()->fetch_assoc();
if (!$header) {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    exit;
}

$is_auto_source = ($header['change_source'] === 'sistem_integrasi');

$events = [];

// Snapshot: pembuatan record
$creator = $is_auto_source
    ? 'Sistem (Auto Sync)'
    : ($header['change_actor_name'] ?: ($header['created_by_name'] ?: 'Sistem'));
$sumber_label = $is_auto_source ? ' via API integrasi' : '';
$events[] = [
    'created_at' => $header['created_at'],
    'user_name' => $creator,
    'action' => 'create',
    'notes' => "Record dibuat{$sumber_label}.",
];

// Snapshot: approval/reject SPV (kalau ada) -- ini selalu aksi manusia, tidak pernah via API.
if (!empty($header['spv_approved_at'])) {
    $notes = ($header['status'] === 'rejected')
        ? 'Ditolak oleh SPV.' . (!empty($header['approval_reason']) ? ' Alasan: ' . $header['approval_reason'] : '')
        : 'Disetujui oleh SPV.';
    $events[] = [
        'created_at' => $header['spv_approved_at'],
        'user_name' => $header['spv_name'] ?: '-',
        'action' => $header['status'] === 'rejected' ? 'reject' : 'approve',
        'notes' => $notes,
    ];
} elseif ($header['status'] === 'rejected' && !empty($header['approval_reason'])) {
    $events[] = [
        'created_at' => $header['created_at'],
        'user_name' => '-',
        'action' => 'reject',
        'notes' => 'Ditolak. Alasan: ' . $header['approval_reason'],
    ];
}

// Pergerakan stok (item baru, koreksi qty, reversal) -- dari inventory_transaksi_stok,
// dikelompokkan per RUN (menit yang sama + catatan yang sama) jadi 1 entri histori.
$stmt_t = $conn->prepare("
    SELECT ts.*, b.nama_barang, u.nama_lengkap AS actor_name
    FROM inventory_transaksi_stok ts
    LEFT JOIN inventory_barang b ON b.id = ts.barang_id
    LEFT JOIN inventory_users u ON u.id = ts.created_by
    WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.referensi_id = ?
    ORDER BY ts.created_at ASC, ts.id ASC
");
$stmt_t->bind_param("i", $id);
$stmt_t->execute();
$res_t = $stmt_t->get_result();

$runs = []; // key = menit + catatan -> list of item lines
while ($row = $res_t->fetch_assoc()) {
    $catatan = (string)($row['catatan'] ?? '');
    $is_auto_run = (stripos($catatan, 'Sync otomatis') !== false);

    $run_minute = date('Y-m-d H:i', strtotime($row['created_at']));
    $run_key = $run_minute . '|' . $catatan;

    if (!isset($runs[$run_key])) {
        $runs[$run_key] = [
            'created_at' => $row['created_at'],
            'user_name' => $is_auto_run ? 'Sistem (Auto Sync)' : ($row['actor_name'] ?: 'Sistem'),
            'is_auto_run' => $is_auto_run,
            'catatan' => $catatan,
            'item_totals' => [], // nama_barang -> net qty (akumulasi, bukan daftar per baris sumber)
            'baris_count' => 0,
        ];
    }

    $nama = $row['nama_barang'] ?: 'Item #' . $row['barang_id'];
    // Dari sudut pandang "pemakaian BHP" (bukan sudut pandang stok gudang):
    // tipe_transaksi 'out' = stok dipakai = pemakaian BERTAMBAH -> positif.
    // tipe_transaksi 'in'  = stok dikembalikan = pemakaian BERKURANG/dikoreksi turun -> negatif.
    $signed_qty = ($row['tipe_transaksi'] === 'out' ? 1 : -1) * (float)$row['qty'];
    if (!isset($runs[$run_key]['item_totals'][$nama])) {
        $runs[$run_key]['item_totals'][$nama] = 0;
    }
    $runs[$run_key]['item_totals'][$nama] += $signed_qty;
    $runs[$run_key]['baris_count']++;
}

// $runs sudah dalam urutan kronologis (sesuai query ORDER BY created_at ASC), jadi akumulasi
// per item berjalan maju di sini -- dipakai utk tampilkan "qty sebelum -> qty sesudah".
$cumulative = [];
foreach ($runs as $run) {
    $label = $run['is_auto_run'] ? 'Sync otomatis' : 'Perubahan stok';
    $items_out = [];
    foreach ($run['item_totals'] as $nama => $net) {
        if (abs($net) < 0.000001) continue; // net 0 (nambah lalu dikoreksi balik di run yg sama) -- tidak perlu ditampilkan
        $sign = $net > 0 ? '+' : '-';
        $before = $cumulative[$nama] ?? 0;
        $after = $before + $net;
        $cumulative[$nama] = $after;
        $items_out[] = [
            'nama' => $nama,
            'label' => $sign . fmt_qty_simple(abs($net)),
            'before' => fmt_qty_simple($before),
            'after' => fmt_qty_simple($after),
        ];
    }
    usort($items_out, fn($a, $b) => strcmp($a['nama'], $b['nama']));
    $events[] = [
        'created_at' => $run['created_at'],
        'user_name' => $run['user_name'],
        'action' => 'stok',
        'notes' => "$label (" . count($items_out) . " item):",
        'items' => $items_out,
    ];
}

usort($events, function ($a, $b) {
    return strtotime($a['created_at']) <=> strtotime($b['created_at']);
});
$events = array_reverse($events); // terbaru di atas, konsisten dengan History Booking

echo json_encode(['success' => true, 'data' => $events]);
