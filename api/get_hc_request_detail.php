<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

check_role(['admin_klinik', 'super_admin', 'spv_klinik']);

$request_id = (int)($_GET['request_id'] ?? 0);
$role       = (string)$_SESSION['role'];
$s_klinik   = (int)($_SESSION['klinik_id'] ?? 0);

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'request_id tidak valid.']); exit;
}

$req = $conn->query("
    SELECT r.*, u.nama_lengkap AS nakes_name
    FROM inventory_hc_transfer_request r
    JOIN inventory_users u ON u.id = r.user_hc_id
    WHERE r.id = $request_id LIMIT 1
")->fetch_assoc();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Request tidak ditemukan.']); exit;
}

if (in_array($role, ['admin_klinik', 'spv_klinik'], true) && (int)$req['klinik_id'] !== $s_klinik) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}

// Items
$items_res = $conn->query("
    SELECT i.id, i.barang_id, i.qty_request, i.qty_approved,
           b.nama_barang, COALESCE(NULLIF(uc.to_uom,''), b.satuan) AS uom
    FROM inventory_hc_transfer_request_items i
    JOIN inventory_barang b ON b.id = i.barang_id
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
    WHERE i.request_id = $request_id
    ORDER BY b.nama_barang ASC
");
$items = [];
while ($items_res && ($ir = $items_res->fetch_assoc())) {
    $items[] = [
        'id'           => (int)$ir['id'],
        'barang_id'    => (int)$ir['barang_id'],
        'nama_barang'  => $ir['nama_barang'],
        'uom'          => $ir['uom'],
        'qty_request'  => (float)$ir['qty_request'],
        'qty_approved' => $ir['qty_approved'] !== null ? (float)$ir['qty_approved'] : null,
    ];
}

$foto_url = '';
if (!empty($req['foto_path'])) {
    $foto_url = base_url($req['foto_path']);
}

echo json_encode([
    'success' => true,
    'data'    => [
        'id'             => (int)$req['id'],
        'nakes_name'     => $req['nakes_name'],
        'status'         => $req['status'],
        'foto_path'      => $req['foto_path'],
        'foto_url'       => $foto_url,
        'catatan'        => $req['catatan'],
        'created_at_fmt' => date('d M Y H:i', strtotime($req['created_at'])),
        'items'          => $items,
        'csrf'           => csrf_token(),
    ],
]);
