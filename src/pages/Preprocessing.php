<?php
$process_done = false;  // Flag untuk memeriksa apakah proses selesai

// Helper function untuk htmlspecialchars yang aman
function safe_htmlspecialchars($string)
{
    return htmlspecialchars((isset($string) && $string !== null) ? $string : '', ENT_QUOTES, 'UTF-8');
}

// Periksa apakah form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Mengambil data teks dari tabel 'dataset'
    $query = "SELECT id, tweet FROM svm_analays.dataset";
    $result = $conn->query($query);

    // Cek apakah dataset kosong
    if ($result->num_rows == 0) {
        echo "<script>
                var toastHTML = '<div class=\"toast-container position-fixed bottom-0 end-0 p-3\">';
                toastHTML += '<div class=\"toast align-items-center text-white bg-danger border-0\" role=\"alert\" aria-live=\"assertive\" aria-atomic=\"true\">';
                toastHTML += '<div class=\"d-flex\">';
                toastHTML += '<div class=\"toast-body\">Error: No data found in the dataset table.</div>';
                toastHTML += '<button type=\"button\" class=\"btn-close btn-close-white\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button>';
                toastHTML += '</div></div></div>';
                document.body.insertAdjacentHTML('beforeend', toastHTML);
                var toast = new bootstrap.Toast(document.querySelector('.toast'));
                toast.show();
                setTimeout(function() {
                    window.location.href = window.location.href; // Reload halaman yang sama
                }, 3000); // Menunggu 3 detik sebelum halaman di-reload
              </script>";
        exit;
    }

    $texts = [];
    $data_ids = [];
    while ($row = $result->fetch_assoc()) {
        $texts[] = $row['tweet'];
        $data_ids[] = $row['id'];
    }

    // Kirim teks ke Flask untuk diproses menggunakan cURL
    $url = 'http://127.0.0.1:5000/api/preprocess';
    $data = json_encode(["texts" => $texts]);

    // Inisialisasi cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Tambah timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Tambah connection timeout

    // Eksekusi cURL dan ambil respon
    $response = curl_exec($ch);

    // Periksa jika cURL gagal
    if (curl_errno($ch)) {
        echo "<script>
                alert('Error connecting to Flask server: " . curl_error($ch) . "');
                window.location.href = window.location.href;
              </script>";
        curl_close($ch);
        exit;
    }

    // Menutup koneksi cURL
    curl_close($ch);

    // Periksa apakah response valid
    $response_data = json_decode($response, true);
    if (!$response_data || !isset($response_data['processed_texts'])) {
        echo "<script>
                alert('Error: Invalid response from Flask server.');
                window.location.href = window.location.href;
              </script>";
        exit;
    }

    // Hapus data lama dan simpan data baru
    $conn->query("DELETE FROM svm_analays.preprocessing");

    foreach ($response_data['processed_texts'] as $index => $processed_text) {
        $data_id = $data_ids[$index]; // The actual data_id from the database

        // Pastikan semua field tidak null sebelum diproses
        $cleaning = $processed_text['cleaned_text'] ?? '';
        $casefolding = $processed_text['casefolded_text'] ?? '';
        $normalisasi = $processed_text['slang_normalized_text'] ?? '';
        $tokenization = json_encode($processed_text['tokenized_text'] ?? []);
        $stopword = json_encode(explode(" ", $processed_text['stopword_removed_text'] ?? ''));
        $stemming = $processed_text['stemmed_text'] ?? '';

        $insert_query = $conn->prepare("INSERT INTO svm_analays.preprocessing (data_id, cleaning, casefolding, normalisasi, tokenization, stopword, stemming)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_query->bind_param(
            'issssss',
            $data_id,
            $cleaning,
            $casefolding,
            $normalisasi,
            $tokenization,
            $stopword,
            $stemming
        );

        if (!$insert_query->execute()) {
            echo "Error inserting data: " . $insert_query->error . "<br>";
        }
    }

    $process_done = true;

    // Show success message
    echo "<script>
            var toastHTML = '<div class=\"toast-container position-fixed bottom-0 end-0 p-3\">';
            toastHTML += '<div class=\"toast align-items-center text-white bg-success border-0\" role=\"alert\" aria-live=\"assertive\" aria-atomic=\"true\">';
            toastHTML += '<div class=\"d-flex\">';
            toastHTML += '<div class=\"toast-body\">Preprocessing completed successfully!</div>';
            toastHTML += '<button type=\"button\" class=\"btn-close btn-close-white\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button>';
            toastHTML += '</div></div></div>';
            document.body.insertAdjacentHTML('beforeend', toastHTML);
            var toast = new bootstrap.Toast(document.querySelector('.toast'));
            toast.show();
          </script>";
}

