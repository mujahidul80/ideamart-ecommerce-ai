<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$cartParam = $_GET['cart'] ?? '';
$cartData = json_decode($cartParam, true);

$productIds = [];

if (is_array($cartData)) {
    foreach ($cartData as $item) {
        $id = intval($item['id'] ?? 0);
        if ($id > 0) {
            if (!in_array($id, $productIds)) {
                $productIds[] = $id;
            }
        }
    }
} else {
    // Backward compatibility for generic calls
    $idsParam = explode(',', $_GET['ids'] ?? '');
    foreach (array_filter(array_map('intval', $idsParam)) as $id) {
        if (!in_array($id, $productIds)) {
            $productIds[] = $id;
        }
    }
}

if (empty($productIds)) {
    echo json_encode(['requiredAdvance' => 0]);
    exit;
}

$totalRequiredAdvance = 0;
$dropshopAdvanceAmount = 200; // Default fallback

try {
    $apiSettingsStmt = $pdo->query("SELECT dropshopAdvanceAmount FROM SettingApi LIMIT 1");
    $apiSettings = $apiSettingsStmt->fetch(PDO::FETCH_ASSOC);
    if ($apiSettings && !empty($apiSettings['dropshopAdvanceAmount'])) {
        $dropshopAdvanceAmount = (float)$apiSettings['dropshopAdvanceAmount'];
    }
} catch (Exception $e) {}

try {
    $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
    $prodStmt = $pdo->prepare("SELECT id, source_url, isAdvanceRequired, advanceAmount FROM Product WHERE id IN ($placeholders)");
    $prodStmt->execute($productIds);
    $productsInCart = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($productsInCart as $p) {
        if (!empty($p['isAdvanceRequired'])) { // Manual product with advance
            $amt = $p['advanceAmount'] > 0 ? $p['advanceAmount'] : $dropshopAdvanceAmount;
            $totalRequiredAdvance += (float)$amt;
        } elseif (!empty($p['source_url'])) { // Dropshop product fallback
            $totalRequiredAdvance += (float)$dropshopAdvanceAmount;
        }
    }
} catch (Exception $e) {
    // Fail silently, default to 0 advance
}

echo json_encode(['requiredAdvance' => $totalRequiredAdvance]);
?>