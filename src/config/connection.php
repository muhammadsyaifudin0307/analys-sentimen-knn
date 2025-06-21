<?php
$host = 'localhost';
$username = 'root';
$password = '';
$db_name = 'svm_analays';

$conn = new mysqli($host, $username, $password, $db_name);


if ($conn->connect_error) {
    die("Koneksi Gagal" . $conn->connect_error);
}
