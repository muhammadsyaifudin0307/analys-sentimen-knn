<?php
// Prevent direct access to this file
if (!defined('INCLUDED_FROM_LOGIN')) {
    header("HTTP/1.0 403 Forbidden");
    exit('Direct access forbidden');
}

// Security: Prevent session fixation
if (!isset($_SESSION['last_regeneration'])) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
} else {
    if (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$logout_success_message = '';
$error_message = '';

// Handle logout success message
if (isset($_SESSION['logout_success'])) {
    $logout_success_message = $_SESSION['logout_success'];
    unset($_SESSION['logout_success']);
}

// Database connection
require_once 'src/config/connection.php';

// Anti-CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process login attempt
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Permintaan tidak valid. Silakan coba lagi.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        // Input validation
        if (empty($username) || empty($password)) {
            $error_message = "Username dan password harus diisi.";
        } elseif (strlen($username) > 50 || strlen($password) > 255) {
            $error_message = "Username atau password terlalu panjang.";
        } else {
            // Database lookup
            $stmt = $conn->prepare("SELECT id, username, password, email FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // Password verification
                if (password_verify($password, $user['password'])) {
                    // Check for too many login attempts
                    if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
                        $error_message = "Terlalu banyak percobaan login. Silakan tunggu beberapa saat.";
                    } else {
                        // Login successful
                        unset($_SESSION['login_attempts']);

                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['login_success'] = "Login berhasil! Selamat datang, {$user['username']}";

                        // Redirect to homepage
                        header("Location: index.php");
                        exit();
                    }
                } else {
                    // Failed login attempt
                    $_SESSION['login_attempts'] = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] + 1 : 1;
                    $error_message = "Username atau password salah.";
                }
            } else {
                // Username not found
                $error_message = "Username tidak ditemukan.";
            }

            $stmt->close();
        }
    }
}
