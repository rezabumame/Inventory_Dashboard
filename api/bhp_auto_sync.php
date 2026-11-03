<?php
/**
 * Endpoint khusus integrasi otomatis (n8n -> Metabase -> sini) untuk mencatat Pemakaian BHP
 * (klinik & HC) sebagai record FINAL (is_auto=0, sama seperti input manual/upload Excel),
 * berdampingan dengan kedua jalur itu. Dibedakan sumbernya lewat change_source='sistem_integrasi',
 * BUKAN is_auto -- data dari Metabase/CIS/HC ini laporan yang sudah pasti terjadi (bukan perkiraan
 * dari resep booking yang masih menunggu konfirmasi manual), jadi tidak masuk tab "Estimasi (Auto)".
 *
 * Auth: header X-Api-Key, bukan session/CSRF (ini dipanggil mesin, bukan browser).
 *
 * Payload: { "rows": [ {jenis, tanggal_pemakaian, source_transaction_id, source_row_id,
 *   clinic_branch_id, clinic_branch_name, nakes_admin_id, barang_odoo_id, nama_barang,
 *   layanan, jumlah, satuan, channel, nama_pasien, nama_nakes, perusahaan_instansi, is_deleted}, ... ] }
 * — persis kolom hasil Metabase question "BHP Gabungan HC dan Klinik".
 *
 * Desain tracking PER BARIS SUMBER (bukan delete+rebuild per grup lagi):
 * - Tiap baris sumber punya "source_key" unik dari source_row_id ASLI di sistem sumbernya --
 *   HC: bumame_bhp_consumable_item.id, Klinik/CIS: cis_expendable_item_usage_items.id (eiui.id).
 *   WAJIB id baris granular ini, BUKAN kombinasi transaction_id+kode_barang -- 1 transaksi/encounter
 *   CIS bisa punya lebih dari 1 sesi pemakaian (eiu) terpisah dengan item yang sama tapi qty beda,
 *   kalau cuma dikunci transaction_id+kode_barang baris-baris valid itu saling tabrakan (riwayat
 *   bug: kasus 261019-O-0208, qty kelihatan "ganti-ganti" tiap sync -- lihat BHP_AUTO_SYNC.md).
 * - Dicatat di inventory_bhp_sync_tracking. Kalau source_key BELUM pernah diproses -> catat baris
 *   detail baru + potong stok. Kalau SUDAH pernah & qty SAMA -> dilewati total (tidak disentuh).
 *   Kalau SUDAH pernah tapi qty BEDA (koreksi di sumbernya) -> cuma sesuaikan SELISIHNYA, update
 *   baris detail yang sama (bukan hapus-bangun-ulang semua).
 * - Jika is_deleted=1 (item dibatalkan/dihapus/diganti di CIS/HC) -> reversal stok penuh (+last_qty),
 *   hapus baris detail dari inventory_pemakaian_bhp_detail, dan update tracking last_qty=0.
 * - Kalau baris detail yang ditrack ternyata sudah dihapus manual oleh admin di sistem kita,
 *   dilewati (hormati penghapusan manual, tidak dibuat ulang otomatis).
 * - Baris yang gagal mapping (klinik di luar whitelist, barang tidak ketemu, nakes tidak ketemu)
 *   di-SKIP per baris, bukan menggagalkan seluruh request.
 */

set_time_limit(600);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../lib/counter.php';

header('Content-Type: application/json');

// ── Auth ────────────────────────────────────────────────────────────────────
$api_key = get_setting('bhp_auto_api_key', '');
if ($api_key === '') {
    $api_key = bin2hex(random_bytes(24));
    set_setting('bhp_auto_api_key', $api_key);
    echo json_encode([
        'success' => false,
        'message' => 'API key belum pernah di-generate. Baru saja dibuatkan 1x — simpan ini, tidak akan ditampilkan lagi. Panggil ulang endpoint ini dengan header X-Api-Key.',
        'api_key' => $api_key,
    ]);
    exit;
}
$given_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals($api_key, $given_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: X-Api-Key tidak valid.']);
    exit;
}

// ── Parse payload ─────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
    echo json_encode(['success' => false, 'message' => 'Payload tidak valid, butuh {"rows":[...]}.']);
    exit;
}
$rows = $payload['rows'];

