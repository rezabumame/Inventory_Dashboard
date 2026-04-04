<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/odoo.php';
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
$token_ok = (!empty(ODOO_SYNC_SYSTEM_TOKEN) && $token === ODOO_SYNC_SYSTEM_TOKEN);
$session_ok = (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'], true));
if (!$token_ok && !$session_ok) {
    http_response_code(403);
    echo json_encode(['success' => false, 'ran' => false, 'message' => 'Forbidden']);
    exit;
}

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

if (!$enabled) {
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (disabled).', 'debug' => $debug]);
    exit;
}

if ($mode === 'manual') {
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (manual mode).', 'debug' => $debug]);
    exit;
}

$due = false;
$target = null;

if ($mode === 'interval' && $interval > 0) {
    $due = ($lastRun === 0) || ($now - $lastRun >= $interval * 60);
}

if ($mode === 'daily') {
    $target = strtotime(date('Y-m-d') . ' ' . $time);
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
        if ($now >= $target && $lastRun < $target) {
            $due = true;
        }
    }
}

if (!$due) {
    $debug['mode'] = $mode;
    $debug['interval_minutes'] = $interval;
    $debug['time_setting'] = $time;
    $debug['last_run'] = $lastRun;
    $debug['last_run_text'] = $lastRun ? date('d M Y H:i:s', $lastRun) : '-';
    $debug['target'] = $target;
    $debug['target_text'] = $target ? date('d M Y H:i:s', $target) : '-';
    $quick = isset($_GET['quick']) && $_GET['quick'] === '1';
    echo json_encode([
        'success' => true, 
        'ran' => false, 
        'message' => 'Not due', 
        'debug' => $debug,
        'quick_due' => ($quick ? false : false)
    ]);
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
    echo json_encode(['success' => true, 'ran' => false, 'message' => 'Not due (busy)', 'debug' => $debug]);
    exit;
}

$lark_url = trim((string)get_setting('webhook_lark_url', ''));
function post_lark_text_sched($text) {
    global $lark_url;
    if ($lark_url === '') return;
    $payload = json_encode(['msg_type' => 'text', 'content' => ['text' => $text]]);
    $ch = curl_init($lark_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    curl_close($ch);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
$dir1 = rtrim(dirname($script), '/');          // e.g. /bumame_iventory2/api
$appRoot = rtrim(dirname($dir1), '/');         // e.g. /bumame_iventory2
if ($appRoot === '') $appRoot = '/';
$targetUrl = $scheme . '://' . $host . $appRoot . '/api/sync_odoo.php';
$delays = [30, 120, 600];
$headers = [];
if (!empty(ODOO_SYNC_SYSTEM_TOKEN)) {
    $headers[] = 'X-Internal-Token: ' . ODOO_SYNC_SYSTEM_TOKEN;
}
function run_once_sched($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if (session_id() !== '') curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $payload = json_decode((string)$resp, true);
    $ok = ($code >= 200 && $code < 300 && is_array($payload) && ($payload['success'] ?? false));
    return ['ok' => $ok, 'code' => $code, 'payload' => $payload, 'err' => $err, 'raw' => $resp];
}

$first = run_once_sched($targetUrl, $headers);
if ($first['ok']) {
    set_setting('odoo_sync_last_run', (string) time());
    $payload = $first['payload'];
    if (is_array($payload)) {
        $payload['ran'] = true;
        $payload['debug'] = $debug;
        echo json_encode($payload);
    } else {
        echo json_encode(['success' => true, 'ran' => true, 'message' => 'Sync selesai', 'debug' => $debug]);
    }
} else {
    post_lark_text_sched("[SYNC ODOO][SCHED] Gagal (" . (int)$first['code'] . "). Auto-retry: 30s → 2m → 10m");
    $ok = false;
    $attempt = 0;
    foreach ($delays as $d) {
        $attempt++;
        sleep($d);
        $r = run_once_sched($targetUrl, $headers);
        if ($r['ok']) {
            set_setting('odoo_sync_last_run', (string) time());
            $payload = $r['payload'];
            if (is_array($payload)) {
                $payload['ran'] = true;
                $payload['retry_attempt'] = $attempt;
                $payload['debug'] = $debug;
                echo json_encode($payload);
            } else {
                echo json_encode(['success' => true, 'ran' => true, 'retry_attempt' => $attempt, 'message' => 'Sync selesai', 'debug' => $debug]);
            }
            post_lark_text_sched("[SYNC ODOO][SCHED] Berhasil setelah retry #" . $attempt . " (" . $d . "s).");
            $ok = true;
            break;
        } else {
            post_lark_text_sched("[SYNC ODOO][SCHED] Retry #" . $attempt . " gagal (" . (int)$r['code'] . ").");
        }
    }
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'ran' => false, 'message' => 'Sync request failed (all retries)', 'debug' => $debug]);
        post_lark_text_sched("[SYNC ODOO][SCHED] Gagal setelah semua retry.");
    }
}

try {
    $conn->query("DO RELEASE_LOCK('odoo_sync_schedule')");
} catch (Exception $e) {}
?>
