<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'admin_gudang'])) {
    die('Akses ditolak.');
}

// Auto-create tables if not exists
$conn->query("CREATE TABLE IF NOT EXISTS `inventory_odoo_format_config` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internal_reference VARCHAR(100),
    name VARCHAR(255),
    uom VARCHAR(50),
    product_category VARCHAR(255),
    income_account VARCHAR(255),
    valuation_account VARCHAR(255),
    expense_account VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS `inventory_odoo_support_data` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100),
    reason VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Check if key_name column exists (migration)
$res = $conn->query("SHOW COLUMNS FROM `inventory_odoo_support_data` LIKE 'key_name'");
if ($res && $res->num_rows == 0) {
    $conn->query("ALTER TABLE `inventory_odoo_support_data` ADD COLUMN key_name VARCHAR(100) AFTER id");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : '#master';
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_odoo_config' || $_POST['action'] === 'edit_odoo_config') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $internal_ref = $conn->real_escape_string($_POST['internal_reference']);
            $name = $conn->real_escape_string($_POST['name']);
            $uom = $conn->real_escape_string($_POST['uom']);
            $category = $conn->real_escape_string($_POST['product_category']);
            $income = $conn->real_escape_string($_POST['income_account']);
            $valuation = $conn->real_escape_string($_POST['valuation_account']);
            $expense = $conn->real_escape_string($_POST['expense_account']);

            if ($id > 0) {
                $sql = "UPDATE inventory_odoo_format_config SET 
                        internal_reference = '$internal_ref', name = '$name', uom = '$uom', 
                        product_category = '$category', income_account = '$income', 
                        valuation_account = '$valuation', expense_account = '$expense' 
                        WHERE id = $id";
                $msg = "Data master Odoo berhasil diperbarui.";
            } else {
                $sql = "INSERT INTO inventory_odoo_format_config (internal_reference, name, uom, product_category, income_account, valuation_account, expense_account) 
                        VALUES ('$internal_ref', '$name', '$uom', '$category', '$income', '$valuation', '$expense')";
                $msg = "Data master Odoo berhasil ditambahkan.";
            }
            
            if ($conn->query($sql)) {
                $_SESSION['success'] = $msg;
            } else {
                $_SESSION['error'] = "Gagal memproses data: " . $conn->error;
            }
        } elseif ($_POST['action'] === 'add_support_data' || $_POST['action'] === 'edit_support_data') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $key = $conn->real_escape_string($_POST['key_name']);
            $reason = $conn->real_escape_string($_POST['reason']);
            $notes = $conn->real_escape_string($_POST['notes']);

            if ($id > 0) {
                $sql = "UPDATE inventory_odoo_support_data SET key_name = '$key', reason = '$reason', notes = '$notes' WHERE id = $id";
                $msg = "Data pendukung berhasil diperbarui.";
            } else {
                $sql = "INSERT INTO inventory_odoo_support_data (key_name, reason, notes) VALUES ('$key', '$reason', '$notes')";
                $msg = "Data pendukung berhasil ditambahkan.";
            }

            if ($conn->query($sql)) {
                $_SESSION['success'] = $msg;
            } else {
                $_SESSION['error'] = "Gagal memproses data: " . $conn->error;
            }
        } elseif ($_POST['action'] === 'delete_config') {
            $id = (int)$_POST['id'];
            $conn->query("DELETE FROM inventory_odoo_format_config WHERE id = $id");
            $_SESSION['success'] = "Data berhasil dihapus.";
        } elseif ($_POST['action'] === 'delete_support') {
            $id = (int)$_POST['id'];
            $conn->query("DELETE FROM inventory_odoo_support_data WHERE id = $id");
            $_SESSION['success'] = "Data berhasil dihapus.";
        } elseif ($_POST['action'] === 'upload_odoo_config') {
            if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
                $xlsx = SimpleXLSX::parse($_FILES['file']['tmp_name']);
                if ($xlsx) {
                    $rows = $xlsx->rows();
                    $success = 0;
                    $updated = 0;
                    
                    foreach ($rows as $idx => $data) {
                        if ($idx === 0) continue; // Skip header
                        if (!is_array($data) || count($data) < 7) continue;
                        
                        $internal_ref = $conn->real_escape_string($data[0]);
                        $name = $conn->real_escape_string($data[1]);
                        $uom = $conn->real_escape_string($data[2]);
                        $category = $conn->real_escape_string($data[3]);
                        $income = $conn->real_escape_string($data[4]);
                        $valuation = $conn->real_escape_string($data[5]);
                        $expense = $conn->real_escape_string($data[6]);

                        if (empty($internal_ref)) continue;

                        // Check if internal_reference exists
                        $check = $conn->query("SELECT id FROM inventory_odoo_format_config WHERE internal_reference = '$internal_ref'");
                        if ($check && $check->num_rows > 0) {
                            $row_id = $check->fetch_assoc()['id'];
                            $conn->query("UPDATE inventory_odoo_format_config SET 
                                name = '$name', uom = '$uom', product_category = '$category', 
                                income_account = '$income', valuation_account = '$valuation', expense_account = '$expense' 
                                WHERE id = $row_id");
                            $updated++;
                        } else {
                            $conn->query("INSERT INTO inventory_odoo_format_config (internal_reference, name, uom, product_category, income_account, valuation_account, expense_account) 
                                VALUES ('$internal_ref', '$name', '$uom', '$category', '$income', '$valuation', '$expense')");
                            $success++;
                        }
                    }
                    $_SESSION['success'] = "Berhasil upload: $success data baru ditambahkan, $updated data diperbarui.";
                } else {
                    $_SESSION['error'] = "Gagal membaca file Excel: " . SimpleXLSX::parseError();
                }
            } else {
                $_SESSION['error'] = "Gagal upload file.";
            }
        }
        $tab_param = str_replace('#', '', $active_tab);
        redirect("index.php?page=odoo_format_config&tab=$tab_param");
        exit;
    }
}

