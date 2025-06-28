<?php

$datasetCounts = getDatasetCounts($conn);

// Variabel untuk hasil prediksi
$prediction = null;
$knn_results = null;
$error_message = null;
$saved_count = 0;

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Ambil nilai K dari form input
        $k_value = intval($_POST['k_value']);

        // Validasi nilai K
        if ($k_value <= 0) {
            throw new Exception("Nilai K harus lebih besar dari 0");
        }

        // Ambil data teks dan TF-IDF dari tabel dataset dan tfidf_results, memisahkan berdasarkan type
        $sql = "
            SELECT d.id AS data_id, d.tweet, d.type, t.terms, t.tf, t.idf, t.tfidf, d.sentiment
            FROM svm_analays.dataset d
            JOIN svm_analays.tfidf_results t ON d.id = t.data_id
            WHERE d.type = 'data_latih' OR d.type = 'data_uji'
            ORDER BY d.type, d.id, t.terms
        ";
        $result = $conn->query($sql);

        if (!$result) {
            throw new Exception("Error in query execution: " . $conn->error);
        }

        if ($result->num_rows == 0) {
            throw new Exception("Tidak ada data yang ditemukan dalam query.");
        }

        // Array untuk menyimpan data yang sudah diagregasi
        $aggregated_data = [];

        // Proses setiap baris dan agregasi berdasarkan data_id
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

        // Pisahkan data latih dan uji
        $train_data = [];
        $test_data = [];
        $test_data_mapping = []; // Untuk mapping index dengan data_id

        foreach ($aggregated_data as $data) {
            $processed_data = [
                'data_id' => $data['data_id'],
                'text' => $data['text'],
                'tfidf' => $data['tfidf'],
                'tf' => $data['tf'],
                'idf' => $data['idf'],
                'terms' => $data['terms'],
                'label' => $data['sentiment']
            ];

            if ($data['type'] == 'data_latih') {
                $train_data[] = $processed_data;
            } elseif ($data['type'] == 'data_uji') {
                $test_data[] = $processed_data;
                $test_data_mapping[count($test_data) - 1] = $data['data_id']; // Simpan mapping index -> data_id
            }
        }

        // Validasi data
        if (empty($train_data)) {
            throw new Exception("Tidak ada data latih ditemukan");
        }

        if (empty($test_data)) {
            throw new Exception("Tidak ada data uji ditemukan");
        }

        if ($k_value > count($train_data)) {
            throw new Exception("Nilai K (" . $k_value . ") tidak boleh lebih besar dari jumlah data latih (" . count($train_data) . ")");
        }

        // Siapkan data untuk Flask API
        $data = array(
            'train_data' => $train_data,
            'test_data' => $test_data,
            'k_value' => $k_value
        );

        $data_json = json_encode($data);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error encoding JSON: " . json_last_error_msg());
        }

        // Kirim ke Flask API
        $ch = curl_init('http://127.0.0.1:5000/api/klasifikasi');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_json)
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);

        if ($curl_error) {
            throw new Exception("cURL Error: " . $curl_error);
        }

        if ($http_code !== 200) {
            throw new Exception("HTTP Error: " . $http_code . " - Response: " . $response);
        }

        if ($response) {
            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Error decoding API response: " . json_last_error_msg());
            }

            if (isset($result['error'])) {
                throw new Exception("API Error: " . $result['error']);
            }

            if (!isset($result['success']) || !$result['success']) {
                throw new Exception("API returned unsuccessful response");
            }

            $knn_results = $result['knn_results'];

            // SIMPAN HASIL KE DATABASE - TABEL SEDERHANA
            $conn->begin_transaction();

            try {
                // Hapus hasil KNN sebelumnya dengan K yang sama (opsional)
                $stmt_delete = $conn->prepare("DELETE FROM knn_results WHERE k_value = ?");
                $stmt_delete->bind_param("i", $k_value);
                $stmt_delete->execute();

                // Prepare statement untuk insert
                $stmt = $conn->prepare("
                    INSERT INTO knn_results 
                    (data_uji_id, k_value, actual_sentiment, predicted_sentiment, is_correct, distance_avg) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($knn_results['results'] as $index => $prediction_result) {
                    $data_uji_id = $test_data_mapping[$index];
                    $actual_sentiment = $test_data[$index]['label'];
                    $predicted_sentiment = $prediction_result['predicted_label'];
                    $is_correct = ($actual_sentiment === $predicted_sentiment) ? 1 : 0;

                    // Hitung rata-rata distance dari k-nearest neighbors
                    $distances = array_column($prediction_result['k_nearest_neighbors'], 'distance');
                    $distance_avg = array_sum($distances) / count($distances);

                    $stmt->bind_param(
                        "iissid",
                        $data_uji_id,
                        $k_value,
                        $actual_sentiment,
                        $predicted_sentiment,
                        $is_correct,
                        $distance_avg
                    );
                    $stmt->execute();
                    $saved_count++;
                }

                $conn->commit();
                $prediction = "Klasifikasi KNN berhasil dilakukan dengan K=" . $k_value . ". Tersimpan " . $saved_count . " hasil prediksi ke database.";
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception("Error saving to database: " . $e->getMessage());
            }
        } else {
            throw new Exception("Tidak ada respons dari API Flask");
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        error_log("KNN Classification Error: " . $error_message);
    }
}

