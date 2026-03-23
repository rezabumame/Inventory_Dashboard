<?php
check_role(['super_admin']);

// Fetch Groups
$groups = $conn->query("
    SELECT 
        g.id,
        g.nama_pemeriksaan,
        COUNT(d.id) AS total_items
    FROM pemeriksaan_grup g
    LEFT JOIN pemeriksaan_grup_detail d ON d.pemeriksaan_grup_id = g.id
    GROUP BY g.id, g.nama_pemeriksaan
    ORDER BY g.nama_pemeriksaan ASC
");

// Fetch Barang for Dropdown
$barangs = $conn->query("SELECT id, kode_barang, nama_barang, satuan FROM barang WHERE kode_barang IS NOT NULL AND kode_barang <> '' ORDER BY nama_barang ASC");
$barang_opts = [];
while($b = $barangs->fetch_assoc()) $barang_opts[] = $b;
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-stethoscope me-2"></i>Daftar Pemeriksaan
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Daftar Pemeriksaan</li>
                </ol>
            </nav>
        </div>
    </div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Daftar Pemeriksaan</h5>
                <button class="btn btn-sm btn-primary-custom" type="button" id="btnNewExam">
                    <i class="fas fa-plus"></i> Baru
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" id="examTable">
                        <thead class="table-light">
                            <tr>
                                <th>Nama Pemeriksaan</th>
                                <th width="160" class="text-center">Total Item</th>
                                <th width="220">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($g = $groups->fetch_assoc()): ?>
                                <tr data-grup-id="<?= (int)$g['id'] ?>" data-grup-nama="<?= htmlspecialchars($g['nama_pemeriksaan'], ENT_QUOTES) ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($g['nama_pemeriksaan']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border" data-role="total-items"><?= (int)$g['total_items'] ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary btnDetail">
                                                <i class="fas fa-list"></i> Detail
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning btnEdit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btnDelete">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetailGrup" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailTitle">Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="detailGrupId" value="">
                <h6 class="mb-2">Item Consumable / Obat</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th width="80">Qty</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody id="detailItemsBody"></tbody>
                    </table>
                </div>

                <hr>
                <h6 class="mb-2">Tambah Item</h6>
                <form class="row g-2" id="formAddMapping">
                    <div class="col-md-8">
                        <select name="barang_id" class="form-select form-select-sm select2" required id="detailBarangId">
                            <option value="">- Pilih Barang -</option>
                            <?php foreach($barang_opts as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['kode_barang']) ?> - <?= htmlspecialchars($b['nama_barang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="qty" class="form-control form-control-sm" placeholder="Qty" required min="1" value="1" id="detailQty">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-success w-100">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGrup" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formGrup">
                <input type="hidden" name="id" id="grupId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="grupModalTitle">Buat Pemeriksaan Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nama Pemeriksaan</label>
                        <input type="text" name="nama_pemeriksaan" class="form-control" required id="grupNama">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary-custom">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div> <!-- End container-fluid -->

<script>
function getRowByGrupId(grupId) {
    return $('#examTable tbody tr[data-grup-id="' + grupId + '"]');
}

function setRowTotalItems(grupId, total) {
    const row = getRowByGrupId(grupId);
    row.find('[data-role="total-items"]').text(total);
}

function loadDetail(grupId) {
    $('#detailItemsBody').html('<tr><td colspan="3" class="text-center text-muted py-3">Memuat...</td></tr>');
    $.ajax({
        url: 'api/ajax_pemeriksaan_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { grup_id: grupId },
        success: function(res) {
            if (!res || !res.success) {
                $('#detailItemsBody').html('<tr><td colspan="3" class="text-center text-danger py-3">' + (res && res.message ? res.message : 'Gagal memuat') + '</td></tr>');
                return;
            }
            $('#detailGrupId').val(res.grup.id);
            $('#detailTitle').text('Detail: ' + res.grup.nama_pemeriksaan);
            const rows = [];
            if (Array.isArray(res.details) && res.details.length > 0) {
                res.details.forEach(function(d) {
                    const itemText = (d.odoo_product_id ? d.odoo_product_id : '') + ' - ' + (d.nama_barang ? d.nama_barang : '') + ' (' + (d.satuan ? d.satuan : '') + ')';
                    rows.push(
                        '<tr>' +
                            '<td>' + $('<div>').text(itemText).html() + '</td>' +
                            '<td class="text-center fw-semibold">' + d.qty_per_pemeriksaan + '</td>' +
                            '<td class="text-center">' +
                                '<button type="button" class="btn btn-xs btn-danger btnDeleteDetail" data-detail-id="' + d.id + '"><i class="fas fa-trash"></i></button>' +
                            '</td>' +
                        '</tr>'
                    );
                });
            } else {
                rows.push('<tr><td colspan="3" class="text-center text-muted py-3">Belum ada mapping item.</td></tr>');
            }
            $('#detailItemsBody').html(rows.join(''));
            setRowTotalItems(res.grup.id, res.total_items);
        },
        error: function() {
            $('#detailItemsBody').html('<tr><td colspan="3" class="text-center text-danger py-3">Gagal memuat</td></tr>');
        }
    });
}

$(document).ready(function() {
    $('#btnNewExam').on('click', function() {
        $('#grupModalTitle').text('Buat Pemeriksaan Baru');
        $('#grupId').val('');
        $('#grupNama').val('');
        new bootstrap.Modal(document.getElementById('modalGrup')).show();
    });

    $('#examTable').on('click', '.btnEdit', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        const nama = tr.data('grup-nama');
        $('#grupModalTitle').text('Edit Pemeriksaan');
        $('#grupId').val(id);
        $('#grupNama').val(nama);
        new bootstrap.Modal(document.getElementById('modalGrup')).show();
    });

    $('#examTable').on('click', '.btnDetail', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        loadDetail(id);
        new bootstrap.Modal(document.getElementById('modalDetailGrup')).show();
    });

    $('#examTable').on('click', '.btnDelete', function() {
        const tr = $(this).closest('tr');
        const id = tr.data('grup-id');
        const nama = tr.data('grup-nama');
        if (!confirm('Hapus pemeriksaan "' + nama + '" beserta mapping itemnya?')) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_delete.php',
            method: 'POST',
            dataType: 'json',
            data: { id: id },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menghapus');
                    return;
                }
                tr.remove();
            },
            error: function() {
                alert('Gagal menghapus');
            }
        });
    });

    $('#formGrup').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: 'api/ajax_pemeriksaan_save.php',
            method: 'POST',
            dataType: 'json',
            data: $(this).serialize(),
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan');
                    return;
                }
                const id = res.id;
                const nama = res.nama_pemeriksaan;
                const existing = getRowByGrupId(id);
                if (existing.length) {
                    existing.data('grup-nama', nama);
                    existing.attr('data-grup-nama', nama);
                    existing.find('td').first().text(nama);
                } else {
                    const rowHtml =
                        '<tr data-grup-id="' + id + '" data-grup-nama="' + $('<div>').text(nama).html() + '">' +
                            '<td class="fw-semibold">' + $('<div>').text(nama).html() + '</td>' +
                            '<td class="text-center"><span class="badge bg-light text-dark border" data-role="total-items">0</span></td>' +
                            '<td><div class="d-flex gap-2">' +
                                '<button type="button" class="btn btn-sm btn-outline-primary btnDetail"><i class="fas fa-list"></i> Detail</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-warning btnEdit"><i class="fas fa-edit"></i> Edit</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-danger btnDelete"><i class="fas fa-trash"></i> Hapus</button>' +
                            '</div></td>' +
                        '</tr>';
                    $('#examTable tbody').append(rowHtml);
                }
                bootstrap.Modal.getInstance(document.getElementById('modalGrup')).hide();
            },
            error: function() {
                alert('Gagal menyimpan');
            }
        });
    });

    $('#formAddMapping').on('submit', function(e) {
        e.preventDefault();
        const grupId = $('#detailGrupId').val();
        const barangId = $('#detailBarangId').val();
        const qty = $('#detailQty').val();
        if (!grupId || !barangId || !qty) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_detail_save.php',
            method: 'POST',
            dataType: 'json',
            data: { grup_id: grupId, barang_id: barangId, qty: qty },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menyimpan mapping');
                    return;
                }
                loadDetail(grupId);
                $('#detailBarangId').val('').trigger('change');
                $('#detailQty').val(1);
            },
            error: function() {
                alert('Gagal menyimpan mapping');
            }
        });
    });

    $('#detailItemsBody').on('click', '.btnDeleteDetail', function() {
        const detailId = $(this).data('detail-id');
        const grupId = $('#detailGrupId').val();
        if (!confirm('Hapus item mapping ini?')) return;
        $.ajax({
            url: 'api/ajax_pemeriksaan_detail_delete.php',
            method: 'POST',
            dataType: 'json',
            data: { detail_id: detailId },
            success: function(res) {
                if (!res || !res.success) {
                    alert(res && res.message ? res.message : 'Gagal menghapus');
                    return;
                }
                loadDetail(grupId);
            },
            error: function() {
                alert('Gagal menghapus');
            }
        });
    });
});
</script>
