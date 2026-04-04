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
        // Global DataTable defaults
        $.extend(true, $.fn.dataTable.defaults, {
            "language": {
                "emptyTable": "Tidak ada data yang tersedia pada tabel ini",
                "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
                "infoEmpty": "Menampilkan 0 sampai 0 dari 0 entri",
                "infoFiltered": "(disaring dari _MAX_ entri keseluruhan)",
                "lengthMenu": "Tampilkan _MENU_ entri",
                "loadingRecords": "Sedang memuat...",
                "processing": "Sedang memproses...",
                "search": "Cari:",
                "zeroRecords": "Tidak ditemukan data yang sesuai",
                "paginate": {
                    "first": "Pertama",
                    "last": "Terakhir",
                    "next": "<i class='fas fa-chevron-right'></i>",
                    "previous": "<i class='fas fa-chevron-left'></i>"
                }
            }
        });

        // Init DataTable - Newest first (index 1 is Tanggal/Created At)
        $('.datatable').DataTable({
            "order": [[ 1, "desc" ]]
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
    
    // Restore sidebar state from localStorage
    if (localStorage.getItem('sidebarActive') === 'true') {
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
