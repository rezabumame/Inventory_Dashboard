<?php
$badge_incoming = 0;
$badge_spv = 0;

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $u_role = $_SESSION['role'];
    $u_klinik_id = $_SESSION['klinik_id'] ?? 0;

    // 1. Incoming Requests Count (for the destination)
    if (in_array($u_role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'], true)) {
        $where_inc = "1=0";
        if ($u_role == 'super_admin') {
            $where_inc = "1=1";
        } elseif ($u_role == 'admin_gudang') {
            $where_inc = "(ke_level = 'gudang_utama' OR (dari_level = 'klinik' AND ke_level = 'klinik'))";
        } else {
            $where_inc = "ke_level = 'klinik' AND ke_id = $u_klinik_id";
        }
        $q_inc = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE $where_inc AND status IN ('pending', 'pending_gudang')");
        $badge_incoming = (int)($q_inc->fetch_assoc()['cnt'] ?? 0);
    }

    // 2. SPV Pending Count (Requests from my clinic awaiting my approval)
    if (in_array($u_role, ['super_admin', 'spv_klinik'], true)) {
        $where_spv = ($u_role == 'super_admin') ? "status = 'pending_spv'" : "dari_level = 'klinik' AND dari_id = $u_klinik_id AND status = 'pending_spv'";
        $q_spv = $conn->query("SELECT COUNT(*) as cnt FROM inventory_request_barang WHERE $where_spv");
        $badge_spv = (int)($q_spv->fetch_assoc()['cnt'] ?? 0);
    }

    // 3. BHP Pending Approval Count (for SPV / Super Admin only)
    $badge_bhp_pending = 0;
    if (in_array($u_role, ['super_admin', 'spv_klinik'], true)) {
        $where_bhp = "status IN ('pending_add', 'pending_edit', 'pending_delete', 'pending_approval_spv')";
        if ($u_role !== 'super_admin') {
            $where_bhp .= " AND klinik_id = $u_klinik_id";
        }
        $q_bhp = $conn->query("SELECT COUNT(*) as cnt FROM inventory_pemakaian_bhp WHERE $where_bhp");
        $badge_bhp_pending = (int)($q_bhp->fetch_assoc()['cnt'] ?? 0);
    }
}

