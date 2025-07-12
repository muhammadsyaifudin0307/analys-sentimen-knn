<?php
// Ambil data teks dari database DENGAN URUTAN
$query = "SELECT d.id, p.stemming 
          FROM dataset d
          JOIN preprocessing p ON d.id = p.data_id
          ORDER BY d.id ASC";
$result = $conn->query($query);

$texts = [];
$data_ids = [];

while ($row = $result->fetch_assoc()) {
    $texts[] = $row['stemming'];
    $data_ids[] = $row['id'];
}

// Cek jika tombol Compute ditekan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['compute_tfidf'])) {

    // Reset status proses sebelumnya
    unset($_SESSION['process_done']);
    unset($_SESSION['flask_results']);
    unset($_SESSION['global_idf']);

    if (empty($texts)) {
        echo "<p class='text-center text-danger'>No texts to process. Please ensure that data is available.</p>";
    } else {
        // Kirim ke Flask API
        $url = 'http://127.0.0.1:5000/api/compute_tfidf';
        $data = json_encode(['texts' => $texts]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Tambah timeout

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            echo '<div class="alert alert-danger">Error connecting to Flask API: ' . curl_error($ch) . '</div>';
            curl_close($ch);
        } else {
            curl_close($ch);

            if ($http_code !== 200) {
                echo '<div class="alert alert-danger">Flask API returned HTTP code: ' . $http_code . '</div>';
            } else {
                $response_data = json_decode($response, true);

                // Cek struktur response yang benar
                if ($response_data === NULL || !isset($response_data['results']) || !isset($response_data['global_idf'])) {
                    echo '<div class="alert alert-danger">Error: Invalid response from Flask API.</div>';
                    echo "<pre class='bg-light p-3'>" . htmlspecialchars(print_r($response_data, true)) . "</pre>";
                } else {
                    // Hapus data lama dari database terlebih dahulu
                    $delete_query = "DELETE FROM tfidf_results";
                    if (!$conn->query($delete_query)) {
                        echo '<div class="alert alert-danger">Error deleting old data: ' . $conn->error . '</div>';
                    } else {
                        echo '<div class="alert alert-info">Old TF-IDF data deleted successfully.</div>';

                        // Ambil hasil dari Flask
                        $flask_results = $response_data['results'];
                        $global_idf = $response_data['global_idf'];

                        $insert_success = true;
                        $insert_count = 0;

                        // Simpan hasil TF-IDF dari Flask ke database
                        foreach ($flask_results as $index => $result) {
                            $data_id = $data_ids[$index];

                            // Ambil data yang sudah dihitung Flask
                            $tf_data = $result['tf'];
                            $tfidf_data = $result['tfidf'];

                            // Simpan setiap term untuk dokumen ini
                            foreach ($tf_data as $term => $tf_value) {
                                $tfidf_value = isset($tfidf_data[$term]) ? $tfidf_data[$term] : 0.0;
                                $idf_value = isset($global_idf[$term]) ? $global_idf[$term] : 0.0;

                                // Insert ke database
                                $sql = "INSERT INTO tfidf_results (data_id, terms, tf, idf, tfidf) VALUES (?, ?, ?, ?, ?)";
                                $stmt = $conn->prepare($sql);

                                if ($stmt) {
                                    $stmt->bind_param('isddd', $data_id, $term, $tf_value, $idf_value, $tfidf_value);

                                    if ($stmt->execute()) {
                                        $insert_count++;
                                    } else {
                                        echo '<div class="alert alert-warning">Error inserting data: ' . $stmt->error . '</div>';
                                        $insert_success = false;
                                    }
                                    $stmt->close();
                                } else {
                                    echo '<div class="alert alert-danger">Error preparing statement: ' . $conn->error . '</div>';
                                    $insert_success = false;
                                }
                            }
                        }

                        if ($insert_success && $insert_count > 0) {
                            // Menandai bahwa proses selesai
                            $_SESSION['process_done'] = true;
                            $_SESSION['flask_results'] = $flask_results;
                            $_SESSION['global_idf'] = $global_idf;

                            echo '<div class="alert alert-success">TF-IDF calculation completed successfully! ' . $insert_count . ' records inserted into database.</div>';
                        } else {
                            echo '<div class="alert alert-warning">Some issues occurred during data insertion.</div>';
                        }
                    }
                }
            }
        }
    }
}

