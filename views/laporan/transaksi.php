<?php
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../lib/stock.php';
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
            WHEN t.level = 'klinik' THEN COALESCE(k.nama_klinik, 'Unit Tidak Terdeteksi')
            WHEN t.level = 'hc' THEN 
                CASE 
                    WHEN uhc.id IS NOT NULL THEN CONCAT('HC: ', uhc.nama_lengkap, ' (', COALESCE(khc.nama_klinik, 'Klinik Unknown'), ')')
                    WHEN khc_direct.id IS NOT NULL THEN CONCAT('HC: General (', khc_direct.nama_klinik, ')')
                    ELSE CONCAT('HC (ID: ', t.level_id, ')')
                END
            ELSE 'Gudang Utama'
        END as unit_name
        FROM inventory_transaksi_stok t
        JOIN inventory_barang b ON t.barang_id = b.id
        LEFT JOIN inventory_users u ON t.created_by = u.id
        LEFT JOIN inventory_klinik k ON t.level = 'klinik' AND t.level_id = k.id
        LEFT JOIN inventory_users uhc ON t.level = 'hc' AND t.level_id = uhc.id
        LEFT JOIN inventory_klinik khc ON uhc.klinik_id = khc.id
        LEFT JOIN inventory_klinik khc_direct ON t.level = 'hc' AND t.level_id = khc_direct.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = "ss";

// Clinic Filter Logic
if ($selected_klinik && $selected_klinik !== 'all') {
    $kid = (int)$selected_klinik;
    $sql .= " AND (
        (t.level = 'klinik' AND t.level_id = ?) 
        OR 
        (t.level = 'hc' AND (t.level_id IN (SELECT id FROM inventory_users WHERE klinik_id = ?) OR t.level_id = ?))
    )";
    $params[] = $kid;
    $params[] = $kid;
    $params[] = $kid;
    $types .= "iii";
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

