<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-dark"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
        <div>
            <span class="badge bg-primary px-3 py-2">
                <i class="bi bi-calendar3 me-1"></i> <?php echo date('d M Y'); ?>
            </span>
        </div>
    </div>

    <!-- Sentiment Analysis Overview -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5><i class="bi bi-graph-up-arrow me-2"></i>Analisis Sentimen Kebijakan Kenaikan PPN 12%</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Analisis sentimen terkait kebijakan **kenaikan PPN 12 persen** menggunakan metode **SVM** menunjukkan bagaimana reaksi publik terhadap kebijakan tersebut. Berdasarkan data yang dikumpulkan, berikut adalah hasil analisis sentimen yang dibagi menjadi dua kategori utama: **positif** dan **negatif**.
                    </p>

                    <p>
                        Hasil analisis menunjukkan bahwa sebagian besar responden memberikan pendapat yang **positif** terhadap kebijakan ini, dengan 150 respon positif. Namun, terdapat juga sejumlah respon negatif yang mencapai 50. Visualisasi berikut ini menggambarkan **sebaran sentimen** yang terjadi terkait kebijakan ini.
                    </p>
                </div>
            </div>
        </div>

        <!-- Sidebar: Sentiment Pie Chart -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-pie-chart-fill me-1"></i> Sebaran Sentimen
                </div>
                <div class="card-body">
                    <!-- Pie Chart Placeholder -->
                    <canvas id="pieChart"></canvas> <!-- Chart.js Canvas for Pie Chart -->
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Pie Chart for Sentiment Proportion
    var pieCtx = document.getElementById('pieChart').getContext('2d');
    var pieChart = new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: ['Positif', 'Negatif'],
            datasets: [{
                data: [150, 50], // Data sentimen Positif dan Negatif
                backgroundColor: ['#28a745', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(tooltipItem) {
                            return tooltipItem.label + ': ' + tooltipItem.raw + ' Responden';
                        }
                    }
                }
            },
            animation: {
                animateScale: true, // Smooth scale animation for pie chart
                animateRotate: true // Smooth rotation animation for pie chart
            }
        }
    });
</script>

<style>
    :root {
        --primary-color: #4361ee;
        --secondary-color: #3f37c9;
        --accent-color: #4895ef;
        --light-color: #f8f9fa;
        --dark-color: #212529;
    }

    body {
        background-color: var(--light-color);
        color: var(--dark-color);
    }

    .card {
        border-radius: 12px;
    }

    .card-header {
        border-bottom: 2px solid var(--primary-color);
    }

    .card-body {
        background-color: var(--light-color);
        color: var(--dark-color);
    }

    .text-muted {
        color: var(--dark-color) !important;
    }

    .shadow-sm {
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .bg-primary {
        background-color: var(--primary-color) !important;
    }

    .bg-success {
        background-color: #28a745 !important;
    }

    .bg-danger {
        background-color: #dc3545 !important;
    }

    .bg-secondary {
        background-color: var(--secondary-color) !important;
    }

    .text-white {
        color: white;
    }

    .badge {
        font-weight: bold;
        border-radius: 10px;
    }

    canvas {
        max-width: 100%;
        height: auto;
    }
</style>