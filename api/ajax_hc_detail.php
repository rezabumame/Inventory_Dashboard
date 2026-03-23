<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$conn->query("
    CREATE TABLE IF NOT EXISTS stock_mirror (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odoo_product_id VARCHAR(64) NOT NULL,
        kode_barang VARCHAR(64) NOT NULL,
        location_code VARCHAR(100) NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS stok_tas_hc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        user_id INT NOT NULL,
        klinik_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY barang_user (barang_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        from_uom VARCHAR(20) NULL,
        to_uom VARCHAR(20) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

// Check role
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'cs', 'petugas_hc'];
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
    FROM barang b
    LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
    WHERE b.id = $barang_id
    LIMIT 1
")->fetch_assoc();

$k = $conn->query("SELECT id, nama_klinik, kode_homecare FROM klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
if (!$b || !$k) {
    echo '<div class="alert alert-warning">Data tidak ditemukan</div>';
    exit;
}

$petugas = [];
$res_p = $conn->query("SELECT id, nama_lengkap FROM users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id ORDER BY nama_lengkap ASC");
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
    $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE TRIM(location_code) = '$loc' AND $match");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    $q = $q * $mult;

    echo '<div class="mb-2">';
    echo '<div class="text-muted small">Stok HC (Mirror Odoo)</div>';
    echo '<div class="d-flex justify-content-between align-items-center">';
    echo '<div class="fw-semibold">' . htmlspecialchars($kode_homecare) . '</div>';
    echo '<div class="fw-bold">' . htmlspecialchars(fmt_qty($q)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
    echo '</div>';
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
        $r_t = $conn->query("SELECT COALESCE(qty,0) AS qty FROM stok_tas_hc WHERE barang_id = $barang_id AND user_id = $uid AND klinik_id = $klinik_id LIMIT 1");
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
} else {
    $kode_homecare = (string)($k['kode_homecare'] ?? '');
    if ($kode_homecare === '' || (string)($b['kode_barang'] ?? '') === '') {
        echo '<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Tidak ada stok HC untuk item ini.</div>';
        $conn->close();
        exit;
    }
    $loc = $conn->real_escape_string($kode_homecare);
    $kb = $conn->real_escape_string((string)$b['kode_barang']);
    $r = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE location_code = '$loc' AND TRIM(kode_barang) = '$kb'");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    $q = $q * $mult;
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
