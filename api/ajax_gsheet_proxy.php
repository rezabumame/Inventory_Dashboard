<?php
session_start();
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$url = trim((string)($_GET['url'] ?? ''));
$action = $_GET['action'] ?? '';
$sheet = $_GET['sheet'] ?? '';

if (empty($url)) {
    echo json_encode(['success' => false, 'message' => 'URL Apps Script tidak boleh kosong.']);
    exit;
}

// Cek apakah URL sudah punya tanda tanya (?) atau belum
$separator = (strpos($url, '?') !== false) ? '&' : '?';
$fetch_url = $url . $separator . "action=" . urlencode($action);

if (!empty($sheet)) {
    $fetch_url .= "&sheet=" . urlencode($sheet);
}

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fetch_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Ikuti redirect Google
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("Koneksi Server Error: $error");
    }

    if ($http_code !== 200) {
        throw new Exception("Google merespon dengan kode: $http_code. Pastikan URL benar.");
    }

    // Jika respon kosong
    if (empty($response)) {
        throw new Exception("Google mengembalikan respon kosong.");
    }

    // Pastikan ini JSON valid sebelum di-echo
    $test_json = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Respon dari Google bukan format JSON yang benar.");
    }

    echo $response;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}