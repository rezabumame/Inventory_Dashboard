<?php
check_role(['super_admin', 'admin_gudang', 'admin_klinik']);

$message = '';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_klinik = $_SESSION['klinik_id'];

// --- Helper Functions ---

function generate_request_number($conn) {
    $prefix = "REQ/" . date('Ymd') . "/";
    $res = $conn->query("SELECT COUNT(*) as cnt FROM request_barang WHERE nomor_request LIKE '$prefix%'");
    $row = $res->fetch_assoc();
    return $prefix . str_pad($row['cnt'] + 1, 4, '0', STR_PAD_LEFT);
}

function generate_transfer_number($conn) {
    $prefix = "TRF/" . date('Ymd') . "/";
    $res = $conn->query("SELECT COUNT(*) as cnt FROM transfer_barang WHERE nomor_transfer LIKE '$prefix%'");
    $row = $res->fetch_assoc();
    return $prefix . str_pad($row['cnt'] + 1, 4, '0', STR_PAD_LEFT);
}

// --- ACTION HANDLERS ---

// 1. Create Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_request') {
    $ke_level = (string)($_POST['ke_level'] ?? ''); // gudang_utama or klinik
    $ke_id_post = (int)($_POST['ke_id'] ?? 0);
    $catatan = $_POST['catatan'];
    $items = $_POST['items']; // Array of barang_id and qty
    $qtys = $_POST['qtys'];

    $dari_level = '';
    $dari_id = 0;
    $ke_id = 0;

    // Determine Source (Requestor) and Destination
    if ($user_role == 'admin_klinik') {
        $dari_level = 'klinik';
        $dari_id = $user_klinik;
        $ke_level = 'klinik';
        $ke_id = $ke_id_post;
        if ($ke_id <= 0 || $ke_id == $dari_id) {
            $message = '<div class="alert alert-warning">Tujuan request harus klinik lain.</div>';
            $ke_id = 0;
        }
    }

    if ($ke_id > 0 && !empty($items) && count($items) > 0) {
        $conn->begin_transaction();
        try {
            $nomor_request = generate_request_number($conn);
            
            $stmt = $conn->prepare("INSERT INTO request_barang (nomor_request, dari_level, dari_id, ke_level, ke_id, status, catatan, created_by) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)");
            $stmt->bind_param("ssisisi", $nomor_request, $dari_level, $dari_id, $ke_level, $ke_id, $catatan, $user_id);
            $stmt->execute();
            $request_id = $conn->insert_id;

            $stmt_detail = $conn->prepare("INSERT INTO request_barang_detail (request_barang_id, barang_id, qty_request) VALUES (?, ?, ?)");
            foreach ($items as $index => $barang_id) {
                $qty = $qtys[$index];
                if ($qty > 0) {
                    $stmt_detail->bind_param("iii", $request_id, $barang_id, $qty);
                    $stmt_detail->execute();
                }
            }

            $conn->commit();
            $message = '<div class="alert alert-success">Request berhasil dibuat: ' . $nomor_request . '</div>';
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    } else {
        if (empty($message)) $message = '<div class="alert alert-warning">Tidak ada barang yang dipilih.</div>';
    }
}

// 2. Approve Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'approve_request') {
    $request_id = $_POST['request_id'];
    $approved_items = $_POST['approved_items']; // barang_id array
    $approved_qtys = $_POST['approved_qtys']; // qty array
    $status_action = $_POST['status_action']; // approve_all, reject
    
    // Fetch Request Info
    $req = $conn->query("SELECT * FROM request_barang WHERE id = $request_id")->fetch_assoc();
    
    // Validate Permissions
    $can_approve = false;
    if ($user_role == 'super_admin') $can_approve = true;
    if ($user_role == 'admin_gudang') {
        // Gudang can approve requests to Gudang Utama OR Inter-Clinic Transfers
        if ($req['ke_level'] == 'gudang_utama' || $req['status'] == 'pending_gudang' || ($req['dari_level'] == 'klinik' && $req['ke_level'] == 'klinik')) {
            $can_approve = true;
        }
    }
    if ($user_role == 'admin_klinik' && $req['ke_level'] == 'klinik' && $req['ke_id'] == $user_klinik) $can_approve = true;

    if ($can_approve) {
        if ($status_action == 'reject') {
            $conn->query("UPDATE request_barang SET status = 'rejected', approved_by = $user_id WHERE id = $request_id");
            $message = '<div class="alert alert-warning">Permintaan barang telah ditolak.</div>';
        } else {
            $conn->begin_transaction();
            try {
                if ($req['dari_level'] === 'hc' || $req['ke_level'] === 'hc') {
                    throw new Exception("Request level HC sudah tidak digunakan");
                }

                $stmt_req_upd = $conn->prepare("UPDATE request_barang_detail SET qty_approved = ? WHERE request_barang_id = ? AND barang_id = ?");

                $all_approved = true;

                foreach ($approved_items as $idx => $b_id) {
                    $qty_app = (int)$approved_qtys[$idx];
                    
                    $res_req_det = $conn->query("SELECT qty_request FROM request_barang_detail WHERE request_barang_id = $request_id AND barang_id = $b_id");
                    $row_req_det = $res_req_det->fetch_assoc();
                    $qty_req = $row_req_det['qty_request'];

                    if ($qty_app < $qty_req) $all_approved = false;
                    if ($qty_app <= 0) continue;

                    $stmt_req_upd->bind_param("iii", $qty_app, $request_id, $b_id);
                    $stmt_req_upd->execute();
                }

                $final_status = $all_approved ? 'approved' : 'partial';
                $conn->query("UPDATE request_barang SET status = '$final_status', approved_by = $user_id WHERE id = $request_id");

                $conn->commit();
                $message = '<div class="alert alert-success">Permintaan barang telah disetujui. Silakan unggah dokumen untuk memproses pergerakan stok.</div>';

            } catch (Exception $e) {
                $conn->rollback();
                $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
            }
        }
    }
}

