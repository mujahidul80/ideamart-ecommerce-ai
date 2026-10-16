<?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'ideamart_db';

// Set default timezone for PHP
date_default_timezone_set('Asia/Dhaka');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default timezone for MySQL Database
    $pdo->exec("SET time_zone = '+06:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>