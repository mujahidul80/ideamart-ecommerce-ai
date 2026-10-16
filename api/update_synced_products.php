<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$items = json_decode($_POST['items'] ?? '[]', true);

if (empty($items)) {
    echo json_encode(['success' => false, 'error' => 'No items provided']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmtTemp = $pdo->prepare("UPDATE products SET original_price = ?, price = ?, stock = ? WHERE source_url = ?");
    $stmtLiveProd = $pdo->prepare("UPDATE Product SET buyPrice = ?, basePrice = ? WHERE source_url = ?");
    $stmtGetLiveIds = $pdo->prepare("SELECT id FROM Product WHERE source_url = ?");
    $stmtLiveVar = $pdo->prepare("UPDATE Variant SET stock = ? WHERE productId = ?");

    foreach ($items as $item) {
        $url = $item['source_url'];
        $price = floatval($item['price']);
        $original_price = floatval($item['original_price']);
        $stock = intval($item['qty']);

        // Update Temp Table
        $stmtTemp->execute([$original_price, $price, $stock, $url]);

        // Update Live Table
        $stmtLiveProd->execute([$original_price, $price, $url]);
        
        $stmtGetLiveIds->execute([$url]);
        $liveIds = $stmtGetLiveIds->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($liveIds as $lId) {
            $stmtLiveVar->execute([$stock, $lId]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>