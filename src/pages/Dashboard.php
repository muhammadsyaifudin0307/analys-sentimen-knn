<?php
// Inisialisasi data default
$sentimentData = [
    'positive' => 0,
    'negative' => 0,
    'total' => 0,
];

// Jalankan query hanya jika koneksi berhasil
if (!isset($error_message)) {
    $query = "SELECT sentiment, COUNT(*) as jumlah 
            FROM dataset 
            WHERE sentiment IN ('positive', 'negative') 
            GROUP BY sentiment";

    $result = $conn->query($query);

    // Cek apakah query berhasil
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $key = strtolower($row['sentiment']);
            $sentimentData[$key] = (int)$row['jumlah'];
            $sentimentData['total'] += (int)$row['jumlah'];
        }
    } else {
        $error_message = "Data tidak ditemukan atau query gagal.";
    }

    $conn->close();
}

// Hitung persentase untuk chart
$positivePercentage = $sentimentData['total'] > 0 ? round(($sentimentData['positive'] / $sentimentData['total']) * 100, 2) : 0;
$negativePercentage = $sentimentData['total'] > 0 ? round(($sentimentData['negative'] / $sentimentData['total']) * 100, 2) : 0;
?>

<!-- Mulai HTML -->
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Analisis Sentimen - Kebijakan PPN 12%</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="container-fluid p-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 text-dark fw-bold">
                <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard Analisis Sentimen PPN 12%
            </h1>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Peringatan:</strong> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-lg-8 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up-arrow me-2"></i>Analisis Sentimen Kebijakan Kenaikan PPN 12%
                        </h5>
                    </div>
                    <div class="card-body">
                        <h6 class="text-primary mb-3">
                            <i class="bi bi-info-circle me-1"></i>Ringkasan Analisis
                        </h6>
                        <p p class="text-muted mb-3">
                            Analisis sentimen terkait kebijakan <strong>kenaikan Pajak Pertambahan Nilai (PPN) dari 11% menjadi 12%</strong>
                            menggunakan metode <strong>K-NEAREST NEIGHBOR (KNN)</strong> untuk mengklasifikasikan
                            opini publik terhadap kebijakan ekonomi pemerintah ini.
                        </p>
                        </p>
                        <h6 class="text-primary mb-2">
                            <i class="bi bi-gear me-1"></i>Metodologi
                        </h6>
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle text-success me-2"></i><strong>Algoritma:</strong> KNN</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i><strong>Sumber:</strong> Twitter/X</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i><strong>Klasifikasi:</strong> Positif / Negatif</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i><strong>Total Dataset:</strong> <?php echo number_format($sentimentData['total']); ?> tweet</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-pie-chart-fill me-2"></i>Distribusi Sentimen</h5>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width: 100%; max-width: 300px;">
                            <canvas id="pieChart"></canvas>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center">
                                    <div class="bg-success rounded-circle me-2" style="width: 12px; height: 12px;"></div>
                                    <small class="text-muted">Positif: <?php echo $sentimentData['positive']; ?></small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center">
                                    <div class="bg-danger rounded-circle me-2" style="width: 12px; height: 12px;"></div>
                                    <small class="text-muted">Negatif: <?php echo $sentimentData['negative']; ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Chart.js Pie Chart -->
    <script>
        const sentimentData = {
            positive: <?php echo $sentimentData['positive']; ?>,
            negative: <?php echo $sentimentData['negative']; ?>,
            total: <?php echo $sentimentData['total']; ?>,
            positivePercentage: <?php echo $positivePercentage; ?>,
            negativePercentage: <?php echo $negativePercentage; ?>
        };

        const ctx = document.getElementById('pieChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Sentimen Positif', 'Sentimen Negatif'],
                datasets: [{
                    data: [sentimentData.positive, sentimentData.negative],
                    backgroundColor: ['#28a745', '#dc3545'],
                    borderColor: ['#218838', '#c82333'],
                    borderWidth: 3,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 12
                            },
                            generateLabels: function(chart) {
                                return chart.data.labels.map((label, i) => {
                                    const val = chart.data.datasets[0].data[i];
                                    const percent = i === 0 ? sentimentData.positivePercentage : sentimentData.negativePercentage;
                                    return {
                                        text: `${label}: ${val} tweet (${percent}%)`,
                                        fillStyle: chart.data.datasets[0].backgroundColor[i],
                                        strokeStyle: chart.data.datasets[0].borderColor[i],
                                        lineWidth: chart.data.datasets[0].borderWidth,
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = context.raw;
                                const percent = context.dataIndex === 0 ? sentimentData.positivePercentage : sentimentData.negativePercentage;
                                return `${context.label}: ${val} tweet (${percent}%)`;
                            }
                        }
                    }
                },
                cutout: '50%'
            }
        });
    </script>
</body>

</html>