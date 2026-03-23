<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <span class="sidebar-brand-mark" aria-hidden="true">
                <img src="assets/img/favicon.ico" alt="">
            </span>
            <div class="sidebar-brand-text">
                <div class="sidebar-brand-title">Bumame</div>
                <div class="sidebar-brand-subtitle">Inventory</div>
            </div>
        </div>
    </div>
    <div class="sidebar-menu">
        <a href="index.php?page=dashboard" class="sidebar-link <?= $current_page == 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>

        <div class="sidebar-heading">OPERASIONAL</div>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'cs'])): ?>
        <a href="index.php?page=stok_klinik" class="sidebar-link <?= $current_page == 'stok_klinik' ? 'active' : '' ?>">
            <i class="fas fa-hospital"></i> Inventory Klinik
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['cs', 'super_admin', 'admin_klinik'])): ?>
        <a href="index.php?page=booking" class="sidebar-link <?= in_array($current_page, ['booking', 'booking_create']) ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i> Booking & Pending
        </a>
        <?php endif; ?>

        <?php if ($role != 'cs'): ?>
        <a href="index.php?page=request" class="sidebar-link <?= $current_page == 'request' ? 'active' : '' ?>">
            <i class="fas fa-exchange-alt"></i> Request Barang
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'b2b_ops'])): ?>
        <a href="index.php?page=pemakaian_bhp_list" class="sidebar-link <?= $current_page == 'pemakaian_bhp_list' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i> Pemakaian BHP
        </a>
        <?php endif; ?>

        <?php if ($role === 'petugas_hc'): ?>
        <a href="index.php?page=stok_petugas_hc" class="sidebar-link <?= $current_page == 'stok_petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-briefcase-medical"></i> Stok HC Saya
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik'], true)): ?>
        <a href="index.php?page=stok_petugas_hc" class="sidebar-link <?= $current_page == 'stok_petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-briefcase-medical"></i> Stok Petugas HC
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang'])): ?>
        <div class="sidebar-heading">MASTER DATA</div>
        <a href="index.php?page=pemeriksaan" class="sidebar-link <?= $current_page == 'pemeriksaan' ? 'active' : '' ?>">
            <i class="fas fa-notes-medical"></i> Master Pemeriksaan
        </a>
        <a href="index.php?page=klinik" class="sidebar-link <?= $current_page == 'klinik' ? 'active' : '' ?>">
            <i class="fas fa-clinic-medical"></i> Data Klinik
        </a>
        <a href="index.php?page=uom_convert" class="sidebar-link <?= $current_page == 'uom_convert' ? 'active' : '' ?>">
            <i class="fas fa-exchange-alt"></i> Konversi UOM
        </a>
        <a href="index.php?page=barang" class="sidebar-link <?= $current_page == 'barang' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i> Database Barang
        </a>
        <a href="index.php?page=users" class="sidebar-link <?= $current_page == 'users' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> Data User
        </a>
        <div class="sidebar-heading">PENGATURAN</div>
        <a href="index.php?page=settings_integrasi" class="sidebar-link <?= $current_page == 'settings_integrasi' ? 'active' : '' ?>">
            <i class="fas fa-cogs"></i> Integrasi Odoo
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik'])): ?>
        <div class="sidebar-heading">MAPPING HC</div>
        <a href="index.php?page=petugas_hc" class="sidebar-link <?= $current_page == 'petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-user-nurse"></i> Petugas HC
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang'])): ?>
        <div class="sidebar-heading">LAPORAN</div>
        <a href="index.php?page=laporan_transaksi" class="sidebar-link <?= $current_page == 'laporan_transaksi' ? 'active' : '' ?>">
            <i class="fas fa-history"></i> Riwayat Transaksi
        </a>
        <?php endif; ?>

        <div class="sidebar-heading">AKUN</div>
        <a href="index.php?page=profile" class="sidebar-link <?= $current_page == 'profile' ? 'active' : '' ?>">
            <i class="fas fa-user-circle"></i> Profil Saya
        </a>
        <a href="index.php?page=logout" class="sidebar-link text-danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<!-- Mobile Overlay -->
<div class="overlay" id="sidebar-overlay"></div>

<div class="main-content" id="main-content">
    <nav class="top-navbar">
        <button class="btn btn-link text-primary-custom p-0" id="sidebar-toggle">
            <i class="fas fa-bars fa-lg"></i>
        </button>
        <div class="d-flex align-items-center">
            <span class="me-3"><?= $_SESSION['nama_lengkap'] ?? 'User' ?> (<?= ucfirst(str_replace('_', ' ', $role)) ?>)</span>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-decoration-none" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php if (!empty($_SESSION['photo']) && file_exists($_SESSION['photo'])): ?>
                        <img src="<?= $_SESSION['photo'] ?>" alt="Profile" width="32" height="32" class="rounded-circle me-2" style="object-fit: cover;">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-2x text-secondary me-2"></i>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end text-small shadow" aria-labelledby="dropdownUser1">
                    <li><a class="dropdown-item" href="index.php?page=profile">Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="index.php?page=logout">Sign out</a></li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid p-3">
        <!-- Global Alert Messages -->
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2 fs-5"></i>
                <div><?= $_SESSION['error'] ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-2 fs-5"></i>
                <div><?= $_SESSION['success'] ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if (isset($_SESSION['warnings']) && !empty($_SESSION['warnings'])): ?>
        <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
            <div class="d-flex align-items-center mb-2">
                <i class="fas fa-exclamation-triangle me-2 fs-5"></i>
                <strong class="me-2">Peringatan:</strong>
            </div>
            <ul class="mb-0 small">
                <?php foreach ($_SESSION['warnings'] as $warning): ?>
                <li><?= htmlspecialchars($warning) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['warnings']); endif; ?>
    </div> <!-- End of Global Alert Container -->
