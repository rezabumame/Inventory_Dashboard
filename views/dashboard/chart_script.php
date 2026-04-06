
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('dashboardChart').getContext('2d');

    <?php
    // Prepare Data based on Role
    $labels = [];
    $data = [];
    $label = "Booking Harian (Semua Klinik)";
    $chartType = 'line';

    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('d M', strtotime($d));
        $q = $conn->query("SELECT COUNT(*) as cnt FROM inventory_booking_pemeriksaan WHERE tanggal_pemeriksaan = '$d' AND status != 'cancelled'");
        $cnt = 0;
        if ($q && $q->num_rows > 0) $cnt = (int)$q->fetch_assoc()['cnt'];
        $data[] = $cnt;
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
