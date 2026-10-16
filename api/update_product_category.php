<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_category_update') {
    $productIds = json_decode($_POST['product_ids'], true);
    $categoryId = $_POST['category_id'] ?? '';

    if (empty($productIds) || !is_array($productIds)) {
        echo json_encode(['success' => false, 'error' => 'No products selected']);
        exit;
    }

    if (empty($categoryId)) {
        echo json_encode(['success' => false, 'error' => 'No category selected']);
        exit;
    }

    try {
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $params = array_merge([$categoryId], $productIds);
        
        $stmt = $pdo->prepare("UPDATE Product SET categoryId = ? WHERE id IN ($placeholders)");
        $stmt->execute($params);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>