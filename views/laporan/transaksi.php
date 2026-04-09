<?php
check_role(['super_admin', 'admin_gudang']);

// Default Dates: First and Last day of current month
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$barang_id = isset($_GET['barang_id']) ? $_GET['barang_id'] : '';
$tipe = isset($_GET['tipe']) ? $_GET['tipe'] : '';

// Build Query
$sql = "SELECT t.*, b.kode_barang, b.nama_barang, u.username as user_name
        FROM inventory_transaksi_stok t
        JOIN inventory_barang b ON t.barang_id = b.id
        LEFT JOIN inventory_users u ON t.created_by = u.id
        WHERE DATE(t.created_at) BETWEEN '$start_date' AND '$end_date'";

if (!empty($barang_id)) {
    $sql .= " AND t.barang_id = $barang_id";
}
if (!empty($tipe)) {
    $sql .= " AND t.tipe_transaksi = '$tipe'";
}

$sql .= " ORDER BY t.created_at DESC LIMIT 500";

$result = $conn->query($sql);

// Fetch Barang for Filter
$barang_list = $conn->query("SELECT * FROM inventory_barang ORDER BY nama_barang ASC");
?>

<div class="container-fluid">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-history me-2"></i>Laporan Riwayat Transaksi
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Laporan Transaksi</li>
                </ol>
            </nav>
        </div>
    </div>

<!-- Filter Card -->
<div class="card mb-4 shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="laporan_transaksi">
            
            <div class="col-md-3">
                <label class="form-label">Tanggal Mulai</label>
                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tanggal Selesai</label>
                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Barang</label>
                <select name="barang_id" class="form-select select2-filter">
                    <option value="">- Semua Barang -</option>
                    <?php while($b = $barang_list->fetch_assoc()): ?>
                        <option value="<?= $b['id'] ?>" <?= $barang_id == $b['id'] ? 'selected' : '' ?>>
                            <?= $b['kode_barang'] ?> - <?= $b['nama_barang'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipe</label>
                <select name="tipe" class="form-select">
                    <option value="">- Semua -</option>
                    <option value="in" <?= $tipe == 'in' ? 'selected' : '' ?>>Masuk (In)</option>
                    <option value="out" <?= $tipe == 'out' ? 'selected' : '' ?>>Keluar (Out)</option>
                </select>
            </div>
            <div class="col-md-2">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i> Filter</button>
                    <button type="button" class="btn btn-success" onclick="exportExcel()" title="Download Excel"><i class="fas fa-file-excel"></i></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function exportExcel() {
    const form = document.querySelector('form[method="GET"]');
    const url = new URL('actions/export_transaksi_stok.php', window.location.href);
    url.searchParams.append('start_date', form.start_date.value);
    url.searchParams.append('end_date', form.end_date.value);
    url.searchParams.append('barang_id', form.barang_id.value);
    url.searchParams.append('tipe', form.tipe.value);
    window.open(url.toString(), '_blank');
}
</script>

<!-- Data Table -->
<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable" id="transTable">
                <thead>
                    <tr>
                        <th>Tanggal & Waktu</th>
                        <th>Barang</th>
                        <th>Tipe</th>
                        <th>Qty</th>
                        <th>Stok Awal</th>
                        <th>Stok Akhir</th>
                        <th>Level</th>
                        <th>Ref</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                        <td>
                            <span class="fw-bold"><?= $row['kode_barang'] ?></span><br>
                            <small><?= $row['nama_barang'] ?></small>
                        </td>
                        <td>
                            <?php if ($row['tipe_transaksi'] == 'in'): ?>
                                <span class="badge bg-success">IN <i class="fas fa-arrow-down"></i></span>
                            <?php else: ?>
                                <span class="badge bg-danger">OUT <i class="fas fa-arrow-up"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold fs-6 <?= $row['tipe_transaksi'] == 'in' ? 'text-success' : 'text-danger' ?>">
                            <?= $row['tipe_transaksi'] == 'in' ? '+' : '-' ?><?= number_format($row['qty']) ?>
                        </td>
                        <td><?= number_format($row['qty_sebelum']) ?></td>
                        <td><?= number_format($row['qty_sesudah']) ?></td>
                        <td>
                            <?= ucfirst(str_replace('_', ' ', $row['level'])) ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?= $row['referensi_tipe'] ?><br>
                                ID: <?= $row['referensi_id'] ?>
                            </small>
                        </td>
                        <td><?= $row['user_name'] ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2-filter').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
});
</script>

</div> <!-- End container-fluid -->
