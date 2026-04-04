<?php
if (isset($_SESSION['user_id'])) {
    redirect('index.php?page=dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, nama_lengkap, role, klinik_id, photo FROM users WHERE username = ? AND status = 'active'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        // For development, I used a known hash in the seed data.
        // If the password matches the hash
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['klinik_id'] = $user['klinik_id'];
            $_SESSION['photo'] = $user['photo']; // Add photo to session
            
            redirect('index.php?page=dashboard');
        } else {
            $error = 'Password salah.';
        }
    } else {
        $error = 'Email tidak ditemukan atau akun tidak aktif.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= base_url('assets/img/favicon.ico') ?>">
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<div class="container" style="max-width: 420px;">
    <div class="d-flex flex-column justify-content-center" style="min-height: 100vh;">
        <div class="text-center mb-3">
            <div class="fw-bold text-primary-custom" style="font-size: 20px;">Bumame</div>
            <div class="text-muted small">Healthcare Inventory Platform</div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="fw-semibold mb-3">Login</div>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small mb-3"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Username</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="loginPassword" class="form-control border-end-0" required>
                    <button class="btn border border-start-0 bg-transparent text-muted" type="button" id="togglePasswordBtn" style="border-color: #dee2e6;">
                        <i class="fas fa-eye" id="togglePasswordIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Sign In</button>
        </form>
        
        <div class="mt-4 text-center">
            <small class="text-muted">&copy; <?= date('Y') ?> Bumame Inventory System</small>
        </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('togglePasswordBtn').addEventListener('click', function() {
        const passwordInput = document.getElementById('loginPassword');
        const icon = document.getElementById('togglePasswordIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
</script>
</body>
</html>
