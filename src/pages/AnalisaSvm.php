<?php

$datasetCounts = getDatasetCounts($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Analysis Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card-hover:hover {
            transform: scale(1.03);
            transition: transform 0.3s ease;
            cursor: pointer;
        }

        .nav-tabs .nav-link {
            font-weight: 500;
            color: #495057;
        }

        .nav-tabs .nav-link.active {
            color: #4e54c8;
            border-bottom: 3px solid #4e54c8;
            background-color: transparent;
        }

        .tab-content {
            padding: 20px;
            border-left: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            border-bottom: 1px solid #dee2e6;
            border-radius: 0 0 8px 8px;
        }

        .analysis-card {
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .chart-container {
            height: 300px;
            background-color: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <!-- Card Section -->
        <div class="row g-4 mb-4">
            <!-- Training Data Card -->
            <div class="col-md-6">
                <div class="card shadow-lg border-0 card-hover" style="background: linear-gradient(120deg, #4e54c8, #8f94fb); color: #f8f9fa; border-radius: 15px;">
                    <div class="card-body d-flex align-items-center py-4 px-5">
                        <div class="me-3">
                            <i class="bi bi-book-half fs-3" style="color: #f8f9fa;"></i>
                        </div>
                        <div class="text-start">
                            <h6 class="mb-1 fw-semibold">Jumlah Data Latih</h6>
                            <p class="fs-4 fw-bold mb-1">1,250</p>
                            <small>Digunakan untuk melatih model</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Data Card -->
            <div class="col-md-6">
                <div class="card shadow-lg border-0 card-hover" style="background: linear-gradient(120deg, #43e97b, #38f9d7); color: #212529; border-radius: 15px;">
                    <div class="card-body d-flex align-items-center py-4 px-5">
                        <div class="me-3">
                            <i class="bi bi-clipboard-data fs-3" style="color: #212529;"></i>
                        </div>
                        <div class="text-start">
                            <h6 class="mb-1 fw-semibold">Jumlah Data Uji</h6>
                            <p class="fs-4 fw-bold mb-1">450</p>
                            <small>Digunakan untuk menguji model</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Section -->
        <div class="row">
            <div class="col-12">
                <ul class="nav nav-tabs" id="analysisTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="preprocessing-tab" data-bs-toggle="tab" data-bs-target="#preprocessing" type="button" role="tab">Preprocessing</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tfidf-tab" data-bs-toggle="tab" data-bs-target="#tfidf" type="button" role="tab">TF-IDF</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="knn-tab" data-bs-toggle="tab" data-bs-target="#knn" type="button" role="tab">Analisis KNN</button>
                    </li>
                </ul>

                <div class="tab-content" id="analysisTabsContent">
                    <!-- Preprocessing Tab -->
                    <div class="tab-pane fade show active" id="preprocessing" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Hasil Preprocessing</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <p class="text-muted">Visualisasi distribusi teks setelah preprocessing</p>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Proses</th>
                                                        <th>Jumlah</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>Tokenisasi</td>
                                                        <td>12,450 kata</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Stopword Removal</td>
                                                        <td>3,210 kata dihapus</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Stemming</td>
                                                        <td>8,740 kata distem</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Contoh Data</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <h6>Sebelum Preprocessing:</h6>
                                            <p class="text-muted">"Produk ini sangat bagus dan berkualitas tinggi, saya sangat merekomendasikannya!"</p>
                                        </div>
                                        <div>
                                            <h6>Setelah Preprocessing:</h6>
                                            <p class="text-muted">"produk sangat bagus kualitas tinggi sangat rekomendasi"</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TF-IDF Tab -->
                    <div class="tab-pane fade" id="tfidf" role="tabpanel">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Matriks TF-IDF</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <p class="text-muted">Visualisasi matriks TF-IDF</p>
                                        </div>
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            TF-IDF (Term Frequency-Inverse Document Frequency) digunakan untuk mengukur pentingnya suatu kata dalam dokumen.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Kata Paling Signifikan</h5>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                produk
                                                <span class="badge bg-primary rounded-pill">0.87</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                kualitas
                                                <span class="badge bg-primary rounded-pill">0.82</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                harga
                                                <span class="badge bg-primary rounded-pill">0.78</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                pelayanan
                                                <span class="badge bg-primary rounded-pill">0.75</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                pengiriman
                                                <span class="badge bg-primary rounded-pill">0.70</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KNN Analysis Tab -->
                    <div class="tab-pane fade" id="knn" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Evaluasi Model KNN</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <p class="text-muted">Visualisasi akurasi model berdasarkan nilai K</p>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Nilai K</th>
                                                        <th>Akurasi</th>
                                                        <th>Presisi</th>
                                                        <th>Recall</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>3</td>
                                                        <td>85.2%</td>
                                                        <td>84.7%</td>
                                                        <td>85.6%</td>
                                                    </tr>
                                                    <tr>
                                                        <td>5</td>
                                                        <td>87.5%</td>
                                                        <td>87.2%</td>
                                                        <td>87.8%</td>
                                                    </tr>
                                                    <tr>
                                                        <td>7</td>
                                                        <td>86.3%</td>
                                                        <td>85.9%</td>
                                                        <td>86.7%</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card analysis-card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Confusion Matrix</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <p class="text-muted">Visualisasi confusion matrix untuk K=5</p>
                                        </div>
                                        <div class="alert alert-success">
                                            <i class="bi bi-check-circle me-2"></i>
                                            Model mencapai akurasi terbaik dengan K=5 (87.5%)
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>