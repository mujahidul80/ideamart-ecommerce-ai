<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Post ID is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ScheduledPost WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(['success' => false, 'error' => 'Post not found.']);
        exit;
    }

    $stmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($settings['fbPageId']) || empty($settings['fbPageAccessToken'])) {
        echo json_encode(['success' => false, 'error' => 'Facebook API credentials missing.']);
        exit;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'ideamartbd.com';

    $prodStmt = $pdo->prepare("SELECT name, description, basePrice, imageUrl, slug FROM Product WHERE id = ?");
    $prodStmt->execute([$post['productId']]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || empty($product['imageUrl'])) {
        $pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE id = ?")->execute([$post['id']]);
        echo json_encode(['success' => false, 'error' => 'Product or its image is missing.']);
        exit;
    }

    $productLink = "https://" . $host . "/p/" . $product['slug'];
    $caption = $post['caption'];
    if (empty($caption)) {
        $caption = $product['name'] . "\nPrice: ৳" . $product['basePrice'] . "\n\n🛍️ Order yours here: " . $productLink;
    } elseif (strpos($caption, $productLink) === false) {
        $caption .= "\n\n🛍️ Order yours here: " . $productLink;
    }

    $imageUrls = explode(',', $product['imageUrl']);
    $finalImagesToPost = [];

    if (!empty($post['imageUrl'])) {
        $finalImagesToPost[] = $protocol . $host . $post['imageUrl'];
    } else {
        for ($i = 0; $i < min(4, count($imageUrls)); $i++) {
            $finalImagesToPost[] = $protocol . $host . $imageUrls[$i];
        }
    }

    $pageId = $settings['fbPageId'];
    $accessToken = $settings['fbPageAccessToken'];

    // Auto-resolve Page Access Token and Page ID if a User Token was provided
    if (!empty($accessToken)) {
        $chMe = curl_init("https://graph.facebook.com/v19.0/me?fields=id,name&metadata=1&access_token=" . urlencode($accessToken));
        curl_setopt($chMe, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chMe, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chMe, CURLOPT_TIMEOUT, 8);
        $resMe = curl_exec($chMe);
        curl_close($chMe);
        $meData = json_decode($resMe, true);

        $isPage = (isset($meData['metadata']['type']) && $meData['metadata']['type'] === 'page');

        // If entity is not a Page, resolve page token from /me/accounts
        if (isset($meData['id']) && !$isPage) {
            $chAccounts = curl_init("https://graph.facebook.com/v19.0/me/accounts?fields=id,name,access_token&access_token=" . urlencode($accessToken));
            curl_setopt($chAccounts, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chAccounts, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chAccounts, CURLOPT_TIMEOUT, 8);
            $resAccounts = curl_exec($chAccounts);
            curl_close($chAccounts);
            $accData = json_decode($resAccounts, true);

            if (!empty($accData['data']) && is_array($accData['data'])) {
                $matchedPage = null;
                foreach ($accData['data'] as $p) {
                    if ($p['id'] == $pageId) {
                        $matchedPage = $p;
                        break;
                    }
                }
                if (!$matchedPage) {
                    $matchedPage = $accData['data'][0];
                    $pageId = $matchedPage['id'];
                    try {
                        $pdo->prepare("UPDATE SettingNotification SET fbPageId = ?, fbPageAccessToken = ? WHERE id = 1")->execute([$pageId, $matchedPage['access_token']]);
                    } catch (Exception $e) {}
                }
                if ($matchedPage && !empty($matchedPage['access_token'])) {
                    $accessToken = $matchedPage['access_token'];
                }
            }
        }
    }

    $resData = null;

    if (count($finalImagesToPost) > 1) {
        // Multi-photo post
        $attachedMedia = [];
        foreach ($finalImagesToPost as $imgUrl) {
            $uploadUrl = "https://graph.facebook.com/v19.0/{$pageId}/photos";
            $uploadData = ['url' => $imgUrl, 'published' => 'false', 'access_token' => $accessToken];
            $ch = curl_init($uploadUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($uploadData)); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $photoData = json_decode(curl_exec($ch), true); curl_close($ch);
            if (isset($photoData['id'])) { $attachedMedia[] = ['media_fbid' => $photoData['id']]; }
        }
        if (!empty($attachedMedia)) {
            $feedUrl = "https://graph.facebook.com/v19.0/{$pageId}/feed";
            $feedData = ['message' => $caption, 'attached_media' => json_encode($attachedMedia), 'access_token' => $accessToken];
            $ch = curl_init($feedUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($feedData)); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $resData = json_decode(curl_exec($ch), true); curl_close($ch);
        }
    } else {
        // Single photo post
        $url = "https://graph.facebook.com/v19.0/{$pageId}/photos";
        $data = ['url' => $finalImagesToPost[0], 'message' => $caption, 'access_token' => $accessToken];
        $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resData = json_decode(curl_exec($ch), true); curl_close($ch);
    }

    if (isset($resData['id']) || isset($resData['post_id'])) {
        $pdo->prepare("UPDATE ScheduledPost SET status = 'PUBLISHED', fbPostId = ? WHERE id = ?")->execute([$resData['id'] ?? $resData['post_id'], $post['id']]);
        echo json_encode(['success' => true]);
    } else {
        $errorMsg = $resData['error']['message'] ?? 'Unknown API Error';
        $pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE id = ?")->execute([$post['id']]);
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>