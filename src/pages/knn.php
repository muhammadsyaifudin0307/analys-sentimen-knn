<?php

$datasetCounts = getDatasetCounts($conn);
$error_message = $prediction = $knn_results = $confusion_matrix = null;
$saved_count = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $k_value = intval($_POST['k_value']);
        if ($k_value <= 0) throw new Exception("Nilai K harus lebih besar dari 0");

        $sql = "SELECT d.id AS data_id, d.tweet, d.type, t.terms, t.tf, t.idf, t.tfidf, d.sentiment 
                FROM svm_analays.dataset d
                JOIN svm_analays.tfidf_results t ON d.id = t.data_id
                WHERE d.type IN ('data_latih', 'data_uji') ORDER BY d.type, d.id, t.terms";

        $result = $conn->query($sql);
        if (!$result || $result->num_rows == 0) throw new Exception("Tidak ada data yang ditemukan.");

        $aggregated_data = [];
        while ($row = $result->fetch_assoc()) {
            $data_id = $row['data_id'];
            if (!isset($aggregated_data[$data_id])) {
                $aggregated_data[$data_id] = [
                    'data_id' => $data_id,
                    'text' => $row['tweet'],
                    'type' => $row['type'],
                    'terms' => [],
                    'tf' => [],
                    'idf' => [],
                    'tfidf' => [],
                    'sentiment' => $row['sentiment']
                ];
            }

            $aggregated_data[$data_id]['terms'][] = $row['terms'];
            $aggregated_data[$data_id]['tf'][] = (float)$row['tf'];
            $aggregated_data[$data_id]['idf'][] = (float)$row['idf'];
            $aggregated_data[$data_id]['tfidf'][] = (float)$row['tfidf'];
        }

        $train_data = $test_data = $test_data_mapping = [];
        foreach ($aggregated_data as $data) {
            $tfidf_dict = array_combine($data['terms'], $data['tfidf']);
            $processed_data = ['data_id' => $data['data_id'], 'text' => $data['text'], 'tfidf' => $tfidf_dict, 'label' => $data['sentiment']];
            if ($data['type'] == 'data_latih') {
                $train_data[] = $processed_data;
            } elseif ($data['type'] == 'data_uji') {
                $test_data[] = $processed_data;
                $test_data_mapping[count($test_data) - 1] = $data['data_id'];
            }
        }

        if (empty($train_data) || empty($test_data)) throw new Exception("Tidak ada data latih atau uji ditemukan");
        if ($k_value > count($train_data)) throw new Exception("Nilai K tidak boleh lebih besar dari jumlah data latih");

        $data_json = json_encode(['train_data' => $train_data, 'test_data' => $test_data, 'k_value' => $k_value]);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Error encoding JSON");

        $ch = curl_init('http://127.0.0.1:5000/api/klasifikasi');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data_json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || !$response) throw new Exception("HTTP Error or no response from API");

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($result['error'])) throw new Exception("API Error");

        $knn_results = $result['knn_results'];
        $confusion_matrix = $result['confusion_matrix'] ?? null;

        $conn->begin_transaction();
        $conn->query("DELETE FROM knn_results WHERE k_value = $k_value");
        $conn->query("DELETE FROM confusion_matrix WHERE k_value = $k_value");
        $conn->query("DELETE FROM confusion_macro WHERE k_value = $k_value");

        $stmt = $conn->prepare("INSERT INTO knn_results (data_uji_id, k_value, actual_sentiment, predicted_sentiment, is_correct, distance_avg) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($knn_results['results'] as $index => $prediction_result) {
            $data_uji_id = $test_data_mapping[$index];
            $actual_sentiment = $test_data[$index]['label'];
            $predicted_sentiment = $prediction_result['predicted_label'];
            $is_correct = ($actual_sentiment === $predicted_sentiment) ? 1 : 0;
            $distance_avg = array_sum(array_column($prediction_result['k_nearest_neighbors'], 'distance')) / count($prediction_result['k_nearest_neighbors']);
            $stmt->bind_param("iissid", $data_uji_id, $k_value, $actual_sentiment, $predicted_sentiment, $is_correct, $distance_avg);
            $stmt->execute();
            $saved_count++;
        }

        if ($confusion_matrix && is_array($confusion_matrix)) {
            $stmt_cm = $conn->prepare("INSERT INTO confusion_matrix (k_value, actual_label, predicted_label, count, accuracy, `precision`, `recall`, f1_score, true_positive, true_negative, false_positive, false_negative, support, total_samples) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($confusion_matrix['detailed_confusion_matrix'] as $actual_label => $prediction_row) {
                foreach ($prediction_row as $predicted_label => $count) {
                    $m = $confusion_matrix['per_label_metrics'][$actual_label];
                    $stmt_cm->bind_param("issiddddiiiiii", $k_value, $actual_label, $predicted_label, $count, $confusion_matrix['macro_avg_metrics']['accuracy'], $m['precision'], $m['recall'], $m['f1_score'], $m['tp'], $m['tn'], $m['fp'], $m['fn'], $m['support'], $confusion_matrix['confusion_matrix_summary']['total_samples']);
                    $stmt_cm->execute();
                }
            }

            $stmt_macro = $conn->prepare("INSERT INTO confusion_macro (k_value, accuracy, macro_precision, macro_recall, macro_f1_score, total_samples) VALUES (?, ?, ?, ?, ?, ?)");
            $m = $confusion_matrix['macro_avg_metrics'];
            $stmt_macro->bind_param("iddddi", $k_value, $m['accuracy'], $m['precision'], $m['recall'], $m['f1_score'], $confusion_matrix['confusion_matrix_summary']['total_samples']);
            $stmt_macro->execute();
        }

        $conn->commit();
        $prediction = "Klasifikasi KNN berhasil dilakukan dengan K=" . $k_value;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
        error_log("KNN Classification Error: " . $error_message);
    }
}

