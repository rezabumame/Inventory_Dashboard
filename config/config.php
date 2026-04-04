<?php
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? (getenv('APP_HOST') ?: 'localhost');
$base_dir = trim((string)(getenv('APP_BASE_DIR') ?: ''));
if ($base_dir === '') {
    $project_root = realpath(__DIR__ . '/..');
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    if ($project_root && $doc_root && stripos($project_root, $doc_root) === 0) {
        $rel = substr($project_root, strlen($doc_root));
        $rel = str_replace('\\', '/', (string)$rel);
        $rel = '/' . ltrim($rel, '/');
        $base_dir = rtrim($rel, '/') . '/';
        if ($base_dir === '//') $base_dir = '/';
    } elseif ($project_root) {
        $folder = basename($project_root);
        $base_dir = '/' . trim((string)$folder, '/') . '/';
    } else {
        $base_dir = '/';
    }
} else {
    if ($base_dir[0] !== '/') $base_dir = '/' . $base_dir;
    if (substr($base_dir, -1) !== '/') $base_dir .= '/';
}
define('BASE_URL', $protocol . '://' . $host . $base_dir);
define('APP_NAME', 'Bumame Inventory');

function base_url($path = '') {
    $p = (string)$path;
    if ($p === 'index.php') $p = '';
    if (strpos($p, 'index.php?') === 0) $p = '?' . substr($p, strlen('index.php?'));
    if ($p !== '' && $p[0] === '/') $p = ltrim($p, '/');
    return BASE_URL . $p;
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
