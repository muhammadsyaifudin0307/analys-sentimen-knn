<?php
// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define a constant to check in login_process.php
define('INCLUDED_FROM_LOGIN', true);
// Include the authentication logic
require_once 'src/auth/login_process.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SENTIMENSVM - Login</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- Success Message Toast -->
    <?php if (!empty($logout_success_message)) : ?>
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($logout_success_message); ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="login-card-wrapper">
        <div class="login-card">
            <!-- Login Header -->
            <div class="login-header">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <i class="bi bi-graph-up fs-1 me-2"></i>
                    <h3 class="m-0">SENTIMENSVM</h3>
                </div>
                <p class="mb-0">Sistem Analisis Sentimen</p>
            </div>

            <!-- Login Body -->
            <div class="login-body">
                <!-- Error Message Toast -->
                <?php if (!empty($error_message)): ?>
                    <div class="toast-container position-fixed top-0 end-0 p-3">
                        <div class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                            <div class="d-flex">
                                <div class="toast-body">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <?php echo htmlspecialchars($error_message); ?>
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <!-- Username Field -->
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username"
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                required maxlength="50" autofocus>
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                required maxlength="255">
                            <span class="input-group-text password-toggle" onclick="togglePassword()">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Login
                        </button>
                    </div>
                </form>

                <!-- Registration Link -->
                <div class="text-center mt-3">
                    <p class="mb-0">Belum memiliki akun? <a href="registration.php" class="text-decoration-none">Daftar di sini</a></p>
                </div>

                <!-- Footer -->
                <div class="text-center mt-4">
                    <small class="text-muted">© <?php echo date('Y'); ?> SENTIMENSVM - Sistem Analisis Sentimen</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JS -->
    <script src="assets/js/index.js"></script>
</body>

</html>