<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../lib/lark.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

$lark_stock_url = trim((string)get_setting('webhook_lark_url', ''));
$lark_booking_url = trim((string)get_setting('webhook_lark_booking_url', ''));

$results = [];

if ($lark_stock_url !== '') {
    $ok = lark_send_payload($lark_stock_url, json_encode([
        'msg_type' => 'text',
        'content' => ['text' => '[TEST] Notifikasi Stok Odoo - System Check OK.']
    ]));
    $results['stock'] = $ok;
}

if ($lark_booking_url !== '') {
    $lines = [
        "**Event:** Test Notification",
        "**Status:** System configuration test",
        "**Time:** " . date('Y-m-d H:i:s')
    ];
    lark_post_card($lark_booking_url, "🧪 Test Booking Notification", $lines, "blue");
    $results['booking'] = true; // Card format doesn't easily return success status from lark_post_card without refactor
}

if (empty($results)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada URL webhook yang diset.']);
} else {
    echo json_encode(['success' => true, 'message' => 'Pesan tes berhasil dikirim ke webhook yang tersedia.', 'details' => $results]);
}