// Fungsi untuk menampilkan hasil - baik dari sesi atau database
function getDisplayData($conn)
{
    // Cek apakah ada hasil Flask di sesi
    $flask_results = isset($_SESSION['flask_results']) ? $_SESSION['flask_results'] : [];
    $global_idf = isset($_SESSION['global_idf']) ? $_SESSION['global_idf'] : [];

    if (!empty($flask_results)) {
        // Gunakan hasil Flask dari sesi
        return buildMatrixFromFlask($flask_results, $global_idf);
    } else {
        // Ambil dari database jika tidak ada di sesi
        return buildMatrixFromDatabase($conn);
    }
}

function buildMatrixFromFlask($flask_results, $global_idf)
{
    $tfidf_matrix = [];
    $word_count_matrix = [];
    $total_documents = count($flask_results);

    foreach ($flask_results as $index => $result) {
        $doc_name = "d" . ($index + 1);

        // Word count dari Flask
        if (isset($result['word_count'])) {
            foreach ($result['word_count'] as $term => $count) {
                $word_count_matrix[$term][$doc_name] = $count;
            }
        }

        // TF-IDF dari Flask
        foreach ($result['tf'] as $term => $tf_value) {
            $tfidf_value = isset($result['tfidf'][$term]) ? $result['tfidf'][$term] : 0.0;
            $idf_value = isset($global_idf[$term]) ? $global_idf[$term] : 0.0;

            $tfidf_matrix[$term][$doc_name] = [
                'tf' => $tf_value,
                'idf' => $idf_value,
                'tfidf' => $tfidf_value,
                'word_count' => isset($result['word_count'][$term]) ? $result['word_count'][$term] : 0
            ];
        }
    }

    return [
        'tfidf_matrix' => $tfidf_matrix,
        'global_idf' => $global_idf,
        'total_documents' => $total_documents
    ];
}

function buildMatrixFromDatabase($conn)
{
    $query = "SELECT * FROM tfidf_results ORDER BY data_id ASC, terms ASC";
    $result = $conn->query($query);

    $tfidf_matrix = [];
    $global_idf = [];
    $doc_name_mapping = [];

    // Buat mapping dokumen
    $query_docs = "SELECT DISTINCT data_id FROM tfidf_results ORDER BY data_id ASC";
    $result_docs = $conn->query($query_docs);
    $doc_counter = 1;

    if ($result_docs && $result_docs->num_rows > 0) {
        while ($row = $result_docs->fetch_assoc()) {
            $data_id = $row['data_id'];
            $doc_name_mapping[$data_id] = "d{$doc_counter}";
            $doc_counter++;
        }
    }

    // Build matrix dari database
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data_id = $row['data_id'];
            $term = $row['terms'];
            $tf_value = $row['tf'];
            $idf_value = $row['idf'];
            $tfidf_value = $row['tfidf'];

            $doc_name = $doc_name_mapping[$data_id];

            $tfidf_matrix[$term][$doc_name] = [
                'tf' => $tf_value,
                'idf' => $idf_value,
                'tfidf' => $tfidf_value,
                'word_count' => 0 // Tidak tersedia dari database
            ];

            $global_idf[$term] = $idf_value;
        }
    }

    // Hitung total dokumen
    $query_total = "SELECT COUNT(DISTINCT data_id) AS total_documents FROM tfidf_results";
    $result_total = $conn->query($query_total);
    $total_documents = $result_total ? $result_total->fetch_assoc()['total_documents'] : 0;

    return [
        'tfidf_matrix' => $tfidf_matrix,
        'global_idf' => $global_idf,
        'total_documents' => $total_documents
    ];
}

