<?php
// Menghapus status proses selesai dari sesi untuk memungkinkan perhitungan ulang
unset($_SESSION['process_done']);  // Menghapus sesi sebelumnya jika ada

// Ambil data teks dari database DENGAN URUTAN
$query = "SELECT id, tweet FROM dataset ORDER BY id ASC";
$result = $conn->query($query);

$texts = [];
$data_ids = [];

while ($row = $result->fetch_assoc()) {
    $texts[] = $row['tweet'];
    $data_ids[] = $row['id'];
}

// Cek jika tombol Compute ditekan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['compute_tfidf'])) {

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

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error: ' . curl_error($ch);
            exit;
        }
        curl_close($ch);

        $response_data = json_decode($response, true);

        if ($response_data === NULL || !isset($response_data['processed_texts'])) {
            echo "Error: Invalid response from Flask.";
            exit;
        }

        // Hapus data lama yang ada di tabel tfidf_results sebelum memasukkan data baru
        $conn->query("DELETE FROM tfidf_results");

        // Simpan hasil TF-IDF yang baru
        foreach ($response_data['processed_texts'] as $index => $processed_text) {
            $data_id = $data_ids[$index];

            // Pastikan 'tf', 'idf', dan 'tfidf' ada di dalam data sebelum mencoba untuk mengaksesnya
            if (isset($processed_text['tf']) && isset($processed_text['idf']) && isset($processed_text['tfidf'])) {
                foreach ($processed_text['tf'] as $terms => $tf) {
                    $tfidf = isset($processed_text['tfidf'][$terms]) ? $processed_text['tfidf'][$terms] : 0.0;
                    $idf = isset($processed_text['idf'][$terms]) ? $processed_text['idf'][$terms] : 0.0;

                    // Insert query untuk menyimpan terms, frekuensi, dan tf-idf (tanpa word_count)
                    $sql = "INSERT INTO tfidf_results (data_id, terms, tf, idf, tfidf) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isddd', $data_id, $terms, $tf, $idf, $tfidf);

                    if (!$stmt->execute()) {
                        echo "Error: " . $stmt->error . "<br>";
                    }
                }
            } else {
                // Jika 'tf', 'idf', atau 'tfidf' tidak ada, tampilkan pesan
                echo "<p class='text-center text-warning'>No TF, IDF, or TF-IDF data for document {$data_id}. Skipping entry.</p>";
            }
        }

        // Menandai bahwa proses selesai
        $_SESSION['process_done'] = true;
        // Setelah proses selesai, ambil data terbaru dan tampilkan
        echo "<p class='text-center text-success'>Perhitungan TF-IDF selesai dan data baru telah dimasukkan ke dalam database.</p>";
    }
}

// Pagination settings
$records_per_page = 1000;  // Set it large enough to fetch all records
$page = 1;  // Remove or adjust pagination
$offset = 0;  // Ensure offset is 0 to fetch all records

// Hitung jumlah dokumen
$query_total = "SELECT COUNT(DISTINCT data_id) AS total_documents FROM tfidf_results";
$result_total = $conn->query($query_total);
$total_documents = $result_total->fetch_assoc()['total_documents'];

// Ambil data TF-IDF dari database dengan pengurutan berdasarkan data_id kemudian terms
$query = "SELECT * FROM tfidf_results ORDER BY data_id ASC, terms ASC LIMIT $records_per_page OFFSET $offset";
$result = $conn->query($query);

// INISIALISASI ARRAY YANG BENAR - INI YANG DIPERBAIKI
$tfidf_matrix = [
    'tf' => [],
    'idf' => [],
    'tfidf' => []
];
$doc_name_mapping = [];
$all_terms_from_db = [];
$word_count_matrix = [];

// PERBAIKAN: Buat mapping dokumen berdasarkan urutan data_id
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

