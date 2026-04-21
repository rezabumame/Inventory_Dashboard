    </div> <!-- End Container Fluid -->
</div> <!-- End Main Content -->

<script>
    $(document).ready(function() {
        // Init DataTable
        $('.datatable').each(function() {
            const $t = $(this);
            
            // Skip if already initialized
            if ($.fn.DataTable.isDataTable($t)) {
                return;
            }
            
            // Count columns in header
            const colCount = $t.find('thead th').length;
            
            // Only init if table has columns
            if (colCount > 0) {
                // Determine default sort
                // Respect data-order-col attribute if present, else default to index 1 (date)
                let sortIdx = $t.data('order-col');
                if (sortIdx === undefined) {
                    sortIdx = colCount >= 2 ? 1 : 0;
                }
                
                const sortDir = $t.data('order-dir') || 'desc';
                
                $t.DataTable({
                    "order": [[ sortIdx, sortDir ]]
                });
            }
        });
        
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
    
    // Restore sidebar state from localStorage (only for desktop)
    const isMobile = window.innerWidth <= 768;
    if (!isMobile && localStorage.getItem('sidebarActive') === 'true') {
        sidebar.classList.add('active');
        if (mainContent) mainContent.classList.add('active');
        if (overlay) overlay.classList.add('active');
    }

    function toggleSidebar() {
        sidebar.classList.toggle('active');
        if (mainContent) mainContent.classList.toggle('active');
        if (overlay) overlay.classList.toggle('active');
        
        // Save state
        localStorage.setItem('sidebarActive', sidebar.classList.contains('active'));
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
</script>
</body>
</html>