// Mapping klinik: source branch_id (bumame_branch.id) -> klinik_id kita. Cuma 5 klinik yang
// sudah dikonfirmasi; tambah 1 baris kalau ada klinik baru. Makassar (MKS) sengaja TIDAK
// dimasukkan -- masih manual sampai datanya siap di Metabase.
const BRANCH_MAP = [
    '1' => 3,  // Cideng
    '2' => 4,  // TB Simatupang
    '3' => 5,  // BSD
    '5' => 11, // Puri
    '8' => 12, // Kelapa Gading
];

function klinik_nama(mysqli $conn, int $klinik_id): string {
    static $cache = [];
    if (isset($cache[$klinik_id])) return $cache[$klinik_id];
    $r = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
    $nama = $r && $r->num_rows > 0 ? (string)$r->fetch_assoc()['nama_klinik'] : "klinik_id=$klinik_id";
    $cache[$klinik_id] = $nama;
    return $nama;
}

function user_nama(mysqli $conn, ?int $user_id): ?string {
    if (!$user_id) return null;
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    $r = $conn->query("SELECT nama_lengkap FROM inventory_users WHERE id = $user_id LIMIT 1");
    $nama = $r && $r->num_rows > 0 ? (string)$r->fetch_assoc()['nama_lengkap'] : null;
    $cache[$user_id] = $nama;
    return $nama;
}

function clean_num($v) {
    if ($v === null) return null;
    $s = str_replace(',', '', (string)$v);
    return trim($s);
}

// Cari barang berdasarkan kode_barang:
// Prioritas Satuan:
// 1. Konversi UOM BHP (inventory_barang_uom_conversion.to_uom) jika barang terdaftar di tabel konversi.
// 2. Satuan Master Barang (inventory_barang.satuan atau uom).
// 3. Fallback jika kosong: 'Pcs'.
function find_barang_info(mysqli $conn, string $kode_barang, array &$cache): ?array {
    if ($kode_barang === '') return null;
    if (array_key_exists($kode_barang, $cache)) return $cache[$kode_barang];
    $esc = $conn->real_escape_string($kode_barang);
    $r = $conn->query("
        SELECT 
            b.id,
            b.kode_barang,
            COALESCE(NULLIF(uc.to_uom, ''), NULLIF(b.satuan, ''), b.uom) AS satuan_final,
            uc.to_uom,
            uc.from_uom,
            COALESCE(uc.multiplier, 1) AS multiplier
        FROM inventory_barang b
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE b.kode_barang = '$esc'
        LIMIT 1
    ");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        $satuan = trim((string)($row['satuan_final'] ?? ''));
        $cache[$kode_barang] = [
            'id' => (int)$row['id'],
            'satuan' => $satuan !== '' ? $satuan : 'Pcs',
            'to_uom' => trim((string)($row['to_uom'] ?? '')),
            'from_uom' => trim((string)($row['from_uom'] ?? '')),
            'multiplier' => (float)($row['multiplier'] ?? 1),
        ];
    } else {
        $cache[$kode_barang] = null;
    }
    return $cache[$kode_barang];
}

