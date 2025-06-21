<?php

// Call the functions to handle actions
addManualData($conn);
importData($conn);
deleteData($conn);
deleteAllData($conn);
editData($conn);
exportCsv($conn);

// Fetch dataset for pagination and stats
$page = isset($_GET['hal']) && is_numeric($_GET['hal']) && $_GET['hal'] > 0 ? (int)$_GET['hal'] : 1;
$records_per_page = 10;
$dataset = fetchDataset($conn, $page, $records_per_page);
$total_pages = getTotalPages($conn, $records_per_page);

// Fetch error and success messages
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
</head>

<body>
    <!-- Toast Notifications -->
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

    <!-- Action Buttons -->
    <div class="mt-4 mb-5">
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
                                        <td><?php echo  $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($row['tweet']); ?></td>
                                        <td>
                                            <?php if ($row['sentiment'] == 'positive'): ?>
                                                <span class="badge bg-success">Positive</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Negative</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>">Edit</button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $row['id']; ?>">Hapus</button>

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
                                        <?php echo "Belum ada data latih. Klik tombol 'Tambah Data' untuk menambahkan data baru."; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?hal=<?php echo $page - 1; ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?hal=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?hal=<?php echo $page + 1; ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>