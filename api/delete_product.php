<?php
session_start();
error_reporting(0);
@ini_set('display_errors', 0);
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $ids = json_decode($_POST['product_ids'] ?? '[]', true);
    if (!empty($ids) && is_array($ids)) {
        $in = str_repeat('?,', count($ids) - 1) . '?';
        
        // Get product images to delete from server
        $stmt = $pdo->prepare("SELECT imageUrl FROM Product WHERE id IN ($in)");
        $stmt->execute($ids);
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($images as $imgList) {
            if (!empty($imgList)) {
                foreach (explode(',', $imgList) as $img) {
                    $img = trim($img);
                    $path = __DIR__ . '/..' . $img;
                    if (file_exists($path) && is_file($path)) { @unlink($path); }
                }
            }
        }
        
        // Get variant images to delete
        $vStmt = $pdo->prepare("SELECT imageUrl FROM Variant WHERE productId IN ($in)");
        $vStmt->execute($ids);
        $vImages = $vStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($vImages as $img) {
            if (!empty($img)) {
                $img = trim($img);
                $path = __DIR__ . '/..' . $img;
                if (file_exists($path) && is_file($path)) { @unlink($path); }
            }
        }
        
        $pdo->prepare("DELETE FROM Product WHERE id IN ($in)")->execute($ids);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'No products selected']);
    exit;
}

$id = $_POST['id'] ?? $_GET['id'] ?? null;
if ($id) {
    // Get product and variant images to delete from server
    $stmt = $pdo->prepare("SELECT imageUrl FROM Product WHERE id = ?");
    $stmt->execute([$id]);
    $productImage = $stmt->fetchColumn();
    
    if (!empty($productImage)) {
        foreach (explode(',', $productImage) as $img) {
            $img = trim($img);
            $filePath = __DIR__ . '/..' . $img;
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    $vStmt = $pdo->prepare("SELECT imageUrl FROM Variant WHERE productId = ?");
    $vStmt->execute([$id]);
    $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($variants as $v) {
        if (!empty($v['imageUrl'])) {
            $img = trim($v['imageUrl']);
            $vPath = __DIR__ . '/..' . $img;
            if (file_exists($vPath) && is_file($vPath)) {
                @unlink($vPath);
            }
        }
    }
    
    $pdo->prepare("DELETE FROM Product WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'No product ID provided']);
exit;