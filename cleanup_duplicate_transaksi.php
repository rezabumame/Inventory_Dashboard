<?php
/**
 * Cleanup: Duplicate inventory_transaksi_stok dari approval flow bug
 *
 * Deteksi yang benar:
 * - BHP dianggap terdampak HANYA jika barang_id yang SAMA muncul lebih dari sekali
 *   dengan created_at BERBEDA (bukan sekadar beda timestamp antar item)
 * - Edit yang menambah item baru (beda barang_id) TIDAK dianggap duplikat
 *
 * Yang dihapus: transaksi LAMA (timestamp lebih awal) untuk barang yang duplikat saja
 * Yang dipertahankan: semua transaksi di batch BARU
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    die('Akses ditolak. Hanya super_admin.');
}

header('Content-Type: text/html; charset=utf-8');

/**
 * Cari semua pemakaian_bhp yang punya barang_id SAMA dengan created_at BERBEDA.
 * Ini adalah true duplicate dari approval flow bug.
 */
function get_affected_bhps(mysqli $conn): array {
    // Step 1: Cari pemakaian_id yang punya barang duplikat (barang sama, timestamp beda)
    $sql_dup_pids = "
        SELECT DISTINCT ts.referensi_id AS pemakaian_id
        FROM inventory_transaksi_stok ts
        WHERE ts.referensi_tipe = 'pemakaian_bhp'
        GROUP BY ts.referensi_id, ts.barang_id
        HAVING COUNT(DISTINCT ts.created_at) > 1
    ";
    $res_pids = $conn->query($sql_dup_pids);
    $pids = [];
    while ($r = $res_pids->fetch_assoc()) $pids[] = (int)$r['pemakaian_id'];

    if (empty($pids)) return [];

    $ids_str = implode(',', $pids);

    // Step 2: Ambil header info untuk pemakaian_bhp yang terdampak
    $sql = "
        SELECT
            pb.id AS pemakaian_id,
            pb.nomor_pemakaian,
            pb.tanggal,
            pb.jenis_pemakaian,
            pb.klinik_id,
            pb.user_hc_id,
            pb.spv_approved_at,
            k.nama_klinik,
            u.nama_lengkap AS nama_petugas_hc
        FROM inventory_pemakaian_bhp pb
        LEFT JOIN inventory_klinik k ON k.id = pb.klinik_id
        LEFT JOIN inventory_users u ON u.id = pb.user_hc_id
        WHERE pb.id IN ($ids_str)
          AND pb.status = 'active'
        ORDER BY pb.tanggal DESC, pb.nomor_pemakaian
    ";
    $res = $conn->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

/**
 * Untuk satu pemakaian_bhp, cari:
 * - barang_id mana yang duplikat (muncul di 2+ timestamp berbeda)
 * - transaksi mana yang LAMA (harus dihapus) vs BARU (dipertahankan)
 * - transaksi yang bukan duplikat (aman, tidak disentuh)
 */
function get_ts_for_bhp(mysqli $conn, int $pid): array {
    $sql = "
        SELECT ts.id AS ts_id, ts.barang_id, b.kode_barang, b.nama_barang,
               ts.tipe_transaksi, ts.qty, ts.created_at
        FROM inventory_transaksi_stok ts
        JOIN inventory_barang b ON b.id = ts.barang_id
        WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.referensi_id = $pid
        ORDER BY ts.created_at ASC, ts.id ASC
    ";
    $res = $conn->query($sql);
    $all = [];
    while ($t = $res->fetch_assoc()) $all[] = $t;

    // Kelompokkan per barang_id, cari yang punya > 1 timestamp berbeda
    $by_barang = [];
    foreach ($all as $t) {
        $bid = (int)$t['barang_id'];
        if (!isset($by_barang[$bid])) $by_barang[$bid] = [];
        $by_barang[$bid][] = $t;
    }

    $to_delete  = []; // transaksi lama dari barang duplikat
    $to_keep    = []; // transaksi baru dari barang duplikat
    $non_dup    = []; // transaksi barang yang tidak duplikat (tidak disentuh)

    foreach ($by_barang as $bid => $ts_list) {
        $timestamps = array_unique(array_column($ts_list, 'created_at'));
        if (count($timestamps) <= 1) {
            // Bukan duplikat — semua transaksi untuk barang ini aman
            foreach ($ts_list as $t) $non_dup[] = $t;
            continue;
        }

        // Duplikat: barang sama, timestamp berbeda
        // Batch paling awal = LAMA (hapus), sisanya = BARU (pertahankan)
        sort($timestamps);
        $ts_lama = $timestamps[0];

        foreach ($ts_list as $t) {
            if ($t['created_at'] === $ts_lama) $to_delete[] = $t;
            else $to_keep[] = $t;
        }
    }

    return [
        'to_delete' => $to_delete,
        'to_keep'   => $to_keep,
        'non_dup'   => $non_dup,
    ];
}

// ── POST: eksekusi cleanup untuk BHP yang dicentang ──────────────────────────
$result_log = [];
$result_ok  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute'])) {
    require_csrf();

    $checked_ids = array_map('intval', (array)($_POST['selected_pids'] ?? []));

    if (empty($checked_ids)) {
        $result_ok = false;
        $result_log[] = 'Tidak ada BHP yang dipilih.';
    } else {
        $affected = get_affected_bhps($conn);
        $conn->begin_transaction();
        try {
            $deleted = 0;
            foreach ($affected as $bhp) {
                $pid = (int)$bhp['pemakaian_id'];
                if (!in_array($pid, $checked_ids, true)) continue;

                $kid    = (int)$bhp['klinik_id'];
                $jenis  = $bhp['jenis_pemakaian'];
                $hc_uid = (int)$bhp['user_hc_id'];
                $detail = get_ts_for_bhp($conn, $pid);

                foreach ($detail['to_delete'] as $t) {
                    $ts_id = (int)$t['ts_id'];
                    $bid   = (int)$t['barang_id'];
                    $qty   = (float)$t['qty'];

                    // Reverse stok: 'out' = kembalikan qty, 'in' = kurangi kembali qty
                    $tipe = $t['tipe_transaksi'];
                    if ($tipe === 'out' || $tipe === 'in') {
                        $delta = ($tipe === 'out') ? $qty : -$qty; // out → +qty, in → -qty
                        if ($jenis === 'hc' && $hc_uid > 0) {
                            $stmt = $conn->prepare("UPDATE inventory_stok_tas_hc SET qty = qty + ?, updated_at = NOW() WHERE barang_id = ? AND user_id = ? AND klinik_id = ?");
                            $stmt->bind_param("diii", $delta, $bid, $hc_uid, $kid);
                            $stmt->execute();
                            $result_log[] = "✅ Reverse {$tipe} {$qty} → stok HC barang {$t['kode_barang']} ({$bhp['nomor_pemakaian']})";
                        } else {
                            $stmt = $conn->prepare("UPDATE inventory_stok_gudang_klinik SET qty = qty + ?, updated_at = NOW() WHERE barang_id = ? AND klinik_id = ?");
                            $stmt->bind_param("dii", $delta, $bid, $kid);
                            $stmt->execute();
                            $result_log[] = "✅ Reverse {$tipe} {$qty} → stok Klinik barang {$t['kode_barang']} ({$bhp['nomor_pemakaian']})";
                        }
                    }

                    $stmt_del = $conn->prepare("DELETE FROM inventory_transaksi_stok WHERE id = ?");
                    $stmt_del->bind_param("i", $ts_id);
                    $stmt_del->execute();
                    $result_log[] = "🗑️ Hapus ts_id={$ts_id} ({$bhp['nomor_pemakaian']} — {$t['kode_barang']})";
                    $deleted++;
                }
            }
            $conn->commit();
            $result_ok = true;
            $result_log[] = "--- Selesai: {$deleted} transaksi dihapus ---";
        } catch (Exception $e) {
            $conn->rollback();
            $result_ok = false;
            $result_log[] = 'ERROR: ' . $e->getMessage();
        }
    }
}

