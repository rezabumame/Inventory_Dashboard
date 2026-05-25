<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) die('Unauthorized');
if (($_SESSION['role'] ?? '') !== 'super_admin') die('Access denied');

$confirmed = isset($_POST['confirm']);

if ($confirmed) {
    // 1. Clear all remember tokens
    $conn->query("UPDATE inventory_users SET remember_token = NULL, remember_token_expires = NULL");
    $affected = $conn->affected_rows;

    // 2. Clear all session files
    $session_dir = ini_get('session.save_path') ?: '/var/lib/php/sessions';
    $deleted = 0;
    foreach (glob($session_dir . '/sess_*') as $file) {
        if (unlink($file)) $deleted++;
    }

    $msg = "Berhasil: $affected user token dihapus, $deleted session file dihapus. Semua user akan ter-logout.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Force Logout All Users</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-5">
    <div class="card mx-auto" style="max-width:480px;">
        <div class="card-header bg-danger text-white fw-bold">Force Logout Semua User</div>
        <div class="card-body">
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
            <?php else: ?>
                <p class="text-muted">Tindakan ini akan:</p>
                <ul>
                    <li>Menghapus semua session aktif</li>
                    <li>Menghapus semua remember token</li>
                    <li>Semua user harus login ulang</li>
                </ul>
                <form method="POST">
                    <input type="hidden" name="confirm" value="1">
                    <?= csrf_input() ?>
                    <button type="submit" class="btn btn-danger w-100">Ya, Logout Semua User</button>
                </form>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-secondary w-100 mt-2">Kembali</a>
        </div>
    </div>
</body>
</html>