// Function untuk mengambil hasil tersimpan dari K tertentu
function getKNNResultsByK($conn, $k_value = null)
{
    if ($k_value) {
        $sql = "
            SELECT kr.*, d.tweet, d.type 
            FROM knn_results kr
            JOIN dataset d ON kr.data_uji_id = d.id
            WHERE kr.k_value = ?
            ORDER BY kr.created_at DESC, kr.id
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $k_value);
    } else {
        $sql = "
            SELECT kr.*, d.tweet, d.type 
            FROM knn_results kr
            JOIN dataset d ON kr.data_uji_id = d.id
            ORDER BY kr.created_at DESC, kr.k_value, kr.id
            LIMIT 50
        ";
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

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
                            Nilai K harus antara 1 dan <?= $datasetCounts['data_latih']; ?> (jumlah data latih)
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-play-circle me-2"></i>Jalankan Klasifikasi KNN
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

        <!-- Tabel Detail K-Nearest Neighbors -->
        <?php if ($knn_results && !$error_message): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>Detail Hasil Klasifikasi KNN
                    </h5>
                </div>
                <div class="card-body">
                    <div class="result-table">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>No</th>
                                    <th>Teks Uji</th>
                                    <th>Prediksi Label</th>
                                    <th>Distribusi Label</th>
                                    <th>Detail K-Nearest</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($knn_results['results'] as $index => $result): ?>
                                    <tr>
                                        <td><?= $index + 1; ?></td>
                                        <td class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($result['test_text']); ?>">
                                            <?= htmlspecialchars(substr($result['test_text'], 0, 50)); ?>...
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($result['predicted_label']); ?></span>
                                        </td>
                                        <td>
                                            <?php foreach ($result['label_distribution'] as $label => $count): ?>
                                                <small class="text-muted"><?= $label; ?>: <?= $count; ?></small><br>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapse<?= $index; ?>"
                                                aria-expanded="false"
                                                aria-controls="collapse<?= $index; ?>">
                                                <i class="bi bi-eye me-1"></i>Lihat Detail
                                            </button>
                                            <div class="collapse mt-2" id="collapse<?= $index; ?>">
                                                <div class="card card-body">
                                                    <?php foreach ($result['k_nearest_neighbors'] as $neighbor_index => $neighbor): ?>
                                                        <small>
                                                            <strong><?= $neighbor_index + 1; ?>.</strong>
                                                            Distance: <?= number_format($neighbor['distance'], 4); ?>,
                                                            Label: <?= htmlspecialchars($neighbor['label']); ?>
                                                        </small><br>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
                    $accuracy = ($total_count > 0) ? ($correct_count / $total_count) * 100 : 0;
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
                        <div class="col-md-3">
                            <div class="text-center">
                                <h6>Akurasi</h6>
                                <span class="badge bg-warning fs-6"><?= number_format($accuracy, 2); ?>%</span>
                            </div>
                        </div>
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
                                            <span class="badge bg-info"><?= htmlspecialchars($result['actual_sentiment']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($result['predicted_sentiment']); ?></span>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to update button text
            function updateButtonText(button, isShown) {
                if (isShown) {
                    button.innerHTML = '<i class="bi bi-eye-slash me-1"></i>Tutup Detail';
                } else {
                    button.innerHTML = '<i class="bi bi-eye me-1"></i>Lihat Detail';
                }
            }

            // Initialize all collapse buttons
            const collapseButtons = document.querySelectorAll('[data-bs-toggle="collapse"]');

            collapseButtons.forEach(function(button) {
                const targetSelector = button.getAttribute('data-bs-target');
                const targetElement = document.querySelector(targetSelector);

                if (targetElement) {
                    // Set initial button text
                    updateButtonText(button, false);

                    // Track state manually
                    let isOpen = false;

                    // Add event listeners for Bootstrap collapse events
                    targetElement.addEventListener('shown.bs.collapse', function() {
                        isOpen = true;
                        updateButtonText(button, true);
                    });

                    targetElement.addEventListener('hidden.bs.collapse', function() {
                        isOpen = false;
                        updateButtonText(button, false);
                    });

                    // Handle click events manually
                    button.addEventListener('click', function(e) {
                        // Let Bootstrap handle the toggle naturally
                        // Just update the button text based on current state
                        setTimeout(function() {
                            if (targetElement.classList.contains('show')) {
                                updateButtonText(button, true);
                            } else {
                                updateButtonText(button, false);
                            }
                        }, 10);
                    });
                }
            });
        });
    </script>
</body>

</html>