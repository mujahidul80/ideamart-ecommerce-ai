<?php
session_start();
error_reporting(0);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sms_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($phone)) {
        echo json_encode(['success' => false, 'error' => 'Mobile number is required.']);
        exit;
    }

    $testMessage = "Test SMS: Your SMS Gateway is configured and working successfully!";
    $result = sendDynamicSMS($pdo, $phone, $testMessage);

    if (ob_get_length()) ob_clean();
    echo json_encode($result);
}
