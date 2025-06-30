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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .card-hover {
            transition: transform 0.3s ease-in-out;
        }

        .card-hover:hover {
            transform: translateY(-5px);
        }

        .result-table {
            max-height: 400px;
            overflow-y: auto;
        }

        .badge-correct {
            background-color: #28a745;
        }

        .badge-incorrect {
            background-color: #dc3545;
        }

        .neighbor-item {
            background-color: #f8f9fa;
            border-left: 3px solid #007bff;
            padding: 0.5rem;
            margin-bottom: 0.25rem;
            border-radius: 0.25rem;
        }

        .distance-badge {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            font-size: 0.75rem;
        }

        .knn-result-card {
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .knn-result-header {
            padding: 1rem;
            border-radius: 0.5rem 0.5rem 0 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }


        .text-preview {
            background-color: rgba(255, 255, 255, 0.9);
            padding: 0.5rem;
            border-radius: 0.25rem;
            margin-top: 0.5rem;
            border-left: 3px solid #ffc107;
            color: #212529;
        }

        .collapse-content {
            padding: 1.5rem;
            background: white;
            border-radius: 0 0 0.5rem 0.5rem;
            border-top: 1px solid #dee2e6;
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
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .knn-info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Fix untuk tombol tutup */
        .close-button {
            z-index: 10;
            position: relative;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
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
                            <p class="fs-4 fw-bold mb-1"><?= $datasetCounts['data_latih']; ?></p>
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
                            <p class="fs-4 fw-bold mb-1"><?= $datasetCounts['data_uji']; ?></p>
                            <small>Digunakan untuk menguji model</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>



        <h2 class="text-center mb-4">Prediksi KNN dengan Data Latih dan Uji</h2>

        <!-- Form Input Nilai K -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="k_value" class="form-label">Masukkan Nilai K:</label>
                        <input type="number" class="form-control" id="k_value" name="k_value"
                            placeholder="Masukkan nilai K (contoh: 3, 5, 7)"
                            min="1" max="<?= $datasetCounts['data_latih']; ?>" required>
                        <div class="form-text">
                            <strong class="text-warning">Disarankan memilih nilai ganjil untuk menghindari hasil seri.</strong>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-play-circle me-2"></i> Klasifikasi KNN
                    </button>
                </form>

            </div>
        </div>

        <!-- Pesan Error -->
        <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>


        <!-- Hasil Prediksi -->
        <?php if ($prediction && !$error_message): ?>
            <div class="card mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-check-circle me-2"></i>Hasil Klasifikasi KNN
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        <strong><?php echo htmlspecialchars($prediction); ?></strong>
                    </p>
                </div>
            </div>
        <?php endif; ?>


        <!-- Detail K-Nearest Neighbors dengan Bootstrap Collapse -->
        <?php if ($knn_results && !$error_message): ?>
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>Detail Hasil Klasifikasi KNN
                    </h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="expandAll()">
                            <i class="bi bi-arrows-expand me-1"></i>Buka Semua
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="collapseAll()">
                            <i class="bi bi-arrows-collapse me-1"></i>Tutup Semua
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php foreach ($knn_results['results'] as $index => $result): ?>
                        <div class="knn-result-card">
                            <div class="knn-result-header collapsed" data-bs-toggle="collapse"
                                data-bs-target="#collapse<?= $index; ?>" aria-expanded="false"
                                aria-controls="collapse<?= $index; ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <strong class="me-3">#<?= $index + 1; ?></strong>
                                        <span class="badge <?= strtolower($result['predicted_label']) === 'negatif' ? 'bg-danger text-light' : 'bg-success text-light' ?> me-3">
                                            Prediksi: <?= htmlspecialchars($result['predicted_label']); ?>
                                        </span>
                                    </div>

                                    <div class="d-flex align-items-center">
                                        <small class="text-light me-3">
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
                                    <div class="d-flex justify-content-end mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="collapse" data-bs-target="#collapse<?= $index; ?>">
                                            <i class="bi bi-x-lg me-1"></i>Tutup
                                        </button>
                                    </div>
                                    <div class="knn-info-grid">
                                        <div>
                                            <h6 class="text-primary mb-3">
                                                <i class="bi bi-file-text me-2"></i>Informasi Teks Uji
                                            </h6>
                                            <div class="mb-3">
                                                <strong>Teks Lengkap:</strong>
                                                <div class="p-3 bg-light border-start border-primary border-3 mt-2">
                                                    <?= htmlspecialchars($result['test_text']); ?>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <strong>Hasil Prediksi:</strong>
                                                <span class="badge <?= strtolower(trim($result['predicted_label'])) === 'negative' ? 'bg-danger text-light' : 'bg-success text-light' ?> ms-2 fs-6">
                                                    <?= htmlspecialchars($result['predicted_label']); ?>
                                                </span>

                                            </div>
                                            <div class="mb-3">
                                                <strong>Distribusi Label:</strong>
                                                <div class="mt-2">
                                                    <?php foreach ($result['label_distribution'] as $label => $count): ?>
                                                        <span class="badge <?= strtolower($label) === 'positive' ? 'bg-success text-light' : 'bg-danger text-light' ?> me-2 mb-1">
                                                            <?= htmlspecialchars($label); ?>: <?= $count; ?> data
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>


                                            </div>
                                        </div>

                                        <div>
                                            <h6 class="text-success mb-3">
                                                <i class="bi bi-bullseye me-2"></i>K-Nearest Neighbors Detail
                                            </h6>
                                            <div class="neighbor-list">
                                                <?php foreach ($result['k_nearest_neighbors'] as $neighbor_index => $neighbor): ?>
                                                    <div class="neighbor-item mb-2">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <strong class="text-primary">Tetangga #<?= $neighbor_index + 1; ?></strong>
                                                                <div class="mt-1">
                                                                    <span class="badge <?= strtolower($neighbor['label']) === 'positive' ? 'bg-success text-light' : 'bg-danger text-light' ?> me-2">
                                                                        Label: <?= htmlspecialchars($neighbor['label']); ?>
                                                                    </span>
                                                                    <span class="badge distance-badge">
                                                                        Jarak: <?= number_format($neighbor['distance'], 4); ?>
                                                                    </span>
                                                                </div>
                                                            </div>

                                                            <div class="text-end">
                                                                <i class="bi bi-geo-alt text-muted"></i>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="mt-3 p-2 bg-light rounded">
                                                <small class="text-muted">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    Total K-Nearest Neighbors: <?= count($result['k_nearest_neighbors']); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabel Perbandingan Hasil Terbaru -->
        <?php if (!empty($current_k_results)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-table me-2"></i>Perbandingan Sentiment Aktual vs Prediksi (K=<?= $current_k_results[0]['k_value']; ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    $correct_count = array_sum(array_column($current_k_results, 'is_correct'));
                    $total_count = count($current_k_results);
                    // $accuracy = ($total_count > 0) ? ($correct_count / $total_count) * 100 : 0;
                    ?>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="text-center">
                                <h6>Total Data Uji</h6>
                                <span class="badge bg-primary fs-6"><?= $total_count; ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h6>Prediksi Benar</h6>
                                <span class="badge bg-success fs-6"><?= $correct_count; ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h6>Prediksi Salah</h6>
                                <span class="badge bg-danger fs-6"><?= $total_count - $correct_count; ?></span>
                            </div>
                        </div>
                        <!-- <div class="col-md-3">
                            <div class="text-center">
                                <h6>Akurasi</h6>
                                <span class="badge bg-warning fs-6"><?= number_format($accuracy, 2); ?>%</span>
                            </div>
                        </div> -->
                    </div>

                    <div class="result-table">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>No</th>
                                    <th>Tweet</th>
                                    <th>Sentiment Aktual</th>
                                    <th>Sentiment Prediksi</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_k_results as $index => $result): ?>
                                    <tr>
                                        <td><?= $index + 1; ?></td>
                                        <td class="text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($result['tweet']); ?>">
                                            <?= htmlspecialchars(substr($result['tweet'], 0, 60)); ?>...
                                        </td>
                                        <td>
                                            <span class="badge <?= trim(strtolower($result['actual_sentiment'])) === 'negative' ? 'bg-danger text-light' : 'bg-success text-light' ?>">
                                                <?= htmlspecialchars($result['actual_sentiment']); ?>
                                            </span>

                                        </td>
                                        <td>
                                            <span class="badge <?= strtolower($result['predicted_sentiment']) === 'negative' ? 'bg-danger text-light' : 'bg-success text-light   ' ?>">
                                                <?= htmlspecialchars($result['predicted_sentiment']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($result['is_correct']): ?>
                                                <span class="badge badge-correct">
                                                    <i class="bi bi-check-circle me-1"></i>Benar
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-incorrect">
                                                    <i class="bi bi-x-circle me-1"></i>Tidak Sesuai
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($current_confusion_matrix)): ?>
            <div class="container my-4 p-3 shadow-sm">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="fw-bold mb-3">Confusion Matrix</h4>
                    <span class="badge bg-info text-dark">K = <?= htmlspecialchars($k_value) ?></span>
                </div>

                <div class="table-responsive shadow-sm">
                    <table class="table table-bordered table-striped text-center align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th class="align-middle">Actual \\ Predicted</th>
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
                                    echo "<td>$value</td>";
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
                <h4 class="fw-b">Classification Report</h4>
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