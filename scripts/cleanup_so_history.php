<?php
require_once __DIR__ . '/../config/database.php';

$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));

// SO entries yang terlanjur masuk history — identifikasi dari catatan default SO
$catatan_patterns = ["Stock Opname", "Alokasi Ulang"];
$where_clauses = array_map(fn($p) => "catatan LIKE '%" . $conn->real_escape_string($p) . "%'", $catatan_patterns);
$where = implode(' OR ', $where_clauses);

$execute = isset($_GET['execute']) && $_GET['execute'] === '1';
$execute = $execute || (isset($argv[1]) && $argv[1] === '--execute');

// Preview: hitung dan tampilkan data yang akan dihapus
$res = $conn->query("
    SELECT a.id, a.klinik_id, a.user_hc_id, a.barang_id, a.qty, a.catatan, a.created_at,
           k.nama_klinik, b.nama_barang, b.kode_barang,
           u.nama_lengkap AS nama_petugas
    FROM inventory_hc_tas_allocation a
    LEFT JOIN inventory_klinik k ON k.id = a.klinik_id
    LEFT JOIN inventory_barang b ON b.id = a.barang_id
    LEFT JOIN inventory_users u ON u.id = a.user_hc_id
    WHERE ($where)
    ORDER BY a.created_at DESC
    LIMIT 500
");

$rows = [];
while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
$total = count($rows);

if ($is_cli) {
    echo "=== CLEANUP SO HISTORY ===\n";
    echo "Ditemukan $total entri SO yang terlanjur masuk History Transfer.\n\n";
    foreach ($rows as $r) {
        echo "[{$r['id']}] {$r['created_at']} | {$r['nama_klinik']} | {$r['kode_barang']} - {$r['nama_barang']} | qty: {$r['qty']} | petugas: {$r['nama_petugas']} | catatan: {$r['catatan']}\n";
    }
    if ($execute) {
        $conn->query("DELETE FROM inventory_hc_tas_allocation WHERE ($where)");
        $deleted = $conn->affected_rows;
        echo "\n✅ Dihapus: $deleted entri.\n";
    } else {
        echo "\nJalankan dengan --execute untuk hapus:\n";
        echo "  php scripts/cleanup_so_history.php --execute\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cleanup SO History</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; padding: 2rem; margin: 0; }
        .container { max-width: 960px; margin: 0 auto; }
        h1 { color: #204EAB; font-size: 1.4rem; margin-bottom: 0.25rem; }
        .subtitle { color: #64748b; font-size: 0.875rem; margin-bottom: 2rem; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; padding: 1.5rem; margin-bottom: 1.5rem; }
        .alert { padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
        .alert-danger  { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .badge-count { display: inline-block; background: #dc2626; color: #fff; padding: 2px 10px; border-radius: 20px; font-weight: 700; font-size: 0.875rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th { background: #204EAB; color: #fff; padding: 8px 10px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; }
        td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
        tr:hover td { background: #f8fafc; }
        .btn { display: inline-block; padding: 0.6rem 1.5rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; border: none; text-decoration: none; }
        .btn-danger  { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-secondary { background: #e2e8f0; color: #475569; }
        .btn-secondary:hover { background: #cbd5e1; }
        .actions { display: flex; gap: 1rem; align-items: center; }
        .text-muted { color: #94a3b8; }
        .fw-bold { font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧹 Cleanup SO History</h1>
    <p class="subtitle">Hapus entri SO (Alokasi Ulang) yang terlanjur masuk History Transfer</p>

    <?php if ($execute): ?>
        <?php
            $conn->query("DELETE FROM inventory_hc_tas_allocation WHERE ($where)");
            $deleted = $conn->affected_rows;
        ?>
        <div class="alert alert-success">
            ✅ Berhasil menghapus <strong><?= $deleted ?> entri</strong> SO dari History Transfer.
        </div>
        <a href="cleanup_so_history.php" class="btn btn-secondary">← Cek Ulang</a>

    <?php elseif ($total === 0): ?>
        <div class="alert alert-success">
            ✅ Tidak ada entri SO di History Transfer. Sudah bersih.
        </div>

    <?php else: ?>
        <div class="alert alert-warning">
            ⚠️ Ditemukan <span class="badge-count"><?= $total ?></span> entri SO yang terlanjur masuk History Transfer.
            Entri ini berasal dari fitur Alokasi Ulang (Stock Opname) sebelum diperbaiki.
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tanggal</th>
                            <th>Klinik</th>
                            <th>Petugas HC</th>
                            <th>Barang</th>
                            <th>Qty</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-muted"><?= (int)$r['id'] ?></td>
                            <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
                            <td><?= htmlspecialchars($r['nama_klinik'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['nama_petugas'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(($r['kode_barang'] ?? '') . ' - ' . ($r['nama_barang'] ?? '-')) ?></td>
                            <td class="fw-bold"><?= number_format((float)$r['qty'], 2) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="actions">
            <a href="cleanup_so_history.php?execute=1" class="btn btn-danger"
               onclick="return confirm('Yakin hapus <?= $total ?> entri ini dari History Transfer? Tindakan ini tidak bisa dibatalkan.')">
                🗑️ Hapus <?= $total ?> Entri SO dari History
            </a>
            <a href="../index.php?page=stok_petugas_hc" class="btn btn-secondary">Batal</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
