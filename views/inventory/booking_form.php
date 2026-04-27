<?php
check_role(['cs', 'super_admin']);

// Check CS Name for display
$cs_name = '';
try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $r = $conn->query("SELECT nama_lengkap FROM inventory_users WHERE id = $uid LIMIT 1");
        if ($r && $r->num_rows > 0) $cs_name = (string)($r->fetch_assoc()['nama_lengkap'] ?? '');
    }
} catch (Exception $e) {
    $cs_name = '';
}

// Fetch Master Data
$kliniks = [];
$klinik_res = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status = 'active' ORDER BY nama_klinik ASC");
while($r = $klinik_res->fetch_assoc()) $kliniks[] = $r;

// POST Handling
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $status_booking = $_POST['status_booking'];
    $klinik_id = (int)$_POST['klinik_id'];
    $tanggal = $_POST['tanggal'];
    $order_id = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
    $booking_type = $_POST['booking_type'] ?? 'keep';
    $jam_layanan = $_POST['jam_layanan'] ?? null;
    $jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;
    $catatan = !empty($_POST['catatan']) ? $_POST['catatan'] : null;
    
    $patients = $_POST['patients'] ?? [];
    $jumlah_pax = (int)$_POST['jumlah_pax'];
    
    if ($jumlah_pax > 10) {
        echo "<script>alert('Maksimal jumlah pax adalah 10!');</script>";
    } elseif (empty($patients)) {
        echo "<script>alert('Data pasien tidak boleh kosong!');</script>";
    } else {
        $nama_pemesan = trim((string)($patients[0]['nama'] ?? ''));
        $nomor_tlp = !empty($patients[0]['nomor_tlp']) ? trim((string)$patients[0]['nomor_tlp']) : null;
        $tanggal_lahir = !empty($patients[0]['tanggal_lahir']) ? (string)$patients[0]['tanggal_lahir'] : null;
        $created_by = $_SESSION['user_id'];

        $conn->begin_transaction();
        try {
            // 1. Calculate total needed items
            $total_needed = [];
            foreach ($patients as $p) {
                $p_exams = $p['exams'] ?? [];
                foreach ($p_exams as $pid) {
                    $pid = (int)$pid;
                    $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
                    while($row = $res->fetch_assoc()) {
                        $bid = $row['barang_id'];
                        $qty = $row['qty_per_pemeriksaan'];
                        if (!isset($total_needed[$bid])) $total_needed[$bid] = 0;
                        $total_needed[$bid] += $qty;
                    }
                }
            }

            if (empty($total_needed)) throw new Exception("Tidak ada item yang perlu dibooking (cek master pemeriksaan).");

            // 2. Check Availability
            require_once __DIR__ . '/../../lib/stock.php';
            $is_hc = (stripos((string)$status_booking, 'HC') !== false);
            foreach ($total_needed as $bid => $qty_need) {
                $ef = stock_effective($conn, (int)$klinik_id, $is_hc, (int)$bid);
                if (!$ef['ok']) throw new Exception("Gagal menghitung stok untuk ID:$bid: " . $ef['message']);
                if ((float)$ef['available'] < (float)$qty_need - 0.00005) {
                    $bname = (string)($ef['barang_name'] ?? ("ID:$bid"));
                    throw new Exception("Stok tidak cukup untuk $bname. Tersedia: " . fmt_qty($ef['available']) . ", Butuh: " . fmt_qty($qty_need));
                }
            }

            // 3. Create Booking Header
            $nomor = "BK-TMP-" . time();
            $stmt = $conn->prepare("INSERT INTO inventory_booking_pemeriksaan (nomor_booking, order_id, klinik_id, status_booking, booking_type, jam_layanan, jotform_submitted, cs_name, nama_pemesan, nomor_tlp, tanggal_lahir, jumlah_pax, catatan, tanggal_pemeriksaan, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?, NOW())");
            $stmt->bind_param("ssisssissssissi", $nomor, $order_id, $klinik_id, $status_booking, $booking_type, $jam_layanan, $jotform_submitted, $cs_name, $nama_pemesan, $nomor_tlp, $tanggal_lahir, $jumlah_pax, $catatan, $tanggal, $created_by);
            $stmt->execute();
            $book_id = $conn->insert_id;
            $nomor_final = "BK-" . str_pad((string)$book_id, 6, '0', STR_PAD_LEFT);
            $conn->query("UPDATE inventory_booking_pemeriksaan SET nomor_booking = '$nomor_final' WHERE id = $book_id");

            // 4. Insert Patients and Details
            $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id, nomor_tlp, tanggal_lahir) VALUES (?, ?, ?, ?, ?)");
            $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($patients as $idx => $p) {
                $pnama = !empty($p['nama']) ? $p['nama'] : "Pasien " . ($idx + 1);
                $ptlp  = !empty($p['nomor_tlp']) ? trim($p['nomor_tlp']) : null;
                $ptgl  = !empty($p['tanggal_lahir']) ? $p['tanggal_lahir'] : null;
                $p_exams = $p['exams'] ?? [];

                foreach ($p_exams as $pid) {
                    $pid = (int)$pid;
                    $stmt_pasien->bind_param("isiss", $book_id, $pnama, $pid, $ptlp, $ptgl);
                    $stmt_pasien->execute();
                    $pasien_id = $conn->insert_id;

                    $res_items = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
                    while($row = $res_items->fetch_assoc()) {
                        $qty_unit = $row['qty_per_pemeriksaan'];
                        $qty_onsite = (stripos($status_booking, 'Clinic') !== false) ? $qty_unit : 0;
                        $qty_hc = (stripos($status_booking, 'HC') !== false) ? $qty_unit : 0;
                        $stmt_detail->bind_param("iiiddd", $book_id, $pasien_id, $row['barang_id'], $qty_unit, $qty_onsite, $qty_hc);
                        $stmt_detail->execute();
                    }
                }
            }

            $conn->commit();
            echo "<script>alert('Booking berhasil dibuat!'); window.location='index.php?page=booking';</script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
        }
    }
}
?>

