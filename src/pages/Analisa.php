<?php
// Solusi 1: Gunakan output buffering di awal file
ob_start();



// --- Ambil nilai K untuk filter ---
$selected_k = isset($_GET['filter_k']) ? $_GET['filter_k'] : 'all';

// Ambil semua nilai K unik dari database (untuk dropdown filter)
$k_options = [];
$k_query = $conn->query("SELECT DISTINCT k_value FROM analysis_results ORDER BY k_value ASC");
while ($row = $k_query->fetch_assoc()) {
    $k_options[] = $row['k_value'];
}

$sql = "SELECT * FROM dataset WHERE type = 'data_latih'";
$result = $conn->query($sql);
$data_latih_sarkasme = [];
while ($row = $result->fetch_assoc()) {
    $data_latih_sarkasme[] = ["text" => $row["tweet"], "label" => $row["sentiment"]];
}

if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $delete_sql = "DELETE FROM analysis_results WHERE id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $redirect_url = $_SERVER['PHP_SELF'] . "?page=analisa";
        if ($selected_k !== 'all') $redirect_url .= "&filter_k=" . urlencode($selected_k);
        echo "<script>window.location.href = '$redirect_url';</script>";
        exit();
    } else {
        echo "Gagal menghapus data: " . $stmt->error;
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
$redirect_needed = false;
$redirect_url = "";

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
            $stmt = $conn->prepare("INSERT INTO analysis_results (test_data, sentiment_actual, sentiment_predicted, status, k_value) VALUES (?, ?, ?, ?, ?)");
            $status = ($prediction === $sentiment) ? 'Benar' : 'Salah';
            $stmt->bind_param("ssssi", $testData, $sentiment, $prediction, $status, $kValue);

            if ($stmt->execute()) {
                $stmt->close();
                // Set flag untuk redirect menggunakan JavaScript
                $redirect_needed = true;
                $redirect_url = $_SERVER['PHP_SELF'] . "?page=analisa&success=1";
                $redirect_url .= "&testData=" . urlencode($testData);
                $redirect_url .= "&sentiment=" . urlencode($sentiment);
                $redirect_url .= "&prediction=" . urlencode($prediction);
                $redirect_url .= "&kValue=" . urlencode($kValue);
                $redirect_url .= "&status=" . urlencode($status);
            } else {
                $error_message = "Gagal menyimpan hasil ke database: " . $stmt->error;
            }
        } else {
            $error_message = "Terjadi kesalahan dalam analisis.";
        }
    }
}

// Handle success redirect parameters
$show_success = false;
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $show_success = true;
    $testData = urldecode($_GET['testData']);
    $sentiment = urldecode($_GET['sentiment']);
    $prediction = urldecode($_GET['prediction']);
    $kValue = urldecode($_GET['kValue']);
    $status = urldecode($_GET['status']);
}

// --- Ambil Riwayat Analisis berdasarkan filter K ---
if ($selected_k === 'all') {
    $sql_history = "SELECT * FROM analysis_results ORDER BY created_at DESC";
    $result_history = $conn->query($sql_history);
} else {
    $sql_history = "SELECT * FROM analysis_results WHERE k_value = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql_history);
    $stmt->bind_param("i", $selected_k);
    $stmt->execute();
    $result_history = $stmt->get_result();
}
$analysis_history = [];
while ($row = $result_history->fetch_assoc()) {
    $analysis_history[] = $row;
}

