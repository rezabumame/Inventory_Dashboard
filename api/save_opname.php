<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$allowed = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$payload   = json_decode(file_get_contents('php://input'), true);
$is_gudang = !empty($payload['is_gudang']);
$klinik_id = (int)($payload['klinik_id'] ?? 0);
$details   = $payload['details'] ?? [];
$user_id   = (int)$_SESSION['user_id'];

if (!csrf_validate($payload['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']); exit;
}

if (!$is_gudang) {
    if (!in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
        $own = (int)($_SESSION['klinik_id'] ?? 0);
        if ($klinik_id !== $own) {
            echo json_encode(['success' => false, 'message' => 'Tidak bisa simpan opname klinik lain.']); exit;
        }
    }
    if ($klinik_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
    }
} else {
    // Gudang hanya super_admin / admin_gudang
    if (!in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
    }
}
if (empty($details)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
}

// Cek kolom baru
$r_col = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'ed_lte3m'");
$has_ed_cols = ($r_col && $r_col->num_rows > 0);
$r_col_exp = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'ed_expired'");
$has_ed_expired_col = ($r_col_exp && $r_col_exp->num_rows > 0);
$r_col2 = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'hc_user_id'");
$has_hc_col = ($r_col2 && $r_col2->num_rows > 0);
$r_col3 = $conn->query("SHOW COLUMNS FROM inventory_stok_opname_detail LIKE 'qty_aktual'");
$has_qty_aktual_col = ($r_col3 && $r_col3->num_rows > 0);
$r_ed_tbl = $conn->query("SHOW TABLES LIKE 'inventory_stok_opname_ed_detail'");
$has_ed_detail_tbl = ($r_ed_tbl && $r_ed_tbl->num_rows > 0);

// Cek kolom periode & is_locked
$r_per = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'periode'");
$has_periode_col = ($r_per && $r_per->num_rows > 0);
$r_lck = $conn->query("SHOW COLUMNS FROM inventory_stok_opname LIKE 'is_locked'");
$has_locked_col  = ($r_lck && $r_lck->num_rows > 0);

// Advisory lock itu session-level, BUKAN bagian dari transaksi — kalau dilepas sebelum transaksi
// commit, request lain yang menunggu lock bisa langsung lolos cek-lalu-tulis padahal tulisan
// request ini belum kelihatan (belum commit), jadi tetap bisa lolos duplikat. Semua RELEASE_LOCK
// ditunda, dikumpulkan di sini, baru dilepas SETELAH commit().
$locks_to_release = [];
function so_release_all_locks(mysqli $conn, array $lock_names): void {
    foreach ($lock_names as $ln) $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($ln) . "')");
}

