<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    // Create table automatically if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `SettingAutoPost` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `postingTimes` TEXT NULL,
        `maxPostsPerDay` INT DEFAULT 3,
        `isActive` BOOLEAN DEFAULT TRUE,
        `updatedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM SettingAutoPost");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO SettingAutoPost (id, postingTimes, maxPostsPerDay, isActive) VALUES (1, '[\"10:00\", \"15:00\", \"20:00\"]', 3, 1)");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postingTimes = $_POST['postingTimes'] ?? '[]';
        $maxPostsPerDay = (int)($_POST['maxPostsPerDay'] ?? 3);
        $isActive = isset($_POST['isActive']) && $_POST['isActive'] == '1' ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE SettingAutoPost SET postingTimes = ?, maxPostsPerDay = ?, isActive = ? WHERE id = 1");
        $stmt->execute([$postingTimes, $maxPostsPerDay, $isActive]);
        
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>