$sql .= " ORDER BY t.id DESC LIMIT 1000";

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

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    :root {
        --bumame-blue: #204EAB;
        --bumame-blue-soft: rgba(32, 78, 171, 0.08);
        --slate-50: #f8fafc;
        --slate-100: #f1f5f9;
        --slate-200: #e2e8f0;
        --slate-600: #475569;
        --slate-900: #0f172a;
        --success-soft: rgba(16, 185, 129, 0.1);
        --danger-soft: rgba(239, 68, 68, 0.1);
    }

    .trans-container {
        font-family: 'Outfit', sans-serif;
        background-color: var(--slate-50);
        min-height: 100vh;
    }

    .page-header {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: 16px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        margin-bottom: 2rem;
        border-left: 6px solid var(--bumame-blue);
    }

    .filter-card {
        background: white;
        border-radius: 16px;
        border: 1px solid var(--slate-200);
        transition: all 0.3s ease;
    }

    .filter-card:hover {
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
    }

    .table-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--slate-200);
    }

    .table-custom thead th {
        background-color: var(--slate-50);
        color: var(--slate-600);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.05em;
        padding: 1.2rem 1rem;
        border-bottom: 2px solid var(--slate-100);
    }

    .table-custom tbody tr {
        transition: all 0.2s ease;
    }

    .table-custom tbody tr:hover {
        background-color: var(--bumame-blue-soft);
        transform: scale(1.002);
    }

    .table-custom td {
        padding: 1rem;
        border-bottom: 1px solid var(--slate-100);
        vertical-align: middle;
        color: var(--slate-900);
    }

    /* Modern Badges */
    .badge-modern {
        padding: 6px 12px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .badge-in { 
        background-color: var(--success-soft); 
        color: #059669; 
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
    
    .badge-out { 
        background-color: var(--danger-soft); 
        color: #dc2626; 
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .qty-text {
        font-family: 'Monaco', 'Consolas', monospace;
        font-size: 1rem;
    }

    .unit-pill {
        background: var(--slate-100);
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75rem;
        color: var(--slate-600);
        display: inline-block;
    }

    /* Custom Form Controls */
    .form-control, .form-select {
        border-radius: 10px;
        border: 1px solid var(--slate-200);
        padding: 0.6rem 1rem;
        font-size: 0.9rem;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--bumame-blue);
        box-shadow: 0 0 0 4px var(--bumame-blue-soft);
    }

    .btn-primary {
        background-color: var(--bumame-blue);
        border: none;
        border-radius: 10px;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-primary:hover {
        background-color: #1a3e8a;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(32, 78, 171, 0.3);
    }

    .btn-excel {
        background-color: #10B981;
        color: white;
        border-radius: 10px;
        padding: 0.6rem 1rem;
        border: none;
    }

    /* Font Weight Utilities */
    .fw-500 { font-weight: 500; }
    .fw-600 { font-weight: 600; }
    .fw-700 { font-weight: 700; }
    .fw-800 { font-weight: 800; }
</style>

<div class="container-fluid trans-container py-4">
    <!-- Header Section -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h3 mb-1 fw-800" style="color: var(--bumame-blue); letter-spacing: -0.02em;">
                Riwayat Transaksi Stok
            </h1>
            <p class="text-muted mb-0 small fw-500">Monitor arus masuk dan keluar barang secara real-time</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-excel shadow-sm" onclick="exportExcel()">
                <i class="fas fa-file-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-card p-4 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="laporan_transaksi">
            
            <?php if ($can_filter_klinik): ?>
            <div class="col-md-3">
                <label class="form-label small fw-700 text-uppercase text-muted" style="font-size: 0.65rem;">Unit Klinik</label>
                <select name="klinik_id" class="form-select select2-filter">
                    <option value="all" <?= $selected_klinik === 'all' ? 'selected' : '' ?>>Semua Unit</option>
                    <?php foreach ($cliniks as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_klinik']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2">
                <label class="form-label small fw-700 text-uppercase text-muted" style="font-size: 0.65rem;">Periode Mulai</label>
                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-700 text-uppercase text-muted" style="font-size: 0.65rem;">Periode Selesai</label>
                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-700 text-uppercase text-muted" style="font-size: 0.65rem;">Pencarian Barang</label>
                <select name="barang_id" class="form-select select2-filter">
                    <option value="">Semua Barang</option>
                    <?php while($b = $barang_list->fetch_assoc()): ?>
                        <option value="<?= $b['id'] ?>" <?= $barang_id == $b['id'] ? 'selected' : '' ?>>
                            <?= $b['kode_barang'] ?> - <?= $b['nama_barang'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-700 text-uppercase text-muted" style="font-size: 0.65rem;">Tipe</label>
                <select name="tipe" class="form-select">
                    <option value="">Semua</option>
                    <option value="in" <?= $tipe == 'in' ? 'selected' : '' ?>>IN</option>
                    <option value="out" <?= $tipe == 'out' ? 'selected' : '' ?>>OUT</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Table Section -->
    <div class="table-card shadow-sm">
        <div class="table-responsive">
            <table class="table table-custom datatable mb-0" id="transTable">
                <thead>
                    <tr>
                        <th>TANGGAL / WAKTU</th>
                        <th>INFORMASI BARANG</th>
                        <th>UNIT & LEVEL</th>
                        <th class="text-center">TIPE</th>
                        <th class="text-center">QTY</th>
                        <th class="text-center">STOK AWAL</th>
                        <th class="text-center">STOK AKHIR</th>
                        <th>REF / USER</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="fw-600 mb-0"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            <div class="text-muted small"><?= date('H:i', strtotime($row['created_at'])) ?> WIB</div>
                        </td>
                        <td>
                            <div class="fw-700 text-dark" style="font-size: 0.95rem;"><?= $row['nama_barang'] ?></div>
                            <div class="text-muted small fw-500"><?= $row['kode_barang'] ?></div>
                        </td>
                        <td>
                            <div class="fw-600 text-dark"><?= $row['unit_name'] ?></div>
                            <span class="unit-pill"><?= strtoupper(str_replace('_', ' ', $row['level'])) ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($row['tipe_transaksi'] == 'in'): ?>
                                <span class="badge-modern badge-in">
                                    <i class="fas fa-arrow-down small"></i> IN
                                </span>
                            <?php else: ?>
                                <span class="badge-modern badge-out">
                                    <i class="fas fa-arrow-up small"></i> OUT
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="qty-text fw-800 <?= $row['tipe_transaksi'] == 'in' ? 'text-success' : 'text-danger' ?>">
                                <?= $row['tipe_transaksi'] == 'in' ? '+' : '-' ?><?= fmt_qty(abs($row['qty'])) ?>
                            </span>
                        </td>
                        <td class="text-center text-muted small fw-500">
                            <?= fmt_qty($row['qty_sebelum']) ?>
                        </td>
                        <td class="text-center">
                            <div class="qty-text fw-800 text-dark"><?= fmt_qty($row['qty_sesudah']) ?></div>
                        </td>
                        <td>
                            <div class="fw-600 small"><?= $row['referensi_tipe'] ?> #<?= $row['referensi_id'] ?></div>
                            <div class="text-muted" style="font-size: 0.7rem;"><i class="fas fa-user-circle me-1"></i><?= $row['user_name'] ?></div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
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

    if ($.fn.DataTable.isDataTable('#transTable')) {
        $('#transTable').DataTable().destroy();
    }
    
    $('#transTable').DataTable({
        "order": [[0, "desc"]], // Sort by first column (Date & Time) descending
        "pageLength": 10,
        "columnDefs": [
            { "orderable": true, "targets": 0 },
            { "orderable": true, "targets": 1 }
        ]
    });
});
</script>
