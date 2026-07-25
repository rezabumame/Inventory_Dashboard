<?php
/**
 * Perbaikan data akibat bug unique key lama di inventory_stok_tas_hc: (barang_id, user_id)
 * tanpa klinik_id. Akibatnya, saat nakes pindah klinik, alokasi stok tas yang seharusnya
 * masuk ke klinik baru malah numpuk (ON DUPLICATE KEY UPDATE) ke baris klinik lama.
 *
 * Script ini menelusuri log kejadian "stok masuk ke tas petugas" yang punya klinik_id
 * definitif (inventory_hc_petugas_transfer & inventory_hc_tas_allocation), lalu untuk
 * tiap kejadian yang klinik tujuannya TIDAK ADA baris di inventory_stok_tas_hc:
 *   - Ambil qty kejadian itu dari baris (barang_id, user_id) yang ada saat ini (klinik lama).
 *   - Pindahkan qty tsb ke baris baru (barang_id, user_id, klinik_id_tujuan).
 * Diproses berurutan berdasarkan created_at, sehingga aman untuk nakes yang pindah
 * klinik berkali-kali. Idempotent: baris yang sudah benar akan di-skip.
 *
 * Default: DRY RUN (tidak mengubah apa pun). Tambahkan --apply untuk benar-benar commit.
 * Opsional: --user=<user_id> untuk membatasi ke satu nakes saja (mis. testing).
 *
 * Keterbatasan yang diketahui:
 *   - Tidak menyentuh kemungkinan salah kurang (pemakaian_bhp / return) yang WHERE-nya
 *     ikut memfilter klinik_id lama sehingga bisa saja 0 baris ter-update (silent no-op).
 *     Butuh audit terpisah jika ditemukan indikasi itu.
 *   - Alokasi dari inventory_hc_mass_allocation_so tidak tercatat di tabel log manapun
 *     dengan klinik_id per-kejadian, sehingga tidak bisa dideteksi/diperbaiki otomatis oleh script ini.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        die("Akses ditolak. Hanya Super Admin yang dapat menjalankan script ini.\n");
    }
}

$apply = in_array('--apply', $argv ?? [], true);
$only_user = 0;
foreach (($argv ?? []) as $a) {
    if (preg_match('/^--user=(\d+)$/', $a, $m)) $only_user = (int)$m[1];
}

echo $apply ? "MODE: APPLY (perubahan akan disimpan)\n" : "MODE: DRY RUN (tidak ada perubahan disimpan, tambahkan --apply untuk eksekusi)\n";
if ($only_user > 0) echo "Filter: hanya user_id=$only_user\n";
echo str_repeat('-', 70) . "\n";

$where_user = $only_user > 0 ? "AND user_hc_id = $only_user" : "";

$events = [];
$r1 = $conn->query("SELECT id, klinik_id, user_hc_id, barang_id, qty, created_at, 'hc_petugas_transfer' AS src
    FROM inventory_hc_petugas_transfer WHERE qty > 0 $where_user ORDER BY created_at ASC, id ASC");
while ($r1 && ($row = $r1->fetch_assoc())) $events[] = $row;

$r2 = $conn->query("SELECT id, klinik_id, user_hc_id, barang_id, qty, created_at, 'hc_tas_allocation' AS src
    FROM inventory_hc_tas_allocation WHERE qty > 0 $where_user ORDER BY created_at ASC, id ASC");
while ($r2 && ($row = $r2->fetch_assoc())) $events[] = $row;

usort($events, function($a, $b) {
    return strcmp($a['created_at'] . '-' . $a['id'], $b['created_at'] . '-' . $b['id']);
});

echo "Total kejadian alokasi ke tas petugas yang diperiksa: " . count($events) . "\n\n";

$fixed = 0;
$flagged = 0;
$conn->begin_transaction();

try {
    foreach ($events as $ev) {
        $uid = (int)$ev['user_hc_id'];
        $bid = (int)$ev['barang_id'];
        $kid = (int)$ev['klinik_id'];
        $qty = (float)$ev['qty'];

        // Kejadian sudah punya baris yang benar di klinik tujuan? skip.
        $chk = $conn->query("SELECT id FROM inventory_stok_tas_hc WHERE user_id=$uid AND barang_id=$bid AND klinik_id=$kid LIMIT 1");
        if ($chk && $chk->num_rows > 0) continue;

        // Cari baris "salah klinik" untuk pasangan (barang_id, user_id) ini.
        $wrong = $conn->query("SELECT * FROM inventory_stok_tas_hc WHERE user_id=$uid AND barang_id=$bid");
        $wrong_rows = [];
        while ($wrong && ($w = $wrong->fetch_assoc())) $wrong_rows[] = $w;

        $label = "[{$ev['src']} #{$ev['id']}] user=$uid barang=$bid -> klinik=$kid qty=$qty ({$ev['created_at']})";

        if (count($wrong_rows) === 0) {
            echo "CREATE  $label (tidak ada baris lama, buat baru)\n";
            if ($apply) {
                $stmt = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiid", $bid, $uid, $kid, $qty);
                $stmt->execute();
            }
            $fixed++;
            continue;
        }

        if (count($wrong_rows) > 1) {
            echo "SKIP    $label (ambigu: ada " . count($wrong_rows) . " baris untuk pasangan ini, perlu review manual)\n";
            $flagged++;
            continue;
        }

        $src_row = $wrong_rows[0];
        $src_klinik = (int)$src_row['klinik_id'];
        $src_qty = (float)$src_row['qty'];

        if ($src_qty < $qty) {
            echo "SKIP    $label (baris klinik=$src_klinik cuma qty=$src_qty, kurang dari $qty, perlu review manual)\n";
            $flagged++;
            continue;
        }

        echo "MOVE    $label (dari klinik=$src_klinik, sisa di sana akan jadi " . ($src_qty - $qty) . ")\n";
        if ($apply) {
            $stmt_dec = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty - ? WHERE id = ?");
            $sid = (int)$src_row['id'];
            $stmt_dec->bind_param("di", $qty, $sid);
            $stmt_dec->execute();

            $stmt_ins = $conn->prepare("INSERT INTO inventory_stok_tas_hc (barang_id, user_id, klinik_id, qty) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)");
            $stmt_ins->bind_param("iiid", $bid, $uid, $kid, $qty);
            $stmt_ins->execute();
        }
        $fixed++;
    }

    if ($apply) {
        $conn->commit();
        echo "\nBERHASIL DI-COMMIT. $fixed baris diperbaiki, $flagged perlu review manual.\n";
    } else {
        $conn->rollback();
        echo "\nDRY RUN selesai (tidak ada yang disimpan). $fixed akan diperbaiki, $flagged akan perlu review manual.\n";
        echo "Jalankan ulang dengan --apply untuk eksekusi sungguhan.\n";
    }
} catch (Throwable $e) {
    $conn->rollback();
    echo "\nERROR: " . $e->getMessage() . "\n";
}
