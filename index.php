<?php
// config.php - Configuration and Database Connection
session_start();

require 'vendor/autoload.php';


// Authentication function
function is_logged_in()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Redirect function
function redirect_to_login()
{
    header("Location: login.php");
    exit();
}
$login_success_message = '';
if (isset($_SESSION['login_success'])) {
    $login_success_message = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}
// index.php
// Include config first to ensure database connection and session management
require_once 'src/config/connection.php';
require_once 'src/includes/functions.php';
// Check if user is logged in
if (!is_logged_in()) {
    redirect_to_login();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SENTIMENSVM - <?php echo isset($page) ? ucfirst($page) : 'Dashboard'; ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/tes.css">
</head>
<?php
require_once 'src/component/navbar.php'
?>

<body>

    <?php if (!empty($login_success_message)) : ?>
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($login_success_message); ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <!-- Content Area -->
    <div class="container-fluid p-4">
        <?php
        // Validate page to prevent directory traversal
        $valid_pages = ['dashboard', 'datalatih', 'datauji', 'analisa', 'tfidf', 'preprocessing'];
        $page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
        if (!in_array($page, $valid_pages)) {
            $page = 'dashboard';
        }

        // Include the page content
        include "src/pages/{$page}.php";
        ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
<script>
    const toastElList = [].slice.call(document.querySelectorAll('.toast'));
    toastElList.map(function(toastEl) {
        return new bootstrap.Toast(toastEl).show();
    });
</script>

</html>