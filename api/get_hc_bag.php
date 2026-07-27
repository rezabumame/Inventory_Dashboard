<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$klinik_id = (int)($_GET['klinik_id'] ?? 0);

if ($klinik_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'klinik_id required']);
    exit;
}

$res = $conn->query("
    SELECT b.id AS barang_id, b.nama_barang,
           COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
           sth.qty
    FROM inventory_stok_tas_hc sth
    JOIN inventory_barang b ON b.id = sth.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE sth.user_id = $user_id AND sth.klinik_id = $klinik_id AND sth.qty != 0
    ORDER BY b.nama_barang ASC
");

$items    = [];
$seen_ids = [];
while ($res && ($row = $res->fetch_assoc())) {
    $items[] = [
        'nama_barang' => $row['nama_barang'],
        'uom'         => $row['uom'],
        'qty'         => (float)$row['qty'],
    ];
    $seen_ids[(int)$row['barang_id']] = true;
}

// Item yang sudah dilaporkan (self-report) tapi belum divalidasi admin (belum masuk
// inventory_stok_tas_hc) — tetap tampilkan pakai laporan sendiri sbg acuan sementara.
// Resolusi opname_id HARUS sama persis dengan api/hc_stock_validate.php (prioritaskan sesi
// yang masih unlocked, fallback ke ID terakhir kalau semua terkunci) supaya referensi ini
// selalu menunjuk ke sesi yang sama dengan tempat laporan nakes benar-benar disimpan.
$r_opname = $conn->query("SELECT id FROM inventory_stok_opname WHERE klinik_id = $klinik_id
    ORDER BY (is_locked = 0 OR is_locked IS NULL) DESC, periode DESC, id DESC LIMIT 1");
$opname_row = $r_opname ? $r_opname->fetch_assoc() : null;
$opname_id = $opname_row ? (int)$opname_row['id'] : 0;

if ($opname_id > 0) {
    $res_pending = $conn->query("
        SELECT b.id AS barang_id, b.nama_barang,
               COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
               COALESCE(uc.multiplier, 1) AS multiplier,
               d.qty_fisik AS qty_fisik_raw
        FROM inventory_stok_opname_detail d
        JOIN inventory_barang b ON b.id = d.barang_id
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE d.opname_id = $opname_id AND d.tipe = 'hc' AND d.hc_user_id = $user_id
          AND d.qty_fisik IS NOT NULL
        ORDER BY b.nama_barang ASC
    ");
    while ($res_pending && ($rp = $res_pending->fetch_assoc())) {
        $bid = (int)$rp['barang_id'];
        if (isset($seen_ids[$bid])) continue; // sudah ada dari stok tas tervalidasi
        $mult = (float)($rp['multiplier'] ?? 1);
        if ($mult <= 0) $mult = 1;
        // qty_fisik disimpan dalam from_uom (lihat hc_stock_validate.php) → konversi ke to_uom
        $items[] = [
            'nama_barang' => $rp['nama_barang'],
            'uom'         => $rp['uom'],
            'qty'         => (float)$rp['qty_fisik_raw'] / $mult,
        ];
        $seen_ids[$bid] = true;
    }
}

echo json_encode(['success' => true, 'items' => $items]);
