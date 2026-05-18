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
            $stmt = $conn->prepare("DELETE FROM inventory_klinik WHERE id=?");
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
        $stmt = $conn->prepare("UPDATE inventory_klinik SET kode_klinik=?, kode_homecare=?, nama_klinik=?, alamat=?, status=? WHERE id=?");
        $stmt->bind_param("sssssi", $kode_klinik, $kode_homecare, $nama_klinik, $alamat, $status, $id);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Klinik berhasil diupdate.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO inventory_klinik (kode_klinik, kode_homecare, nama_klinik, alamat, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $kode_klinik, $kode_homecare, $nama_klinik, $alamat, $status);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Klinik berhasil ditambahkan.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
    }
    }
}

$result = $conn->query("SELECT * FROM inventory_klinik ORDER BY id DESC LIMIT 500");
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
                                onclick='editKlinik(<?= json_encode($row) ?>)' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if (!empty($row['kode_klinik'])): ?>
                            <button class="btn btn-sm btn-outline-primary"
                                onclick='showQR(<?= (int)$row['id'] ?>, <?= htmlspecialchars(json_encode($row['nama_klinik']), ENT_QUOTES) ?>)' title="QR Code">
                                <i class="fas fa-qrcode"></i>
                            </button>
                            <?php endif; ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus?')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Hapus">
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

<!-- Modal QR Klinik -->
<div class="modal fade" id="modalQRKlinik" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden;">
            <!-- Header -->
            <div class="modal-header border-0 pb-0" style="background:linear-gradient(135deg,#204EAB,#3b82f6);color:#fff;padding:20px 24px 16px;">
                <div>
                    <div class="fw-bold" style="font-size:18px;"><i class="fas fa-qrcode me-2"></i>QR Code Request Stok</div>
                    <div id="qrKlinikName" style="font-size:13px;opacity:.85;margin-top:3px;"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- QR Area -->
            <div class="modal-body px-4 pt-3 pb-0 text-center">
                <div style="background:#f8fafc;border-radius:14px;padding:20px;display:inline-block;margin-bottom:16px;border:1px solid #e8ecf4;">
                    <img id="qrKlinikImg" src="" alt="QR Code" style="width:200px;height:200px;display:block;">
                </div>

                <!-- URL kecil -->
                <div id="qrKlinikUrl" style="font-size:10px;color:#94a3b8;word-break:break-all;margin-bottom:16px;padding:0 8px;"></div>

                <!-- Rules -->
                <div style="background:#f0f7ff;border-radius:12px;padding:14px 16px;text-align:left;margin-bottom:16px;border:1px solid #bfdbfe;">
                    <div style="font-size:12px;font-weight:700;color:#204EAB;margin-bottom:8px;"><i class="fas fa-info-circle me-1"></i>Cara Penggunaan</div>
                    <ol style="margin:0;padding-left:18px;font-size:12px;color:#475569;line-height:1.8;">
                        <li>Scan QR ini menggunakan HP nakes</li>
                        <li>Login dengan akun Bumame Inventory</li>
                        <li>Pilih item yang dibutuhkan beserta qty</li>
                        <li>Upload foto bukti <em>(opsional)</em></li>
                        <li>Tekan <strong>Kirim Request</strong></li>
                        <li>Admin klinik akan mereview & menyetujui</li>
                    </ol>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="modal-footer border-0 pt-0 px-4 pb-4" style="gap:10px;">
                <button type="button" class="btn btn-outline-secondary flex-fill" onclick="downloadQR()">
                    <i class="fas fa-download me-1"></i>Download
                </button>
                <button type="button" class="btn flex-fill text-white" style="background:#204EAB;" onclick="printQRModal()">
                    <i class="fas fa-print me-1"></i>Cetak
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let _currentQRKlinikId = 0;
let _currentQRKlinikName = '';

function showQR(klinikId, klinikName) {
    _currentQRKlinikId   = klinikId;
    _currentQRKlinikName = klinikName;
    const baseUrl = '<?= base_url() ?>';
    const targetUrl = baseUrl + 'index.php?page=qr_transfer&klinik_id=' + klinikId;
    const qrSrc = baseUrl + 'api/qr.php?size=220x220&text=' + encodeURIComponent(targetUrl);

    document.getElementById('qrKlinikName').textContent = klinikName;
    document.getElementById('qrKlinikImg').src = qrSrc;
    document.getElementById('qrKlinikUrl').textContent = targetUrl;

    new bootstrap.Modal(document.getElementById('modalQRKlinik')).show();
}

function downloadQR() {
    const img = document.getElementById('qrKlinikImg');
    const a   = document.createElement('a');
    // Fetch blob to trigger download (cross-origin safe via proxy)
    fetch(img.src)
        .then(r => r.blob())
        .then(blob => {
            a.href = URL.createObjectURL(blob);
            a.download = 'QR_' + _currentQRKlinikName.replace(/\s+/g,'_') + '.png';
            a.click();
            URL.revokeObjectURL(a.href);
        });
}

function printQRModal() {
    const baseUrl  = '<?= base_url() ?>';
    const targetUrl = baseUrl + 'index.php?page=qr_transfer&klinik_id=' + _currentQRKlinikId;
    const qrSrc    = baseUrl + 'api/qr.php?size=300x300&text=' + encodeURIComponent(targetUrl);
    const win = window.open('', '_blank', 'width=520,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
<title>QR – ${_currentQRKlinikName}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;display:flex;justify-content:center;padding:30px 20px;}
.card{border:2px solid #204EAB;border-radius:16px;padding:28px 24px;max-width:340px;width:100%;text-align:center;}
.logo{color:#204EAB;font-size:13px;font-weight:700;letter-spacing:1px;margin-bottom:12px;text-transform:uppercase;}
h2{color:#1e293b;font-size:17px;font-weight:700;margin-bottom:4px;}
.sub{color:#64748b;font-size:12px;margin-bottom:18px;}
img{border:1px solid #e2e8f0;border-radius:10px;padding:6px;background:#f8fafc;}
.rules{background:#f0f7ff;border-radius:10px;padding:12px 14px;text-align:left;margin-top:18px;border:1px solid #bfdbfe;}
.rules-title{font-size:11px;font-weight:700;color:#204EAB;margin-bottom:6px;}
ol{padding-left:16px;font-size:11px;color:#475569;line-height:1.9;}
.url{font-size:9px;color:#94a3b8;word-break:break-all;margin-top:14px;}
@media print{body{padding:10px;}.card{border-color:#ccc;}}
</style></head><body>
<div class="card">
    <div class="logo">Bumame Inventory</div>
    <h2>${_currentQRKlinikName}</h2>
    <div class="sub">Scan untuk Request Stok HC</div>
    <img src="${qrSrc}" width="240" height="240" onload="window.print()">
    <div class="rules">
        <div class="rules-title">📋 Cara Penggunaan</div>
        <ol>
            <li>Scan QR ini dengan HP Anda</li>
            <li>Login dengan akun Bumame Inventory</li>
            <li>Pilih item &amp; qty yang dibutuhkan</li>
            <li>Upload foto bukti <em>(opsional)</em></li>
            <li>Tekan <strong>Kirim Request</strong></li>
            <li>Admin akan mereview &amp; menyetujui</li>
        </ol>
    </div>
    <div class="url">${targetUrl}</div>
</div>
</body></html>`);
    win.document.close();
}

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