// Options for dropdowns
$uom_options = ['Pcs','Test','uL','mL','Unit','Units','L','Vial','Tube','Botol','Btl','Ampule','gr','Kit@100test','Tablet','Roll','Box','Lembar','Lbr','mg','Set','Psg','Strip','Pack','Pfs','Injeksi','Tab','Ampul'];
$category_options = [
    'MCU / Laboratory Test', 'All [KHUSUS NON TRACK INVENTORY]', 'OTHERS / General', 
    'GENETICS SCREENING / GEN-Others', 'VITAMIN BOOSTER / V-Boost', 'VITAMIN BOOSTER / V-Drip', 
    'GENETICS SCREENING / Reproductive', 'NIFTY / NIFTY', 'GENETICS SCREENING / Oncology', 
    'VACCINE / VAC-Others', 'MCU / Body Functions', 'MCU / MCU-Others', 
    'Medical Expertise Service / Medical Expertise Service', 'VACCINE / Hepatitis Vaccine', 
    'VACCINE / Measles, Mumps, Rubella Vaccine', 'VACCINE / HPV Vaccine', 
    'VACCINE / Herpes Vaccine', 'VACCINE / Influenza Vaccine', 
    'VACCINE / Japanese Encephalitis Vaccine', 'VACCINE / Typhoid Vaccine', 
    'VACCINE / Pneumonia Vaccine', 'VACCINE / Polio Vaccine', 
    'VACCINE / Dengue Vaccine', 'VACCINE / RSV Vaccine', 'VACCINE / Tdap Vaccine', 
    'VACCINE / Tetanus Vaccine', 'VACCINE / Yellow Fever Vaccine', 
    'VACCINE / Meningitis Vaccine', 'VACCINE / Rabies Vaccine'
];
$income_options = [
    '410101001 PENDAPATAN USAHA LAB - MCU', '410101000 PENDAPATAN USAHA - LABORATORIUM', 
    '420101002 PENDAPATAN LAIN - LAIN', '410101003 PENDAPATAN USAHA LAB - GENETICS SCREENING', 
    '410201001 PENDAPATAN USAHA NON LAB - VITAMIN BOOSTER', '410101002 PENDAPATAN USAHA LAB - NIPT', 
    '410201002 PENDAPATAN USAHA NON LAB - VACCINE', '420101004 PENDAPATAN USAHA MEDICAL EXPERTISE SERVICE'
];
$valuation_options = [
    '110501001 PERSEDIAAN BARANG PRODUK MCU', '110501009 PERSEDIAAN BARANG LAIN - LAIN', 
    '110501003 PERSEDIAAN BARANG PRODUK GENETICS SCREENING', '110501006 PERSEDIAAN BARANG PRODUK VITAMIN BOOSTER', 
    '110501002 PERSEDIAAN BARANG PRODUK NIPT', '110501007 PERSEDIAAN BARANG PRODUK VACCINE', 
    '110501008 PERSEDIAAN BARANG PRODUK OTHERS'
];
$expense_options = [
    '510101001 BEBAN LANGSUNG PENDAPATAN LAB - MCU', '510101000 BEBAN LANGSUNG - LABORATORIUM', 
    '510301001 BEBAN LANGSUNG PENDAPATAN CONSUMABLE', '510101003 BEBAN LANGSUNG PENDAPATAN LAB - GENETICS SCREENING', 
    '510201001 BEBAN LANGSUNG PENDAPATAN NON LAB - VITAMIN BOOSTER', '410101003 PENDAPATAN USAHA LAB - GENETICS SCREENING', 
    '510101002 BEBAN LANGSUNG PENDAPATAN LAB - NIPT', '510201002 BEBAN LANGSUNG PENDAPATAN NON LAB - VACCINE', 
    '520101004 BEBAN LANGSUNG MEDICAL EXPERTISE SERVICE'
];