if ($result && $result->num_rows > 0) {
    // Build the TF, IDF, TF-IDF matrices
    while ($row = $result->fetch_assoc()) {
        $data_id = $row['data_id'];
        $term = $row['terms'];
        $tfidf_value = $row['tfidf'];
        $tf_value = $row['tf'];
        $idf_value = $row['idf'];

        // Simpan semua terms dari database
        if (!in_array($term, $all_terms_from_db)) {
            $all_terms_from_db[] = $term;
        }

        $doc_name = $doc_name_mapping[$data_id];

        // Initialize arrays if not exists
        if (!isset($tfidf_matrix['tf'][$term])) {
            $tfidf_matrix['tf'][$term] = [];
        }
        if (!isset($tfidf_matrix['idf'][$term])) {
            $tfidf_matrix['idf'][$term] = [];
        }
        if (!isset($tfidf_matrix['tfidf'][$term])) {
            $tfidf_matrix['tfidf'][$term] = [];
        }

        // Fill the matrices with TF, IDF, and TF-IDF values
        $tfidf_matrix['tf'][$term][$doc_name] = $tf_value;
        $tfidf_matrix['idf'][$term][$doc_name] = $idf_value;
        $tfidf_matrix['tfidf'][$term][$doc_name] = $tfidf_value;
    }
}

// Ambil word count dari response Flask untuk ditampilkan
if (isset($response_data['processed_texts'])) {
    foreach ($response_data['processed_texts'] as $index => $processed_text) {
        $data_id = $data_ids[$index];
        $doc_name = isset($doc_name_mapping[$data_id]) ? $doc_name_mapping[$data_id] : "d" . ($index + 1);

        if (isset($processed_text['word_count'])) {
            foreach ($processed_text['word_count'] as $term => $count) {
                if (!isset($word_count_matrix[$term])) {
                    $word_count_matrix[$term] = [];
                }
                $word_count_matrix[$term][$doc_name] = $count;
            }
        }
    }
}

// Ambil IDF data dari database (yang sudah disimpan dari Flask)
$idf_data = [];
$query_idf = "SELECT terms, idf FROM tfidf_results GROUP BY terms ORDER BY terms ASC";
$result_idf = $conn->query($query_idf);

if ($result_idf && $result_idf->num_rows > 0) {
    while ($row = $result_idf->fetch_assoc()) {
        $term = $row['terms'];
        $idf_value = $row['idf'];

        // Hitung DF dari database
        $query_df = "SELECT COUNT(DISTINCT data_id) as df FROM tfidf_results WHERE terms = ?";
        $stmt = $conn->prepare($query_df);
        $stmt->bind_param('s', $term);
        $stmt->execute();
        $result_df = $stmt->get_result();
        $df_value = $result_df->fetch_assoc()['df'];

        $idf_data[$term] = [
            'df' => $df_value,
            'idf_value' => $idf_value
        ];
    }
}

// PENGECEKAN YANG DIPERBAIKI - LEBIH AMAN
if (!empty($tfidf_matrix['tf'])) {
    ksort($tfidf_matrix['tf']);
}

if (!empty($tfidf_matrix['idf'])) {
    ksort($tfidf_matrix['idf']);
}

if (!empty($tfidf_matrix['tfidf'])) {
    ksort($tfidf_matrix['tfidf']);
}

if (!empty($word_count_matrix)) {
    ksort($word_count_matrix);
}

if (!empty($idf_data)) {
    ksort($idf_data);
}

// PERBAIKAN: Urutkan terms dan pastikan urutan dokumen konsisten
sort($all_terms_from_db);

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
    </style>
</head>

