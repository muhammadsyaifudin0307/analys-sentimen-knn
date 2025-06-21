<?php


// Pesan yang akan ditampilkan
$error_message = "";
$success_message = "";
$toast_type = "";

// Process Excel import
if (isset($_POST['import_excel'])) {
    // 1. Verifikasi file diunggah dengan benar
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != 0) {
        $error_message = "Terjadi kesalahan saat mengunggah file Excel. Pilih file terlebih dahulu.";
        $toast_type = 'error';
    } else {
        // 2. Verifikasi ekstensi file
        $file_name = $_FILES['excel_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_ext != 'xlsx' && $file_ext != 'xls') {
            $error_message = "File harus berformat Excel (.xlsx atau .xls).";
            $toast_type = 'error';
        } else {
            // 3. Cek apakah autoloader tersedia
            if (!file_exists('vendor/autoload.php')) {
                $error_message = "Library PhpSpreadsheet tidak ditemukan. Pastikan Anda menginstal library ini menggunakan Composer.";
                $toast_type = 'error';
            } else {
                // 4. Load library PhpSpreadsheet
                require 'vendor/autoload.php';

                try {
                    // 5. Buat reader sesuai dengan format file
                    if ($file_ext == 'xlsx') {
                        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    } else {
                        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                    }

                    // 6. Baca file Excel
                    $spreadsheet = $reader->load($_FILES['excel_file']['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();

                    // 7. Cek jumlah baris
                    if (count($rows) <= 1) {
                        $error_message = "File Excel tidak berisi data yang cukup. Minimal harus ada baris header dan satu baris data.";
                        $toast_type = 'error';
                    } else {
                        // 8. Mulai proses import
                        // Skip header row
                        array_shift($rows);

                        $imported_count = 0;
                        $skipped_count = 0;

                        // 9. Mulai transaksi database
                        $conn->begin_transaction();

                        // 10. Persiapkan statement
                        $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");

                        if (!$stmt) {
                            throw new Exception("Error preparing statement: " . $conn->error);
                        }

                        // 11. Proses setiap baris data
                        foreach ($rows as $row) {
                            // Pastikan baris memiliki minimal 2 kolom
                            if (isset($row[0]) && isset($row[1])) {
                                $tweet = trim($row[0]);
                                $sentiment = strtolower(trim($row[1]));

                                // Validasi data
                                if (!empty($tweet) && ($sentiment == 'positive' || $sentiment == 'negative')) {
                                    if (!$stmt->bind_param("ss", $tweet, $sentiment)) {
                                        throw new Exception("Error binding parameters: " . $stmt->error);
                                    }

                                    if (!$stmt->execute()) {
                                        throw new Exception("Error executing statement: " . $stmt->error);
                                    }

                                    $imported_count++;
                                } else {
                                    $skipped_count++;
                                }
                            } else {
                                $skipped_count++;
                            }
                        }

                        // 12. Commit transaksi jika berhasil
                        $conn->commit();
                        $stmt->close();

                        // 13. Tampilkan pesan hasil
                        if ($imported_count > 0) {
                            $success_message = "$imported_count data berhasil diimpor dari Excel.";
                            if ($skipped_count > 0) {
                                $success_message .= " ($skipped_count data dilewati karena tidak valid)";
                            }
                            $toast_type = 'success';
                        } else {
                            $error_message = "Tidak ada data valid yang dapat diimpor dari file Excel.";
                            $toast_type = 'error';
                        }
                    }
                } catch (Exception $e) {
                    // 14. Rollback jika terjadi error
                    if ($conn->connect_errno == 0) {
                        $conn->rollback();
                    }

                    $error_message = "Terjadi kesalahan saat memproses file Excel: " . $e->getMessage();
                    $toast_type = 'error';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Data Excel</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Toastify CSS untuk notifikasi -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
</head>

<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4>Import Data dari Excel</h4>
                    </div>
                    <div class="card-body">
                        <!-- Form upload Excel -->
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="excel_file" class="form-label">Pilih File Excel (.xlsx atau .xls)</label>
                                <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx, .xls" required>
                                <div class="form-text">Format: Kolom 1 = Tweet, Kolom 2 = Sentiment (positive/negative)</div>
                            </div>
                            <button type="submit" name="import_excel" class="btn btn-primary">Import Data</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Toastify JS untuk notifikasi -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <?php if (!empty($error_message) || !empty($success_message)): ?>
        <script>
            Toastify({
                text: "<?php echo !empty($error_message) ? $error_message : $success_message; ?>",
                duration: 5000,
                close: true,
                gravity: "top",
                position: "right",
                backgroundColor: "<?php echo $toast_type == 'error' ? '#dc3545' : '#198754'; ?>",
            }).showToast();
        </script>
    <?php endif; ?>
</body>

</html>