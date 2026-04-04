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
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['klinik_id'] = $user['klinik_id'];
            $_SESSION['photo'] = $user['photo'];
            
            redirect('index.php?page=dashboard');
        } else {
            $error = 'Email atau password salah.';
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
    <link rel="icon" type="image/x-icon" href="<?= base_url('assets/img/favicon.ico') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 440px;
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            background: #ffffff;
        }
        .login-header {
            padding: 40px 40px 10px;
            text-align: center;
        }
        .login-logo {
            width: 70px;
            height: 70px;
            background: #E8EFFF;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .login-body {
            padding: 30px 40px 40px;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
            margin-bottom: 8px;
        }
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .input-group:focus-within {
            border-color: #204EAB;
            box-shadow: 0 0 0 4px rgba(32, 78, 171, 0.1);
        }
        .input-group-text {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding-left: 15px;
        }
        .form-control {
            border: none;
            padding: 12px 12px 12px 0;
            font-size: 0.95rem;
        }
        .form-control:focus {
            box-shadow: none;
        }
        #togglePasswordBtn {
            border: none;
            background: transparent;
            color: #94a3b8;
        }
        .btn-primary {
            background-color: #204EAB;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn-primary:hover {
            background-color: #1a3e8a;
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(32, 78, 171, 0.2);
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">
                <img src="<?= base_url('assets/img/favicon.ico') ?>" alt="Logo" style="width: 35px;">
            </div>
            <h3 class="fw-bold text-dark mb-1">Bumame Inventory</h3>
            <p class="text-muted small">Healthcare Logistics & Inventory System</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small mb-4 border-0 shadow-sm d-flex align-items-center">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <div><?= $error ?></div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <div class="d-flex justify-content-between">
                        <label class="form-label">Password</label>
                        <a href="#" class="small text-decoration-none" style="color: #204EAB; font-weight: 500;">Forgot?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="loginPassword" class="form-control" placeholder="••••••••" required>
                        <button type="button" id="togglePasswordBtn">
                            <i class="fas fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe">
                        <label class="form-check-label small text-muted" for="rememberMe">Stay signed in for 30 days</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">
                    Sign In <i class="fas fa-arrow-right ms-2 small"></i>
                </button>
            </form>
            
            <div class="mt-5 text-center">
                <small class="text-muted">&copy; <?= date('Y') ?> PT Bumame Health</small>
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
