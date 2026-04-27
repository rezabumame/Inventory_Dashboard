<?php
check_role(['super_admin']);

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    require_csrf();
    
    if ($_POST['action'] == 'add_user') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $role = $_POST['role'];
        $klinik_raw = trim((string)($_POST['klinik_id'] ?? ''));
        $klinik_id = $klinik_raw === '' ? null : (int)$klinik_raw;
        
        $stmt = $conn->prepare("INSERT INTO inventory_users (username, password, nama_lengkap, role, klinik_id) VALUES (?, ?, ?, ?, ?)");
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
        $klinik_raw = trim((string)($_POST['klinik_id'] ?? ''));
        $klinik_id = $klinik_raw === '' ? null : (int)$klinik_raw;
        
        $sql = "UPDATE inventory_users SET nama_lengkap = ?, role = ?, klinik_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $nama_lengkap, $role, $klinik_id, $id);
        
        if ($stmt->execute()) {
            // Update password if provided
            if (!empty($_POST['password'])) {
                $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $conn->query("UPDATE inventory_users SET password = '$pass' WHERE id = $id");
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
            if ($conn->query("DELETE FROM inventory_users WHERE id = $id")) {
                $success = "User berhasil dihapus.";
            } else {
                $error = "Gagal menghapus user.";
            }
        }
    }
}

// Fetch Data
$filter_role = $_GET['role'] ?? '';
$filter_klinik = $_GET['klinik_id'] ?? '';
$search = trim($_GET['search'] ?? '');

$where_clauses = [];
if ($filter_role !== '') {
    $where_clauses[] = "u.role = '" . $conn->real_escape_string($filter_role) . "'";
}
if ($filter_klinik !== '') {
    $where_clauses[] = "u.klinik_id = " . (int)$filter_klinik;
}
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where_clauses[] = "(u.nama_lengkap LIKE '%$s%' OR u.username LIKE '%$s%')";
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Pagination
$limit = 10;
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Count Total
$total_res = $conn->query("SELECT COUNT(*) as total FROM inventory_users u $where_sql");
$total_row = $total_res->fetch_assoc();
$total_records = $total_row['total'];
$total_pages = ceil($total_records / $limit);

$users = [];
$res = $conn->query("SELECT u.*, k.nama_klinik FROM inventory_users u LEFT JOIN inventory_klinik k ON u.klinik_id = k.id $where_sql ORDER BY u.nama_lengkap ASC LIMIT $limit OFFSET $offset");
while ($row = $res->fetch_assoc()) $users[] = $row;