// 2b. Process Request (Upload Document + Apply Stock Movement)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_request') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $received_items = $_POST['received_items'] ?? [];
    $received_qtys = $_POST['received_qtys'] ?? [];

    $ensure_col = function(string $table, string $column, string $definition) use ($conn) {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
        }
    };
    $ensure_col('request_barang', 'dokumen_path', "VARCHAR(255) NULL");
    $ensure_col('request_barang', 'dokumen_name', "VARCHAR(255) NULL");
    $ensure_col('request_barang', 'processed_by', "INT NULL");
    $ensure_col('request_barang', 'processed_at', "TIMESTAMP NULL");
    $ensure_col('request_barang_detail', 'qty_received', "INT NOT NULL DEFAULT 0");

    $req = $conn->query("SELECT * FROM request_barang WHERE id = $request_id")->fetch_assoc();
    if (!$req) {
        $message = '<div class="alert alert-danger">Permintaan barang tidak ditemukan.</div>';
    } else {
        $can_process = false;
        if ($user_role === 'super_admin') $can_process = true;
        if ($user_role === 'admin_klinik' && $req['dari_level'] === 'klinik' && (int)$req['dari_id'] === (int)$user_klinik) $can_process = true;

        if (!$can_process) {
            $message = '<div class="alert alert-danger">Anda tidak memiliki akses untuk memproses permintaan ini.</div>';
        } elseif (!in_array($req['status'], ['approved', 'partial'], true)) {
            $message = '<div class="alert alert-warning">Permintaan ini belum disetujui atau sudah diproses.</div>';
        } else {
            if (!isset($_FILES['dokumen']) || $_FILES['dokumen']['error'] !== UPLOAD_ERR_OK) {
                $message = '<div class="alert alert-danger">Dokumen wajib diunggah.</div>';
            } else {
                $allowed_ext = ['pdf', 'xls', 'xlsx', 'csv'];
                $name = (string)($_FILES['dokumen']['name'] ?? '');
                $tmp = (string)($_FILES['dokumen']['tmp_name'] ?? '');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) {
                    $message = '<div class="alert alert-danger">Format dokumen tidak didukung. Gunakan PDF/XLS/XLSX/CSV.</div>';
                } else {
                    $upload_dir = __DIR__ . '/../uploads/request_barang';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                    $safe_nomor = preg_replace('/[^A-Za-z0-9_\\-]/', '_', (string)$req['nomor_request']);
                    $filename = $safe_nomor . '_' . date('Ymd_His') . '.' . $ext;
                    $dest = $upload_dir . '/' . $filename;
                    if (!move_uploaded_file($tmp, $dest)) {
                        $message = '<div class="alert alert-danger">Gagal menyimpan dokumen.</div>';
                    } else {
                        $conn->begin_transaction();
                        try {
                            $source_level = $req['ke_level'];
                            $source_id = (int)$req['ke_id'];
                            $dest_level = $req['dari_level'];
                            $dest_id = (int)$req['dari_id'];
                            if ($dest_level !== 'klinik') throw new Exception('Tujuan tidak valid');

                            $nomor_transfer = generate_transfer_number($conn);
                            $catatan_trf = "Pemenuhan permintaan barang " . $req['nomor_request'];
                            $stmt_trf = $conn->prepare("INSERT INTO transfer_barang (nomor_transfer, request_barang_id, dari_level, dari_id, ke_level, ke_id, status, transfer_by, catatan) VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?)");
                            $stmt_trf->bind_param("sisissis", $nomor_transfer, $request_id, $source_level, $source_id, $dest_level, $dest_id, $user_id, $catatan_trf);
                            $stmt_trf->execute();
                            $transfer_id = (int)$conn->insert_id;

                            $stmt_trf_det = $conn->prepare("INSERT INTO transfer_barang_detail (transfer_barang_id, barang_id, qty) VALUES (?, ?, ?)");
                            $stmt_req_recv = $conn->prepare("UPDATE request_barang_detail SET qty_received = ? WHERE request_barang_id = ? AND barang_id = ?");

                            $conn->query("
                                CREATE TABLE IF NOT EXISTS stock_mirror (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    odoo_product_id VARCHAR(64) NOT NULL,
                                    kode_barang VARCHAR(64) NOT NULL,
                                    location_code VARCHAR(100) NOT NULL,
                                    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
                                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                    UNIQUE KEY uniq_loc_prod (odoo_product_id, location_code)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                            ");
                            $conn->query("
                                CREATE TABLE IF NOT EXISTS barang_uom_conversion (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    barang_id INT NOT NULL,
                                    from_uom VARCHAR(20) NULL,
                                    to_uom VARCHAR(20) NULL,
                                    multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
                                    note VARCHAR(255) NULL,
                                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                    UNIQUE KEY uniq_barang (barang_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                            ");

                            $barang_cache = [];
                            $get_barang_info = function(int $barang_id) use ($conn, &$barang_cache): array {
                                if ($barang_id <= 0) return ['kode_barang' => '', 'odoo_product_id' => '', 'nama_barang' => '-', 'satuan' => '', 'multiplier' => 1.0];
                                if (isset($barang_cache[$barang_id])) return $barang_cache[$barang_id];
                                $r = $conn->query("
                                    SELECT b.kode_barang, b.odoo_product_id, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan, COALESCE(uc.multiplier, 1) AS multiplier
                                    FROM barang b
                                    LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
                                    WHERE b.id = $barang_id
                                    LIMIT 1
                                ");
                                $row = $r && $r->num_rows > 0 ? $r->fetch_assoc() : [];
                                $m = (float)($row['multiplier'] ?? 1);
                                if ($m <= 0) $m = 1;
                                $info = [
                                    'kode_barang' => (string)($row['kode_barang'] ?? ''),
                                    'odoo_product_id' => (string)($row['odoo_product_id'] ?? ''),
                                    'nama_barang' => (string)($row['nama_barang'] ?? '-'),
                                    'satuan' => (string)($row['satuan'] ?? ''),
                                    'multiplier' => $m
                                ];
                                $barang_cache[$barang_id] = $info;
                                return $info;
                            };

                            $location_candidates = function(string $input): array {
                                $input = trim($input);
                                if ($input === '') return [];
                                $candidates = [];
                                $candidates[] = $input;
                                if (stripos($input, '/stock') === false && strpos($input, '/') === false) {
                                    $candidates[] = $input . '/Stock';
                                }
                                if (preg_match('/\/Stock$/i', $input)) {
                                    $candidates[] = preg_replace('/\/Stock$/i', '', $input);
                                }
                                $seen = [];
                                $uniq = [];
                                foreach ($candidates as $c) {
                                    $k = strtolower($c);
                                    if (isset($seen[$k])) continue;
                                    $seen[$k] = true;
                                    $uniq[] = $c;
                                }
                                $prefer = [];
                                foreach ($uniq as $c) if (preg_match('/\/Stock$/i', $c)) $prefer[] = $c;
                                foreach ($uniq as $c) if (!preg_match('/\/Stock$/i', $c)) $prefer[] = $c;
                                return $prefer;
                            };

                            $get_best_mirror = function(array $locs, string $kode_barang, string $odoo_product_id) use ($conn): array {
                                $kb = trim($kode_barang);
                                $oid = trim($odoo_product_id);
                                $clauses = [];
                                if ($kb !== '') $clauses[] = "TRIM(kode_barang) = '" . $conn->real_escape_string($kb) . "'";
                                if ($oid !== '') $clauses[] = "TRIM(odoo_product_id) = '" . $conn->real_escape_string($oid) . "'";
                                if (empty($clauses) || empty($locs)) return ['loc' => '', 'qty' => 0, 'last_update' => ''];
                                $best_qty = 0;
                                $best_loc = '';
                                foreach ($locs as $loc) {
                                    $loc_esc = $conn->real_escape_string(trim($loc));
                                    $where = "TRIM(location_code) = '$loc_esc' AND (" . implode(' OR ', $clauses) . ")";
                                    $res = $conn->query("SELECT COALESCE(MAX(qty), 0) AS qty FROM stock_mirror WHERE $where");
                                    $q = (int)floor((float)($res && $res->num_rows > 0 ? ($res->fetch_assoc()['qty'] ?? 0) : 0));
                                    if ($q > $best_qty) {
                                        $best_qty = $q;
                                        $best_loc = (string)$loc;
                                    }
                                }
                                $last_update = '';
                                if ($best_loc !== '') {
                                    $loc_esc = $conn->real_escape_string(trim($best_loc));
                                    $ru = $conn->query("SELECT MAX(updated_at) AS last_update FROM stock_mirror WHERE TRIM(location_code) = '$loc_esc'");
                                    if ($ru && $ru->num_rows > 0) $last_update = (string)($ru->fetch_assoc()['last_update'] ?? '');
                                }
                                return ['loc' => $best_loc, 'qty' => $best_qty, 'last_update' => $last_update];
                            };

                            $get_effective_qty = function(string $level, int $level_id, int $barang_id) use ($conn, $get_barang_info, $location_candidates, $get_best_mirror): array {
                                $info = $get_barang_info($barang_id);
                                $mult = (float)($info['multiplier'] ?? 1);
                                if ($mult <= 0) $mult = 1;

                                if ($level === 'gudang_utama') {
                                    $stmt = $conn->prepare("SELECT qty FROM stok_gudang_utama WHERE barang_id = ? LIMIT 1");
                                    $stmt->bind_param("i", $barang_id);
                                    $stmt->execute();
                                    $qty = (int)($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
                                    return ['qty' => $qty, 'loc' => ''];
                                }

                                if ($level !== 'klinik' || $level_id <= 0) return ['qty' => 0, 'loc' => ''];
                                $r = $conn->query("SELECT kode_klinik, nama_klinik FROM klinik WHERE id = $level_id LIMIT 1");
                                $kode_klinik = '';
                                if ($r && $r->num_rows > 0) $kode_klinik = (string)($r->fetch_assoc()['kode_klinik'] ?? '');
                                $locs = $location_candidates($kode_klinik);
                                $mirror = $get_best_mirror($locs, (string)$info['kode_barang'], (string)$info['odoo_product_id']);
                                $base = (float)($mirror['qty'] ?? 0) * $mult;
                                $lu = (string)($mirror['last_update'] ?? '');

                                $delta_in = 0;
                                $delta_out = 0;
                                if ($lu !== '') {
                                    $lu_esc = $conn->real_escape_string($lu);
                                    $res = $conn->query("
                                        SELECT
                                            COALESCE(SUM(CASE WHEN tipe_transaksi = 'in' THEN qty ELSE 0 END), 0) AS in_qty,
                                            COALESCE(SUM(CASE WHEN tipe_transaksi = 'out' THEN qty ELSE 0 END), 0) AS out_qty
                                        FROM transaksi_stok
                                        WHERE barang_id = $barang_id AND level = 'klinik' AND level_id = $level_id AND created_at > '$lu_esc'
                                    ");
                                    if ($res && $res->num_rows > 0) {
                                        $row = $res->fetch_assoc();
                                        $delta_in = (int)($row['in_qty'] ?? 0);
                                        $delta_out = (int)($row['out_qty'] ?? 0);
                                    }
                                }

                                $eff = (int)floor($base) + $delta_in - $delta_out;
                                if ($eff < 0) $eff = 0;

                                if (($mirror['loc'] ?? '') === '' && $lu === '') {
                                    $stmt = $conn->prepare("SELECT qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ? LIMIT 1");
                                    $stmt->bind_param("ii", $barang_id, $level_id);
                                    $stmt->execute();
                                    $eff = (int)($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
                                }

                                return ['qty' => $eff, 'loc' => (string)($mirror['loc'] ?? '')];
                            };

                            $stmt_set_src_utama = $conn->prepare("INSERT INTO stok_gudang_utama (barang_id, qty, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(updated_by), updated_at = NOW()");
                            $stmt_set_src_klinik = $conn->prepare("INSERT INTO stok_gudang_klinik (barang_id, klinik_id, qty, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(updated_by), updated_at = NOW()");

                            $stmt_inc_check = $conn->prepare("SELECT id, qty FROM stok_gudang_klinik WHERE barang_id = ? AND klinik_id = ?");
                            $stmt_inc_ins = $conn->prepare("INSERT INTO stok_gudang_klinik (barang_id, klinik_id, qty) VALUES (?, ?, ?)");
                            $stmt_inc_upd = $conn->prepare("UPDATE stok_gudang_klinik SET qty = qty + ? WHERE barang_id = ? AND klinik_id = ?");

                            $stmt_log = $conn->prepare("INSERT INTO transaksi_stok (barang_id, level, level_id, tipe_transaksi, qty, qty_sebelum, qty_sesudah, referensi_tipe, referensi_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'transfer', ?, ?)");

                            $all_full = true;
                            foreach ($received_items as $idx => $b_id_raw) {
                                $b_id = (int)$b_id_raw;
                                $qty_recv = (int)($received_qtys[$idx] ?? 0);
                                if ($b_id <= 0) continue;
                                if ($qty_recv < 0) throw new Exception('Qty tidak valid');

                                $row_det = $conn->query("SELECT qty_request, qty_approved FROM request_barang_detail WHERE request_barang_id = $request_id AND barang_id = $b_id")->fetch_assoc();
                                if (!$row_det) continue;
                                $qty_app = (int)($row_det['qty_approved'] ?? 0);
                                $qty_req = (int)($row_det['qty_request'] ?? 0);
                                if ($qty_app <= 0) $qty_app = $qty_req;
                                if ($qty_recv > $qty_app) throw new Exception("Qty diterima melebihi qty yang disetujui untuk barang ID: $b_id");
                                if ($qty_recv < $qty_app) $all_full = false;
                                if ($qty_recv === 0) {
                                    $stmt_req_recv->bind_param("iii", $qty_recv, $request_id, $b_id);
                                    $stmt_req_recv->execute();
                                    continue;
                                }

                                $source_qty_before = 0;
                                $src = $get_effective_qty((string)$source_level, (int)$source_id, (int)$b_id);
                                $source_qty_before = (int)($src['qty'] ?? 0);
                                $uom = (string)($get_barang_info((int)$b_id)['satuan'] ?? '');
                                if ($source_qty_before < $qty_recv) {
                                    $info = $get_barang_info((int)$b_id);
                                    $kode = trim((string)($info['kode_barang'] ?? ''));
                                    $nm = trim((string)($info['nama_barang'] ?? '-'));
                                    $label = ($kode !== '' ? ($kode . ' - ') : '') . $nm;
                                    $src_name = ($source_level === 'gudang_utama') ? 'Gudang Utama' : 'Klinik sumber';
                                    if ($source_level === 'klinik') {
                                        $r = $conn->query("SELECT nama_klinik FROM klinik WHERE id = " . (int)$source_id . " LIMIT 1");
                                        if ($r && $r->num_rows > 0) $src_name = (string)($r->fetch_assoc()['nama_klinik'] ?? $src_name);
                                    }
                                    throw new Exception("Stok tidak mencukupi di $src_name untuk barang: $label. Tersedia: $source_qty_before" . ($uom !== '' ? " $uom" : '') . ", Diterima: $qty_recv" . ($uom !== '' ? " $uom" : ''));
                                }
                                $source_qty_after = $source_qty_before - $qty_recv;

                                if ($source_level == 'gudang_utama') {
                                    $stmt_set_src_utama->bind_param("iii", $b_id, $source_qty_after, $user_id);
                                    $stmt_set_src_utama->execute();
                                } else {
                                    $stmt_set_src_klinik->bind_param("iiii", $b_id, $source_id, $source_qty_after, $user_id);
                                    $stmt_set_src_klinik->execute();
                                }

                                $dest_qty_before = 0;
                                $stmt_inc_check->bind_param("ii", $b_id, $dest_id);
                                $stmt_inc_check->execute();
                                $res_check = $stmt_inc_check->get_result();
                                if ($res_check->num_rows > 0) {
                                    $row_dest = $res_check->fetch_assoc();
                                    $dest_qty_before = (int)($row_dest['qty'] ?? 0);
                                    $stmt_inc_upd->bind_param("iii", $qty_recv, $b_id, $dest_id);
                                    $stmt_inc_upd->execute();
                                } else {
                                    $dest_qty_before = 0;
                                    $stmt_inc_ins->bind_param("iii", $b_id, $dest_id, $qty_recv);
                                    $stmt_inc_ins->execute();
                                }
                                $dest_qty_after = $dest_qty_before + $qty_recv;

                                $stmt_trf_det->bind_param("iii", $transfer_id, $b_id, $qty_recv);
                                $stmt_trf_det->execute();

                                $stmt_req_recv->bind_param("iii", $qty_recv, $request_id, $b_id);
                                $stmt_req_recv->execute();

                                $log_type = 'out';
                                $stmt_log->bind_param("isisiiiii", $b_id, $source_level, $source_id, $log_type, $qty_recv, $source_qty_before, $source_qty_after, $transfer_id, $user_id);
                                $stmt_log->execute();
                                $log_type = 'in';
                                $stmt_log->bind_param("isisiiiii", $b_id, $dest_level, $dest_id, $log_type, $qty_recv, $dest_qty_before, $dest_qty_after, $transfer_id, $user_id);
                                $stmt_log->execute();
                            }

                            $dok_rel = 'uploads/request_barang/' . $filename;
                            $dok_name = mb_substr(basename($name), 0, 255);
                            $final_status = 'completed';
                            if (!$all_full) $final_status = 'partial';
                            $stmt_up = $conn->prepare("UPDATE request_barang SET status = ?, dokumen_path = ?, dokumen_name = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                            $stmt_up->bind_param("sssii", $final_status, $dok_rel, $dok_name, $user_id, $request_id);
                            $stmt_up->execute();

                            $conn->commit();
                            $message = '<div class="alert alert-success">Dokumen berhasil diunggah. Pergerakan stok telah diproses.</div>';
                        } catch (Exception $e) {
                            $conn->rollback();
                            $message = '<div class="alert alert-danger">Gagal memproses permintaan: ' . $e->getMessage() . '</div>';
                        }
                    }
                }
            }
        }
    }
}

// 3. Cancel Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cancel_request') {
    $request_id = $_POST['request_id'];
    // Validate ownership: Must be created by user and status pending
    $stmt = $conn->prepare("SELECT id FROM request_barang WHERE id = ? AND created_by = ? AND status = 'pending'");
    $stmt->bind_param("ii", $request_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $cat = $conn->real_escape_string("Dibatalkan oleh pemohon");
        $conn->query("UPDATE request_barang SET status = 'rejected', catatan = CONCAT(COALESCE(catatan,''), '\n', '$cat') WHERE id = $request_id");
        $message = '<div class="alert alert-success">Permintaan barang berhasil dibatalkan.</div>';
    } else {
        $message = '<div class="alert alert-danger">Permintaan barang tidak dapat dibatalkan.</div>';
    }
}

// --- DATA FETCHING ---

// Stock Availability loaded on demand
$stock_available = [];

// Fetch Barang for Dropdown
$conn->query("
    CREATE TABLE IF NOT EXISTS barang_uom_conversion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barang_id INT NOT NULL,
        from_uom VARCHAR(20) NULL,
        to_uom VARCHAR(20) NULL,
        multiplier DECIMAL(18,8) NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_barang (barang_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$seeded_barang_from_mirror = false;
try {
    $conn->query("
        INSERT INTO barang (odoo_product_id, kode_barang, nama_barang, satuan, stok_minimum, kategori)
        SELECT DISTINCT sm.odoo_product_id, sm.kode_barang, sm.kode_barang, 'Unit', 0, 'Odoo'
        FROM stock_mirror sm
        LEFT JOIN barang b ON b.odoo_product_id = sm.odoo_product_id
        WHERE sm.odoo_product_id IS NOT NULL AND sm.odoo_product_id <> ''
          AND b.id IS NULL
    ");
    $seeded_barang_from_mirror = ($conn->affected_rows > 0);
} catch (Exception $e) {
    $seeded_barang_from_mirror = false;
}

$res_barang = $conn->query("
    SELECT b.id, b.kode_barang, b.odoo_product_id, b.barcode, b.nama_barang, COALESCE(uc.to_uom, b.satuan) AS satuan
    FROM barang b
    LEFT JOIN barang_uom_conversion uc ON uc.barang_id = b.id
    ORDER BY b.nama_barang ASC
");
$barang_list = [];
while ($b = $res_barang->fetch_assoc()) $barang_list[] = $b;

$target_kliniks = [];
if ($user_role === 'admin_klinik') {
    $kid = (int)$user_klinik;
    $res = $conn->query("SELECT id, nama_klinik, status FROM klinik WHERE id <> $kid ORDER BY (status='active') DESC, nama_klinik ASC");
    while ($res && ($row = $res->fetch_assoc())) $target_kliniks[] = $row;
}

// List Requests - Separate Outgoing (My Requests) and Incoming (To Me)
$outgoing_requests = [];
$incoming_requests = [];

// Outgoing: Created by me
if (in_array($user_role, ['admin_klinik', 'petugas_hc'])) {
    $query = "SELECT 
                r.*, 
                u.nama_lengkap as requestor_name,
                CASE
                    WHEN r.ke_level = 'klinik' THEN CONCAT('Klinik - ', COALESCE(k_to.nama_klinik, '-'))
                    WHEN r.ke_level = 'gudang_utama' THEN 'Gudang Utama'
                    ELSE UPPER(r.ke_level)
                END AS tujuan_label
              FROM request_barang r 
              JOIN users u ON r.created_by = u.id
              LEFT JOIN klinik k_to ON (r.ke_level = 'klinik' AND r.ke_id = k_to.id)
              WHERE r.created_by = $user_id 
              ORDER BY r.created_at DESC";
    $res = $conn->query($query);
    while($row = $res->fetch_assoc()) $outgoing_requests[] = $row;
}

// Incoming: Directed to me
if (in_array($user_role, ['admin_gudang', 'admin_klinik', 'super_admin'])) {
    $where = "1=0"; // Default false
    if ($user_role == 'super_admin') {
        $where = "1=1"; // See all? Or maybe just pending? Let's show all for super admin
    } elseif ($user_role == 'admin_gudang') {
        // Admin Gudang sees requests to Gudang Utama AND Inter-Clinic Transfers (Klinik -> Klinik)
        $where = "ke_level = 'gudang_utama' OR (dari_level = 'klinik' AND ke_level = 'klinik')";
    } elseif ($user_role == 'admin_klinik') {
        $where = "ke_level = 'klinik' AND ke_id = $user_klinik";
    }

    $query = "SELECT r.*, u.nama_lengkap as requestor_name 
              , CASE
                    WHEN r.dari_level = 'klinik' THEN CONCAT('Klinik - ', COALESCE(k_from.nama_klinik, '-'))
                    WHEN r.dari_level = 'gudang_utama' THEN 'Gudang Utama'
                    ELSE UPPER(r.dari_level)
                END AS dari_label
              , CASE
                    WHEN r.ke_level = 'klinik' THEN CONCAT('Klinik - ', COALESCE(k_to.nama_klinik, '-'))
                    WHEN r.ke_level = 'gudang_utama' THEN 'Gudang Utama'
                    ELSE UPPER(r.ke_level)
                END AS tujuan_label
              FROM request_barang r 
              JOIN users u ON r.created_by = u.id
              LEFT JOIN klinik k_from ON (r.dari_level = 'klinik' AND r.dari_id = k_from.id)
              LEFT JOIN klinik k_to ON (r.ke_level = 'klinik' AND r.ke_id = k_to.id)
              WHERE $where
              ORDER BY r.created_at DESC";
    $res = $conn->query($query);
    while($row = $res->fetch_assoc()) $incoming_requests[] = $row;
}

$incoming_all = $incoming_requests;

?>

<div class="row mb-4 align-items-center">
    <div class="col">
        <h1 class="h3 mb-1 fw-bold" style="color: #204EAB;">
            <i class="fas fa-boxes me-2"></i>Request Barang
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Request Barang</li>
            </ol>
        </nav>
    </div>
    <div class="col-auto">
        <?php if (in_array($user_role, ['admin_klinik'])): ?>
        <button class="btn shadow-sm text-white px-4" style="background-color: #204EAB;" data-bs-toggle="modal" data-bs-target="#modalRequest">
            <i class="fas fa-plus me-2"></i>Buat Request
        </button>
        <?php endif; ?>
    </div>
</div>

<?= $message ?>

<ul class="nav nav-pills mb-4" id="requestTabs" role="tablist">
    <?php if (in_array($user_role, ['super_admin', 'admin_gudang', 'admin_klinik'], true)): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill px-4 <?= in_array($user_role, ['super_admin', 'admin_gudang', 'admin_klinik'], true) ? 'active' : '' ?>" id="incoming-tab" data-bs-toggle="tab" data-bs-target="#incoming" type="button" role="tab">
            <i class="fas fa-inbox me-2"></i>Masuk <span class="badge bg-danger ms-1"><?= count(array_filter($incoming_all, function($r){ return in_array($r['status'], ['pending', 'pending_gudang'], true); })) ?></span>
        </button>
    </li>
    <?php endif; ?>
    
    <?php if (in_array($user_role, ['admin_klinik', 'petugas_hc'])): ?>
    <li class="nav-item ms-2" role="presentation">
        <button class="nav-link rounded-pill px-4 <?= ($user_role == 'petugas_hc') ? 'active' : '' ?>" id="outgoing-tab" data-bs-toggle="tab" data-bs-target="#outgoing" type="button" role="tab">
            <i class="fas fa-paper-plane me-2"></i>Permintaan Saya
        </button>
    </li>
    <?php endif; ?>
</ul>

<style>
    .nav-pills .nav-link { color: #6c757d; font-weight: 500; }
    .nav-pills .nav-link.active { background-color: #204EAB !important; color: white !important; }
    .breadcrumb-item + .breadcrumb-item::before { content: "/"; }
    .datatable thead th { background-color: #f8f9fa; color: #444; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
</style>

<div class="tab-content" id="requestTabsContent">
    
    <!-- Incoming Requests -->
    <div class="tab-pane fade <?= in_array($user_role, ['super_admin', 'admin_gudang', 'admin_klinik'], true) ? 'show active' : '' ?>" id="incoming" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>No Request</th>
                                <th>Tanggal</th>
                                <th>Dari</th>
                                <th>Tujuan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incoming_all as $row): ?>
                            <tr>
                                <td><?= $row['nomor_request'] ?></td>
                                <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                <td><?= htmlspecialchars(($row['dari_label'] ?? strtoupper($row['dari_level'])) . ' (' . ($row['requestor_name'] ?? '-') . ')') ?></td>
                                <td><?= htmlspecialchars($row['tujuan_label'] ?? strtoupper($row['ke_level'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= in_array($row['status'], ['pending', 'pending_gudang']) ? 'warning' : ($row['status'] == 'approved' ? 'success' : ($row['status'] == 'rejected' ? 'danger' : 'secondary')) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm text-white" style="background-color: #204EAB;" onclick="viewRequest(<?= $row['id'] ?>)">
                                        Detail
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Outgoing Requests (My Requests) -->
    <div class="tab-pane fade <?= ($user_role == 'petugas_hc') ? 'show active' : '' ?>" id="outgoing" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>No Request</th>
                                <th>Tanggal</th>
                                <th>Tujuan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outgoing_requests as $row): ?>
                            <tr>
                                <td><?= $row['nomor_request'] ?></td>
                                <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                <td><?= htmlspecialchars($row['tujuan_label'] ?? strtoupper($row['ke_level'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['status'] == 'pending' ? 'warning' : ($row['status'] == 'approved' ? 'success' : ($row['status'] == 'rejected' ? 'danger' : 'secondary')) ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm text-white" style="background-color: #204EAB;" onclick="viewRequest(<?= $row['id'] ?>)">
                                        Detail
                                    </button>
                                    <?php if ($row['status'] == 'pending'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="cancelRequest(<?= $row['id'] ?>)">
                                        Cancel
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Create Request -->
<div class="modal fade" id="modalRequest" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" onsubmit="return validateStock()">
                <input type="hidden" name="action" value="create_request">
                <div class="modal-header" style="background-color: #204EAB;">
                    <h5 class="modal-title text-white fw-bold"><i class="fas fa-plus-circle me-2"></i>Buat Permintaan Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Tujuan Request</label>
                            <?php if ($user_role === 'admin_klinik'): ?>
                                <?php $default_target_id = !empty($target_kliniks) ? (int)$target_kliniks[0]['id'] : 0; ?>
                                <select class="form-select" id="tujuanRequest">
                                    <?php foreach ($target_kliniks as $k): ?>
                                        <option value="klinik:<?= (int)$k['id'] ?>">
                                            <?= htmlspecialchars($k['nama_klinik']) ?><?= ($k['status'] ?? 'active') !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="ke_level" id="ke_level" value="klinik">
                                <input type="hidden" name="ke_id" id="ke_id" value="<?= (int)$default_target_id ?>">
                            <?php else: ?>
                                <input type="text" class="form-control" value="Klinik" readonly>
                                <input type="hidden" name="ke_level" value="klinik">
                                <input type="hidden" name="ke_id" value="0">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Catatan</label>
                            <textarea name="catatan" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Barang</th>
                                    <th width="150">Ketersediaan</th>
                                    <th width="100">Qty</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody id="reqBody">
                                <!-- Rows -->
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-success btn-sm" onclick="addRow()">+ Tambah Baris</button>
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn text-white px-4" style="background-color: #204EAB;">Kirim Permintaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Approval/View -->
<div class="modal fade" id="modalView" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="approvalForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="form_action" value="approve_request">
                <input type="hidden" name="request_id" id="view_request_id">
                <input type="hidden" name="status_action" id="status_action" value="approve_all">
                
                <div class="modal-header">
                    <h5 class="modal-title">Detail Request <span id="view_nomor"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="view_content">Loading...</div>
                </div>
                <div class="modal-footer" id="view_footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form Cancel (Hidden) -->
<form id="cancelForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="cancel_request">
    <input type="hidden" name="request_id" id="cancel_request_id">
</form>

<script>
var barangData = <?= json_encode($barang_list) ?>;
var stockData = <?= json_encode($stock_available) ?>;
var stockDataLoaded = false;
var availableItems = [];
var destVersion = 0;
var currentDestKey = '';
var stockDataDestKey = '';

function fmtQty(v) {
    var n = Number(v || 0);
    if (!isFinite(n)) n = 0;
    if (Math.abs(n - Math.round(n)) < 0.00005) return String(Math.round(n));
    var s = n.toFixed(4);
    s = s.replace(/0+$/,'').replace(/\.$/,'');
    return s === '' ? '0' : s;
}

document.addEventListener('DOMContentLoaded', function() {
    // Move modals to body
    var modalRequest = document.getElementById('modalRequest');
    var modalView = document.getElementById('modalView');
    if (modalRequest) document.body.appendChild(modalRequest);
    if (modalView) document.body.appendChild(modalView);

    loadAvailableItems().then(function() {
        addRow();
    });
});

$(document).on('change', '#tujuanRequest', function() {
    applyTujuanValue(this.value);
    $('#reqBody .stock-info').each(function() {
        $(this).text('-').removeClass('bg-success bg-danger').addClass('bg-secondary');
    });
    loadAvailableItems().then(function() {
        rebuildAllRowOptions();
        updateAllRowStocks();
    });
});

$(document).on('shown.bs.modal', '#modalRequest', function() {
    var tujuanEl = document.getElementById('tujuanRequest');
    if (!tujuanEl) return;
    applyTujuanValue(tujuanEl.value);
    loadAvailableItems().then(function() {
        rebuildAllRowOptions();
        updateAllRowStocks();
    });
});

function applyTujuanValue(v) {
    var parts = String(v || '').split(':');
    var lvl = parts[0] || 'gudang_utama';
    var id = parts[1] || '0';
    var keLevelEl = document.getElementById('ke_level');
    var keIdEl = document.getElementById('ke_id');
    if (keLevelEl) keLevelEl.value = lvl;
    if (keIdEl) keIdEl.value = id;
    currentDestKey = lvl + ':' + id;
}

function loadAvailableItems() {
    var myVersion = ++destVersion;
    stockDataLoaded = false;
    stockData = {};
    stockDataDestKey = '';
    var keLevelEl = document.getElementById('ke_level');
    var keIdEl = document.getElementById('ke_id');
    var tujuanEl = document.getElementById('tujuanRequest');
    if (tujuanEl && tujuanEl.value) {
        applyTujuanValue(tujuanEl.value);
    }
    var keLevel = keLevelEl ? keLevelEl.value : 'gudang_utama';
    var keId = keIdEl ? keIdEl.value : '0';
    var destKey = keLevel + ':' + keId;
    stockDataDestKey = destKey;
    return $.ajax({
        url: 'api/ajax_request_items.php',
        method: 'POST',
        dataType: 'json',
        data: { ke_level: keLevel, ke_id: keId }
    }).then(function(res) {
        if (myVersion !== destVersion || destKey !== currentDestKey) return;
        availableItems = [];
        stockData = {};
        window.__stockUom = window.__stockUom || {};
        if (res && res.success && Array.isArray(res.items)) {
            availableItems = res.items;
            availableItems.forEach(function(it) {
                if (it && it.barang_id) stockData[it.barang_id] = parseFloat(it.qty || 0);
                if (it && it.barang_id) window.__stockUom[it.barang_id] = it.satuan || '';
            });
        }
        window.__requestStockLocationCode = (res && res.location_code) ? res.location_code : '';
        stockDataLoaded = true;
    }).catch(function() {
        if (myVersion !== destVersion || destKey !== currentDestKey) return;
        stockData = {};
        availableItems = [];
        window.__requestStockLocationCode = '';
        stockDataLoaded = true;
    });
}

function buildOptionsHtml(selectedId) {
    var options = '<option value="">- Pilih Barang -</option>';
    if (stockDataLoaded && availableItems.length > 0) {
        availableItems.forEach(function(it) {
            var sel = (selectedId && it.barang_id == selectedId) ? 'selected' : '';
            var labelCode = (it.kode_barang && String(it.kode_barang).trim() !== '') ? it.kode_barang : it.odoo_product_id;
            options += '<option value="' + it.barang_id + '" ' + sel + '>' + labelCode + ' - ' + it.nama_barang + ' (' + it.satuan + ')</option>';
        });
    } else {
        barangData.forEach(function(b) {
            var sel = (selectedId && b.id == selectedId) ? 'selected' : '';
            var labelCode = (b.kode_barang && String(b.kode_barang).trim() !== '') ? b.kode_barang : b.odoo_product_id;
            options += '<option value="' + b.id + '" ' + sel + '>' + labelCode + ' - ' + b.nama_barang + ' (' + b.satuan + ')</option>';
        });
    }
    return options;
}

function addRow(selectedId = null) {
    var options = buildOptionsHtml(selectedId);

    var row = `<tr>
        <td>
            <select name="items[]" class="form-select req-item" onchange="updateStock(this)" required>
                ${options}
            </select>
        </td>
        <td>
            <span class="stock-info badge bg-secondary">-</span>
        </td>
        <td><input type="number" name="qtys[]" class="form-control" min="1" value="1" required></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#reqBody').append(row);
    
    // Init Select2
    var lastRow = $('#reqBody tr:last');
    lastRow.find('.req-item').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#modalRequest')
    });
    
    // Trigger update if selected
    if (selectedId) {
        updateStock(lastRow.find('.req-item')[0]);
    }
}

function rebuildAllRowOptions() {
    $('#reqBody tr').each(function() {
        var selEl = $(this).find('.req-item');
        if (selEl.length === 0) return;
        var current = selEl.val();
        var hadSelect2 = (selEl.data('select2') !== undefined);
        if (hadSelect2) {
            selEl.select2('destroy');
        }
        selEl.html(buildOptionsHtml(current));
        selEl.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#modalRequest')
        });
        if (current && selEl.find('option[value="' + current + '"]').length > 0) {
            selEl.val(current).trigger('change');
        } else {
            selEl.val('').trigger('change');
        }
    });
}

function updateStock(select) {
    var id = $(select).val();
    var row = $(select).closest('tr');
    var stockSpan = row.find('.stock-info');
    
    if (!id) {
        stockSpan.text('-');
        stockSpan.removeClass('bg-success bg-danger').addClass('bg-secondary');
        return;
    }
    if (stockDataDestKey === currentDestKey && stockDataLoaded && stockData[id] !== undefined) {
        var qtyCached = parseFloat(stockData[id]) || 0;
        var uomCached = (window.__stockUom && window.__stockUom[id]) ? window.__stockUom[id] : '';
        if (qtyCached > 0) {
            stockSpan.text(fmtQty(qtyCached) + (uomCached ? (' ' + uomCached) : '') + ' Ready');
            stockSpan.removeClass('bg-secondary bg-danger bg-success').addClass('bg-success');
            return;
        }
    }

    stockSpan.text('...');
    stockSpan.removeClass('bg-success bg-danger').addClass('bg-secondary');

    var keLevelEl = document.getElementById('ke_level');
    var keIdEl = document.getElementById('ke_id');
    var tujuanEl = document.getElementById('tujuanRequest');
    if (tujuanEl && tujuanEl.value) {
        applyTujuanValue(tujuanEl.value);
    }
    var keLevel = keLevelEl ? keLevelEl.value : 'gudang_utama';
    var keId = keIdEl ? keIdEl.value : '0';
    var destKey = keLevel + ':' + keId;
    var myVersion = destVersion;

    $.ajax({
        url: 'api/ajax_request_stock_qty.php',
        method: 'POST',
        dataType: 'json',
        data: { ke_level: keLevel, ke_id: keId, barang_id: id }
    }).then(function(res) {
        if (myVersion !== destVersion || destKey !== currentDestKey) return;
        if ($(select).val() != id) return;
        if (res && res.success) {
            var q = parseFloat(res.qty || 0);
            stockData[id] = q;
            stockDataLoaded = true;
            window.__requestStockLocationCode = res.location_code ? res.location_code : window.__requestStockLocationCode;
            window.__stockUom = window.__stockUom || {};
            window.__stockUom[id] = res.satuan || window.__stockUom[id] || '';
            var uom = window.__stockUom[id] || '';
            stockSpan.text(fmtQty(q) + (uom ? (' ' + uom) : '') + ' Ready');
            stockSpan.removeClass('bg-secondary bg-danger bg-success').addClass(q > 0 ? 'bg-success' : 'bg-danger');
        } else {
            stockData[id] = 0;
            stockDataLoaded = true;
            stockSpan.text('0 Ready');
            stockSpan.removeClass('bg-secondary bg-success').addClass('bg-danger');
        }
    }).catch(function() {
        if (myVersion !== destVersion || destKey !== currentDestKey) return;
        if ($(select).val() != id) return;
        stockData[id] = 0;
        stockDataLoaded = true;
        stockSpan.text('0 Ready');
        stockSpan.removeClass('bg-secondary bg-success').addClass('bg-danger');
    });
}

function updateAllRowStocks() {
    $('#reqBody tr').each(function() {
        var sel = $(this).find('.req-item')[0];
        if (sel) updateStock(sel);
    });
}

function validateStock() {
    var keLevelEl = document.getElementById('ke_level');
    var keIdEl = document.getElementById('ke_id');
    if (keLevelEl && keIdEl && keLevelEl.value === 'klinik' && (!keIdEl.value || keIdEl.value === '0')) {
        alert('Silakan pilih klinik tujuan terlebih dahulu.');
        return false;
    }
    if (!stockDataLoaded) {
        alert('Ketersediaan belum dimuat. Silakan coba kembali.');
        return false;
    }
    var valid = true;
    $('#reqBody tr').each(function() {
        var id = $(this).find('.req-item').val();
        var qtyReq = parseFloat($(this).find('input[name="qtys[]"]').val()) || 0;
        
        if (id) {
            var avail = (stockData[id] !== undefined) ? parseFloat(stockData[id]) : 0;
            if (qtyReq > avail) {
                alert('Ketersediaan stok tidak mencukupi untuk salah satu item.');
                valid = false;
                return false;
            }
        }
    });
    return valid;
}

function removeRow(btn) {
    if ($('#reqBody tr').length > 1) {
        $(btn).closest('tr').remove();
    }
}

function viewRequest(id) {
    var modalEl = document.getElementById('modalView');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    
    $('#view_content').html('<div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div><br>Loading details...</div>');
    $('#view_footer').html('<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
    
    fetch('api/get_request_details.php?id=' + id)
        .then(response => response.text())
        .then(data => {
            $('#view_content').html(data);
            $('#view_request_id').val(id);
            
            if (data.includes('data-pending="true"')) {
                 $('#form_action').val('approve_request');
                 $('#view_footer').html(`
                    <button type="button" class="btn btn-danger me-auto" onclick="submitApproval('reject')">Tolak</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary-custom" onclick="submitApproval('approve')">Setujui</button>
                 `);
            } else if (data.includes('data-process="true"')) {
                 $('#form_action').val('process_request');
                 $('#status_action').val('');
                 $('#view_footer').html(`
                    <a href="scripts/print_request.php?id=${id}" target="_blank" class="btn btn-info text-white me-auto">
                        <i class="fas fa-print me-1"></i> Cetak Dokumen
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="fas fa-upload me-1"></i> Unggah Dokumen & Proses
                    </button>
                 `);
            } else {
                 // For non-pending requests, add Print button
                 $('#form_action').val('');
                 $('#view_footer').html(`
                    <a href="scripts/print_request.php?id=${id}" target="_blank" class="btn btn-info text-white me-auto">
                        <i class="fas fa-print me-1"></i> Cetak Dokumen
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                 `);
            }
        });
}

function submitApproval(action) {
    if (action == 'reject') {
        if(!confirm('Yakin ingin menolak request ini?')) return;
        $('#status_action').val('reject');
    } else {
        $('#status_action').val('approve_all');
    }
    $('#approvalForm').submit();
}

function cancelRequest(id) {
    if(confirm('Yakin ingin membatalkan request ini?')) {
        $('#cancel_request_id').val(id);
        $('#cancelForm').submit();
    }
}
</script>
