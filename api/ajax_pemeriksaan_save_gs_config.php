<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

$config_json = $_POST['config'] ?? '';
$config = json_decode($config_json, true);

if (!$config || empty($config['url'])) {
    echo json_encode(['success' => false, 'message' => 'Data konfigurasi tidak valid.']);
    exit;
}

try {
    $conn->begin_transaction();

    // Save each part to settings using the correct set_setting from settings.php
    require_once __DIR__ . '/../config/settings.php';
    set_setting('gsheet_exam_url', $config['url']);
    set_setting('gsheet_exam_sheet', $config['sheet']);
    set_setting('gsheet_exam_mapping', json_encode($config['mapping']));

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
