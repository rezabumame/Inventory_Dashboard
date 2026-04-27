<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/stock.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowed = ['cs', 'super_admin', 'admin_klinik'];
if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

$where = "b.id = ?";
if (($_SESSION['role'] ?? '') === 'admin_klinik') {
    $where .= " AND b.klinik_id = " . (int)($_SESSION['klinik_id'] ?? 0);
}

$stmt = $conn->prepare("
    SELECT 
        b.*,
        k.nama_klinik
    FROM inventory_booking_pemeriksaan b
    JOIN inventory_klinik k ON b.klinik_id = k.id
    WHERE $where
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();
if (!$header) {
    echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan']);
    exit;
}

$stmt = $conn->prepare("
    SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ') AS jenis_pemeriksaan
    FROM inventory_booking_pasien bp
    JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
    WHERE bp.booking_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$jenis = (string)($stmt->get_result()->fetch_assoc()['jenis_pemeriksaan'] ?? '');

$stmt = $conn->prepare("
    SELECT 
        b.id AS barang_id,
        b.kode_barang,
        b.nama_barang,
        b.satuan,
        SUM(bd.qty_gantung) AS qty
    FROM inventory_booking_detail bd
    JOIN inventory_barang b ON bd.barang_id = b.id
    WHERE bd.booking_id = ?
    GROUP BY b.id, b.kode_barang, b.nama_barang, b.satuan
    ORDER BY b.nama_barang ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['is_history'] = false;
    $items[] = $row;
}

// Fallback: If items are empty (e.g. Completed or Cancelled), fetch from exam mapping as history
if (empty($items)) {
    $stmt_hist = $conn->prepare("
        SELECT 
            b.id AS barang_id,
            b.kode_barang,
            b.nama_barang,
            b.satuan,
            SUM(pgd.qty_per_pemeriksaan) AS qty
        FROM inventory_booking_pasien bp
        JOIN inventory_pemeriksaan_grup_detail pgd ON bp.pemeriksaan_grup_id = pgd.pemeriksaan_grup_id
        JOIN inventory_barang b ON pgd.barang_id = b.id
        WHERE bp.booking_id = ? AND bp.status = 'done'
        GROUP BY b.id, b.kode_barang, b.nama_barang, b.satuan
        ORDER BY b.nama_barang ASC
    ");
    $stmt_hist->bind_param("i", $id);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();
    while ($row = $res_hist->fetch_assoc()) {
        $row['is_history'] = true;
        $items[] = $row;
    }
}
$is_hc = (stripos($header['status_booking'] ?? '', 'HC') !== false);
$klinik_id = (int)$header['klinik_id'];

$re_evaluated_is_out_of_stock = 0;
$re_evaluated_out_of_stock_items = [];

foreach ($items as &$row) {
    // Only check current availability for active bookings (not historical or already processed)
    if (!$row['is_history'] && !in_array($header['status'], ['completed', 'cancelled', 'done'])) {
        $ef = stock_effective($conn, $klinik_id, $is_hc, (int)$row['barang_id']);
        $row['current_available'] = $ef['ok'] ? (float)$ef['on_hand'] : 0;
        
        if ($row['current_available'] < (float)$row['qty']) {
            $re_evaluated_is_out_of_stock = 1;
            $re_evaluated_out_of_stock_items[] = htmlspecialchars($row['nama_barang']) . " (Sisa: " . fmt_qty($row['current_available']) . ", Butuh: " . fmt_qty($row['qty']) . ")";
        }
    } else {
        $row['current_available'] = null; // Don't show availability for history
    }
}
unset($row);

// Update header with re-evaluated OOS status
$header['is_out_of_stock'] = $re_evaluated_is_out_of_stock;
$header['out_of_stock_items'] = !empty($re_evaluated_out_of_stock_items) ? implode(', ', $re_evaluated_out_of_stock_items) : null;

// Fetch all pasien (utama + tambahan) with their exams
$stmt = $conn->prepare("
    SELECT 
        MIN(bp.id) as id,
        bp.nama_pasien, 
        bp.nomor_tlp, 
        bp.tanggal_lahir,
        -- Status agregasi: prioritaskan rescheduled, lalu done (meskipun baru sebagian sesuai request user)
        CASE 
            WHEN SUM(CASE WHEN bp.status = 'rescheduled' THEN 1 ELSE 0 END) > 0 THEN 'rescheduled'
            WHEN SUM(CASE WHEN bp.status = 'done' THEN 1 ELSE 0 END) > 0 THEN 'done'
            WHEN SUM(CASE WHEN bp.status = 'cancelled' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN bp.status != 'cancelled' THEN 1 ELSE 0 END) = 0 THEN 'cancelled'
            ELSE 'booked'
        END as status,
        GROUP_CONCAT(DISTINCT bp.remark SEPARATOR ' | ') as remark,
        GROUP_CONCAT(
            CONCAT(
                pg.nama_pemeriksaan, 
                CASE 
                    WHEN bp.status = 'done' THEN ' [Done]' 
                    WHEN bp.status = 'rescheduled' THEN ' [Rescheduled]' 
                    WHEN bp.status = 'cancelled' THEN ' [Cancelled]' 
                    ELSE '' 
                END
            ) 
            SEPARATOR ', '
        ) as exams,
        GROUP_CONCAT(pg.id SEPARATOR ',') as exam_ids
    FROM inventory_booking_pasien bp
    JOIN inventory_pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
    WHERE bp.booking_id = ?
    GROUP BY bp.nama_pasien, bp.nomor_tlp, bp.tanggal_lahir
    ORDER BY MIN(bp.id) ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$pasien_list = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['exam_ids'])) {
        $row['exam_ids'] = explode(',', $row['exam_ids']);
    } else {
        $row['exam_ids'] = [];
    }
    $pasien_list[] = $row;
}

echo json_encode([
    'success' => true,
    'header' => $header,
    'jenis_pemeriksaan' => $jenis,
    'items' => $items,
    'pasien_list' => $pasien_list,
], JSON_UNESCAPED_UNICODE);


