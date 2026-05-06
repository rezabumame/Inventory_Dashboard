<?php
if (!defined('APP_NAME')) { exit; }

$u_role = $_SESSION['role'];
$u_klinik_id = $_SESSION['klinik_id'] ?? 0;

// Access check
$allowed_roles = ['super_admin', 'admin_gudang', 'admin_klinik', 'spv_klinik'];
if (!in_array($u_role, $allowed_roles)) {
    echo "<div class='alert alert-danger'>Access Denied.</div>";
    exit;
}

$can_manage_master = in_array($u_role, ['super_admin', 'admin_klinik']);
$can_input_stok = in_array($u_role, ['super_admin', 'admin_klinik']);
$can_adjust = in_array($u_role, ['super_admin', 'admin_klinik']);
$can_approve = in_array($u_role, ['super_admin', 'spv_klinik']);
$is_view_only = ($u_role === 'admin_gudang');
$is_admin_gudang_or_sa = in_array($u_role, ['super_admin', 'admin_gudang']);

// Fetch clinics for filtering & selection
$kliniks = [];
$res_k = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
while ($res_k && ($row = $res_k->fetch_assoc())) {
    $kliniks[] = $row;
}

?>

<!-- Load Moment.js for date formatting -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>

<div class="row mb-2 align-items-center">
    <div class="col">
        <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
            <i class="fas fa-box-open me-2"></i>BHP Non-Odoo
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">BHP Non-Odoo</li>
            </ol>
        </nav>
    </div>
</div>

