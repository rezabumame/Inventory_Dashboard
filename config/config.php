<?php
// Session configuration (12 hours)
$session_lifetime = 12 * 60 * 60; // 43200 seconds
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

// Robust HTTPS detection (supports reverse proxy/CDN)
$is_https = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') $is_https = true;
if (!$is_https && isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https') $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)$_SERVER['HTTP_X_FORWARDED_PORT'] === 443) $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_CF_VISITOR']) && strpos(strtolower($_SERVER['HTTP_CF_VISITOR']), 'https') !== false) $is_https = true;
if (!$is_https && !empty($_SERVER['HTTP_FORWARDED'])) {
    $fwd = strtolower((string)$_SERVER['HTTP_FORWARDED']);
    if (strpos($fwd, 'proto=https') !== false) $is_https = true;
}
$protocol = $is_https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? (getenv('APP_HOST') ?: 'localhost');

// --- ROBUST BASE DIRECTORY DETECTION ---
$base_dir = trim((string)(getenv('APP_BASE_DIR') ?: ''));

if ($base_dir === '') {
    // 1. Detect relative path from Document Root
    $abs_path = str_replace('\\', '/', __DIR__); 
    $root_path = dirname($abs_path); // Project Root (e.g. C:/xampp/htdocs/bumame_iventory2)
    $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''); 

    if ($doc_root !== '') {
        $base_dir = str_ireplace($doc_root, '', $root_path);
    }
    
    // 2. Fallback for XAMPP subfolders if direct replacement failed
    if ($base_dir === '' || $base_dir === '/' || $base_dir === $root_path) {
        if (strpos($root_path, '/htdocs/') !== false) {
            $parts = explode('/htdocs/', $root_path);
            if (isset($parts[1])) $base_dir = '/' . ltrim($parts[1], '/');
        }
    }
}

// 3. Normalize and Force Ngrok if needed
$base_dir = '/' . trim(str_replace('\\', '/', $base_dir), '/') . '/';
if ($base_dir === '//') $base_dir = '/';

if (strpos($host, 'ngrok') !== false && strpos($base_dir, 'bumame_iventory2') === false) {
    $base_dir = '/bumame_iventory2/';
}

define('BASE_URL', $protocol . '://' . $host . $base_dir);
define('APP_NAME', 'Bumame Inventory');

function base_url($path = '') {
    $p = (string)$path;
    if ($p === 'index.php') $p = '';
    return BASE_URL . ltrim($p, '/');
}

function redirect($path) {
    $url = base_url($path);
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    }
    $url_js = json_encode($url, JSON_UNESCAPED_SLASHES);
    $url_html = htmlspecialchars($url, ENT_QUOTES);
    echo "<script>window.location.href=" . $url_js . ";</script>";
    echo "<noscript><meta http-equiv=\"refresh\" content=\"0;url=" . $url_html . "\"></noscript>";
    exit;
}

function check_login() {
    if (!isset($_SESSION['user_id'])) {
        redirect('index.php?page=login');
    }
}

function check_role($allowed_roles) {
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'spv_klinik' && in_array('admin_klinik', $allowed_roles, true)) {
        return;
    }
    if (!in_array($role, $allowed_roles, true)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses Ditolak: Peran Anda tidak diizinkan.']);
        } else {
            http_response_code(403);
            echo "Access Denied";
        }
        exit;
    }
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
}

function csrf_validate(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    $t = (string)($token ?? '');
    $s = (string)($_SESSION['_csrf'] ?? '');
    if ($t === '' || $s === '') return false;
    return hash_equals($s, $t);
}

define('GS_SYNC_KEY', 'BUMAME_SYNC_SECRET_2026'); // Secret key for GSheet Push Sync

function require_csrf(): void {
    $token = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (!csrf_validate((string)$token)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
        } else {
            echo "CSRF validation failed";
        }
        exit;
    }
}
?>