<body>
    <div class="py-3">
        <form method="post" action="" class="text-center mb-4">
            <button type="submit" name="compute_tfidf" class="btn btn-success btn-lg">Hitung TF-IDF</button>
        </form>

        <?php if (isset($_SESSION['process_done']) && $_SESSION['process_done']) : ?>
            <h2 class='text-center mt-4 mb-4'>Perhitungan TF-IDF Selesai:</h2>
        <?php endif; ?>

        <!-- Display TF Table -->
        <h3 class="text-center">TF Table (Term Frequency)</h3>
        <div class='table-responsive'>
            <?php if (empty($tfidf_matrix['tf'])): ?>
                <p class="text-center text-warning">No TF data available to display.</p>
            <?php else: ?>
                <table class='table table-bordered table-striped table-hover'>
                    <thead class='thead-dark'>
                        <tr>
                            <th>Term</th>
                            <?php
                            // PERBAIKAN: Tampilkan dokumen berdasarkan urutan yang benar
                            ksort($doc_name_mapping);
                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                echo "<th>{$doc_name}</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($tfidf_matrix['tf'] as $term => $doc_values) {
                            echo "<tr><td>" . htmlspecialchars($term) . "</td>";
                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                $tf_value = isset($doc_values[$doc_name]) ? $doc_values[$doc_name] : 0;
                                echo "<td>" . htmlspecialchars($tf_value) . "</td>";
                            }
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Display Word Count Table -->
        <h3 class="text-center">Word Count Table (Frequency of Terms in Documents)</h3>
        <div class='table-responsive'>
            <h4 class="text-center fw-bolder">N : <?php echo $total_documents; ?></h4>
            <?php if (empty($word_count_matrix)): ?>
                <p class="text-center text-warning">No Word Count data available to display.</p>
            <?php else: ?>
                <table class='table table-bordered table-striped table-hover'>
                    <thead class='thead-dark'>
                        <tr>
                            <th>Term</th>
                            <?php
                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                echo "<th>{$doc_name}</th>";
                            }
                            ?>
                            <th>df</th>
                            <th>log(N/df)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($word_count_matrix as $term => $doc_values) {
                            echo "<tr><td>" . htmlspecialchars($term) . "</td>";

                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                $word_count = isset($doc_values[$doc_name]) ? $doc_values[$doc_name] : 0;
                                echo "<td>" . htmlspecialchars($word_count) . "</td>";
                            }

                            $df_value = isset($idf_data[$term]) ? $idf_data[$term]['df'] : 0;
                            $idf_value = isset($idf_data[$term]) ? $idf_data[$term]['idf_value'] : 0;
                            echo "<td>" . htmlspecialchars($df_value) . "</td>";
                            echo "<td>" . htmlspecialchars(number_format($idf_value, 6)) . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Display TF-IDF Table -->
        <h3 class="text-center">TF-IDF Table (Term Frequency - Inverse Document Frequency)</h3>
        <div class='table-responsive'>
            <?php if (empty($tfidf_matrix['tfidf'])): ?>
                <p class="text-center text-warning">No TF-IDF data available to display.</p>
            <?php else: ?>
                <table class='table table-bordered table-striped table-hover'>
                    <thead class='thead-dark'>
                        <tr>
                            <th>Term</th>
                            <?php
                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                echo "<th>{$doc_name}</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($tfidf_matrix['tfidf'] as $term => $doc_values) {
                            echo "<tr><td>" . htmlspecialchars($term) . "</td>";
                            foreach ($doc_name_mapping as $data_id => $doc_name) {
                                $tfidf_value = isset($doc_values[$doc_name]) ? $doc_values[$doc_name] : 0;
                                echo "<td>" . htmlspecialchars($tfidf_value) . "</td>";
                            }
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Display Transpose TF-IDF Table -->
        <h3 class="text-center">Transpose TF-IDF Table (Documents as Rows, Terms as Columns)</h3>
        <div class='table-responsive'>
            <?php if (empty($tfidf_matrix['tfidf'])): ?>
                <p class="text-center text-warning">No TF-IDF data available to display in transposed format.</p>
            <?php else: ?>
                <table class='table table-bordered table-striped table-hover'>
                    <thead class='thead-dark'>
                        <tr>
                            <th>Document</th>
                            <?php
                            foreach ($all_terms_from_db as $term) {
                                echo "<th>" . htmlspecialchars($term) . "</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($doc_name_mapping as $data_id => $doc_name) {
                            echo "<tr><td>" . htmlspecialchars($doc_name) . "</td>";
                            foreach ($all_terms_from_db as $term) {
                                $tfidf_value = isset($tfidf_matrix['tfidf'][$term][$doc_name]) ? $tfidf_matrix['tfidf'][$term][$doc_name] : 0;
                                echo "<td>" . htmlspecialchars($tfidf_value) . "</td>";
                            }
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
</body>

</html>