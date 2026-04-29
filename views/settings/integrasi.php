<?php
check_role(['super_admin']);
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../config/odoo.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? 'schedule';
    if ($form_type === 'rpc') {
        $input_url = trim((string)($_POST['rpc_url'] ?? ''));
        $input_db = trim((string)($_POST['rpc_db'] ?? ''));
        $input_user = trim((string)($_POST['rpc_username'] ?? ''));
        $input_pass = (string)($_POST['rpc_password'] ?? '');
        $input_gudang = trim((string)($_POST['gudang_location_code'] ?? ''));

        $rpc_url = $input_url;
        $p = parse_url($input_url);
        if (is_array($p) && !empty($p['scheme']) && !empty($p['host'])) {
            $rpc_url = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }

        set_setting('odoo_integration_method', 'rpc');
        set_setting('odoo_rpc_url', $rpc_url);
        set_setting('odoo_rpc_db', $input_db);
        set_setting('odoo_rpc_username', $input_user);
        if ($input_pass !== '') {
            set_setting('odoo_rpc_password', $input_pass);
        }
        set_setting('odoo_location_gudang_utama', $input_gudang);
        $msg = '<div class="alert alert-success">Koneksi RPC tersimpan.</div>';
    } else if ($form_type === 'hooks') {
        $lark = trim((string)($_POST['webhook_lark_url'] ?? ''));
        $lark_booking = trim((string)($_POST['webhook_lark_booking_url'] ?? ''));
        $lark_at_id = trim((string)($_POST['webhook_lark_booking_at_id'] ?? ''));
        $gsheet = trim((string)($_POST['gsheet_booking_webhook_url'] ?? ''));
        set_setting('webhook_lark_url', $lark);
        set_setting('webhook_lark_booking_url', $lark_booking);
        set_setting('webhook_lark_booking_at_id', $lark_at_id);
        set_setting('gsheet_booking_webhook_url', $gsheet);
        $msg = '<div class="alert alert-success">Webhook tersimpan.</div>';
    } else if ($form_type === 'public_access') {
        $token = trim((string)($_POST['public_stok_token'] ?? ''));
        set_setting('public_stok_token', $token);
        $msg = '<div class="alert alert-success">Token akses publik tersimpan.</div>';
    } else if ($form_type === 'schedule') {
        $enabled_input = $_POST['enabled'] ?? '0';
        $enabled = ($enabled_input === '1') ? '1' : '0';
        
        $scheduler_token = trim((string)($_POST['scheduler_token'] ?? ''));
        set_setting('odoo_sync_token', $scheduler_token);
        set_setting('odoo_sync_enabled', $enabled);
        
        // Always save mode, weekday, and time if they are in the POST
        // to avoid confusion when user changes them while sync is off
        if (isset($_POST['mode'])) {
            set_setting('odoo_sync_mode', $_POST['mode']);
        }
        if (isset($_POST['interval_minutes'])) {
            $interval = (string) max(1, (int)$_POST['interval_minutes']);
            set_setting('odoo_sync_interval_minutes', $interval);
        }
        if (isset($_POST['weekday'])) {
            $weekday = (string) max(0, min(6, (int)$_POST['weekday']));
            set_setting('odoo_sync_weekday', $weekday);
        }
        if (isset($_POST['time'])) {
            set_setting('odoo_sync_time', $_POST['time']);
        }
        
        $msg = '<div class="alert alert-success">Pengaturan jadwal tersimpan.</div>';
    }
}

