    </div> <!-- End Container Fluid -->
</div> <!-- End Main Content -->

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Init DataTable
        $('.datatable').DataTable();
        
        // Init Select2 globally for static selects
        $('.form-select').each(function() {
            // Skip if it's a template or already initialized
            if (!$(this).hasClass('no-select2') && !$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    width: '100%'
                });
            }
        });
    });

    // Sidebar Toggle Logic
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const overlay = document.getElementById('sidebar-overlay');
    
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        if (mainContent) mainContent.classList.toggle('active');
        if (overlay) overlay.classList.toggle('active');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            toggleSidebar();
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
    
    // Close sidebar on route change (optional, for SPA feeling if links didn't reload)
    // But since it reloads, it's fine.

    // PERSIST SIDEBAR SCROLL POSITION & COLLAPSE STATE
    const sidebarMenu = document.querySelector('.sidebar-menu');
    if (sidebarMenu) {
        // Restore scroll position
        const scrollPos = localStorage.getItem('sidebarScrollPos');
        if (scrollPos) {
            sidebarMenu.scrollTop = scrollPos;
        }
        
        // Save scroll position on scroll
        sidebarMenu.addEventListener('scroll', () => {
            localStorage.setItem('sidebarScrollPos', sidebarMenu.scrollTop);
        });
    }
</script>
<?php $odoo_auto_tick = in_array($_SESSION['role'] ?? '', ['super_admin', 'admin_gudang'], true); ?>
<script>
(() => {
    if (!<?= $odoo_auto_tick ? 'true' : 'false' ?>) return;
    let running = false;
    async function tick() {
        if (running) return;
        running = true;
        try {
            const res = await fetch('api/sync_odoo_schedule.php', { cache: 'no-store' });
            const data = await res.json();
            if (data && data.ran) {
                const params = new URLSearchParams(window.location.search);
                const page = params.get('page') || 'dashboard';
                if (page === 'stok_klinik' || document.getElementById('lastUpdateText')) {
                    setTimeout(() => window.location.reload(), 800);
                }
            }
        } catch (e) {
        } finally {
            running = false;
        }
    }
    setTimeout(tick, 8000);
    setInterval(tick, 60000);
})();
</script>
</body>
</html>
