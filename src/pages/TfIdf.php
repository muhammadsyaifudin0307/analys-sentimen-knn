<?php
// Menghapus status proses selesai dari sesi untuk memungkinkan perhitungan ulang
unset($_SESSION['process_done']);  // Menghapus sesi sebelumnya jika ada

// Cek jika tombol Compute ditekan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['compute_tfidf'])) {

    // Ambil data teks
    $query = "SELECT id, tweet FROM dataset";
    $result = $conn->query($query);

    $texts = [];
    $data_ids = [];

    while ($row = $result->fetch_assoc()) {
        $texts[] = $row['tweet'];
        $data_ids[] = $row['id'];
    }

    if (empty($texts)) {
        echo "No texts to process.";
        exit;
    }

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

    // Collect all terms from processed texts
    $all_terms = [];

    // Menyimpan hasil TF-IDF yang baru
    foreach ($response_data['processed_texts'] as $index => $processed_text) {
        $data_id = $data_ids[$index];

        foreach ($processed_text['tf'] as $terms => $tf) {
            $tfidf = $processed_text['tfidf'][$terms] ?? 0.0;
            $idf = $processed_text['idf'][$terms] ?? 0.0;

            // Insert query untuk menyimpan terms, frekuensi, dan tf-idf
            $sql = "INSERT INTO tfidf_results (data_id, terms, tf, idf, tfidf) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isddd', $data_id, $terms, $tf, $idf, $tfidf);

            if (!$stmt->execute()) {
                echo "Error: " . $stmt->error . "<br>";
            }

            // Collecting all terms into an array
            $all_terms[] = $terms;
        }
    }

    // Remove duplicates using array_unique
    $unique_terms = array_unique($all_terms);

    // Sort the unique terms alphabetically
    sort($unique_terms);

    // Menandai bahwa proses selesai
    $_SESSION['process_done'] = true;
    // Setelah proses selesai, ambil data terbaru dan tampilkan
    echo "<p class='text-center'>Perhitungan TF-IDF selesai dan data baru telah dimasukkan ke dalam database.</p>";

    // Optionally, you can display the unique terms in sorted order
    echo "<p class='text-center'>Unique Terms (Sorted Alphabetically):</p>";
    echo "<p class='text-center'>" . implode(", ", $unique_terms) . "</p>";
}

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Hitung jumlah dokumen
$query_total = "SELECT COUNT(DISTINCT data_id) AS total_documents FROM tfidf_results";
$result_total = $conn->query($query_total);
$total_documents = $result_total->fetch_assoc()['total_documents'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TF-IDF Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>

<body>
    <div class="container">
        <form method="post" action="" class="text-center mb-4">
            <button type="submit" name="compute_tfidf" class="btn btn-success btn-lg">Compute TF-IDF for All Texts</button>
        </form>

        <?php if (isset($_SESSION['process_done']) && $_SESSION['process_done']) : ?>
            <h2 class='text-center mt-4 mb-4'>TF-IDF Results:</h2>
        <?php endif; ?>

        <!-- Display total document count -->
        <p class="text-center">Total Documents: <?php echo $total_documents; ?></p>

        <!-- TF Table -->
        <h3 class="text-center">TF-IDF Table</h3>
        <div class='table-responsive'>
            <table class='table table-bordered table-striped table-hover'>
                <thead class='thead-dark'>
                    <tr>
                        <th>Document ID</th>
                        <th>Term</th>
                        <th>TF</th>
                        <th>IDF</th>
                        <th>TF-IDF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Menampilkan hasil dari tabel tfidf_results
                    $query = "SELECT * FROM tfidf_results LIMIT $records_per_page OFFSET $offset";
                    $result = $conn->query($query);
                    if ($result && $result->num_rows > 0) {
                        $doc_counter = 1; // Counter untuk nama dokumen dimulai dari D1
                        $current_data_id = null; // Variable untuk menyimpan ID dokumen yang sedang diproses
                        while ($row = $result->fetch_assoc()) {
                            // Jika data_id berubah, kita buat nama dokumen baru
                            if ($current_data_id !== $row['data_id']) {
                                $doc_name = "D{$doc_counter}"; // Penamaan dokumen berdasarkan urutan
                                $doc_counter++; // Increment untuk dokumen selanjutnya
                                $current_data_id = $row['data_id']; // Update ID dokumen yang sedang diproses
                            }

                            // Menampilkan ID dokumen dengan nama D1, D2, dst.
                            echo "<tr>
                                <td>{$doc_name}</td> <!-- Nama dokumen -->
                                <td>" . htmlspecialchars($row['terms']) . "</td>
                                <td>" . htmlspecialchars($row['tf']) . "</td>
                                <td>" . htmlspecialchars($row['idf']) . "</td>
                                <td>" . htmlspecialchars($row['tfidf']) . "</td>
                              </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5'>No data found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="text-center">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <?php
                    // Pagination
                    $query = "SELECT COUNT(*) AS total FROM tfidf_results";
                    $result = $conn->query($query);
                    $total_records = $result->fetch_assoc()['total'];
                    $total_pages = ceil($total_records / $records_per_page);

                    for ($i = 1; $i <= $total_pages; $i++) {
                        echo "<li class='page-item " . ($i == $page ? 'active' : '') . "'>
                            <a class='page-link' href='?page=$i'>$i</a>
                          </li>";
                    }
                    ?>
                </ul>
            </nav>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
</body>

</html>