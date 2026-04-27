<?php
/**
 * API Endpoint to fetch bookings with status 'keep'
 * Used for external monitoring (e.g. via Google Apps Script)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

header('Content-Type: application/json');

// Security Token Validation
$sysToken = trim((string)get_setting('odoo_sync_token', ''));
if ($sysToken === '') {
    // Fallback to config constant if DB setting is empty
    if (defined('ODOO_SYNC_SYSTEM_TOKEN')) {
        $sysToken = (string)ODOO_SYNC_SYSTEM_TOKEN;
    }
}

$providedToken = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? ''));

if ($sysToken === '' || !hash_equals($sysToken, $providedToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Forbidden: Invalid or missing token.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch active 'keep' bookings
// We include both 'booked' and 'rescheduled' status but filter for 'keep' type
$sql = "SELECT 
            b.id,
            b.nomor_booking,
            b.nama_pemesan,
            b.tanggal_pemeriksaan,
            b.jam_layanan,
            b.status,
            b.booking_type,
            b.status_booking,
            b.jumlah_pax,
            b.butuh_fu,
            b.cs_name,
            k.nama_klinik
        FROM inventory_booking_pemeriksaan b
        LEFT JOIN inventory_klinik k ON b.klinik_id = k.id
        WHERE b.status = 'booked'
        AND LOWER(COALESCE(b.booking_type, 'keep')) = 'keep'
        ORDER BY b.tanggal_pemeriksaan ASC, b.jam_layanan ASC, b.id ASC";

$res = $conn->query($sql);
if (!$res) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = [];
while ($row = $res->fetch_assoc()) {
    // Sanitize and format
    $row['id'] = (int)$row['id'];
    $row['jumlah_pax'] = (int)$row['jumlah_pax'];
    $row['butuh_fu'] = (int)$row['butuh_fu'];
    $data[] = $row;
}

// Fetch global settings for Lark notifications
$larkWebhook = trim((string)get_setting('webhook_lark_booking_url', ''));
$larkMentionIds = trim((string)get_setting('webhook_lark_booking_at_id', ''));

echo json_encode([
    'success' => true,
    'count' => count($data),
    'generated_at' => date('Y-m-d H:i:s'),
    'config' => [
        'lark_webhook' => $larkWebhook,
        'lark_mention_ids' => $larkMentionIds
    ],
    'data' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
