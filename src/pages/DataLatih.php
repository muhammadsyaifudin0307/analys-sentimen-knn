<?php

// Fungsi untuk menambah, mengimpor, menghapus, mengedit, dan mengekspor data latih
addManualData($conn);
importData($conn);
deleteData($conn);
deleteAllData($conn);
editData($conn);
exportCsv($conn);

// Mengatur paginasi dan statistik
$page = isset($_GET['pagination']) && is_numeric($_GET['pagination']) && $_GET['pagination'] > 0 ? (int)$_GET['pagination'] : 1;
$records_per_page = 10;
$dataset = fetchDataset($conn, $page, $records_per_page);
$total_pages = getTotalPages($conn, $records_per_page);

// Mengambil jumlah total data untuk ditampilkan
$total_query = "SELECT COUNT(*) AS total FROM dataset WHERE type = 'data_latih'";
$total_result = $conn->query($total_query);
$total_count = $total_result ? $total_result->fetch_assoc()['total'] : 0;

// Menampilkan pesan error dan sukses
global $error_message, $success_message, $toast_type;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Latih</title>
    <!-- Include Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body>
    <!-- Toast Notifications -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
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

    <div class="container-fluid mt-4">
        <!-- Action Buttons -->
        <div class="mb-5">
            <div class="d-flex justify-content-between mb-4">
                <div class="button-left">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDataModal">
                        <i class="bi bi-plus-circle"></i> Tambah Data
                    </button>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importDataModal">
                        <i class="bi bi-file-earmark-arrow-up"></i> Import Data
                    </button>
                </div>
                <div class="button-right">
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal">
                        <i class="bi bi-trash"></i> Hapus Semua Data
                    </button>
                </div>
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
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="importDataModalLabel">Import Data (CSV atau Excel)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" enctype="multipart/form-data" action="">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="file" class="form-label">Pilih File:</label>
                                    <input type="file" class="form-control" id="file" name="file" accept=".csv, .xlsx, .xls" required>
                                    <span class="form-text">Format: CSV atau Excel</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" name="import_file" class="btn btn-info">Upload</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Confirm Delete All Modal -->
            <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmDeleteModalLabel">Konfirmasi Penghapusan</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus semua data?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <form method="post" action="">
                                <button type="submit" name="delete_all" class="btn btn-danger">Hapus Semua Data</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3 class="card-title">Dataset Data Latih</h3>
                </div>
                <div class="card-body">
                    <!-- Data Info -->
                    <div class="alert alert-info text-center mb-4">
                        Total Data: <strong><?php echo $total_count; ?></strong>
                        <?php if ($total_pages > 1): ?>
                            | Page: <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Tweet</th>
                                    <th>Sentimen</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($dataset) > 0): ?>
                                    <?php foreach ($dataset as $index => $row): ?>
                                        <tr>
                                            <td><?php echo (($page - 1) * $records_per_page) + $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($row['tweet']); ?></td>
                                            <td>
                                                <?php if ($row['sentiment'] == 'positive'): ?>
                                                    <span class="badge bg-success">Positive</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Negative</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>

                                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $row['id']; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Hapus">
                                                    <i class="bi bi-trash"></i>
                                                </button>

                                                <!-- Edit Modal -->
                                                <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Data</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <form method="post" action="">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="edit_id" value="<?php echo $row['id']; ?>">
                                                                    <div class="mb-3">
                                                                        <label for="edit_tweet" class="form-label">Tweet:</label>
                                                                        <textarea class="form-control" name="edit_tweet" rows="3" required><?php echo htmlspecialchars($row['tweet']); ?></textarea>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="edit_sentiment" class="form-label">Sentimen:</label>
                                                                        <select class="form-select" name="edit_sentiment" required>
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
                                                                <h5 class="modal-title">Konfirmasi Hapus</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Apakah Anda yakin ingin menghapus data ini?</p>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <form method="post" action="">
                                                                    <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                    <button type="submit" name="delete_data" class="btn btn-danger">Hapus</button>
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
                                            <?php echo "Belum ada data latih. Klik tombol 'Tambah Data' untuk menambahkan data baru."; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-container d-flex justify-content-center">
                                <nav aria-label="Page navigation">
                                    <ul class="pagination">
                                        <?php
                                        // Get current URL and parse it
                                        $current_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                                        $parsed_url = parse_url($current_url);
                                        parse_str($parsed_url['query'] ?? '', $query_params);

                                        // Previous button
                                        if ($page > 1) {
                                            $query_params['pagination'] = $page - 1;
                                            $prev_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                            echo "<li class='page-item'><a class='page-link' href='$prev_url'>Previous</a></li>";
                                        } else {
                                            echo "<li class='page-item disabled'><span class='page-link'>Previous</span></li>";
                                        }

                                        // Page numbers with smart pagination
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);

                                        // Show first page if not in range
                                        if ($start_page > 1) {
                                            $query_params['pagination'] = 1;
                                            $first_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                            echo "<li class='page-item'><a class='page-link' href='$first_url'>1</a></li>";
                                            if ($start_page > 2) {
                                                echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                                            }
                                        }

                                        // Show page numbers in range
                                        for ($i = $start_page; $i <= $end_page; $i++) {
                                            $query_params['pagination'] = $i;
                                            $page_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                            $active_class = ($i == $page) ? 'active' : '';
                                            echo "<li class='page-item $active_class'><a class='page-link' href='$page_url'>$i</a></li>";
                                        }

                                        // Show last page if not in range
                                        if ($end_page < $total_pages) {
                                            if ($end_page < $total_pages - 1) {
                                                echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                                            }
                                            $query_params['pagination'] = $total_pages;
                                            $last_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                            echo "<li class='page-item'><a class='page-link' href='$last_url'>$total_pages</a></li>";
                                        }

                                        // Next button
                                        if ($page < $total_pages) {
                                            $query_params['pagination'] = $page + 1;
                                            $next_url = $parsed_url['path'] . '?' . http_build_query($query_params);
                                            echo "<li class='page-item'><a class='page-link' href='$next_url'>Next</a></li>";
                                        } else {
                                            echo "<li class='page-item disabled'><span class='page-link'>Next</span></li>";
                                        }
                                        ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show toasts
        document.addEventListener('DOMContentLoaded', function() {
            var toastElList = [].slice.call(document.querySelectorAll('.toast'));
            var toastList = toastElList.map(function(toastEl) {
                return new bootstrap.Toast(toastEl);
            });
            toastList.forEach(toast => toast.show());
        });
    </script>
</body>

</html>