<?php
$process_done = false;  // Flag untuk memeriksa apakah proses selesai

// Pastikan koneksi ke database ada (misalnya menggunakan mysqli_connect)
// $conn = new mysqli("localhost", "username", "password", "database_name");

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

    $texts = $data_ids = [];
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

    // Eksekusi cURL dan ambil respon
    $response = curl_exec($ch);

    // Periksa jika cURL gagal
    if (curl_errno($ch)) {
        echo "Error: " . curl_error($ch);
        exit;
    }

    // Menutup koneksi cURL
    curl_close($ch);

    // Periksa apakah response valid
    $response_data = json_decode($response, true);
    if (!$response_data || !isset($response_data['processed_texts'])) {
        echo "Error: Invalid response from Flask.";
        exit;
    }

    // Hapus data lama dan simpan data baru
    $conn->query("DELETE FROM svm_analays.preprocessing");
    foreach ($response_data['processed_texts'] as $index => $processed_text) {
        $data_id = $data_ids[$index]; // The actual data_id from the database
        $tokenization = json_encode($processed_text['tokenized_text']);
        $stopword = json_encode(explode(" ", $processed_text['stopword_removed_text']));
        $stemming = json_encode($processed_text['stemmed_text']);

        $insert_query = $conn->prepare("INSERT INTO svm_analays.preprocessing (data_id, cleaning, casefolding, normalisasi, tokenization, stopword, stemming)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_query->bind_param(
            'issssss',
            $data_id,
            $processed_text['cleaned_text'],
            $processed_text['casefolded_text'],
            $processed_text['normalized_text'],
            $tokenization,
            $stopword,
            $stemming
        );
        if (!$insert_query->execute()) {
            echo "Error: " . $insert_query->error . "<br>";
        }
    }

    $process_done = true;
}
// Mengambil total jumlah data untuk pagination
$total_query = "SELECT COUNT(*) AS total FROM svm_analays.preprocessing";
$total_pages = ceil($conn->query($total_query)->fetch_assoc()['total'] / 10);

// Menentukan halaman yang sedang aktif, jika tidak ada maka default ke halaman 1
$page = isset($_GET['pagination']) && is_numeric($_GET['pagination']) && $_GET['pagination'] > 0 ? (int)$_GET['pagination'] : 1;
$offset = ($page - 1) * 30;

// Query untuk mengambil data berdasarkan offset dan limit
$query = "SELECT * FROM svm_analays.preprocessing LIMIT 10 OFFSET $offset";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preprocessing Example</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            color: #333;
        }

        #loading:after {
            content: "Loading...";
            display: inline-block;
            padding-left: 10px;
            animation: blink 1s step-end infinite;
        }

        @keyframes blink {
            50% {
                opacity: 0;
            }
        }

        .table th,
        .table td {
            text-align: center;
            vertical-align: middle;
        }

        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>

<body>
    <div class="py-2">
        <h1 class="text-center">Preprocessing Texts</h1>
        <form method="post" action="" id="preprocessForm" class="text-center mb-4">
            <button type="submit" class="btn btn-success">Preprocess All Texts</button>
        </form>

        <div id="loading"></div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
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
                        $counter = ($page - 1) * 10 + 1; // Mulai dari D1 di halaman pertama, D11 di halaman kedua, dll
                        while ($row = $result->fetch_assoc()) {
                            $tokenization = is_array(json_decode($row['tokenization'], true)) ? json_decode($row['tokenization'], true) : explode(", ", $row['tokenization']);
                            $stopword = is_array(json_decode($row['stopword'], true)) ? json_decode($row['stopword'], true) : explode(", ", $row['stopword']);
                            $stemming = is_array(json_decode($row['stemming'], true)) ? json_decode($row['stemming'], true) : explode(", ", $row['stemming']);

                            $tokenization_display = implode(", ", $tokenization);
                            $stopword_display = implode(", ", $stopword);
                            $stemming_display = implode(", ", $stemming);

                            $name = "D" . $counter++; // Penomoran yang berlanjut
                            echo "<tr>
                                    <td>{$name}</td>
                                    <td>" . htmlspecialchars($row['cleaning']) . "</td>
                                    <td>" . htmlspecialchars($row['casefolding']) . "</td>
                                    <td>" . htmlspecialchars($row['normalisasi']) . "</td>
                                    <td>[" . htmlspecialchars($tokenization_display) . "]</td>
                                    <td>[" . htmlspecialchars($stopword_display) . "]</td>
                                    <td>[" . htmlspecialchars($stemming_display) . "]</td>
                                  </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7'>No data found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center">
            <?php
            // Pagination links
            for ($i = 1; $i <= $total_pages; $i++) {
                // Mengambil URL saat ini
                $current_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $parsed_url = parse_url($current_url);
                parse_str($parsed_url['query'] ?? '', $query_params);

                // Menjaga agar parameter page tetap ada
                $query_params['page'] = 'preprocessing'; // Menambahkan page=preprocessing
                $query_params['pagination'] = $i; // Menambahkan parameter pagination

                // Membuat URL untuk pagination dengan menambahkan parameter page yang sesuai
                $new_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                echo "<a href='$new_url' class='btn btn-link'>$i</a> ";
            }
            ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
    <script>
        document.getElementById('preprocessForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'block';
        });
    </script>
</body>

</html>