$kliniks = [];
$res_k = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
while ($row = $res_k->fetch_assoc()) $kliniks[] = $row;
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    :root {
        --bumame-blue: #204EAB;
        --bumame-blue-soft: rgba(32, 78, 171, 0.08);
        --slate-50: #f8fafc;
        --slate-100: #f1f5f9;
        --slate-200: #e2e8f0;
        --slate-600: #475569;
        --slate-900: #0f172a;
    }

    .users-container {
        font-family: 'Outfit', sans-serif;
        background-color: var(--slate-50);
        min-height: 100vh;
    }

    .page-header {
        background: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        margin-bottom: 1.5rem;
        border-left: 5px solid var(--bumame-blue);
    }

    /* Table Styles */
    .table-premium {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--slate-200);
    }

    .table-premium thead th {
        background-color: var(--slate-50);
        color: var(--slate-600);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 1px solid var(--slate-200);
    }

    .table-premium tbody td {
        padding: 1rem;
        vertical-align: middle;
        color: var(--slate-900);
        font-size: 0.9rem;
    }

    .table-premium tr:hover {
        background-color: var(--slate-50);
    }

    /* Role Badges */
    .badge-role {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .role-super_admin { background: #1e293b; color: white; }
    .role-admin_gudang { background: #3b82f6; color: white; }
    .role-admin_klinik { background: #0ea5e9; color: white; }
    .role-spv_klinik { background: #6366f1; color: white; }
    .role-petugas_hc { background: #8b5cf6; color: white; }
    .role-cs { background: #10b981; color: white; }

    /* Action Buttons */
    .btn-action {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
        border: 1px solid var(--slate-200);
        background: white;
        color: var(--slate-600);
    }

    .btn-action:hover {
        background: var(--bumame-blue);
        color: white;
        border-color: var(--bumame-blue);
        transform: translateY(-2px);
    }

    .btn-action-delete:hover {
        background: #ef4444;
        border-color: #ef4444;
    }

    /* Filter Card */
    .filter-card {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--slate-200);
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    /* Circular Pagination */
    .pagination-circular .page-item { margin: 0 4px; }
    .pagination-circular .page-link {
        border-radius: 50% !important;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid var(--slate-200); color: var(--slate-600); font-weight: 500;
        transition: all 0.2s ease; background: #fff;
    }
    .pagination-circular .page-link:hover { background-color: var(--slate-50); color: var(--bumame-blue); border-color: var(--bumame-blue); }
    .pagination-circular .page-item.active .page-link { background-color: var(--bumame-blue-soft) !important; color: var(--bumame-blue) !important; border-color: var(--bumame-blue) !important; font-weight: 700; }
    .pagination-circular .page-item.disabled .page-link { background-color: #fff; color: var(--slate-200); border-color: var(--slate-100); opacity: 0.6; }
</style>

<div class="container-fluid users-container py-4">
    <!-- Header -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h4 mb-0 fw-800" style="color: var(--bumame-blue); letter-spacing: -0.02em;">
                <i class="fas fa-users-cog me-2"></i>Manajemen User
            </h1>
            <p class="text-muted mb-0 small">Kelola hak akses dan profil staf Bumame</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-sm fw-700 px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#modalImport">
                <i class="fas fa-file-excel me-1"></i>Import
            </button>
            <button class="btn btn-primary btn-sm fw-700 px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#modalAdd">
                <i class="fas fa-plus me-1"></i>Tambah User
            </button>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-3 border-0 shadow-sm"><?= $error ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success rounded-3 border-0 shadow-sm"><?= $success ?></div><?php endif; ?>

    <!-- Compact Filter -->
    <div class="filter-card shadow-sm">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="users">
            <div class="col-md-3">
                <label class="form-label small fw-700 text-muted mb-1 uppercase" style="font-size: 0.65rem;">Cari User</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 rounded-end-3" placeholder="Nama atau username..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-700 text-muted mb-1 uppercase" style="font-size: 0.65rem;">Filter Role</label>
                <select name="role" class="form-select form-select-sm rounded-3">
                    <option value="">-- Semua Role --</option>
                    <option value="super_admin" <?= $filter_role === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                    <option value="admin_gudang" <?= $filter_role === 'admin_gudang' ? 'selected' : '' ?>>Admin Gudang</option>
                    <option value="admin_klinik" <?= $filter_role === 'admin_klinik' ? 'selected' : '' ?>>Admin Klinik</option>
                    <option value="spv_klinik" <?= $filter_role === 'spv_klinik' ? 'selected' : '' ?>>SPV Klinik</option>
                    <option value="petugas_hc" <?= $filter_role === 'petugas_hc' ? 'selected' : '' ?>>Petugas HC</option>
                    <option value="cs" <?= $filter_role === 'cs' ? 'selected' : '' ?>>CS</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-700 text-muted mb-1 uppercase" style="font-size: 0.65rem;">Filter Klinik/Lokasi</label>
                <select name="klinik_id" class="form-select form-select-sm rounded-3">
                    <option value="">-- Semua Lokasi --</option>
                    <?php foreach ($kliniks as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= (string)$filter_klinik === (string)$k['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($k['nama_klinik']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm fw-700 flex-grow-1 rounded-3">
                    <i class="fas fa-filter me-2"></i>Filter Data
                </button>
                <a href="index.php?page=users" class="btn btn-outline-secondary btn-sm rounded-3">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- User Table -->
    <div class="table-premium shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Profil & Username</th>
                        <th>Role User</th>
                        <th>Lokasi Tugas</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm me-3 bg-light rounded-circle d-flex align-items-center justify-content-center text-primary fw-bold" style="width: 38px; height: 38px;">
                                    <?= strtoupper(substr($u['nama_lengkap'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-800 text-dark"><?= htmlspecialchars($u['nama_lengkap']) ?></div>
                                    <div class="text-muted small">@<?= htmlspecialchars($u['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge-role role-<?= $u['role'] ?>">
                                <i class="fas fa-user-shield small"></i>
                                <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['nama_klinik']): ?>
                                <div class="fw-600"><i class="fas fa-map-marker-alt text-danger me-1 small"></i><?= htmlspecialchars($u['nama_klinik']) ?></div>
                            <?php else: ?>
                                <span class="text-muted small fst-italic">Akses Global / Gudang</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button class="btn-action me-1" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)" title="Edit User">
                                <i class="fas fa-pen-nib small"></i>
                            </button>
                            <button class="btn-action btn-action-delete" onclick="deleteUser(<?= $u['id'] ?>)" title="Hapus User">
                                <i class="fas fa-trash-alt small"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="p-3 border-top d-flex justify-content-between align-items-center bg-white">
            <div class="small text-muted fw-600">
                Menampilkan <?= min($offset + 1, $total_records) ?> - <?= min($offset + $limit, $total_records) ?> dari <?= $total_records ?> user
            </div>
            <nav>
                <ul class="pagination pagination-circular mb-0">
                    <?php 
                        $query_str = "index.php?page=users&role=$filter_role&klinik_id=$filter_klinik&search=" . urlencode($search);
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $query_str ?>&p=<?= $page - 1 ?>"><i class="fas fa-chevron-left small"></i></a>
                    </li>
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="'.$query_str.'&p=1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $query_str ?>&p=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>
                        <li class="page-item"><a class="page-link" href="<?= $query_str ?>&p=<?= $total_pages ?>"><?= $total_pages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $query_str ?>&p=<?= $page + 1 ?>"><i class="fas fa-chevron-right small"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Import -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="actions/process_user_bulk_upload.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-800" style="color: var(--bumame-blue);">
                        <i class="fas fa-file-excel me-2"></i>Import User Bulk
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 rounded-3 shadow-sm mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        Gunakan template resmi untuk format yang benar.
                        <div class="mt-2">
                            <a href="api/download_template_users.php" class="btn btn-primary btn-sm rounded-pill px-3">
                                <i class="fas fa-download me-1"></i>Download Template
                            </a>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-700 text-muted uppercase">Pilih File Excel (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control rounded-3" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light fw-700 px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success fw-700 px-4 rounded-pill">
                        <i class="fas fa-upload me-1"></i>Proses Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-800" style="color: var(--bumame-blue);">
                        <i class="fas fa-user-plus me-2"></i>Tambah User Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label small fw-700 text-muted uppercase">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-control rounded-3" placeholder="Contoh: Budi Santoso" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-700 text-muted uppercase">Username</label>
                            <input type="text" name="username" class="form-control rounded-3" placeholder="budi_s" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-700 text-muted uppercase">Password</label>
                            <input type="password" name="password" class="form-control rounded-3" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-700 text-muted uppercase">Role User</label>
                        <select name="role" class="form-select rounded-3" required onchange="toggleKlinik(this)">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin_gudang">Admin Gudang</option>
                            <option value="admin_klinik">Admin Klinik</option>
                            <option value="spv_klinik">SPV Klinik</option>
                            <option value="petugas_hc">Petugas HC</option>
                            <option value="cs">CS</option>
                        </select>
                    </div>
                    <div class="mb-3 klinik-select" style="display:none;">
                        <label class="form-label small fw-700 text-muted uppercase">Klinik / Unit</label>
                        <select name="klinik_id" class="form-select rounded-3">
                            <option value="">-- Pilih Lokasi Tugas --</option>
                            <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Tentukan lokasi spesifik untuk role ini.</small>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light fw-700 px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-700 px-4 rounded-pill">Simpan User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div id="modalEdit" class="modal fade" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-800" style="color: var(--bumame-blue);">
                        <i class="fas fa-user-edit me-2"></i>Edit Profil User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label small fw-700 text-muted uppercase">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="edit_nama" class="form-control rounded-3" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-700 text-muted uppercase">Username</label>
                            <input type="text" id="edit_username" class="form-control rounded-3 bg-light" readonly disabled>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-700 text-muted uppercase">Password Baru</label>
                            <input type="password" name="password" class="form-control rounded-3" placeholder="Kosongkan jika tetap">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-700 text-muted uppercase">Role User</label>
                        <select name="role" id="edit_role" class="form-select rounded-3" required onchange="toggleKlinik(this)">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin_gudang">Admin Gudang</option>
                            <option value="admin_klinik">Admin Klinik</option>
                            <option value="spv_klinik">SPV Klinik</option>
                            <option value="petugas_hc">Petugas HC</option>
                            <option value="cs">CS</option>
                        </select>
                    </div>
                    <div class="mb-3 klinik-select">
                        <label class="form-label small fw-700 text-muted uppercase">Klinik / Unit</label>
                        <select name="klinik_id" id="edit_klinik_id" class="form-select rounded-3">
                            <option value="">-- Pilih Lokasi Tugas --</option>
                            <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light fw-700 px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-700 px-4 rounded-pill">Simpan Perubahan</button>
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
    if (['admin_klinik', 'spv_klinik', 'petugas_hc'].includes(role)) {
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
