<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik', 'petugas_hc', 'cs', 'b2b_ops']);

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_lengkap = $_POST['nama_lengkap'];
    $password = $_POST['password'];
    
    $update_pass = "";
    if (!empty($password)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update_pass = ", password = '$hashed'";
    }

    if (empty($message)) {
        $sql = "UPDATE users SET nama_lengkap = ? $update_pass WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $nama_lengkap, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['nama_lengkap'] = $nama_lengkap;
            $message = 'Profil berhasil diperbarui!';
            $message_type = 'success';
        } else {
            $message = 'Gagal update profil: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Fetch Current Data
$user = $conn->query("SELECT u.*, k.nama_klinik FROM users u LEFT JOIN klinik k ON u.klinik_id = k.id WHERE u.id = $user_id")->fetch_assoc();
?>

    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-user-circle me-2"></i>Profil Saya
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Profil</li>
                </ol>
            </nav>
        </div>
    </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Profile Card -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center p-4">
                        <div class="position-relative d-inline-block mb-3">
                            <?php if (!empty($user['photo']) && file_exists($user['photo'])): ?>
                                <img src="<?= $user['photo'] ?>?v=<?= time() ?>" id="profilePreview" class="rounded-circle" width="150" height="150" style="object-fit: cover; border: 1px solid #e5e7eb;">
                            <?php else: ?>
                                <div id="profilePreview" class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px; background: #eff6ff; color: #204EAB; border: 1px solid #e5e7eb;">
                                    <i class="fas fa-user fa-3x"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <h5 class="mb-1 fw-bold"><?= htmlspecialchars($user['nama_lengkap']) ?></h5>
                        <p class="text-muted mb-2">
                            <span class="badge bg-primary-custom">
                                <?= ucfirst(str_replace('_', ' ', $user['role'])) ?>
                            </span>
                        </p>
                        <p class="text-muted small mb-0">
                            <i class="fas fa-envelope"></i> <?= htmlspecialchars($user['username']) ?>
                        </p>
                        <?php if (!empty($user['nama_klinik'])): ?>
                        <p class="text-muted small mb-0">
                            <i class="fas fa-hospital"></i> <?= htmlspecialchars($user['nama_klinik']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mt-3">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="fas fa-info-circle text-primary"></i> Informasi Akun</h6>
                        <div class="mb-2">
                            <small class="text-muted">Status</small>
                            <p class="mb-0">
                                <span class="badge bg-success">
                                    <i class="fas fa-check-circle"></i> Aktif
                                </span>
                            </p>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted">Dibuat</small>
                            <p class="mb-0"><?= date('d M Y', strtotime($user['created_at'] ?? 'now')) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="mb-0 fw-bold" style="color: #204EAB;">
                            <i class="fas fa-edit me-2"></i>Edit Profil
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="profileForm">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-user text-primary"></i> Nama Lengkap
                                    </label>
                                    <input type="text" name="nama_lengkap" class="form-control form-control-lg" value="<?= htmlspecialchars($user['nama_lengkap']) ?>" required>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-envelope text-primary"></i> Email
                                    </label>
                                    <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($user['username']) ?>" readonly disabled style="background-color: #f8f9fa;">
                                    <small class="text-muted">Email tidak dapat diubah</small>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-lock text-primary"></i> Password Baru
                                    </label>
                                    <input type="password" name="password" class="form-control form-control-lg" placeholder="Biarkan kosong jika tidak ingin mengganti password">
                                    <small class="text-muted">Minimal 6 karakter</small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-user-tag" style="color: #204EAB;"></i> Role
                                    </label>
                                    <input type="text" class="form-control form-control-lg" value="<?= ucfirst(str_replace('_', ' ', $user['role'])) ?>" readonly disabled style="background-color: #f8f9fa;">
                                </div>

                                <?php if (!empty($user['nama_klinik'])): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-hospital text-primary"></i> Klinik
                                    </label>
                                    <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($user['nama_klinik']) ?>" readonly disabled style="background-color: #f8f9fa;">
                                </div>
                                <?php endif; ?>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-lg px-4 text-white border-0 shadow-sm" style="background-color: #204EAB;">
                                    <i class="fas fa-save me-1"></i>Simpan Perubahan
                                </button>
                                <a href="index.php?page=dashboard" class="btn btn-lg btn-outline-secondary px-4">
                                    <i class="fas fa-times me-1"></i>Batal
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