$conn->begin_transaction();
try {
    // Cari opname aktif: periode bulan ini (jika kolom periode ada), fallback ke hari ini
    $now    = date('Y-m-d H:i:s');
    $today  = date('Y-m-d');
    $periode = date('Y-m');

    if ($has_periode_col) {
        // Sesi aktif = baris yang belum dikunci untuk lokasi ini, apa pun periodenya —
        // supaya sesi yang berjalan lewat tengah malam (lintas bulan kalender) tidak terputus.
        // status='draft' (sesi auto-create dari laporan nakes, belum pernah "dibuka" admin sendiri)
        // dikecualikan — endpoint ini dipakai admin utk validasi, tidak boleh menulis ke sesi yang
        // belum diakui admin ada. Tie-break periode DESC dulu (bukan cuma id DESC) konsisten dgn
        // resolusi di tempat lain, supaya tidak salah pilih periode lama yang sempat dibuka-kunci ulang.
        $lck_sel2  = $has_locked_col ? 'is_locked' : '0 AS is_locked';
        $lock_cond = $has_locked_col ? "AND (is_locked = 0 OR is_locked IS NULL)" : '';
        $draft_cond2 = "AND (status IS NULL OR status != 'draft')";
        if ($is_gudang) {
            $rOp = $conn->query("SELECT id, periode, $lck_sel2 FROM inventory_stok_opname
                WHERE is_gudang_utama = 1 $lock_cond $draft_cond2
                ORDER BY periode DESC, id DESC LIMIT 1");
        } else {
            $rOp = $conn->query("SELECT id, periode, $lck_sel2 FROM inventory_stok_opname
                WHERE klinik_id = $klinik_id $lock_cond $draft_cond2
                ORDER BY periode DESC, id DESC LIMIT 1");
        }
        $opRow = $rOp ? $rOp->fetch_assoc() : null;
        $opname_id = $opRow ? (int)$opRow['id'] : 0;

        if (!$opname_id) {
            $label = $is_gudang ? "SO Gudang periode $periode" : "SO periode $periode";
            throw new RuntimeException("$label belum dibuka. Silakan buka SO terlebih dahulu melalui halaman Stock Opname.");
        }
        // Jaga-jaga: kalau client sedang menampilkan periode historis (bukan sesi aktif saat ini — mis.
        // tab lama yang belum di-refresh saat sesi aktif berpindah), tolak supaya tidak salah simpan ke periode lain.
        $client_periode = trim((string)($payload['periode'] ?? ''));
        if ($client_periode !== '' && $client_periode !== $opRow['periode']) {
            throw new RuntimeException("Sesi aktif sudah berpindah ke periode {$opRow['periode']}. Muat ulang halaman.");
        }
    } else {
        // Fallback: cari/buat berdasarkan hari ini (legacy, klinik only)
        $lck_sel = $has_locked_col ? 'is_locked' : '0 AS is_locked';
        $rOp = $conn->query("SELECT id, $lck_sel FROM inventory_stok_opname
            WHERE klinik_id = $klinik_id AND DATE(tanggal_mulai) = '$today'
            ORDER BY id DESC LIMIT 1");
        $opRow = $rOp ? $rOp->fetch_assoc() : null;
        $opname_id = $opRow ? (int)$opRow['id'] : 0;
        if ($opname_id && $has_locked_col && (int)($opRow['is_locked'] ?? 0)) {
            throw new RuntimeException('SO hari ini sudah terkunci dan tidak dapat diubah.');
        }
        if (!$opname_id) {
            $conn->query("INSERT INTO inventory_stok_opname (klinik_id, user_id, tanggal_mulai, tanggal_selesai, status, catatan)
                VALUES ($klinik_id, $user_id, '$now', '$now', 'selesai', '')");
            $opname_id = (int)$conn->insert_id;
            if (!$opname_id) throw new RuntimeException('Gagal membuat sesi opname: ' . $conn->error);
        }
    }

    // Update tanggal selesai
    $conn->query("UPDATE inventory_stok_opname SET tanggal_selesai='$now' WHERE id=$opname_id");

    $detail_ids_out = [];
    $hc_owner_cache = []; // hc_user_id => bool, sudah dicek milik klinik_id ini atau tidak
    foreach ($details as $d) {
        $barang_id  = (int)($d['barang_id'] ?? 0);
        $tipe       = in_array($d['tipe'] ?? 'klinik', ['klinik','hc']) ? $d['tipe'] : 'klinik';
        $hc_user_id = isset($d['hc_user_id']) ? (int)$d['hc_user_id'] : 0;
        $stok_sistem = (float)($d['stok_sistem'] ?? 0);
        $qty_fisik  = isset($d['qty_fisik']) ? (float)$d['qty_fisik'] : null;
        // qty_aktual = stok aktual yang divalidasi admin (terpisah dari qty_fisik nakes)
        $qty_aktual_payload = array_key_exists('qty_aktual', $d);
        $qty_aktual = $qty_aktual_payload && $d['qty_aktual'] !== null ? (float)$d['qty_aktual'] : null;
        $ed_lte3m   = (float)($d['ed_lte3m'] ?? 0);
        $ed_gt3m    = (float)($d['ed_gt3m'] ?? 0);
        $ed_expired = (float)($d['ed_expired'] ?? 0);
        $catatan    = $conn->real_escape_string(trim((string)($d['catatan'] ?? '')));
        $selisih    = $qty_fisik !== null ? ($qty_fisik - $stok_sistem) : null;
        $status     = $qty_fisik === null ? 'pending' : ($selisih == 0 ? 'ok' : 'selisih');
        if ($barang_id <= 0) continue;
        // Breakdown ED (kalau diisi) harus sama dgn qty_fisik — sudah divalidasi di client, ini
        // defense-in-depth server-side supaya panggilan API langsung tidak bisa menyimpan data
        // yang totalnya tidak konsisten.
        $ed_total_chk = $ed_lte3m + $ed_gt3m + $ed_expired;
        if ($qty_fisik !== null && $ed_total_chk > 0 && abs($ed_total_chk - $qty_fisik) > 0.0001) continue;

        // Pastikan hc_user_id (nakes yang datanya diedit) benar-benar milik klinik ini —
        // cegah admin/SPV klinik lain menautkan data ke nakes klinik lain lewat manipulasi request.
        if ($tipe === 'hc' && $hc_user_id > 0 && !$is_gudang) {
            if (!array_key_exists($hc_user_id, $hc_owner_cache)) {
                $rOwn = $conn->query("SELECT id FROM inventory_users WHERE id=$hc_user_id AND klinik_id=$klinik_id LIMIT 1");
                $hc_owner_cache[$hc_user_id] = ($rOwn && $rOwn->num_rows > 0);
            }
            if (!$hc_owner_cache[$hc_user_id]) continue; // nakes ini bukan milik klinik ini, lewati
        }

        $detail_id = 0;
        if ($has_hc_col && $has_ed_cols) {
            $hc_val   = $hc_user_id > 0 ? $hc_user_id : 'NULL';
            $hc_where = $hc_user_id > 0 ? "hc_user_id=$hc_user_id" : "hc_user_id IS NULL";

            if ($tipe === 'hc') {
                // HC: satu baris per (opname, barang, nakes) — upsert manual (bukan lagi via unique key,
                // karena constraint dilepas agar riwayat scan Klinik bisa multi-baris). Advisory lock
                // supaya cek-lalu-insert ini tidak diselang request lain yg konkuren (double-submit/race).
                $hc_lock_name = "hc_so_{$opname_id}_{$barang_id}_" . ($hc_user_id > 0 ? $hc_user_id : 0);
                $rHcLock = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($hc_lock_name) . "', 5) AS got");
                $got_hc_lock = $rHcLock && (int)($rHcLock->fetch_assoc()['got'] ?? 0) === 1;

                $existing_id = 0;
                $rExist = $conn->query("SELECT id FROM inventory_stok_opname_detail WHERE opname_id=$opname_id AND barang_id=$barang_id AND tipe='hc' AND $hc_where LIMIT 1");
                if ($rExist) $existing_id = (int)($rExist->fetch_assoc()['id'] ?? 0);

                if ($has_qty_aktual_col && $qty_aktual_payload && $qty_fisik === null) {
                    // HC quick-save: hanya update qty_aktual, jangan timpa qty_fisik (laporan nakes)
                    $akt_val   = $qty_aktual !== null ? $qty_aktual : 'NULL';
                    $hc_status = $qty_aktual !== null ? 'validated' : 'pending';
                    if ($existing_id > 0) {
                        $conn->query("UPDATE inventory_stok_opname_detail SET
                            stok_sistem=$stok_sistem, qty_aktual=$akt_val, catatan='$catatan', status='$hc_status'
                            WHERE id=$existing_id");
                        $detail_id = $existing_id;
                    } else {
                        $conn->query("INSERT INTO inventory_stok_opname_detail
                            (opname_id, barang_id, hc_user_id, tipe, stok_sistem, qty_fisik, qty_aktual, selisih, ed_lte3m, ed_gt3m, catatan, status)
                            VALUES ($opname_id, $barang_id, $hc_val, 'hc', $stok_sistem, NULL, $akt_val, NULL, 0, 0, '$catatan', '$hc_status')");
                        $detail_id = (int)$conn->insert_id;
                    }
                } else {
                    $qty_val = $qty_fisik !== null ? $qty_fisik : 'NULL';
                    $sel_val = $selisih   !== null ? $selisih   : 'NULL';
                    // HC: qty_aktual (Stok Aktual, hasil validasi admin) HARUS independen dari qty_fisik
                    // (laporan nakes) — jangan pernah ikut ditimpa hanya krn payload ini menyimpan qty_fisik
                    // tanpa qty_aktual eksplisit, supaya validasi yang sudah ada tidak rusak/salah satuan.
                    $akt_val = $has_qty_aktual_col && $qty_aktual !== null ? $qty_aktual : null;
                    $exp_col = $has_ed_expired_col ? ', ed_expired' : '';
                    $exp_ins = $has_ed_expired_col ? ", $ed_expired" : '';
                    $exp_set = $has_ed_expired_col ? ", ed_expired=$ed_expired" : '';
                    if ($existing_id > 0) {
                        $akt_set = ($has_qty_aktual_col && $akt_val !== null) ? ", qty_aktual=$akt_val" : '';
                        $conn->query("UPDATE inventory_stok_opname_detail SET
                            stok_sistem=$stok_sistem, qty_fisik=$qty_val, selisih=$sel_val$akt_set,
                            ed_lte3m=$ed_lte3m, ed_gt3m=$ed_gt3m$exp_set, catatan='$catatan', status='$status'
                            WHERE id=$existing_id");
                        $detail_id = $existing_id;
                    } else {
                        $akt_col = ($has_qty_aktual_col && $akt_val !== null) ? ', qty_aktual' : '';
                        $akt_ins = ($has_qty_aktual_col && $akt_val !== null) ? ", $akt_val" : '';
                        $conn->query("INSERT INTO inventory_stok_opname_detail
                            (opname_id, barang_id, hc_user_id, tipe, stok_sistem, qty_fisik$akt_col, selisih, ed_lte3m, ed_gt3m$exp_col, catatan, status)
                            VALUES ($opname_id, $barang_id, $hc_val, '$tipe', $stok_sistem, $qty_val$akt_ins, $sel_val, $ed_lte3m, $ed_gt3m$exp_ins, '$catatan', '$status')");
                        $detail_id = (int)$conn->insert_id;
                    }
                }
                if ($got_hc_lock) $locks_to_release[] = $hc_lock_name;
            } else {
                // Klinik: setiap simpan = 1 riwayat scan baru, kecuali detail_id eksplisit dikirim (edit riwayat tertentu)
                $req_detail_id = isset($d['detail_id']) ? (int)$d['detail_id'] : 0;
                $existing_id = 0;
                if ($req_detail_id > 0) {
                    $rChk = $conn->query("SELECT id FROM inventory_stok_opname_detail WHERE id=$req_detail_id AND opname_id=$opname_id AND barang_id=$barang_id AND tipe='klinik' LIMIT 1");
                    if ($rChk && $rChk->num_rows > 0) $existing_id = $req_detail_id;
                }
                $qty_val = $qty_fisik !== null ? $qty_fisik : 'NULL';
                $sel_val = $selisih   !== null ? $selisih   : 'NULL';
                $akt_val = $has_qty_aktual_col ? ($qty_aktual !== null ? $qty_aktual : $qty_val) : null;
                $exp_col = $has_ed_expired_col ? ', ed_expired' : '';
                $exp_ins = $has_ed_expired_col ? ", $ed_expired" : '';
                $exp_set = $has_ed_expired_col ? ", ed_expired=$ed_expired" : '';
                if ($existing_id > 0) {
                    $akt_set = $has_qty_aktual_col ? ", qty_aktual=$akt_val" : '';
                    $conn->query("UPDATE inventory_stok_opname_detail SET
                        stok_sistem=$stok_sistem, qty_fisik=$qty_val, selisih=$sel_val$akt_set,
                        ed_lte3m=$ed_lte3m, ed_gt3m=$ed_gt3m$exp_set, catatan='$catatan', status='$status'
                        WHERE id=$existing_id");
                    $detail_id = $existing_id;
                } else {
                    $akt_col = $has_qty_aktual_col ? ', qty_aktual' : '';
                    $akt_ins = $has_qty_aktual_col ? ", $akt_val" : '';
                    $conn->query("INSERT INTO inventory_stok_opname_detail
                        (opname_id, barang_id, hc_user_id, tipe, stok_sistem, qty_fisik$akt_col, selisih, ed_lte3m, ed_gt3m$exp_col, catatan, status)
                        VALUES ($opname_id, $barang_id, $hc_val, 'klinik', $stok_sistem, $qty_val$akt_ins, $sel_val, $ed_lte3m, $ed_gt3m$exp_ins, '$catatan', '$status')");
                    $detail_id = (int)$conn->insert_id;
                }
            }
        } else {
            $qty_val = $qty_fisik !== null ? $qty_fisik : 'NULL';
            $sel_val = $selisih   !== null ? $selisih   : 'NULL';
            $conn->query("INSERT INTO inventory_stok_opname_detail
                (opname_id, barang_id, stok_sistem, qty_fisik, selisih, status)
                VALUES ($opname_id, $barang_id, $stok_sistem, $qty_val, $sel_val, '$status')");
            $detail_id = (int)$conn->insert_id;
        }
        $detail_ids_out[] = $detail_id;

        // Save per-ED breakdown if ed_detail provided and table exists
        if ($has_ed_detail_tbl && $detail_id > 0 && isset($d['ed_detail']) && is_array($d['ed_detail'])) {
            $conn->query("DELETE FROM inventory_stok_opname_ed_detail WHERE opname_detail_id=$detail_id");
            foreach ($d['ed_detail'] as $ed) {
                $ed_month = $conn->real_escape_string(trim((string)($ed['ed_month'] ?? '')));
                $ed_ket   = $conn->real_escape_string(trim((string)($ed['ket'] ?? '')));
                $ed_qty   = (float)($ed['qty'] ?? 0);
                $kategori = in_array($ed['kategori'] ?? '', ['expired', 'lte3m', 'gt3m'], true)
                    ? $ed['kategori']
                    : (($ed['isLte'] ?? false) ? 'lte3m' : 'gt3m');
                if (!$ed_month || $ed_qty <= 0) continue;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed_month)) continue;
                $conn->query("INSERT INTO inventory_stok_opname_ed_detail (opname_detail_id, ed_month, keterangan, qty, kategori) VALUES ($detail_id, '$ed_month', '$ed_ket', $ed_qty, '$kategori')");
            }
        }
    }

    // Setelah simpan opname_detail, update stok_tas_hc hanya dari qty_aktual (hasil validasi admin, skip untuk gudang)
    if ($is_gudang) {
        $conn->commit();
        so_release_all_locks($conn, $locks_to_release);
        echo json_encode(['success' => true, 'opname_id' => $opname_id, 'detail_ids' => $detail_ids_out, 'message' => 'Stock opname Gudang berhasil disimpan.']);
        return;
    }

    // Kumpulkan barang_id+hc_user_id yang ada di payload ini untuk scope sync
    $hc_pairs_in_payload = [];
    foreach ($details as $d) {
        $tid = in_array($d['tipe'] ?? 'klinik', ['klinik','hc']) ? $d['tipe'] : 'klinik';
        if ($tid === 'hc') {
            $hc_pairs_in_payload[] = [(int)($d['barang_id'] ?? 0), (int)($d['hc_user_id'] ?? 0)];
        }
    }

    if ($has_hc_col && $has_qty_aktual_col && !empty($hc_pairs_in_payload)) {
        // Build IN clause for the specific (barang_id, hc_user_id) pairs saved in this request
        $pair_conds = implode(' OR ', array_map(fn($p) => "(barang_id={$p[0]} AND hc_user_id={$p[1]})", $hc_pairs_in_payload));
        $rHC = $conn->query("SELECT barang_id, hc_user_id, qty_aktual AS final_qty
            FROM inventory_stok_opname_detail
            WHERE opname_id = $opname_id AND tipe = 'hc' AND hc_user_id IS NOT NULL
              AND qty_aktual IS NOT NULL AND ($pair_conds)");
        while ($rHC && ($hrow = $rHC->fetch_assoc())) {
            $b_id  = (int)$hrow['barang_id'];
            $u_id  = (int)$hrow['hc_user_id'];
            $q_val = (float)$hrow['final_qty'];
            if ($q_val <= 0) {
                // Divalidasi 0 = item itu memang tidak ada di tas nakes — hapus barisnya
                // (bukan simpan qty=0), supaya benar-benar hilang dari semua tampilan stok tas.
                $conn->query("DELETE FROM inventory_stok_tas_hc WHERE barang_id=$b_id AND user_id=$u_id AND klinik_id=$klinik_id");
            } else {
                $conn->query("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by, updated_at)
                    VALUES ($b_id, $u_id, $klinik_id, $q_val, $user_id, NOW())
                    ON DUPLICATE KEY UPDATE qty = $q_val, updated_by = $user_id, updated_at = NOW()");
            }
        }
    } elseif ($has_hc_col && !empty($hc_pairs_in_payload)) {
        // Fallback: qty_aktual column absent — use qty_fisik but divide by multiplier (stored in from_uom)
        $pair_conds = implode(' OR ', array_map(fn($p) => "(d.barang_id={$p[0]} AND d.hc_user_id={$p[1]})", $hc_pairs_in_payload));
        $rHC = $conn->query("SELECT d.barang_id, d.hc_user_id,
                d.qty_fisik / COALESCE(NULLIF(uc.multiplier,0), 1) AS final_qty
            FROM inventory_stok_opname_detail d
            LEFT JOIN inventory_barang b ON b.id = d.barang_id
            LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
            WHERE d.opname_id = $opname_id AND d.tipe = 'hc'
              AND d.hc_user_id IS NOT NULL AND d.qty_fisik IS NOT NULL
              AND ($pair_conds)");
        while ($rHC && ($hrow = $rHC->fetch_assoc())) {
            $b_id  = (int)$hrow['barang_id'];
            $u_id  = (int)$hrow['hc_user_id'];
            $q_val = (float)$hrow['final_qty'];
            if ($q_val <= 0) {
                $conn->query("DELETE FROM inventory_stok_tas_hc WHERE barang_id=$b_id AND user_id=$u_id AND klinik_id=$klinik_id");
            } else {
                $conn->query("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty, updated_by, updated_at)
                    VALUES ($b_id, $u_id, $klinik_id, $q_val, $user_id, NOW())
                    ON DUPLICATE KEY UPDATE qty = $q_val, updated_by = $user_id, updated_at = NOW()");
            }
        }
    }

    $conn->commit();
    so_release_all_locks($conn, $locks_to_release);
    echo json_encode(['success' => true, 'opname_id' => $opname_id, 'detail_ids' => $detail_ids_out, 'message' => 'Stock opname berhasil disimpan dan stok tas HC telah diperbarui.']);
} catch (Exception $e) {
    $conn->rollback();
    so_release_all_locks($conn, $locks_to_release);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
