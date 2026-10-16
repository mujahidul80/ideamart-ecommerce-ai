<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $cartData = $data['cart'] ?? [];

    if (empty($cartData)) {
        echo json_encode(['success' => true, 'changed' => false, 'valid_cart' => []]);
        exit;
    }

    $validCart = [];
    $changed = false;

    foreach ($cartData as $item) {
        $productId = (int)($item['productId'] ?? $item['id']);
        $qty = intval($item['quantity']);
        $size = $item['size'] ?? 'Standard';
        $color = $item['color'] ?? 'Default';

        // Check if product exists in main catalog
        $priceStmt = $pdo->prepare("SELECT id FROM Product WHERE id = ?");
        $priceStmt->execute([$productId]);
        if (!$priceStmt->fetch()) {
            $changed = true;
            continue; // Product not found / deleted
        }

        // Check variant stock
        $stockStmt = $pdo->prepare("SELECT stock FROM Variant WHERE productId = ? AND size = ? AND color = ?");
        $stockStmt->execute([$productId, $size, $color]);
        $variantData = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$variantData || $variantData['stock'] <= 0) {
            $changed = true;
            continue; // Variant not found or Out of stock
        }

        if ($qty > $variantData['stock']) {
            $item['quantity'] = $variantData['stock']; // Reduce quantity to max available
            $changed = true;
        }

        $validCart[] = $item;
    }

    echo json_encode(['success' => true, 'changed' => $changed, 'valid_cart' => array_values($validCart)]);
    exit;
}
echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>