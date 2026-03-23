
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('dashboardChart').getContext('2d');

    <?php
    // Prepare Data based on Role
    $labels = [];
    $data = [];
    $label = "Data";
    $chartType = 'bar'; // default

    if ($role == 'cs' || $role == 'admin_klinik') {
        // Booking stats for next 7 days
        $label = "Booking 7 Hari Ke Depan";
        $chartType = 'line';
        $klinik_id = $_SESSION['klinik_id'] ?? 0;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i days"));
            $labels[] = date('d M', strtotime($d));
            $q = $conn->query("SELECT COUNT(*) as cnt FROM booking_pemeriksaan WHERE klinik_id = $klinik_id AND tanggal_pemeriksaan = '$d' AND status != 'cancelled'");
            $data[] = $q->fetch_assoc()['cnt'];
        }
    } else {
        // Stock stats (Top 10 items by qty in Gudang Utama)
        $label = "Stok Terbanyak (Gudang Utama)";
        $chartType = 'bar';
        $q = $conn->query("SELECT b.nama_barang, s.qty FROM stok_gudang_utama s JOIN barang b ON s.barang_id = b.id ORDER BY s.qty DESC LIMIT 10");
        while($r = $q->fetch_assoc()) {
            $labels[] = $r['nama_barang'];
            $data[] = $r['qty'];
        }
    }
    ?>

    const chartData = {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: '<?= $label ?>',
            data: <?= json_encode($data) ?>,
            backgroundColor: '<?= $chartType ?>' === 'line' ? 'rgba(32, 78, 171, 0.08)' : '#204EAB',
            borderColor: '#204EAB',
            borderWidth: 2,
            borderRadius: 5,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#204EAB',
            pointHoverBackgroundColor: '#204EAB',
            pointHoverBorderColor: '#fff',
            fill: false
        }]
    };

    new Chart(ctx, {
        type: '<?= $chartType ?>',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            tension: 0.4,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#000',
                    bodyColor: '#000',
                    borderColor: '#ddd',
                    borderWidth: 1,
                    padding: 10,
                    displayColors: false,
                }
            },
            scales: {
                y: { 
                    beginAtZero: true,
                    grid: {
                        color: '#f0f0f0',
                        drawBorder: false
                    },
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
</script>