$top_role_label = strtoupper(str_replace('_', ' ', (string)($role ?? ($_SESSION['role'] ?? ''))));
$top_role_sub = '';
$roles_with_klinik = ['admin_klinik', 'spv_klinik', 'cs', 'petugas_hc'];
if (in_array((string)($_SESSION['role'] ?? ''), $roles_with_klinik, true) && !empty($_SESSION['klinik_id'])) {
    $kid = (int)$_SESSION['klinik_id'];
    $rk_top = $conn->query("SELECT nama_klinik FROM inventory_klinik WHERE id = $kid LIMIT 1");
    if ($rk_top && $rk_top->num_rows > 0) {
        $top_role_sub = (string)($rk_top->fetch_assoc()['nama_klinik'] ?? '');
    }
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header d-flex justify-content-center align-items-center">
        <a href="index.php?page=dashboard" class="sidebar-brand text-decoration-none text-center">
            <img src="<?= base_url('assets/img/logo.png') ?>" alt="Bumame Logo" style="max-width: 140px; height: auto;">
        </a>
    </div>
    <div class="sidebar-menu" id="sidebar-menu-scroll">
        <?php if ($role !== 'petugas_hc'): ?>
        <a href="index.php?page=dashboard" class="sidebar-link <?= $current_page == 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <?php endif; ?>

        <div class="sidebar-heading">OPERASIONAL</div>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik', 'cs'])): ?>
        <a href="index.php?page=stok_klinik" class="sidebar-link <?= $current_page == 'stok_klinik' ? 'active' : '' ?>">
            <i class="fas fa-hospital"></i> Inventory Klinik
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['cs', 'super_admin', 'admin_klinik', 'spv_klinik', 'admin_hc'])): ?>
        <?php 
            // Admin HC, Super Admin, CS, etc should see ALL by default.
            // Admin Klinik & SPV Klinik see Today by default.
            $booking_url = in_array($role, ['admin_klinik', 'spv_klinik'], true) ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1'; 
        ?>
        <a href="<?= $booking_url ?>" class="sidebar-link <?= $current_page == 'booking' ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i> CS Booking
        </a>
        <?php endif; ?>

        <?php if (!in_array($role, ['cs', 'petugas_hc', 'admin_hc'])): ?>
        <a href="index.php?page=request" class="sidebar-link <?= $current_page == 'request' ? 'active' : '' ?>">
            <div class="d-flex w-100 align-items-center justify-content-between">
                <div><i class="fas fa-exchange-alt"></i> Request Barang</div>
                <?php if ($u_role !== 'super_admin' && ($badge_incoming + $badge_spv) > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= $badge_incoming + $badge_spv ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'])): ?>
        <a href="index.php?page=pemakaian_bhp_list" class="sidebar-link <?= $current_page == 'pemakaian_bhp_list' ? 'active' : '' ?>">
            <div class="d-flex w-100 align-items-center justify-content-between">
                <div><i class="fas fa-clipboard-list"></i> Pemakaian BHP</div>
                <?php if ($badge_bhp_pending > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= $badge_bhp_pending ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php endif; ?>

        <?php if ($role === 'petugas_hc'): ?>
        <a href="index.php?page=stok_petugas_hc" class="sidebar-link <?= $current_page == 'stok_petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-briefcase-medical"></i> Stok HC Saya
        </a>
        <a href="index.php?page=pemakaian_bhp_list" class="sidebar-link <?= $current_page == 'pemakaian_bhp_list' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i> BHP Saya
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'], true)): ?>
        <a href="index.php?page=stok_petugas_hc" class="sidebar-link <?= $current_page == 'stok_petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-briefcase-medical"></i> Stok Petugas HC
        </a>
        <?php endif; ?>

        <?php if ($role === 'super_admin' || in_array($role, ['admin_klinik', 'spv_klinik', 'cs'])): ?>
        <div class="sidebar-heading">MASTER DATA</div>
        <a href="index.php?page=pemeriksaan" class="sidebar-link <?= $current_page == 'pemeriksaan' ? 'active' : '' ?>">
            <i class="fas fa-notes-medical"></i> <?= ($role === 'super_admin') ? 'Master Pemeriksaan' : 'Daftar Pemeriksaan' ?>
        </a>
        <?php if (in_array($role, ['super_admin', 'admin_klinik', 'spv_klinik'])): ?>
        <a href="index.php?page=petugas_hc" class="sidebar-link <?= $current_page == 'petugas_hc' ? 'active' : '' ?>">
            <i class="fas fa-user-nurse"></i> Petugas HC
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'super_admin'): ?>
        <a href="index.php?page=klinik" class="sidebar-link <?= $current_page == 'klinik' ? 'active' : '' ?>">
            <i class="fas fa-clinic-medical"></i> Data Klinik
        </a>
        <a href="index.php?page=barang" class="sidebar-link <?= $current_page == 'barang' ? 'active' : '' ?>">
            <i class="fas fa-box"></i> Database Barang
        </a>
        <a href="index.php?page=uom_convert" class="sidebar-link <?= $current_page == 'uom_convert' ? 'active' : '' ?>">
            <i class="fas fa-exchange-alt"></i> Konversi UOM
        </a>
        <a href="index.php?page=users" class="sidebar-link <?= $current_page == 'users' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> Data User
        </a>
        <div class="sidebar-heading">PENGATURAN</div>
        <a href="index.php?page=settings_integrasi" class="sidebar-link <?= $current_page == 'settings_integrasi' ? 'active' : '' ?>">
            <i class="fas fa-cogs"></i> Pengaturan Sistem
        </a>
        <a href="index.php?page=odoo_format_config" class="sidebar-link <?= $current_page == 'odoo_format_config' ? 'active' : '' ?>">
            <i class="fas fa-file-invoice"></i> Format Odoo
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'])): ?>
        <div class="sidebar-heading">LAPORAN</div>
        <a href="index.php?page=monthly_summary" class="sidebar-link <?= $current_page == 'monthly_summary' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i> Rekap Bulanan
        </a>
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
        <div class="d-flex align-items-center">
            <button class="btn btn-link text-primary-custom ps-0 me-3" id="sidebar-toggle">
                <i class="fas fa-bars fa-lg"></i>
            </button>
            <h5 class="mb-0 fw-bold d-none d-md-block text-primary-custom"><?= APP_NAME ?></h5>
        </div>
        <div class="d-flex align-items-center">
            <div class="me-3 text-end d-none d-sm-block">
                <div class="fw-bold small"><?= $_SESSION['nama_lengkap'] ?? 'User' ?></div>
                <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($top_role_label) ?></div>
                <?php if ($top_role_sub !== ''): ?>
                    <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($top_role_sub) ?></div>
                <?php endif; ?>
            </div>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center link-dark text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-primary-light rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fas fa-user text-primary-custom"></i>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" aria-labelledby="dropdownUser1">
                    <li class="px-3 py-2 d-sm-none">
                        <div class="fw-bold small"><?= $_SESSION['nama_lengkap'] ?? 'User' ?></div>
                        <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($top_role_label) ?></div>
                        <?php if ($top_role_sub !== ''): ?>
                            <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($top_role_sub) ?></div>
                        <?php endif; ?>
                    </li>
                    <li class="d-sm-none"><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2" href="index.php?page=profile"><i class="fas fa-user-circle me-2 text-muted"></i>Profil Saya</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="index.php?page=logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="content-wrapper">
    <div class="container-fluid pt-2 pb-4">
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
    <script>
    (function() {
        const sidebarMenu = document.getElementById('sidebar-menu-scroll');
        if (sidebarMenu) {
            // Restore scroll position immediately to prevent blink
            const scrollPos = localStorage.getItem('sidebarScrollPos');
            if (scrollPos) {
                sidebarMenu.scrollTop = scrollPos;
                // Double check after layout finishes
                window.addEventListener('load', () => {
                    sidebarMenu.scrollTop = scrollPos;
                });
            }

            // Save scroll position function with debounce
            let scrollTimer;
            const saveScroll = () => {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    try {
                        localStorage.setItem('sidebarScrollPos', sidebarMenu.scrollTop);
                    } catch (e) {
                        console.error("Failed to save sidebar scroll position", e);
                    }
                }, 50);
            };

            // Enhanced click handler for all sidebar links
            sidebarMenu.querySelectorAll('.sidebar-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href && href !== '#' && !href.includes('logout')) {
                        saveScroll();
                    }
                });
            });

            // Save on scroll
            sidebarMenu.addEventListener('scroll', saveScroll);
        }
    })();
    </script>
