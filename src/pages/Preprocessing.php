<?php

// Menyertakan koneksi database
$process_done = false;

// Jumlah data per halaman
$limit = 10;

// Menentukan halaman yang diminta (default ke halaman 1)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// Cegah nilai negatif untuk halaman
if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $limit;

// Jika tombol di-submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Mengambil semua data teks dari tabel 'dataset'
    $query = "SELECT id, tweet FROM svm_analays.dataset";
    $result = $conn->query($query);

    $texts = [];
    $data_ids = [];

    while ($row = $result->fetch_assoc()) {
        $texts[] = $row['tweet'];  // Menyimpan teks tweet
        $data_ids[] = $row['id'];  // Menyimpan ID data untuk referensi
    }

    // Jika tidak ada data untuk diproses, beri peringatan dan hentikan eksekusi
    if (empty($texts)) {
        echo "No texts available for preprocessing.";
        exit;
    }

    // Kirim teks ke Flask untuk diproses
    $url = 'http://127.0.0.1:5000/preprocess';  // URL Flask API

    $data = array("texts" => $texts);  // Kirim semua teks dalam array
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),  // Mengirimkan teks dalam format JSON
        ),
    );
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    // Periksa apakah ada response dari Flask
    if ($response === FALSE) {
        echo "Error: Failed to connect to Flask API.";
        exit;
    }

    $response_data = json_decode($response, true);

    // Periksa apakah response valid
    if ($response_data === NULL || !isset($response_data['processed_texts'])) {
        echo "Error: Invalid response from Flask.";
        var_dump($response);  // Debugging: melihat response yang diterima
        exit;
    }

    // Menghapus data lama di tabel 'preprocessing' sebelum menyimpan data baru
    $delete_query = "DELETE FROM svm_analays.preprocessing";
    $conn->query($delete_query);  // Menghapus semua data yang ada di tabel preprocessing

    // Menyimpan hasil preprocessing ke dalam tabel 'preprocessing'
    foreach ($response_data['processed_texts'] as $index => $processed_text) {
        $data_id = $data_ids[$index];
        $cleaning = $processed_text['cleaning'];
        $casefolding = $processed_text['casefolding'];
        $normalisasi = $processed_text['normalisasi'];
        $tokenization = json_encode($processed_text['tokenization']); // Menyimpan tokenization sebagai JSON string
        $stopword = json_encode($processed_text['stopword']); // Menyimpan stopword sebagai JSON string
        $stemming = json_encode($processed_text['stemming']); // Menyimpan stemming sebagai JSON string

        // Menggunakan prepared statement untuk menghindari SQL injection
        $insert_query = $conn->prepare("INSERT INTO svm_analays.preprocessing 
                         (data_id, cleaning, casefolding, normalisasi, tokenization, stopword, stemming)
                         VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_query->bind_param('issssss', $data_id, $cleaning, $casefolding, $normalisasi, $tokenization, $stopword, $stemming);

        if ($insert_query->execute() !== TRUE) {
            echo "Error: " . $insert_query->error . "<br>";
        }
    }

    // Tandai bahwa proses telah selesai dan simpan di session
    $_SESSION['process_done'] = true;
}

// Mengambil total jumlah data untuk pagination
$total_query = "SELECT COUNT(*) AS total FROM svm_analays.preprocessing";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preprocessing Example</title>
    <!-- Link to Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-KyZXEJgD2D0cG3b6TO5ioQ6N2Wj3v2/3OZ92Faznt7T0DYYm/uc0N4eES2xOB5gG" crossorigin="anonymous">
    <style>
        /* Styling untuk loading spinner */
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

        .table thead {
            background-color: #007bff;
            color: white;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f9f9f9;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }

        .container {
            margin-top: 50px;
        }

        h1 {
            font-size: 36px;
            font-weight: bold;
            color: #4CAF50;
        }

        h2 {
            font-size: 28px;
            color: #007bff;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1 class="text-center">Preprocessing Texts</h1>

        <!-- Tombol untuk menjalankan proses preprocessing -->
        <form method="post" action="" id="preprocessForm" class="text-center mb-4">
            <button type="submit" class="btn btn-success">Preprocess All Texts</button>
        </form>

        <!-- Loading Indicator -->
        <div id="loading"></div> <!-- Indikator loading yang akan ditampilkan selama proses berlangsung -->

        <?php
        // Pastikan data tidak null dan merupakan array sebelum melakukan foreach
        if (isset($_SESSION['process_done']) && $_SESSION['process_done']) {
            echo "<h2 class='text-center mt-4'>Processed Texts:</h2>";
            echo "<div class='table-responsive'>
                    <table class='table table-striped table-hover'>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Cleaning</th>
                            <th>Casefolding</th>
                            <th>Normalization</th>
                            <th>Tokenization</th>
                            <th>Stopword</th>
                            <th>Stemming</th>
                        </tr>
                    </thead>
                    <tbody>";

            // Menampilkan hasil preprocessing yang telah disimpan
            $query = "SELECT * FROM svm_analays.preprocessing LIMIT $limit OFFSET $offset";
            $result = $conn->query($query);

            // Pastikan query mengembalikan data yang valid
            if ($result && $result->num_rows > 0) {
                // Jika ada data, lakukan iterasi
                while ($row = $result->fetch_assoc()) {
                    // Mengambil tokenization dari database (misalnya dalam bentuk JSON)
                    $tokenization = json_decode($row['tokenization'], true);  // Mengubah string JSON menjadi array
                    $stopword = json_decode($row['stopword'], true); // Mengubah string JSON menjadi array
                    $stemming = json_decode($row['stemming'], true); // Mengubah string JSON menjadi array

                    // Pastikan tokenization adalah array sebelum menggunakan implode()
                    if (is_array($tokenization)) {
                        $tokenization_display = implode(", ", $tokenization);
                    } else {
                        $tokenization_display = "Invalid tokenization format";
                    }

                    // Menampilkan hasil
                    echo "<tr>
                            <td>" . $row['data_id'] . "</td>
                            <td>" . htmlspecialchars($row['cleaning']) . "</td>
                            <td>" . htmlspecialchars($row['casefolding']) . "</td>
                            <td>" . htmlspecialchars($row['normalisasi']) . "</td>
                            <td>" . htmlspecialchars($tokenization_display) . "</td>
                            <td>" . htmlspecialchars(implode(", ", $stopword)) . "</td>
                            <td>" . htmlspecialchars(implode(", ", $stemming)) . "</td>
                          </tr>";
                }
            } else {
                // Jika query tidak mengembalikan data
                echo "<tr><td colspan='7'>No data found.</td></tr>";
            }

            echo "</tbody></table></div>";

            // Pagination Links
            echo "<div class='d-flex justify-content-center'>";
            for ($i = 1; $i <= $total_pages; $i++) {
                echo "<a href='?page=$i' class='btn btn-link'>$i</a> ";
            }
            echo "</div>";
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>

    <script>
        // Menangani form submit untuk menampilkan loading indicator
        document.getElementById('preprocessForm').addEventListener('submit', function() {
            // Tampilkan loading spinner
            document.getElementById('loading').style.display = 'block';
        });
    </script>
</body>

</html>