$configs = $conn->query("SELECT * FROM inventory_odoo_format_config ORDER BY id DESC");
$supports = $conn->query("SELECT * FROM inventory_odoo_support_data ORDER BY id DESC");
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold text-primary-custom">
                <i class="fas fa-file-invoice me-2"></i>Konfigurasi Format Odoo
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Format Odoo</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white p-0">
            <ul class="nav nav-tabs nav-fill border-0" id="odooTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-3 fw-bold border-0" id="master-tab" data-bs-toggle="tab" data-bs-target="#master" type="button" role="tab" aria-controls="master" aria-selected="true">
                        <i class="fas fa-database me-2"></i>MASTER DATA ODOO
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-3 fw-bold border-0" id="support-tab" data-bs-toggle="tab" data-bs-target="#support" type="button" role="tab" aria-controls="support" aria-selected="false">
                        <i class="fas fa-info-circle me-2"></i>DATA PENDUKUNG
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content" id="odooTabsContent">
                <!-- TAB 1: MASTER DATA ODOO -->
                <div class="tab-pane fade show active" id="master" role="tabpanel" aria-labelledby="master-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 fw-bold">Daftar Format Odoo</h5>
                        <div class="btn-group">
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalUploadConfig">
                                <i class="fas fa-upload me-1"></i> Upload Excel
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddConfig" onclick="clearConfigForm()">
                                <i class="fas fa-plus me-1"></i> Tambah Master
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle datatable">
                            <thead class="bg-light">
                                <tr>
                                    <th>Internal Ref</th>
                                    <th>Name</th>
                                    <th>UoM</th>
                                    <th>Category</th>
                                    <th>Income Acc</th>
                                    <th>Valuation Acc</th>
                                    <th>Expense Acc</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $configs->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['internal_reference']) ?></td>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['uom']) ?></td>
                                    <td><small><?= htmlspecialchars($row['product_category']) ?></small></td>
                                    <td><small><?= htmlspecialchars($row['income_account']) ?></small></td>
                                    <td><small><?= htmlspecialchars($row['valuation_account']) ?></small></td>
                                    <td><small><?= htmlspecialchars($row['expense_account']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary border-0" 
                                                onclick='editConfig(<?= json_encode($row, ENT_QUOTES) ?>)'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Hapus data ini?');" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_config">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="active_tab" value="#master">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 2: DATA PENDUKUNG -->
                <div class="tab-pane fade" id="support" role="tabpanel" aria-labelledby="support-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 fw-bold">Data Pendukung</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddSupport" onclick="clearSupportForm()">
                            <i class="fas fa-plus me-1"></i> Tambah Data
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle datatable">
                            <thead class="bg-light">
                                <tr>
                                    <th>Key</th>
                                    <th>Reason</th>
                                    <th>Notes</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $supports->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($row['key_name']) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($row['reason']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($row['notes'])) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary border-0" 
                                                onclick='editSupport(<?= json_encode($row, ENT_QUOTES) ?>)'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Hapus data ini?');" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_support">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="active_tab" value="#support">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Config -->
<div class="modal fade" id="modalAddConfig" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" id="formConfig">
            <input type="hidden" name="action" value="add_odoo_config" id="configAction">
            <input type="hidden" name="id" id="configId">
            <input type="hidden" name="active_tab" value="#master">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="configModalTitle">Tambah Master Data Odoo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Internal Reference</label>
                        <input type="text" name="internal_reference" id="internal_reference" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Unit of Measure</label>
                        <select name="uom" id="uom" class="form-select" required>
                            <option value="">Pilih UoM</option>
                            <?php foreach ($uom_options as $u): ?>
                            <option value="<?= $u ?>"><?= $u ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Product Category</label>
                        <select name="product_category" id="product_category" class="form-select" required>
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($category_options as $c): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Income Account</label>
                        <select name="income_account" id="income_account" class="form-select" required>
                            <option value="">Pilih Akun</option>
                            <?php foreach ($income_options as $i): ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Stock Valuation Account</label>
                        <select name="valuation_account" id="valuation_account" class="form-select" required>
                            <option value="">Pilih Akun</option>
                            <?php foreach ($valuation_options as $v): ?>
                            <option value="<?= $v ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Expense Account</label>
                        <select name="expense_account" id="expense_account" class="form-select" required>
                            <option value="">Pilih Akun</option>
                            <?php foreach ($expense_options as $e): ?>
                            <option value="<?= $e ?>"><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary px-4">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Upload Config -->
<div class="modal fade" id="modalUploadConfig" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_odoo_config">
            <input type="hidden" name="active_tab" value="#master">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Upload Master Odoo (Excel)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <i class="fas fa-info-circle me-1"></i> Format Excel (.xlsx) harus memiliki kolom (Baris 1):<br>
                    <code>Internal Reference, Name, Unit of Measure, Product Category, Income Account, Stock Valuation Account, Expense Account</code>
                    <br><br>
                    <span class="text-danger">Catatan: Jika Internal Reference sudah ada, data akan diperbarui (overwrite).</span>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Pilih File Excel (.xlsx)</label>
                    <input type="file" name="file" class="form-control" accept=".xlsx" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Mulai Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Add/Edit Support -->
<div class="modal fade" id="modalAddSupport" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="formSupport">
            <input type="hidden" name="action" value="add_support_data" id="supportAction">
            <input type="hidden" name="id" id="supportId">
            <input type="hidden" name="active_tab" value="#support">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="supportModalTitle">Tambah Data Pendukung</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Key</label>
                    <input type="text" name="key_name" id="key_name" class="form-control" required placeholder="Contoh: category_mcu">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <input type="text" name="reason" id="reason" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="4" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary px-4">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Restore active tab from URL parameter or localStorage
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab');
    var activeTab = tabParam ? '#' + tabParam : localStorage.getItem('activeOdooTab');
    
    if (activeTab) {
        var tabEl = document.querySelector('button[data-bs-target="' + activeTab + '"]');
        if (tabEl) {
            bootstrap.Tab.getOrCreateInstance(tabEl).show();
        }
    }

    // Save active tab to localStorage and update URL on change
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        var target = $(e.target).attr("data-bs-target");
        localStorage.setItem('activeOdooTab', target);
        
        // Update URL without refresh
        var newUrl = new URL(window.location.href);
        newUrl.searchParams.set('tab', target.replace('#', ''));
        window.history.replaceState({}, '', newUrl);
    });
});

