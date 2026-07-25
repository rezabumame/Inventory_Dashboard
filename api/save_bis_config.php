<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
$is_full_admin = in_array($role, ['super_admin', 'admin_gudang']);
if (!isset($_SESSION['user_id']) || !in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!csrf_validate($_POST['_csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

$barang_id    = (int)($_POST['barang_id'] ?? 0);
// admin_klinik/spv_klinik (dipakai saat penerimaan barang di halaman Inbound) HANYA boleh menyimpan
// Konfigurasi Label (label_only) — tidak boleh mengubah tipe/stok_minimum/track_ed lewat endpoint ini,
// berapa pun nilai label_only yang dikirim client.
$label_only   = $is_full_admin ? !empty($_POST['label_only']) : true; // only update label fields, preserve tipe/stok/track_ed
$tipe         = trim((string)($_POST['tipe'] ?? ''));
$stok_minimum = max(0, (int)($_POST['stok_minimum'] ?? 0));
$track_ed     = (int)($_POST['track_ed'] ?? 0) ? 1 : 0;
$label_print  = in_array($_POST['label_print'] ?? '', ['none', 'physical']) ? $_POST['label_print'] : 'none';
$label_placement = in_array($_POST['label_placement'] ?? '', ['unit', 'box', 'outer', 'catalogue'])
    ? $_POST['label_placement'] : null;
$label_config_set = 1;

// Konfigurasi Label — wizard answers & manual override
$wizard_answers       = (string)($_POST['wizard_answers'] ?? '');
$recommended_category = in_array($_POST['recommended_category'] ?? '', ['A', 'B', 'C', 'D'])
    ? $_POST['recommended_category'] : null;
$final_category       = in_array($_POST['final_category'] ?? '', ['A', 'B', 'C', 'D'])
    ? $_POST['final_category'] : null;
$override_status      = !empty($_POST['override_status']) ? 1 : 0;
$override_reason      = $override_status ? trim((string)($_POST['override_reason'] ?? '')) : null;
$override_notes       = $override_status ? trim((string)($_POST['override_notes'] ?? '')) : null;

if ($barang_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID barang tidak valid.']);
    exit;
}

if ($override_status && $override_reason === '') {
    echo json_encode(['success' => false, 'message' => 'Alasan override wajib dipilih.']);
    exit;
}
if ($override_status && $override_reason === 'Lainnya' && $override_notes === '') {
    echo json_encode(['success' => false, 'message' => 'Keterangan wajib diisi untuk alasan "Lainnya".']);
    exit;
}
if ($override_status && !$final_category) {
    echo json_encode(['success' => false, 'message' => 'Kategori final wajib dipilih untuk override.']);
    exit;
}

// If label_print is none, clear placement
if ($label_print === 'none') $label_placement = null;

// Validate tipe
if (!in_array($tipe, ['Core', 'Support', ''])) $tipe = '';

// Fetch current row to get kode_barang for BIS generation
$row = null;
$res = $conn->query("SELECT kode_barang, barcode_internal FROM inventory_barang WHERE id = $barang_id LIMIT 1");
if ($res) $row = $res->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Barang tidak ditemukan.']);
    exit;
}

// Auto-generate barcode_internal if not set
$barcode_internal = $row['barcode_internal'];
if (empty($barcode_internal)) {
    $kode = trim((string)($row['kode_barang'] ?? ''));
    $barcode_internal = is_numeric($kode)
        ? 'BIS-' . str_pad((int)$kode, 4, '0', STR_PAD_LEFT)
        : 'BIS-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $kode), 0, 8));
}

$bi_esc  = $conn->real_escape_string($barcode_internal);
$tipe_esc = $conn->real_escape_string($tipe);
$lp_val  = $label_placement ? "'" . $conn->real_escape_string($label_placement) . "'" : "NULL";
$wa_val  = $wizard_answers !== '' ? "'" . $conn->real_escape_string($wizard_answers) . "'" : "NULL";
$rc_val  = $recommended_category ? "'" . $recommended_category . "'" : "NULL";
$fc_val  = $final_category ? "'" . $final_category . "'" : "NULL";
$or_val  = $override_reason !== null ? "'" . $conn->real_escape_string($override_reason) . "'" : "NULL";
$on_val  = $override_notes !== null ? "'" . $conn->real_escape_string($override_notes) . "'" : "NULL";
$ob_val  = $override_status ? (int)$_SESSION['user_id'] : "NULL";
$oa_val  = $override_status ? "NOW()" : "NULL";

$label_fields = "
        wizard_answers        = $wa_val,
        recommended_category  = $rc_val,
        final_category        = $fc_val,
        override_status       = $override_status,
        override_reason       = $or_val,
        override_notes        = $on_val,
        override_by           = $ob_val,
        override_at           = $oa_val";

if ($label_only) {
    $sql = "UPDATE inventory_barang SET
        barcode_internal = '$bi_esc',
        label_print      = '$label_print',
        label_placement  = $lp_val,
        label_config_set = 1,$label_fields
    WHERE id = $barang_id";
} else {
    $sql = "UPDATE inventory_barang SET
        barcode_internal  = '$bi_esc',
        tipe              = " . ($tipe_esc !== '' ? "'$tipe_esc'" : "NULL") . ",
        stok_minimum      = $stok_minimum,
        track_ed          = $track_ed,
        label_print       = '$label_print',
        label_placement   = $lp_val,
        label_config_set  = 1,$label_fields
    WHERE id = $barang_id";
}

if ($conn->query($sql)) {
    echo json_encode([
        'success'          => true,
        'barcode_internal' => $barcode_internal,
        'message'          => 'Konfigurasi BIS berhasil disimpan.',
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $conn->error]);
}