<div class="row g-4">
    <!-- Tabs Navigation -->
    <div class="col-12">
        <ul class="nav nav-pills mb-4" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-4" id="pills-stok-tab" data-bs-toggle="pill" data-bs-target="#pills-stok" type="button" role="tab">
                    <i class="fas fa-boxes me-2"></i>Stok Saat Ini
                </button>
            </li>
            <li class="nav-item ms-2" role="presentation">
                <button class="nav-link rounded-pill px-4" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button" role="tab">
                    <i class="fas fa-history me-2"></i>Riwayat & Approval
                    <?php 
                    // Badge for pending approvals
                    $q_pend = $conn->query("SELECT COUNT(*) as cnt FROM inventory_history_lokal WHERE status = 'pending' " . ($u_role !== 'super_admin' ? "AND klinik_id = $u_klinik_id" : ""));
                    $pend_cnt = $q_pend->fetch_assoc()['cnt'] ?? 0;
                    if ($pend_cnt > 0): 
                    ?>
                    <span class="badge bg-danger ms-2"><?= $pend_cnt ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <style>
            .nav-pills .nav-link { color: #6c757d; font-weight: 500; transition: all 0.3s ease; }
            .nav-pills .nav-link:hover { background-color: rgba(32, 78, 171, 0.05); }
            .nav-pills .nav-link.active { background-color: #204EAB !important; color: white !important; box-shadow: 0 4px 12px rgba(32, 78, 171, 0.2); }
            
            .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
            
            /* Table Styling */
            .card { border-radius: 16px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03); overflow: hidden; }
            .datatable { border-collapse: separate !important; border-spacing: 0 8px !important; margin-top: 0 !important; }
            .datatable thead th { 
                background-color: #204EAB !important; 
                color: white !important; 
                font-weight: 600; 
                text-transform: uppercase; 
                font-size: 0.75rem; 
                letter-spacing: 0.8px; 
                padding: 15px !important;
                border: none !important;
            }
            .datatable thead th:first-child { border-radius: 8px 0 0 8px !important; }
            .datatable thead th:last-child { border-radius: 0 8px 8px 0 !important; }
            
            .datatable tbody tr { 
                background-color: white !important; 
                box-shadow: 0 2px 6px rgba(0,0,0,0.02); 
                transition: all 0.2s ease;
                border-radius: 8px;
            }
            .datatable tbody tr:hover { 
                transform: translateY(-2px); 
                box-shadow: 0 5px 15px rgba(0,0,0,0.05); 
                background-color: #fcfdfe !important;
            }
            .datatable tbody td { 
                padding: 16px 15px !important; 
                border-top: 1px solid rgba(0,0,0,0.03) !important;
                border-bottom: 1px solid rgba(0,0,0,0.03) !important;
                color: #444;
            }
            .datatable tbody td:first-child { border-left: 1px solid rgba(0,0,0,0.03) !important; border-radius: 8px 0 0 8px !important; }
            .datatable tbody td:last-child { border-right: 1px solid rgba(0,0,0,0.03) !important; border-radius: 0 8px 8px 0 !important; }
            
            .btn-primary-custom { background: linear-gradient(135deg, #204EAB, #1a3e8a); color: white; border: none; font-weight: 500; }
            .btn-primary-custom:hover { background: linear-gradient(135deg, #1a3e8a, #15326d); color: white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(32, 78, 171, 0.25); }
            
            .badge-subtle { padding: 5px 10px; font-weight: 600; border-radius: 6px; }
            
            /* DataTable Search & Pagination Styling */
            .dataTables_filter input { 
                border-radius: 10px !important; 
                padding: 6px 12px !important; 
                border: 1px solid rgba(0,0,0,0.1) !important;
                background-color: #f8f9fa !important;
            }
            .page-item.active .page-link { background-color: #204EAB !important; border-color: #204EAB !important; }
            .page-link { border-radius: 8px !important; margin: 0 3px !important; border: none !important; color: #204EAB !important; }

            /* Premium Filter Style */
            .filter-card-header { padding: 1.25rem 1.5rem !important; }
            .filter-group {
                display: flex;
                align-items: center;
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                padding: 4px 15px;
                border-radius: 50px;
                transition: all 0.2s ease;
                box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            }
            .filter-group:hover { border-color: #dee2e6; }
            .filter-group i { color: #204EAB; opacity: 0.8; font-size: 0.85rem; }
            .filter-group select, .filter-group input {
                border: none !important;
                background: transparent !important;
                font-size: 0.85rem;
                padding: 4px 8px !important;
                color: #495057;
                box-shadow: none !important;
            }
            .filter-group select:focus, .filter-group input:focus { outline: none !important; }
            .filter-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #999; margin-bottom: 4px; font-weight: 700; margin-left: 12px; }
        </style>

        <div class="tab-content" id="pills-tabContent">
            <!-- Stock Tab (Default) -->
            <div class="tab-pane fade show active" id="pills-stok" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-0 filter-card-header">
                        <div class="row align-items-center g-3">
                            <div class="col">
                                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-list text-primary me-2"></i>Daftar Stok Per Klinik</h6>
                            </div>
                            <div class="col-auto d-flex align-items-center gap-3">
                                <?php if ($is_admin_gudang_or_sa): ?>
                                <div>
                                    <div class="filter-label">Filter Klinik</div>
                                    <div class="filter-group">
                                        <i class="fas fa-hospital"></i>
                                        <select id="filterKlinikStok" style="width: 200px;">
                                            <option value="">Semua Klinik</option>
                                            <?php foreach ($kliniks as $k): ?>
                                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div>
                                    <div class="filter-label">&nbsp;</div>
                                    <?php if ($can_input_stok): ?>
                                    <button type="button" class="btn btn-sm btn-primary-custom px-4 shadow-sm rounded-pill py-2" data-bs-toggle="modal" data-bs-target="#modalPullItem">
                                        <i class="fas fa-plus-circle me-1"></i> Tambah Item
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="tableStokLokal" class="table table-hover align-middle datatable" style="width:100%">
                                <thead class="bg-primary text-white">
                                    <tr>
                                        <th>ITEM</th>
                                        <th>KLINIK / LOKASI</th>
                                        <th>STOK AKHIR</th>
                                        <th>UPDATE TERAKHIR</th>
                                        <th width="150" class="text-end">AKSI</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pills-history" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-0 filter-card-header">
                        <div class="row g-3">
                            <div class="col-12 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-history text-primary me-2"></i>Riwayat Transaksi Stok</h6>
                                <button type="button" id="btnExportHistory" class="btn btn-sm btn-success px-4 shadow-sm rounded-pill py-2">
                                    <i class="fas fa-file-excel me-1"></i> Export Excel
                                </button>
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <?php if ($is_admin_gudang_or_sa): ?>
                                    <div>
                                        <div class="filter-label">Klinik</div>
                                        <div class="filter-group">
                                            <i class="fas fa-hospital"></i>
                                            <select id="filterKlinikHistory" style="width: 180px;">
                                                <option value="">Semua Klinik</option>
                                                <?php foreach ($kliniks as $k): ?>
                                                <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <div class="filter-label">Rentang Tanggal</div>
                                        <div class="filter-group">
                                            <i class="fas fa-calendar-alt"></i>
                                            <input type="date" id="filterDateStart" style="width: 135px;" value="<?= date('Y-m-01') ?>">
                                            <span class="text-muted mx-1">s/d</span>
                                            <input type="date" id="filterDateEnd" style="width: 135px;" value="<?= date('Y-m-d') ?>">
                                        </div>
                                    </div>

                                    <div>
                                        <div class="filter-label">Tipe Transaksi</div>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="filter-group">
                                                <i class="fas fa-tags"></i>
                                                <select id="filterType" style="width: 130px;">
                                                    <option value="">Semua Tipe</option>
                                                    <option value="tambah">Tambah</option>
                                                    <option value="kurang">Kurang</option>
                                                    <option value="pakai">Pakai</option>
                                                </select>
                                            </div>
                                            <button type="button" id="btnResetHistoryFilter" class="btn btn-sm btn-light border shadow-sm px-3 rounded-pill text-muted">
                                                <i class="fas fa-undo-alt me-1"></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="tableHistoryLokal" class="table table-hover align-middle datatable" style="width:100%">
                                <thead class="bg-primary text-white">
                                    <tr>
                                        <th>TANGGAL</th>
                                        <th>ITEM</th>
                                        <th>KLINIK</th>
                                        <th>TIPE</th>
                                        <th>QTY</th>
                                        <th>STATUS</th>
                                        <th>KETERANGAN</th>
                                        <th class="text-end">AKSI</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pull Item from Master Database -->
<div class="modal fade" id="modalPullItem" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary-custom">Tarik Item dari Database Barang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="fas fa-info-circle me-1"></i> Pilih item di bawah ini untuk menambahkan stok ke klinik Anda.
                </div>
                <div class="table-responsive">
                    <table id="tableMasterItemPull" class="table table-hover align-middle datatable" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th width="50">ID</th>
                                <th>Nama Item</th>
                                <th>UOM</th>
                                <th width="120" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah/Adjust Stok -->
<div class="modal fade" id="modalStockAction" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form id="formStockAction">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary-custom" id="stockActionTitle">Tambah Stok</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <input type="hidden" name="barang_lokal_id" id="sa_barang_id">
                    <input type="hidden" name="tipe" id="sa_tipe" value="tambah">
                    
                    <div class="p-3 bg-light rounded-3 mb-3">
                        <div class="x-small text-muted text-uppercase fw-bold mb-1">Item</div>
                        <div class="fw-bold" id="sa_item_name">-</div>
                        <div class="row mt-2 g-2">
                            <div class="col-6">
                                <div class="x-small text-muted">Stok Sekarang</div>
                                <div class="fw-bold" id="sa_current_qty">0</div>
                            </div>
                            <div class="col-6">
                                <div class="x-small text-muted">Klinik</div>
                                <?php if ($is_admin_gudang_or_sa): ?>
                                <select name="klinik_id" id="sa_klinik_id" class="form-select form-select-sm mt-1" required>
                                    <option value="">Pilih Klinik...</option>
                                    <?php foreach ($kliniks as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <input type="hidden" name="klinik_id" value="<?= $u_klinik_id ?>">
                                <div class="fw-bold" id="sa_klinik_name_fixed">-</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold" id="labelQtyAction">Jumlah Tambah</label>
                        <div class="input-group">
                            <input type="number" name="qty" id="inputQtyStock" class="form-control" step="0.01" required>
                            <span class="input-group-text sa_uom_label">UOM</span>
                        </div>
                        <div class="form-text x-small text-info" id="sa_note_adjust" style="display:none;">
                            <i class="fas fa-info-circle me-1"></i> Gunakan angka negatif (misal: -5) untuk mengurangi stok.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label small fw-bold">Keterangan / Alasan</label>
                        <textarea name="keterangan" class="form-control" rows="2" placeholder="Contoh: Pembelian lokal klinik atau Penyesuaian stok fisik"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary-custom px-4" id="btnSubmitStock">Proses</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // 0. Tab Persistence via Hash
    var hash = window.location.hash;
    if (hash) {
        $('.nav-link[data-bs-target="' + hash + '"]').tab('show');
    }

    $('button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
        window.location.hash = $(e.target).attr('data-bs-target');
    });

    // 1. Master Item Pull Modal
    var tableMaster = $('#tableMasterItemPull').DataTable({
        ajax: 'api/ajax_bhp_lokal.php?action=list_master',
        columns: [
            { data: 'id' },
            { data: 'nama_item', render: function(data) { return '<b>' + data + '</b>'; } },
            { data: 'uom', render: function(data) { return '<span class="badge bg-light text-dark border">' + data + '</span>'; } },
            { 
                data: null, 
                orderable: false,
                className: 'text-end',
                render: function(data) {
                    return `
                        <button class="btn btn-sm btn-primary-custom btn-add-stok-from-master shadow-sm" data-id="${data.id}" data-name="${data.nama_item}" data-uom="${data.uom}">
                            <i class="fas fa-plus me-1"></i> Pilih & Isi Stok
                        </button>
                    `;
                }
            }
        ]
    });

    // 2. Current Stock Table
    var tableStok = $('#tableStokLokal').DataTable({
        ajax: {
            url: 'api/ajax_bhp_lokal.php?action=list_stok',
            data: function(d) { d.klinik_id = $('#filterKlinikStok').val(); }
        },
        columns: [
            { data: 'nama_item', render: function(data) { return '<b>' + data + '</b>'; } },
            { data: 'nama_klinik' },
            { 
                data: 'qty', 
                render: function(data, type, row) { 
                    return '<span class="fw-bold h6 mb-0">' + parseFloat(data).toLocaleString() + '</span> <small class="text-muted">' + row.uom + '</small>'; 
                } 
            },
            { data: 'updated_at', render: function(data) { return data ? moment(data).format('DD MMM YYYY HH:mm') : '-'; } },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: function(data) {
                    if (<?= json_encode($is_view_only) ?>) return '-';
                    return `
                        <button class="btn btn-sm btn-outline-warning btn-adj-stok shadow-sm" 
                            data-id="${data.barang_lokal_id}" 
                            data-klinik-id="${data.klinik_id}" 
                            data-klinik-name="${data.nama_klinik}" 
                            data-name="${data.nama_item}" 
                            data-qty="${data.qty}" 
                            data-uom="${data.uom}" 
                            title="Adjust Stok">
                            <i class="fas fa-sliders-h me-1"></i> Adjust
                        </button>
                    `;
                }
            }
        ]
    });

    // 3. History Table
    var tableHistory = $('#tableHistoryLokal').DataTable({
        ajax: {
            url: 'api/ajax_bhp_lokal.php?action=list_history',
            data: function(d) { 
                d.klinik_id = $('#filterKlinikHistory').val(); 
                d.start_date = $('#filterDateStart').val();
                d.end_date = $('#filterDateEnd').val();
                d.tipe = $('#filterType').val();
            }
        },
        order: [[0, 'desc']],
        columns: [
            { data: 'created_at', render: function(data) { return moment(data).format('DD/MM/YY HH:mm'); } },
            { data: 'nama_item' },
            { data: 'nama_klinik' },
            { 
                data: 'tipe', 
                render: function(data, type, row) {
                    var label = data.toUpperCase();
                    if (label === 'ADJUST') {
                        label = (parseFloat(row.qty_perubahan) >= 0 ? 'TAMBAH' : 'KURANG');
                    }
                    var cls = label === 'TAMBAH' ? 'success' : (label === 'PAKAI' || label === 'KURANG' ? 'danger' : 'warning');
                    return '<span class="badge bg-' + cls + '-subtle text-' + cls + ' text-uppercase" style="font-size:0.7rem">' + label + '</span>';
                }
            },
            { 
                data: 'qty_perubahan', 
                className: 'text-center fw-bold',
                render: function(data, type, row) {
                    var val = parseFloat(data);
                    var sign = val > 0 ? '+' : ''; // Negative already has '-'
                    var color = val >= 0 ? 'text-success' : 'text-danger';
                    return '<span class="' + color + '">' + sign + val.toLocaleString() + '</span>';
                }
            },
            {
                data: 'status',
                render: function(data) {
                    var cls = data === 'completed' ? 'success' : (data === 'pending' ? 'info' : (data === 'approved' ? 'primary' : 'danger'));
                    return '<span class="badge bg-' + cls + ' rounded-pill" style="font-size:0.7rem">' + data + '</span>';
                }
            },
            { data: 'keterangan', render: function(data) { return '<small class="text-muted">' + (data || '-') + '</small>'; } },
            {
                data: null,
                className: 'text-end',
                render: function(data) {
                    if (data.status === 'pending' && <?= json_encode($can_approve) ?>) {
                        return `
                            <div class="btn-group">
                                <button class="btn btn-xs btn-success btn-approve" data-id="${data.id}"><i class="fas fa-check"></i></button>
                                <button class="btn btn-xs btn-danger btn-reject" data-id="${data.id}"><i class="fas fa-times"></i></button>
                            </div>
                        `;
                    }
                    return '-';
                }
            }
        ]
    });

    $('#filterKlinikStok').change(function() {
        tableStok.ajax.reload();
    });

    $('#filterKlinikHistory, #filterDateStart, #filterDateEnd, #filterType').change(function() {
        tableHistory.ajax.reload();
    });

    $('#btnResetHistoryFilter').click(function() {
        $('#filterKlinikHistory').val('');
        $('#filterDateStart').val('<?= date('Y-m-01') ?>');
        $('#filterDateEnd').val('<?= date('Y-m-d') ?>');
        $('#filterType').val('');
        tableHistory.ajax.reload();
    });

    $('#btnExportHistory').click(function() {
        var kid = $('#filterKlinikHistory').val();
        var sd = $('#filterDateStart').val();
        var ed = $('#filterDateEnd').val();
        var t = $('#filterType').val();
        window.location.href = `actions/export_bhp_lokal_history.php?klinik_id=${kid}&start_date=${sd}&end_date=${ed}&tipe=${t}`;
    });

    $('#sa_klinik_id').change(function() {
        var kid = $(this).val();
        var bid = $('#sa_barang_id').val();
        var uom = $('.sa_uom_label').first().text();
        if (kid && bid) {
            $('#sa_current_qty').text('Memuat...');
            $.get('api/ajax_bhp_lokal.php?action=get_current_stok&id=' + bid + '&klinik_id=' + kid, function(res) {
                $('#sa_current_qty').text(parseFloat(res.qty || 0).toLocaleString() + ' ' + uom);
            }, 'json');
        } else {
            $('#sa_current_qty').text('Pilih klinik untuk melihat stok');
        }
    });

    $(document).on('click', '.btn-add-stok-from-master', function() {
        var d = $(this).data();
        $('#modalPullItem').modal('hide');
        
        $('#sa_barang_id').val(d.id);
        $('#sa_tipe').val('tambah');
        $('#sa_item_name').text(d.name);
        $('.sa_uom_label').text(d.uom);
        $('#sa_current_qty').text('Memuat...');
        
        $('#stockActionTitle').text('Tambah Stok Awal/Baru');
        $('#labelQtyAction').text('Jumlah Tambah');
        $('#sa_note_adjust').hide();
        $('#inputQtyStock').attr('min', '0.01'); // Force positive for Tambah
        
        var u_klinik_id = <?= json_encode($u_klinik_id) ?>;
        var kid = $('#sa_klinik_id').val() || u_klinik_id;
        if (kid > 0) {
            $.get('api/ajax_bhp_lokal.php?action=get_current_stok&id=' + d.id + '&klinik_id=' + kid, function(res) {
                $('#sa_current_qty').text(parseFloat(res.qty || 0).toLocaleString() + ' ' + d.uom);
            }, 'json');
        }
        $('#sa_klinik_name_fixed').text('<?= htmlspecialchars($_SESSION['nama_klinik'] ?? '-') ?>');
        $('#modalStockAction').modal('show');
    });

    $(document).on('click', '.btn-adj-stok', function() {
        var d = $(this).data();
        $('#sa_barang_id').val(d.id);
        $('#sa_tipe').val('adjust');
        $('#sa_item_name').text(d.name);
        $('.sa_uom_label').text(d.uom);
        $('#sa_current_qty').text(parseFloat(d.qty || 0).toLocaleString() + ' ' + d.uom);
        
        $('#stockActionTitle').text('Adjust Stok (Approval SPV)');
        $('#labelQtyAction').text('Jumlah Penyesuaian (+/-)');
        $('#sa_note_adjust').show();
        $('#inputQtyStock').removeAttr('min'); // Allow negative for Adjust

        if (<?= json_encode($is_admin_gudang_or_sa) ?>) {
            $('#sa_klinik_id').val(d.klinikId).trigger('change');
            $('#sa_klinik_id').attr('disabled', true);
            if ($('#sa_klinik_id_hidden').length === 0) {
                $('#sa_klinik_id').after('<input type="hidden" name="klinik_id" id="sa_klinik_id_hidden">');
            }
            $('#sa_klinik_id_hidden').val(d.klinikId);
        } else {
            $('#sa_klinik_name_fixed').text(d.klinikName);
        }
        $('#modalStockAction').modal('show');
    });

    $('#modalStockAction').on('hidden.bs.modal', function() {
        $('#sa_klinik_id').attr('disabled', false);
        $('#sa_klinik_id_hidden').remove();
    });

    $('#formStockAction').submit(function(e) {
        e.preventDefault();
        var data = $(this).serialize();
        $.post('api/ajax_bhp_lokal.php?action=process_stock', data, function(res) {
            if (res.success) {
                $('#modalStockAction').modal('hide');
                tableStok.ajax.reload();
                tableHistory.ajax.reload();
                Swal.fire('Berhasil', res.message, 'success');
            } else {
                Swal.fire('Gagal', res.message, 'error');
            }
        }, 'json');
    });

    $(document).on('click', '.btn-approve, .btn-reject', function() {
        var id = $(this).data('id');
        var action = $(this).hasClass('btn-approve') ? 'approve' : 'reject';
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: action + " permohonan adjust stok ini.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, ' + action,
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('api/ajax_bhp_lokal.php?action=approval', { id: id, action: action }, function(res) {
                    if (res.success) {
                        tableStok.ajax.reload();
                        tableHistory.ajax.reload();
                        Swal.fire('Berhasil', res.message, 'success');
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                }, 'json');
            }
        });
    });
});
</script>
