<?php
check_role(['cs', 'super_admin', 'admin_klinik']);

function ensure_booking_col($column, $definition) {
    global $conn;
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `booking_pemeriksaan` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `booking_pemeriksaan` ADD COLUMN `$column` $definition");
    }
}

ensure_booking_col('booking_type', "VARCHAR(10) NULL");
ensure_booking_col('jam_layanan', "VARCHAR(10) NULL");
ensure_booking_col('jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_booking_col('nomor_tlp', "VARCHAR(30) NULL");
ensure_booking_col('tanggal_lahir', "DATE NULL");

if (!isset($_GET['id'])) {
    redirect('index.php?page=booking');
}

$id = intval($_GET['id']);

// Fetch booking
$booking = $conn->query("SELECT b.*, k.nama_klinik FROM booking_pemeriksaan b JOIN klinik k ON b.klinik_id = k.id WHERE b.id = $id")->fetch_assoc();

if (!$booking || $booking['status'] != 'booked') {
    $_SESSION['error'] = 'Booking tidak ditemukan atau sudah diproses';
    redirect('index.php?page=booking');
}

$exams_result = $conn->query("SELECT id, nama_pemeriksaan FROM pemeriksaan_grup ORDER BY nama_pemeriksaan ASC");
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-edit me-2"></i>Edit Booking - Pilih Pemeriksaan Baru
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php?page=booking" class="text-decoration-none">Booking</a></li>
                    <li class="breadcrumb-item active">Edit Booking</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="alert alert-info">
                <strong>Booking:</strong> <?= htmlspecialchars($booking['nomor_booking']) ?><br>
                <strong>Klinik:</strong> <?= htmlspecialchars($booking['nama_klinik']) ?><br>
                <strong>Tanggal:</strong> <?= date('d M Y', strtotime($booking['tanggal_pemeriksaan'])) ?><br>
                <strong>Jumlah Pax:</strong> <?= $booking['jumlah_pax'] ?>
            </div>

            <form id="formEditBooking" method="POST" action="actions/process_booking_edit.php">
                <input type="hidden" name="booking_id" value="<?= $id ?>">

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Nama Pasien <span class="text-danger">*</span></label>
                        <input type="text" name="nama_pemesan" class="form-control" value="<?= htmlspecialchars($booking['nama_pemesan'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Nomor Tlp</label>
                        <input type="text" name="nomor_tlp" class="form-control" value="<?= htmlspecialchars($booking['nomor_tlp'] ?? '') ?>" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" class="form-control" value="<?= !empty($booking['tanggal_lahir']) ? htmlspecialchars($booking['tanggal_lahir']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Tindakan <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($booking['tanggal_pemeriksaan']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Jam Layanan</label>
                        <input type="time" name="jam_layanan" class="form-control" value="<?= htmlspecialchars($booking['jam_layanan'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tipe (Keep/Fixed) <span class="text-danger">*</span></label>
                        <?php $bt = strtolower((string)($booking['booking_type'] ?? 'keep')); ?>
                        <select name="booking_type" class="form-select" required>
                            <option value="keep" <?= $bt === 'keep' ? 'selected' : '' ?>>Keep</option>
                            <option value="fixed" <?= $bt === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                            <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                            <option value="cancel" <?= $bt === 'cancel' ? 'selected' : '' ?>>Dibatalkan (CS)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Jotform Submitted <span class="text-danger">*</span></label>
                        <?php $jf = (int)($booking['jotform_submitted'] ?? 0); ?>
                        <select name="jotform_submitted" class="form-select" required>
                            <option value="0" <?= $jf === 0 ? 'selected' : '' ?>>Belum</option>
                            <option value="1" <?= $jf === 1 ? 'selected' : '' ?>>Sudah</option>
                        </select>
                    </div>
                </div>

                <h6 class="mb-3 fw-bold">
                    <i class="fas fa-notes-medical text-success"></i> Pilih Pemeriksaan Baru
                </h6>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th width="60%">Pemeriksaan</th>
                                <th width="20%">Qty</th>
                                <th width="20%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="examTable">
                            <!-- Rows will be added here -->
                        </tbody>
                    </table>
                </div>

                <button type="button" class="btn btn-sm btn-success mb-3" onclick="addExamRow()">
                    <i class="fas fa-plus"></i> Tambah Pemeriksaan
                </button>

                <div class="d-flex justify-content-end gap-2">
                    <a href="index.php?page=booking" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var examOptions = '<option value="">Pilih pemeriksaan...</option>';
<?php while ($exam = $exams_result->fetch_assoc()): ?>
examOptions += '<option value="<?= $exam['id'] ?>"><?= htmlspecialchars($exam['nama_pemeriksaan']) ?></option>';
<?php endwhile; ?>

var defaultPax = <?= $booking['jumlah_pax'] ?>;

$(document).ready(function() {
    // Add first row
    addExamRow();
    
    // Handle form submit
    $('#formEditBooking').on('submit', function(e) {
        e.preventDefault();
        
        if ($('#examTable tr').length === 0) {
            showWarning('Minimal 1 pemeriksaan harus dipilih!');
            return;
        }
        
        var formData = $(this).serialize();
        
        $.ajax({
            url: 'actions/process_booking_edit.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSuccessRedirect(response.message, 'index.php?page=booking');
                } else {
                    showError(response.message);
                }
            },
            error: function() {
                showError('Terjadi kesalahan. Silakan coba lagi.');
            }
        });
    });
});

function addExamRow() {
    var idx = $('#examTable tr').length;
    var row = `<tr>
        <td>
            <select name="exams[${idx}][pemeriksaan_id]" class="form-select form-select-sm" required>
                ${examOptions}
            </select>
        </td>
        <td>
            <input type="number" name="exams[${idx}][qty]" class="form-control form-control-sm" min="1" value="${defaultPax}" required>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeExamRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#examTable').append(row);
}

function removeExamRow(btn) {
    if ($('#examTable tr').length > 1) {
        $(btn).closest('tr').remove();
    } else {
        showWarning('Minimal 1 pemeriksaan!');
    }
}
</script>
