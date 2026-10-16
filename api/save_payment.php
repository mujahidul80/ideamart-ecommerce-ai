<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codEnabled = isset($_POST['codEnabled']) && $_POST['codEnabled'] == '1' ? 1 : 0;
    $bkashEnabled = isset($_POST['bkashEnabled']) && $_POST['bkashEnabled'] == '1' ? 1 : 0;
    
    $bkashAppKey = trim($_POST['bkashAppKey'] ?? '');
    $bkashAppSecret = trim($_POST['bkashAppSecret'] ?? '');
    $bkashUsername = trim($_POST['bkashUsername'] ?? '');
    $bkashPassword = trim($_POST['bkashPassword'] ?? '');
    $bkashBaseUrl = trim($_POST['bkashBaseUrl'] ?? 'https://tokenized.pay.bka.sh/v1.2.0-beta');

    try {
        $stmt = $pdo->prepare("UPDATE SettingPayment SET 
            codEnabled = ?, bkashEnabled = ?, 
            bkashAppKey = ?, bkashAppSecret = ?, 
            bkashUsername = ?, bkashPassword = ?, 
            bkashBaseUrl = ? WHERE id = 1");
        $stmt->execute([$codEnabled, $bkashEnabled, $bkashAppKey, $bkashAppSecret, $bkashUsername, $bkashPassword, $bkashBaseUrl]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}