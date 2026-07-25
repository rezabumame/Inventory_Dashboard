<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!csrf_validate($_GET['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

$periode = trim($_GET['periode'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['success' => false, 'message' => 'Format periode tidak valid']); exit;
}
$safe_per = $conn->real_escape_string($periode);

// Ambil semua item tipe='klinik' utk 1 opname_id: qty & ED digabung per barang_id, tanggal ED
// (deduped per kategori) & seluruh catatan riwayat digabung (bukan cuma yang terakhir).
function fetch_so_klinik_items(mysqli $conn, int $opname_id, array $items_map, array $odoo_map): array {
    $r_col_exp = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'ed_expired'");
    $exp_sel = ($r_col_exp && $r_col_exp->num_rows > 0) ? "COALESCE(d.ed_expired,0) AS ed_expired," : "0 AS ed_expired,";

    $qDet = $conn->query("SELECT d.id AS detail_id, d.barang_id, d.qty_fisik, COALESCE(d.catatan,'') AS catatan,
        $exp_sel COALESCE(d.ed_lte3m,0) AS ed_lte3m, COALESCE(d.ed_gt3m,0) AS ed_gt3m
        FROM inventory_stok_opname_detail d
        WHERE d.opname_id = $opname_id AND (d.tipe = 'klinik' OR d.tipe IS NULL)
        ORDER BY d.barang_id ASC, d.id ASC");

    $by_bid = [];
    $detail_bid_map = [];
    while ($qDet && ($r = $qDet->fetch_assoc())) {
        $bid = (int)$r['barang_id'];
        if (!isset($by_bid[$bid])) {
            $by_bid[$bid] = ['qty_total' => 0, 'ed_expired' => 0, 'ed_lte3m' => 0, 'ed_gt3m' => 0,
                'catatan' => [], 'exp_dates' => [], 'lte_dates' => [], 'gt_dates' => []];
        }
        $by_bid[$bid]['qty_total']  += (float)($r['qty_fisik'] ?? 0);
        $by_bid[$bid]['ed_expired'] += (float)($r['ed_expired'] ?? 0);
        $by_bid[$bid]['ed_lte3m']   += (float)($r['ed_lte3m'] ?? 0);
        $by_bid[$bid]['ed_gt3m']    += (float)($r['ed_gt3m']  ?? 0);
        if (trim((string)$r['catatan']) !== '') $by_bid[$bid]['catatan'][] = $r['catatan'];
        $detail_bid_map[(int)$r['detail_id']] = $bid;
    }

    if (!empty($detail_bid_map)) {
        $r_ed_tbl = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
        if ($r_ed_tbl && $r_ed_tbl->num_rows > 0) {
            $ids_str = implode(',', array_keys($detail_bid_map));
            $qEd = $conn->query("SELECT opname_detail_id, DATE_FORMAT(ed_month,'%Y-%m-%d') AS ed_month, kategori
                FROM inventory_stok_opname_ed_detail
                WHERE opname_detail_id IN ($ids_str) AND qty > 0 ORDER BY ed_month ASC");
            while ($qEd && ($er = $qEd->fetch_assoc())) {
                $did = (int)$er['opname_detail_id'];
                if (!isset($detail_bid_map[$did])) continue;
                $bid = $detail_bid_map[$did];
                $key = $er['kategori'] === 'expired' ? 'exp_dates' : ($er['kategori'] === 'lte3m' ? 'lte_dates' : 'gt_dates');
                if (!in_array($er['ed_month'], $by_bid[$bid][$key], true)) $by_bid[$bid][$key][] = $er['ed_month'];
            }
        }
    }

    $items = [];
    foreach ($by_bid as $bid => $d) {
        $it = $items_map[$bid] ?? null;
        if (!$it) continue;
        $mult = (float)($it['multiplier'] ?? 0);
        $items[] = [
            'kode_barang' => $it['kode_barang'], 'nama_barang' => $it['nama_barang'],
            'satuan' => $it['satuan'] ?? '', 'uom' => $it['uom'] ?? $it['satuan'] ?? '',
            'from_uom' => $it['from_uom'] ?? '', 'multiplier' => $mult > 0 ? $mult : null,
            'odoo_qty' => $odoo_map[$it['kode_barang']] ?? null, 'qty_total' => $d['qty_total'],
            'ed_expired' => $d['ed_expired'], 'ed_lte3m' => $d['ed_lte3m'], 'ed_gt3m' => $d['ed_gt3m'],
            'ed_expired_dates' => $d['exp_dates'], 'ed_lte3m_dates' => $d['lte_dates'], 'ed_gt3m_dates' => $d['gt_dates'],
            'catatan' => implode('; ', $d['catatan']),
        ];
    }
    return $items;
}

// Fetch items with UOM conversion info
$items_map = [];
$qIt = $conn->query("SELECT b.id, b.kode_barang, b.nama_barang, b.satuan,
    COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom,
    uc.from_uom, uc.multiplier
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    ORDER BY b.nama_barang ASC LIMIT 5000");
while ($qIt && ($r = $qIt->fetch_assoc())) {
    $items_map[(int)$r['id']] = $r;
}

// Fetch all kliniks
$kliniks = [];
$qKl = $conn->query("SELECT k.id, k.nama_klinik, k.alamat, k.kode_klinik, k.kode_homecare
    FROM inventory_klinik k WHERE k.status = 'active' ORDER BY k.nama_klinik ASC");
while ($qKl && ($r = $qKl->fetch_assoc())) $kliniks[] = $r;

// Fetch opname headers for this period
$opname_map = []; // klinik_id → opname_id
$qOp = $conn->query("SELECT id, klinik_id, is_locked FROM inventory_stok_opname
    WHERE periode = '$safe_per' AND klinik_id IS NOT NULL ORDER BY id ASC");
while ($qOp && ($r = $qOp->fetch_assoc())) {
    $kid = (int)$r['klinik_id'];
    $opname_map[$kid] = ['id' => (int)$r['id'], 'is_locked' => (int)$r['is_locked']];
}

// Fetch Odoo mirror for each klinik location
$odoo_by_klinik = []; // klinik_id → kode_barang → qty (already / multiplier)
foreach ($kliniks as $kl) {
    $kid  = (int)$kl['id'];
    $kode = trim($kl['kode_klinik'] ?? '');
    if ($kode === '') continue;
    $sk = $conn->real_escape_string($kode);
    $in_k = "'$sk','". $conn->real_escape_string($kode . '/Stock') . "'";
    $qOM = $conn->query("SELECT sm.kode_barang,
        SUM(sm.qty) / NULLIF(COALESCE(MAX(uc.multiplier), 1), 0) AS qty
        FROM inventory_stock_mirror sm
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = sm.kode_barang
        WHERE sm.location_code IN ($in_k)
        GROUP BY sm.kode_barang");
    $odoo_by_klinik[$kid] = [];
    while ($qOM && ($r = $qOM->fetch_assoc())) {
        $odoo_by_klinik[$kid][$r['kode_barang']] = (float)$r['qty'];
    }
}

// Check if required columns exist
$r_akt = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'qty_aktual'");
$has_akt = ($r_akt && $r_akt->num_rows > 0);
$r_hc  = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'hc_user_id'");
$has_hc = ($r_hc && $r_hc->num_rows > 0);

// Fetch HC users per klinik
$hc_users_map = []; // klinik_id → [user_id → nama]
$qHCU = $conn->query("SELECT id, klinik_id, nama_lengkap FROM inventory_users
    WHERE role = 'petugas_hc' AND status = 'active' ORDER BY nama_lengkap ASC");
while ($qHCU && ($r = $qHCU->fetch_assoc())) {
    $hc_users_map[(int)$r['klinik_id']][(int)$r['id']] = $r['nama_lengkap'];
}

// Fetch Odoo HC mirror per klinik
$odoo_hc_by_klinik = []; // klinik_id → kode_barang → qty
foreach ($kliniks as $kl) {
    $kid  = (int)$kl['id'];
    $kode_hc = trim($kl['kode_homecare'] ?? '');
    if ($kode_hc === '') continue;
    $sh = $conn->real_escape_string($kode_hc);
    $in_h = "'$sh','". $conn->real_escape_string($kode_hc . '/Stock') . "'";
    $qHM = $conn->query("SELECT sm.kode_barang,
        SUM(sm.qty) / NULLIF(COALESCE(MAX(uc.multiplier), 1), 0) AS qty
        FROM inventory_stock_mirror sm
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = sm.kode_barang
        WHERE sm.location_code IN ($in_h)
        GROUP BY sm.kode_barang");
    $odoo_hc_by_klinik[$kid] = [];
    while ($qHM && ($r = $qHM->fetch_assoc())) {
        $odoo_hc_by_klinik[$kid][$r['kode_barang']] = (float)$r['qty'];
    }
}

// Fetch SO details for each klinik
$result_kliniks = [];
foreach ($kliniks as $kl) {
    $kid  = (int)$kl['id'];
    $op   = $opname_map[$kid] ?? null;
    if (!$op) {
        $result_kliniks[] = ['nama' => $kl['nama_klinik'], 'kode' => $kl['kode_klinik'], 'kode_hc' => $kl['kode_homecare'] ?? '', 'status' => 'belum_dibuka', 'items' => [], 'hc' => []];
        continue;
    }
    $opname_id = (int)$op['id'];
    $is_locked = (int)$op['is_locked'];

    $akt_sel = $has_akt ? 'd.qty_aktual,' : 'NULL AS qty_aktual,';
    $hc_sel  = $has_hc  ? 'd.hc_user_id,'  : 'NULL AS hc_user_id,';

    // ── Klinik SO items ───────────────────────────────────────────────────────
    $items = fetch_so_klinik_items($conn, $opname_id, $items_map, $odoo_by_klinik[$kid] ?? []);

    // ── HC items (per nakes) ──────────────────────────────────────────────────
    $hc_rows = [];
    if ($has_hc) {
        $hcu_map = $hc_users_map[$kid] ?? [];
        if (!empty($hcu_map)) {
            $hcu_ids = implode(',', array_keys($hcu_map));
            $qHC = $conn->query("SELECT d.barang_id, d.hc_user_id, d.qty_fisik, $akt_sel
                COALESCE(d.catatan,'') AS catatan
                FROM inventory_stok_opname_detail d
                WHERE d.opname_id = $opname_id AND d.tipe = 'hc' AND d.hc_user_id IN ($hcu_ids)
                ORDER BY d.hc_user_id ASC, d.barang_id ASC");
            while ($qHC && ($r = $qHC->fetch_assoc())) {
                $bid  = (int)$r['barang_id'];
                $uid  = (int)$r['hc_user_id'];
                $it   = $items_map[$bid] ?? null;
                if (!$it) continue;
                $mult = (float)($it['multiplier'] ?? 0);
                $odoo_hc_q = $odoo_hc_by_klinik[$kid][$it['kode_barang']] ?? null;
                $akt  = $r['qty_aktual'] !== null ? (float)$r['qty_aktual'] : null;
                $hc_rows[] = [
                    'nakes_nama'  => $hcu_map[$uid] ?? ('Nakes #' . $uid),
                    'kode_barang' => $it['kode_barang'], 'nama_barang' => $it['nama_barang'],
                    'satuan'      => $it['satuan'] ?? '', 'from_uom' => $it['from_uom'] ?? '',
                    'uom'         => $it['uom'] ?? $it['satuan'] ?? '',
                    'multiplier'  => $mult > 0 ? $mult : null,
                    'odoo_qty'    => $odoo_hc_q,
                    'nakes_qty'   => (float)($r['qty_fisik'] ?? 0),
                    'qty_aktual'  => $akt,
                    'catatan'     => $r['catatan'],
                ];
            }
        }
    }

    $result_kliniks[] = [
        'nama'   => $kl['nama_klinik'], 'kode' => $kl['kode_klinik'], 'kode_hc' => $kl['kode_homecare'] ?? '',
        'status' => $is_locked ? 'terkunci' : 'buka',
        'items'  => $items,
        'hc'     => $hc_rows,
    ];
}

// ── Gudang Utama ────────────────────────────────────────────────────────────
$gudang_result = ['status' => 'belum_dibuka', 'items' => []];
$rGdOp = $conn->query("SELECT id, is_locked FROM inventory_stok_opname
    WHERE is_gudang_utama = 1 AND periode = '$safe_per' ORDER BY id DESC LIMIT 1");
$gdOpRow = $rGdOp ? $rGdOp->fetch_assoc() : null;
if ($gdOpRow) {
    $gd_opname_id = (int)$gdOpRow['id'];
    $gudang_result['status'] = ((int)$gdOpRow['is_locked']) ? 'terkunci' : 'buka';

    $gudang_result['items'] = fetch_so_klinik_items($conn, $gd_opname_id, $items_map, []);
}

echo json_encode(['success' => true, 'periode' => $periode, 'kliniks' => $result_kliniks, 'gudang' => $gudang_result]);
