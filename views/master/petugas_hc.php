<?php
check_role(['super_admin', 'admin_klinik']);
require_once __DIR__ . '/../../lib/stock.php';

$role = (string)($_SESSION['role'] ?? '');
$user_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
$can_choose_klinik = in_array($role, ['super_admin'], true);

$message = '';

if (function_exists('ensure_enum_value')) {
    ensure_enum_value($conn, 'inventory_users', 'role', 'petugas_hc');
}
$conn->query("UPDATE inventory_users SET role = 'petugas_hc' WHERE role = '' AND klinik_id IS NOT NULL AND username IS NOT NULL AND username <> ''");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $klinik_id = (int)($_POST['klinik_id'] ?? 0);
    $nama_lengkap = trim((string)($_POST['nama_lengkap'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $status = (string)($_POST['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    if (!$can_choose_klinik) $klinik_id = $user_klinik_id;

    if ($action === 'save') {
        if ($klinik_id <= 0 || $nama_lengkap === '' || $username === '') {
            $message = '<div class="alert alert-danger mb-3">Data tidak valid. Klinik, Nama, dan Username wajib diisi.</div>';
        } else {
            $r = $conn->query("SELECT kode_homecare FROM inventory_klinik WHERE id = $klinik_id LIMIT 1");
            $kode_homecare = '';
            if ($r && $r->num_rows > 0) $kode_homecare = trim((string)($r->fetch_assoc()['kode_homecare'] ?? ''));
            if ($kode_homecare === '') {
                $message = '<div class="alert alert-danger mb-3">Klinik belum memiliki Kode Homecare. Isi dulu Kode Homecare agar stok HC bisa terbaca.</div>';
            } else {
                if ($id > 0) {
                    $row_old = $conn->query("SELECT id, klinik_id FROM inventory_users WHERE id = $id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
                    if (!$row_old) {
                        $message = '<div class="alert alert-danger mb-3">User tidak ditemukan.</div>';
                    } elseif (!$can_choose_klinik && (int)$row_old['klinik_id'] !== $user_klinik_id) {
                        $message = '<div class="alert alert-danger mb-3">Access denied.</div>';
                    } else {
                        $sql = "UPDATE inventory_users SET username = ?, nama_lengkap = ?, klinik_id = ?, status = ? WHERE id = ? AND role = 'petugas_hc'";
                        $params = [$username, $nama_lengkap, $klinik_id, $status, $id];
                        $types = "ssisi";
                        if ($password !== '') {
                            $sql = "UPDATE inventory_users SET username = ?, nama_lengkap = ?, klinik_id = ?, status = ?, password = ? WHERE id = ? AND role = 'petugas_hc'";
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $params = [$username, $nama_lengkap, $klinik_id, $status, $hashed, $id];
                            $types = "ssissi";
                        }
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $message = '<div class="alert alert-success mb-3">Petugas HC berhasil diupdate.</div>';

                        // Nakes pindah klinik terdaftar -- otomatis kembalikan SEMUA barang di
                        // tasnya (klinik lama) ke stok onsite klinik lama, supaya tidak ada yang
                        // "nyangkut" dan bikin mismatch kayak kasus-kasus sebelumnya.
                        $old_klinik_id = (int)$row_old['klinik_id'];
                        if ($old_klinik_id > 0 && $old_klinik_id !== $klinik_id) {
                            $ret = hc_full_return($conn, $id, $old_klinik_id, (int)$_SESSION['user_id'], 'Pindah klinik terdaftar');
                            if ($ret['ok']) {
                                $message = '<div class="alert alert-success mb-3">Petugas HC berhasil diupdate & dipindahkan. ' . htmlspecialchars($ret['message']) . '</div>';
                            } else {
                                $message = '<div class="alert alert-warning mb-3">Petugas HC berhasil diupdate & dipindahkan, TAPI pengembalian stok otomatis GAGAL: ' . htmlspecialchars($ret['message']) . '. Silakan proses manual lewat menu Return Petugas → Onsite.</div>';
                            }
                        }
                    }
                } else {
                    if ($password === '') {
                        $message = '<div class="alert alert-danger mb-3">Password wajib diisi untuk petugas baru.</div>';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO inventory_users (username, password, nama_lengkap, role, klinik_id, status) VALUES (?, ?, ?, 'petugas_hc', ?, ?)");
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt->bind_param("sssis", $username, $hashed, $nama_lengkap, $klinik_id, $status);
                        if ($stmt->execute()) {
                            $new_id = (int)$conn->insert_id;
                            $conn->query("UPDATE inventory_users SET role = 'petugas_hc' WHERE id = $new_id AND role = ''");
                            $message = '<div class="alert alert-success mb-3">Petugas HC berhasil ditambahkan.</div>';
                        }
                        else $message = '<div class="alert alert-danger mb-3">Gagal menambah petugas: ' . htmlspecialchars($stmt->error) . '</div>';
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        if ($id > 0) {
            $row_old = $conn->query("SELECT id, klinik_id FROM inventory_users WHERE id = $id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
            if (!$row_old) {
                $message = '<div class="alert alert-danger mb-3">User tidak ditemukan.</div>';
            } elseif (!$can_choose_klinik && (int)$row_old['klinik_id'] !== $user_klinik_id) {
                $message = '<div class="alert alert-danger mb-3">Access denied.</div>';
            } else {
                $conn->begin_transaction();
                try {
                    // 1. Delete allocated stock so it returns to 'unallocated'
                    $conn->query("DELETE FROM inventory_stok_tas_hc WHERE user_id = $id");
                    
                    // 2. Delete the user
                    $conn->query("DELETE FROM inventory_users WHERE id = $id AND role = 'petugas_hc'");
                    
                    $conn->commit();
                    $message = '<div class="alert alert-success mb-3">Petugas HC dan data alokasi stoknya berhasil dihapus. Stok otomatis kembali ke status Unallocated.</div>';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = '<div class="alert alert-danger mb-3">Gagal menghapus petugas: ' . $e->getMessage() . '</div>';
                }
            }
        }
    } elseif ($action === 'claim') {
        // Pindahkan nakes yang SAAT INI terdaftar di klinik LAIN, ke klinik Admin Klinik yang
        // melakukan aksi ini. SENGAJA cuma ubah klinik_id (+ trigger full-return stok tas ke
        // klinik lama) -- TIDAK ikut ubah username/password/nama/status milik nakes itu, supaya
        // Admin Klinik tidak bisa "nyerempet" edit data orang dari klinik lain di luar yg diminta.
        if ($can_choose_klinik) {
            $message = '<div class="alert alert-danger mb-3">Fitur ini khusus Admin Klinik.</div>';
        } elseif ($id <= 0 || $user_klinik_id <= 0) {
            $message = '<div class="alert alert-danger mb-3">Data tidak valid.</div>';
        } else {
            $row_old = $conn->query("SELECT id, nama_lengkap, klinik_id FROM inventory_users WHERE id = $id AND role = 'petugas_hc' LIMIT 1")->fetch_assoc();
            if (!$row_old) {
                $message = '<div class="alert alert-danger mb-3">Petugas tidak ditemukan.</div>';
            } elseif ((int)$row_old['klinik_id'] === $user_klinik_id) {
                $message = '<div class="alert alert-warning mb-3">Petugas ini sudah terdaftar di klinik Anda.</div>';
            } else {
                $r_kh = $conn->query("SELECT nama_klinik, kode_homecare FROM inventory_klinik WHERE id = $user_klinik_id LIMIT 1")->fetch_assoc();
                $kode_homecare = trim((string)($r_kh['kode_homecare'] ?? ''));
                if ($kode_homecare === '') {
                    $message = '<div class="alert alert-danger mb-3">Klinik Anda belum memiliki Kode Homecare. Isi dulu di Data Klinik.</div>';
                } else {
                    $old_klinik_id = (int)$row_old['klinik_id'];
                    $stmt = $conn->prepare("UPDATE inventory_users SET klinik_id = ? WHERE id = ? AND role = 'petugas_hc'");
                    $stmt->bind_param("ii", $user_klinik_id, $id);
                    $stmt->execute();

                    $ret = hc_full_return($conn, $id, $old_klinik_id, (int)$_SESSION['user_id'], 'Ditarik ke ' . ($r_kh['nama_klinik'] ?? 'klinik lain'));
                    $nama_nakes = htmlspecialchars((string)$row_old['nama_lengkap']);
                    if ($ret['ok']) {
                        $message = '<div class="alert alert-success mb-3">' . $nama_nakes . ' berhasil dipindahkan ke klinik Anda. ' . htmlspecialchars($ret['message']) . '</div>';
                    } else {
                        $message = '<div class="alert alert-warning mb-3">' . $nama_nakes . ' berhasil dipindahkan ke klinik Anda, TAPI pengembalian stok otomatis GAGAL: ' . htmlspecialchars($ret['message']) . '. Silakan proses manual lewat menu Return Petugas → Onsite.</div>';
                    }
                }
            }
        }
    }
}

$kliniks = [];
if ($can_choose_klinik) {
    $res = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $kliniks[] = $row;
} else {
    $res = $conn->query("SELECT id, nama_klinik, kode_homecare FROM inventory_klinik WHERE id = $user_klinik_id LIMIT 1");
    if ($res && $res->num_rows > 0) $kliniks[] = $res->fetch_assoc();
}

$filter_klinik_id = $can_choose_klinik ? (int)($_GET['klinik_id'] ?? 0) : $user_klinik_id;
$search_query = trim((string)($_GET['q'] ?? ''));
if (!$can_choose_klinik) $filter_klinik_id = $user_klinik_id;

$where = "1=1";
if ($filter_klinik_id > 0) $where .= " AND u.klinik_id = " . (int)$filter_klinik_id;
if ($search_query !== '') {
    $sq = $conn->real_escape_string($search_query);
    $where .= " AND (u.nama_lengkap LIKE '%$sq%' OR u.username LIKE '%$sq%')";
}

$petugas = [];
$res = $conn->query("
    SELECT u.id, u.username, u.nama_lengkap, u.status, u.klinik_id, k.nama_klinik, k.kode_homecare
    FROM inventory_users u
    JOIN inventory_klinik k ON k.id = u.klinik_id
    WHERE u.role = 'petugas_hc' AND $where
    ORDER BY k.nama_klinik ASC, u.nama_lengkap ASC
");
while ($res && ($row = $res->fetch_assoc())) $petugas[] = $row;

// Pencarian nakes DI KLINIK LAIN, khusus Admin Klinik -- buat fitur "Pindahkan ke Klinik Saya".
$claim_results = [];
$claim_query = '';
if (!$can_choose_klinik) {
    $claim_query = trim((string)($_GET['claim_q'] ?? ''));
    if ($claim_query !== '') {
        $cq = $conn->real_escape_string($claim_query);
        $res_claim = $conn->query("
            SELECT u.id, u.username, u.nama_lengkap, u.klinik_id, k.nama_klinik
            FROM inventory_users u
            JOIN inventory_klinik k ON k.id = u.klinik_id
            WHERE u.role = 'petugas_hc' AND u.klinik_id != $user_klinik_id
              AND (u.nama_lengkap LIKE '%$cq%' OR u.username LIKE '%$cq%')
            ORDER BY u.nama_lengkap ASC
            LIMIT 20
        ");
        while ($res_claim && ($row = $res_claim->fetch_assoc())) $claim_results[] = $row;
    }
}
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color:#204EAB;">
                <i class="fas fa-user-nurse me-2"></i>Petugas HC
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Petugas HC</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPetugasHC" onclick="openPetugasModal(null)">
                <i class="fas fa-plus me-2"></i>Tambah Petugas
            </button>
        </div>
    </div>

    <?= $message ?>

    <div class="alert alert-info shadow-sm">
        <div class="fw-semibold mb-1">Konsep Mapping Stok HC</div>
        <div class="small">
            Odoo hanya menyimpan <span class="fw-semibold">total stok HC per klinik</span>. Jadi, petugas HC di sini hanya dipetakan ke kliniknya. Stok HC yang ditampilkan akan membaca mirror Odoo dari <span class="fw-semibold">Klinik.kode_homecare</span>.
        </div>
    </div>

    <?php if ($can_choose_klinik): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="petugas_hc">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-muted mb-1">Klinik</label>
                    <select class="form-select" name="klinik_id" onchange="this.form.submit()">
                        <option value="0">Semua</option>
                        <?php foreach ($kliniks as $k): ?>
                            <option value="<?= (int)$k['id'] ?>" <?= (int)$filter_klinik_id === (int)$k['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($k['nama_klinik']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$can_choose_klinik): ?>
    <div class="card shadow-sm mb-3 border-warning-subtle">
        <div class="card-body">
            <div class="fw-semibold mb-2"><i class="fas fa-user-plus text-warning me-1"></i>Pindahkan Nakes dari Klinik Lain</div>
            <div class="small text-muted mb-2">Cari nakes yang saat ini terdaftar di klinik LAIN. Memindahkan akan otomatis mengembalikan semua stok di tasnya ke klinik lama (full return), lalu klinik terdaftarnya diubah jadi klinik Anda.</div>
            <form method="GET" class="row g-2 align-items-end mb-2">
                <input type="hidden" name="page" value="petugas_hc">
                <div class="col-md-6">
                    <input type="text" name="claim_q" class="form-control" placeholder="Cari nama atau username nakes..." value="<?= htmlspecialchars($claim_query) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search me-1"></i>Cari</button>
                </div>
            </form>
            <?php if ($claim_query !== ''): ?>
                <?php if (empty($claim_results)): ?>
                    <div class="text-muted small">Tidak ada nakes dari klinik lain yang cocok dengan pencarian ini.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="bg-light"><tr><th>Nama</th><th>Username</th><th>Klinik Saat Ini</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($claim_results as $cr): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cr['nama_lengkap']) ?></td>
                                    <td><?= htmlspecialchars($cr['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($cr['nama_klinik']) ?></span></td>
                                    <td class="text-end">
                                        <form method="POST" class="m-0 form-claim-nakes" data-nama="<?= htmlspecialchars($cr['nama_lengkap'], ENT_QUOTES) ?>" data-klinik-lama="<?= htmlspecialchars($cr['nama_klinik'], ENT_QUOTES) ?>">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                            <input type="hidden" name="action" value="claim">
                                            <input type="hidden" name="id" value="<?= (int)$cr['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-arrow-right me-1"></i>Pindahkan ke Klinik Saya</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable">
                    <thead class="bg-light">
                        <tr>
                            <th>Klinik</th>
                            <th>Nama Petugas</th>
                            <th>Username</th>
                            <th>Mirror HC</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($petugas as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($p['nama_klinik']) ?></div>
                                </td>
                                <td class="fw-semibold"><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['username']) ?></span></td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars(trim((string)($p['kode_homecare'] ?? '-'))) ?></span>
                                </td>
                                <td>
                                    <?php if (($p['status'] ?? 'active') === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php
                                        $data_json = htmlspecialchars(json_encode([
                                            'id' => (int)$p['id'],
                                            'klinik_id' => (int)$p['klinik_id'],
                                            'nama_lengkap' => (string)$p['nama_lengkap'],
                                            'username' => (string)$p['username'],
                                            'status' => (string)$p['status']
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                                    ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalPetugasHC"
                                        onclick="openPetugasModal(<?= $data_json ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus petugas ini?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPetugasHC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background-color:#204EAB;">
                <h5 class="modal-title fw-bold text-white" id="modalPetugasTitle"><i class="fas fa-user-nurse me-2"></i>Petugas HC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="modal-body bg-light">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="petugas_id" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Klinik</label>
                        <select name="klinik_id" id="petugas_klinik_id" class="form-select" <?= $can_choose_klinik ? '' : 'disabled' ?> required>
                            <option value="">- Pilih Klinik -</option>
                            <?php foreach ($kliniks as $k): ?>
                                <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$can_choose_klinik): ?>
                            <input type="hidden" name="klinik_id" id="petugas_klinik_id_hidden" value="<?= (int)$user_klinik_id ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Nama Petugas</label>
                        <input type="text" name="nama_lengkap" id="petugas_nama" class="form-control" placeholder="Nama petugas HC" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Username</label>
                        <input type="text" name="username" id="petugas_username" class="form-control" placeholder="username login" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Password</label>
                        <input type="password" name="password" id="petugas_password" class="form-control" placeholder="kosongkan jika tidak diubah">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small d-block">Status</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="petugas_status_switch" checked onchange="toggleStatus(this)">
                            <label class="form-check-label fw-semibold" for="petugas_status_switch" id="status_label">Active</label>
                            <input type="hidden" name="status" id="petugas_status_hidden" value="active">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-0 pb-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleStatus(el) {
    const hidden = document.getElementById('petugas_status_hidden');
    const label = document.getElementById('status_label');
    if (el.checked) {
        hidden.value = 'active';
        label.innerText = 'Active';
        label.classList.replace('text-secondary', 'text-success');
    } else {
        hidden.value = 'inactive';
        label.innerText = 'Inactive';
        label.classList.replace('text-success', 'text-secondary');
    }
}

function openPetugasModal(data) {
    // Reset or set data based on whether data is provided (edit vs add)
    document.getElementById('petugas_id').value = (data && data.id) ? data.id : '';
    
    var k = document.getElementById('petugas_klinik_id');
    var k_hidden = document.getElementById('petugas_klinik_id_hidden');
    
    if (k) {
        // Clear previous selection
        k.value = "";
        
        const targetKlinikId = (data && data.klinik_id) ? String(data.klinik_id) : "";
        
        if (targetKlinikId !== "") {
            // Set value and try to ensure it's visually selected
            k.value = targetKlinikId;
            
            // If the value isn't matching (e.g. clinic not in options), k.value will be empty
            // In that case, we might need a backup way or just let it be empty
        }
        
        // Also update hidden field if exists (for non-super-admins)
        if (k_hidden) {
            k_hidden.value = targetKlinikId || "<?= (int)$user_klinik_id ?>";
        }
    }
    
    document.getElementById('petugas_nama').value = (data && data.nama_lengkap) ? data.nama_lengkap : '';
    document.getElementById('petugas_username').value = (data && data.username) ? data.username : '';
    document.getElementById('petugas_password').value = ''; // Always clear password field
    
    // Status Switch setup
    const status = (data && data.status) ? data.status : 'active';
    const switchEl = document.getElementById('petugas_status_switch');
    const hidden = document.getElementById('petugas_status_hidden');
    const label = document.getElementById('status_label');
    
    if (status === 'active') {
        switchEl.checked = true;
        hidden.value = 'active';
        label.innerText = 'Active';
        label.classList.remove('text-secondary');
        label.classList.add('text-success');
    } else {
        switchEl.checked = false;
        hidden.value = 'inactive';
        label.innerText = 'Inactive';
        label.classList.remove('text-success');
        label.classList.add('text-secondary');
    }
    
    // Update modal title
    var title = document.getElementById('modalPetugasTitle');
    if (title) {
        title.innerHTML = '<i class="fas fa-user-nurse me-2"></i>' + (data && data.id ? 'Edit Petugas HC' : 'Tambah Petugas HC');
    }
}

document.querySelectorAll('.form-claim-nakes').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var nama = form.dataset.nama;
        var klinikLama = form.dataset.klinikLama;
        Swal.fire({
            title: 'Pindahkan Nakes?',
            html: 'Pindahkan <strong>' + nama + '</strong> dari <strong>' + klinikLama + '</strong> ke klinik Anda?<br><br>' +
                  '<div class="text-start small text-muted"><i class="fas fa-info-circle me-1"></i>Semua stok di tasnya akan otomatis dikembalikan ke klinik lama (full return).</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            confirmButtonText: '<i class="fas fa-arrow-right me-1"></i>Ya, Pindahkan',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
</script>
