<?php
require_once 'config/config.php';
require_once 'config/database.php';

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Redirect petugas_hc from dashboard to stok_petugas_hc
if ($page === 'dashboard' && isset($_SESSION['role']) && $_SESSION['role'] === 'petugas_hc') {
    $page = 'stok_petugas_hc';
}
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Auth check for non-public pages
$public_pages = ['login', 'logout', 'qr_verify_rab', 'stok_klinik_publik'];
if (!in_array($page, $public_pages) && !isset($_SESSION['user_id'])) {
    redirect('index.php?page=login');
}

// Layout structure
if (!in_array($page, $public_pages) && !isset($_GET['layout'])) {
    include 'views/layouts/header.php';
    include 'views/layouts/sidebar.php';
}

// Routing
switch ($page) {
    case 'login':
        include 'views/auth/login.php';
        break;
    case 'logout':
        include 'views/auth/logout.php';
        break;
    case 'dashboard':
        include 'views/dashboard/index.php';
        break;
    // Master Data
    case 'users':
        include 'views/master/users.php';
        break;
    case 'klinik':
        include 'views/master/klinik.php';
        break;
    case 'pemeriksaan':
        include 'views/master/pemeriksaan.php';
        break;
    case 'petugas_hc':
        include 'views/master/petugas_hc.php';
        break;
    case 'barang':
        include 'views/master/barang.php';
        break;
    // Inventory
    case 'stok_klinik':
        include 'views/inventory/stok_klinik.php';
        break;

    case 'uom_convert':
        include 'views/inventory/uom_convert.php';
        break;

    case 'request':
        include 'views/inventory/request.php';
        break;
    case 'stok_petugas_hc':
        include 'views/inventory/stok_petugas_hc.php';
        break;
    case 'stok_klinik_publik':
        include 'views/inventory/stok_klinik_publik.php';
        break;
    // case 'stock_opname':
    //     include 'views/inventory/stock_opname.php';
    //     break;
    // Purchase Order (PO) - REMOVED
    // case 'po':
    //     include 'views/inventory/po_list.php';
    //     break;
    // case 'po_create':
    //     include 'views/inventory/po_form.php';
    //     break;
    // case 'po_receive':
    //     include 'views/inventory/po_receive.php';
    //     break;
    case 'booking':
        include 'views/inventory/booking_list.php';
        break;
    case 'booking_edit':
        include 'views/inventory/booking_edit.php';
        break;
    case 'hc_distribution':
        include 'views/inventory/hc_distribution.php';
        break;
    // Pemakaian BHP
    case 'pemakaian_bhp_list':
        include 'views/inventory/pemakaian_bhp_list.php';
        break;

    case 'bhp_lokal':
        include 'views/inventory/bhp_lokal.php';
        break;

    case 'monthly_summary':
        include 'views/laporan/monthly_summary.php';
        break;

    // Pengaturan
    case 'settings_integrasi':
        include 'views/settings/integrasi.php';
        break;
    case 'odoo_format_config':
        include 'views/settings/odoo_format_config.php';
        break;
    case 'mcu_realization':
        include 'views/mcu/realization.php';
        break;
    case 'qr_verify_rab':
        include 'api/verify_request.php';
        break;

    // Laporan
    case 'laporan_transaksi':
        include 'views/laporan/transaksi.php';
        break;
    case 'profile':
        include 'views/auth/profile.php';
        break;
    default:
        include 'views/dashboard/index.php';
        break;
}

if (!in_array($page, $public_pages) && !isset($_GET['layout'])) {
    include 'views/layouts/footer.php';
}
?>
