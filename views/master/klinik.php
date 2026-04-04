<?php
check_role(['super_admin']);

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();
    $id = $_POST['id'] ?? '';
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $del_id = (int)($_POST['delete_id'] ?? 0);
        if ($del_id > 0) {
            $stmt = $conn->prepare("DELETE FROM klinik WHERE id=?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Klinik berhasil dihapus.</div>';
            } else {
                $message = '<div class="alert alert-danger">Gagal menghapus klinik. Mungkin sedang digunakan.</div>';
            }
        }
    } else {
    $kode_klinik = $_POST['kode_klinik'];
    $nama_klinik = $_POST['nama_klinik'];
    $kode_homecare = $_POST['kode_homecare'] ?? '';
    $alamat = $_POST['alamat'];
    $status = $_POST['status'];

    if ($id) {
        $stmt = $conn->prepare("UPDATE klinik SET kode_klinik=?, kode_homecare=?, nama_klinik=?, alamat=?, status=? WHERE id=?");
        $stmt->bind_param("sssssi", $kode_klinik, $kode_homecare, $nama_klinik, $alamat, $status, $id);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Klinik berhasil diupdate.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO klinik (kode_klinik, kode_homecare, nama_klinik, alamat, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $kode_klinik, $kode_homecare, $nama_klinik, $alamat, $status);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Klinik berhasil ditambahkan.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
    }
    }
}

$result = $conn->query("SELECT * FROM klinik ORDER BY id DESC");
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-hospital me-2"></i>Data Klinik
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Data Klinik</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <button class="btn shadow-sm text-white px-4" style="background-color: #204EAB;" data-bs-toggle="modal" data-bs-target="#modalKlinik" onclick="resetForm()">
                <i class="fas fa-plus me-2"></i>Tambah Klinik
            </button>
        </div>
    </div>
</div>

<?= $message ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Kode Klinik</th>
                        <th>Kode HC</th>
                        <th>Nama Klinik</th>
                        <th>Alamat</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['kode_klinik']) ?></td>
                        <td><?= htmlspecialchars($row['kode_homecare'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['nama_klinik']) ?></td>
                        <td><?= htmlspecialchars($row['alamat']) ?></td>
                        <td>
                            <span class="badge <?= $row['status'] == 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info text-white" 
                                onclick='editKlinik(<?= json_encode($row) ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus?')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalKlinik" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Tambah Klinik</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="klinik_id">
                    <div class="mb-3">
                        <label class="form-label">Kode Klinik</label>
                        <input type="text" name="kode_klinik" id="kode_klinik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kode Homecare</label>
                        <input type="text" name="kode_homecare" id="kode_homecare" class="form-control" placeholder="Kosongkan jika tidak ada layanan HC">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama</label>
                        <input type="text" name="nama_klinik" id="nama_klinik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alamat</label>
                        <textarea name="alamat" id="alamat" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary-custom">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editKlinik(data) {
    document.getElementById('modalTitle').innerText = 'Edit Klinik';
    document.getElementById('klinik_id').value = data.id;
    document.getElementById('kode_klinik').value = data.kode_klinik;
    document.getElementById('kode_homecare').value = data.kode_homecare || '';
    document.getElementById('nama_klinik').value = data.nama_klinik;
    document.getElementById('alamat').value = data.alamat;
    document.getElementById('status').value = data.status;
    
    var modal = new bootstrap.Modal(document.getElementById('modalKlinik'));
    modal.show();
}

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Tambah Klinik';
    document.getElementById('klinik_id').value = '';
    document.getElementById('kode_klinik').value = '';
    document.getElementById('kode_homecare').value = '';
    document.getElementById('nama_klinik').value = '';
    document.getElementById('alamat').value = '';
    document.getElementById('status').value = 'active';
}
</script>

</div> <!-- End container-fluid -->
