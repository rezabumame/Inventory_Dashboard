<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/stock.php';

// PUBLIC ACCESS CHECK
if (!isset($_SESSION['user_id'])) {
    $token = $_POST['token'] ?? '';
    $saved_token = get_setting('public_stok_token');
    if ($token === '' || $token !== $saved_token) {
        die("Access Denied");
    }
} else {
    check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'petugas_hc', 'cs']);
}

// Get parameters
$barang_id = isset($_POST['barang_id']) ? (int)$_POST['barang_id'] : 0;
$klinik_id = isset($_POST['klinik_id']) ? (int)$_POST['klinik_id'] : 0;

if ($barang_id == 0) {
    echo '<div class="alert alert-warning">Invalid parameters</div>';
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if ($klinik_id > 0) {
    if ($role === 'admin_klinik' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
        echo '<div class="alert alert-danger">Access denied</div>';
        exit;
    }
    if ($role === 'petugas_hc' && (int)($_SESSION['klinik_id'] ?? 0) !== $klinik_id) {
        echo '<div class="alert alert-danger">Access denied</div>';
        exit;
    }
}

$b = $conn->query("
    SELECT b.id, b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan, COALESCE(uc.multiplier, 1) AS multiplier
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE b.id = $barang_id
    LIMIT 1
")->fetch_assoc();

if (!$b) {
    echo '<div class="alert alert-warning">Barang tidak ditemukan</div>';
    exit;
}

$petugas = [];
if ($klinik_id === 0) {
    $res_p = $conn->query("SELECT u.id, u.nama_lengkap, k.nama_klinik FROM inventory_users u JOIN inventory_klinik k ON u.klinik_id = k.id WHERE u.role = 'petugas_hc' AND u.status = 'active' AND k.status = 'active' ORDER BY k.nama_klinik, u.nama_lengkap ASC");
} else {
    $res_p = $conn->query("SELECT id, nama_lengkap FROM inventory_users WHERE role = 'petugas_hc' AND status = 'active' AND klinik_id = $klinik_id ORDER BY nama_lengkap ASC");
}
while ($res_p && ($row = $res_p->fetch_assoc())) $petugas[] = $row;

$mult = (float)($b['multiplier'] ?? 1);
if ($mult <= 0) $mult = 1;

if (!empty($petugas)) {
    if ($klinik_id === 0) {
        $res_k = $conn->query("SELECT id, kode_homecare FROM inventory_klinik WHERE status = 'active' AND kode_homecare != ''");
        $ids = [];
        $locs = [];
        while($rk = $res_k->fetch_assoc()) {
            $ids[] = (int)$rk['id'];
            $locs[] = "'" . $conn->real_escape_string(trim($rk['kode_homecare'])) . "'";
        }
        $loc_filter = "IN (" . implode(',', $locs) . ")";
        $klinik_filter_pb = "pb.klinik_id IN (" . (empty($ids) ? "0" : implode(',', $ids)) . ")";
        $kode_homecare_label = "Semua Klinik (HC)";
    } else {
        $k = $conn->query("SELECT nama_klinik, kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1")->fetch_assoc();
        if (!$k) {
            echo '<div class="alert alert-warning">Klinik tidak ditemukan</div>';
            exit;
        }
        $loc_filter = "= '" . $conn->real_escape_string(trim($k['kode_homecare'])) . "'";
        $klinik_filter_pb = "pb.klinik_id = $klinik_id";
        $kode_homecare_label = (string)($k['kode_homecare'] ?? '');
    }

    if ($loc_filter === "IN ()" || (string)($b['kode_barang'] ?? '') === '') {
        echo '<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Klinik belum memiliki Kode Homecare atau barang belum memiliki kode_barang.</div>';
        $conn->close();
        exit;
    }

    $kb = $conn->real_escape_string((string)$b['kode_barang']);
    $oid = $conn->real_escape_string((string)($b['odoo_product_id'] ?? ''));
    $clauses = [];
    if ($kb !== '') $clauses[] = "TRIM(kode_barang) = '$kb'";
    if ($oid !== '') $clauses[] = "TRIM(odoo_product_id) = '$oid'";
    if (empty($clauses)) $clauses[] = "1=0";
    $match = '(' . implode(' OR ', $clauses) . ')';
    
    $r = $conn->query("SELECT COALESCE(SUM(qty), 0) AS qty FROM inventory_stock_mirror WHERE TRIM(location_code) $loc_filter AND $match");
    $q = (float)($r && $r->num_rows > 0 ? ($r->fetch_assoc()['qty'] ?? 0) : 0);
    $q = $q / $mult;
    
    $res_u = $conn->query("SELECT MAX(updated_at) AS last_update FROM inventory_stock_mirror WHERE TRIM(location_code) $loc_filter");
    $last_update = (string)($res_u && $res_u->num_rows > 0 ? ($res_u->fetch_assoc()['last_update'] ?? '') : '');

    // Hitung total transfer in dari onsite untuk barang ini di klinik ini
    $total_transfer_in = 0;
    if ($barang_id > 0) {
        $sql_total_tr = "SELECT COALESCE(SUM(qty), 0) AS total_in FROM inventory_hc_petugas_transfer WHERE barang_id = $barang_id";
        if ($klinik_id > 0) $sql_total_tr .= " AND klinik_id = $klinik_id";
        if ($last_update !== '') {
            $sql_total_tr .= " AND created_at > '" . $conn->real_escape_string($last_update) . "'";
        }
        $res_total_tr = $conn->query($sql_total_tr);
        if ($res_total_tr && ($r_total_tr = $res_total_tr->fetch_assoc())) {
            $total_transfer_in = (float)$r_total_tr['total_in'];
        }
    }

    echo '<div class="mb-2">';
    echo '<div class="text-muted small">Stok HC (Mirror Odoo)</div>';
    echo '<div class="d-flex justify-content-between align-items-center">';
    echo '<div class="fw-semibold">' . htmlspecialchars($kode_homecare_label) . '</div>';
    echo '<div class="fw-bold">' . htmlspecialchars(fmt_qty($q)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
    echo '</div>';
    if ($total_transfer_in > 0) {
        echo '<div class="d-flex justify-content-between align-items-center mt-1">';
        echo '<div class="fw-semibold">Transfer Onsite</div>';
        echo '<div class="fw-bold">' . htmlspecialchars(fmt_qty($total_transfer_in)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
        echo '</div>';
    }
    if ($last_update !== '') {
        echo '<div class="text-muted small">Terakhir update mirror: <span class="fw-semibold">' . htmlspecialchars(date('d M Y H:i', strtotime($last_update))) . '</span></div>';
    }
    echo '<div class="text-muted small">Stok ini bersifat bersama untuk klinik. Petugas HC hanya dipetakan untuk kebutuhan operasional di sistem.</div>';
    echo '</div>';

    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-hover mb-0">';
    echo '<thead class="table-light">';
    echo '<tr>';
    $header_label = ($klinik_id === 0) ? "Semua Klinik" : htmlspecialchars($k['nama_klinik']);
    echo '<th><i class="fas fa-user-nurse"></i> Petugas HC (' . $header_label . ')</th>';
    echo '<th class="text-end"><i class="fas fa-briefcase-medical"></i> Stok Tas</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    $total_tas = 0.0;
    foreach ($petugas as $p) {
        $uid = (int)$p['id'];
        $tas_klinik_filter = ($klinik_id === 0) ? "" : " AND klinik_id = $klinik_id";
        $r_t = $conn->query("SELECT COALESCE(qty,0) AS qty FROM inventory_stok_tas_hc WHERE barang_id = $barang_id AND user_id = $uid $tas_klinik_filter LIMIT 1");
        $qt = (float)($r_t && $r_t->num_rows > 0 ? ($r_t->fetch_assoc()['qty'] ?? 0) : 0);
        $total_tas += $qt;

        $transfer_in_qty = 0;
        if ($barang_id > 0 && $uid > 0) {
            $sql_tr = "SELECT COALESCE(SUM(qty), 0) AS total_in FROM inventory_hc_petugas_transfer WHERE barang_id = $barang_id AND user_hc_id = $uid";
            if ($klinik_id > 0) $sql_tr .= " AND klinik_id = $klinik_id";
            if ($last_update !== '') {
                $sql_tr .= " AND created_at > '" . $conn->real_escape_string($last_update) . "'";
            }
            $res_tr = $conn->query($sql_tr);
            if ($res_tr && ($r_tr = $res_tr->fetch_assoc())) {
                $transfer_in_qty = (float)$r_tr['total_in'];
            }
        }

        echo '<tr>';
        echo '<td class="fw-semibold">' . htmlspecialchars($p['nama_lengkap']) . ($klinik_id === 0 && !empty($p['nama_klinik']) ? " <small class='text-muted'>(" . htmlspecialchars($p['nama_klinik']) . ")</small>" : "") . '</td>';
        echo '<td class="text-end">';
        echo '<div class="fw-bold">' . htmlspecialchars(fmt_qty($qt)) . ' <small class="text-muted">' . htmlspecialchars((string)$b['satuan']) . '</small></div>';
        if ($transfer_in_qty > 0) {
              echo '<div class="text-success small" style="font-size: 0.75rem; font-weight: bold;"><i class="fas fa-arrow-down me-1"></i>' . htmlspecialchars(fmt_qty($transfer_in_qty)) . ' dari transfer onsite</div>';
          }
        echo '</td>';
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
        $filter = "$klinik_filter_pb AND pb.jenis_pemakaian = 'hc' AND pbd.barang_id = $barang_id AND pb.created_at > '$lu'";
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
    if ($klinik_id === 0) {
        echo '<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Tidak ada petugas HC yang terdaftar di semua klinik aktif.</div>';
        $conn->close();
        exit;
    }
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
    $header_label = htmlspecialchars($k['nama_klinik'] ?? 'Klinik');
    echo '<th><i class="fas fa-user-nurse"></i> HC (' . $header_label . ')</th>';
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