// Resolusi nakes: cek mapping tersimpan dulu, kalau belum ada coba auto-match by nama
// (role petugas_hc, di klinik yang sama) -- simpan mapping-nya kalau ketemu persis 1.
function resolve_nakes_user_id(mysqli $conn, string $admin_id, string $nama_nakes, int $klinik_id, string $klinik_label): array {
    if ($admin_id === '') return ['user_id' => null, 'reason' => 'admin_id kosong'];

    $esc_admin = $conn->real_escape_string($admin_id);
    $r = $conn->query("SELECT user_id FROM inventory_nakes_external_mapping WHERE external_admin_id = '$esc_admin' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        return ['user_id' => (int)$r->fetch_assoc()['user_id'], 'reason' => null];
    }

    $nama_trim = trim($nama_nakes);
    if ($nama_trim === '') return ['user_id' => null, 'reason' => "admin_id $admin_id: nama nakes kosong, tidak bisa auto-match"];

    $esc_nama = $conn->real_escape_string($nama_trim);
    $r2 = $conn->query("SELECT id FROM inventory_users WHERE role = 'petugas_hc' AND klinik_id = $klinik_id AND status = 'active' AND nama_lengkap = '$esc_nama'");
    if ($r2 && $r2->num_rows === 1) {
        $uid = (int)$r2->fetch_assoc()['id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO inventory_nakes_external_mapping (external_admin_id, user_id, matched_by) VALUES (?, ?, 'auto_name')");
        $stmt->bind_param("si", $admin_id, $uid);
        $stmt->execute();
        return ['user_id' => $uid, 'reason' => null];
    }
    if ($r2 && $r2->num_rows > 1) {
        return ['user_id' => null, 'reason' => "nakes '$nama_trim' ada >1 orang dgn nama sama, perlu mapping manual"];
    }
    return ['user_id' => null, 'reason' => "nakes '$nama_trim' belum terdaftar di inventory dashboard"];
}

// Upsert stok: $delta positif = tambah stok (reversal), negatif = kurangi (pemakaian).
function adjust_stock(mysqli $conn, string $jenis, int $klinik_id, ?int $user_hc_id, int $barang_id, float $delta): void {
    if (abs($delta) < 0.000001) return;
    if ($jenis === 'hc') {
        $stmt = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = NOW()");
        $stmt->bind_param("iiid", $barang_id, $user_hc_id, $klinik_id, $delta);
    } else {
        $stmt = $conn->prepare("INSERT INTO inventory_stok_gudang_klinik (barang_id, klinik_id, qty, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = NOW()");
        $stmt->bind_param("iid", $barang_id, $klinik_id, $delta);
    }
    $stmt->execute();
}

function log_transaksi(mysqli $conn, string $jenis, int $klinik_id, ?int $user_hc_id, int $barang_id, float $delta, int $header_id, string $nomor, int $created_by, ?string $custom_cat = null): void {
    if (abs($delta) < 0.000001) return;
    $eff = stock_effective($conn, $klinik_id, $jenis === 'hc', $barang_id);
    $qty_sebelum = $eff['ok'] ? (float)$eff['on_hand'] : 0.0;
    // delta negatif (dipakai) -> stok berkurang sebesar |delta|; delta positif (koreksi turun/reversal) -> stok nambah.
    $qty_sesudah = $qty_sebelum + $delta;
    $level = $jenis === 'hc' ? 'hc' : 'klinik';
    $level_id = $jenis === 'hc' ? (int)$user_hc_id : $klinik_id;
    $tipe = $delta < 0 ? 'out' : 'in';
    $log_qty = abs($delta);
    $cat = $custom_cat ?? "Sync otomatis $nomor (penyesuaian)";
    $stmt = $conn->prepare("INSERT INTO inventory_transaksi_stok
        (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, catatan, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pemakaian_bhp', ?, ?, ?, NOW())");
    $stmt->bind_param("isisdddisi", $barang_id, $level, $level_id, $tipe, $log_qty, $qty_sebelum, $qty_sesudah, $header_id, $cat, $created_by);
    $stmt->execute();
}

// Cari header aktif hasil sync utk kombinasi ini, atau buat baru kalau belum ada.
function find_or_create_header(mysqli $conn, int $klinik_id, string $jenis, string $tanggal, ?int $user_hc_id, int $created_by): array {
    $uid_cond = $user_hc_id !== null ? "user_hc_id = " . (int)$user_hc_id : "user_hc_id IS NULL";
    $r = $conn->query("SELECT id, nomor_pemakaian FROM inventory_pemakaian_bhp
        WHERE change_source = 'sistem_integrasi' AND klinik_id = $klinik_id AND tanggal = '$tanggal'
          AND jenis_pemakaian = '$jenis' AND status = 'active' AND $uid_cond LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        return ['id' => (int)$row['id'], 'nomor' => $row['nomor_pemakaian'], 'is_new' => false];
    }

    $r_k = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
    $k_code_raw = ($jenis === 'hc') ? ($r_k['kode_homecare'] ?? 'HC') : ($r_k['kode_klinik'] ?? 'CLN');
    $k_code = explode('/', $k_code_raw)[0];
    $date_ym = date('ym', strtotime($tanggal));
    $prefix = 'BHP-' . $k_code;
    $seq = next_sequence($conn, $prefix, $date_ym);
    $nomor = $prefix . '-' . $date_ym . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $catatan = 'Sinkronisasi otomatis dari Metabase/CIS' . ($jenis === 'hc' ? ' (HC)' : ' (Klinik)');
    $stmt = $conn->prepare("INSERT INTO inventory_pemakaian_bhp
        (nomor_pemakaian, tanggal, jenis_pemakaian, klinik_id, user_hc_id, catatan_transaksi, created_by, status, is_auto, change_source)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, 'sistem_integrasi')");
    $stmt->bind_param("sssiisi", $nomor, $tanggal, $jenis, $klinik_id, $user_hc_id, $catatan, $created_by);
    $stmt->execute();
    return ['id' => $conn->insert_id, 'nomor' => $nomor, 'is_new' => true];
}

$barang_cache = [];
$valid_rows = [];
$skipped = [];

foreach ($rows as $i => $row) {
    $jenis = trim((string)($row['jenis'] ?? ''));
    if (!in_array($jenis, ['klinik', 'hc'], true)) {
        $skipped[] = ['row' => $i, 'klinik' => 'Lainnya', 'reason' => "jenis tidak valid: '$jenis'"];
        continue;
    }
    $tanggal_raw = (string)($row['tanggal_pemakaian'] ?? '');
    // strtotime() gagal parse format "21 October, 2026, 10:00" (ada koma) dari Metabase --
    // meski formatnya valid dibaca manusia. Hilangkan koma dulu supaya konsisten ke-parse.
    $ts = strtotime(str_replace(',', '', $tanggal_raw));
    if (!$ts) {
        $skipped[] = ['row' => $i, 'klinik' => 'Lainnya', 'reason' => "tanggal_pemakaian tidak bisa di-parse: '$tanggal_raw'"];
        continue;
    }
    $tanggal = date('Y-m-d', $ts);

    $branch_id = clean_num($row['clinic_branch_id'] ?? '');
    if (!isset(BRANCH_MAP[$branch_id])) {
        $branch_label = (string)($row['clinic_branch_name'] ?? "branch_id $branch_id");
        $skipped[] = ['row' => $i, 'klinik' => "$branch_label (belum siap otomasi)", 'reason' => "di luar whitelist klinik yang sudah siap"];
        continue;
    }
    $klinik_id = BRANCH_MAP[$branch_id];
    $klinik_label = klinik_nama($conn, $klinik_id);

    $is_del_raw = $row['is_deleted'] ?? 0;
    $is_deleted = in_array($is_del_raw, [1, '1', true, 'true'], true);

    $kode_barang_src = clean_num($row['barang_odoo_id'] ?? '');
    $nama_barang_src = (string)($row['nama_barang'] ?? '-');
    $barang_info = ($kode_barang_src !== '' && $kode_barang_src !== null)
        ? find_barang_info($conn, (string)$kode_barang_src, $barang_cache)
        : null;
    $barang_id = $barang_info ? $barang_info['id'] : null;

    if (!$barang_id && !$is_deleted) {
        $reason = ($kode_barang_src === '' || $kode_barang_src === null)
            ? "kode_barang kosong dari sumber utk item '$nama_barang_src' (barang_odoo_id tidak terisi di Metabase)"
            : "kode_barang '$kode_barang_src' ($nama_barang_src) tidak ketemu di master barang";
        $skipped[] = ['row' => $i, 'klinik' => $klinik_label, 'reason' => $reason];
        continue;
    }

    $raw_jumlah = (float)clean_num($row['jumlah'] ?? 0);
    $raw_satuan = trim((string)($row['satuan'] ?? ''));

    // UOM / Satuan:
    // Prioritas 1: Jika barang ada di tabel 'Konversi UOM BHP', gunakan to_uom (UOM Operasional, misal Btl / Vial / tube / pfs)
    // Prioritas 2: Gunakan satuan master di inventory_barang kita
    // Prioritas 3: Fallback ke satuan dari Metabase
    $satuan_final = $barang_info && !empty($barang_info['satuan'])
        ? $barang_info['satuan']
        : ($raw_satuan !== '' ? $raw_satuan : 'Pcs');

    $jumlah = $raw_jumlah;
    // Jika ada rasio konversi dan data sumber mengirim angka dalam satuan Odoo (misal mg / mL yang besar)
    if ($barang_info && $barang_info['multiplier'] > 1 && !empty($barang_info['from_uom'])) {
        if (strcasecmp($raw_satuan, $barang_info['from_uom']) === 0 && $raw_jumlah >= $barang_info['multiplier']) {
            $jumlah = $raw_jumlah / $barang_info['multiplier'];
        }
    }

    if (!$is_deleted && $jumlah <= 0) {
        $skipped[] = ['row' => $i, 'klinik' => $klinik_label, 'reason' => "jumlah <= 0, dilewati"];
        continue;
    }

    $user_hc_id = null;
    if ($jenis === 'hc') {
        $admin_id = clean_num($row['nakes_admin_id'] ?? '');
        $nama_nakes = (string)($row['nama_nakes'] ?? '');
        $res = resolve_nakes_user_id($conn, (string)$admin_id, $nama_nakes, $klinik_id, $klinik_label);
        if (!$res['user_id'] && !$is_deleted) {
            $skipped[] = ['row' => $i, 'klinik' => $klinik_label, 'reason' => $res['reason']];
            continue;
        }
        $user_hc_id = $res['user_id'];
    }

    // Identitas unik per baris sumber -- WAJIB pakai source_row_id (id baris eiui/bhp_consumable_item
    // asli dari sumber), BUKAN komposit transaction_id+kode_barang. Satu transaksi/encounter CIS bisa
    // punya lebih dari 1 sesi pemakaian (eiu) terpisah yang sama-sama mengandung item yang sama dengan
    // qty beda -- komposit lama akan menabrakkan baris-baris valid yang berbeda itu jadi 1 kunci yang
    // sama, bikin qty "ganti-ganti" tiap sync tergantung urutan baris yang dikembalikan Metabase.
    $src_row_id = clean_num($row['source_row_id'] ?? '');
    if ($src_row_id === '' || $src_row_id === null) {
        $skipped[] = ['row' => $i, 'klinik' => $klinik_label, 'reason' => "source_row_id kosong utk baris $jenis, tidak bisa di-track (pastikan query Metabase sudah expose eiui.id utk klinik)"];
        continue;
    }
    $source_key = $jenis . ':' . $src_row_id;

    $valid_rows[] = [
        'source_key' => $source_key,
        'jenis' => $jenis,
        'tanggal' => $tanggal,
        'klinik_id' => $klinik_id,
        'user_hc_id' => $user_hc_id,
        'barang_id' => $barang_id,
        'jumlah' => $jumlah,
        'satuan' => $satuan_final,
        'nama_barang' => (string)($row['nama_barang'] ?? ''),
        'is_deleted' => $is_deleted,
    ];
}

// ── Kelompokkan per (tanggal, klinik, jenis, user_hc_id) HANYA utk batas transaksi/lock ──
// (bukan utk digabung jadi 1 angka lagi -- tiap baris sumber tetap ditrack terpisah).
$groups = [];
foreach ($valid_rows as $vr) {
    $key = $vr['tanggal'] . '|' . $vr['klinik_id'] . '|' . $vr['jenis'] . '|' . ($vr['user_hc_id'] ?? '0');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'tanggal' => $vr['tanggal'], 'klinik_id' => $vr['klinik_id'],
            'jenis' => $vr['jenis'], 'user_hc_id' => $vr['user_hc_id'], 'rows' => [],
        ];
    }
    $groups[$key]['rows'][] = $vr;
}

$created_by = 1; // super_admin, dipakai sbg pemilik teknis record otomatis
$stat_new = 0;
$stat_updated = 0;
$stat_deleted = 0;
$stat_unchanged = 0;
$stat_removed_respected = 0;
$stat_bhp_baru = 0; // jumlah NOMOR BHP (header) baru dibuat, beda dari jumlah baris/item
$errors = [];
$processed_groups = [];

foreach ($groups as $g) {
    $klinik_id = $g['klinik_id'];
    $jenis = $g['jenis'];
    $tanggal = $g['tanggal'];
    $user_hc_id = $g['user_hc_id'];

    $lock_name = 'stock_pemakaian_bhp_' . (int)$klinik_id . '_' . preg_replace('/[^a-z]/', '', $jenis);
    $lock = advisory_get_lock($conn, $lock_name, 10);
    if (!$lock['got'] && $lock['supported']) {
        $errors[] = ['klinik_id' => $klinik_id, 'tanggal' => $tanggal, 'jenis' => $jenis, 'user_hc_id' => $user_hc_id, 'message' => 'Gagal ambil lock, coba lagi nanti.'];
        continue;
    }

    $conn->begin_transaction();
    try {
        $header = null; // lazy: baru dibuat/dicari kalau memang ada baris baru/berubah
        $touched = 0;
        $group_new = 0;
        $group_updated = 0;
        $group_deleted = 0;
        $touched_qty = 0.0;

        foreach ($g['rows'] as $vr) {
            $esc_key = $conn->real_escape_string($vr['source_key']);
            $r_track = $conn->query("SELECT * FROM inventory_bhp_sync_tracking WHERE source_key = '$esc_key' LIMIT 1 FOR UPDATE");
            $track = $r_track && $r_track->num_rows > 0 ? $r_track->fetch_assoc() : null;

            // --- PENANGANAN ITEM DIHAPUS / DIBATALKAN DI SUMBER (CIS / HC) ---
            if ($vr['is_deleted']) {
                if (!$track) {
                    // Baris dibatalkan di sumber sebelum sempat diproses di inventory, abaikan
                    continue;
                }

                $old_qty = (float)$track['last_qty'];
                $detail_id = (int)$track['pemakaian_bhp_detail_id'];
                $track_bid = (int)($track['barang_id'] ?: $vr['barang_id']);
                $hdr_id = (int)$track['pemakaian_bhp_id'];

                if ($old_qty > 0) {
                    if ($header === null) {
                        $r_h = $conn->query("SELECT id, nomor_pemakaian FROM inventory_pemakaian_bhp WHERE id = $hdr_id LIMIT 1")->fetch_assoc();
                        $header = ['id' => $hdr_id, 'nomor' => $r_h['nomor_pemakaian'] ?? '', 'is_new' => false];
                    }

                    // 1. Reversal stok penuh (+old_qty)
                    adjust_stock($conn, $jenis, $klinik_id, $user_hc_id ?? (int)($track['user_hc_id'] ?? 0), $track_bid, +$old_qty);

                    // 2. Log audit penyesuaian (tipe 'in' / reversal)
                    $cat_batal = "Sync otomatis " . ($header['nomor'] ?? '') . " (Batal/Hapus di sumber CIS/HC)";
                    log_transaksi($conn, $jenis, $klinik_id, $user_hc_id ?? (int)($track['user_hc_id'] ?? 0), $track_bid, +$old_qty, $hdr_id, $header['nomor'] ?? '', $created_by, $cat_batal);
                }

                // 3. Hapus baris detail dari inventory_pemakaian_bhp_detail
                $conn->query("DELETE FROM inventory_pemakaian_bhp_detail WHERE id = $detail_id");

                // 4. Update tracking last_qty = 0 agar tidak diproses lagi
                $tid = (int)$track['id'];
                $conn->query("UPDATE inventory_bhp_sync_tracking SET last_qty = 0, last_seen_at = NOW() WHERE id = $tid");

                $stat_deleted++;
                $touched++;
                $group_deleted++;
                $touched_qty += $old_qty;
                continue;
            }

            if (!$track) {
                // Baris baru sama sekali.
                if ($header === null) $header = find_or_create_header($conn, $klinik_id, $jenis, $tanggal, $user_hc_id, $created_by);
                $stmt_d = $conn->prepare("INSERT INTO inventory_pemakaian_bhp_detail (pemakaian_bhp_id, barang_id, qty, satuan) VALUES (?, ?, ?, ?)");
                $stmt_d->bind_param("iids", $header['id'], $vr['barang_id'], $vr['jumlah'], $vr['satuan']);
                $stmt_d->execute();
                $detail_id = $conn->insert_id;

                adjust_stock($conn, $jenis, $klinik_id, $user_hc_id, $vr['barang_id'], -$vr['jumlah']);
                log_transaksi($conn, $jenis, $klinik_id, $user_hc_id, $vr['barang_id'], -$vr['jumlah'], $header['id'], $header['nomor'], $created_by);

                $stmt_t = $conn->prepare("INSERT INTO inventory_bhp_sync_tracking
                    (source_key, pemakaian_bhp_detail_id, pemakaian_bhp_id, barang_id, klinik_id, jenis, user_hc_id, last_qty)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_t->bind_param("siiiisid", $vr['source_key'], $detail_id, $header['id'], $vr['barang_id'], $klinik_id, $jenis, $user_hc_id, $vr['jumlah']);
                $stmt_t->execute();
                $stat_new++;
                $touched++;
                $group_new++;
                $touched_qty += $vr['jumlah'];
                continue;
            }

            // Sudah pernah diproses -- cek baris detailnya masih ada (belum dihapus manual).
            $detail_id = (int)$track['pemakaian_bhp_detail_id'];
            $r_det = $conn->query("SELECT qty FROM inventory_pemakaian_bhp_detail WHERE id = $detail_id LIMIT 1");
            if (!$r_det || $r_det->num_rows === 0) {
                // Admin sudah hapus manual -- hormati, jangan dibuat ulang otomatis.
                $stat_removed_respected++;
                continue;
            }

            $old_qty = (float)$track['last_qty'];
            $new_qty = $vr['jumlah'];
            if (abs($new_qty - $old_qty) < 0.000001) {
                // Tidak berubah -- lewati total, tidak disentuh.
                $stat_unchanged++;
                continue;
            }

            // Berubah -- sesuaikan SELISIHNYA saja.
            if ($header === null) {
                $r_h = $conn->query("SELECT id, nomor_pemakaian FROM inventory_pemakaian_bhp WHERE id = " . (int)$track['pemakaian_bhp_id'] . " LIMIT 1")->fetch_assoc();
                $header = ['id' => (int)$track['pemakaian_bhp_id'], 'nomor' => $r_h['nomor_pemakaian'] ?? '', 'is_new' => false];
            }
            $delta = $new_qty - $old_qty; // positif = tambah pemakaian, negatif = koreksi turun
            $stmt_u = $conn->prepare("UPDATE inventory_pemakaian_bhp_detail SET qty = ? WHERE id = ?");
            $stmt_u->bind_param("di", $new_qty, $detail_id);
            $stmt_u->execute();

            adjust_stock($conn, $jenis, $klinik_id, $user_hc_id, $vr['barang_id'], -$delta);
            log_transaksi($conn, $jenis, $klinik_id, $user_hc_id, $vr['barang_id'], -$delta, $header['id'], $header['nomor'], $created_by);

            $stmt_tu = $conn->prepare("UPDATE inventory_bhp_sync_tracking SET last_qty = ? WHERE id = ?");
            $tid = (int)$track['id'];
            $stmt_tu->bind_param("di", $new_qty, $tid);
            $stmt_tu->execute();
            $stat_updated++;
            $touched++;
            $group_updated++;
            $touched_qty += abs($delta);
        }

        $conn->commit();
        if ($touched > 0 && $header !== null) {
            $header_is_new = (bool)($header['is_new'] ?? false);
            if ($header_is_new) $stat_bhp_baru++;
            $processed_groups[] = [
                'klinik_id' => $klinik_id,
                'klinik' => klinik_nama($conn, $klinik_id),
                'tanggal' => $tanggal,
                'jenis' => $jenis,
                'user_hc_id' => $user_hc_id,
                'nakes' => $jenis === 'hc' ? user_nama($conn, $user_hc_id) : null,
                'nomor_pemakaian' => $header['nomor'],
                'nomor_baru' => $header_is_new,
                'baris_baru' => $group_new,
                'baris_diupdate' => $group_updated,
                'baris_dihapus' => $group_deleted,
                'touched_qty' => $touched_qty,
            ];
        }
    } catch (\Throwable $e) {
        $conn->rollback();
        $errors[] = [
            'klinik_id' => $klinik_id, 'tanggal' => $tanggal, 'jenis' => $jenis, 'user_hc_id' => $user_hc_id,
            'message' => 'PHP Error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
        ];
    } finally {
        advisory_release_lock($conn, $lock_name);
    }
}

echo json_encode([
    'success' => true,
    'total_rows_received' => count($rows),
    'valid_rows' => count($valid_rows),
    'baris_baru' => $stat_new,
    'baris_diupdate' => $stat_updated,
    'baris_dihapus' => $stat_deleted,
    'baris_tidak_berubah' => $stat_unchanged,
    'nomor_bhp_baru' => $stat_bhp_baru,
    'baris_dihapus_manual_dihormati' => $stat_removed_respected,
    'groups_processed' => count($processed_groups),
    'processed' => $processed_groups,
    'skipped' => $skipped,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
