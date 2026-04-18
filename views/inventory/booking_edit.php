<?php
check_role(['cs', 'super_admin', 'admin_klinik']);

if (!isset($_GET['id'])) {
    redirect('index.php?page=booking');
}

$id = intval($_GET['id']);

// Fetch booking
$booking = $conn->query("SELECT b.*, k.nama_klinik FROM inventory_booking_pemeriksaan b JOIN inventory_klinik k ON b.klinik_id = k.id WHERE b.id = $id")->fetch_assoc();

if (!$booking || !in_array($booking['status'], ['booked', 'pending_edit', 'rejected'])) {
    $_SESSION['error'] = 'Booking tidak ditemukan atau sudah diproses';
    redirect('index.php?page=booking');
}

$request_reason = $_GET['request_reason'] ?? '';

// Fetch all patients and their exams for editing
$patients_data = [];
$res_p = $conn->query("
    SELECT id, nama_pasien, nomor_tlp, tanggal_lahir, pemeriksaan_grup_id
    FROM inventory_booking_pasien
    WHERE booking_id = $id
    ORDER BY id ASC
");

while ($row = $res_p->fetch_assoc()) {
    $nama = $row['nama_pasien'];
    $tlp = (string)($row['nomor_tlp'] ?? '');
    $dob = (string)($row['tanggal_lahir'] ?? '');
    $exam_id = $row['pemeriksaan_grup_id'];
    
    // Grouping by patient data to handle multiple exams per patient
    $key = $nama . '|' . $tlp . '|' . $dob;
    if (!isset($patients_data[$key])) {
        $patients_data[$key] = [
            'nama' => $nama,
            'nomor_tlp' => $tlp,
            'tanggal_lahir' => $dob,
            'exams' => []
        ];
    }
    if ($exam_id) $patients_data[$key]['exams'][] = $exam_id;
}
$patients_json = json_encode(array_values($patients_data));

$role = (string)($_SESSION['role'] ?? '');
$can_cs_edit = in_array($role, ['cs', 'super_admin'], true);
$return_url = ($role === 'admin_klinik') ? 'index.php?page=booking&filter_today=1' : 'index.php?page=booking&show_all=1';
$kliniks = [];
if ($can_cs_edit) {
    $res = $conn->query("SELECT id, nama_klinik, status FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $kliniks[] = $row;
}
?>

<style>
    /* Use exact same styling as booking_list.php */
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
    .x-small { font-size: 0.75rem !important; }
</style>

<div class="modal fade" id="modalEditBookingReal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background-color:#204EAB;">
                <div>
                    <h5 class="modal-title fw-bold mb-0 text-white">
                        <i class="fas fa-edit me-2"></i>Edit Booking: <?= htmlspecialchars($booking['nomor_booking']) ?>
                    </h5>
                    <div class="small text-white">Ubah data booking, pasien, dan pemeriksaan.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formEditBookingReal" method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="booking_id" value="<?= $id ?>">
                <input type="hidden" name="request_reason" value="<?= htmlspecialchars($request_reason) ?>">
                
                <div class="modal-body">
                    <div id="bookingEditStockWarning" class="alert alert-warning py-2 small mb-3 d-none">
                        <i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong: proses tetap lanjut sesuai kebijakan, mohon follow up restock.
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                                    <div class="fw-bold"><i class="fas fa-tag me-2 text-primary-custom"></i>Info Booking</div>
                                    <div class="small text-muted">
                                        <i class="fas fa-user-tie me-1"></i>CS: <span class="fw-semibold text-primary"><?= htmlspecialchars($booking['cs_name'] ?? '-') ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Status Booking <span class="text-danger">*</span></label>
                                            <div class="segmented-control">
                                                <?php $sb = $booking['status_booking']; ?>
                                                <input type="radio" class="btn-check" name="new_status_booking" id="edit_status_clinic" value="Reserved - Clinic" <?= stripos($sb, 'Clinic') !== false ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_status_clinic">Clinic</label>
                                                
                                                <input type="radio" class="btn-check" name="new_status_booking" id="edit_status_hc" value="Reserved - HC" <?= stripos($sb, 'HC') !== false ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_status_hc">HC</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Klinik <span class="text-danger">*</span></label>
                                            <select name="new_klinik_id" id="edit_klinik_id" class="form-select" required>
                                                <?php 
                                                $k_res = $conn->query("SELECT * FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik");
                                                while($k = $k_res->fetch_assoc()): 
                                                ?>
                                                    <option value="<?= $k['id'] ?>" <?= (int)$k['id'] === (int)$booking['klinik_id'] ? 'selected' : '' ?>>
                                                        <?= $k['nama_klinik'] ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Jumlah Pax <span class="text-danger">*</span></label>
                                            <input type="number" name="jumlah_pax" id="edit_jumlah_pax" class="form-control" min="1" value="<?= (int)$booking['jumlah_pax'] ?>" required>
                                        </div>
                                        <div class="col-md-3" id="edit_order_id_container" style="<?= stripos($booking['status_booking'], 'HC') !== false ? '' : 'display: none;' ?>">
                                            <label class="form-label fw-semibold">Order ID</label>
                                            <input type="text" name="order_id" class="form-control" value="<?= htmlspecialchars($booking['order_id'] ?? '') ?>" placeholder="Opsional">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Jadwal Pemeriksaan <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="date" name="tanggal" id="edit_tanggal" class="form-control" value="<?= $booking['tanggal_pemeriksaan'] ?>" required>
                                                <input type="time" name="jam_layanan" id="edit_jam" class="form-control" value="<?= $booking['jam_layanan'] ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Tipe (Fixed/Keep) <span class="text-danger">*</span></label>
                                            <div class="segmented-control">
                                                <?php $bt = strtolower($booking['booking_type'] ?? 'keep'); ?>
                                                <input type="radio" class="btn-check" name="booking_type" id="edit_type_keep" value="keep" <?= $bt === 'keep' ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_type_keep">Keep</label>
                                                
                                                <input type="radio" class="btn-check" name="booking_type" id="edit_type_fixed" value="fixed" <?= $bt === 'fixed' ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_type_fixed">Fixed</label>

                                                <?php if ($can_cs_edit): ?>
                                                <input type="radio" class="btn-check" name="booking_type" id="edit_type_cancel" value="cancel" <?= $bt === 'cancel' ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_type_cancel">Cancel</label>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Jotform Submitted <span class="text-danger">*</span></label>
                                            <div class="segmented-control">
                                                <?php $jf = (int)$booking['jotform_submitted']; ?>
                                                <input type="radio" class="btn-check" name="jotform_submitted" id="edit_jf_no" value="0" <?= $jf === 0 ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_jf_no">Belum</label>
                                                
                                                <input type="radio" class="btn-check" name="jotform_submitted" id="edit_jf_yes" value="1" <?= $jf === 1 ? 'checked' : '' ?>>
                                                <label class="btn-segmented" for="edit_jf_yes">Sudah</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dynamic Pax Sections -->
                            <div id="editPaxSectionsWrapper"></div>

                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-bold">
                                    <i class="fas fa-sticky-note me-2 text-primary-custom"></i>Catatan
                                </div>
                                <div class="card-body">
                                    <textarea name="catatan" class="form-control" rows="3"><?= htmlspecialchars($booking['catatan'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitEditBooking">
                        <i class="fas fa-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var examOptionsEdit = '<option value="">Pilih klinik dulu...</option>';
    var initialPatients = <?= $patients_json ?>;

    function renderPaxSectionsEdit(paxCount, existingData = null) {
        var $wrapper = $('#editPaxSectionsWrapper');
        if (!$wrapper.length) return;

        if (!existingData) {
            existingData = [];
            $wrapper.find('.pax-section-card').each(function(idx) {
                var exams = [];
                $(this).find('.patient-exam-select').each(function() { 
                    var val = $(this).val();
                    if (val) exams.push(val); 
                });
                existingData[idx] = {
                    nama: $(this).find('input[name*="[nama]"]').val(),
                    nomor_tlp: $(this).find('input[name*="[nomor_tlp]"]').val(),
                    tanggal_lahir: $(this).find('input[name*="[tanggal_lahir]"]').val(),
                    exams: exams
                };
            });
        }

        var inheritExamId = (existingData[0] && existingData[0].exams && existingData[0].exams.length > 0) ? existingData[0].exams[0] : '';

        $wrapper.empty();
        for (var i = 0; i < paxCount; i++) {
            var num = i + 1;
            var data = existingData[i] || { nama: '', nomor_tlp: '', tanggal_lahir: '', exams: [] };
            
            var card = `
                <div class="card border-0 shadow-sm mb-3 pax-section-card" data-patient-idx="${i}">
                    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center py-2">
                        <span class="small"><i class="fas fa-user me-2 text-primary"></i>Pasien ${num} ${i === 0 ? '(Utama)' : ''}</span>
                        ${i > 0 ? '<span class="badge bg-light text-muted fw-normal x-small border">Opsional</span>' : ''}
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label x-small fw-semibold mb-1">Nama Pasien ${i === 0 ? '<span class="text-danger">*</span>' : ''}</label>
                                <input type="text" name="patients[${i}][nama]" class="form-control form-control-sm" value="${data.nama || ''}" ${i === 0 ? 'required' : ''}>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label x-small fw-semibold mb-1">Nomor Tlp</label>
                                <input type="text" name="patients[${i}][nomor_tlp]" class="form-control form-control-sm" value="${data.nomor_tlp || ''}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label x-small fw-semibold mb-1">Tanggal Lahir</label>
                                <input type="date" name="patients[${i}][tanggal_lahir]" class="form-control form-control-sm" value="${data.tanggal_lahir || ''}">
                            </div>
                        </div>
                        <div class="pemeriksaan-section">
                            <label class="form-label x-small fw-bold text-success mb-1"><i class="fas fa-notes-medical me-1"></i>Pemeriksaan</label>
                            <div class="patient-exams-list" data-patient-idx="${i}"></div>
                            <button type="button" class="btn btn-link btn-sm text-success p-0 mt-0 x-small btn-add-exam" data-patient-idx="${i}">
                                <i class="fas fa-plus-circle me-1"></i>Tambah Pemeriksaan
                            </button>
                        </div>
                    </div>
                </div>`;
            $wrapper.append(card);
            
            if (data.exams && data.exams.length > 0) {
                data.exams.forEach(function(examId) { addPatientExamRowEdit(i, examId); });
            } else {
                addPatientExamRowEdit(i, (i > 0 ? inheritExamId : ''));
            }
        }
    }

    function addPatientExamRowEdit(patientIdx, selectedId = '') {
        var $list = $(`.patient-exams-list[data-patient-idx="${patientIdx}"]`);
        var rowIdx = $list.find('.exam-row').length;
        var row = `
            <div class="row g-2 mb-1 exam-row" data-row-idx="${rowIdx}">
                <div class="col">
                    <select name="patients[${patientIdx}][exams][]" class="form-select form-select-sm patient-exam-select" data-patient-idx="${patientIdx}" required>
                        ${examOptionsEdit}
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-danger btn-sm border-0 py-0 btn-remove-exam">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>`;
        $list.append(row);
        var $select = $list.find('.exam-row').last().find('select');
        if (selectedId) $select.val(selectedId);
        if (typeof $select.select2 === 'function') {
            var $modal = $('#modalEditBookingReal');
            if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
            $select.select2({ 
                theme: 'bootstrap-5', 
                width: '100%', 
                dropdownParent: ($modal.length ? $modal : $(document.body)),
                templateResult: formatExamOptionEdit,
                templateSelection: formatExamOptionEdit
            });
            if (selectedId) $select.trigger('change');
        }
        checkEditSelectedStock();
    }

    function formatExamOptionEdit(state) {
        if (!state.id) return state.text;
        var isAvailable = $(state.element).data('available');
        var $state = $('<span>' + state.text + '</span>');
        if (isAvailable == 0) $state.addClass('text-danger fw-bold');
        return $state;
    }

    let __editOosPreviewTimer = null;
    let __editOosPreviewXhr = null;
    function checkEditSelectedStock() {
        const examIds = [];
        let hasOutOfStock = false;
        $('.patient-exam-select').each(function() {
            const $opt = $(this).find('option:selected');
            const exId = parseInt($opt.val() || '0', 10);
            if (exId > 0) examIds.push(exId);
            if ($opt.data('available') == 0) hasOutOfStock = true;
        });
        const $w = $('#bookingEditStockWarning');
        if (!hasOutOfStock) {
            $w.addClass('d-none');
            return;
        }

        if (__editOosPreviewTimer) clearTimeout(__editOosPreviewTimer);
        __editOosPreviewTimer = setTimeout(function() {
            if (__editOosPreviewXhr && __editOosPreviewXhr.readyState !== 4) {
                try { __editOosPreviewXhr.abort(); } catch (e) {}
            }
            const klinikId = parseInt($('#edit_klinik_id').val() || '0', 10);
            const statusBooking = $('input[name="new_status_booking"]:checked').val() || '';
            const csrf = $('#formEditBookingReal input[name="_csrf"]').val() || '';
            __editOosPreviewXhr = $.ajax({
                url: 'api/get_core_oos_items.php',
                method: 'POST',
                dataType: 'json',
                data: { _csrf: csrf, klinik_id: klinikId, status_booking: statusBooking, exam_ids: examIds }
            }).done(function(res) {
                if (!res || !res.success || !res.items || res.items.length === 0) {
                    $w.html('<i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong: proses tetap lanjut sesuai kebijakan, mohon follow up restock.');
                    $w.removeClass('d-none');
                    return;
                }
                const list = res.items.map(function(x){ return '<li>' + $('<div>').text(x).html() + '</li>'; }).join('');
                $w.html(
                    '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Peringatan:</strong> Core kosong (item):' +
                    '<ul class="mb-1 mt-1 ps-4">' + list + '</ul>' +
                    '<span class="small opacity-75">Proses tetap lanjut sesuai kebijakan, mohon follow up restock.</span>'
                );
                $w.removeClass('d-none');
            }).fail(function() {
                $w.removeClass('d-none');
            });
        }, 250);
    }

    $(document).on('change', '.patient-exam-select', function() {
        checkEditSelectedStock();
    });

    function loadExamOptionsEdit(klinikId, callback) {
        var statusBooking = $('input[name=\"new_status_booking\"]:checked').val() || '';
        $.ajax({
            url: 'api/get_exam_availability.php',
            method: 'GET',
            data: { klinik_id: klinikId, status_booking: statusBooking },
            dataType: 'json',
            success: function(data) {
                examOptionsEdit = '<option value="">Pilih pemeriksaan...</option>';
                if (data && data.length > 0) {
                    data.forEach(function(exam) {
                        var readyText = '';
                        if (exam.no_mapping) {
                            readyText = '(Input Manual di BHP)';
                        } else {
                            readyText = exam.is_available ? `(Ready: ${exam.qty})` : '(STOK KOSONG)';
                        }
                        var textClass = exam.is_available ? '' : 'text-danger';
                        examOptionsEdit += `<option value="${exam.id}" data-available="${exam.is_available ? 1 : 0}" class="${textClass}">${exam.name} ${readyText}</option>`;
                    });
                } else {
                    examOptionsEdit = '<option value="">Tidak ada pemeriksaan tersedia</option>';
                }
                $('.patient-exam-select').each(function() {
                    var val = $(this).val();
                    $(this).html(examOptionsEdit).val(val);
                    if (typeof $(this).select2 === 'function') {
                        var $modal = $('#modalEditBookingReal');
                        if ($(this).hasClass('select2-hidden-accessible')) {
                            $(this).trigger('change.select2');
                        } else {
                            $(this).select2({ 
                                theme: 'bootstrap-5', 
                                width: '100%', 
                                dropdownParent: ($modal.length ? $modal : $(document.body)),
                                templateResult: formatExamOptionEdit,
                                templateSelection: formatExamOptionEdit
                            });
                        }
                    }
                });
                checkEditSelectedStock();
                if (callback) callback();
            }
        });
    }

    $(document).ready(function() {
        var modalEl = document.getElementById('modalEditBookingReal');
        var $modalEl = $(modalEl);
        var modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });
        modal.show();

        modalEl.addEventListener('hidden.bs.modal', function() {
            $(this).remove();
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css('overflow', '');
        });

        // Initial Data Load
        var initialKlinikId = $('#edit_klinik_id').val();
        loadExamOptionsEdit(initialKlinikId, function() {
            renderPaxSectionsEdit($('#edit_jumlah_pax').val(), initialPatients);
        });

        // Events
        $modalEl.find('#edit_jumlah_pax').on('input change', function() {
            renderPaxSectionsEdit(parseInt($(this).val()) || 1);
        });

        $modalEl.find('#edit_klinik_id').on('change', function() {
            var statusBooking = $('input[name=\"new_status_booking\"]:checked').val();
            if (statusBooking === 'Reserved - HC') {
                $('#edit_order_id_container').show();
            } else {
                $('#edit_order_id_container').hide();
                $('#edit_order_id_container input').val('');
            }
            loadExamOptionsEdit($('#edit_klinik_id').val());
        });
        $modalEl.find('input[name=\"new_status_booking\"]').on('change', function() {
            var statusBooking = $('input[name=\"new_status_booking\"]:checked').val();
            if (statusBooking === 'Reserved - HC') {
                $('#edit_order_id_container').show();
            } else {
                $('#edit_order_id_container').hide();
                $('#edit_order_id_container input').val('');
            }
            loadExamOptionsEdit($('#edit_klinik_id').val());
        });

        $modalEl.on('click', '.btn-add-exam', function() {
            addPatientExamRowEdit($(this).data('patient-idx'));
        });

        $modalEl.on('click', '.btn-remove-exam', function() {
            var $list = $(this).closest('.patient-exams-list');
            if ($list.find('.exam-row').length > 1) $(this).closest('.exam-row').remove();
            else showWarning('Minimal 1 pemeriksaan!');
        });

        $modalEl.find('#formEditBookingReal').on('submit', function(e) {
            e.preventDefault();
            var $btn = $('#btnSubmitEditBooking');
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...');
            
            $.ajax({
                url: 'actions/process_booking_edit.php',
                method: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showSuccessRedirect(res.message || 'Berhasil diupdate', 'index.php?page=booking');
                    } else {
                        showError(res.message);
                        $btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> Simpan Perubahan');
                    }
                },
                error: function() {
                    showError('Gagal memproses data');
                    $btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> Simpan Perubahan');
                }
            });
        });
    });
})();
</script>
