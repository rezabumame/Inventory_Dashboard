<?php
// Bootstrap if accessed directly (e.g. from standalone QR transfer page)
if (!function_exists('redirect')) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($conn)) {
        require_once __DIR__ . '/../../config/database.php';
    }
    function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
}

// Clear remember me token from DB
if (!empty($_COOKIE['_rm_token']) && isset($conn)) {
    $tok = $conn->real_escape_string($_COOKIE['_rm_token']);
    $conn->query("UPDATE inventory_users SET remember_token=NULL, remember_token_expires=NULL WHERE remember_token='$tok'");
}
// Clear cookie
setcookie('_rm_token', '', time() - 3600, '/', '', false, true);
session_destroy();
redirect('index.php?page=login');
