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
function getDisplayData($conn) {
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

function buildMatrixFromFlask($flask_results, $global_idf) {
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

function buildMatrixFromDatabase($conn) {
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
        .matrix-table td, .matrix-table th {
            padding: 0.3rem;
            text-align: center;
        }
        .btn-compute {
            font-size: 1.1em;
            padding: 10px 30px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <h1 class="mb-4">TF-IDF Calculator</h1>
                
                <form method="post" action="" class="mb-4">
                    <button type="submit" name="compute_tfidf" class="btn btn-success btn-lg btn-compute">
                        <?php echo $has_data ? 'Hitung Ulang TF-IDF' : 'Hitung TF-IDF'; ?>
                    </button>
                </form>
                
                <?php if ($has_data): ?>
                    <div class="alert alert-info">
                        <strong>Status:</strong> 
                        <?php if (isset($_SESSION['process_done']) && $_SESSION['process_done']): ?>
                            Perhitungan TF-IDF terbaru selesai.
                        <?php else: ?>
                            Menampilkan data TF-IDF dari database.
                        <?php endif; ?>
                        <br>
                        <small>
                            Total Dokumen: <?php echo $total_documents; ?> | 
                            Total Terms: <?php echo count($tfidf_matrix); ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

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

            <!-- Word Count Matrix -->
            <div class="row">
                <div class="col-12">
                    <h3 class="text-center mb-3">Word Count Matrix</h3>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-striped matrix-table">
                            <thead class="table-dark">
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
                                        <td><strong><?php echo htmlspecialchars($term); ?></strong></td>
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
            </div>

            <!-- TF Matrix -->
            <div class="row">
                <div class="col-12">
                    <h3 class="text-center mb-3">TF (Term Frequency) Matrix</h3>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-striped matrix-table">
                            <thead class="table-dark">
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
                                        <td><strong><?php echo htmlspecialchars($term); ?></strong></td>
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
            </div>

            <!-- IDF Values -->
            <div class="row">
                <div class="col-12">
                    <h3 class="text-center mb-3">IDF (Inverse Document Frequency) Values</h3>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-striped matrix-table">
                            <thead class="table-dark">
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
                                        <td><strong><?php echo htmlspecialchars($term); ?></strong></td>
                                        <td><?php echo number_format($idf_value, 4); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TF-IDF Matrix -->
            <div class="row">
                <div class="col-12">
                    <h3 class="text-center mb-3">TF-IDF Matrix</h3>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-striped matrix-table">
                            <thead class="table-dark">
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
                                        <td><strong><?php echo htmlspecialchars($term); ?></strong></td>
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
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="alert alert-info text-center">
                        <h4>Belum Ada Data</h4>
                        <p>Klik tombol "Hitung TF-IDF" untuk memulai perhitungan.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="alert alert-warning text-center">
                        <h4>Tidak Ada Data untuk Ditampilkan</h4>
                        <p>Perhitungan selesai tapi tidak ada hasil yang dapat ditampilkan.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>