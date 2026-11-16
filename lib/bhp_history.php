<?php
// Audit log Pemakaian BHP: edit manual & hapus. Disimpan di tabel terpisah
// (inventory_pemakaian_bhp_history) karena edit/hapus menghapus baris inventory_transaksi_stok,
// jadi jejaknya tidak bisa direkonstruksi dari ledger stok.

function bhp_history_fmt_qty($v): string {
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
}

// Snapshot header + item (diagregasi per nama barang) sebuah record BHP.
function bhp_history_snapshot(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("
        SELECT pb.id, pb.nomor_pemakaian, pb.tanggal, pb.jenis_pemakaian, pb.catatan_transaksi,
               k.nama_klinik, u.nama_lengkap AS nama_hc
        FROM inventory_pemakaian_bhp pb
        LEFT JOIN inventory_klinik k ON k.id = pb.klinik_id
        LEFT JOIN inventory_users u ON u.id = pb.user_hc_id
        WHERE pb.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $h = $stmt->get_result()->fetch_assoc();
    if (!$h) return null;

    $stmt = $conn->prepare("
        SELECT d.barang_id, d.qty, d.satuan, b.nama_barang
        FROM inventory_pemakaian_bhp_detail d
        LEFT JOIN inventory_barang b ON b.id = d.barang_id
        WHERE d.pemakaian_bhp_id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $nama = $r['nama_barang'] ?: ('Item #' . $r['barang_id']);
        if (!isset($items[$nama])) $items[$nama] = ['qty' => 0.0, 'satuan' => (string)$r['satuan']];
        $items[$nama]['qty'] += (float)$r['qty'];
    }
    ksort($items);

    return [
        'nomor' => (string)$h['nomor_pemakaian'],
        'fields' => [
            'Tanggal' => $h['tanggal'] ? date('Y-m-d', strtotime($h['tanggal'])) : '',
            'Jenis' => $h['jenis_pemakaian'] === 'hc' ? 'HC' : 'Klinik',
            'Klinik' => (string)($h['nama_klinik'] ?? ''),
            'Petugas HC' => (string)($h['nama_hc'] ?? ''),
            'Catatan' => trim((string)($h['catatan_transaksi'] ?? '')),
        ],
        'items' => $items,
    ];
}

// Selisih dua snapshot -> ['fields' => [...], 'items' => [...]] dalam format yang dirender modal History.
function bhp_history_diff(array $before, array $after): array {
    $fields = [];
    foreach ($after['fields'] as $label => $new) {
        $old = $before['fields'][$label] ?? '';
        if ((string)$old !== (string)$new) {
            $fields[] = ['label' => $label, 'before' => $old !== '' ? $old : '-', 'after' => $new !== '' ? $new : '-'];
        }
    }

    $items = [];
    $names = array_unique(array_merge(array_keys($before['items']), array_keys($after['items'])));
    sort($names);
    foreach ($names as $nama) {
        $b = (float)($before['items'][$nama]['qty'] ?? 0);
        $a = (float)($after['items'][$nama]['qty'] ?? 0);
        $net = $a - $b;
        if (abs($net) < 0.000001) continue;
        $items[] = [
            'nama' => $nama,
            'label' => ($net > 0 ? '+' : '-') . bhp_history_fmt_qty(abs($net)),
            'before' => bhp_history_fmt_qty($b),
            'after' => bhp_history_fmt_qty($a),
        ];
    }
    return ['fields' => $fields, 'items' => $items];
}

// Daftar item snapshot sebagai baris "qty -> 0" (dipakai untuk log hapus).
function bhp_history_items_removed(array $snap): array {
    $out = [];
    foreach ($snap['items'] as $nama => $it) {
        $out[] = [
            'nama' => $nama,
            'label' => '-' . bhp_history_fmt_qty($it['qty']),
            'before' => bhp_history_fmt_qty($it['qty']),
            'after' => '0',
        ];
    }
    return $out;
}

// Gagal menulis log tidak boleh menggagalkan transaksi utamanya (mis. migrasi tabel belum jalan).
function bhp_history_log(mysqli $conn, int $bhp_id, string $nomor, string $action, string $notes, array $changes = []): void {
    try {
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        $user_name = (string)($_SESSION['nama_lengkap'] ?? ($_SESSION['username'] ?? 'Sistem'));
        $changes_json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        // Harus dipanggil SEBELUM record dihapus, supaya klinik_id masih bisa diambil.
        $stmt_k = $conn->prepare("SELECT klinik_id FROM inventory_pemakaian_bhp WHERE id = ?");
        $stmt_k->bind_param("i", $bhp_id);
        $stmt_k->execute();
        $klinik_id = (int)($stmt_k->get_result()->fetch_assoc()['klinik_id'] ?? 0);

        $stmt = $conn->prepare("
            INSERT INTO inventory_pemakaian_bhp_history
            (pemakaian_bhp_id, nomor_pemakaian, klinik_id, user_id, user_name, action, notes, changes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isiissss", $bhp_id, $nomor, $klinik_id, $user_id, $user_name, $action, $notes, $changes_json);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('bhp_history_log failed: ' . $e->getMessage());
    }
}