$enabled = get_setting('odoo_sync_enabled', '0') === '1';
$mode = get_setting('odoo_sync_mode', 'manual');
$interval = (int) get_setting('odoo_sync_interval_minutes', '0');
$weekday = (int) get_setting('odoo_sync_weekday', '1');
$time = get_setting('odoo_sync_time', '20:00');
$last_run = (int) get_setting('odoo_sync_last_run', '0');
$last_run_text = $last_run ? date('d M Y H:i', $last_run) : '-';
$rpc_url = trim((string)get_setting('odoo_rpc_url', ''));
$rpc_db = trim((string)get_setting('odoo_rpc_db', ''));
$rpc_user = trim((string)get_setting('odoo_rpc_username', ''));
$rpc_password_saved = ((string)get_setting('odoo_rpc_password', '')) !== '';
$gudang_location_code = trim((string)get_setting('odoo_location_gudang_utama', ''));
$integration_method = get_setting('odoo_integration_method', $rpc_url !== '' ? 'rpc' : 'api');
$lark_webhook = trim((string)get_setting('webhook_lark_url', ''));
$lark_booking_webhook = trim((string)get_setting('webhook_lark_booking_url', ''));
$lark_booking_at_id = trim((string)get_setting('webhook_lark_booking_at_id', ''));
$gsheet_webhook = trim((string)get_setting('gsheet_booking_webhook_url', ''));
$public_stok_token = trim((string)get_setting('public_stok_token', ''));
$scheduler_token_saved = trim((string)get_setting('odoo_sync_token', ''));
$internal_token = getenv('ODOO_SYNC_SYSTEM_TOKEN') ?: '';
$schedule_hint_url = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
$appRoot = rtrim(dirname(rtrim(dirname($script), '/')), '/'); // up 2 levels from /views/settings/...
if ($appRoot === '/' || $appRoot === '\\') $appRoot = '';
$schedule_hint_url = $scheme . '://' . $host . $appRoot . '/api/updatedataforodoo.php';
if ($scheduler_token_saved !== '') $schedule_hint_url .= '?token=' . urlencode($scheduler_token_saved);

