<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/webhooks.php';

header('Content-Type: application/json');

// 1. Get JSON Input
$input = file_get_contents('php://input');
$json = json_decode($input, true);

if (!$json) {
    echo json_encode(['success' => false, 'message' => 'Format JSON tidak valid']);
    exit;
}

// 2. Validate API Key
$key = $json['key'] ?? '';
if ($key !== GS_SYNC_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Kunci API tidak valid atau tidak diizinkan']);
    exit;
}

$data = $json['data'] ?? [];
if (empty($data)) {
    echo json_encode(['success' => true, 'message' => 'Tidak ada data untuk disinkronkan']);
    exit;
}

try {
    // 1. Cache current barangs and their names
    $barangs_by_code = [];
    $barangs_by_odoo = [];
    $barang_names = [];
    $res_b = $conn->query("SELECT id, kode_barang, odoo_product_id, nama_barang FROM inventory_barang");
    while($b = $res_b->fetch_assoc()) {
        $bid = (int)$b['id'];
        $barang_names[$bid] = $b['nama_barang'];
        if ($b['kode_barang']) $barangs_by_code[trim(strtolower($b['kode_barang']))] = $bid;
        if ($b['odoo_product_id']) $barangs_by_odoo[trim(strtolower($b['odoo_product_id']))] = $bid;
    }

    // 2. Cache current exams state for "Dirty Check"
    $existing_state = [];
    $res_e = $conn->query("SELECT id, nama_pemeriksaan FROM inventory_pemeriksaan_grup");
    while($e = $res_e->fetch_assoc()) {
        $existing_state[$e['id']] = [
            'nama' => $e['nama_pemeriksaan'],
            'details' => [] // Keyed by "id_biosys|barang_id"
        ];
    }
    
    $res_d = $conn->query("SELECT pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, qty_per_pemeriksaan 
                           FROM inventory_pemeriksaan_grup_detail");
    while($d = $res_d->fetch_assoc()) {
        $gid = $d['pemeriksaan_grup_id'];
        if (isset($existing_state[$gid])) {
            $key = $d['id_biosys'] . '|' . $d['barang_id'];
            $existing_state[$gid]['details'][$key] = [
                'layanan' => $d['nama_layanan'],
                'qty' => (float)$d['qty_per_pemeriksaan'],
                'name' => $barang_names[$d['barang_id']] ?? ('ID: ' . $d['barang_id'])
            ];
        }
    }

    // 3. Group Incoming Data
    $grouped_data = [];
    foreach ($data as $r) {
        $id_paket = trim((string)($r['id_paket'] ?? ''));
        if ($id_paket === '') continue;
        
        if (!isset($grouped_data[$id_paket])) {
            $grouped_data[$id_paket] = [
                'nama' => trim((string)($r['nama_paket'] ?? '')),
                'details' => [],
                'missing' => []
            ];
        }
        
        $raw_barang_id = trim((string)($r['kode_odoo'] ?? ''));
        $lookup_key = strtolower($raw_barang_id);
        $bid = 0;
        if (isset($barangs_by_code[$lookup_key])) $bid = $barangs_by_code[$lookup_key];
        elseif (isset($barangs_by_odoo[$lookup_key])) $bid = $barangs_by_odoo[$lookup_key];
        
        $qty = (float)($r['qty'] ?? 0);
        $id_biosys = trim((string)($r['id_biosys'] ?? ''));
        
        if ($bid > 0 && $qty > 0) {
            $key = $id_biosys . '|' . $bid;
            $grouped_data[$id_paket]['details'][$key] = [
                'layanan' => trim((string)($r['layanan'] ?? '')),
                'qty' => $qty,
                'name' => $barang_names[$bid] ?? $raw_barang_id,
                'bid' => $bid,
                'id_biosys' => $id_biosys
            ];
        } else if ($raw_barang_id !== '' && !isset($barangs_by_code[$lookup_key]) && !isset($barangs_by_odoo[$lookup_key])) {
            $grouped_data[$id_paket]['missing'][] = $raw_barang_id;
        }
    }

    $conn->begin_transaction();
    
    $new_exam_names = [];
    $updated_exams = []; // Now stores diff details
    $missing_items = [];
    
    foreach ($grouped_data as $id_paket => $p) {
        $is_new = !isset($existing_state[$id_paket]);
        
        $diff = [
            'added' => [],
            'removed' => [],
            'changed' => []
        ];
        
        if ($is_new) {
            $is_changed = true;
        } else {
            $old = $existing_state[$id_paket];
            $is_changed = ($old['nama'] !== $p['nama']);
            
            // Check for added or changed items
            foreach ($p['details'] as $key => $new_det) {
                if (!isset($old['details'][$key])) {
                    $diff['added'][] = $new_det['name'];
                    $is_changed = true;
                } else if ($old['details'][$key]['qty'] != $new_det['qty']) {
                    $diff['changed'][] = $new_det['name'] . " ({$old['details'][$key]['qty']} ➔ {$new_det['qty']})";
                    $is_changed = true;
                }
            }
            // Check for removed items
            foreach ($old['details'] as $key => $old_det) {
                if (!isset($p['details'][$key])) {
                    $diff['removed'][] = $old_det['name'];
                    $is_changed = true;
                }
            }
        }
        
        if ($is_changed) {
            if ($is_new) {
                $stmt_ins = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup (id, nama_pemeriksaan) VALUES (?, ?)");
                $stmt_ins->bind_param("ss", $id_paket, $p['nama']);
                $stmt_ins->execute();
                $new_exam_names[] = $p['nama'];
            } else {
                $stmt_upd = $conn->prepare("UPDATE inventory_pemeriksaan_grup SET nama_pemeriksaan = ? WHERE id = ?");
                $stmt_upd->bind_param("ss", $p['nama'], $id_paket);
                $stmt_upd->execute();
                $updated_exams[$p['nama']] = $diff;
                
                $stmt_del = $conn->prepare("DELETE FROM inventory_pemeriksaan_grup_detail WHERE pemeriksaan_grup_id = ?");
                $stmt_del->bind_param("s", $id_paket);
                $stmt_del->execute();
            }
            
            // Insert Details
            if (!empty($p['details'])) {
                $stmt_map = $conn->prepare("INSERT INTO inventory_pemeriksaan_grup_detail (pemeriksaan_grup_id, id_biosys, nama_layanan, barang_id, qty_per_pemeriksaan) VALUES (?, ?, ?, ?, ?)");
                foreach ($p['details'] as $det) {
                    $stmt_map->bind_param("sssid", $id_paket, $det['id_biosys'], $det['layanan'], $det['bid'], $det['qty']);
                    $stmt_map->execute();
                }
            }
        }
        
        if (!empty($p['missing'])) {
            foreach ($p['missing'] as $m) {
                if (!in_array($m, $missing_items)) $missing_items[] = $m;
            }
        }
    }

    $conn->commit();
    
    $summary = [
        'new_exams' => $new_exam_names,
        'updated_exams' => $updated_exams,
        'missing_items' => $missing_items
    ];
    
    if (!empty($new_exam_names) || !empty($updated_exams) || !empty($missing_items)) {
        notify_lark_inventory_sync($summary);
    }

    echo json_encode([
        'success' => true, 
        'message' => "Sinkronisasi Selesai",
        'summary' => [
            'paket_baru' => count($new_exam_names),
            'paket_diupdate' => count($updated_exams),
            'item_tidak_ditemukan' => count($missing_items)
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
