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
        $gsheet = trim((string)($_POST['gsheet_booking_webhook_url'] ?? ''));
        set_setting('webhook_lark_url', $lark);
        set_setting('gsheet_booking_webhook_url', $gsheet);
        $msg = '<div class="alert alert-success">Webhook tersimpan.</div>';
    } else if ($form_type === 'public_access') {
        $token = trim((string)($_POST['public_stok_token'] ?? ''));
        set_setting('public_stok_token', $token);
        $msg = '<div class="alert alert-success">Token akses publik tersimpan.</div>';
    } else {
        $enabled = isset($_POST['enabled']) ? '1' : '0';
        $scheduler_token = trim((string)($_POST['scheduler_token'] ?? ''));
        set_setting('odoo_sync_token', $scheduler_token);
        set_setting('odoo_sync_enabled', $enabled);
        if ($enabled === '1') {
            $mode = $_POST['mode'] ?? 'manual';
            $interval = (string) max(0, (int)($_POST['interval_minutes'] ?? 0));
            $weekday = (string) max(0, min(6, (int)($_POST['weekday'] ?? 1)));
            $time = $_POST['time'] ?? '20:00';
            set_setting('odoo_sync_mode', $mode);
            set_setting('odoo_sync_interval_minutes', $interval);
            set_setting('odoo_sync_weekday', $weekday);
            set_setting('odoo_sync_time', $time);
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

<div class="container-fluid">
    <div class="row mb-2 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
                <i class="fas fa-cogs me-2"></i>Integrasi Odoo
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Integrasi Odoo</li>
                </ol>
            </nav>
        </div>
    </div>

    <?= $msg ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="fw-bold">Koneksi Odoo (RPC)</div>
                        <span class="badge bg-primary">RPC</span>
                    </div>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="form_type" value="rpc">
                        <div class="col-12">
                            <label class="form-label">Server URL</label>
                            <input type="text" class="form-control" name="rpc_url" value="<?= htmlspecialchars($rpc_url) ?>" placeholder="http://46.250.225.199:8072" required>
                            <div class="form-text">Masukkan base URL server Odoo (tanpa /web/...)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Database</label>
                            <input type="text" class="form-control" name="rpc_db" value="<?= htmlspecialchars($rpc_db) ?>" placeholder="Bumame_1701" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email / Username</label>
                            <input type="text" class="form-control" name="rpc_username" value="<?= htmlspecialchars($rpc_user) ?>" placeholder="user_test1@gmail.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="rpc_password" value="" placeholder="<?= $rpc_password_saved ? 'Tersimpan (kosongkan jika tidak diganti)' : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Metode Integrasi</label>
                            <input type="text" class="form-control" value="RPC (JSON-RPC)" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Kode Lokasi Gudang Utama (Odoo)</label>
                            <input type="text" class="form-control" name="gudang_location_code" value="<?= htmlspecialchars($gudang_location_code) ?>" placeholder="Contoh: WH/Stock">
                            <div class="form-text">Digunakan untuk menampilkan stok Gudang Utama dari Odoo (termasuk ketersediaan di Request Barang).</div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Koneksi</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="testConn(this)"><i class="fas fa-plug"></i> Tes Koneksi</button>
                            <small class="text-muted ms-2 align-self-center" id="connStatus"></small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="form_type" value="schedule">
                        <div class="col-12 d-flex align-items-center justify-content-between">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?= $enabled ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="enabled">Aktifkan sinkronisasi otomatis</label>
                            </div>
                            <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>"><?= $enabled ? 'Aktif' : 'Nonaktif' ?></span>
                        </div>
                        <div id="scheduleContainer" style="display: <?= $enabled ? 'block' : 'none' ?>;" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Mode</label>
                                <select class="form-select" name="mode" id="mode">
                                    <option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manual</option>
                                    <option value="interval" <?= $mode === 'interval' ? 'selected' : '' ?>>Interval (menit)</option>
                                    <option value="daily" <?= $mode === 'daily' ? 'selected' : '' ?>>Harian (jam)</option>
                                    <option value="weekly" <?= $mode === 'weekly' ? 'selected' : '' ?>>Mingguan (hari & jam)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mode-interval" style="display: <?= ($enabled && $mode === 'interval') ? 'block' : 'none' ?>;">
                                <label class="form-label">Interval (menit)</label>
                                <input type="number" class="form-control" id="interval_minutes" name="interval_minutes" value="<?= $interval ?>" min="1">
                            </div>
                            <div class="col-md-4 mode-weekly" style="display: <?= ($enabled && $mode === 'weekly') ? 'block' : 'none' ?>;">
                                <label class="form-label">Hari</label>
                                <select class="form-select" id="weekday" name="weekday">
                                    <?php
                                    $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                                    for ($i=0; $i<7; $i++): ?>
                                    <option value="<?= $i ?>" <?= $weekday === $i ? 'selected' : '' ?>><?= $days[$i] ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mode-time" style="display: <?= ($enabled && ($mode === 'weekly' || $mode === 'daily')) ? 'block' : 'none' ?>;">
                                <label class="form-label">Jam</label>
                                <input type="time" class="form-control" id="time" name="time" value="<?= htmlspecialchars($time) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Token Scheduler (untuk Apps Script)</label>
                                <input type="text" class="form-control" name="scheduler_token" value="<?= htmlspecialchars($scheduler_token_saved) ?>" placeholder="Contoh: bumame-sync-token">
                                <div class="form-text">Token ini dipakai di URL: <span class="fw-semibold"><?= htmlspecialchars($schedule_hint_url) ?></span></div>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                            <button type="button" class="btn btn-outline-primary" onclick="confirmSyncNow(this)"><i class="fas fa-sync-alt"></i> Jalankan Sekarang</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="testConn(this)"><i class="fas fa-plug"></i> Tes Koneksi</button>
                            <small class="text-muted ms-2 align-self-center" id="runStatus"></small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Public Inventory Token Section -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="fw-bold">Akses Publik Inventory</div>
                        <span class="badge bg-info">Read-Only</span>
                    </div>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="form_type" value="public_access">
                        <div class="col-12">
                            <label class="form-label">Token Akses Publik</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="public_stok_token" id="public_stok_token" value="<?= htmlspecialchars($public_stok_token) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="generateToken()"><i class="fas fa-random"></i></button>
                            </div>
                            <div class="form-text">Gunakan token ini untuk memberikan akses stok tanpa login ke tim lain.</div>
                        </div>
                        <?php if ($public_stok_token !== ''): ?>
                        <div class="col-12">
                            <label class="form-label">Link Publik (Copy-Paste)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="public_link" value="<?= base_url('index.php?page=stok_klinik_publik&token=' . urlencode($public_stok_token)) ?>" readonly>
                                <button class="btn btn-outline-primary" type="button" onclick="copyPublicLink()"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Token</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small">Terakhir Jalan</div>
                                    <div class="fw-bold fs-5"><?= $last_run_text ?></div>
                                </div>
                                <i class="fas fa-history fa-2x text-secondary opacity-25"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small">Jadwal Berikutnya</div>
                                    <div class="fw-bold fs-5"><?= $next_due ?></div>
                                </div>
                                <i class="fas fa-clock fa-2x text-secondary opacity-25"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small">Status Konfigurasi</div>
                                    <div>
                                        <span class="badge bg-primary"><?= strtoupper(htmlspecialchars($integration_method)) ?></span>
                                        <span class="badge <?= $rpc_url !== '' ? 'bg-success' : 'bg-secondary' ?>">RPC URL</span>
                                        <span class="badge <?= $rpc_db !== '' ? 'bg-success' : 'bg-secondary' ?>">DB</span>
                                        <span class="badge <?= $rpc_user !== '' ? 'bg-success' : 'bg-secondary' ?>">User</span>
                                        <span class="badge <?= $rpc_password_saved ? 'bg-success' : 'bg-secondary' ?>">Password</span>
                                    </div>
                                </div>
                                <i class="fas fa-sliders-h fa-2x text-secondary opacity-25"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="fw-bold mb-3"><i class="fas fa-bell me-2"></i>Notifikasi & Webhook</div>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="form_type" value="hooks">
                                <div class="col-12">
                                    <label class="form-label">Lark Webhook URL</label>
                                    <input type="url" class="form-control" name="webhook_lark_url" value="<?= htmlspecialchars($lark_webhook) ?>" placeholder="https://open.larksuite.com/open-apis/bot/v2/hook/...">
                                    <div class="form-text">Dipakai untuk kirim ringkasan hasil sync Odoo (sukses/gagal).</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Google Sheets Webhook (Booking)</label>
                                    <input type="url" class="form-control" name="gsheet_booking_webhook_url" value="<?= htmlspecialchars($gsheet_webhook) ?>" placeholder="https://script.google.com/macros/s/....../exec">
                                    <div class="form-text">Set Web App Apps Script (doPost) untuk menerima booking_created dan menulis ke Sheet.</div>
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Webhook</button>
                                    <button type="button" class="btn btn-outline-primary" id="btnTestLark"><i class="fas fa-paper-plane"></i> Test Lark</button>
                                    <div class="text-muted small align-self-center" id="larkTestStatus"></div>
                                    <?php if ($schedule_hint_url !== ''): ?>
                                        <a href="<?= htmlspecialchars($schedule_hint_url) ?>" target="_blank" class="btn btn-outline-secondary">
                                            <i class="fas fa-link"></i> Cek Scheduler URL
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function toggleFields() {
        const enabledEl = document.getElementById('enabled');
        const schedule = document.getElementById('scheduleContainer');
        const modeEl = document.getElementById('mode');
        const enabled = !!(enabledEl && enabledEl.checked);
        if (schedule) schedule.style.display = enabled ? 'block' : 'none';
        const mode = modeEl ? modeEl.value : 'manual';
        document.querySelectorAll('.mode-interval').forEach(el => el.style.display = (enabled && mode === 'interval') ? 'block' : 'none');
        document.querySelectorAll('.mode-weekly').forEach(el => el.style.display = (enabled && mode === 'weekly') ? 'block' : 'none');
        document.querySelectorAll('.mode-time').forEach(el => el.style.display = (enabled && (mode === 'weekly' || mode === 'daily')) ? 'block' : 'none');
        const intervalInput = document.getElementById('interval_minutes');
        const weekdaySelect = document.getElementById('weekday');
        const timeInput = document.getElementById('time');
        if (intervalInput) intervalInput.disabled = !(enabled && mode === 'interval');
        if (weekdaySelect) weekdaySelect.disabled = !(enabled && mode === 'weekly');
        if (timeInput) timeInput.disabled = !(enabled && (mode === 'weekly' || mode === 'daily'));
    }
    const enabledEl = document.getElementById('enabled');
    const modeEl = document.getElementById('mode');
    if (enabledEl) enabledEl.addEventListener('change', toggleFields);
    if (modeEl) modeEl.addEventListener('change', toggleFields);
    if (window.jQuery) {
        window.jQuery(function() {
            window.jQuery('#enabled').on('change', toggleFields);
            window.jQuery('#mode').on('change', toggleFields);
        });
    }
    toggleFields();

    window.confirmSyncNow = function(btn) {
        Swal.fire({
            title: 'Konfirmasi Sinkronisasi',
            text: 'Apakah Anda yakin ingin menjalankan sinkronisasi Odoo sekarang?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#204EAB',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Jalankan',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                runSyncNow(btn);
            }
        });
    }

    window.runSyncNow = async function(btn) {
        const s = document.getElementById('runStatus');
        s.textContent = 'Memproses...';
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
            const res = await fetch('api/sync_odoo.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Sinkronisasi Odoo telah selesai.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
                s.textContent = 'Selesai';
            } else {
                s.textContent = 'Gagal: ' + (data.message || 'Unknown');
            }
        } catch (e) {
            s.textContent = 'Gagal: ' + e.message;
        } finally {
            btn.disabled = false;
        }
    }
    window.testConn = async function(btn) {
        const s = document.getElementById('connStatus') || document.getElementById('runStatus');
        if (s) s.textContent = 'Menguji koneksi...';
        btn.disabled = true;
        try {
            const res = await fetch('api/odoo_test.php');
            const data = await res.json();
            if (data.success) {
                if (s) s.textContent = 'Koneksi OK';
            } else {
                if (s) s.textContent = 'Gagal: ' + (data.message || 'Tidak dapat terhubung');
            }
        } catch (e) {
            if (s) s.textContent = 'Gagal: ' + e.message;
        } finally {
            btn.disabled = false;
        }
    }

    const btnTestLark = document.getElementById('btnTestLark');
    const larkStatus = document.getElementById('larkTestStatus');
    if (btnTestLark) {
        btnTestLark.addEventListener('click', async function() {
            if (larkStatus) larkStatus.textContent = 'Mengirim test...';
            btnTestLark.disabled = true;
            try {
                const fd = new FormData();
                fd.append('_csrf', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>);
                const res = await fetch('api/test_lark_webhook.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data || !data.success) {
                    if (larkStatus) larkStatus.textContent = 'Gagal: ' + (data && data.message ? data.message : 'Unknown');
                    return;
                }
                if (larkStatus) larkStatus.textContent = 'OK. Preferred: ' + (data.preferred || '-');
                if (data.results) console.log('Lark test results', data.results);
            } catch (e) {
                if (larkStatus) larkStatus.textContent = 'Gagal: ' + e.message;
            } finally {
                btnTestLark.disabled = false;
            }
        });
    }
});

function generateToken() {
    const chars = '0123456789abcdef';
    let token = '';
    for (let i = 0; i < 32; i++) {
        token += chars[Math.floor(Math.random() * chars.length)];
    }
    document.getElementById('public_stok_token').value = token;
}

function copyPublicLink() {
    const link = document.getElementById('public_link');
    link.select();
    document.execCommand('copy');
    alert('Link berhasil disalin ke clipboard!');
}
</script>
