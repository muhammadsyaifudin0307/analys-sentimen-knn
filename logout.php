<?php
session_start();
session_unset();
session_destroy();

// Start session lagi untuk set notifikasi logout
session_start();
$_SESSION['logout_success'] = "Anda telah berhasil logout.";

header("Location: login.php");
exit();
