<?php
$process_done = false;  // Flag untuk memeriksa apakah proses selesai

// Cek jika tombol di-submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Mengambil data teks dari tabel 'dataset'
    $query = "SELECT id, tweet FROM svm_analays.dataset";
    $result = $conn->query($query);

    // Cek apakah dataset kosong
    if ($result->num_rows == 0) {
        // Menampilkan toast notifikasi dan kembali ke halaman yang sama
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

    // Kirim teks ke Flask untuk diproses
    $url = 'http://127.0.0.1:5000/api/preprocess';
    $data = json_encode(["texts" => $texts]);
    $response = file_get_contents($url, false, stream_context_create([
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => $data,
        ]
    ]));

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
        $tokenization = json_encode(explode(" ", $processed_text));
        $stopword = json_encode(explode(" ", $processed_text));
        $stemming = json_encode(explode(" ", $processed_text));

        $insert_query = $conn->prepare("INSERT INTO svm_analays.preprocessing (data_id, cleaning, casefolding, normalisasi, tokenization, stopword, stemming)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_query->bind_param('issssss', $data_id, $processed_text, $processed_text, $processed_text, $tokenization, $stopword, $stemming);
        if (!$insert_query->execute()) {
            echo "Error: " . $insert_query->error . "<br>";
        }
    }

    $process_done = true;
}

// Mengambil total jumlah data untuk pagination
$total_query = "SELECT COUNT(*) AS total FROM svm_analays.preprocessing";
$total_pages = ceil($conn->query($total_query)->fetch_assoc()['total'] / 10);

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
                    $query = "SELECT * FROM svm_analays.preprocessing LIMIT 10 OFFSET 0";
                    $result = $conn->query($query);
                    if ($result && $result->num_rows > 0) {
                        $counter = 1; // Start from D1
                        while ($row = $result->fetch_assoc()) {
                            $tokenization = json_decode($row['tokenization'], true);
                            $stopword = json_decode($row['stopword'], true);
                            $stemming = json_decode($row['stemming'], true);
                            $tokenization_display = is_array($tokenization) ? implode(", ", $tokenization) : "Invalid tokenization format";

                            // Dynamically generate names like D1, D2, D3, ...
                            $name = "D" . $counter++;

                            echo "<tr>
                                    <td>{$name}</td>
                                    <td>" . htmlspecialchars($row['cleaning']) . "</td>
                                    <td>" . htmlspecialchars($row['casefolding']) . "</td>
                                    <td>" . htmlspecialchars($row['normalisasi']) . "</td>
                                    <td>[" . htmlspecialchars($tokenization_display) . "]</td>
                                    <td>[" . htmlspecialchars(implode(", ", $stopword)) . "]</td>
                                    <td>[" . htmlspecialchars(implode(", ", $stemming)) . "]</td>
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
            <?php for ($i = 1; $i <= $total_pages; $i++) echo "<a href='?page=$i' class='btn btn-link'>$i</a> "; ?>
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