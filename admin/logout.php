<?php
session_start();

require_once __DIR__ . '/../db.php';

if (isset($_SESSION['admin_id'])) {
    try {
        $pdo->prepare("UPDATE `User` SET remember_token = NULL WHERE id = ? AND role = 'admin'")->execute([$_SESSION['admin_id']]);
    } catch (Exception $e) {}
}

if (isset($_COOKIE['admin_remember'])) {
    setcookie('admin_remember', '', time() - 3600, '/');
}

// Unset admin session variables
unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);

header("Location: login");
exit;