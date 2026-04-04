<?php
check_role(['cs', 'super_admin', 'admin_klinik']);

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

$jenis_now = '';
$rj = $conn->query("
    SELECT GROUP_CONCAT(DISTINCT pg.nama_pemeriksaan ORDER BY pg.nama_pemeriksaan SEPARATOR ', ') AS jenis
    FROM booking_pasien bp
    JOIN pemeriksaan_grup pg ON bp.pemeriksaan_grup_id = pg.id
    WHERE bp.booking_id = $id
");
if ($rj && $rj->num_rows > 0) $jenis_now = (string)($rj->fetch_assoc()['jenis'] ?? '');

// Fetch pasien tambahan (selain pasien utama)
$extra_pasien_db = [];
$rp = $conn->query("
    SELECT DISTINCT nama_pasien, nomor_tlp, tanggal_lahir
    FROM booking_pasien
    WHERE booking_id = $id
    ORDER BY id ASC
");
$is_first = true;
while ($rp && ($rowp = $rp->fetch_assoc())) {
    if ($is_first) { $is_first = false; continue; } // skip pasien utama
    $extra_pasien_db[] = $rowp;
}

$role = (string)($_SESSION['role'] ?? '');
$can_cs_edit = in_array($role, ['cs', 'super_admin'], true);
$return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
$kliniks = [];
if ($can_cs_edit) {
    $res = $conn->query("SELECT id, nama_klinik, status FROM klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $kliniks[] = $row;
}
?>

<style>
    /* Segmented Control Styling */
    .segmented-control {
        display: flex;
        background-color: #f1f3f5;
        border-radius: 8px;
        padding: 4px;
        border: 1px solid #dee2e6;
    }
    .segmented-control .btn-check:checked + .btn-segmented {
        background-color: #fff;
        color: #204EAB;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-color: transparent;
        font-weight: 600;
    }
    .btn-segmented {
        flex: 1;
        border: none;
        background: transparent;
        color: #6c757d;
        font-size: 0.85rem;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.2s ease;
        text-align: center;
        cursor: pointer;
    }
    .btn-segmented:hover {
        background-color: rgba(0,0,0,0.03);
    }
</style>

<div class="container-fluid">
    <div class="modal fade" id="modalEditBooking" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header text-white" style="background-color: #204EAB;">
                    <div>
                        <div class="fw-bold">Edit Booking</div>
                        <div class="small opacity-75">
                            <?= htmlspecialchars((string)($booking['nomor_booking'] ?? '')) ?>
                            · <?= htmlspecialchars((string)($booking['nama_klinik'] ?? '')) ?>
                            · <?= htmlspecialchars(date('d M Y', strtotime((string)($booking['tanggal_pemeriksaan'] ?? '')))) ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-light">
                    <form id="formEditBooking" method="POST" action="actions/process_booking_edit.php">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                        <input type="hidden" name="booking_id" value="<?= $id ?>">

                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <div class="row g-3">
                    <?php if ($can_cs_edit): ?>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Pindah Klinik <span class="text-danger">*</span></label>
                        <select name="new_klinik_id" id="new_klinik_id" class="form-select" required>
                            <?php foreach ($kliniks as $k): ?>
                                <option value="<?= (int)$k['id'] ?>" <?= (int)$k['id'] === (int)$booking['klinik_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($k['nama_klinik']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tujuan (Move) <span class="text-danger">*</span></label>
                        <?php $sb = (string)($booking['status_booking'] ?? 'Reserved - Clinic'); ?>
                        <select name="new_status_booking" id="new_status_booking" class="form-select" required>
                            <option value="Reserved - Clinic" <?= stripos($sb, 'Clinic') !== false ? 'selected' : '' ?>>Reserved - Clinic</option>
                            <option value="Reserved - HC" <?= stripos($sb, 'HC') !== false ? 'selected' : '' ?>>Reserved - HC</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Jumlah Pax <span class="text-danger">*</span></label>
                        <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control" min="1" value="<?= (int)($booking['jumlah_pax'] ?? 1) ?>" required>
                    </div>
                    <?php endif; ?>
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

                    <!-- Pasien Tambahan -->
                    <?php if (!empty($extra_pasien_db) || (int)($booking['jumlah_pax'] ?? 1) > 1): ?>
                    <div class="col-12" id="extraPasienEditWrapper">
                        <div class="fw-semibold small text-muted mb-2"><i class="fas fa-users me-1"></i>Data Pasien Tambahan</div>
                        <div id="extraPasienEditList">
                        <?php
                        $pax_total = (int)($booking['jumlah_pax'] ?? 1);
                        for ($pi = 0; $pi < $pax_total - 1; $pi++):
                            $ep = $extra_pasien_db[$pi] ?? [];
                        ?>
                        <div class="border rounded p-2 mb-2 bg-light">
                            <div class="small fw-semibold text-primary mb-2">Pasien <?= $pi + 2 ?></div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <input type="text" name="extra_pasien[<?= $pi ?>][nama]" class="form-control form-control-sm"
                                        placeholder="Nama Pasien <?= $pi + 2 ?>"
                                        value="<?= htmlspecialchars($ep['nama_pasien'] ?? '') ?>" required>
                                </div>
                                <div class="col-6">
                                    <input type="text" name="extra_pasien[<?= $pi ?>][nomor_tlp]" class="form-control form-control-sm"
                                        placeholder="Nomor Tlp"
                                        value="<?= htmlspecialchars($ep['nomor_tlp'] ?? '') ?>">
                                </div>
                                <div class="col-6">
                                    <input type="date" name="extra_pasien[<?= $pi ?>][tanggal_lahir]" class="form-control form-control-sm"
                                        value="<?= htmlspecialchars($ep['tanggal_lahir'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Tindakan <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal" id="edit_booking_tanggal" class="form-control" value="<?= htmlspecialchars($booking['tanggal_pemeriksaan']) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Jam Layanan</label>
                        <input type="time" name="jam_layanan" id="edit_booking_jam" class="form-control" value="<?= htmlspecialchars($booking['jam_layanan'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tipe (Keep/Fixed) <span class="text-danger">*</span></label>
                        <?php $bt = strtolower((string)($booking['booking_type'] ?? 'keep')); ?>
                        <div class="segmented-control">
                            <input type="radio" class="btn-check" name="booking_type" id="edit_type_keep" value="keep" <?= $bt === 'keep' ? 'checked' : '' ?>>
                            <label class="btn-segmented" for="edit_type_keep">Keep</label>
                            
                            <input type="radio" class="btn-check" name="booking_type" id="edit_type_fixed" value="fixed" <?= $bt === 'fixed' ? 'checked' : '' ?>>
                            <label class="btn-segmented" for="edit_type_fixed">Fixed</label>

                            <?php if (in_array($_SESSION['role'] ?? '', ['cs', 'super_admin'], true)): ?>
                                <input type="radio" class="btn-check" name="booking_type" id="edit_type_cancel" value="cancel" <?= $bt === 'cancel' ? 'checked' : '' ?>>
                                <label class="btn-segmented" for="edit_type_cancel">Cancel</label>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Jotform Submitted <span class="text-danger">*</span></label>
                        <?php $jf = (int)($booking['jotform_submitted'] ?? 0); ?>
                        <div class="segmented-control">
                            <input type="radio" class="btn-check" name="jotform_submitted" id="edit_jotform_no" value="0" <?= $jf === 0 ? 'checked' : '' ?>>
                            <label class="btn-segmented" for="edit_jotform_no">Belum</label>
                            
                            <input type="radio" class="btn-check" name="jotform_submitted" id="edit_jotform_yes" value="1" <?= $jf === 1 ? 'checked' : '' ?>>
                            <label class="btn-segmented" for="edit_jotform_yes">Sudah</label>
                        </div>
                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="fw-bold">
                                        Pilih Pemeriksaan
                                    </div>
                                    <button type="button" class="btn btn-sm btn-success" onclick="addExamRow()">
                                        <i class="fas fa-plus"></i> Tambah Pemeriksaan
                                    </button>
                                </div>
                                <div class="small text-muted mb-3">
                                    Qty pemeriksaan otomatis mengikuti Jumlah Pax.
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm bg-white">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="70%">Pemeriksaan</th>
                                                <th width="15%">Qty</th>
                                                <th width="15%">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="examTable"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer bg-white">
                    <a href="<?= htmlspecialchars($return_url, ENT_QUOTES) ?>" class="btn btn-outline-secondary px-4">
                        Batal
                    </a>
                    <button type="submit" form="formEditBooking" class="btn btn-primary px-4">
                        Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var examOptions = '<option value="">Memuat pemeriksaan...</option>';
var klinikId = <?= (int)$booking['klinik_id'] ?>;
var statusBooking = <?= json_encode((string)($booking['status_booking'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
var examRowIndex = 0;

$(document).ready(function() {
    var modal = document.getElementById('modalEditBooking');
    if (modal && window.bootstrap) {
        var inst = bootstrap.Modal.getOrCreateInstance(modal, { backdrop: 'static' });
        inst.show();
        modal.addEventListener('hidden.bs.modal', function() {
            window.location.href = <?= json_encode($return_url, JSON_UNESCAPED_SLASHES) ?>;
        });
    }

    loadExamOptions();

    // Real-time validation for date and time
    $('#edit_booking_tanggal, #edit_booking_jam').on('change', function() {
        const selectedDate = $('#edit_booking_tanggal').val();
        const selectedTime = $('#edit_booking_jam').val();
        if (!selectedDate) return;

        const now = new Date();
        const year = now.getFullYear();
        const month = (now.getMonth() + 1).toString().padStart(2, '0');
        const day = now.getDate().toString().padStart(2, '0');
        const today = `${year}-${month}-${day}`;
        const currentTime = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

        if (selectedDate < today) {
            showError('Tanggal booking tidak boleh backdate!');
            $('#edit_booking_tanggal').val(today);
        } else if (selectedDate === today && selectedTime && selectedTime < currentTime) {
            showError('Jam layanan tidak boleh mundur dari jam sekarang!');
            $('#edit_booking_jam').val(currentTime);
        }
    });

    <?php if ($can_cs_edit): ?>
    $('#new_klinik_id, #new_status_booking').on('change', function() {
        klinikId = parseInt($('#new_klinik_id').val() || '0', 10) || klinikId;
        statusBooking = String($('#new_status_booking').val() || statusBooking);
        loadExamOptions();
    });
    $('#jumlah_pax').on('input change', function() {
        syncQtyToPax();
        syncExtraPasienFields();
    });
    <?php endif; ?>
    
    // Handle form submit
    $('#formEditBooking').on('submit', function(e) {
        e.preventDefault();

        // Validate Backdate & Backtime (Local Time)
        const selectedDate = $('#edit_booking_tanggal').val();
        const selectedTime = $('#edit_booking_jam').val();
        const now = new Date();
        const year = now.getFullYear();
        const month = (now.getMonth() + 1).toString().padStart(2, '0');
        const day = now.getDate().toString().padStart(2, '0');
        const today = `${year}-${month}-${day}`;
        
        if (selectedDate < today) {
            showError('Tanggal booking tidak boleh backdate!');
            return;
        }
        
        if (selectedDate === today && selectedTime) {
            const currentTime = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
            if (selectedTime < currentTime) {
                showError('Jam layanan tidak boleh mundur dari jam sekarang!');
                return;
            }
        }

        var $btn = $('#formEditBooking button[type="submit"]');
        if ($btn.prop('disabled')) return;
        
        if ($('#examTable tr').length === 0) {
            showWarning('Minimal 1 pemeriksaan harus dipilih!');
            return;
        }
        
        var formData = $(this).serialize();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
        
        $.ajax({
            url: 'actions/process_booking_edit.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSuccessRedirect(response.message, <?= json_encode($return_url, JSON_UNESCAPED_SLASHES) ?>);
                } else {
                    showError(response.message);
                    $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Perubahan');
                }
            },
            error: function() {
                showError('Terjadi kesalahan. Silakan coba lagi.');
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Perubahan');
            }
        });
    });
});

function loadExamOptions() {
    $.ajax({
        url: 'api/get_exam_availability.php',
        method: 'GET',
        data: { klinik_id: klinikId, status_booking: statusBooking },
        dataType: 'json',
        success: function(data) {
            examOptions = '<option value="">Pilih pemeriksaan...</option>';
            if (Array.isArray(data) && data.length > 0) {
                data.forEach(function(exam) {
                    examOptions += `<option value="${exam.id}">${exam.name} (Ready: ${exam.qty})</option>`;
                });
            } else {
                examOptions = '<option value="">Tidak ada pemeriksaan available</option>';
            }
            updateAllExamSelects();
            if ($('#examTable tr').length === 0) addExamRow();
        },
        error: function() {
            examOptions = '<option value="">Error loading data</option>';
            updateAllExamSelects();
            if ($('#examTable tr').length === 0) addExamRow();
        }
    });
}

function updateAllExamSelects() {
    $('.exam-select-modal').each(function() {
        var currentVal = $(this).val();
        $(this).html(examOptions);
        if (currentVal) $(this).val(currentVal);
    });
}

function addExamRow() {
    var idx = examRowIndex++;
    var pax = getCurrentPax();
    var row = `<tr>
        <td>
            <select name="exams[${idx}][pemeriksaan_id]" class="form-select form-select-sm exam-select-modal" required>
                ${examOptions}
            </select>
        </td>
        <td>
            <input type="number" name="exams[${idx}][qty]" class="form-control form-control-sm exam-qty" min="1" value="${pax}" required readonly>
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

function getCurrentPax() {
    var el = document.getElementById('jumlah_pax');
    var pax = el ? parseInt(el.value || '0', 10) : 0;
    if (!pax || pax < 1) pax = <?= (int)($booking['jumlah_pax'] ?? 1) ?>;
    return pax;
}

function syncQtyToPax() {
    var pax = getCurrentPax();
    $('.exam-qty').each(function() {
        $(this).val(pax);
    });
}

function syncExtraPasienFields() {
    var pax = getCurrentPax();
    var extra = pax - 1;
    var $wrapper = $('#extraPasienEditWrapper');
    var $list = $('#extraPasienEditList');

    if (extra <= 0) {
        $wrapper.hide();
        $list.empty();
        return;
    }

    $wrapper.show();

    // Add rows if needed
    var current = $list.children('.extra-pasien-row').length;
    for (var i = current; i < extra; i++) {
        var num = i + 2;
        $list.append(
            '<div class="border rounded p-2 mb-2 bg-light extra-pasien-row">' +
                '<div class="small fw-semibold text-primary mb-2">Pasien ' + num + '</div>' +
                '<div class="row g-2">' +
                    '<div class="col-12">' +
                        '<input type="text" name="extra_pasien[' + i + '][nama]" class="form-control form-control-sm" placeholder="Nama Pasien ' + num + '" required>' +
                    '</div>' +
                    '<div class="col-6">' +
                        '<input type="text" name="extra_pasien[' + i + '][nomor_tlp]" class="form-control form-control-sm" placeholder="Nomor Tlp">' +
                    '</div>' +
                    '<div class="col-6">' +
                        '<input type="date" name="extra_pasien[' + i + '][tanggal_lahir]" class="form-control form-control-sm">' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
    }

    // Remove rows if pax decreased
    while ($list.children('.extra-pasien-row').length > extra) {
        $list.children('.extra-pasien-row').last().remove();
    }
}
</script>
