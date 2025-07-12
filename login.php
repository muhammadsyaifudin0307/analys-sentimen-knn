<?php
// Memulai session jika session belum dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Mendefinisikan konstanta untuk memeriksa dalam login_process.php
define('INCLUDED_FROM_LOGIN', true);
// Menyertakan logika otentikasi (proses login)
require_once 'src/auth/login_process.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SENTIMENKNN - Login</title>
    <!-- Menyertakan Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Menyertakan Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/log-regist.css">
</head>

<body>
    <!-- Menampilkan pesan sukses jika logout berhasil -->
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
            <!-- Header Login -->
            <div class="login-header">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <i class="bi bi-graph-up fs-1 me-2"></i>
                    <h3 class="m-0">SENTIMENKNN</h3>
                </div>
                <p class="mb-0">Sistem Analisis Sentimen</p>
            </div>

            <!-- Body Login -->
            <div class="login-body">
                <!-- Menampilkan pesan error jika login gagal -->
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

                <!-- Form Login -->
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                    <!-- Token CSRF untuk melindungi form dari serangan -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <!-- Input Username -->
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username"
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                required maxlength="50" autofocus>
                        </div>
                    </div>

                    <!-- Input Password -->
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

                    <!-- Tombol Submit untuk Login -->
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Login
                        </button>
                    </div>
                </form>

                <!-- Tautan untuk menuju halaman registrasi jika belum punya akun -->
                <div class="text-center mt-3">
                    <p class="mb-0">Belum memiliki akun? <a href="registration.php" class="text-decoration-none">Daftar di sini</a></p>
                </div>


            </div>
        </div>
    </div>

    <!-- Menyertakan Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script Kustom untuk Menangani Interaksi di Halaman -->
    <script src="assets/js/index.js"></script>
</body>

</html>