<?php
if (isset($_POST['set_active_tab'])) {
    $_SESSION['active_tab'] = $_POST['set_active_tab'];  // Menyimpan tab yang aktif di session
} else {
    // Tab default jika belum ada perhitungan
    $_SESSION['active_tab'] = isset($_SESSION['active_tab']) ? $_SESSION['active_tab'] : 'preprocessing';
}
$datasetCounts = getDatasetCounts($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Analysis Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card-hover:hover {
            transform: scale(1.03);
            transition: transform 0.3s ease;
            cursor: pointer;
        }

        .nav-tabs .nav-link {
            font-weight: 500;
            color: #495057;
        }

        .nav-tabs .nav-link.active {
            color: #4e54c8;
            border-bottom: 3px solid #4e54c8;
            background-color: transparent;
        }

        .tab-content {
            padding: 20px;
            border-left: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            border-bottom: 1px solid #dee2e6;
            border-radius: 0 0 8px 8px;
        }
    </style>
</head>

<body>
    <div class="px-3 py-4">
        <!-- Card Section -->
        <div class="row g-4 mb-4">
            <!-- Training Data Card -->
            <div class="col-md-6">
                <div class="card shadow-lg border-0 card-hover" style="background: linear-gradient(120deg, #4e54c8, #8f94fb); color: #f8f9fa; border-radius: 15px;">
                    <div class="card-body d-flex align-items-center py-4 px-5">
                        <div class="me-3">
                            <i class="bi bi-book-half fs-3" style="color: #f8f9fa;"></i>
                        </div>
                        <div class="text-start">
                            <h6 class="mb-1 fw-semibold">Jumlah Data Latih</h6>
                            <p class="fs-4 fw-bold mb-1"><?= $datasetCounts['data_latih']; ?></p>
                            <small>Digunakan untuk melatih model</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Data Card -->
            <div class="col-md-6">
                <div class="card shadow-lg border-0 card-hover" style="background: linear-gradient(120deg, #43e97b, #38f9d7); color: #212529; border-radius: 15px;">
                    <div class="card-body d-flex align-items-center py-4 px-5">
                        <div class="me-3">
                            <i class="bi bi-clipboard-data fs-3" style="color: #212529;"></i>
                        </div>
                        <div class="text-start">
                            <h6 class="mb-1 fw-semibold">Jumlah Data Uji</h6>
                            <p class="fs-4 fw-bold mb-1"><?= $datasetCounts['data_uji']; ?></p>
                            <small>Digunakan untuk menguji model</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Tab Section -->
        <div class="row">
            <div class="col-12">
                <?php
                // Menyertakan file tabs.php yang sudah diperbarui
                require_once $_SERVER['DOCUMENT_ROOT'] . '/SentimenSvm/src/component/tabs.php';
                ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>