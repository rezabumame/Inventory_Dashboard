<?php
check_role(['super_admin']);

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    require_csrf();
    
    if ($_POST['action'] == 'add_user') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $role = $_POST['role'];
        $klinik_id = ($_POST['klinik_id'] === '') ? null : (int)$_POST['klinik_id'];
        
        $stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, klinik_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $username, $password, $nama_lengkap, $role, $klinik_id);
        
        if ($stmt->execute()) {
            $success = "User berhasil ditambahkan.";
        } else {
            $error = "Gagal menambahkan user: " . $conn->error;
        }
    }
    
    if ($_POST['action'] == 'edit_user') {
        $id = (int)$_POST['id'];
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $role = $_POST['role'];
        $klinik_id = ($_POST['klinik_id'] === '') ? null : (int)$_POST['klinik_id'];
        
        $sql = "UPDATE users SET nama_lengkap = ?, role = ?, klinik_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $nama_lengkap, $role, $klinik_id, $id);
        
        if ($stmt->execute()) {
            // Update password if provided
            if (!empty($_POST['password'])) {
                $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $conn->query("UPDATE users SET password = '$pass' WHERE id = $id");
            }
            $success = "User berhasil diperbarui.";
        } else {
            $error = "Gagal memperbarui user.";
        }
    }
    
    if ($_POST['action'] == 'delete_user') {
        $id = (int)$_POST['id'];
        if ($id == $_SESSION['user_id']) {
            $error = "Anda tidak bisa menghapus akun sendiri.";
        } else {
            if ($conn->query("DELETE FROM users WHERE id = $id")) {
                $success = "User berhasil dihapus.";
            } else {
                $error = "Gagal menghapus user.";
            }
        }
    }
}

// Fetch Data
$users = [];
$res = $conn->query("SELECT u.*, k.nama_klinik FROM users u LEFT JOIN klinik k ON u.klinik_id = k.id ORDER BY u.nama_lengkap ASC");
while ($row = $res->fetch_assoc()) $users[] = $row;

$kliniks = [];
$res_k = $conn->query("SELECT id, nama_klinik FROM klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
while ($row = $res_k->fetch_assoc()) $kliniks[] = $row;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold" style="color: #204EAB;">Data User</h1>
    <button class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#modalAdd">
        <i class="fas fa-plus me-2"></i>Tambah User
    </button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nama Lengkap</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Klinik / Lokasi</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td>
                            <span class="badge bg-info text-dark rounded-pill px-3">
                                <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
                            </span>
                        </td>
                        <td><?= $u['nama_klinik'] ?: '<span class="text-muted fst-italic">Semua Lokasi / Gudang</span>' ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= $u['id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah User Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required onchange="toggleKlinik(this)">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin_gudang">Admin Gudang</option>
                            <option value="admin_klinik">Admin Klinik</option>
                            <option value="spv_klinik">SPV Klinik</option>
                            <option value="petugas_hc">Petugas HC</option>
                            <option value="cs">CS</option>
                            <option value="b2b_ops">B2B Ops</option>
                        </select>
                    </div>
                    <div class="mb-3 klinik-select" style="display:none;">
                        <label class="form-label">Klinik</label>
                        <select name="klinik_id" class="form-select">
                            <option value="">-- Pilih Klinik --</option>
                            <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div id="modalEdit" class="modal fade" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="edit_nama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" id="edit_username" class="form-control" readonly disabled>
                        <small class="text-muted">Username tidak dapat diubah.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="edit_role" class="form-select" required onchange="toggleKlinik(this)">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin_gudang">Admin Gudang</option>
                            <option value="admin_klinik">Admin Klinik</option>
                            <option value="spv_klinik">SPV Klinik</option>
                            <option value="petugas_hc">Petugas HC</option>
                            <option value="cs">CS</option>
                            <option value="b2b_ops">B2B Ops</option>
                        </select>
                    </div>
                    <div class="mb-3 klinik-select">
                        <label class="form-label">Klinik</label>
                        <select name="klinik_id" id="edit_klinik_id" class="form-select">
                            <option value="">-- Pilih Klinik --</option>
                            <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="id" id="delete_id">
</form>

<script>
function toggleKlinik(select) {
    const role = select.value;
    const modal = select.closest('.modal');
    const klinikDiv = modal.querySelector('.klinik-select');
    if (['admin_klinik', 'spv_klinik', 'petugas_hc', 'cs'].includes(role)) {
        klinikDiv.style.display = 'block';
    } else {
        klinikDiv.style.display = 'none';
        modal.querySelector('select[name="klinik_id"]').value = '';
    }
}

function editUser(u) {
    console.log("Editing user:", u);
    // Set values
    $('#edit_id').val(u.id);
    $('#edit_nama').val(u.nama_lengkap);
    $('#edit_username').val(u.username);
    
    // Set role and trigger change
    const roleSelect = document.getElementById('edit_role');
    $(roleSelect).val(u.role).trigger('change');
    
    // Force toggleKlinik to run immediately
    toggleKlinik(roleSelect);
    
    // Set klinik_id and trigger change
    const klinikSelect = document.getElementById('edit_klinik_id');
    $(klinikSelect).val(u.klinik_id || '').trigger('change');
    
    $('#modalEdit').modal('show');
}

function deleteUser(id) {
    if (confirm('Yakin ingin menghapus user ini?')) {
        $('#delete_id').val(id);
        $('#formDelete').submit();
    }
}
</script>
