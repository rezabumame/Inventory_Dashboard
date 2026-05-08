<?php
require_once __DIR__ . '/../../lib/usage.php';
check_role(['super_admin']);

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'usage';
$selected_klinik = isset($_GET['klinik_id']) ? (int)$_GET['klinik_id'] : 0;

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Fetch Kliniks for filter
$res_klinik = $conn->query("SELECT id, nama_klinik FROM inventory_klinik WHERE status='active' ORDER BY nama_klinik ASC");
$kliniks = [];
while($k = $res_klinik->fetch_assoc()) $kliniks[] = $k;

if ($selected_klinik === 0 && !empty($kliniks)) {
    $selected_klinik = (int)$kliniks[0]['id'];
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch($_POST['action']) {
            case 'save_schedule':
                $kid = (int)$_POST['klinik_id'];
                for($i=0; $i<7; $i++) {
                    $is_open = isset($_POST['days'][$i]) ? 1 : 0;
                    $conn->query("INSERT INTO inventory_operational_schedule (klinik_id, day_of_week, is_open) 
                                 VALUES ($kid, $i, $is_open) 
                                 ON DUPLICATE KEY UPDATE is_open = VALUES(is_open)");
                }
                $_SESSION['success'] = "Jadwal operasional berhasil diperbarui.";
                break;
            
            case 'save_calendar':
                $kid = (int)$_POST['klinik_id'];
                $is_op = (int)$_POST['is_operational'];
                $notes = $conn->real_escape_string($_POST['notes']);
                
                // Support both single date and multiple dates
                $dates_raw = isset($_POST['dates']) && $_POST['dates'] !== '' ? $_POST['dates'] : ($_POST['date'] ?? '');
                $dates = explode(',', $dates_raw);
                
                foreach($dates as $date) {
                    $date = trim($date);
                    if ($date === '') continue;
                    $date = $conn->real_escape_string($date);
                    $conn->query("INSERT INTO inventory_operational_calendar (klinik_id, date, is_operational, notes) 
                                 VALUES ($kid, '$date', $is_op, '$notes') 
                                 ON DUPLICATE KEY UPDATE is_operational = VALUES(is_operational), notes = VALUES(notes)");
                }
                $_SESSION['success'] = "Kalender operasional berhasil diperbarui.";
                break;

            case 'delete_calendar':
                if (isset($_POST['id']) && (int)$_POST['id'] > 0) {
                    $id = (int)$_POST['id'];
                    $conn->query("DELETE FROM inventory_operational_calendar WHERE id = $id");
                } else if (isset($_POST['dates'])) {
                    $kid = (int)$_POST['klinik_id'];
                    $dates = explode(',', $_POST['dates']);
                    foreach($dates as $date) {
                        $date = $conn->real_escape_string(trim($date));
                        if ($date === '') continue;
                        $conn->query("DELETE FROM inventory_operational_calendar WHERE klinik_id = $kid AND date = '$date'");
                    }
                }
                $_SESSION['success'] = "Pengecualian kalender berhasil dihapus.";
                break;

            case 'update_usage_config':
                $id = (int)($_POST['id'] ?? 0);
                $kid = (int)$_POST['klinik_id'];
                $bid = (int)$_POST['barang_id'];
                $mode = $_POST['mode'];
                $manual_val = (float)$_POST['manual_value'];

                if ($id > 0) {
                    $conn->query("UPDATE inventory_daily_usage_config SET mode = '$mode', manual_value = $manual_val WHERE id = $id");
                } else {
                    $conn->query("INSERT INTO inventory_daily_usage_config (klinik_id, barang_id, mode, manual_value) 
                                 VALUES ($kid, $bid, '$mode', $manual_val) 
                                 ON DUPLICATE KEY UPDATE mode = VALUES(mode), manual_value = VALUES(manual_value)");
                }
                echo json_encode(['success' => true]);
                exit;

            case 'sync_auto':
                sync_daily_usage_auto();
                $_SESSION['success'] = "Sinkronisasi Auto Daily Usage selesai.";
                break;

            case 'bulk_update_mode':
                $kid = (int)$_POST['klinik_id'];
                $mode = $_POST['mode'];
                if (in_array($mode, ['auto', 'manual'])) {
                    // Update all items for this clinic
                    $conn->query("UPDATE inventory_daily_usage_config SET mode = '$mode' WHERE klinik_id = $kid");
                    
                    // Also ensure all items have a config entry
                    $res_b = $conn->query("SELECT id FROM inventory_barang");
                    while($b = $res_b->fetch_assoc()) {
                        $bid = (int)$b['id'];
                        $conn->query("INSERT IGNORE INTO inventory_daily_usage_config (klinik_id, barang_id, mode) VALUES ($kid, $bid, '$mode')");
                    }
                    $_SESSION['success'] = "Semua item berhasil diatur ke mode " . strtoupper($mode) . ".";
                }
                break;
        }
        if (!isset($_POST['ajax'])) {
            redirect("index.php?page=daily_usage_config&tab=$active_tab&klinik_id=$selected_klinik");
            exit;
        }
    }
}

// Fetch Data for tabs
$schedule = [];
if ($selected_klinik) {
    $res_sch = $conn->query("SELECT day_of_week, is_open FROM inventory_operational_schedule WHERE klinik_id = $selected_klinik");
    while($s = $res_sch->fetch_assoc()) $schedule[(int)$s['day_of_week']] = (bool)$s['is_open'];
}

$calendar = [];
if ($selected_klinik) {
    // Fetch all exceptions for this clinic to show in the calendar view
    $res_cal = $conn->query("SELECT * FROM inventory_operational_calendar WHERE klinik_id = $selected_klinik ORDER BY date ASC");
    while($c = $res_cal->fetch_assoc()) $calendar[] = $c;
}

$usage_configs = [];
if ($selected_klinik) {
    $res_usage = $conn->query("
        SELECT b.id as barang_id, b.nama_barang, b.kode_barang, c.id, c.mode, c.manual_value, c.last_calculated_rate, c.updated_at
        FROM inventory_barang b
        LEFT JOIN inventory_daily_usage_config c ON b.id = c.barang_id AND c.klinik_id = $selected_klinik
        ORDER BY b.nama_barang ASC
    ");
    while($u = $res_usage->fetch_assoc()) {
        $u['mode'] = $u['mode'] ?: 'auto';
        $usage_configs[] = $u;
    }
}

$all_manual = !empty($usage_configs);
foreach($usage_configs as $u) {
    if (($u['mode'] ?? 'auto') !== 'manual') {
        $all_manual = false;
        break;
    }
}

$last_sync = get_setting('daily_usage_auto_last_sync', '-');
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-calendar-alt me-2"></i>Daily Usage & Calendar
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Daily Usage Configuration</li>
                </ol>
            </nav>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="d-flex flex-column align-items-md-end">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#modalImport">
                        <i class="fas fa-file-import me-1"></i> Import / Export Manual Usage
                    </button>
                    <form method="POST" onsubmit="return confirm('Mulai sinkronisasi pemakaian rata-rata bulan lalu?')">
                        <input type="hidden" name="action" value="sync_auto">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-sync-alt me-1"></i> Sync Auto Usage (Bulan Lalu)
                        </button>
                    </form>
                </div>
                <small class="text-muted mt-1" style="font-size: 0.7rem;">Last Sync: <?= htmlspecialchars($last_sync) ?></small>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm border-0 mb-3" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm border-0 mb-3" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex justify-content-between align-items-end">
                    <div class="flex-grow-1 me-4">
                        <label class="form-label fw-bold small text-muted text-uppercase mb-2">Pilih Klinik</label>
                        <select class="form-select select2" onchange="window.location.href='index.php?page=daily_usage_config&tab=<?= $active_tab ?>&klinik_id=' + this.value">
                            <?php foreach($kliniks as $k): ?>
                                <option value="<?= $k['id'] ?>" <?= $selected_klinik == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_klinik']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($active_tab == 'usage'): ?>
                    <div class="pb-1" style="min-width: 180px;">
                        <label class="form-label fw-bold small text-muted text-uppercase mb-2 d-block text-center">Set All Mode</label>
                        <div class="d-flex align-items-center justify-content-center bg-light p-2 rounded border">
                            <span class="small fw-bold me-2 <?= !$all_manual ? 'text-primary' : 'text-muted' ?>">AUTO</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input bulk-mode-switch" type="checkbox" id="bulkModeSwitch" 
                                       style="width: 2.5rem; height: 1.25rem; cursor: pointer;"
                                       <?= $all_manual ? 'checked' : '' ?>>
                            </div>
                            <span class="small fw-bold ms-1 <?= $all_manual ? 'text-secondary' : 'text-muted' ?>">MANUAL</span>
                        </div>
                        <form id="bulkModeForm" method="POST">
                            <input type="hidden" name="action" value="bulk_update_mode">
                            <input type="hidden" name="klinik_id" value="<?= $selected_klinik ?>">
                            <input type="hidden" name="mode" id="bulkModeValue" value="">
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 overflow-hidden">
        <div class="card-header bg-white p-0">
            <ul class="nav nav-tabs nav-fill border-bottom-0" id="usageTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link py-3 fw-bold <?= $active_tab == 'usage' ? 'active text-primary' : 'text-muted' ?>" href="index.php?page=daily_usage_config&tab=usage&klinik_id=<?= $selected_klinik ?>">
                        <i class="fas fa-chart-line me-2"></i>Konfigurasi Daily Usage
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-3 fw-bold <?= $active_tab == 'operational_calendar' ? 'active text-primary' : 'text-muted' ?>" href="index.php?page=daily_usage_config&tab=operational_calendar&klinik_id=<?= $selected_klinik ?>">
                        <i class="fas fa-calendar-alt me-2"></i>Kalender Operasional
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body p-4">
            <?php if ($active_tab == 'usage'): ?>
                <div class="alert alert-info py-2 small">
                    <i class="fas fa-info-circle me-1"></i> 
                    <b>Auto</b>: Menggunakan rata-rata pemakaian non-reserve bulan lalu. <br>
                    <b>Manual</b>: Menggunakan nilai tetap yang Anda inputkan di bawah ini.
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-cog me-2"></i>Daily Usage Configuration</h6>
                    <div class="d-flex gap-2">
                        <!-- Redundant button removed -->
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable-usage">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 120px;">KODE</th>
                                <th>Barang</th>
                                <th class="text-center">Mode</th>
                                <th class="text-center">Auto (Bulan Lalu)</th>
                                <th class="text-center" style="width: 150px;">Nilai Manual</th>
                                <th class="text-center">Updated At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($usage_configs as $u): ?>
                                <tr>
                                    <td class="text-center small fw-bold text-dark"><?= htmlspecialchars($u['kode_barang']) ?></td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= htmlspecialchars($u['nama_barang']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input usage-mode-switch" type="checkbox" role="switch" 
                                                   data-id="<?= $u['id'] ?>" data-barang-id="<?= $u['barang_id'] ?>" 
                                                   <?= $u['mode'] == 'manual' ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-bold <?= $u['mode'] == 'manual' ? 'text-warning' : 'text-primary' ?>">
                                                <?= strtoupper($u['mode']) ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold text-muted">
                                        <?= round($u['last_calculated_rate'], 0) ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control text-center manual-val-input" 
                                                   value="<?= (int)$u['manual_value'] ?>" 
                                                   data-id="<?= $u['id'] ?>" data-barang-id="<?= $u['barang_id'] ?>"
                                                   <?= $u['mode'] == 'auto' ? 'disabled' : '' ?>>
                                            <button class="btn btn-outline-primary save-manual-btn" 
                                                    data-id="<?= $u['id'] ?>" data-barang-id="<?= $u['barang_id'] ?>"
                                                    <?= $u['mode'] == 'auto' ? 'disabled' : '' ?>>
                                                <i class="fas fa-save"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="text-center small text-muted" style="white-space: nowrap;">
                                        <?= !empty($u['updated_at']) ? date('d/m/y H:i', strtotime($u['updated_at'])) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($active_tab == 'operational_calendar'): ?>
                <div class="row">
                    <!-- Left Side: Routine Schedule -->
                    <div class="col-md-3 border-end">
                        <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-clock me-2"></i>Jadwal Rutin Mingguan</h6>
                        <p class="text-muted small mb-4">Centang hari-hari operasional normal klinik ini.</p>
                        <form id="formRoutineSchedule" method="POST">
                            <input type="hidden" name="action" value="save_schedule">
                            <input type="hidden" name="klinik_id" value="<?= $selected_klinik ?>">
                            <?php 
                            $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                            foreach($days as $idx => $name): 
                            ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 p-2 rounded hover-light border-bottom">
                                    <span class="fw-semibold small"><?= $name ?></span>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input routine-day-check" type="checkbox" name="days[<?= $idx ?>]" role="switch" 
                                               data-day="<?= $idx ?>" <?= ($schedule[$idx] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary w-100 mt-2 py-2 fw-bold btn-sm">
                                <i class="fas fa-save me-1"></i> Update Jadwal Rutin
                            </button>
                        </form>
                    </div>

                    <!-- Right Side: Interactive Calendar -->
                    <div class="col-md-9 ps-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-calendar-check me-2"></i>Interactive Calendar</h6>
                                <p class="text-muted small mb-0">Klik pada tanggal untuk menambah hari libur/pengecualian.</p>
                            </div>
                            <div class="calendar-nav d-flex align-items-center gap-3">
                                <button class="btn btn-outline-secondary btn-sm" id="prevMonth"><i class="fas fa-chevron-left"></i></button>
                                <h5 class="mb-0 fw-bold text-dark" id="currentMonthYear" style="min-width: 150px; text-align: center;">-</h5>
                                <button class="btn btn-outline-secondary btn-sm" id="nextMonth"><i class="fas fa-chevron-right"></i></button>
                            </div>
                        </div>

                        <div class="calendar-wrapper position-relative">
                            <!-- Legend -->
                            <div class="d-flex gap-3 mb-3 small justify-content-end">
                                <div class="d-flex align-items-center gap-1">
                                    <div style="width: 12px; height: 12px; background-color: #d1fae5; border: 1px solid #10b981; border-radius: 2px;"></div>
                                    <span>Hari Aktif</span>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <div style="width: 12px; height: 12px; background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 2px;"></div>
                                    <span>Tutup (Rutin)</span>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <div style="width: 12px; height: 12px; background-color: #fee2e2; border: 1px solid #ef4444; border-radius: 2px;"></div>
                                    <span>Libur (Manual)</span>
                                </div>
                            </div>

                            <div class="calendar-grid">
                                <div class="calendar-header-grid">
                                    <div class="cal-day-header">SUN</div>
                                    <div class="cal-day-header">MON</div>
                                    <div class="cal-day-header">TUE</div>
                                    <div class="cal-day-header">WED</div>
                                    <div class="cal-day-header">THU</div>
                                    <div class="cal-day-header">FRI</div>
                                    <div class="cal-day-header">SAT</div>
                                </div>
                            <div id="calendarDays" class="calendar-body-grid">
                                    <!-- Days populated by JS -->
                                </div>
                            </div>

                            <!-- Floating Action Bar for Multi-select -->
                            <div id="multiSelectBar" class="position-absolute bottom-0 start-50 translate-middle-x mb-3 shadow-lg rounded-pill bg-dark text-white p-2 px-4 d-flex align-items-center gap-3" style="display:none !important; z-index: 100;">
                                <span class="small fw-bold"><span id="selectedCount">0</span> Tanggal Terpilih</span>
                                <div class="vr"></div>
                                <button class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" id="btnBulkSet">
                                    <i class="fas fa-edit me-1"></i> Set Operasional
                                </button>
                                <button class="btn btn-outline-light btn-sm rounded-pill" id="btnClearSelect">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Exception -->
                <div class="modal fade" id="modalCalendarException" tabindex="-1">
                    <div class="modal-dialog modal-sm modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-primary text-white py-2">
                                <h6 class="modal-title fw-bold">Set Operasional</h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="save_calendar">
                                    <input type="hidden" name="klinik_id" value="<?= $selected_klinik ?>">
                                    <input type="hidden" name="date" id="exc_date">
                                    <input type="hidden" name="dates" id="exc_dates_multi">
                                    
                                    <div class="mb-3">
                                        <label class="small fw-bold d-block mb-1">Tanggal</label>
                                        <div id="exc_date_text" class="fw-bold text-primary small"></div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="small fw-bold d-block mb-1">Status</label>
                                        <select name="is_operational" id="exc_is_op" class="form-select form-select-sm">
                                            <option value="0">TUTUP (Libur)</option>
                                            <option value="1">BUKA (Override)</option>
                                        </select>
                                    </div>

                                    <div class="mb-0">
                                        <label class="small fw-bold d-block mb-1">Alasan / Catatan</label>
                                        <input type="text" name="notes" id="exc_notes" class="form-control form-control-sm" placeholder="Contoh: Libur Lebaran">
                                    </div>
                                </div>
                                 <div class="modal-footer p-2 border-top-0 d-flex gap-2">
                                    <button type="button" id="btnDeleteExc" class="btn btn-outline-secondary btn-sm flex-grow-1" style="display:none;">Reset ke Jadwal Rutin</button>
                                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Simpan</button>
                                </div>
                            </form>
                            <form id="formDeleteExc" method="POST">
                                <input type="hidden" name="action" value="delete_calendar">
                                <input type="hidden" name="klinik_id" value="<?= $selected_klinik ?>">
                                <input type="hidden" name="id" id="exc_delete_id">
                                <input type="hidden" name="dates" id="exc_delete_dates">
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Import & Export -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-import me-2"></i>Import / Export Manual Usage</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <!-- Export Section -->
                    <div class="col-md-5 border-end">
                        <h6 class="fw-bold mb-3"><i class="fas fa-file-download text-success me-2"></i>1. Export Template</h6>
                        <p class="text-muted small">Download template Excel untuk diisi secara manual.</p>
                        <form action="api/inventory_usage_export.php" method="GET">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Pilih Klinik (Bisa pilih banyak)</label>
                                <select name="klinik_ids[]" class="form-select select2-multi" multiple required data-placeholder="Cari Klinik...">
                                    <option value="all">Semua Klinik</option>
                                    <?php foreach ($kliniks as $k): ?>
                                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_klinik']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success w-100 btn-sm">
                                <i class="fas fa-download me-1"></i> Download Template
                            </button>
                        </form>
                    </div>
                    
                    <!-- Import Section -->
                    <div class="col-md-7 ps-md-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-file-upload text-primary me-2"></i>2. Import Data</h6>
                        <form action="api/inventory_usage_import.php" method="POST" enctype="multipart/form-data">
                            <div class="alert alert-warning py-2 small border-0">
                                <i class="fas fa-exclamation-triangle me-1"></i> 
                                Pastikan format file sesuai dengan hasil export. Sistem akan mengupdate berdasarkan <b>Klinik ID</b> dan <b>Barang ID</b>.
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Pilih File Excel (.xlsx)</label>
                                <input type="file" name="file" class="form-control btn-sm" accept=".xlsx" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 btn-sm">
                                <i class="fas fa-upload me-1"></i> Proses Import Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .nav-tabs .nav-link {
        border: none;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
    }
    .nav-tabs .nav-link.active {
        border-bottom: 3px solid #204EAB;
        background-color: rgba(32, 78, 171, 0.05) !important;
    }
    .form-switch .form-check-input:checked {
        background-color: #204EAB;
        border-color: #204EAB;
    }
    .table > :not(caption) > * > * {
        padding: 0.65rem 0.75rem;
    }
    .hover-light:hover { background-color: #f8fafc; }
    
    /* Calendar Styles */
    .calendar-grid { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: white; }
    
    /* Rounded Pagination */
    .dataTables_wrapper .pagination { gap: 6px; padding-top: 15px; justify-content: flex-end; }
    .dataTables_wrapper .pagination .page-item .page-link { 
        border-radius: 50% !important; 
        width: 36px; 
        height: 36px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        border: 1px solid #e2e8f0; 
        color: #64748b; 
        font-size: 0.85rem; 
        font-weight: 500; 
        margin: 0 2px;
        padding: 0;
        transition: all 0.2s ease;
        background: #ffffff;
    }
    .dataTables_wrapper .pagination .page-item.active .page-link { 
        background-color: #eff6ff !important; 
        color: #204EAB !important; 
        border-color: #dbeafe !important; 
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(32, 78, 171, 0.12);
    }
    .dataTables_wrapper .pagination .page-item:hover:not(.active):not(.disabled) .page-link {
        background-color: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .dataTables_wrapper .pagination .page-item.disabled .page-link {
        opacity: 0.35;
        background: #f8fafc;
        border-color: #f1f5f9;
    }
    .dataTables_info { font-size: 0.8rem; color: #94a3b8; padding-top: 15px; }

    .calendar-header-grid { display: grid; grid-template-columns: repeat(7, 1fr); background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .cal-day-header { padding: 10px; text-align: center; font-size: 0.7rem; fw-bold: 700; color: #64748b; letter-spacing: 0.5px; }
    .calendar-body-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
    .cal-day { 
        min-height: 90px; padding: 8px; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; 
        position: relative; cursor: pointer; transition: all 0.2s;
    }
    .cal-day:nth-child(7n) { border-right: none; }
    .cal-day:hover { background-color: #f8fafc; z-index: 1; box-shadow: inset 0 0 0 2px #204EAB; }
    .cal-day.empty { background-color: #f8fafc; cursor: default; }
    .cal-day.empty:hover { box-shadow: none; }
    .cal-date-num { font-weight: 700; font-size: 1rem; color: #1e293b; display: flex; align-items: center; justify-content: space-between; }
    
    /* Day States */
    .cal-day.is-active { background-color: #f0fdf4; border: 1px solid #dcfce7; } /* Routine Open */
    .cal-day.is-active .cal-date-num { color: #166534; }
    
    .cal-day.is-closed { background-color: #f8fafc; border: 1px solid #e2e8f0; } /* Routine Closed */
    .cal-day.is-closed .cal-date-num { color: #64748b; }
    
    .cal-day.has-exception.is-holiday { background-color: #fff1f2 !important; border: 1px solid #fecdd3 !important; } /* Manual Closed */
    .cal-day.has-exception.is-holiday .cal-date-num { color: #be123c; }
    
    .cal-day.has-exception.is-override { background-color: #f0fdfa !important; border: 1px solid #ccfbf1 !important; } /* Manual Open Override */
    .cal-day.has-exception.is-override .cal-date-num { color: #0f766e; }

    /* Today Indicator */
    .cal-day.is-today { box-shadow: inset 0 0 0 2px #204EAB; }
    .cal-day.is-today .cal-date-num::after { 
        content: 'TODAY'; font-size: 0.5rem; background: #204EAB; color: white; 
        padding: 1px 4px; border-radius: 4px; margin-left: 5px;
    }
    
    .cal-day.is-selected { box-shadow: inset 0 0 0 2px #204EAB; background-color: rgba(32, 78, 171, 0.05) !important; }
    .cal-day.is-selected::before {
        content: '\f058'; font-family: 'Font Awesome 5 Free'; font-weight: 900;
        position: absolute; top: 8px; right: 8px; color: #204EAB; font-size: 0.8rem;
    }
    .cal-exc-note { font-size: 0.65rem; margin-top: 4px; line-height: 1.2; font-weight: 500; }
    .cal-badge { 
        font-size: 0.6rem; padding: 2px 4px; border-radius: 4px; text-transform: uppercase; 
        font-weight: 700; display: inline-block; margin-top: 2px;
    }
</style>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap-5' });
    
    if ($.fn.DataTable.isDataTable('.datatable-usage')) {
        $('.datatable-usage').DataTable().destroy();
    }
    $('.datatable-usage').DataTable({
        pageLength: 10,
        order: [[0, 'asc']] // Order by Kode Barang (Column index 0)
    });

    // Handle Bulk Mode Switch
    $('#bulkModeSwitch').on('change', function() {
        const isManual = $(this).is(':checked');
        const mode = isManual ? 'manual' : 'auto';
        const modeText = mode.toUpperCase();
        
        Swal.fire({
            title: 'Konfirmasi Bulk Update',
            text: `Apakah Anda yakin ingin mengatur SEMUA item di klinik ini ke mode ${modeText}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: isManual ? '#6c757d' : '#204EAB',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Terapkan!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#bulkModeValue').val(mode);
                $('#bulkModeForm').submit();
            } else {
                // Reset switch state
                $(this).prop('checked', !isManual);
            }
        });
    });

    // Handle Mode Switch
    $('.usage-mode-switch').on('change', function() {
        const id = $(this).data('id');
        const barang_id = $(this).data('barang-id');
        const mode = $(this).is(':checked') ? 'manual' : 'auto';
        const row = $(this).closest('tr');
        const input = row.find('.manual-val-input');
        const btn = row.find('.save-manual-btn');
        const label = $(this).next('label');

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'update_usage_config',
                ajax: 1,
                id: id,
                barang_id: barang_id,
                klinik_id: '<?= $selected_klinik ?>',
                mode: mode,
                manual_value: input.val()
            },
            success: function() {
                if (mode === 'manual') {
                    input.prop('disabled', false);
                    btn.prop('disabled', false);
                    label.text('MANUAL').removeClass('text-primary').addClass('text-warning');
                } else {
                    input.prop('disabled', true);
                    btn.prop('disabled', true);
                    label.text('AUTO').removeClass('text-warning').addClass('text-primary');
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: 'Mode penggunaan diperbarui.',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        });
    });

    // Handle Manual Value Save
    $('.save-manual-btn').on('click', function() {
        const id = $(this).data('id');
        const barang_id = $(this).data('barang-id');
        const row = $(this).closest('tr');
        const input = row.find('.manual-val-input');
        const manual_val = input.val();
        const mode = row.find('.usage-mode-switch').is(':checked') ? 'manual' : 'auto';

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'update_usage_config',
                ajax: 1,
                id: id,
                barang_id: barang_id,
                klinik_id: '<?= $selected_klinik ?>',
                mode: mode,
                manual_value: manual_val
            },
            success: function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Tersimpan',
                    text: 'Nilai manual berhasil disimpan.',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        });
    });

    // Calendar Variables
    let currentViewDate = new Date();
    const calendarData = <?= json_encode($calendar) ?>;
    const routineSchedule = <?= json_encode($schedule) ?>;
    let selectedDates = [];

    function updateMultiSelectBar() {
        if (selectedDates.length > 0) {
            $('#selectedCount').text(selectedDates.length);
            $('#multiSelectBar').attr('style', 'display: flex !important; z-index: 100;');
        } else {
            $('#multiSelectBar').attr('style', 'display: none !important;');
        }
    }

    function renderCalendar() {
        const year = currentViewDate.getFullYear();
        const month = currentViewDate.getMonth();
        const monthNames = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
        
        $('#currentMonthYear').text(`${monthNames[month]} ${year}`);
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        const $grid = $('#calendarDays');
        $grid.empty();
        
        // Empty cells for first week
        for (let i = 0; i < firstDay; i++) {
            $grid.append('<div class="cal-day empty"></div>');
        }
        
        // Days of the month
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const dayOfWeek = new Date(year, month, d).getDay();
            const isRoutineOpen = routineSchedule[dayOfWeek] === undefined ? true : routineSchedule[dayOfWeek];
            
            const today = new Date();
            const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === d;
            
            // Check for exception
            const exception = calendarData.find(c => c.date === dateStr);
            
            let classes = 'cal-day';
            if (isToday) classes += ' is-today';
            if (isRoutineOpen) classes += ' is-active';
            else classes += ' is-closed';
            
            if (selectedDates.includes(dateStr)) classes += ' is-selected';
            
            let noteHtml = '';
            if (exception) {
                classes += ' has-exception';
                if (parseInt(exception.is_operational) === 1) {
                    classes += ' is-override';
                    noteHtml = `<div class="cal-badge bg-success text-white">BUKA</div><div class="cal-exc-note text-success">${exception.notes || 'Override'}</div>`;
                } else {
                    classes += ' is-holiday';
                    noteHtml = `<div class="cal-badge bg-danger text-white">LIBUR</div><div class="cal-exc-note text-danger">${exception.notes || 'Tutup'}</div>`;
                }
            }
            
            const $day = $(`
                <div class="${classes}" data-date="${dateStr}">
                    <div class="cal-date-num">${d}</div>
                    ${noteHtml}
                </div>
            `);
            
            $day.on('click', function(e) {
                const date = $(this).data('date');
                
                // If clicking an already selected date or we are in multi-mode
                if (selectedDates.includes(date)) {
                    selectedDates = selectedDates.filter(d => d !== date);
                    $(this).removeClass('is-selected');
                } else {
                    selectedDates.push(date);
                    $(this).addClass('is-selected');
                }
                
                updateMultiSelectBar();
            });
            
            $grid.append($day);
        }
    }

    $('#btnBulkSet').on('click', function() {
        if (selectedDates.length === 0) return;
        
        if (selectedDates.length === 1) {
            // Single date mode
            const date = selectedDates[0];
            const exc = calendarData.find(c => c.date === date);
            $('#exc_date').val(date);
            $('#exc_dates_multi').val('');
            $('#exc_date_text').text(new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }));
            
            if (exc) {
                $('#exc_is_op').val(exc.is_operational);
                $('#exc_notes').val(exc.notes);
                $('#exc_delete_id').val(exc.id);
                $('#exc_delete_dates').val('');
                $('#btnDeleteExc').show();
            } else {
                $('#exc_is_op').val(0);
                $('#exc_notes').val('');
                $('#exc_delete_id').val('');
                $('#exc_delete_dates').val('');
                $('#btnDeleteExc').hide();
            }
        } else {
            // Multi date mode
            $('#exc_date').val('');
            $('#exc_dates_multi').val(selectedDates.join(','));
            $('#exc_date_text').text(`${selectedDates.length} Tanggal Terpilih`);
            $('#exc_is_op').val(0);
            $('#exc_notes').val('');
            
            // For multi-delete
            $('#exc_delete_id').val('');
            $('#exc_delete_dates').val(selectedDates.join(','));
            $('#btnDeleteExc').show();
        }
        
        new bootstrap.Modal('#modalCalendarException').show();
    });

    $('#btnClearSelect').on('click', function() {
        selectedDates = [];
        $('.cal-day').removeClass('is-selected');
        updateMultiSelectBar();
    });

    $('#prevMonth').on('click', function() {
        currentViewDate.setMonth(currentViewDate.getMonth() - 1);
        renderCalendar();
    });
    
    $('#nextMonth').on('click', function() {
        currentViewDate.setMonth(currentViewDate.getMonth() + 1);
        renderCalendar();
    });

    if ($('#calendarDays').length) {
        renderCalendar();
    }

    $('#btnDeleteExc').on('click', function() {
        if (confirm('Hapus pengecualian ini?')) {
            $('#formDeleteExc').submit();
        }
    });

    // Initialize Select2 inside modal
    $('#modalImport').on('shown.bs.modal', function () {
        $('.select2-multi').select2({
            dropdownParent: $('#modalImport'),
            theme: 'bootstrap-5',
            width: '100%'
        });
    });
});
</script>
