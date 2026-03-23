<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_POST['barang_id']) || !isset($_POST['klinik_id'])) {
    die("Invalid request");
}

$barang_id = (int)$_POST['barang_id'];
$klinik_id = (int)$_POST['klinik_id'];
$type = $_POST['type'] ?? '';

$where_type = "";
if ($type === 'klinik' || $type === 'hc') {
    $where_type = " AND pb.jenis_pemakaian = '$type'";
}

$query = "
    SELECT 
        pb.tanggal, 
        pb.nomor_pemakaian, 
        pb.jenis_pemakaian,
        pbd.qty,
        u_created.nama_lengkap as created_by_name
    FROM pemakaian_bhp pb
    JOIN pemakaian_bhp_detail pbd ON pb.id = pbd.pemakaian_bhp_id
    LEFT JOIN users u_created ON pb.created_by = u_created.id
    WHERE pbd.barang_id = $barang_id AND pb.klinik_id = $klinik_id $where_type
    ORDER BY pb.tanggal DESC, pb.created_at DESC
";

$result = $conn->query($query);
?>

<div class="table-responsive">
    <table class="table table-sm table-hover table-striped">
        <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>No. Pemakaian</th>
                <th>Jenis</th>
                <th>HC / PIC</th>
                <th class="text-end">Qty</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows == 0): ?>
                <tr><td colspan="5" class="text-center py-3">Tidak ada data pemakaian.</td></tr>
            <?php else: ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                    <td><small><?= htmlspecialchars($row['nomor_pemakaian']) ?></small></td>
                    <td>
                        <?php if ($row['jenis_pemakaian'] === 'klinik'): ?>
                            <span class="badge bg-info" style="font-size: 0.7rem;">Klinik</span>
                        <?php else: ?>
                            <span class="badge bg-warning" style="font-size: 0.7rem;">HC</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['jenis_pemakaian'] === 'hc' ? 'HC' : ($row['created_by_name'] ?? '-')) ?></td>
                    <td class="text-end fw-bold"><?= $row['qty'] ?></td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