// --- Tutup koneksi setelah semua operasi selesai ---
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
            <!-- Success message for redirect -->
            <?php if ($show_success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Analisis Berhasil!</strong> Data telah disimpan ke riwayat analisis.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Form Input -->
            <div class="form-section">
                <h3><i class="bi bi-pencil-square me-2"></i>Input Data</h3>
                <form method="POST" id="sarkasmeForm">
                    <div class="mb-3">
                        <label class="form-label">Kalimat untuk Dianalisis</label>
                        <textarea class="form-control" name="testData" rows="3" placeholder="Masukkan kalimat yang ingin dianalisis..." required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Label Aktual</label>
                                <select class="form-select" name="sentiment" required>
                                    <option value="" disabled selected>Pilih label</option>
                                    <option value="positive">Positive</option>
                                    <option value="negative">Negative</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nilai K</label>
                                <input type="number" class="form-control" name="kValue" min="1" max="<?php echo count($data_latih_sarkasme); ?>" required>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-2"></i>Analisis Sentimen
                    </button>
                </form>
            </div>

            <!-- Hasil Analisis untuk redirect success -->
            <?php if ($show_success): ?>
                <div class="results-section">
                    <h3><i class="bi bi-bar-chart me-2"></i>Hasil Analisis Terakhir</h3>

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
                                        <span class="badge badge-<?php echo $status === 'Benar' ? 'success' : 'danger'; ?>">
                                            <?php echo $status; ?>
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
                </div>
            <?php endif; ?>
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


            <!-- Hasil Analisis untuk POST -->
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !$redirect_needed): ?>
                <div class="results-section">
                    <h3><i class="bi bi-bar-chart me-2"></i>Hasil Analisis</h3>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>


        <?php

        // --- Fungsi Confusion Matrix & Metrics ---
        function calculateConfusionMatrix($analysis_history)
        {
            if (empty($analysis_history)) return null;

            $confusion_matrix = [
                'positive' => ['positive' => 0, 'negative' => 0],
                'negative' => ['positive' => 0, 'negative' => 0]
            ];
            $total_predictions = 0;
            $correct_predictions = 0;
            foreach ($analysis_history as $result) {
                $actual = strtolower($result['sentiment_actual']);
                $predicted = strtolower($result['sentiment_predicted']);
                if (isset($confusion_matrix[$actual][$predicted])) {
                    $confusion_matrix[$actual][$predicted]++;
                    $total_predictions++;
                    if ($actual === $predicted) $correct_predictions++;
                }
            }
            $tp_positive = $confusion_matrix['positive']['positive'];
            $fp_positive = $confusion_matrix['negative']['positive'];
            $fn_positive = $confusion_matrix['positive']['negative'];
            $tn_positive = $confusion_matrix['negative']['negative'];

            $tp_negative = $confusion_matrix['negative']['negative'];
            $fp_negative = $confusion_matrix['positive']['negative'];
            $fn_negative = $confusion_matrix['negative']['positive'];
            $tn_negative = $confusion_matrix['positive']['positive'];

            $precision_positive = ($tp_positive + $fp_positive) > 0 ? $tp_positive / ($tp_positive + $fp_positive) : 0;
            $recall_positive = ($tp_positive + $fn_positive) > 0 ? $tp_positive / ($tp_positive + $fn_positive) : 0;
            $f1_positive = ($precision_positive + $recall_positive) > 0 ? 2 * ($precision_positive * $recall_positive) / ($precision_positive + $recall_positive) : 0;

            $precision_negative = ($tp_negative + $fp_negative) > 0 ? $tp_negative / ($tp_negative + $fp_negative) : 0;
            $recall_negative = ($tp_negative + $fn_negative) > 0 ? $tp_negative / ($tp_negative + $fn_negative) : 0;
            $f1_negative = ($precision_negative + $recall_negative) > 0 ? 2 * ($precision_negative * $recall_negative) / ($precision_negative + $recall_negative) : 0;

            $accuracy = $total_predictions > 0 ? $correct_predictions / $total_predictions : 0;
            $macro_precision = ($precision_positive + $precision_negative) / 2;
            $macro_recall = ($recall_positive + $recall_negative) / 2;
            $macro_f1 = ($f1_positive + $f1_negative) / 2;

            return [
                'matrix' => $confusion_matrix,
                'total_predictions' => $total_predictions,
                'correct_predictions' => $correct_predictions,
                'accuracy' => $accuracy,
                'metrics' => [
                    'positive' => [
                        'precision' => $precision_positive,
                        'recall' => $recall_positive,
                        'f1_score' => $f1_positive
                    ],
                    'negative' => [
                        'precision' => $precision_negative,
                        'recall' => $recall_negative,
                        'f1_score' => $f1_negative
                    ]
                ],
                'macro_avg' => [
                    'precision' => $macro_precision,
                    'recall' => $macro_recall,
                    'f1_score' => $macro_f1
                ]
            ];
        }
        $evaluation_results = calculateConfusionMatrix($analysis_history);
        ?>
        <!-- FILTER K -->
        <div class="form-section mb-3">
            <form method="GET" action="">
                <label for="filter_k" class="form-label me-2">Filter berdasarkan Nilai K:</label>
                <select name="filter_k" id="filter_k" onchange="this.form.submit()" class="form-select" style="width:10%;display:inline-block;">
                    <option value="all" <?php if ($selected_k === 'all') echo 'selected'; ?>>Semua</option>
                    <?php foreach ($k_options as $k): ?>
                        <option value="<?= $k ?>" <?php if ($selected_k == $k) echo 'selected'; ?>><?= $k ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($_GET['page'])): ?>
                    <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page']) ?>">
                <?php endif; ?>
            </form>
        </div>

        <!-- RIWAYAT ANALISIS -->
        <div class="form-section mt-2 rounded">
            <h3 class="mb-3"><i class="bi bi-card-list me-2"></i>Riwayat Analisis <?php if ($selected_k !== 'all') echo "(K = $selected_k)"; ?></h3>
            <div class="result-table my-4">
                <?php if (empty($analysis_history)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i> Belum ada data analisis<?php if ($selected_k !== 'all') echo " untuk nilai K ini"; ?>.
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
                                        <?= htmlspecialchars(substr($history['test_data'], 0, 50)); ?>
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
                                        <a href="javascript:void(0)" onclick="deleteAnalysis(<?php echo $history['id']; ?>)" class="btn btn-danger btn-sm" title="Hapus">
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

        <!-- CONFUSION MATRIX & CLASSIFICATION REPORT -->
        <?php if ($evaluation_results): ?>
            <div class="form-section mt-5">
                <div class="evaluation-section">
                    <div class="section-header">
                        <h4>
                            <i class="bi bi-grid-3x3-gap me-2"></i>
                            Confusion Matrix
                            <?php if ($selected_k !== 'all'): ?>
                                <span class="k-value-badge">K = <?= $selected_k ?></span>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="confusion-matrix-container">
                        <div class="table-responsive">
                            <table class="confusion-matrix-table">
                                <thead>
                                    <tr>
                                        <th class="corner-cell">Actual \ Predicted</th>
                                        <th class="header-cell">Negative</th>
                                        <th class="header-cell">Positive</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th class="row-header">Negative</th>
                                        <td class="matrix-cell"><?php echo $evaluation_results['matrix']['negative']['negative']; ?></td>
                                        <td class="matrix-cell"><?php echo $evaluation_results['matrix']['negative']['positive']; ?></td>
                                    </tr>
                                    <tr>
                                        <th class="row-header">Positive</th>
                                        <td class="matrix-cell"><?php echo $evaluation_results['matrix']['positive']['negative']; ?></td>
                                        <td class="matrix-cell"><?php echo $evaluation_results['matrix']['positive']['positive']; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="evaluation-section mt-4">
                    <div class="section-header">
                        <h4>Classification Report</h4>
                    </div>
                    <div class="classification-report-container">
                        <div class="table-responsive">
                            <table class="classification-report-table">
                                <thead>
                                    <tr>
                                        <th class="label-col">Label</th>
                                        <th class="metric-col">Precision</th>
                                        <th class="metric-col">Recall</th>
                                        <th class="metric-col">F1-Score</th>
                                        <th class="metric-col">Support</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="label-cell">Negative</td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['negative']['precision'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['negative']['recall'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['negative']['f1_score'], 2); ?></td>
                                        <td class="metric-cell"><?php echo $evaluation_results['matrix']['negative']['negative'] + $evaluation_results['matrix']['negative']['positive']; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label-cell">Positive</td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['positive']['precision'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['positive']['recall'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['metrics']['positive']['f1_score'], 2); ?></td>
                                        <td class="metric-cell"><?php echo $evaluation_results['matrix']['positive']['positive'] + $evaluation_results['matrix']['positive']['negative']; ?></td>
                                    </tr>
                                    <tr class="accuracy-row">
                                        <td class="label-cell">Accuracy</td>
                                        <td class="metric-cell"></td>
                                        <td class="metric-cell"></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['accuracy'] * 100, 2); ?>%</td>
                                        <td class="metric-cell"><?php echo $evaluation_results['total_predictions']; ?></td>
                                    </tr>
                                    <tr class="macro-avg-row">
                                        <td class="label-cell">Macro Avg</td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['macro_avg']['precision'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['macro_avg']['recall'], 2); ?></td>
                                        <td class="metric-cell"><?php echo number_format($evaluation_results['macro_avg']['f1_score'], 2); ?></td>
                                        <td class="metric-cell"><?php echo $evaluation_results['total_predictions']; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="form-section mt-5">
                <h3 class="mb-4"><i class="bi bi-graph-up me-2"></i>Evaluasi Model</h3>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Belum ada data untuk evaluasi model. Silakan lakukan beberapa analisis terlebih dahulu.
                </div>
            </div>
        <?php endif; ?>

        <style>
            /* Evaluation Section Styles */
            .evaluation-section {
                margin-bottom: 30px;
            }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .section-header h4 {
                margin: 0;
                font-size: 1.2rem;
                color: #333;
                font-weight: 600;
            }

            .k-value-badge {
                background-color: #007bff;
                color: white;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 0.875rem;
                font-weight: 500;
            }

            /* Confusion Matrix Styles */
            .confusion-matrix-container {
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .confusion-matrix-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }

            .confusion-matrix-table th,
            .confusion-matrix-table td {
                padding: 15px;
                text-align: center;
                border: 1px solid #dee2e6;
            }

            .confusion-matrix-table .corner-cell {
                background-color: #343a40;
                color: white;
                font-weight: 600;
                font-size: 0.9rem;
            }

            .confusion-matrix-table .header-cell {
                background-color: #343a40;
                color: white;
                font-weight: 600;
                font-size: 1rem;
            }

            .confusion-matrix-table .row-header {
                background-color: #f8f9fa;
                font-weight: 600;
                color: #333;
            }

            .confusion-matrix-table .matrix-cell {
                background-color: #fff;
                font-size: 1.1rem;
                font-weight: 500;
                color: #333;
            }

            /* Classification Report Styles */
            .classification-report-container {
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .classification-report-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }

            .classification-report-table th,
            .classification-report-table td {
                padding: 12px 15px;
                text-align: center;
                border: 1px solid #dee2e6;
            }

            .classification-report-table thead th {
                background-color: #343a40;
                color: white;
                font-weight: 600;
                font-size: 0.9rem;
            }

            .classification-report-table .label-col {
                text-align: left;
                width: 20%;
            }

            .classification-report-table .metric-col {
                width: 20%;
            }

            .classification-report-table .label-cell {
                text-align: left;
                font-weight: 500;
                color: #333;
            }

            .classification-report-table .metric-cell {
                text-align: center;
                font-weight: 500;
                color: #333;
            }

            .classification-report-table .accuracy-row {
                background-color: #f8f9fa;
            }

            .classification-report-table .macro-avg-row {
                background-color: #e9ecef;
                font-weight: 600;
            }

            .classification-report-table .macro-avg-row .label-cell,
            .classification-report-table .macro-avg-row .metric-cell {
                font-weight: 600;
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                .section-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }

                .confusion-matrix-table th,
                .confusion-matrix-table td,
                .classification-report-table th,
                .classification-report-table td {
                    padding: 10px 8px;
                    font-size: 0.9rem;
                }

                .k-value-badge {
                    align-self: flex-start;
                }
            }
        </style>
    </div>

    <script>
        // Loading overlay
        document.getElementById('sarkasmeForm').addEventListener('submit', function(e) {
            document.getElementById('loadingOverlay').style.display = 'flex';
            this.querySelector('button[type="submit"]').disabled = true;
        });

        // Auto hide success alert after 5 seconds
        setTimeout(function() {
            var alert = document.querySelector('.alert-success');
            if (alert) {
                alert.style.display = 'none';
            }
        }, 5000);

        function deleteAnalysis(id) {
            if (confirm('Apakah Anda yakin ingin menghapus data ini?')) {
                let url = '?page=analisa&delete_id=' + id;
                <?php if ($selected_k !== 'all'): ?>
                    url += '&filter_k=<?= urlencode($selected_k) ?>';
                <?php endif; ?>
                window.location.href = url;
            }
        }


        // // Handle redirect after successful analysis
        // <?php if ($redirect_needed): ?>
        //     setTimeout(function() {
        //         window.location.href = '<?php echo $redirect_url; ?>';
        //     }, 1000);
        // <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

<?php
// Flush output buffer jika menggunakan ob_start()
if (ob_get_level()) {
    ob_end_flush();
}
?>