<?php
$sql = "SELECT * FROM dataset WHERE type = 'data_latih'";
$result = $conn->query($sql);
$data_latih_sarkasme = [];
while ($row = $result->fetch_assoc()) {
    $data_latih_sarkasme[] = ["text" => $row["tweet"], "label" => $row["sentiment"]];
}

// Ambil Riwayat Kalimat
$sql_history = "SELECT * FROM analysis_results ORDER BY created_at DESC";
$result_history = $conn->query($sql_history);
$analysis_history = [];
while ($row = $result_history->fetch_assoc()) {
    $analysis_history[] = $row;
}

// Function to delete analysis result
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $delete_sql = "DELETE FROM analysis_results WHERE id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("i", $delete_id);

    if ($stmt->execute()) {
        // Redirect ke halaman analisa setelah penghapusan
        header("Location: index.php?page=analisa"); // Correkte URL untuk pengalihan
        exit;
    } else {
        $error_message = "Gagal menghapus data: " . $stmt->error;
    }
}

// Fungsi kirim data ke Flask API
function sendToFlaskSarkasme($testData, $sentiment, $kValue, $trainData)
{
    $flask_url = 'http://localhost:5000/api/sarc';
    $data = json_encode([
        'testData' => $testData,
        'sentiment' => $sentiment,
        'kValue' => $kValue,
        'trainData' => $trainData
    ]);
    $ch = curl_init($flask_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($data)]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// Validasi dan proses jika form disubmit
$prediction = $error_message = null;
$analysis_details = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $testData = trim($_POST['testData']);
    $sentiment = $_POST['sentiment'];
    $kValue = (int)$_POST['kValue'];

    if (empty($testData)) $error_message = "Kalimat tidak boleh kosong!";
    elseif (empty($sentiment)) $error_message = "Label harus dipilih!";
    elseif ($kValue <= 0) $error_message = "Nilai K harus lebih besar dari 0!";
    elseif (empty($data_latih_sarkasme)) $error_message = "Data latih tidak tersedia!";
    elseif ($kValue > count($data_latih_sarkasme)) $error_message = "Nilai K terlalu besar!";
    elseif (strlen($testData) < 3) $error_message = "Kalimat terlalu pendek!";
    else {
        $response = sendToFlaskSarkasme($testData, $sentiment, $kValue, $data_latih_sarkasme);
        $result = json_decode($response, true);

        if (isset($result['error'])) $error_message = "Error dari Flask: " . $result['error'];
        elseif (isset($result['success']) && $result['success']) {
            $prediction = $result['prediction'];
            $analysis_details = $result;

            // Simpan hasil analisis ke dalam database
            $stmt = $conn->prepare("INSERT INTO analysis_results (test_data, sentiment_actual, sentiment_predicted  , status, k_value) VALUES (?, ?, ?, ?, ?)");
            $status = ($prediction === $sentiment) ? 'Benar' : 'Salah';
            $stmt->bind_param("ssssi", $testData, $sentiment, $prediction, $status, $kValue);

            if ($stmt->execute()) {
                // Jika berhasil disimpan, lakukan apa pun yang perlu di sini
            } else {
                $error_message = "Gagal menyimpan hasil ke database: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Terjadi kesalahan dalam analisis.";
        }
    }
}

// Tutup koneksi hanya setelah semua operasi selesai
$conn->close();

// Fungsi untuk mendapatkan warna dan ikon berdasarkan label
function getLabelColor($label)
{
    return ($label === 'negative') ? 'danger' : 'success';
}

function getLabelIcon($label)
{
    return ($label === 'negative') ? 'emoji-frown' : 'emoji-smile';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisis Sentimen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .form-section,
        .form-analys {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 6px;
            margin-bottom: 25px;
        }

        .form-section h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: #2c3e50;
            font-weight: 600;
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 0.95rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

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

        .results-section {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 25px;
        }

        .results-section h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: #2c3e50;
            font-weight: 600;
        }

        .alert {
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            background: #f8f9fa;
            border-top: none;
            font-weight: 600;
            color: #495057;
            padding: 12px;
        }

        .table td {
            padding: 12px;
            vertical-align: middle;
        }

        .badge-success {
            background-color: #28a745;
        }

        .badge-danger {
            background-color: #dc3545;
        }

        .badge-primary {
            background-color: #007bff;
        }

        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            flex: 1;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }

        .nearest-neighbors-table {
            margin-top: 20px;
        }

        .nearest-neighbors-table .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa;
        }

        .distance-value {
            font-family: 'Consolas', 'Monaco', monospace;
            font-weight: 600;
            color: #495057;
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
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .spinner-border {
            width: 2rem;
            height: 2rem;
            margin-bottom: 15px;
        }
    </style>
</head>

<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="mb-2">Menganalisis ...</h6>
            <p class="text-muted mb-0">Memproses dengan algoritma KNN</p>
        </div>
    </div>
    <div class="main-container p-4">
        <div class="content">
            <!-- Form Input -->
            <div class="form-section">
                <h3><i class="bi bi-pencil-square me-2"></i>Input Data</h3>
                <form method="POST" id="sarkasmeForm">
                    <div class="mb-3">
                        <label class="form-label">Kalimat untuk Dianalisis</label>
                        <textarea class="form-control" name="testData" rows="3" placeholder="Masukkan kalimat yang ingin dianalisis..." required><?php echo isset($_POST['testData']) ? htmlspecialchars($_POST['testData']) : ''; ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Label Aktual</label>
                                <select class="form-select" name="sentiment" required>
                                    <option value="" disabled <?php echo !isset($_POST['sentiment']) ? 'selected' : ''; ?>>Pilih label</option>
                                    <option value="positive" <?php echo isset($_POST['sentiment']) && $_POST['sentiment'] === 'positive' ? 'selected' : ''; ?>>Positive</option>
                                    <option value="negative" <?php echo isset($_POST['sentiment']) && $_POST['sentiment'] === 'negative' ? 'selected' : ''; ?>>Negative</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nilai K</label>
                                <input type="number" class="form-control" name="kValue" min="1" max="<?php echo count($data_latih_sarkasme); ?>" value="<?php echo isset($_POST['kValue']) ? $_POST['kValue'] : ''; ?>" required>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-2"></i>Analisis Sentimen
                    </button>
                </form>
            </div>

            <!-- Hasil Analisis -->
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                <div class="results-section">
                    <h3><i class="bi bi-bar-chart me-2"></i>Hasil Analisis</h3>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php elseif ($prediction): ?>

                        <!-- Ringkasan Hasil -->
                        <div class="mb-4">
                            <h5 class="mb-3">Ringkasan Hasil</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <tr>
                                        <th width="20%">Kalimat</th>
                                        <td><?php echo htmlspecialchars($testData); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Label Aktual</th>
                                        <td>
                                            <span class="badge badge-<?php echo getLabelColor($sentiment); ?>">
                                                <?php echo htmlspecialchars($sentiment); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Prediksi</th>
                                        <td>
                                            <span class="badge badge-<?php echo getLabelColor($prediction); ?>">
                                                <?php echo htmlspecialchars($prediction); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Status</th>
                                        <td>
                                            <span class="badge badge-<?php echo $prediction === $sentiment ? 'success' : 'danger'; ?>">
                                                <?php echo $prediction === $sentiment ? 'Benar' : 'Salah'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Nilai K</th>
                                        <td>
                                            <span class="badge badge-primary"><?php echo $kValue; ?></span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Distribusi Label -->
                        <?php if (isset($analysis_details['confidence_info']['label_distribution'])): ?>
                            <div class="mb-4">
                                <h5 class="mb-3">Distribusi Label</h5>
                                <div class="stats-row">
                                    <?php foreach ($analysis_details['confidence_info']['label_distribution'] as $label => $count): ?>
                                        <div class="stat-card <?php echo strtolower($label) === 'positive' ? 'badge-success' : (strtolower($label) === 'negative' ? 'badge-danger' : ''); ?>">
                                            <div class="stat-number text-white"><?php echo $count; ?></div>
                                            <div class="stat-label text-white"><?php echo htmlspecialchars($label); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- K-Nearest Neighbors -->
                        <?php if (isset($analysis_details['confidence_info']['k_nearest_neighbors']) && !empty($analysis_details['confidence_info']['k_nearest_neighbors'])): ?>
                            <div class="nearest-neighbors-table">
                                <h5 class="mb-3">K-Nearest Neighbors (K=<?php echo $kValue; ?>)</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Ranking</th>
                                                <th>Label</th>
                                                <th>Jarak Euclidean</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analysis_details['confidence_info']['k_nearest_neighbors'] as $index => $neighbor): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-primary">#<?php echo $index + 1; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-<?php echo getLabelColor($neighbor['label']); ?>">
                                                            <?php echo htmlspecialchars($neighbor['label']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="distance-value">
                                                        <?php echo number_format($neighbor['distance'], 6); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="form-section mt-5 rounded">
            <h3 class="mb-3"><i class="bi bi-card-list me-2"></i>Riwayat Analisis</h3>
            <div class="result-table my-4">

                <?php if (empty($analysis_history)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i> Belum ada data analisis.
                    </div>
                <?php else: ?>
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>No</th>
                                <th>Kalimat</th>
                                <th>Label Aktual</th>
                                <th>Label Prediksi</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analysis_history as $index => $history): ?>
                                <tr>
                                    <td><?= $index + 1; ?></td>
                                    <td class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($history['test_data']); ?>">
                                        <?= htmlspecialchars(substr($history['test_data'], 0, 50)); ?>...
                                    </td>
                                    <td>
                                        <span class="badge <?= strtolower($history['sentiment_actual']) === 'negative' ? 'badge-danger' : 'badge-success' ?>">
                                            <?= htmlspecialchars($history['sentiment_actual']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= strtolower($history['sentiment_predicted']) === 'negative' ? 'badge-danger' : 'badge-success' ?>">
                                            <?= htmlspecialchars($history['sentiment_predicted']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $history['status'] === 'Benar' ? 'badge-success' : 'badge-danger' ?>">
                                            <i class="bi <?= $history['status'] === 'Benar' ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                            <?= $history['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Hapus button with X icon -->
                                        <a href="?delete_id=<?php echo $history['id']; ?>" class="btn btn-danger btn-sm" title="Hapus">
                                            <i class="bi bi-x-circle"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <script>
        // Loading overlay
        document.getElementById('sarkasmeForm').addEventListener('submit', function(e) {
            document.getElementById('loadingOverlay').style.display = 'flex';
            this.querySelector('button[type="submit"]').disabled = true;
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>