<?php
require_once __DIR__ . '/../../config/settings.php';
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik']);

$user_role = $_SESSION['role'];
$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$can_filter_klinik = in_array($user_role, ['super_admin', 'admin_gudang']);

// Default Dates: First and Last day of current month
$start_date = isset($_GET['start_date']) ? (string)$_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? (string)$_GET['end_date'] : date('Y-m-t');
$barang_id = isset($_GET['barang_id']) ? (int)$_GET['barang_id'] : 0;
$tipe = isset($_GET['tipe']) ? trim((string)$_GET['tipe']) : '';
$selected_klinik = $can_filter_klinik ? (isset($_GET['klinik_id']) ? $_GET['klinik_id'] : '') : $user_klinik_id;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = date('Y-m-t');
if ($tipe !== '' && !in_array($tipe, ['in', 'out'], true)) $tipe = '';

// Build Query
$sql = "SELECT t.*, b.kode_barang, b.nama_barang, u.username as user_name,
        CASE 
            WHEN t.level = 'klinik' THEN k.nama_klinik
            WHEN t.level = 'hc' THEN COALESCE(CONCAT('HC: ', uhc.nama_lengkap, ' (', khc.nama_klinik, ')'), 'HC (Unknown)')
            ELSE 'Gudang Utama'
        END as unit_name
        FROM inventory_transaksi_stok t
        JOIN inventory_barang b ON t.barang_id = b.id
        LEFT JOIN inventory_users u ON t.created_by = u.id
        LEFT JOIN inventory_klinik k ON t.level = 'klinik' AND t.level_id = k.id
        LEFT JOIN inventory_users uhc ON t.level = 'hc' AND t.level_id = uhc.id
        LEFT JOIN inventory_klinik khc ON uhc.klinik_id = khc.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = "ss";

// Clinic Filter Logic
if ($selected_klinik && $selected_klinik !== 'all') {
    $kid = (int)$selected_klinik;
    $sql .= " AND (
        (t.level = 'klinik' AND t.level_id = ?) 
        OR 
        (t.level = 'hc' AND t.level_id IN (SELECT id FROM inventory_users WHERE klinik_id = ?))
    )";
    $params[] = $kid;
    $params[] = $kid;
    $types .= "ii";
}

if ($barang_id > 0) {
    $sql .= " AND t.barang_id = ?";
    $params[] = $barang_id;
    $types .= "i";
}
if (!empty($tipe)) {
    $sql .= " AND t.tipe_transaksi = ?";
    $params[] = $tipe;
    $types .= "s";
}

$sql .= " ORDER BY t.created_at DESC LIMIT 1000";

$stmt = $conn->prepare($sql);
$bind = [];
$bind[] = $types;
foreach ($params as $k => $v) $bind[] = &$params[$k];
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();

// Fetch Barang for Filter
$barang_list = $conn->query("SELECT * FROM inventory_barang ORDER BY nama_barang ASC");

// Fetch Clinics for dropdown
$cliniks = [];
if ($can_filter_klinik) {
    $res_k = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik");
    while ($row = $res_k->fetch_assoc()) $cliniks[] = $row;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    .trans-container {
        font-family: 'Poppins', sans-serif;
    }
    .text-primary-custom { color: #204EAB; }
    .table-custom thead th {
        background-color: #204EAB;
        color: #ffffff;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        padding: 0.85rem 1rem;
        border: none;
    }
    .table-custom tbody tr:hover {
        background-color: #f8fafc;
    }
    .table-custom td {
        font-size: 0.9rem;
        vertical-align: middle;
    }
    .badge-in { background-color: rgba(16, 185, 129, 0.1); color: #059669; font-weight: 700; border: 1px solid #059669; }
    .badge-out { background-color: rgba(239, 68, 68, 0.1); color: #dc2626; font-weight: 700; border: 1px solid #dc2626; }
</style>

<div class="container-fluid trans-container py-3">
    <div class="row mb-3 align-items-start">
        <div class="col">
            <h1 class="h3 mb-0 fw-bold text-primary-custom">
                <i class="fas fa-history me-2"></i>Riwayat Transaksi Stok
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Riwayat Transaksi</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="laporan_transaksi">
                
                <?php if ($can_filter_klinik): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Klinik</label>
                    <select name="klinik_id" class="form-select select2-filter">
                        <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Klinik</option>
                        <?php foreach ($cliniks as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_klinik']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Barang</label>
                    <select name="barang_id" class="form-select select2-filter">
                        <option value="">- Semua Barang -</option>
                        <?php while($b = $barang_list->fetch_assoc()): ?>
                            <option value="<?= $b['id'] ?>" <?= $barang_id == $b['id'] ? 'selected' : '' ?>>
                                <?= $b['kode_barang'] ?> - <?= $b['nama_barang'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold text-muted">Tipe</label>
                    <select name="tipe" class="form-select">
                        <option value="">Semua</option>
                        <option value="in" <?= $tipe == 'in' ? 'selected' : '' ?>>Masuk</option>
                        <option value="out" <?= $tipe == 'out' ? 'selected' : '' ?>>Keluar</option>
                    </select>
                </div>
                <div class="col-md-auto">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="fas fa-search me-1"></i> Filter</button>
                        <button type="button" class="btn btn-success" onclick="exportExcel()" title="Download Excel"><i class="fas fa-file-excel"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-custom datatable mb-0" id="transTable">
                    <thead>
                        <tr>
                            <th>TANGGAL & WAKTU</th>
                            <th>NAMA BARANG</th>
                            <th>UNIT / PETUGAS</th>
                            <th class="text-center">TIPE</th>
                            <th class="text-center">QTY</th>
                            <th class="text-center">STOK AWAL</th>
                            <th class="text-center">STOK AKHIR</th>
                            <th>REFERENSI</th>
                            <th>USER</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="small"><?= date('d/m/y H:i', strtotime($row['created_at'])) ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= $row['nama_barang'] ?></div>
                                <div class="small text-muted"><?= $row['kode_barang'] ?></div>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?= $row['unit_name'] ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= ucfirst(str_replace('_', ' ', $row['level'])) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($row['tipe_transaksi'] == 'in'): ?>
                                    <span class="badge badge-in px-2 py-1">IN</span>
                                <?php else: ?>
                                    <span class="badge badge-out px-2 py-1">OUT</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold <?= $row['tipe_transaksi'] == 'in' ? 'text-success' : 'text-danger' ?>">
                                <?= $row['tipe_transaksi'] == 'in' ? '+' : '-' ?><?= number_format($row['qty']) ?>
                            </td>
                            <td class="text-center text-muted"><?= number_format($row['qty_sebelum']) ?></td>
                            <td class="text-center fw-bold text-dark"><?= number_format($row['qty_sesudah']) ?></td>
                            <td>
                                <div class="small text-dark fw-semibold"><?= $row['referensi_tipe'] ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;">ID: <?= $row['referensi_id'] ?></div>
                            </td>
                            <td class="small"><?= $row['user_name'] ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
    if (form.klinik_id) url.searchParams.append('klinik_id', form.klinik_id.value);
    window.open(url.toString(), '_blank');
}

$(document).ready(function() {
    $('.select2-filter').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
});
</script>