// ── Ambil data untuk tampilan ─────────────────────────────────────────────────
$affected_bhps = get_affected_bhps($conn);
$bhp_details   = [];
$total_to_del  = 0;
foreach ($affected_bhps as $bhp) {
    $pid = (int)$bhp['pemakaian_id'];
    $d   = get_ts_for_bhp($conn, $pid);
    $bhp_details[$pid] = ['bhp' => $bhp, 'detail' => $d];
    $total_to_del += count($d['to_delete']);
}

?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cleanup Duplicate Transaksi</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: monospace; padding: 24px; background: #f8f9fa; font-size: 13px; max-width: 1400px; margin: 0 auto; }
  h2 { color: #dc3545; margin-bottom: 4px; }
  .subtitle { color: #6c757d; margin-bottom: 20px; font-size: 12px; }
  .banner { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
  .warn  { background: #fff3cd; border: 2px solid #ffc107; }
  .success { background: #d1e7dd; border: 2px solid #a3cfbb; }
  .danger  { background: #f8d7da; border: 2px solid #f5c6cb; }
  .summary { background: #fff; border: 1px solid #dee2e6; padding: 12px 18px; border-radius: 6px; margin-bottom: 16px; display: flex; gap: 30px; align-items: center; }
  .stat { text-align: center; }
  .stat strong { display: block; font-size: 22px; color: #dc3545; }
  .stat span { font-size: 11px; color: #6c757d; }
  .actions-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
  .btn { padding: 8px 18px; border-radius: 5px; border: none; cursor: pointer; font-size: 13px; font-weight: bold; }
  .btn-danger { background: #dc3545; color: #fff; }
  .btn-danger:hover { background: #bb2d3b; }
  .btn-secondary { background: #6c757d; color: #fff; }
  .btn-secondary:hover { background: #565e64; }
  .btn-sm { padding: 4px 10px; font-size: 11px; font-weight: normal; }
  .bhp-block { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 14px; overflow: hidden; }
  .bhp-block.selected { border-color: #dc3545; box-shadow: 0 0 0 2px rgba(220,53,69,.15); }
  .bhp-header { padding: 10px 14px; display: flex; align-items: center; gap: 12px; cursor: pointer; user-select: none; }
  .bhp-header:hover { background: #f8f9fa; }
  .bhp-header input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
  .bhp-header .nomor { font-weight: bold; font-size: 14px; flex: 1; }
  .bhp-header .meta { font-size: 11px; color: #6c757d; }
  .bhp-body { display: none; border-top: 1px solid #dee2e6; }
  .bhp-body.open { display: block; }
  .sub-label { padding: 6px 14px; font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
  .sub-del  { background: #fff5f5; color: #dc3545; border-bottom: 1px solid #f5c6cb; }
  .sub-keep { background: #f0fff4; color: #198754; border-bottom: 1px solid #c3e6cb; }
  .sub-safe { background: #f8f9fa; color: #6c757d; border-bottom: 1px solid #dee2e6; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #495057; color: #fff; padding: 5px 12px; text-align: left; font-size: 11px; font-weight: normal; }
  td { padding: 5px 12px; border-bottom: 1px solid #f0f0f0; font-size: 12px; }
  .del  { color: #dc3545; }
  .keep { color: #198754; }
  .muted { color: #adb5bd; }
  .ok   { color: #198754; font-weight: bold; }
  .result-box { background: #212529; color: #adb5bd; padding: 14px; border-radius: 6px; line-height: 1.7; margin-bottom: 20px; white-space: pre; }
  .selected-count { font-size: 13px; color: #dc3545; font-weight: bold; }
  .empty { color: #198754; font-size: 15px; font-weight: bold; padding: 20px 0; }
  .badge-dup { background: #f8d7da; color: #dc3545; padding: 1px 6px; border-radius: 10px; font-size: 10px; }
</style>
</head>
<body>

<h2>Cleanup: Duplicate Transaksi Stok — Approval Flow Bug</h2>
<p class="subtitle">
  Deteksi: barang_id yang SAMA muncul 2x dengan timestamp berbeda dalam satu BHP.<br>
  Edit yang menambah item baru (beda barang) tidak dianggap duplikat dan tidak ditampilkan.
</p>

<?php if ($result_ok === true): ?>
<div class="banner success">✅ <strong>Cleanup berhasil!</strong></div>
<div class="result-box"><?= htmlspecialchars(implode("\n", $result_log)) ?></div>
<p style="color:red"><strong>⚠️ Segera hapus file ini dari server setelah selesai.</strong></p>
<?php elseif ($result_ok === false): ?>
<div class="banner danger">❌ <strong>Gagal atau tidak ada yang dipilih.</strong></div>
<div class="result-box"><?= htmlspecialchars(implode("\n", $result_log)) ?></div>
<?php endif; ?>

<div class="banner warn">
  ⚠️ <strong>Hati-hati.</strong> Expand tiap BHP untuk lihat detail sebelum centang.
  Hanya barang yang muncul di KEDUA batch (merah + hijau) yang akan dihapus.
  Item non-duplikat (abu-abu) tidak disentuh.
</div>

<?php if (empty($affected_bhps)): ?>
<p class="empty">✅ Tidak ditemukan duplicate transaksi. Database bersih.</p>
<?php else: ?>

<div class="summary">
  <div class="stat"><strong><?= count($affected_bhps) ?></strong><span>BHP Terdampak</span></div>
  <div class="stat"><strong><?= $total_to_del ?></strong><span>Transaksi akan dihapus</span></div>
  <div style="flex:1; font-size:12px; color:#6c757d;">
    Hanya BHP yang punya <strong>barang sama</strong> dengan timestamp berbeda yang muncul di sini.<br>
    Edit yang tambah item baru sudah difilter keluar secara otomatis.
  </div>
</div>

<form method="POST">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="actions-bar">
    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(true)">Centang Semua</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(false)">Hapus Centang</button>
    <span class="selected-count" id="selectedCount">0 BHP dipilih</span>
    <button type="submit" name="execute" value="1" class="btn btn-danger"
      onclick="return confirm('Yakin ingin menghapus transaksi LAMA untuk BHP yang dipilih?\nTindakan ini tidak bisa dibatalkan.')">
      🗑️ Jalankan Cleanup untuk yang Dicentang
    </button>
  </div>

  <?php foreach ($bhp_details as $pid => $d):
      $bhp    = $d['bhp'];
      $to_del = $d['detail']['to_delete'];
      $to_kp  = $d['detail']['to_keep'];
      $non    = $d['detail']['non_dup'];
  ?>
  <div class="bhp-block" id="block-<?= $pid ?>">
    <div class="bhp-header" onclick="toggleBody(<?= $pid ?>)">
      <input type="checkbox" name="selected_pids[]" value="<?= $pid ?>"
             onclick="event.stopPropagation(); updateCount(); toggleSelected(<?= $pid ?>, this.checked)"
             id="chk-<?= $pid ?>">
      <label for="chk-<?= $pid ?>" class="nomor" onclick="event.stopPropagation()">
        <?= htmlspecialchars($bhp['nomor_pemakaian']) ?>
      </label>
      <span class="meta">
        <?= htmlspecialchars($bhp['tanggal']) ?> |
        <?= htmlspecialchars($bhp['nama_klinik'] ?? 'klinik_id=' . $bhp['klinik_id']) ?> |
        <?= strtoupper(htmlspecialchars($bhp['jenis_pemakaian'])) ?>
        <?php if ($bhp['jenis_pemakaian'] === 'hc'): ?>
          | <?= htmlspecialchars($bhp['nama_petugas_hc'] ?? '-') ?>
        <?php endif; ?> |
        <span class="badge-dup"><?= count($to_del) ?> duplikat</span>
        <?php if (!empty($non)): ?>
          &nbsp;<span style="font-size:10px;color:#adb5bd;"><?= count($non) ?> item non-duplikat aman</span>
        <?php endif; ?>
      </span>
    </div>

    <div class="bhp-body" id="body-<?= $pid ?>">

      <div class="sub-label sub-del">🗑️ DIHAPUS — Transaksi LAMA (barang duplikat, <?= count($to_del) ?> entri)</div>
      <table>
        <tr><th>ts_id</th><th>Kode</th><th>Nama Barang</th><th>Tipe</th><th>Qty</th><th>Dibuat</th></tr>
        <?php foreach ($to_del as $t): ?>
        <tr>
          <td class="del"><?= (int)$t['ts_id'] ?></td>
          <td><?= htmlspecialchars($t['kode_barang']) ?></td>
          <td><?= htmlspecialchars($t['nama_barang']) ?></td>
          <td><?= htmlspecialchars($t['tipe_transaksi']) ?></td>
          <td class="del"><?= (float)$t['qty'] ?></td>
          <td><?= htmlspecialchars($t['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>

      <div class="sub-label sub-keep">✅ DIPERTAHANKAN — Transaksi BARU (<?= count($to_kp) ?> entri)</div>
      <table>
        <tr><th>ts_id</th><th>Kode</th><th>Nama Barang</th><th>Tipe</th><th>Qty</th><th>Dibuat</th></tr>
        <?php foreach ($to_kp as $t): ?>
        <tr>
          <td class="keep"><?= (int)$t['ts_id'] ?></td>
          <td><?= htmlspecialchars($t['kode_barang']) ?></td>
          <td><?= htmlspecialchars($t['nama_barang']) ?></td>
          <td><?= htmlspecialchars($t['tipe_transaksi']) ?></td>
          <td class="keep"><?= (float)$t['qty'] ?></td>
          <td><?= htmlspecialchars($t['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>

      <?php if (!empty($non)): ?>
      <div class="sub-label sub-safe">⬜ TIDAK DISENTUH — Item non-duplikat (<?= count($non) ?> entri)</div>
      <table>
        <tr><th>ts_id</th><th>Kode</th><th>Nama Barang</th><th>Tipe</th><th>Qty</th><th>Dibuat</th></tr>
        <?php foreach ($non as $t): ?>
        <tr>
          <td class="muted"><?= (int)$t['ts_id'] ?></td>
          <td><?= htmlspecialchars($t['kode_barang']) ?></td>
          <td><?= htmlspecialchars($t['nama_barang']) ?></td>
          <td><?= htmlspecialchars($t['tipe_transaksi']) ?></td>
          <td><?= (float)$t['qty'] ?></td>
          <td><?= htmlspecialchars($t['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>

  <div class="actions-bar" style="margin-top:10px;">
    <span class="selected-count" id="selectedCount2">0 BHP dipilih</span>
    <button type="submit" name="execute" value="1" class="btn btn-danger"
      onclick="return confirm('Yakin ingin menghapus transaksi LAMA untuk BHP yang dipilih?')">
      🗑️ Jalankan Cleanup untuk yang Dicentang
    </button>
  </div>
</form>

<?php endif; ?>

<script>
function toggleBody(pid) {
    document.getElementById('body-' + pid).classList.toggle('open');
}
function toggleSelected(pid, checked) {
    const block = document.getElementById('block-' + pid);
    if (checked) block.classList.add('selected');
    else block.classList.remove('selected');
}
function updateCount() {
    const n = document.querySelectorAll('input[name="selected_pids[]"]:checked').length;
    document.getElementById('selectedCount').textContent = n + ' BHP dipilih';
    document.getElementById('selectedCount2').textContent = n + ' BHP dipilih';
}
function toggleAll(state) {
    document.querySelectorAll('input[name="selected_pids[]"]').forEach(cb => {
        cb.checked = state;
        toggleSelected(cb.value, state);
    });
    updateCount();
}
</script>
</body>
</html>
