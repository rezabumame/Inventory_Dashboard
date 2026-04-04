<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/odoo.php';

header('Content-Type: application/json');
set_time_limit(300);

$now = time();
$debug = [
    'server_time' => date('d M Y H:i:s'),
    'server_tz' => date_default_timezone_get(),
    'now' => $now
];

$enabled = get_setting('odoo_sync_enabled', '0') === '1';
$mode = get_setting('odoo_sync_mode', 'manual');
$interval = (int) get_setting('odoo_sync_interval_minutes', '0');
$weekday = (int) get_setting('odoo_sync_weekday', '0');
$time = get_setting('odoo_sync_time', '20:00');
$lastRun = (int) get_setting('odoo_sync_last_run', '0');
$dbToken = trim((string)get_setting('odoo_sync_token', ''));
$sysToken = $dbToken !== '' ? $dbToken : (string)ODOO_SYNC_SYSTEM_TOKEN;
$providedToken = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? ''));

if ($sysToken !== '' && !hash_equals($sysToken, $providedToken)) {
    // Allow if user is logged in as super_admin (for browser 'tick')
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'ran' => false, 'message' => 'Forbidden', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!$enabled) {
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (disabled).', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'manual') {
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (manual mode).', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

$due = false;
$target = null;
 
$force = isset($_GET['force']) && $_GET['force'] === '1';

if ($mode === 'interval' && $interval > 0) {
    // Tambahkan buffer 5 detik agar tidak terpicu 2x jika terpanggil sangat cepat di detik yang sama
    $due = ($lastRun === 0) || ($now - $lastRun >= ($interval * 60) - 5);
}

if ($mode === 'daily') {
    $target = strtotime(date('Y-m-d') . ' ' . $time);
    // Cek: Sekarang sudah melewati jam target DAN terakhir jalan adalah SEBELUM jam target hari ini
    if ($now >= $target && $lastRun < $target) {
        $due = true;
    }
}

if ($mode === 'weekly') {
    $todayW = (int) date('w');
    $debug['today_w'] = $todayW;
    $debug['weekday_setting'] = $weekday;
    if ($weekday === $todayW) {
        $target = strtotime(date('Y-m-d') . ' ' . $time);
        // Cek: Sekarang sudah melewati jam target DAN terakhir jalan adalah SEBELUM jam target hari ini
        if ($now >= $target && $lastRun < $target) {
            $due = true;
        }
    }
}

$quick = isset($_GET['quick']) && $_GET['quick'] === '1';
if ($quick) {
    $debug['mode'] = $mode;
    $debug['interval_minutes'] = $interval;
    $debug['time_setting'] = $time;
    $debug['last_run'] = $lastRun;
    $debug['last_run_text'] = $lastRun ? date('d M Y H:i:s', $lastRun) : '-';
    $debug['target'] = $target;
    $debug['target_text'] = $target ? date('d M Y H:i:s', $target) : '-';
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Quick check', 'quick_due' => ($due || $force), 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$due && !$force) {
    $debug['mode'] = $mode;
    $debug['interval_minutes'] = $interval;
    $debug['time_setting'] = $time;
    $debug['last_run'] = $lastRun;
    $debug['last_run_text'] = $lastRun ? date('d M Y H:i:s', $lastRun) : '-';
    $debug['target'] = $target;
    $debug['target_text'] = $target ? date('d M Y H:i:s', $target) : '-';
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($sysToken === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'ran' => false, 'message' => 'Token scheduler belum diset. Isi Token Scheduler di Pengaturan Integrasi Odoo.', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

$lock_ok = false;
try {
    $lock = $conn->query("SELECT GET_LOCK('odoo_sync_schedule', 0) as l");
    if ($lock && $lock->num_rows > 0) {
        $lock_ok = ((int)($lock->fetch_assoc()['l'] ?? 0) === 1);
    }
} catch (Exception $e) {
    $lock_ok = false;
}

if (!$lock_ok) {
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (busy)', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir1 = rtrim(dirname($script), '/');
    $appRoot = rtrim(dirname($dir1), '/');
    if ($appRoot === '') $appRoot = '/';
    $targetUrl = $scheme . '://' . $host . $appRoot . '/api/sync_odoo.php';

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Internal-Token: ' . $sysToken]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code >= 200 && $code < 300) {
        $payload = json_decode((string)$resp, true);
        if (is_array($payload)) {
            $payload['ran'] = true;
            $payload['debug'] = $debug;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => true, 'ran' => true, 'message' => 'Sync selesai', 'debug' => $debug], JSON_UNESCAPED_UNICODE);
        }
    } else {
        http_response_code(500);
        $snippet = '';
        $resp_s = (string)$resp;
        if ($resp_s !== '') $snippet = substr($resp_s, 0, 300);
        echo json_encode(['success' => false, 'ran' => false, 'message' => 'Sync request failed', 'http_code' => $code, 'body' => $snippet, 'debug' => $debug], JSON_UNESCAPED_UNICODE);
    }
} finally {
    try {
        $conn->query("DO RELEASE_LOCK('odoo_sync_schedule')");
    } catch (Exception $e) {
    }
}