<style>
    .pax-section-card {
        border: 1px solid #e2e8f0 !important;
        border-left: 5px solid #204EAB !important;
        border-radius: 12px !important;
        transition: transform 0.2s, box-shadow 0.2s;
        margin-bottom: 1.5rem;
    }
    .pax-card-header {
        background: #fff !important;
        border-bottom: 1px solid #f1f5f9 !important;
        padding: 12px 16px !important;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .pax-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }
    .pax-title i { color: #204EAB; margin-right: 8px; }
    .pax-label-minimal {
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 4px;
        text-transform: uppercase;
    }
    .pax-exam-container {
        background: #f8fafc;
        border-radius: 10px;
        padding: 12px;
        margin-top: 10px;
    }
    .pax-exam-label {
        font-size: 0.75rem;
        font-weight: 800;
        color: #20AB5C;
        margin-bottom: 8px;
        text-transform: uppercase;
    }
    .x-small { font-size: 0.75rem !important; }
    #jumlah_pax { pointer-events: none; background-color: #f8f9fa; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 text-primary-custom">Buat Booking Baru</h2>
    <a href="index.php?page=booking" class="btn btn-secondary">Kembali</a>
</div>

<form method="POST" id="bookingForm">
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white py-2">
            <i class="fas fa-info-circle me-2"></i>Informasi Utama
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="fw-bold">Status Booking <span class="text-danger">*</span></label>
                    <select name="status_booking" id="status_booking" class="form-select" required>
                        <option value="">- Pilih -</option>
                        <option value="Reserved - Clinic">Reserved - Clinic</option>
                        <option value="Reserved - HC">Reserved - HC</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">Klinik <span class="text-danger">*</span></label>
                    <select name="klinik_id" id="klinik_id" class="form-select" required>
                        <option value="">- Pilih Klinik -</option>
                        <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['nama_klinik'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">Jumlah Pax</label>
                    <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control" value="1" readonly>
                </div>
                <div class="col-md-3" id="order_id_container" style="display: none;">
                    <label class="fw-bold">Order ID</label>
                    <input type="text" name="order_id" class="form-control" placeholder="Opsional">
                </div>

                <div class="col-md-3">
                    <label class="fw-bold">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">Jam Layanan</label>
                    <input type="time" name="jam_layanan" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">Tipe (Fixed/Keep) <span class="text-danger">*</span></label>
                    <select name="booking_type" class="form-select" required>
                        <option value="keep" selected>Keep</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">Jotform Submitted <span class="text-danger">*</span></label>
                    <select name="jotform_submitted" class="form-select" required>
                        <option value="0" selected>Belum</option>
                        <option value="1">Sudah</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Identitas Pasien -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-primary-custom mb-0"><i class="fas fa-users me-2"></i>Identitas Pasien</h5>
        <button type="button" class="btn btn-primary" id="btnAddPatient">
            <i class="fas fa-plus me-1"></i> Tambah Pasien
        </button>
    </div>
    
    <div id="paxSectionsWrapper"></div>

    <div class="card mb-4">
        <div class="card-body">
            <label class="fw-bold mb-2">Catatan Tambahan</label>
            <textarea name="catatan" class="form-control" rows="3" placeholder="Opsional..."></textarea>
        </div>
    </div>

    <div class="text-end mb-5">
        <button type="submit" class="btn btn-lg btn-success px-5 shadow">
            <i class="fas fa-save me-2"></i>Simpan Booking
        </button>
    </div>
</form>

<script>
(function() {
    var examOptions = '<option value="">Pilih klinik dulu...</option>';
    var $wrapper = $('#paxSectionsWrapper');
    var $jumlahPax = $('#jumlah_pax');

    function renderPaxSections(paxCount) {
        var existingData = [];
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

        $wrapper.empty();
        for (var i = 0; i < paxCount; i++) {
            var num = i + 1;
            var data = existingData[i] || { nama: '', nomor_tlp: '', tanggal_lahir: '', exams: [] };
            
            var card = `
                <div class="card pax-section-card shadow-sm" data-patient-idx="${i}">
                    <div class="pax-card-header">
                        <div class="pax-title">
                            <i class="fas fa-user-circle"></i>Pasien ${num}
                            ${i === 0 ? '<span class="badge bg-primary ms-2" style="font-size:0.65rem">UTAMA</span>' : ''}
                        </div>
                        <div>
                            <button type="button" class="btn btn-outline-danger btn-sm border-0 btn-remove-patient" data-idx="${i}">
                                <i class="fas fa-trash-alt me-1"></i><span class="x-small fw-bold">HAPUS</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="pax-label-minimal">Nama Pasien <span class="text-danger">*</span></label>
                                <input type="text" name="patients[${i}][nama]" class="form-control form-control-sm" placeholder="Nama Lengkap" value="${data.nama || ''}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="pax-label-minimal">Nomor Tlp</label>
                                <input type="text" name="patients[${i}][nomor_tlp]" class="form-control form-control-sm" placeholder="08xxxxxxxx" value="${data.nomor_tlp || ''}">
                            </div>
                            <div class="col-md-4">
                                <label class="pax-label-minimal">Tanggal Lahir</label>
                                <input type="date" name="patients[${i}][tanggal_lahir]" class="form-control form-control-sm" value="${data.tanggal_lahir || ''}">
                            </div>
                        </div>
                        
                        <div class="pax-exam-container">
                            <label class="pax-exam-label"><i class="fas fa-microscope me-2"></i>Paket Pemeriksaan</label>
                            <div class="patient-exams-list" data-patient-idx="${i}"></div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-link btn-sm text-success p-0 fw-bold x-small text-decoration-none btn-add-exam" data-patient-idx="${i}">
                                    <i class="fas fa-plus-circle me-1"></i>TAMBAH PEMERIKSAAN
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            $wrapper.append(card);
            
            if (data.exams && data.exams.length > 0) {
                data.exams.forEach(function(eid) { addExamRow(i, eid); });
            } else {
                addExamRow(i, '');
            }
        }
    }

    function addExamRow(pIdx, selectedId) {
        var $list = $(`.patient-exams-list[data-patient-idx="${pIdx}"]`);
        var row = `
            <div class="row g-2 mb-2 exam-row">
                <div class="col">
                    <select name="patients[${pIdx}][exams][]" class="form-select form-select-sm patient-exam-select" required>
                        ${examOptions}
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-danger btn-sm border-0 btn-remove-exam"><i class="fas fa-times"></i></button>
                </div>
            </div>`;
        $list.append(row);
        var $sel = $list.find('.patient-exam-select').last();
        $sel.select2({ theme: 'bootstrap-5', width: '100%' });
        if (selectedId) $sel.val(selectedId).trigger('change');
    }

    $(document).ready(function() {
        renderPaxSections(1);

        $('#btnAddPatient').on('click', function() {
            var curr = parseInt($jumlahPax.val()) || 0;
            if (curr < 10) {
                $jumlahPax.val(curr + 1);
                renderPaxSections(curr + 1);
            }
        });

        $(document).on('click', '.btn-remove-patient', function() {
            var curr = parseInt($jumlahPax.val()) || 1;
            if (curr > 1) {
                $(this).closest('.pax-section-card').remove();
                $jumlahPax.val(curr - 1);
                // Re-render to update numbering
                renderPaxSections(curr - 1);
            }
        });

        $(document).on('click', '.btn-add-exam', function() {
            addExamRow($(this).data('patient-idx'), '');
        });

        $(document).on('click', '.btn-remove-exam', function() {
            var $p = $(this).closest('.patient-exams-list');
            if ($p.find('.exam-row').length > 1) $(this).closest('.exam-row').remove();
        });

        $('#klinik_id, #status_booking').on('change', function() {
            var kid = $('#klinik_id').val();
            var sb = $('#status_booking').val();
            if (sb === 'Reserved - HC') $('#order_id_container').show();
            else $('#order_id_container').hide();

            if (kid && sb) {
                $.get('api/get_exam_availability.php', { klinik_id: kid, status_booking: sb }, function(res) {
                    examOptions = '<option value="">Pilih pemeriksaan...</option>';
                    if (res && res.length > 0) {
                        res.forEach(function(ex) {
                            var rdy = ex.no_mapping ? '(Manual)' : (ex.is_available ? '(Ready: '+ex.qty+')' : '(KOSONG)');
                            examOptions += `<option value="${ex.id}" data-available="${ex.is_available ? 1 : 0}">${ex.name} ${rdy}</option>`;
                        });
                    }
                    $('.patient-exam-select').each(function() {
                        var v = $(this).val();
                        $(this).html(examOptions).val(v).trigger('change');
                    });
                });
            }
        });
    });
})();
</script>
