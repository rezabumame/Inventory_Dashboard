<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_csrf();

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File tidak terupload dengan benar.']);
    exit;
}

$xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
if (!$xlsx) {
    echo json_encode(['success' => false, 'message' => SimpleXLSX::parseError()]);
    exit;
}

// Function to clean and trim strings including hidden characters
function clean_string($str) {
    if ($str === null) return '';
    // Remove non-printable characters and trim
    $str = preg_replace('/[\x00-\x1F\x7F-\x9F\xAD]/u', '', (string)$str);
    return trim($str);
}

$rows = $xlsx->rows();
if (count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'File Excel kosong atau tidak sesuai format.']);
    exit;
}

$valid_mappings = [];
$all_invalid_rows = [];
$invalid_summary = []; // Key: error_type|barang_id|uom_excel
$delete_all = isset($_POST['delete_all']) && $_POST['delete_all'] == '1';
$total_excel_rows = 0;

// Cache barangs for faster lookup
$barangs_cache = [];
$barangs_by_code = [];
$barangs_by_odoo = [];
$res_b = $conn->query("
    SELECT 
        b.id, 
        b.kode_barang, 
        b.odoo_product_id, 
        b.nama_barang, 
        COALESCE(NULLIF(uc.to_uom, ''), b.satuan) AS satuan
    FROM inventory_barang b
    LEFT JOIN inventory_barang_uom_conversion uc ON uc.kode_barang = b.kode_barang
");
while($b = $res_b->fetch_assoc()) {
    $barangs_cache[$b['id']] = $b;
    if ($b['kode_barang'] !== null && $b['kode_barang'] !== '') {
        $barangs_by_code[clean_string($b['kode_barang'])] = $b;
    }
    if ($b['odoo_product_id'] !== null && $b['odoo_product_id'] !== '') {
        $barangs_by_odoo[clean_string($b['odoo_product_id'])] = $b;
    }
}

// Skip header row
for ($i = 1; $i < count($rows); $i++) {
    $id_paket = clean_string($rows[$i][0] ?? '');
    $nama_pemeriksaan = clean_string($rows[$i][1] ?? '');
    $id_biosys = clean_string($rows[$i][2] ?? '');
    $layanan = clean_string($rows[$i][3] ?? '');
    
    $raw_barang_id = clean_string($rows[$i][4] ?? '');
    $consumables = clean_string($rows[$i][5] ?? '');
    $qty = (float)($rows[$i][6] ?? 0);
    $uom_excel = clean_string($rows[$i][7] ?? '');
    
    if ($id_paket === '' || $nama_pemeriksaan === '') continue;
    
    $total_excel_rows++;

    $row_data = [
        'id_paket' => $id_paket,
        'nama_paket' => $nama_pemeriksaan,
        'id_biosys' => $id_biosys,
        'layanan' => $layanan,
        'barang_id' => $raw_barang_id, // Default to what's in excel for now
        'consumables' => $consumables,
        'qty' => $qty,
        'uom_excel' => $uom_excel,
        'system_uom' => null
    ];

    if ($raw_barang_id !== '' && $qty > 0) {
        // Try to find item by Code or Odoo ID (DO NOT use internal database ID)
        $b = null;
        if (isset($barangs_by_code[$raw_barang_id])) {
            $b = $barangs_by_code[$raw_barang_id];
        } elseif (isset($barangs_by_odoo[$raw_barang_id])) {
            $b = $barangs_by_odoo[$raw_barang_id];
        }

        if (!$b) {
            $error_type = 'item_not_found';
            $error_msg = "ID Barang $raw_barang_id tidak ditemukan";
            
            $summary_key = $error_type . '|' . $raw_barang_id;
            if (!isset($invalid_summary[$summary_key])) {
                $invalid_summary[$summary_key] = [
                    'error_type' => $error_type,
                    'barang_id' => $raw_barang_id,
                    'consumables' => $consumables,
                    'uom_excel' => $uom_excel,
                    'error' => $error_msg,
                    'count' => 0
                ];
            }
            $invalid_summary[$summary_key]['count']++;
            $row_data['summary_key'] = $summary_key;
            $all_invalid_rows[] = $row_data;

        } else {
            $row_data['barang_id'] = $b['id']; // Use actual ID
            $row_data['system_uom'] = $b['satuan'];
            
            if (strtolower($uom_excel) !== strtolower($b['satuan'])) {
                $error_type = 'uom_mismatch';
                $error_msg = "UoM berbeda (Excel: $uom_excel, Sistem: {$b['satuan']})";
                
                $summary_key = $error_type . '|' . $b['id'] . '|' . strtolower($uom_excel);
                if (!isset($invalid_summary[$summary_key])) {
                    $invalid_summary[$summary_key] = [
                        'error_type' => $error_type,
                        'barang_id' => $b['id'],
                        'consumables' => $consumables,
                        'uom_excel' => $uom_excel,
                        'system_uom' => $b['satuan'],
                        'error' => $error_msg,
                        'count' => 0
                    ];
                }
                $invalid_summary[$summary_key]['count']++;
                $row_data['summary_key'] = $summary_key;
                $all_invalid_rows[] = $row_data;

            } else {
                $valid_mappings[] = $row_data;
            }
        }
    } else {
        $valid_mappings[] = $row_data;
    }
}

echo json_encode([
    'success' => true,
    'needs_review' => !empty($invalid_summary),
    'total_excel_rows' => $total_excel_rows,
    'valid_count' => count($valid_mappings),
    'invalid_count' => count($all_invalid_rows),
    'invalid_summary' => array_values($invalid_summary),
    'all_invalid_rows' => $all_invalid_rows,
    'valid_data' => $valid_mappings,
    'delete_all' => $delete_all
]);
exit;


