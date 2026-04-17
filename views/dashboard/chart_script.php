<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php
    $role = (string)($_SESSION['role'] ?? '');
    $session_klinik_id = (int)($_SESSION['klinik_id'] ?? 0);
    $is_klinik_scope = in_array($role, ['admin_klinik', 'cs', 'spv_klinik'], true) && $session_klinik_id > 0;

    $trend_labels = [];
    $trend_onsite = [];
    $trend_hc = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend_labels[] = date('d M', strtotime($d));
        $onsite = 0.0;
        $hc = 0.0;

        if ($is_klinik_scope) {
            $stmt_o = $conn->prepare("SELECT COALESCE(SUM(ts.qty), 0) AS qty FROM inventory_transaksi_stok ts WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND ts.level = 'klinik' AND ts.level_id = ? AND DATE(ts.created_at) = ?");
            $stmt_o->bind_param("is", $session_klinik_id, $d);
            $stmt_o->execute();
            $onsite = (float)($stmt_o->get_result()->fetch_assoc()['qty'] ?? 0);

            $stmt_h = $conn->prepare("SELECT COALESCE(SUM(ts.qty), 0) AS qty FROM inventory_transaksi_stok ts JOIN inventory_users u ON ts.level = 'hc' AND ts.level_id = u.id WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND u.klinik_id = ? AND DATE(ts.created_at) = ?");
            $stmt_h->bind_param("is", $session_klinik_id, $d);
            $stmt_h->execute();
            $hc = (float)($stmt_h->get_result()->fetch_assoc()['qty'] ?? 0);
        } else {
            $q_o = $conn->query("SELECT COALESCE(SUM(ts.qty), 0) AS qty FROM inventory_transaksi_stok ts WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND ts.level = 'klinik' AND DATE(ts.created_at) = '$d'");
            if ($q_o && $q_o->num_rows > 0) $onsite = (float)($q_o->fetch_assoc()['qty'] ?? 0);

            $q_h = $conn->query("SELECT COALESCE(SUM(ts.qty), 0) AS qty FROM inventory_transaksi_stok ts WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND ts.level = 'hc' AND DATE(ts.created_at) = '$d'");
            if ($q_h && $q_h->num_rows > 0) $hc = (float)($q_h->fetch_assoc()['qty'] ?? 0);
        }

        $trend_onsite[] = round($onsite, 2);
        $trend_hc[] = round($hc, 2);
    }

    $top_labels = [];
    $top_qty = [];
    $date_from = date('Y-m-d', strtotime('-29 days'));
    if ($is_klinik_scope) {
        $stmt_top = $conn->prepare("SELECT b.nama_barang, COALESCE(SUM(ts.qty), 0) AS total_qty FROM inventory_transaksi_stok ts JOIN inventory_barang b ON b.id = ts.barang_id LEFT JOIN inventory_users u ON ts.level = 'hc' AND ts.level_id = u.id WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND DATE(ts.created_at) >= ? AND ((ts.level = 'klinik' AND ts.level_id = ?) OR (ts.level = 'hc' AND u.klinik_id = ?)) GROUP BY ts.barang_id, b.nama_barang ORDER BY total_qty DESC LIMIT 10");
        $stmt_top->bind_param("sii", $date_from, $session_klinik_id, $session_klinik_id);
        $stmt_top->execute();
        $res_top = $stmt_top->get_result();
    } else {
        $res_top = $conn->query("SELECT b.nama_barang, COALESCE(SUM(ts.qty), 0) AS total_qty FROM inventory_transaksi_stok ts JOIN inventory_barang b ON b.id = ts.barang_id WHERE ts.referensi_tipe = 'pemakaian_bhp' AND ts.tipe_transaksi = 'out' AND DATE(ts.created_at) >= '$date_from' GROUP BY ts.barang_id, b.nama_barang ORDER BY total_qty DESC LIMIT 10");
    }
    while ($res_top && ($row = $res_top->fetch_assoc())) {
        $nm = (string)($row['nama_barang'] ?? '-');
        if (mb_strlen($nm) > 28) $nm = mb_substr($nm, 0, 28) . '...';
        $top_labels[] = $nm;
        $top_qty[] = round((float)($row['total_qty'] ?? 0), 2);
    }
    ?>

    const trendCtxEl = document.getElementById('bhpTrendChart');
    if (trendCtxEl) {
        new Chart(trendCtxEl.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_labels) ?>,
                datasets: [
                    { label: 'Onsite', data: <?= json_encode($trend_onsite) ?>, borderColor: '#0EA5E9', backgroundColor: 'rgba(14, 165, 233, 0.14)', borderWidth: 2, tension: 0.35, fill: false, pointRadius: 3 },
                    { label: 'HC', data: <?= json_encode($trend_hc) ?>, borderColor: '#F59E0B', backgroundColor: 'rgba(245, 158, 11, 0.14)', borderWidth: 2, tension: 0.35, fill: false, pointRadius: 3 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0', drawBorder: false } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    const topCtxEl = document.getElementById('topItemsChart');
    if (topCtxEl) {
        new Chart(topCtxEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($top_labels) ?>,
                datasets: [{ label: 'Qty Keluar', data: <?= json_encode($top_qty) ?>, backgroundColor: '#204EAB', borderRadius: 6, maxBarThickness: 24 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f0f0f0', drawBorder: false } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
});
</script>
