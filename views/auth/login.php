<?php
if (isset($_SESSION['user_id'])) {
    redirect('index.php?page=dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, nama_lengkap, role, klinik_id, photo FROM inventory_users WHERE username = ? AND status = 'active'");
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
        :root {
            --primary-blue: #204EAB;
            --secondary-blue: #3b82f6;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }
        body {
            background: #D1E5FF;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            padding: 40px;
            text-align: center;
        }
        .logo-container {
            margin-bottom: 30px;
        }
        .logo-container img {
            width: 160px;
            height: auto;
            margin-bottom: 5px;
        }
        .subtitle {
            color: #204EAB;
            font-size: 14px;
            font-weight: 400;
            margin-bottom: 30px;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            display: block;
        }
        .input-wrapper {
            position: relative;
        }
        .form-control-custom {
            width: 100%;
            padding: 12px 15px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            font-size: 14px;
        }
        .btn-signin {
            width: 100%;
            padding: 12px;
            background: #204EAB;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.2s ease;
        }
        .btn-signin:hover {
            background: #1a3e8a;
        }
        .footer-text {
            margin-top: 30px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .alert-custom {
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fee2e2;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <img src="<?= base_url('assets/img/logo.png') ?>" alt="Bumame Logo">
            <div class="subtitle">Bumame Inventory Dashboard</div>
        </div>

        <?php if ($error): ?>
            <div class="alert-custom">
                <i class="fas fa-exclamation-circle me-1"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            
            <div class="form-group">
                <label class="form-label">Username</label>
                <div class="input-wrapper">
                    <input type="text" name="username" class="form-control-custom" placeholder="Enter your username" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="loginPassword" class="form-control-custom" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="togglePasswordBtn">
                        <i class="fas fa-eye" id="togglePasswordIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-signin">
                Sign In
            </button>
        </form>

        <div class="footer-text">
            &copy; <?= date('Y') ?> Bumame Cahaya Medika. All rights reserved.
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
