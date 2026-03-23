<?php
check_role(['super_admin']);

$message = '';

// Fetch Clinics for dropdown
$clinics = $conn->query("SELECT * FROM klinik WHERE status='active'");
$clinic_options = [];
while ($c = $clinics->fetch_assoc()) {
    $clinic_options[] = $c;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? '';
    $username = $_POST['username'];
    $nama_lengkap = $_POST['nama_lengkap'];
    $role = $_POST['role'];
    $klinik_id = $_POST['klinik_id'] ?: NULL;
    $status = $_POST['status'];
    $password = $_POST['password'];

    if ($id) {
        $sql = "UPDATE users SET username=?, nama_lengkap=?, role=?, klinik_id=?, status=?";
        $params = [$username, $nama_lengkap, $role, $klinik_id, $status];
        $types = "sssis";

        if (!empty($password)) {
            $sql .= ", password=?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
            $types .= "s";
        }
        $sql .= " WHERE id=?";
        $params[] = $id;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">User berhasil diupdate.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
    } else {
        if (empty($password)) {
            $message = '<div class="alert alert-danger">Password wajib diisi untuk user baru.</div>';
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, klinik_id, status) VALUES (?, ?, ?, ?, ?, ?)");
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bind_param("ssssis", $username, $hashed_password, $nama_lengkap, $role, $klinik_id, $status);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">User berhasil ditambahkan.</div>';
            } else {
                $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
            }
        }
    }
}

if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    // Prevent deleting self
    if ($id == $_SESSION['user_id']) {
         $message = '<div class="alert alert-danger">Tidak bisa menghapus akun sendiri.</div>';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">User berhasil dihapus.</div>';
        } else {
            $message = '<div class="alert alert-danger">Gagal menghapus user.</div>';
        }
    }
}

$result = $conn->query("SELECT u.*, k.nama_klinik FROM users u LEFT JOIN klinik k ON u.klinik_id = k.id ORDER BY u.id DESC");
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-users me-2"></i>Data User
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Data User</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <button class="btn shadow-sm text-white px-4" style="background-color: #204EAB;" data-bs-toggle="modal" data-bs-target="#modalUser" onclick="resetForm()">
                <i class="fas fa-plus me-2"></i>Tambah User
            </button>
        </div>
    </div>
    </button>
</div>

<?= $message ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Role</th>
                        <th>Klinik</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                        <td><span class="badge bg-info"><?= str_replace('_', ' ', strtoupper($row['role'])) ?></span></td>
                        <td><?= htmlspecialchars($row['nama_klinik'] ?? '-') ?></td>
                        <td>
                            <span class="badge <?= $row['status'] == 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info text-white" 
                                onclick='editUser(<?= json_encode($row) ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($row['id'] != $_SESSION['user_id']): ?>
                            <a href="index.php?page=users&delete_id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirm('Yakin ingin menghapus?')">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalUser" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Tambah User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="user_id">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah password">
                        <small class="text-muted" id="password_hint">Wajib diisi untuk user baru.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="nama_lengkap" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="role" class="form-select" onchange="toggleKlinik()" required>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin_gudang">Admin Gudang</option>
                            <option value="admin_klinik">Admin Klinik</option>
                            <option value="cs">CS</option>
                            <option value="b2b_ops">B2B Ops</option>
                            <option value="petugas_hc">Petugas HC</option>
                        </select>
                    </div>
                    <div class="mb-3" id="klinik_group" style="display:none;">
                        <label class="form-label">Klinik</label>
                        <select name="klinik_id" id="klinik_id" class="form-select">
                            <option value="">- Pilih Klinik -</option>
                            <?php foreach ($clinic_options as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= $c['nama_klinik'] ?></option>
                            <?php endforeach; ?>
                        </select>
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
function toggleKlinik() {
    var role = document.getElementById('role').value;
    var klinikGroup = document.getElementById('klinik_group');
    if (role === 'admin_klinik' || role === 'petugas_hc') {
        klinikGroup.style.display = 'block';
        document.getElementById('klinik_id').required = true;
    } else {
        klinikGroup.style.display = 'none';
        document.getElementById('klinik_id').required = false;
        document.getElementById('klinik_id').value = '';
    }
}

function editUser(data) {
    document.getElementById('modalTitle').innerText = 'Edit User';
    document.getElementById('user_id').value = data.id;
    document.getElementById('username').value = data.username;
    document.getElementById('nama_lengkap').value = data.nama_lengkap;
    document.getElementById('role').value = data.role;
    document.getElementById('klinik_id').value = data.klinik_id || '';
    document.getElementById('status').value = data.status;
    document.getElementById('password_hint').innerText = 'Isi hanya jika ingin mengubah password.';
    
    toggleKlinik();
    var modal = new bootstrap.Modal(document.getElementById('modalUser'));
    modal.show();
}

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Tambah User';
    document.getElementById('user_id').value = '';
    document.getElementById('username').value = '';
    document.getElementById('password').value = '';
    document.getElementById('nama_lengkap').value = '';
    document.getElementById('role').value = 'admin_klinik';
    document.getElementById('klinik_id').value = '';
    document.getElementById('status').value = 'active';
    document.getElementById('password_hint').innerText = 'Wajib diisi untuk user baru.';
    
    toggleKlinik();
}
</script>

</div> <!-- End container-fluid -->
