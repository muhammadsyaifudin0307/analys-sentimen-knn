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
$total_results = count($knn_results['results'] ?? []);
$results_per_tab = 10;
$total_tabs = ceil($total_results / $results_per_tab);

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

</head>
<style>
    .btn-primary {
        background: #3498db;
        border-color: #3498db;
        padding: 12px 30px;
        font-weight: 500;
        border-radius: 4px;
    }

    .btn-primary:hover {
        background: #2980b9;
        border-color: #2980b9;
    }
</style>

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
            <div class="form-section mt-4">
                <h3><i class="bi bi-gear me-2"></i>Klasifikasi Sentimen KNN</h3>
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
            <!-- Detailed KNN Results dengan Tab System -->
            <?php if (($knn_results ?? false) && !($error_message ?? false)): ?>
                <div class="results-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3><i class="bi bi-list-ul me-2"></i>Detail Hasil Klasifikasi (<?= $total_results ?> Data)</h3>
                        <div class="action-buttons">
                            <button type="button" class="btn btn-outline-primary" onclick="expandAllInActiveTab()">
                                <i class="bi bi-arrows-expand me-1"></i>Buka Semua
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="collapseAllInActiveTab()">
                                <i class="bi bi-arrows-collapse me-1"></i>Tutup Semua
                            </button>
                        </div>
                    </div>

                    <?php if ($total_results > 0): ?>
                        <!-- Enhanced Tab Navigation -->
                        <div class="tabs-wrapper">
                            <ul class="nav nav-tabs custom-tabs" id="resultsTab" role="tablist">
                                <?php for ($tab = 1; $tab <= $total_tabs; $tab++): ?>
                                    <?php
                                    $start_num = ($tab - 1) * $results_per_tab + 1;
                                    $end_num = min($tab * $results_per_tab, $total_results);
                                    ?>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link custom-tab-btn <?= $tab === 1 ? 'active' : '' ?>"
                                            id="tab<?= $tab ?>-tab"
                                            data-bs-toggle="tab"
                                            data-bs-target="#tab<?= $tab ?>"
                                            type="button"
                                            role="tab"
                                            aria-controls="tab<?= $tab ?>"
                                            aria-selected="<?= $tab === 1 ? 'true' : 'false' ?>">
                                            <div class="tab-content-wrapper">
                                                <div class="tab-icon">
                                                    <i class="bi bi-collection me-2"></i>
                                                </div>
                                                <div class="tab-info">
                                                    <span class="tab-title">Tabs <?= $tab ?></span>
                                                    <small class="tab-subtitle">Data <?= $start_num ?>-<?= $end_num ?></small>
                                                </div>
                                            </div>
                                        </button>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </div>

                        <!-- Enhanced Tab Content -->
                        <div class="tab-content custom-tab-content" id="resultsTabContent">
                            <?php for ($tab = 1; $tab <= $total_tabs; $tab++): ?>
                                <div class="tab-pane fade <?= $tab === 1 ? 'show active' : '' ?>"
                                    id="tab<?= $tab ?>"
                                    role="tabpanel"
                                    aria-labelledby="tab<?= $tab ?>-tab"
                                    style="padding: 25px; background: #f8f9fa; border-radius: 0 0 8px 8px;">

                                    <?php
                                    $start_index = ($tab - 1) * $results_per_tab;
                                    $end_index = min($start_index + $results_per_tab, $total_results);
                                    ?>

                                    <!-- Tab Content Header -->
                                    <div class="tab-content-header mb-4">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">
                                                <i class="bi bi-file-earmark-text me-2 text-primary"></i>
                                                Halaman <?= $tab ?> - Menampilkan <?= $end_index - $start_index ?> dari <?= $total_results ?> data
                                            </h5>
                                            <div class="tab-stats">
                                                <span class="badge bg-light text-dark">
                                                    <?= $start_index + 1 ?> - <?= $end_index ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <?php for ($i = $start_index; $i < $end_index; $i++): ?>
                                        <?php $result = $knn_results['results'][$i]; ?>
                                        <div class="knn-result-card" id="card-<?= $i ?>">
                                            <div class="knn-result-header collapsed"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapse<?= $i ?>"
                                                aria-expanded="false">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <strong class="me-3">Doc - <?= $i + 1 ?></strong>
                                                        <span class="badge <?= strtolower($result['predicted_label']) === 'negative' ? 'badge-danger' : 'badge-success' ?> me-3">
                                                            <?= htmlspecialchars($result['predicted_label']) ?>
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
                                                        <button class="close-btn me-2"
                                                            onclick="closeCard(<?= $i ?>)"
                                                            title="Tutup"
                                                            style="background: none; border: none; color: #666; font-size: 1.2rem; cursor: pointer; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s;">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                        <i class="bi bi-chevron-down chevron-icon"></i>
                                                    </div>
                                                </div>
                                                <div class="text-preview">
                                                    <small><?= htmlspecialchars(substr($result['test_text'], 0, 100)) ?>...</small>
                                                </div>
                                            </div>

                                            <div class="collapse" id="collapse<?= $i ?>">
                                                <div class="collapse-content">
                                                    <div class="knn-info-grid">
                                                        <div>
                                                            <h6 class="text-primary mb-3">
                                                                <i class="bi bi-file-text me-2"></i>Informasi Teks
                                                            </h6>
                                                            <div class="mb-3">
                                                                <strong>Teks Lengkap:</strong>
                                                                <div class="p-3 bg-light border-start border-primary border-3 mt-2">
                                                                    <?= htmlspecialchars($result['test_text']) ?>
                                                                </div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <strong>Prediksi:</strong>
                                                                <span class="badge <?= strtolower($result['predicted_label']) === 'negative' ? 'badge-danger' : 'badge-success' ?> ms-2">
                                                                    <?= htmlspecialchars($result['predicted_label']) ?>
                                                                </span>
                                                            </div>
                                                            <div class="mb-3">
                                                                <strong>Distribusi Label:</strong>
                                                                <div class="mt-2">
                                                                    <?php foreach ($result['label_distribution'] as $label => $count): ?>
                                                                        <span class="badge <?= strtolower($label) === 'positive' ? 'badge-success' : 'badge-danger' ?> me-2 mb-1">
                                                                            <?= htmlspecialchars($label) ?>: <?= $count ?>
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
                                                                                <strong class="text-primary">Tetangga #<?= $neighbor_index + 1 ?></strong>
                                                                                <div class="mt-1">
                                                                                    <span class="badge <?= strtolower($neighbor['label']) === 'positive' ? 'badge-success' : 'badge-danger' ?> me-2">
                                                                                        <?= htmlspecialchars($neighbor['label']) ?>
                                                                                    </span>
                                                                                    <span class="badge distance-badge">
                                                                                        Jarak: <?= number_format($neighbor['distance'], 4) ?>
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
                                    <?php endfor; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
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

                    <div class="comparison-stats d-flex justify-content-between">
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
                <div class=" p-4 shadow-sm">
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>

    <script>
        // Menunggu hingga DOM sepenuhnya dimuat
        document.addEventListener('DOMContentLoaded', function() {
            // Mengambil form berdasarkan ID
            const knnForm = document.getElementById('knnForm');

            // Menambahkan event listener pada form untuk menangani submit
            knnForm.addEventListener('submit', function(e) {
                e.preventDefault(); // Mencegah form untuk dikirimkan secara default
                document.getElementById('loadingOverlay').style.display = 'flex'; // Menampilkan loading overlay

                // Menonaktifkan tombol submit agar tidak bisa diklik berulang kali
                this.querySelector('button[type="submit"]').disabled = true;

                // Menunggu sedikit sebelum mengirimkan form agar overlay muncul lebih jelas
                setTimeout(() => {
                    knnForm.submit(); // Kirimkan form setelah overlay tampil
                }, 1000);
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Fungsi untuk menutup card individual ketika tombol "x" ditekan
            window.closeCard = function(index) {
                event.stopPropagation(); // Mencegah event klik propagasi lebih lanjut

                // Ambil elemen collapse berdasarkan index
                const collapseElement = document.getElementById(`collapse${index}`);

                // Cek apakah elemen collapse ada
                if (collapseElement) {
                    // Membuat instance Bootstrap Collapse untuk elemen yang dipilih
                    const bsCollapse = new bootstrap.Collapse(collapseElement, {
                        toggle: false
                    }); // toggle: false artinya tidak langsung membuka atau menutup, kita kontrol manual
                    bsCollapse.hide(); // Menyembunyikan collapse tanpa menghapus elemen
                }
            };

            // Function untuk membuka semua collapse di tab aktif
            window.expandAllInActiveTab = function() {
                const activeTabPane = document.querySelector('.tab-pane.active');
                if (activeTabPane) {
                    const collapseElements = activeTabPane.querySelectorAll('.collapse');
                    collapseElements.forEach(function(collapse) {
                        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
                        bsCollapse.show(); // Membuka semua collapse di tab aktif
                    });
                }
            };

            // Function untuk menutup semua collapse di tab aktif
            window.collapseAllInActiveTab = function() {
                const activeTabPane = document.querySelector('.tab-pane.active');
                if (activeTabPane) {
                    const collapseElements = activeTabPane.querySelectorAll('.collapse');
                    collapseElements.forEach(function(collapse) {
                        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
                        bsCollapse.hide(); // Menutup semua collapse di tab aktif
                    });
                }
            };

            // Event listener untuk mengatur rotasi chevron
            document.addEventListener('click', function(e) {
                if (e.target.closest('.knn-result-header')) {
                    const header = e.target.closest('.knn-result-header');
                    const chevron = header.querySelector('.chevron-icon');

                    if (chevron) {
                        setTimeout(() => {
                            const isExpanded = !header.classList.contains('collapsed');
                            chevron.style.transform = isExpanded ? 'rotate(180deg)' : 'rotate(0deg)';
                        }, 10);
                    }
                }
            });
        });
    </script>
</body>

</html>