function next_due_text($enabled, $mode, $interval, $weekday, $time, $last_run) {
    if (!$enabled) return '-';
    $now = time();
    if ($mode === 'manual') return 'Manual';
    if ($mode === 'interval' && $interval > 0) {
        $target = $last_run ? ($last_run + ($interval * 60)) : $now;
        if ($now >= $target - 5 && $last_run >= $target - 5) {
            $target += ($interval * 60);
        }
        return date('d M Y H:i', $target);
    }
    if ($mode === 'daily') {
        $target = strtotime(date('Y-m-d') . ' ' . $time);
        if ($now >= $target && $last_run >= $target) {
            $target = strtotime('+1 day', $target);
        }
        return date('d M Y H:i', $target);
    }
    if ($mode === 'weekly') {
        $todayW = (int) date('w');
        $diff = ($weekday - $todayW + 7) % 7;
        $target = strtotime(date('Y-m-d', strtotime("+$diff days")) . ' ' . $time);
        if ($weekday === $todayW && $now >= $target && $last_run >= $target) {
            $target = strtotime('+1 week', $target);
        }
        return date('d M Y H:i', $target);
    }
    return '-';
}
$next_due = next_due_text($enabled, $mode, $interval, $weekday, $time, $last_run);
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
        --success: #10B981;
        --danger: #EF4444;
        --info: #3B82F6;
    }

    .settings-container {
        font-family: 'Outfit', sans-serif;
        background-color: var(--slate-50);
        min-height: 100vh;
        padding-bottom: 3rem;
    }

    .page-header {
        background: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        margin-bottom: 1.5rem;
        border-left: 5px solid var(--bumame-blue);
    }

    .settings-card {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--slate-200);
        padding: 1rem;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .settings-card:hover {
        box-shadow: 0 8px 12px -3px rgba(0, 0, 0, 0.05);
    }

    .card-title-premium {
        font-weight: 800;
        color: var(--slate-900);
        font-size: 1rem;
        letter-spacing: -0.01em;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 1.25rem;
    }

    .card-title-premium i {
        color: var(--bumame-blue);
        background: var(--bumame-blue-soft);
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 0.9rem;
    }

    /* Form Controls */
    .form-label {
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        color: var(--slate-600);
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }

    .form-control, .form-select {
        border-radius: 10px;
        border: 1px solid var(--slate-200);
        padding: 0.7rem 1rem;
        font-size: 0.9rem;
        background-color: var(--slate-50);
    }

    .form-control:focus, .form-select:focus {
        background-color: white;
        border-color: var(--bumame-blue);
        box-shadow: 0 0 0 4px var(--bumame-blue-soft);
    }

    .form-text {
        font-size: 0.75rem;
        color: var(--slate-600);
    }

    .btn-premium {
        border-radius: 10px;
        padding: 0.7rem 1.5rem;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .btn-save {
        background-color: var(--bumame-blue);
        color: white;
        border: none;
    }

    .btn-save:hover {
        background-color: #1a3e8a;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(32, 78, 171, 0.3);
        color: white;
    }

    .btn-test {
        background-color: white;
        color: var(--bumame-blue);
        border: 1px solid var(--bumame-blue);
    }

    .btn-test:hover {
        background-color: var(--bumame-blue-soft);
    }

    /* Status Badges */
    .status-pill {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .danger-zone {
        border: 2px solid rgba(239, 68, 68, 0.1);
        background-color: rgba(239, 68, 68, 0.02);
    }

    .danger-zone .card-title-premium i {
        color: var(--danger);
        background: rgba(239, 68, 68, 0.1);
    }

    /* Info Stats */
    .info-stat-card {
        background: white;
        padding: 1rem;
        border-radius: 12px;
        border: 1px solid var(--slate-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .info-stat-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--slate-600);
        text-transform: uppercase;
    }

    .info-stat-value {
        font-size: 1rem;
        font-weight: 800;
        color: var(--slate-900);
    }

    .fw-800 { font-weight: 800; }
</style>

<div class="container-fluid settings-container py-4">
    <!-- Header Section -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h3 mb-1 fw-800" style="color: var(--bumame-blue); letter-spacing: -0.02em;">
                Pengaturan Sistem
            </h1>
            <p class="text-muted mb-0 small fw-500">Konfigurasi integrasi Odoo, Webhook, dan Keamanan Sistem</p>
        </div>
    </div>

    <?= $msg ?>

    <div class="row g-3 align-items-start">
        <!-- Left Column: Primary Configurations -->
        <div class="col-lg-7">
            <!-- Odoo Connection -->
            <div class="settings-card mb-3">
                <div class="card-title-premium">
                    <i class="fas fa-plug"></i>
                    <span>Koneksi Odoo (RPC)</span>
                    <span class="ms-auto status-pill bg-primary text-white">JSON-RPC</span>
                </div>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="form_type" value="rpc">
                    <div class="col-12">
                        <label class="form-label">Server URL</label>
                        <input type="text" class="form-control" name="rpc_url" value="<?= htmlspecialchars($rpc_url) ?>" placeholder="http://46.250.225.199:8072" required>
                        <div class="form-text">Base URL server Odoo (tanpa /web/...)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" name="rpc_db" value="<?= htmlspecialchars($rpc_db) ?>" placeholder="Bumame_1701" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username / Email</label>
                        <input type="text" class="form-control" name="rpc_username" value="<?= htmlspecialchars($rpc_user) ?>" placeholder="user@bumame.com" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="rpc_password" value="" placeholder="<?= $rpc_password_saved ? '••••••••' : 'Masukkan password' ?>">
                        <div class="form-text"><?= $rpc_password_saved ? 'Password tersimpan aman' : '' ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Odoo Stock Location</label>
                        <input type="text" class="form-control" name="gudang_location_code" value="<?= htmlspecialchars($gudang_location_code) ?>" placeholder="WH/Stock">
                        <div class="form-text">Source lokas gudang utama di Odoo</div>
                    </div>
                    <div class="col-12 pt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-premium btn-save">
                            <i class="fas fa-save me-2"></i>Simpan Koneksi
                        </button>
                        <button type="button" class="btn btn-premium btn-test" onclick="testConn(this)">
                            <i class="fas fa-vial me-2"></i>Uji Koneksi
                        </button>
                        <div class="ms-2 align-self-center small fw-700" id="connStatus"></div>
                    </div>
                </form>
            </div>

            <!-- Scheduler Configuration -->
            <div class="settings-card mb-3">
                <div class="card-title-premium">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Sinkronisasi Otomatis</span>
                    <div class="ms-auto form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enabled" <?= $enabled ? 'checked' : '' ?> style="cursor: pointer;">
                    </div>
                </div>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="form_type" value="schedule">
                    <input type="hidden" id="sync_enabled_hidden" name="enabled" value="<?= $enabled ? '1' : '0' ?>">
                    
                    <div id="scheduleContainer" style="display: <?= $enabled ? 'contents' : 'none' ?>;">
                        <div class="col-md-5">
                            <label class="form-label">Mode Sinkronisasi</label>
                            <select class="form-select" name="mode" id="mode">
                                <option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manual Only</option>
                                <option value="interval" <?= $mode === 'interval' ? 'selected' : '' ?>>Interval (Menit)</option>
                                <option value="daily" <?= $mode === 'daily' ? 'selected' : '' ?>>Harian (Spesifik Jam)</option>
                                <option value="weekly" <?= $mode === 'weekly' ? 'selected' : '' ?>>Mingguan (Hari & Jam)</option>
                            </select>
                        </div>
                        <div class="col-md-7 mode-interval" style="display: <?= $mode === 'interval' ? 'block' : 'none' ?>;">
                            <label class="form-label">Interval Menit</label>
                            <input type="number" class="form-control" name="interval_minutes" value="<?= $interval ?>">
                        </div>
                        <div class="col-md-4 mode-weekly" style="display: <?= $mode === 'weekly' ? 'block' : 'none' ?>;">
                            <label class="form-label">Hari Eksekusi</label>
                            <select class="form-select" name="weekday">
                                <?php $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                                foreach ($days as $i => $d): ?>
                                    <option value="<?= $i ?>" <?= $weekday == $i ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mode-time" style="display: <?= ($mode === 'weekly' || $mode === 'daily') ? 'block' : 'none' ?>;">
                            <label class="form-label">Jam Eksekusi</label>
                            <input type="time" class="form-control" name="time" value="<?= htmlspecialchars($time) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Scheduler Access Token</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="scheduler_token" value="<?= htmlspecialchars($scheduler_token_saved) ?>" placeholder="Contoh: token-keamanan-anda">
                                <button class="btn btn-outline-secondary" type="button" onclick="this.previousElementSibling.value = Math.random().toString(36).substring(2, 15)"><i class="fas fa-redo"></i></button>
                            </div>
                            <div class="form-text mt-2">Gunakan URL ini di Apps Script / Cron Job: <br>
                                <code class="bg-light p-1 rounded"><?= htmlspecialchars($schedule_hint_url) ?></code>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 pt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-premium btn-save">
                            <i class="fas fa-check-circle me-2"></i>Simpan Jadwal
                        </button>
                        <button type="button" class="btn btn-premium btn-test" onclick="confirmSyncNow(this)">
                            <i class="fas fa-sync me-2"></i>Jalankan Sekarang
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Security & Webhooks -->
        <div class="col-lg-5">
            <!-- Stats -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <div class="info-stat-card shadow-sm">
                        <div>
                            <div class="info-stat-label">Terakhir Sync</div>
                            <div class="info-stat-value"><?= $last_run ? date('H:i', $last_run) : '--:--' ?></div>
                            <div class="text-muted" style="font-size: 0.65rem;"><?= $last_run ? date('d M Y', $last_run) : 'Belum pernah' ?></div>
                        </div>
                        <i class="fas fa-history text-primary opacity-25 fa-2x"></i>
                    </div>
                </div>
                <div class="col-6">
                    <div class="info-stat-card shadow-sm">
                        <div>
                            <div class="info-stat-label">Berikutnya</div>
                            <div class="info-stat-value"><?= $next_due !== '-' ? explode(' ', $next_due)[count(explode(' ', $next_due))-1] : '-' ?></div>
                            <div class="text-muted" style="font-size: 0.65rem;"><?= $next_due !== '-' ? date('d M Y', strtotime($next_due)) : 'N/A' ?></div>
                        </div>
                        <i class="fas fa-hourglass-half text-warning opacity-25 fa-2x"></i>
                    </div>
                </div>
            </div>

            <!-- Lark & GSheet -->
            <div class="settings-card mb-3">
                <div class="card-title-premium">
                    <i class="fas fa-share-alt"></i>
                    <span>Integrasi Webhook</span>
                </div>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="form_type" value="hooks">
                    <div class="col-12">
                        <label class="form-label">Lark Webhook URL (Stock / Odoo)</label>
                        <input type="url" class="form-control" name="webhook_lark_url" value="<?= htmlspecialchars($lark_webhook) ?>" placeholder="https://open.larksuite.com/...">
                        <div class="form-text">Notifikasi ringkasan sinkronisasi stok Odoo</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Lark Webhook URL (Booking FU / Reschedule)</label>
                        <input type="url" class="form-control" name="webhook_lark_booking_url" value="<?= htmlspecialchars($lark_booking_webhook) ?>" placeholder="https://open.larksuite.com/...">
                        <div class="form-text">Notifikasi jika status booking menjadi FU atau Reschedule</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Lark Open ID to Tag (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text">@</span>
                            <input type="text" name="webhook_lark_booking_at_id" class="form-control" value="<?= htmlspecialchars($lark_booking_at_id) ?>" placeholder="Contoh: ou_xxxxxx">
                        </div>
                        <div class="form-text mt-1">Masukkan Open ID user untuk mention otomatis di notifikasi booking. Gunakan tanda koma (,) untuk memasukkan lebih dari satu ID.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">GSheets Webhook (Booking)</label>
                        <input type="url" class="form-control" name="gsheet_booking_webhook_url" value="<?= htmlspecialchars($gsheet_webhook) ?>" placeholder="https://script.google.com/...">
                        <div class="form-text">Sync otomatis data booking ke Google Sheets</div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-premium btn-save w-100">
                            <i class="fas fa-save me-2"></i>Update Webhook
                        </button>
                        <button type="button" class="btn btn-premium btn-test" id="btnTestLark">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Public Access -->
            <div class="settings-card mb-3">
                <div class="card-title-premium">
                    <i class="fas fa-globe"></i>
                    <span>Akses Stok Publik</span>
                </div>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="form_type" value="public_access">
                    <div class="col-12">
                        <label class="form-label">Public Access Token</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="public_stok_token" id="public_stok_token" value="<?= htmlspecialchars($public_stok_token) ?>" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="generateToken()"><i class="fas fa-magic"></i></button>
                        </div>
                    </div>
                    <?php if ($public_stok_token !== ''): ?>
                    <div class="col-12">
                        <label class="form-label">Public Link (Read-Only)</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm" id="public_link" value="<?= base_url('index.php?page=stok_klinik_publik&token=' . urlencode($public_stok_token)) ?>" readonly>
                            <button class="btn btn-outline-primary" type="button" onclick="copyPublicLink()"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <button type="submit" class="btn btn-premium btn-save w-100">Simpan Token</button>
                    </div>
                </form>
            </div>

            <!-- Danger Zone -->
            <div class="settings-card danger-zone">
                <div class="card-title-premium">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span class="text-danger">Zona Bahaya</span>
                </div>
                <p class="small text-muted mb-3 fw-500">Pilih data transaksi untuk dihapus secara permanen (Master Data Aman):</p>
                
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="form-check small"><input class="form-check-input truncate-check" type="checkbox" value="booking" id="c_b" checked><label class="form-check-label" for="c_b">Booking</label></div>
                        <div class="form-check small"><input class="form-check-input truncate-check" type="checkbox" value="request" id="c_r" checked><label class="form-check-label" for="c_r">Request</label></div>
                        <div class="form-check small"><input class="form-check-input truncate-check" type="checkbox" value="bhp" id="c_bhp" checked><label class="form-check-label" for="c_bhp">BHP</label></div>
                    </div>
                    <div class="col-6">
                        <div class="form-check small"><input class="form-check-input truncate-check" type="checkbox" value="hc" id="c_hc" checked><label class="form-check-label" for="c_hc">Stok HC</label></div>
                        <div class="form-check small"><input class="form-check-input truncate-check" type="checkbox" value="history" id="c_his" checked><label class="form-check-label" for="c_his">History Stok</label></div>
                        <div class="form-check small"><input class="form-check-input" type="checkbox" id="c_all" checked onchange="$('.truncate-check').prop('checked', this.checked)"><label class="form-check-label fw-700" for="c_all">ALL</label></div>
                    </div>
                </div>

                <button type="button" class="btn btn-danger w-100 btn-premium shadow-sm" onclick="confirmTruncateData()">
                    <i class="fas fa-trash-alt me-2"></i>Kosongkan Data Terpilih
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function confirmTruncateData() {
    const selected = [];
    $('.truncate-check:checked').each(function() {
        selected.push($(this).val());
    });

    if (selected.length === 0) {
        Swal.fire('Peringatan', 'Pilih minimal satu kategori data yang ingin dihapus.', 'warning');
        return;
    }

    const { value: confirmed } = await Swal.fire({
        title: 'Hapus Data Terpilih?',
        text: "Data yang dipilih akan DIHAPUS PERMANEN. Master Data (User, Barang, Klinik) tetap aman.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Lanjutkan',
        cancelButtonText: 'Batal',
        reverseButtons: true
    });

    if (confirmed) {
        const { value: password } = await Swal.fire({
            title: 'Konfirmasi Keamanan',
            text: 'Ketik "HAPUS" untuk mengonfirmasi tindakan ini:',
            input: 'text',
            inputPlaceholder: 'HAPUS',
            showCancelButton: true,
            confirmButtonText: 'Eksekusi',
            cancelButtonText: 'Batal',
            inputValidator: (value) => {
                if (value !== 'HAPUS') {
                    return 'Anda harus mengetik "HAPUS"!'
                }
            }
        });

        if (password === 'HAPUS') {
            executeTruncate(selected);
        }
    }
}

async function executeTruncate(modules) {
    Swal.fire({
        title: 'Sedang Menghapus...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    try {
        const fd = new FormData();
        fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
        modules.forEach(m => fd.append('modules[]', m));
        
        const res = await fetch('actions/process_truncate_data.php', {
            method: 'POST',
            body: fd
        });
        
        const data = await res.json();
        
        if (data.success) {
            Swal.fire({
                title: 'Berhasil!',
                text: data.message,
                icon: 'success'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Gagal!', data.message, 'error');
        }
    } catch (e) {
        Swal.fire('Error!', e.message, 'error');
    }
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function toggleFields() {
        const enabledEl = document.getElementById('enabled');
        const hiddenEnabled = document.getElementById('sync_enabled_hidden');
        const schedule = document.getElementById('scheduleContainer');
        const modeEl = document.getElementById('mode');
        
        const isEnabled = enabledEl && enabledEl.checked;
        if (hiddenEnabled) hiddenEnabled.value = isEnabled ? '1' : '0';
        if (schedule) schedule.style.display = isEnabled ? 'contents' : 'none';
        
        const mode = modeEl ? modeEl.value : 'manual';
        document.querySelectorAll('.mode-interval').forEach(el => el.style.display = (isEnabled && mode === 'interval') ? 'block' : 'none');
        document.querySelectorAll('.mode-weekly').forEach(el => el.style.display = (isEnabled && mode === 'weekly') ? 'block' : 'none');
        document.querySelectorAll('.mode-time').forEach(el => el.style.display = (isEnabled && (mode === 'weekly' || mode === 'daily')) ? 'block' : 'none');
    }

    const enabledEl = document.getElementById('enabled');
    const modeEl = document.getElementById('mode');
    if (enabledEl) enabledEl.addEventListener('change', toggleFields);
    if (modeEl) modeEl.addEventListener('change', toggleFields);
    
    toggleFields();

    window.confirmSyncNow = async function(btn) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;

        const { value: formValues } = await Swal.fire({
            title: 'Konfirmasi Sinkronisasi',
            html: `
                <div class="text-start">
                    <p class="small text-muted mb-3">Pilih waktu efektif sinkronisasi. Pemakaian lokal setelah waktu ini akan tetap muncul di dashboard untuk menghindari selisih.</p>
                    <label class="form-label small fw-bold">WAKTU EFEKTIF (OVERRIDE)</label>
                    <input type="datetime-local" id="override_time" class="form-control mb-2" value="${currentDateTime}">
                    <div class="form-text small text-primary"><i class="fas fa-info-circle me-1"></i> Biarkan default jika ingin menggunakan waktu saat ini.</div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#204EAB',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Jalankan',
            cancelButtonText: 'Batal',
            focusConfirm: false,
            preConfirm: () => {
                return document.getElementById('override_time').value;
            }
        });

        if (formValues) {
            runSyncNow(btn, formValues);
        }
    }

    window.runSyncNow = async function(btn, overrideTime = '') {
        const s = document.getElementById('connStatus');
        s.textContent = '⚡ Memproses...';
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
            if (overrideTime) {
                // Convert T to space for MySQL format
                fd.append('override_time', overrideTime.replace('T', ' ') + ':00');
            }
            const res = await fetch('api/sync_odoo.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                Swal.fire('Berhasil!', 'Sinkronisasi selesai.', 'success').then(() => location.reload());
            } else {
                s.textContent = '❌ Gagal: ' + (data.message || 'Unknown');
                Swal.fire('Gagal!', data.message || 'Terjadi kesalahan saat sinkronisasi.', 'error');
            }
        } catch (e) {
            s.textContent = '❌ Gagal: ' + e.message;
            Swal.fire('Error!', e.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    window.testConn = async function(btn) {
        const s = document.getElementById('connStatus');
        s.textContent = '🔍 Menguji...';
        btn.disabled = true;
        try {
            const res = await fetch('api/odoo_test.php');
            const data = await res.json();
            if (data.success) {
                s.textContent = '✅ Koneksi OK';
                s.className = 'ms-2 align-self-center small fw-700 text-success';
            } else {
                s.textContent = '❌ Gagal';
                s.className = 'ms-2 align-self-center small fw-700 text-danger';
            }
        } catch (e) {
            s.textContent = '❌ Error';
        } finally {
            btn.disabled = false;
        }
    }

    const btnTestLark = document.getElementById('btnTestLark');
    if (btnTestLark) {
        btnTestLark.addEventListener('click', async function() {
            btnTestLark.disabled = true;
            try {
                const fd = new FormData();
                fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
                const res = await fetch('api/test_lark_webhook.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) Swal.fire('Terkirim!', 'Pesan tes Lark berhasil dikirim.', 'success');
                else Swal.fire('Gagal', data.message, 'error');
            } catch (e) {
                Swal.fire('Error', e.message, 'error');
            } finally {
                btnTestLark.disabled = false;
            }
        });
    }
});

function generateToken() {
    const token = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
    document.getElementById('public_stok_token').value = token;
}

function copyPublicLink() {
    const link = document.getElementById('public_link');
    link.select();
    document.execCommand('copy');
    Swal.fire({ title: 'Tersalin!', text: 'Link publik sudah ada di clipboard.', icon: 'success', timer: 1500, showConfirmButton: false });
}
</script>