function clearConfigForm() {
    $('#configAction').val('add_odoo_config');
    $('#configId').val('');
    $('#configModalTitle').text('Tambah Master Data Odoo');
    $('#formConfig')[0].reset();
}

function editConfig(data) {
    clearConfigForm();
    $('#configAction').val('edit_odoo_config');
    $('#configId').val(data.id);
    $('#configModalTitle').text('Edit Master Data Odoo');
    
    $('#internal_reference').val(data.internal_reference);
    $('#name').val(data.name);
    
    // Set select values and trigger change for Select2 if used
    $('#uom').val(data.uom).trigger('change');
    $('#product_category').val(data.product_category).trigger('change');
    $('#income_account').val(data.income_account).trigger('change');
    $('#valuation_account').val(data.valuation_account).trigger('change');
    $('#expense_account').val(data.expense_account).trigger('change');
    
    new bootstrap.Modal(document.getElementById('modalAddConfig')).show();
}

function clearSupportForm() {
    $('#supportAction').val('add_support_data');
    $('#supportId').val('');
    $('#supportModalTitle').text('Tambah Data Pendukung');
    $('#formSupport')[0].reset();
}

function editSupport(data) {
    clearSupportForm();
    $('#supportAction').val('edit_support_data');
    $('#supportId').val(data.id);
    $('#supportModalTitle').text('Edit Data Pendukung');
    
    $('#key_name').val(data.key_name);
    $('#reason').val(data.reason);
    $('#notes').val(data.notes);
    
    new bootstrap.Modal(document.getElementById('modalAddSupport')).show();
}
</script>

<style>
.nav-tabs .nav-link {
    color: #6c757d;
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6 !important;
}
.nav-tabs .nav-link.active {
    color: #204EAB;
    background-color: #fff;
    border-bottom: 3px solid #204EAB !important;
}
.text-primary-custom { color: #204EAB; }
</style>
