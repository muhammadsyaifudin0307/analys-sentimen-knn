<?php
// Make sure database connection is established
// Add this at the beginning of your file

// Initialize variables
$error_message = '';
$success_message = '';
$toast_type = ''; // 'success' atau 'error'

// Process manual input
if (isset($_POST['add_manual'])) {
    $tweet = trim($_POST['tweet']);
    $sentiment = $_POST['sentiment'];

    if (empty($tweet) || empty($sentiment)) {
        $error_message = "Tweet dan sentimen harus diisi.";
        $toast_type = 'error';
    } else {
        $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");
        $stmt->bind_param("ss", $tweet, $sentiment);

        if ($stmt->execute()) {
            $success_message = "Data berhasil ditambahkan.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal menambahkan data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Process CSV import
if (isset($_POST['import_csv'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != 0) {
        $error_message = "Terjadi kesalahan saat mengunggah file CSV. Pilih file terlebih dahulu.";
        $toast_type = 'error';
    } else {
        $file_ext = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);

        if (strtolower($file_ext) != 'csv') {
            $error_message = "File harus berformat CSV.";
            $toast_type = 'error';
        } else {
            $file = fopen($_FILES['csv_file']['tmp_name'], 'r');

            // Verifikasi file dapat dibuka
            if (!$file) {
                $error_message = "Gagal membuka file CSV.";
                $toast_type = 'error';
            } else {
                // Skip header row
                fgetcsv($file);

                $imported_count = 0;
                $skipped_count = 0;
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");

                    while (($line = fgetcsv($file)) !== FALSE) {
                        if (count($line) >= 2) {
                            $tweet = trim($line[0]);
                            $sentiment = strtolower(trim($line[1]));

                            if (!empty($tweet) && ($sentiment == 'positive' || $sentiment == 'negative')) {
                                $stmt->bind_param("ss", $tweet, $sentiment);
                                $stmt->execute();
                                $imported_count++;
                            } else {
                                $skipped_count++;
                            }
                        } else {
                            $skipped_count++;
                        }
                    }

                    $conn->commit();
                    fclose($file);
                    $stmt->close();

                    if ($imported_count > 0) {
                        $success_message = "$imported_count data berhasil diimpor dari CSV.";
                        if ($skipped_count > 0) {
                            $success_message .= " ($skipped_count data dilewati karena tidak valid)";
                        }
                        $toast_type = 'success';
                    } else {
                        $error_message = "Tidak ada data valid yang dapat diimpor dari file CSV.";
                        $toast_type = 'error';
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Terjadi kesalahan saat mengimpor file CSV: " . $e->getMessage();
                    $toast_type = 'error';
                }
            }
        }
    }
}

// Process Excel import
// Pastikan file autoloader Composer sudah dimuat

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

if (isset($_POST['import_excel'])) {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != 0) {
        $error_message = "Terjadi kesalahan saat mengunggah file Excel. Pilih file terlebih dahulu.";
        $toast_type = 'error';
    } else {
        $file_ext = pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION);

        if ($file_ext !== 'xlsx' && $file_ext !== 'xls') {
            $error_message = "File harus berformat Excel (.xlsx atau .xls).";
            $toast_type = 'error';
        } else {
            if (!file_exists($_FILES['excel_file']['tmp_name'])) {
                $error_message = "File tidak ditemukan di server.";
                $toast_type = 'error';
            } else {
                try {
                    $reader = ($file_ext === 'xlsx') ? new Xlsx() : new Xls();
                    $spreadsheet = $reader->load($_FILES['excel_file']['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();

                    if (count($rows) <= 1) {
                        $error_message = "File Excel tidak berisi data yang cukup.";
                        $toast_type = 'error';
                    } else {
                        array_shift($rows); // skip header
                        $imported_count = 0;
                        $skipped_count = 0;

                        $conn->begin_transaction();
                        $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");

                        foreach ($rows as $row) {
                            if (count($row) >= 2) {
                                $tweet = trim($row[0]);
                                $sentiment = strtolower(trim($row[1]));

                                if (!empty($tweet) && in_array($sentiment, ['positive', 'negative'])) {
                                    $stmt->bind_param("ss", $tweet, $sentiment);
                                    $stmt->execute();
                                    $imported_count++;
                                } else {
                                    $skipped_count++;
                                }
                            } else {
                                $skipped_count++;
                            }
                        }

                        $conn->commit();
                        $stmt->close();

                        if ($imported_count > 0) {
                            $success_message = "$imported_count data berhasil diimpor.";
                            if ($skipped_count > 0) {
                                $success_message .= " ($skipped_count dilewati)";
                            }
                            $toast_type = 'success';
                        } else {
                            $error_message = "Tidak ada data valid yang berhasil diimpor.";
                            $toast_type = 'error';
                        }
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Gagal membaca file Excel: " . $e->getMessage();
                    $toast_type = 'error';
                }
            }
        }
    }
}
// Delete data handling using POST instead of GET for better security
if (isset($_POST['delete_id']) && is_numeric($_POST['delete_id'])) {
    $id = $_POST['delete_id'];

    $stmt = $conn->prepare("DELETE FROM dataset WHERE id = ? AND type = 'data_latih'");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $success_message = "Data berhasil dihapus.";
        $toast_type = 'success';
    } else {
        $error_message = "Gagal menghapus data: " . $conn->error;
        $toast_type = 'error';
    }

    $stmt->close();
}

// Edit data
if (isset($_POST['edit_data'])) {
    $id = $_POST['edit_id'];
    $tweet = trim($_POST['edit_tweet']);
    $sentiment = $_POST['edit_sentiment'];

    if (empty($tweet) || empty($sentiment)) {
        $error_message = "Tweet dan sentimen harus diisi.";
        $toast_type = 'error';
    } else {
        $stmt = $conn->prepare("UPDATE dataset SET tweet = ?, sentiment = ? WHERE id = ? AND type = 'data_latih'");
        $stmt->bind_param("ssi", $tweet, $sentiment, $id);

        if ($stmt->execute()) {
            $success_message = "Data berhasil diperbarui.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal memperbarui data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Export data to CSV
if (isset($_POST['export_csv'])) {
    $stmt = $conn->prepare("SELECT tweet, sentiment FROM dataset WHERE type = 'data_latih' ORDER BY id");
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="data_latih_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tweet', 'Sentiment']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['tweet'], $row['sentiment']]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

// Fetch dataset statistics - Count total only
$dataset_stats = [
    'total' => 0
];

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'dataset'");
if ($table_check->num_rows == 0) {
    $error_message = "Tabel 'dataset' tidak ditemukan. Buat tabel terlebih dahulu.";
    $toast_type = 'error';
}

// $result = $conn->query("SELECT COUNT(*) AS total FROM dataset WHERE type = 'data_latih'");
// if ($result && $row = $result->fetch_assoc()) {
// $dataset_stats['total'] = $row['total'];
// }

// Fetch dataset with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Pastikan offset tidak negatif
if ($offset < 0) $offset = 0;

// Perbaikan query untuk dataset
$sql = "SELECT id, tweet, sentiment FROM dataset WHERE type = 'data_latih' ORDER BY id DESC LIMIT $offset, $records_per_page";
$result = $conn->query($sql);

if (!$result) {
    $error_message = "Error query: " . $conn->error;
    $toast_type = 'error';
    $dataset = [];
} else {
    $dataset = $result->fetch_all(MYSQLI_ASSOC);
}

// Get total pages for pagination
$result = $conn->query("SELECT COUNT(*) AS count FROM dataset WHERE type = 'data_latih'");
if ($result) {
    $row = $result->fetch_assoc();
    $total_records = $row['count'];
    $total_pages = ceil($total_records / $records_per_page);
} else {
    $total_pages = 0;
    $error_message = "Error counting records: " . $conn->error;
    $toast_type = 'error';
}
?>

<style>
    .dataset-table {
        margin-top: 20px;
    }

    .input-section {
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        padding: 20px;
        margin-bottom: 20px;
    }

    .input-section h4 {
        margin-bottom: 15px;
    }

    /* Toast styling */
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1050;
    }

    .toast {
        min-width: 300px;
    }

    /* Table column width adjustments */
    .table-responsive {
        overflow-x: auto;
    }

    .tweet-column {
        width: 70%;
        max-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: normal;
        word-wrap: break-word;
    }

    .sentiment-column {
        width: 15%;
        text-align: center;
    }

    .action-column {
        width: 15%;
        white-space: nowrap;
    }

    .number-column {
        width: 5%;
        text-align: center;
    }

    /* Action button container */
    .action-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }

    /* File upload custom styles */
    .file-upload-container {
        position: relative;
    }

    .file-upload-container input[type="file"] {
        display: block;
    }

    .file-upload-label {
        margin-bottom: 8px;
        font-weight: 500;
    }

    .file-format-hint {
        display: block;
        margin-top: 5px;
        font-size: 0.875rem;
        color: #6c757d;
    }
</style>
</head>

<body>
    <!-- Toast Container -->
    <div class="toast-container">
        <?php if (!empty($success_message) && $toast_type == 'success'): ?>
            <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo $success_message; ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message) && $toast_type == 'error'): ?>
            <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <?php echo $error_message; ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-4 mb-5">
        <!-- Action Buttons -->
        <div class="action-buttons">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDataModal">
                <i class="bi bi-plus-circle"></i> Tambah Data
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importDataModal">
                <i class="bi bi-file-earmark-arrow-up"></i> Import Data
            </button>
            <form method="post" action="" class="d-inline">
                <button type="submit" name="export_csv" class="btn btn-secondary" <?php echo ($dataset_stats['total'] == 0) ?: ''; ?>>
                    <i class="bi bi-download"></i> Export ke CSV
                </button>
            </form>
        </div>

        <!-- Add Data Modal -->
        <div class="modal fade" id="addDataModal" tabindex="-1" aria-labelledby="addDataModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addDataModalLabel">Tambah Data Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" action="">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="tweet" class="form-label">Tweet:</label>
                                <textarea class="form-control" id="tweet" name="tweet" rows="4" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="sentiment" class="form-label">Sentimen:</label>
                                <select class="form-select" id="sentiment" name="sentiment" required>
                                    <option value="" selected disabled>Pilih sentimen</option>
                                    <option value="positive">Positive</option>
                                    <option value="negative">Negative</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="add_manual" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Import Data Modal -->
        <div class="modal fade" id="importDataModal" tabindex="-1" aria-labelledby="importDataModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="importDataModalLabel">Import Data</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-primary text-white">
                                        <i class="bi bi-filetype-csv"></i> Import dari CSV
                                    </div>
                                    <div class="card-body">
                                        <form method="post" action="" enctype="multipart/form-data" id="csvForm">
                                            <div class="file-upload-container mb-3">
                                                <label for="csv_file" class="file-upload-label">Pilih file CSV:</label>
                                                <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                                                <span class="file-format-hint">Format: <code>tweet,sentiment</code> (sentiment harus 'positive' atau 'negative')</span>
                                            </div>
                                            <div class="d-grid">
                                                <button type="submit" name="import_csv" class="btn btn-primary">
                                                    <i class="bi bi-upload"></i> Upload CSV
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-success text-white">
                                        <i class="bi bi-file-earmark-excel"></i> Import dari Excel
                                    </div>
                                    <div class="card-body">
                                        <form method="post" action="" enctype="multipart/form-data" id="excelForm">
                                            <div class="file-upload-container mb-3">
                                                <label for="excel_file" class="file-upload-label">Pilih file Excel:</label>
                                                <input class="form-control" type="file" id="excel_file" name="excel_file" accept=".xlsx, .xls" required>
                                                <span class="file-format-hint">Format: Kolom A untuk tweet, Kolom B untuk sentiment (baris pertama adalah header)</span>
                                            </div>
                                            <div class="d-grid">
                                                <button type="submit" name="import_excel" class="btn btn-success">
                                                    <i class="bi bi-upload"></i> Upload Excel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle-fill"></i> <strong>Petunjuk:</strong>
                            <ul class="mb-0">
                                <li>File CSV/Excel harus berisi kolom <strong>tweet</strong> dan <strong>sentiment</strong></li>
                                <li>Nilai sentiment harus berupa <strong>positive</strong> atau <strong>negative</strong> (case insensitive)</li>
                                <li>Baris pertama harus berisi nama header (akan dilewati saat import)</li>
                                <li>Ukuran file maksimum: 5MB</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tampilkan Dataset -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h3 class="card-title mb-0">Dataset Data Latih </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th class="number-column">No.</th>
                                <th class="tweet-column">Tweet</th>
                                <th class="sentiment-column">Sentimen</th>
                                <th class="action-column">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($dataset) > 0): ?>
                                <?php foreach ($dataset as $index => $row): ?>
                                    <tr>
                                        <td class="number-column"><?php echo $offset + $index + 1; ?></td>
                                        <td class="tweet-column"><?php echo htmlspecialchars($row['tweet']); ?></td>
                                        <td class="sentiment-column">
                                            <?php if ($row['sentiment'] == 'positive'): ?>
                                                <span class="badge bg-success">Positive</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Negative</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="action-column">
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $row['id']; ?>">
                                                <i class="bi bi-trash"></i> Hapus
                                            </button>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="editModalLabel<?php echo $row['id']; ?>">Edit Data</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="post" action="">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="edit_id" value="<?php echo $row['id']; ?>">
                                                                <div class="mb-3">
                                                                    <label for="edit_tweet<?php echo $row['id']; ?>" class="form-label">Tweet:</label>
                                                                    <textarea class="form-control" id="edit_tweet<?php echo $row['id']; ?>" name="edit_tweet" rows="3" required><?php echo htmlspecialchars($row['tweet']); ?></textarea>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="edit_sentiment<?php echo $row['id']; ?>" class="form-label">Sentimen:</label>
                                                                    <select class="form-select" id="edit_sentiment<?php echo $row['id']; ?>" name="edit_sentiment" required>
                                                                        <option value="positive" <?php echo ($row['sentiment'] == 'positive') ? 'selected' : ''; ?>>Positive</option>
                                                                        <option value="negative" <?php echo ($row['sentiment'] == 'negative') ? 'selected' : ''; ?>>Negative</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                <button type="submit" name="edit_data" class="btn btn-primary">Simpan Perubahan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Delete Modal -->
                                            <div class="modal fade" id="deleteModal<?php echo $row['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="deleteModalLabel<?php echo $row['id']; ?>">Konfirmasi Hapus</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Apakah Anda yakin ingin menghapus data ini?</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <form method="post" action="">
                                                                <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                <button type="submit" class="btn btn-danger">Hapus</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">
                                        <?php
                                        if ($dataset_stats['total'] > 0) {
                                            echo "Tidak ada data pada halaman ini. Coba halaman lain.";
                                        } else {
                                            echo "Belum ada data latih. Klik tombol 'Tambah Data' untuk menambahkan data baru.";
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php

                    $current_page = isset($_GET['hal']) ? (int)$_GET['hal'] : 1;
                    if ($current_page < 1) $current_page = 1;

                    // Pagination logic...
                    $items_per_page = 10; // contoh
                    $offset = ($current_page - 1) * $items_per_page;

                    if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=datalatih&hal=<?php echo $current_page - 1; ?>" tabindex="-1" aria-disabled="<?php echo ($current_page <= 1) ? 'true' : 'false'; ?>">Previous</a>
                                </li>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($current_page == $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=datalatih&hal=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=datalatih&hal=<?php echo $current_page + 1; ?>" aria-disabled="<?php echo ($current_page >= $total_pages) ? 'true' : 'false'; ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inisialisasi toast
        document.addEventListener('DOMContentLoaded', function() {
            // Show toast notifications
            var toastElList = [].slice.call(document.querySelectorAll('.toast'));
            var toastList = toastElList.map(function(toastEl) {
                var toast = new bootstrap.Toast(toastEl, {
                    autohide: true,
                    delay: 5000
                });
                toast.show();
                return toast;
            });

            // File upload validations
            document.getElementById('csv_file').addEventListener('change', function(e) {
                validateFileSize(this, 5); // 5MB limit
            });

            document.getElementById('excel_file').addEventListener('change', function(e) {
                validateFileSize(this, 5); // 5MB limit
            });

            // Form submission
            document.getElementById('csvForm').addEventListener('submit', function(e) {
                if (!validateFileInput('csv_file')) {
                    e.preventDefault();
                    alert('Pilih file CSV terlebih dahulu!');
                }
            });

            document.getElementById('excelForm').addEventListener('submit', function(e) {
                if (!validateFileInput('excel_file')) {
                    e.preventDefault();
                    alert('Pilih file Excel terlebih dahulu!');
                }
            });
        });

        // Validate file size
        function validateFileSize(input, maxSize) {
            if (input.files.length > 0) {
                const fileSize = input.files[0].size / 1024 / 1024; // in MB
                if (fileSize > maxSize) {
                    alert(`Ukuran file terlalu besar! Maksimum ${maxSize}MB.`);
                    input.value = ''; // Clear the input
                }
            }
        }

        // Validate if file is selected
        function validateFileInput(inputId) {
            const input = document.getElementById(inputId);
            return input.files && input.files.length > 0;
        }
    </script>
</body>