// Helper functions to get confusion matrix and results
function getConfusionMatrixByK($conn, $k_value)
{
    $stmt = $conn->prepare("SELECT * FROM confusion_matrix WHERE k_value = ? ORDER BY actual_label, predicted_label");
    $stmt->bind_param("i", $k_value);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getConfusionMatrixSummaryByK($conn, $k_value)
{
    $stmt = $conn->prepare("SELECT * FROM confusion_macro WHERE k_value = ? LIMIT 1");
    $stmt->bind_param("i", $k_value);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getKNNResultsByK($conn, $k_value = null)
{
    $stmt = $conn->prepare($k_value ? "SELECT kr.*, d.tweet, d.type FROM knn_results kr JOIN dataset d ON kr.data_uji_id = d.id WHERE kr.k_value = ? ORDER BY kr.created_at DESC, kr.id" : "SELECT kr.*, d.tweet, d.type FROM knn_results kr JOIN dataset d ON kr.data_uji_id = d.id ORDER BY kr.created_at DESC, kr.k_value, kr.id LIMIT 50");
    if ($k_value) $stmt->bind_param("i", $k_value);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$current_confusion_matrix = isset($_POST['k_value']) ? getConfusionMatrixByK($conn, intval($_POST['k_value'])) : [];
$current_confusion_summary = isset($_POST['k_value']) ? getConfusionMatrixSummaryByK($conn, intval($_POST['k_value'])) : null;
$current_k_results = isset($_POST['k_value']) ? getKNNResultsByK($conn, intval($_POST['k_value'])) : [];

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Klasifikasi KNN</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background-color: #f8f9fa;
            }
            
            .main-container {
                margin: 0 auto;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .header h1 {
                font-size: 2rem;
                font-weight: 700;
                margin-bottom: 10px;
            }
            
            .header p {
                font-size: 1.1rem;
                opacity: 0.9;
                margin-bottom: 0;
            }
            
            .content {
                padding: 30px;
            }
            
            .stats-section {
                margin-bottom: 30px;
            }
            
            .stat-card {
                background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 25px;
                text-align: center;
                transition: transform 0.3s ease, box-shadow 0.3s ease;
                height: 100%;
            }
            
            .stat-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            }
            
            .stat-card .icon {
                font-size: 2.5rem;
                margin-bottom: 15px;
            }
            
            .stat-card.training .icon {
                color: #4e54c8;
            }
            
            .stat-card.testing .icon {
                color: #43e97b;
            }
            
            .stat-number {
                font-size: 2.5rem;
                font-weight: 700;
                color: #2c3e50;
                margin-bottom: 8px;
            }
            
            .stat-label {
                font-size: 1rem;
                color: #6c757d;
                font-weight: 500;
            }
            
            .form-section {
                background: #f8f9fa;
                padding: 25px;
                border-radius: 8px;
                margin-bottom: 30px;
                border: 1px solid #e9ecef;
            }
            
            .form-section h3 {
                font-size: 1.3rem;
                margin-bottom: 20px;
                color: #2c3e50;
                font-weight: 600;
            }
            
            .form-label {
                font-weight: 500;
                color: #495057;
                margin-bottom: 8px;
            }
            
            .form-control, .form-select {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 12px 15px;
                font-size: 0.95rem;
                transition: border-color 0.3s ease, box-shadow 0.3s ease;
            }
            
            .form-control:focus, .form-select:focus {
                border-color: #3498db;
                box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            }
            
            .btn-primary {
                background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                border: none;
                padding: 12px 30px;
                font-weight: 500;
                border-radius: 6px;
                transition: all 0.3s ease;
            }
            
            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
            }
            
            .results-section {
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 25px;
                margin-bottom: 30px;
            }
            
            .results-section h3 {
                font-size: 1.3rem;
                margin-bottom: 20px;
                color: #2c3e50;
                font-weight: 600;
            }
            
            .alert {
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 20px;
                border: none;
            }
            
            .alert-danger {
                background: linear-gradient(135deg, #ff7675 0%, #e17055 100%);
                color: white;
            }
            
            .alert-success {
                background: linear-gradient(135deg, #55a3ff 0%, #003d82 100%);
                color: white;
            }
            
            .table {
                margin-bottom: 0;
            }
            
            .table th {
                background: #f8f9fa;
                border-top: none;
                font-weight: 600;
                color: #495057;
                padding: 15px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .table td {
                padding: 15px;
                vertical-align: middle;
                border-bottom: 1px solid #f1f3f4;
            }
            
            .table-striped tbody tr:nth-of-type(odd) {
                background-color: #f8f9fa;
            }
            
            .badge {
                font-size: 0.85rem;
                padding: 8px 12px;
                border-radius: 6px;
                font-weight: 500;
            }
            
            .badge-success {
                background: linear-gradient(135deg, #00b894 0%, #00a085 100%);
            }
            
            .badge-danger {
                background: linear-gradient(135deg, #e17055 0%, #d63031 100%);
            }
            
            .badge-primary {
                background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%);
            }
            
            .badge-warning {
                background: linear-gradient(135deg, #fdcb6e 0%, #e17055 100%);
            }
            
            .knn-result-card {
                border: 1px solid #e9ecef;
                border-radius: 8px;
                margin-bottom: 20px;
                background: white;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                transition: all 0.3s ease;
                overflow: hidden;
            }
            
            .knn-result-card:hover {
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
            }
            
            .knn-result-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                cursor: pointer;
                transition: all 0.3s ease;
            }
            
            .knn-result-header:hover {
                background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            }
            
            .text-preview {
                background: rgba(255, 255, 255, 0.2);
                padding: 10px;
                border-radius: 6px;
                margin-top: 10px;
                border-left: 3px solid #ffeaa7;
                font-style: italic;
            }
            
            .collapse-content {
                padding: 25px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
            }
            
            .chevron-icon {
                transition: transform 0.3s ease;
            }
            
            .collapsed .chevron-icon {
                transform: rotate(-90deg);
            }
            
            .knn-info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 25px;
            }
            
            .neighbor-item {
                background: white;
                border: 1px solid #e9ecef;
                border-left: 4px solid #3498db;
                padding: 15px;
                margin-bottom: 10px;
                border-radius: 6px;
                transition: all 0.3s ease;
            }
            
            .neighbor-item:hover {
                border-left-color: #2980b9;
                transform: translateX(5px);
            }
            
            .distance-badge {
                background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                color: white;
                font-size: 0.75rem;
                font-family: 'Consolas', 'Monaco', monospace;
            }
            
            .comparison-section {
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 25px;
                margin-bottom: 30px;
            }
            
            .comparison-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 25px;
            }
            
            .comparison-stat {
                text-align: center;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 1px solid #e9ecef;
            }
            
            .comparison-stat h6 {
                color: #6c757d;
                margin-bottom: 10px;
                font-weight: 600;
            }
            
            .comparison-stat .badge {
                font-size: 1rem;
                padding: 8px 16px;
            }
            
            .confusion-matrix-section {
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 25px;
                margin-bottom: 30px;
            }
            
            .confusion-matrix-section h4 {
                color: #2c3e50;
                font-weight: 600;
                margin-bottom: 20px;
            }
            
            .table-dark {
                background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            }
            
            .table-secondary {
                background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
                color: white;
            }
            
            .result-table {
                max-height: 500px;
                overflow-y: auto;
                border: 1px solid #e9ecef;
                border-radius: 8px;
            }
            
            .action-buttons {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
            }
            
            .btn-outline-primary, .btn-outline-secondary {
                border-width: 2px;
                padding: 8px 16px;
                font-weight: 500;
                border-radius: 6px;
                transition: all 0.3s ease;
            }
            
            .btn-outline-primary:hover, .btn-outline-secondary:hover {
                transform: translateY(-2px);
            }
            
            .loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: none;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            }
            
            .loading-content {
                background: white;
                padding: 30px;
                border-radius: 8px;
                text-align: center;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            
            .spinner-border {
                width: 2rem;
                height: 2rem;
                margin-bottom: 15px;
            }
            
            @media (max-width: 768px) {
                .content {
                    padding: 20px;
                }
                
                .knn-info-grid {
                    grid-template-columns: 1fr;
                }
                
                .comparison-stats {
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                }
                
                .header {
                    padding: 20px;
                }
                
                .form-section {
                    padding: 20px;
                }
            }
        </style>
    </head>
    <body>
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-content">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h6 class="mb-2">Memproses Klasifikasi...</h6>
                <p class="text-muted mb-0">Menganalisis dengan algoritma KNN</p>
            </div>
        </div>

        <div class="main-container">
            <div class="header">
                <h1><i class="bi bi-diagram-3 me-3"></i>Klasifikasi KNN</h1>
                <p>Analisis Sentimen dengan K-Nearest Neighbors</p>
            </div>

            <div class="content">
                <!-- Statistics Section -->
                <div class="stats-section">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="stat-card training">
                                <div class="icon">
                                    <i class="bi bi-book-half"></i>
                                </div>
                                <div class="stat-number"><?= $datasetCounts['data_latih'] ?? '0'; ?></div>
                                <div class="stat-label">Data Latih</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-card testing">
                                <div class="icon">
                                    <i class="bi bi-clipboard-data"></i>
                                </div>
                                <div class="stat-number"><?= $datasetCounts['data_uji'] ?? '0'; ?></div>
                                <div class="stat-label">Data Uji</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Input -->
                <div class="form-section">
                    <h3><i class="bi bi-gear me-2"></i>Pengaturan Klasifikasi</h3>
                    <form method="POST" action="" id="knnForm">
                        <div class="mb-3">
                            <label for="k_value" class="form-label">Nilai K</label>
                            <input type="number" class="form-control" id="k_value" name="k_value"
                                placeholder="Masukkan nilai K (contoh: 3, 5, 7)"
                                min="1" max="<?= $datasetCounts['data_latih'] ?? '100'; ?>" required>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong class="text-warning">Disarankan memilih nilai ganjil untuk menghindari hasil seri.</strong>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-play-circle me-2"></i>Mulai Klasifikasi
                        </button>
                    </form>
                </div>

                <!-- Error Message -->
                <?php if ($error_message ?? false): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Prediction Result -->
                <?php if (($prediction ?? false) && !($error_message ?? false)): ?>
                    <div class="results-section">
                        <h3><i class="bi bi-check-circle me-2"></i>Hasil Klasifikasi</h3>
                        <div class="alert alert-success">
                            <strong>Prediksi Berhasil:</strong> <?php echo htmlspecialchars($prediction); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Detailed KNN Results -->
                <?php if (($knn_results ?? false) && !($error_message ?? false)): ?>
                    <div class="results-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3><i class="bi bi-list-ul me-2"></i>Detail Hasil Klasifikasi</h3>
                            <div class="action-buttons">
                                <button type="button" class="btn btn-outline-primary" onclick="expandAll()">
                                    <i class="bi bi-arrows-expand me-1"></i>Buka Semua
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="collapseAll()">
                                    <i class="bi bi-arrows-collapse me-1"></i>Tutup Semua
                                </button>
                            </div>
                        </div>

                        <?php foreach ($knn_results['results'] as $index => $result): ?>
                            <div class="knn-result-card">
                                <div class="knn-result-header collapsed" data-bs-toggle="collapse"
                                    data-bs-target="#collapse<?= $index; ?>" aria-expanded="false">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <strong class="me-3">#<?= $index + 1; ?></strong>
                                            <span class="badge <?= strtolower($result['predicted_label']) === 'negative' ? 'badge-danger' : 'badge-success' ?> me-3">
                                                <?= htmlspecialchars($result['predicted_label']); ?>
                                            </span>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <small class="me-3">
                                                (<?php
                                                    $distributions = [];
                                                    foreach ($result['label_distribution'] as $label => $count):
                                                        $distributions[] = $label . ': ' . $count;
                                                    endforeach;
                                                    echo implode(', ', $distributions);
                                                ?>)
                                            </small>
                                            <i class="bi bi-chevron-down chevron-icon"></i>
                                        </div>
                                    </div>
                                    <div class="text-preview">
                                        <small><?= htmlspecialchars(substr($result['test_text'], 0, 100)); ?>...</small>
                                    </div>
                                </div>

                                <div class="collapse" id="collapse<?= $index; ?>">
                                    <div class="collapse-content">
                                        <div class="knn-info-grid">
                                            <div>
                                                <h6 class="text-primary mb-3">
                                                    <i class="bi bi-file-text me-2"></i>Informasi Teks
                                                </h6>
                                                <div class="mb-3">
                                                    <strong>Teks Lengkap:</strong>
                                                    <div class="p-3 bg-light border-start border-primary border-3 mt-2">
                                                        <?= htmlspecialchars($result['test_text']); ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <strong>Prediksi:</strong>
                                                    <span class="badge <?= strtolower($result['predicted_label']) === 'negative' ? 'badge-danger' : 'badge-success' ?> ms-2">
                                                        <?= htmlspecialchars($result['predicted_label']); ?>
                                                    </span>
                                                </div>
                                                <div class="mb-3">
                                                    <strong>Distribusi Label:</strong>
                                                    <div class="mt-2">
                                                        <?php foreach ($result['label_distribution'] as $label => $count): ?>
                                                            <span class="badge <?= strtolower($label) === 'positive' ? 'badge-success' : 'badge-danger' ?> me-2 mb-1">
                                                                <?= htmlspecialchars($label); ?>: <?= $count; ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div>
                                                <h6 class="text-success mb-3">
                                                    <i class="bi bi-bullseye me-2"></i>K-Nearest Neighbors
                                                </h6>
                                                <div class="neighbor-list">
                                                    <?php foreach ($result['k_nearest_neighbors'] as $neighbor_index => $neighbor): ?>
                                                        <div class="neighbor-item">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <strong class="text-primary">Tetangga #<?= $neighbor_index + 1; ?></strong>
                                                                    <div class="mt-1">
                                                                        <span class="badge <?= strtolower($neighbor['label']) === 'positive' ? 'badge-success' : 'badge-danger' ?> me-2">
                                                                            <?= htmlspecialchars($neighbor['label']); ?>
                                                                        </span>
                                                                        <span class="badge distance-badge">
                                                                            Jarak: <?= number_format($neighbor['distance'], 4); ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Comparison Results -->
                <?php if (!empty($current_k_results ?? [])): ?>
                    <div class="comparison-section">
                        <h3><i class="bi bi-table me-2"></i>Perbandingan Hasil (K=<?= $current_k_results[0]['k_value'] ?? ''; ?>)</h3>
                        
                        <?php
                        $correct_count = array_sum(array_column($current_k_results, 'is_correct'));
                        $total_count = count($current_k_results);
                        ?>
                        
                        <div class="comparison-stats">
                            <div class="comparison-stat">
                                <h6>Total Data</h6>
                                <span class="badge badge-primary"><?= $total_count; ?></span>
                            </div>
                            <div class="comparison-stat">
                                <h6>Prediksi Benar</h6>
                                <span class="badge badge-success"><?= $correct_count; ?></span>
                            </div>
                            <div class="comparison-stat">
                                <h6>Prediksi Salah</h6>
                                <span class="badge badge-danger"><?= $total_count - $correct_count; ?></span>
                            </div>
                        </div>

                        <div class="result-table">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>No</th>
                                        <th>Tweet</th>
                                        <th>Aktual</th>
                                        <th>Prediksi</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($current_k_results as $index => $result): ?>
                                        <tr>
                                            <td><?= $index + 1; ?></td>
                                            <td class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($result['tweet']); ?>">
                                                <?= htmlspecialchars(substr($result['tweet'], 0, 50)); ?>...
                                            </td>
                                            <td>
                                                <span class="badge <?= strtolower($result['actual_sentiment']) === 'negative' ? 'badge-danger' : 'badge-success' ?>">
                                                    <?= htmlspecialchars($result['actual_sentiment']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= strtolower($result['predicted_sentiment']) === 'negative' ? 'badge-danger' : 'badge-success' ?>">
                                                    <?= htmlspecialchars($result['predicted_sentiment']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $result['is_correct'] ? 'badge-success' : 'badge-danger' ?>">
                                                    <i class="bi <?= $result['is_correct'] ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                                    <?= $result['is_correct'] ? 'Benar' : 'Salah' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Confusion Matrix -->
                <?php if (!empty($current_confusion_matrix ?? [])): ?>
                    <div class="confusion-matrix-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4><i class="bi bi-grid-3x3 me-2"></i>Confusion Matrix</h4>
                            <span class="badge badge-primary">K = <?= htmlspecialchars($k_value ?? ''); ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered text-center">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Actual \ Predicted</th>
                                        <?php
                                        $predicted_labels = [];
                                        foreach ($current_confusion_matrix as $row) {
                                            if (!in_array($row['predicted_label'], $predicted_labels) && $row['actual_label'] !== 'summary') {
                                                $predicted_labels[] = $row['predicted_label'];
                                            }
                                        }
                                        foreach ($predicted_labels as $label):
                                            echo "<th class='text-capitalize'>$label</th>";
                                        endforeach;
                                        ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $actual_labels = [];
                                    foreach ($current_confusion_matrix as $row) {
                                        if (!in_array($row['actual_label'], $actual_labels) && $row['actual_label'] !== 'summary') {
                                            $actual_labels[] = $row['actual_label'];
                                        }
                                    }

                                    foreach ($actual_labels as $actual):
                                        echo "<tr><th class='bg-light text-capitalize'>$actual</th>";
                                        foreach ($predicted_labels as $predicted):
                                            $value = 0;
                                            foreach ($current_confusion_matrix as $row) {
                                                if ($row['actual_label'] === $actual && $row['predicted_label'] === $predicted) {
                                                    $value = $row['count'];
                                                    break;
                                                }
                                            }
                                            echo "<td class='fw-bold'>$value</td>";
                                        endforeach;
                                        echo "</tr>";
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

          <?php if (!empty($current_confusion_summary)): ?>
            <div class="container my-4 p-4 shadow-sm">
                <h4 class="fw-bold">Classification Report</h4>
                <div class="table-responsive shadow-sm">
                    <table class="table table-striped table-bordered text-center mt-3">
                        <thead class="table-dark">
                            <tr>
                                <th>Label</th>
                                <th>Precision</th>
                                <th>Recall</th>
                                <th>F1-Score</th>
                                <th>Support</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $labels = [];
                            foreach ($current_confusion_matrix as $row) {
                                if ($row['actual_label'] !== 'summary') {
                                    $label = $row['actual_label'];
                                    if (!isset($labels[$label])) {
                                        $labels[$label] = $row;
                                    }
                                }
                            }

                            foreach ($labels as $label => $metrics): ?>
                                <tr>
                                    <td class="text-capitalize"><?= htmlspecialchars($label) ?></td>
                                    <td><?= number_format($metrics['precision'] ?? 0, 2) ?></td>
                                    <td><?= number_format($metrics['recall'] ?? 0, 2) ?></td>
                                    <td><?= number_format($metrics['f1_score'] ?? 0, 2) ?></td>
                                    <td><?= $metrics['support'] ?? ($metrics['tp'] + $metrics['fn']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <th>Accuracy</th>
                                <td colspan="4">
                                    <?= isset($current_confusion_summary['accuracy']) ? number_format($current_confusion_summary['accuracy'] * 100, 2) . '%' : '-' ?>
                                </td>
                            </tr>
                            <tr class="table-secondary fw-bold">
                                <th>Macro Avg</th>
                                <td><?= isset($current_confusion_summary['macro_precision']) ? number_format($current_confusion_summary['macro_precision'], 2) : '-' ?></td>
                                <td><?= isset($current_confusion_summary['macro_recall']) ? number_format($current_confusion_summary['macro_recall'], 2) : '-' ?></td>
                                <td><?= isset($current_confusion_summary['macro_f1_score']) ? number_format($current_confusion_summary['macro_f1_score'], 2) : '-' ?></td>
                                <td><?= $current_confusion_summary['total_samples'] ?? '-' ?></td>
                            </tr>

                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>


        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>

        <script>
            // Script untuk mengatur rotasi chevron icon saat collapse
            document.addEventListener('DOMContentLoaded', function() {
                const collapseElements = document.querySelectorAll('.collapse');

                collapseElements.forEach(function(collapse) {
                    collapse.addEventListener('show.bs.collapse', function() {
                        const header = document.querySelector('[data-bs-target="#' + this.id + '"]');
                        if (header) {
                            header.classList.remove('collapsed');
                        }
                    });

                    collapse.addEventListener('hide.bs.collapse', function() {
                        const header = document.querySelector('[data-bs-target="#' + this.id + '"]');
                        if (header) {
                            header.classList.add('collapsed');
                        }
                    });
                });

                // Event listener untuk tombol tutup individual - PERBAIKAN UTAMA
                const closeButtons = document.querySelectorAll('[data-close-collapse]');
                closeButtons.forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const targetId = this.getAttribute('data-close-collapse');
                        const targetElement = document.getElementById(targetId);

                        if (targetElement) {
                            const bsCollapse = bootstrap.Collapse.getOrCreateInstance(targetElement);
                            bsCollapse.hide();
                        }
                    });
                });
            });

            // Function untuk membuka semua collapse
            function expandAll() {
                const collapseElements = document.querySelectorAll('.collapse');
                collapseElements.forEach(function(collapse) {
                    const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
                    bsCollapse.show();
                });
            }

            // Function untuk menutup semua collapse
            function collapseAll() {
                const collapseElements = document.querySelectorAll('.collapse');
                collapseElements.forEach(function(collapse) {
                    const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
                    bsCollapse.hide();
                });
            }
        </script>
</body>

</html>