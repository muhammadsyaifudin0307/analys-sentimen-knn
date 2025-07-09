<?php
session_start();

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Include database connection
require_once 'src/config/connection.php';

$success_message = '';
$error_message = '';
$username = '';
// Process registration form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = $conn->real_escape_string($_POST['email']);

    // Validate input
    $errors = [];

    // Check username
    if (empty($username)) {
        $errors[] = "Username wajib diisi";
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = "Username harus terdiri dari 3-50 karakter";
    }

    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Username sudah digunakan";
    }
    $stmt->close();

    // Check email
    if (empty($email)) {
        $errors[] = "Email wajib diisi";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Format email tidak valid";
    } elseif (strlen($email) > 100) {
        $errors[] = "Email terlalu panjang (maksimal 100 karakter)";
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Email sudah digunakan";
    }
    $stmt->close();

    // Check password
    if (empty($password)) {
        $errors[] = "Password wajib diisi";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password minimal 6 karakter";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Konfirmasi password tidak sesuai";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $username, $hashed_password, $email);

        if ($stmt->execute()) {
            $success_message = "Registrasi berhasil! Silakan <a href='login.php'>login</a> untuk melanjutkan.";

            // Clear form data after successful registration
            $username = $email = "";
        } else {
            $error_message = "Registrasi gagal: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SENTIMENKNN - Registrasi</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="registration-card">
        <div class="registration-header">
            <h3 class="m-0"><i class="bi bi-graph-up me-2"></i>SENTIMEKNN</h3>
            <p class="mb-0 mt-2">Registrasi Akun Baru</p>
        </div>
        <div class="registration-body">
            <?php if (!empty($success_message)): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <div class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo $success_message; ?>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <div class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo $error_message; ?>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>


            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                            value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
                            required>
                    </div>
                    <div class="form-text">Username harus terdiri dari 3-50 karakter.</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                            value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                            required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <span class="input-group-text password-toggle" onclick="togglePassword('password')">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                    <div class="form-text">Password minimal 6 karakter.</div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i> Daftar
                    </button>
                </div>

                <div class="text-center">
                    <p class="mb-0">Sudah memiliki akun? <a href="login.php" class="text-decoration-none">Login di sini</a></p>
                </div>
            </form>

            <div class="text-center mt-4">
                <small class="text-muted">© <?php echo date('Y'); ?> SENTIMENKNN - Sistem Analisis Sentimen</small>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Js Script -->
    <script src="assets/js/index.js"></script>

</body>

</html>