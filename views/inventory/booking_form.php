<?php
check_role(['cs', 'super_admin']);

$_SESSION['error'] = 'Gunakan halaman Booking (tombol Booking Baru) untuk membuat booking. Halaman booking_create sudah dinonaktifkan agar perhitungan stok dan security konsisten.';
redirect('index.php?page=booking');

function ensure_column_exists($table, $column, $definition) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
    }
}

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
$klinik_res = $conn->query("SELECT * FROM inventory_klinik WHERE status = 'active'");
$kliniks = [];
while($r = $klinik_res->fetch_assoc()) $kliniks[] = $r;

$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(100) NOT NULL,
        from_uom VARCHAR(50) NULL,
        to_uom VARCHAR(50) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kode_barang (kode_barang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Pre-calculate Availability for ALL Clinics and Exams
// 1. Get all Stock Data: stok[klinik_id][barang_id] = available
$stok_data = [];
try {
    require_once __DIR__ . '/../../lib/stock.php';
    
    // a. Mirror massal
    $mirror_data = [];
    $res_m = $conn->query("SELECT location_code, odoo_product_id, kode_barang, qty FROM inventory_stock_mirror");
    while($rm = $res_m->fetch_assoc()){
        $loc = trim($rm['location_code']);
        $key = trim($rm['odoo_product_id'] ?: $rm['kode_barang']);
        if(!isset($mirror_data[$loc])) $mirror_data[$loc] = [];
        $mirror_data[$loc][$key] = (float)$rm['qty'];
    }

    // b. Pending massal
    $pending_data = [];
    $res_p = $conn->query("
        SELECT level, level_id, barang_id, 
               SUM(CASE WHEN tipe_transaksi='in' THEN qty ELSE 0 END) as qin,
               SUM(CASE WHEN tipe_transaksi='out' THEN qty ELSE 0 END) as qout
        FROM inventory_transaksi_stok
        WHERE referensi_tipe IN ('transfer','hc_petugas_transfer')
        GROUP BY level, level_id, barang_id
    ");
    while($rp = $res_p->fetch_assoc()){
        $k = $rp['level'] . '_' . $rp['level_id'] . '_' . $rp['barang_id'];
        $pending_data[$k] = ['in' => (float)$rp['qin'], 'out' => (float)$rp['qout']];
    }

    // c. Sellout massal
    $sellout_data = [];
    $res_s = $conn->query("
        SELECT pb.klinik_id, pb.jenis_pemakaian, pbd.barang_id, SUM(pbd.qty) as qty
        FROM inventory_pemakaian_bhp_detail pbd
        JOIN inventory_pemakaian_bhp pb ON pb.id = pbd.pemakaian_bhp_id
        GROUP BY pb.klinik_id, pb.jenis_pemakaian, pbd.barang_id
    ");
    while($rs = $res_s->fetch_assoc()){
        $type = ($rs['jenis_pemakaian'] == 'hc' ? 'hc' : 'klinik');
        $k = $rs['klinik_id'] . '_' . $type . '_' . $rs['barang_id'];
        $sellout_data[$k] = (float)$rs['qty'];
    }

    // d. Reserve massal
    $reserve_data = [];
    $res_r = $conn->query("
        SELECT bp.klinik_id, bp.status_booking, bd.barang_id,
               SUM(CASE WHEN bp.status_booking LIKE '%HC%' THEN (CASE WHEN bd.qty_reserved_hc > 0 THEN bd.qty_reserved_hc ELSE bd.qty_gantung END)
                        ELSE (CASE WHEN bd.qty_reserved_onsite > 0 THEN bd.qty_reserved_onsite ELSE bd.qty_gantung END) END) as qty
        FROM inventory_booking_detail bd
        JOIN inventory_booking_pemeriksaan bp ON bd.booking_id = bp.id
        WHERE bp.status = 'booked'
        GROUP BY bp.klinik_id, bp.status_booking, bd.barang_id
    ");
    while($rr = $res_r->fetch_assoc()){
        $type = (stripos($rr['status_booking'], 'HC') !== false ? 'hc' : 'klinik');
        $k = $rr['klinik_id'] . '_' . $type . '_' . $rr['barang_id'];
        if(!isset($reserve_data[$k])) $reserve_data[$k] = 0;
        $reserve_data[$k] += (float)$rr['qty'];
    }

    // e. Build final map
    $res_k = $conn->query("SELECT id, kode_klinik, kode_homecare FROM inventory_klinik WHERE status='active'");
    $res_b = $conn->query("
        SELECT b.id, b.odoo_product_id, b.kode_barang, COALESCE(uc.multiplier, 1) as uom_ratio 
        FROM inventory_barang b 
        LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
        WHERE b.odoo_product_id IS NOT NULL AND b.odoo_product_id <> ''
    ");
    $all_b = [];
    while($rb = $res_b->fetch_assoc()) $all_b[] = $rb;

    while($rk = $res_k->fetch_assoc()){
        $kid = (int)$rk['id'];
        $loc_on = trim($rk['kode_klinik']);
        $stok_data[$kid] = [];
        
        foreach($all_b as $bl){
            $bid = (int)$bl['id'];
            $mult = (float)($bl['uom_ratio'] ?: 1);
            $key = trim($bl['odoo_product_id'] ?: $bl['kode_barang']);

            $base_on = (isset($mirror_data[$loc_on][$key]) ? $mirror_data[$loc_on][$key] : 0) / $mult;
            $pend_on = isset($pending_data['klinik_'.$kid.'_'.$bid]) ? $pending_data['klinik_'.$kid.'_'.$bid] : ['in'=>0,'out'=>0];
            $sell_on = isset($sellout_data[$kid.'_klinik_'.$bid]) ? $sellout_data[$kid.'_klinik_'.$bid] : 0;
            $resv_on = isset($reserve_data[$kid.'_klinik_'.$bid]) ? $reserve_data[$kid.'_klinik_'.$bid] : 0;
            $avail_on = $base_on + $pend_on['in'] - $pend_on['out'] - $sell_on - $resv_on;
            
            $stok_data[$kid][$bid] = max(0, $avail_on) * $mult;
        }
    }
} catch (Exception $e) {
    $stok_data = [];
}

// 2. Get all Recipes: recipe[exam_id][] = {barang_id, qty}
$recipes = [];
$exams = [];
$res_exams = $conn->query("SELECT * FROM inventory_pemeriksaan_grup ORDER BY nama_pemeriksaan");
while($ex = $res_exams->fetch_assoc()) {
    $exams[$ex['id']] = $ex['nama_pemeriksaan'];
    $res_det = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = " . $ex['id']);
    while($d = $res_det->fetch_assoc()) {
        $recipes[$ex['id']][] = $d;
    }
}

// 3. Compute Availability
$availability_map = []; // [klinik_id] => [ {id, name, qty} ]
foreach ($kliniks as $k) {
    $kid = $k['id'];
    $availability_map[$kid] = [];
    
    foreach ($exams as $eid => $ename) {
        $max_possible = 999999;
        $is_possible = true;
        
        if (!isset($recipes[$eid]) || empty($recipes[$eid])) {
            $max_possible = 999999;
            $is_possible = true;
            $no_mapping = true;
        } else {
            $no_mapping = false;
            foreach ($recipes[$eid] as $ing) {
                $bid = $ing['barang_id'];
                $req = $ing['qty_per_pemeriksaan'];
                
                $have = isset($stok_data[$kid][$bid]) ? $stok_data[$kid][$bid] : 0;
                
                if ($have < $req) {
                    $max_possible = 0;
                    $is_possible = false;
                    break;
                } else {
                    $possible = floor($have / $req);
                    if ($possible < $max_possible) $max_possible = $possible;
                }
            }
        }
        
        // Only add if ready > 0 (as requested)
        if ($is_possible) {
            $availability_map[$kid][] = [
                'id' => $eid,
                'name' => $ename,
                'qty' => $max_possible,
                'no_mapping' => $no_mapping
            ];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $status_booking = $_POST['status_booking'];
    $klinik_id = $_POST['klinik_id'];
    $tanggal = $_POST['tanggal'];
    $order_id = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
    $booking_type = $_POST['booking_type'] ?? 'keep';
    $jam_layanan = $_POST['jam_layanan'] ?? null;
    $jotform_submitted = isset($_POST['jotform_submitted']) ? (int)$_POST['jotform_submitted'] : 0;
    $nama_pemesan = $_POST['nama_pemesan'];
    $nomor_tlp = !empty($_POST['nomor_tlp']) ? trim((string)$_POST['nomor_tlp']) : null;
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? (string)$_POST['tanggal_lahir'] : null;
    $jumlah_pax = $_POST['jumlah_pax'];
    $catatan = !empty($_POST['catatan']) ? $_POST['catatan'] : null;
    $exams = $_POST['exams']; // array of [pemeriksaan_id, qty]
    $created_by = $_SESSION['user_id'];

    if (empty($exams)) {
        echo "<script>alert('Minimal 1 pemeriksaan!');</script>";
    } else {
        $conn->begin_transaction();
        try {
            ensure_column_exists('booking_pemeriksaan', 'booking_type', "VARCHAR(10) NULL");
            ensure_column_exists('booking_pemeriksaan', 'jam_layanan', "VARCHAR(10) NULL");
            ensure_column_exists('booking_pemeriksaan', 'jotform_submitted', "TINYINT(1) NOT NULL DEFAULT 0");
            ensure_column_exists('booking_pemeriksaan', 'cs_name', "VARCHAR(100) NULL");
            ensure_column_exists('booking_pemeriksaan', 'nomor_tlp', "VARCHAR(30) NULL");
            ensure_column_exists('booking_pemeriksaan', 'tanggal_lahir', "DATE NULL");

            // 1. Calculate TOTAL needed items for ALL exams
            $total_needed = []; // [barang_id => qty]

            foreach ($exams as $idx => $exam) {
                if (empty($exam['pemeriksaan_id']) || empty($exam['qty'])) continue;
                
                $pid = $exam['pemeriksaan_id'];
                $qty_multiplier = $exam['qty'];
                
                $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
                while($row = $res->fetch_assoc()) {
                    $bid = $row['barang_id'];
                    $qty = $row['qty_per_pemeriksaan'] * $qty_multiplier;
                    if (!isset($total_needed[$bid])) $total_needed[$bid] = 0;
                    $total_needed[$bid] += $qty;
                }
            }

            if (empty($total_needed)) throw new Exception("Tidak ada item yang perlu dibooking (cek master pemeriksaan).");

            // 2. Check Availability ONCE for the whole batch
            $klinik_row = $conn->query("SELECT kode_klinik, kode_homecare FROM inventory_klinik WHERE id = " . (int)$klinik_id . " LIMIT 1")->fetch_assoc();
            $kode_klinik = (string)($klinik_row['kode_klinik'] ?? '');
            $kode_homecare = (string)($klinik_row['kode_homecare'] ?? '');
            $is_hc = (stripos((string)$status_booking, 'HC') !== false);
            $location_code = $is_hc ? $kode_homecare : $kode_klinik;
            if ($location_code === '') throw new Exception("Kode lokasi Odoo untuk klinik ini belum diisi.");
            $loc_esc = $conn->real_escape_string($location_code);

            foreach ($total_needed as $bid => $qty_need) {
                $bname_row = $conn->query("SELECT nama_barang, odoo_product_id FROM inventory_barang WHERE id = " . (int)$bid . " LIMIT 1")->fetch_assoc();
                $bname = (string)($bname_row['nama_barang'] ?? ("ID:$bid"));
                
                require_once __DIR__ . '/../../lib/stock.php';
                $is_hc = (stripos((string)$status_booking, 'HC') !== false);
                $ef = stock_effective($conn, (int)$klinik_id, $is_hc, (int)$bid);
                
                if (!$ef['ok']) throw new Exception("Gagal menghitung stok untuk $bname: " . $ef['message']);
                
                $available = (float)$ef['available'];
                $mult = stock_multiplier($conn, (int)$bid);
                $qty_need_oper = (float)$qty_need / $mult; // total_needed is in RAW units

                if ($available < $qty_need_oper - 0.00005) {
                    throw new Exception("Stok tidak cukup untuk $bname. Tersedia: " . fmt_qty($available) . ", Butuh Total: " . fmt_qty($qty_need_oper));
                }
            }

            // 3. Create Booking Header
            $nomor = "BK-TMP-" . time();
            $stmt = $conn->prepare("INSERT INTO inventory_booking_pemeriksaan (nomor_booking, order_id, klinik_id, status_booking, booking_type, jam_layanan, jotform_submitted, cs_name, nama_pemesan, nomor_tlp, tanggal_lahir, jumlah_pax, catatan, tanggal_pemeriksaan, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?, NOW())");
            $stmt->bind_param("ssisssissssissi", $nomor, $order_id, $klinik_id, $status_booking, $booking_type, $jam_layanan, $jotform_submitted, $cs_name, $nama_pemesan, $nomor_tlp, $tanggal_lahir, $jumlah_pax, $catatan, $tanggal, $created_by);
            $stmt->execute();
            $book_id = $conn->insert_id;
            $nomor_final = "BK-" . str_pad((string)$book_id, 6, '0', STR_PAD_LEFT);
            $stmt_up = $conn->prepare("UPDATE inventory_booking_pemeriksaan SET nomor_booking = ? WHERE id = ?");
            $stmt_up->bind_param("si", $nomor_final, $book_id);
            $stmt_up->execute();

            // 4. Insert Patients (one entry with nama_pemesan)
            $stmt_pasien = $conn->prepare("INSERT INTO inventory_booking_pasien (booking_id, nama_pasien, pemeriksaan_grup_id) VALUES (?, ?, ?)");
            
            // 5. Insert Details for each exam
            foreach ($exams as $exam) {
                if (empty($exam['pemeriksaan_id']) || empty($exam['qty'])) continue;
                
                $pid = $exam['pemeriksaan_id'];
                $qty_multiplier = $exam['qty'];
                
                // Insert patient entry for this exam
                $stmt_pasien->bind_param("isi", $book_id, $nama_pemesan, $pid);
                $stmt_pasien->execute();
                $pasien_id = $conn->insert_id;

                // Get items for this exam
                $res = $conn->query("SELECT barang_id, qty_per_pemeriksaan FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = $pid");
                while($row = $res->fetch_assoc()) {
                    $qty_total = $row['qty_per_pemeriksaan'] * $qty_multiplier;
                    
                    // Determine qty based on status_booking
                    $qty_reserved_onsite = 0;
                    $qty_reserved_hc = 0;
                    
                    if (strpos($status_booking, 'Clinic') !== false) {
                        $qty_reserved_onsite = $qty_total;
                    } else if (strpos($status_booking, 'HC') !== false) {
                        $qty_reserved_hc = $qty_total;
                    }
                    
                    // Insert Detail
                    $stmt_detail = $conn->prepare("INSERT INTO inventory_booking_detail (booking_id, booking_pasien_id, barang_id, qty_gantung, qty_reserved_onsite, qty_reserved_hc) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_detail->bind_param("iiiiii", $book_id, $pasien_id, $row['barang_id'], $qty_total, $qty_reserved_onsite, $qty_reserved_hc);
                    $stmt_detail->execute();
                }
            }

            $conn->commit();
            echo "<script>alert('Booking Group berhasil dibuat!'); window.location='index.php?page=booking';</script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 text-primary-custom">Buat Booking Baru</h2>
    <a href="index.php?page=booking" class="btn btn-secondary">Kembali</a>
</div>

<form method="POST" id="bookingForm">
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-info-circle"></i> Informasi Booking
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Status Booking <span class="text-danger">*</span></label>
                    <select name="status_booking" id="status_booking" class="form-select" required>
                        <option value="">- Pilih Status -</option>
                        <option value="Reserved - Clinic">Reserved - Clinic</option>
                        <option value="Reserved - HC">Reserved - HC</option>
                    </select>
                    <small class="text-muted">Status lain akan diupdate oleh tim</small>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Location (Klinik) <span class="text-danger">*</span></label>
                    <select name="klinik_id" id="klinik_id" class="form-select" required>
                        <option value="">- Pilih Klinik -</option>
                        <?php foreach ($kliniks as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['nama_klinik'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Tanggal Booking Tindakan <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3 mb-3" id="order_id_container" style="display: none;">
                    <label class="fw-bold">Order ID</label>
                    <input type="text" name="order_id" class="form-control" placeholder="Opsional">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="fw-bold">Nama Pemesan / Pasien <span class="text-danger">*</span></label>
                    <input type="text" name="nama_pemesan" class="form-control" placeholder="Nama pemesan atau pasien" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="fw-bold">Nomor Tlp</label>
                    <input type="text" name="nomor_tlp" class="form-control" placeholder="08xxxxxxxxxx">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="fw-bold">Tanggal Lahir</label>
                    <input type="date" name="tanggal_lahir" class="form-control">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Jumlah Pax <span class="text-danger">*</span></label>
                    <input type="number" name="jumlah_pax" id="jumlah_pax" class="form-control" min="1" value="1" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Nama CS</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($cs_name) ?>" readonly style="background-color: #f8f9fa;">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Status (Fixed / Keep) <span class="text-danger">*</span></label>
                    <select name="booking_type" class="form-select" required>
                        <option value="keep" selected>Keep</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Jam Layanan</label>
                    <input type="time" name="jam_layanan" class="form-control">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="fw-bold">Jotform Submitted <span class="text-danger">*</span></label>
                    <select name="jotform_submitted" class="form-select" required>
                        <option value="0" selected>Belum</option>
                        <option value="1">Sudah</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-notes-medical"></i> Daftar Pemeriksaan</span>
            <button type="button" class="btn btn-sm btn-light" onclick="addExam()">
                <i class="fas fa-plus"></i> Tambah Pemeriksaan
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="70%">Nama Product (Pemeriksaan)</th>
                            <th width="20%">Quantity <span class="text-danger">*</span></th>
                            <th width="10%"></th>
                        </tr>
                    </thead>
                    <tbody id="examTable">
                        <!-- Rows will be added here -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light">
            <small class="text-muted">
                <i class="fas fa-info-circle"></i> 
                Qty akan otomatis masuk ke <strong>Reserved - On Site</strong> atau <strong>Reserved - HC</strong> sesuai status booking yang dipilih.
            </small>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-12">
                    <label class="fw-bold">Catatan</label>
                    <textarea name="catatan" class="form-control" rows="3" placeholder="Catatan tambahan (opsional)"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end">
        <button type="submit" class="btn btn-lg btn-success">
            <i class="fas fa-save"></i> Simpan Booking
        </button>
    </div>
</form>

<script>
// JSON Data
var availData = <?= json_encode($availability_map) ?>;
var currentExamOptions = '<option value="">- Pilih Klinik Terlebih Dahulu -</option>';
var examRowIndex = 0;

// Init
document.addEventListener('DOMContentLoaded', function() {
    // Listen for Klinik Change
    $('#klinik_id').on('change', function() {
        var kid = $(this).val();
        updateExamOptions(kid);
    });

    // Toggle Order ID based on Status Booking
    $('#status_booking').on('change', function() {
        var status = $(this).val();
        if (status === 'Reserved - HC') {
            $('#order_id_container').show();
        } else {
            $('#order_id_container').hide();
            $('input[name="order_id"]').val(''); // Clear if hidden
        }
    });
    
    addExam(); // Add initial row
});

function updateExamOptions(klinikId) {
    if (!klinikId || !availData[klinikId] || availData[klinikId].length === 0) {
        currentExamOptions = '<option value="">- Tidak ada pemeriksaan available -</option>';
        if (!klinikId) currentExamOptions = '<option value="">- Pilih Klinik Terlebih Dahulu -</option>';
    } else {
        currentExamOptions = '<option value="">- Pilih Pemeriksaan -</option>';
        availData[klinikId].forEach(function(ex) {
            var readyText = '';
            if (ex.no_mapping) {
                readyText = '(Input Manual di BHP)';
            } else {
                readyText = `(Ready: ${ex.qty})`;
            }
            currentExamOptions += `<option value="${ex.id}">${ex.name} ${readyText}</option>`;
        });
    }

    // Update existing dropdowns
    $('.exam-select').each(function() {
        $(this).html(currentExamOptions).trigger('change');
    });
}

function addExam() {
    var idx = examRowIndex++;
    var row = `<tr>
        <td>
            <select name="exams[${idx}][pemeriksaan_id]" class="form-select exam-select" required>
                ${currentExamOptions}
            </select>
        </td>
        <td>
            <input type="number" name="exams[${idx}][qty]" class="form-control" min="1" value="1" required>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    </tr>`;
    
    $('#examTable').append(row);
    
    // Init Select2 on new row
    $('#examTable tr:last .exam-select').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
}

function removeRow(btn) {
    if ($('#examTable tr').length > 1) {
        $(btn).closest('tr').remove();
    } else {
        alert('Minimal 1 pemeriksaan.');
    }
}
</script>
