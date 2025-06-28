
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