// Mengambil total jumlah data untuk pagination
$total_query = "SELECT COUNT(*) AS total FROM svm_analays.preprocessing";
$total_result = $conn->query($total_query);
$total_count = $total_result ? $total_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_count / 30);

// Menentukan halaman yang sedang aktif, jika tidak ada maka default ke halaman 1
$page = isset($_GET['pagination']) && is_numeric($_GET['pagination']) && $_GET['pagination'] > 0 ? (int)$_GET['pagination'] : 1;
$offset = ($page - 1) * 30; // Fix: gunakan 10 bukan 30

// Query untuk mengambil data berdasarkan offset dan limit
$query = "SELECT * FROM svm_analays.preprocessing LIMIT 30 OFFSET $offset";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preprocessing Example</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table th,
        .table td {
            text-align: center;
            vertical-align: middle;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            <h6 class="mb-2">Menganalisis ...</h6>
            <p class="text-muted mb-0">Memproses dengan algoritma KNN</p>
        </div>
    </div>

    <div class="main-container p-4">
        <div class="content">
            <!-- Preprocessing Form -->
            <form method="post" action="" id="preprocessForm" class="text-center mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-play-circle me-2"></i>Lakukan Preprocessing
                </button>
            </form>

            <!-- Data Table -->
            <?php if ($total_count > 0): ?>
                <div class="alert alert-info text-center">
                    Total Data: <strong><?php echo $total_count; ?></strong> |
                    Page: <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Name</th>
                            <th>Cleaning</th>
                            <th>Casefolding</th>
                            <th>Normalization</th>
                            <th>Tokenization</th>
                            <th>Stopword</th>
                            <th>Stemming</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($result && $result->num_rows > 0) {
                            $counter = ($page - 1) * 30 + 1;
                            while ($row = $result->fetch_assoc()) {
                                // Decode JSON with null check
                                $tokenization = json_decode($row['tokenization'] ?? '[]', true);
                                $stopword = json_decode($row['stopword'] ?? '[]', true);
                                $stemming = $row['stemming'] ?? '';

                                // Ensure valid arrays before imploding
                                $tokenization_display = is_array($tokenization) ? implode(", ", $tokenization) : '';
                                $stopword_display = is_array($stopword) ? implode(", ", $stopword) : '';
                                $stemming_display = is_string($stemming) ? $stemming : '';

                                $name = "D" . $counter++; // Sequential numbering
                                echo "<tr>
                                        <td><strong>{$name}</strong></td>
                                        <td title='" . htmlspecialchars($row['cleaning']) . "'>" . htmlspecialchars($row['cleaning']) . "</td>
                                        <td title='" . htmlspecialchars($row['casefolding']) . "'>" . htmlspecialchars($row['casefolding']) . "</td>
                                        <td title='" . htmlspecialchars($row['normalisasi']) . "'>" . htmlspecialchars($row['normalisasi']) . "</td>
                                        <td title='" . htmlspecialchars($tokenization_display) . "'>[" . htmlspecialchars($tokenization_display) . "]</td>
                                        <td title='" . htmlspecialchars($stopword_display) . "'>[" . htmlspecialchars($stopword_display) . "]</td>
                                        <td title='" . htmlspecialchars($stemming_display) . "'>" . htmlspecialchars($stemming_display) . "</td>
                                      </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-muted'>No data found. Please run preprocessing first.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container d-flex justify-content-center">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php
                            // Pagination links
                            $current_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                            $parsed_url = parse_url($current_url);
                            parse_str($parsed_url['query'] ?? '', $query_params);

                            // Previous button
                            if ($page > 1) {
                                $query_params['pagination'] = $page - 1;
                                $prev_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                echo "<li class='page-item'><a class='page-link' href='$prev_url'>Previous</a></li>";
                            }

                            // Page numbers
                            for ($i = 1; $i <= $total_pages; $i++) {
                                $query_params['pagination'] = $i;
                                $new_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                $active_class = ($i == $page) ? 'active' : '';
                                echo "<li class='page-item $active_class'><a class='page-link' href='$new_url'>$i</a></li>";
                            }

                            // Next button
                            if ($page < $total_pages) {
                                $query_params['pagination'] = $page + 1;
                                $next_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                echo "<li class='page-item'><a class='page-link' href='$next_url'>Next</a></li>";
                            }
                            ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle form submission for preprocessing
        document.getElementById('preprocessForm').addEventListener('submit', function(e) {
            document.getElementById('loadingOverlay').style.display = 'flex'; // Show loading overlay
            this.querySelector('button[type="submit"]').disabled = true; // Disable submit button
        });
    </script>
</body>

</html>