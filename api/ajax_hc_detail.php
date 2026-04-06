<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

// Check role
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'cs', 'petugas_hc'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}

// Get parameters
$barang_id = isset($_POST['barang_id']) ? (int)$_POST['barang_id'] : 0;
$klinik_id = isset($_POST['klinik_id']) ? (int)$_POST['klinik_id'] : 0;

if ($barang_id == 0 || $klinik_id == 0) {
    echo '<div class="alert alert-warning">Invalid parameters</div>';
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}
if ($role === 'petugas_hc' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
    echo '<div class="alert alert-danger">Access denied</div>';
    exit;
}

$b = $conn->query("
    SELECT b.id, b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan, COALESCE(uc.multiplier, 1) AS multiplier
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE b.id = $barang_id
    LIMIT 1
")->fetch_assoc();

$k = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$b || !$k) {
    echo '<div class="alert alert-warning">Data tidak ditemukan</div>';
    exit;
}

$petugas = [];
$res_p = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id ORDER BY nama_lengkap ASC");
while ($res_p && ($row = $res_p->fetch_assoc())) $petugas[] = $row;

function fmt_qty($v) {
    $n = (float)($v ?? 0);
    if (abs($n - round($n)) < 0.00005) return (string)(int)round($n);
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

$mult = (float)($b['multiplier'] ?? 1);
if ($mult <= 0) $mult = 1;

if (!empty($petugas)) {
    $kode_homecare = (string)($k['kode_homecare'] ?? '');
    if ($kode_homecare === '' || (string)($b['kode_barang'] ?? '') === '') {
        echo '<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Klinik ini belum memiliki Kode Homecare atau barang belum memiliki kode_barang.</div>';
        $conn->close();
        exit;
    }
    $loc = $conn->real_escape_string($kode_homecare);
    $kb = $conn->real_escape_string((string)$b['kode_barang']);
    $oid = $conn->real_escape_string((string)($b['odoo_product_id'] ?? ''));
    $clauses = [];
    if ($kb !== '') $clauses[] = "TRIM(kode_barang) = '$kb'";
    if ($oid !== '') $clauses[] = "TRIM(odoo_product_id) = '$oid'";
    if (empty($clauses)) $clauses[] = "1=0";
    $match = '(' . implode(' OR ', $clauses) . ')';
    $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc' AND $match");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    $q = $q / $mult;
    $res_u = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE TRIM(location_code) = '$loc'");
    $last_update = (string)($res_u && $res_u->num_rows > 0 ? ($res_u->fetch_assoc()['last_update'] ?? '') : '');

    echo '<div class="mb-2">';
    echo '<div class="text-muted small">Stok HC (Mirror Odoo)</div>';
    echo '<div class="d-flex justify-content-between align-items-center">';
    echo '<div class="fw-semibold">' . htmlspecialchars($kode_homecare) . '</div>';
    echo '<div class="fw-bold">' . htmlspecialchars(fmt_qty($q)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
    echo '</div>';
    if ($last_update !== '') {
        echo '<div class="text-muted small">Terakhir update mirror: <span class="fw-semibold">' . htmlspecialchars(date('d M Y H:i', strtotime($last_update))) . '</span></div>';
    }
    echo '<div class="text-muted small">Stok ini bersifat bersama untuk klinik. Petugas HC hanya dipetakan untuk kebutuhan operasional di sistem.</div>';
    echo '</div>';

    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-hover mb-0">';
    echo '<thead class="table-light">';
    echo '<tr>';
    echo '<th><i class="fas fa-user-nurse"></i> Petugas HC (' . htmlspecialchars($k['nama_klinik']) . ')</th>';
    echo '<th class="text-end"><i class="fas fa-briefcase-medical"></i> Stok Tas</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    $total_tas = 0.0;
    foreach ($petugas as $p) {
        $uid = (int)$p['id'];
        $r_t = $conn->query("SELECT COALESCE(qty,0) AS qty FROM inventory_stok_tas_hc WHERE barang_id = $barang_id AND user_id = $uid AND klinik_id = $klinik_id LIMIT 1");
        $qt = (float)($r_t && $r_t->num_rows > 0 ? ($r_t->fetch_assoc()['qty'] ?? 0) : 0);
        $total_tas += $qt;
        echo '<tr>';
        echo '<td class="fw-semibold">' . htmlspecialchars($p['nama_lengkap']) . '</td>';
        echo '<td class="text-end fw-bold">' . htmlspecialchars(fmt_qty($qt)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></td>';
        echo '</tr>';
    }
    echo '<tr class="table-light">';
    echo '<td class="fw-semibold">Total Tas</td>';
    echo '<td class="text-end fw-bold">' . htmlspecialchars(fmt_qty($total_tas)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    $sellout_total = 0.0;
    $sellout_rows = [];
    if ($last_update !== '') {
        $lu = $conn->real_escape_string($last_update);
        $filter = "pb.klinik_id = $klinik_id AND pb.jenis_pemakaian = 'hc' AND pbd.barang_id = $barang_id AND pb.created_at > '$lu'";
    } else {
        $filter = "1=0";
    }
    $rs = $conn->query("
        SELECT COALESCE(SUM(pbd.qty),0) AS qty
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
        WHERE $filter
    ");
    if ($rs && $rs->num_rows > 0) $sellout_total = (float)($rs->fetch_assoc()['qty'] ?? 0);

    $rs = $conn->query("
        SELECT pb.nomor_pemakaian, pb.tanggal, pb.created_at, pb.user_hc_id, pb.created_by, pbd.qty,
               u_hc.nama_lengkap AS hc_name,
               u_created.nama_lengkap AS created_by_name
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
        LEFT JOIN inventory_users u_hc ON u_hc.id = pb.user_hc_id
        LEFT JOIN inventory_users u_created ON u_created.id = pb.created_by
        WHERE $filter
        ORDER BY pb.created_at DESC
        LIMIT 15
    ");
    while ($rs && ($row = $rs->fetch_assoc())) $sellout_rows[] = $row;

    if ($sellout_total > 0.0001) {
        echo '<div class="mt-3 p-3 border rounded-3 bg-light">';
        echo '<div class="d-flex justify-content-between align-items-center mb-2">';
        echo '<div class="fw-bold"><i class="fas fa-minus-circle text-danger me-2"></i>Sellout HC (setelah refresh)</div>';
        echo '<div class="fw-bold text-danger">' . htmlspecialchars(fmt_qty($sellout_total)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
        echo '</div>';
        echo '<div class="small text-muted mb-2">Berikut transaksi pemakaian HC yang mengurangi stok setelah mirror terakhir di-refresh.</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm mb-0">';
        echo '<thead class="table-light"><tr><th>No</th><th>Waktu</th><th>Petugas HC</th><th>Dibuat Oleh</th><th class="text-end">Qty</th></tr></thead>';
        echo '<tbody>';
        foreach ($sellout_rows as $sr) {
            $no = (string)($sr['nomor_pemakaian'] ?? '-');
            $waktu = (string)($sr['created_at'] ?? ($sr['tanggal'] ?? ''));
            $hc_name = (string)($sr['hc_name'] ?? '-');
            $created_by_name = (string)($sr['created_by_name'] ?? '-');
            $qty = (float)($sr['qty'] ?? 0);
            echo '<tr>';
            echo '<td class="text-muted small">' . htmlspecialchars($no) . '</td>';
            echo '<td class="text-muted small">' . htmlspecialchars($waktu !== '' ? date('d M Y H:i', strtotime($waktu)) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($hc_name) . '</td>';
            echo '<td class="text-muted small">' . htmlspecialchars($created_by_name) . '</td>';
            echo '<td class="text-end fw-semibold text-danger">' . htmlspecialchars(fmt_qty($qty)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
} else {
    $kode_homecare = (string)($k['kode_homecare'] ?? '');
    if ($kode_homecare === '' || (string)($b['kode_barang'] ?? '') === '') {
        echo '<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Tidak ada stok HC untuk item ini.</div>';
        $conn->close();
        exit;
    }
    $loc = $conn->real_escape_string($kode_homecare);
    $kb = $conn->real_escape_string((string)$b['kode_barang']);
    $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM inventory_stock_mirror WHERE location_code = '$loc' AND TRIM(kode_barang) = '$kb'");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    $q = $q / $mult;
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-hover mb-0">';
    echo '<thead class="table-light">';
    echo '<tr>';
    echo '<th><i class="fas fa-user-nurse"></i> HC (' . htmlspecialchars($k['nama_klinik']) . ')</th>';
    echo '<th class="text-end"><i class="fas fa-boxes"></i> Qty</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string)$b['nama_barang']) . '<div class="text-muted small">' . htmlspecialchars($kode_homecare) . '</div></td>';
    echo '<td class="text-end fw-bold">' . htmlspecialchars(fmt_qty($q)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

$conn->close();
?>
