<?php
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? (getenv('APP_HOST') ?: 'localhost');
$base_dir = trim((string)(getenv('APP_BASE_DIR') ?: ''));
// Detect base path correctly for local and hosting (like InfinityFree/Vercel)
if ($base_dir === '') {
    // Check if we are on InfinityFree by looking for specific server patterns
    $is_infinityfree = isset($_SERVER['HTTP_X_FORWARDED_HOST']) || 
                       (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'fwh.is') !== false) ||
                       (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'infinityfree') !== false);

    if ($is_infinityfree) {
        $base_dir = '/';
    } else {
        // Fallback for local subfolders or other environments
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $base_dir = rtrim(dirname($script_name), '/\\') . '/';
        if ($base_dir === '//' || $base_dir === '\\' || $base_dir === '') $base_dir = '/';
    }
}
define('BASE_URL', $protocol . '://' . $host . $base_dir);
define('APP_NAME', 'Bumame Inventory');

function base_url($path = '') {
    $p = (string)$path;
    // Remove index.php prefix for cleaner URLs if requested
    if ($p === 'index.php') $p = '';
    // Use relative path if the base URL detection is still messy
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
        echo "Access Denied";
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

function require_csrf(): void {
    $token = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (!csrf_validate((string)$token)) {
        http_response_code(403);
        echo "CSRF validation failed";
        exit;
    }
}
?>