// Ambil data untuk ditampilkan
$display_data = getDisplayData($conn);
$tfidf_matrix = $display_data['tfidf_matrix'];
$global_idf = $display_data['global_idf'];
$total_documents = $display_data['total_documents'];

// Cek apakah ada data untuk ditampilkan (baik dari proses baru atau data lama)
$has_data = !empty($tfidf_matrix) || (isset($_SESSION['process_done']) && $_SESSION['process_done']);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TF-IDF Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <style>
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
            width: 100%;
        }

        .matrix-table {
            font-size: 0.85em;
        }

        .matrix-table td,
        .matrix-table th {
            padding: 0.3rem;
            text-align: center;
        }


        .control-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }

        .control-section h3 {
            font-size: 1.3rem;
            margin-bottom: 25px;
            color: #2c3e50;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }


        .status-card {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .status-card .status-title {
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 10px;
        }

        .status-card .status-details {
            color: #1565c0;
            font-size: 0.95rem;
        }

        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            flex: 1;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #dee2e6;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
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

        .matrix-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }

        .matrix-section h3 {
            font-size: 1.3rem;
            margin-bottom: 25px;
            color: #2c3e50;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }

        .matrix-table {
            font-size: 0.9rem;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .matrix-table thead th {
            background: linear-gradient(135deg, #343a40 0%, #495057 100%);
            color: white;
            font-weight: 600;
            padding: 15px 12px;
            text-align: center;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .matrix-table tbody td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
            transition: background-color 0.2s ease;
        }

        .matrix-table tbody td:first-child {
            background: #f8f9fa;
            font-weight: 600;
            text-align: left;
            border-left: 3px solid #007bff;
        }

        .matrix-table tbody tr:hover td {
            background: #f0f8ff;
        }

        .matrix-table tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .alert-custom {
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .alert-info-custom {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }

        .alert-warning-custom {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-content {
            background: white;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            min-width: 300px;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
            margin-bottom: 20px;
        }

        .loading-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .loading-subtext {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .matrix-icon {
            color: #007bff;
        }

        .compute-icon {
            color: #28a745;
        }

        .stats-icon {
            color: #17a2b8;
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
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="loading-text">Menghitung TF-IDF...</div>
            <div class="loading-subtext">Memproses dokumen dan menghitung matrix</div>
        </div>
    </div>

    <div class="main-container">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="bi bi-calculator me-3"></i>TF-IDF</h1>
            <p>Analisis Term Frequency - Inverse Document Frequency</p>
        </div>

        <!-- Control Section -->
        <div class="control-section">

            <div class="text-center">
                <form method="post" action="" id="tfidfForm">
                    <button type="submit" name="compute_tfidf" class="btn btn-primary">
                        <i class="bi bi-play-circle-fill me-2"></i>
                        <?php echo $has_data ? 'Hitung Ulang TF-IDF' : 'Hitung TF-IDF'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Status and Stats -->
        <?php if ($has_data): ?>
            <div class="fade-in">
                <div class="status-card">
                    <div class="status-title">
                        <i class="bi bi-check-circle-fill me-2"></i>Status Perhitungan
                    </div>
                    <div class="status-details">
                        <?php if (isset($_SESSION['process_done']) && $_SESSION['process_done']): ?>
                            Perhitungan TF-IDF terbaru telah selesai dan berhasil diproses.
                        <?php else: ?>
                            Menampilkan data TF-IDF yang tersimpan dari database.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_documents; ?></div>
                        <div class="stat-label">
                            <i class="bi bi-file-text me-1"></i>Total Dokumen
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($tfidf_matrix); ?></div>
                        <div class="stat-label">
                            <i class="bi bi-tags me-1"></i>Total Terms
                        </div>
                    </div>

                </div>
            </div>
        <?php endif; ?>

        <!-- Matrix Display -->
        <?php if ($has_data && !empty($tfidf_matrix)) : ?>
            <?php
            // Siapkan nama dokumen untuk header tabel
            $doc_names = [];
            foreach ($tfidf_matrix as $term => $docs) {
                $doc_names = array_merge($doc_names, array_keys($docs));
            }
            $doc_names = array_unique($doc_names);
            sort($doc_names);
            ?>

            <div class="fade-in">
                <!-- Word Count Matrix -->
                <div class="matrix-section">
                    <h3>

                        Word Count Matrix
                    </h3>
                    <div class="table-responsive">
                        <table class="table table-bordered matrix-table">
                            <thead>
                                <tr>
                                    <th>Terms</th>
                                    <?php foreach ($doc_names as $doc_name): ?>
                                        <th><?php echo htmlspecialchars($doc_name); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                ksort($tfidf_matrix);
                                foreach ($tfidf_matrix as $term => $docs) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($term); ?></td>
                                        <?php foreach ($doc_names as $doc_name) : ?>
                                            <td>
                                                <?php
                                                echo isset($docs[$doc_name]['word_count'])
                                                    ? $docs[$doc_name]['word_count']
                                                    : '0';
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TF Matrix -->
                <div class="matrix-section">
                    <h3>
                        TF (Term Frequency) Matrix
                    </h3>
                    <div class="table-responsive">
                        <table class="table table-bordered matrix-table">
                            <thead>
                                <tr>
                                    <th>Terms</th>
                                    <?php foreach ($doc_names as $doc_name): ?>
                                        <th><?php echo htmlspecialchars($doc_name); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tfidf_matrix as $term => $docs) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($term); ?></td>
                                        <?php foreach ($doc_names as $doc_name) : ?>
                                            <td>
                                                <?php
                                                echo isset($docs[$doc_name]['tf'])
                                                    ? number_format($docs[$doc_name]['tf'], 4)
                                                    : '0.0000';
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- IDF Values -->
                <div class="matrix-section">
                    <h3>
                        IDF (Inverse Document Frequency) Values
                    </h3>
                    <div class="table-responsive">
                        <table class="table table-bordered matrix-table">
                            <thead>
                                <tr>
                                    <th>Terms</th>
                                    <th>IDF Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                ksort($global_idf);
                                foreach ($global_idf as $term => $idf_value) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($term); ?></td>
                                        <td><?php echo number_format($idf_value, 4); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TF-IDF Matrix -->
                <div class="matrix-section">
                    <h3>
                        TF-IDF Matrix
                    </h3>
                    <div class="table-responsive">
                        <table class="table table-bordered matrix-table">
                            <thead>
                                <tr>
                                    <th>Terms</th>
                                    <?php foreach ($doc_names as $doc_name): ?>
                                        <th><?php echo htmlspecialchars($doc_name); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tfidf_matrix as $term => $docs) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($term); ?></td>
                                        <?php foreach ($doc_names as $doc_name) : ?>
                                            <td>
                                                <?php
                                                echo isset($docs[$doc_name]['tfidf'])
                                                    ? number_format($docs[$doc_name]['tfidf'], 4)
                                                    : '0.0000';
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif (!$has_data) : ?>
            <div class="alert alert-info-custom alert-custom text-center">
                <i class="bi bi-info-circle-fill me-2" style="font-size: 1.2rem;"></i>
                <h4 class="mb-3">Belum Ada Data</h4>
                <p class="mb-0">Klik tombol "Hitung TF-IDF" untuk memulai perhitungan dan analisis dokumen.</p>
            </div>
        <?php else: ?>
            <div class="alert alert-warning-custom alert-custom text-center">
                <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.2rem;"></i>
                <h4 class="mb-3">Tidak Ada Data untuk Ditampilkan</h4>
                <p class="mb-0">Perhitungan selesai tapi tidak ada hasil yang dapat ditampilkan.</p>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.getElementById('tfidfForm').addEventListener('submit', function(e) {
            document.getElementById('loadingOverlay').style.display = 'flex'; // Show loading overlay
            this.querySelector('button[type="submit"]').disabled = true; // Disable submit button
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
</body>

</html>