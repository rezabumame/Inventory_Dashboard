<?php
$bis_page = $_GET['page'] ?? '';
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header d-flex justify-content-center align-items-center">
        <a href="index.php?page=dashboard" class="sidebar-brand text-decoration-none text-center">
            <img src="<?= base_url('assets/img/logo.png') ?>" alt="Bumame Logo" style="max-width: 140px; height: auto;">
        </a>
    </div>

    <div class="sidebar-menu" id="sidebar-menu-scroll">

        <a href="index.php?page=dashboard" class="sidebar-link" style="color:#94a3b8;font-size:.8rem;margin-bottom:4px;">
            <i class="fas fa-arrow-left me-1"></i> Kembali ke Sistem
        </a>

        <div class="sidebar-heading" style="letter-spacing:.12em;">BIS — INTERNAL SYSTEM</div>

        <a href="index.php?page=qr_inbound"
           class="sidebar-link <?= $bis_page === 'qr_inbound' ? 'active' : '' ?>">
            <i class="fas fa-truck-loading"></i> Inbound Barang
        </a>

        <a href="index.php?page=qr_stock_opname"
           class="sidebar-link <?= $bis_page === 'qr_stock_opname' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-check"></i> Stok Opname
        </a>

        <a href="index.php?page=qr_master_barcode"
           class="sidebar-link <?= $bis_page === 'qr_master_barcode' ? 'active' : '' ?>">
            <i class="fas fa-database"></i> Database Barang
        </a>

    </div>
</div>

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
                <div class="text-muted" style="font-size:10px;">BIS System</div>
            </div>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center link-dark text-decoration-none dropdown-toggle"
                   id="dropdownUserBis" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-primary-light rounded-circle d-flex align-items-center justify-content-center"
                         style="width:40px;height:40px;">
                        <i class="fas fa-user text-primary-custom"></i>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" aria-labelledby="dropdownUserBis">
                    <li><a class="dropdown-item py-2" href="index.php?page=profile">
                        <i class="fas fa-user-circle me-2 text-muted"></i>Profil Saya</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="index.php?page=logout">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="content-wrapper">
    <div class="container-fluid pt-2 pb-4">
