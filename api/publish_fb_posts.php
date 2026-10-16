<?php
/**
 * Scheduled Facebook Auto-Poster
 * This script should be triggered by a Cron Job every 5 minutes.
 */
require_once __DIR__ . '/../db.php';
date_default_timezone_set('Asia/Dhaka');

try { $pdo->exec("ALTER TABLE `SettingNotification` ADD COLUMN `lastCronRun` DATETIME NULL"); } catch (Exception $e) {}
$nowDate = date('Y-m-d H:i:s');
$pdo->prepare("UPDATE `SettingNotification` SET `lastCronRun` = ? WHERE id = 1")->execute([$nowDate]);

// Mark missed posts as FAILED if they are older than 30 minutes (to prevent burst posting)
$missedDate = date('Y-m-d H:i:s', strtotime('-30 minutes'));
$pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE status = 'PENDING' AND publishAt < ?")->execute([$missedDate]);

// Fetch pending posts that are due for publishing
$stmt = $pdo->prepare("SELECT * FROM ScheduledPost WHERE status = 'PENDING' AND publishAt <= ? ORDER BY publishAt ASC LIMIT 1"); // Strictly limit to 1 per run to prevent burst posting at the same time
$stmt->execute([$nowDate]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($posts)) {
    echo "No pending posts to publish at this time.\n";
    exit;
}

$stmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (isset($settings['fbAutoPosterEnabled']) && empty($settings['fbAutoPosterEnabled'])) {
    $pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE status = 'PENDING' AND publishAt <= ?")->execute([$nowDate]);
    echo "Facebook Auto-Poster is globally disabled in settings. Missed posts marked as FAILED.\n";
    exit;
}

if (empty($settings['fbPageId']) || empty($settings['fbPageAccessToken'])) {
    echo "Facebook API credentials missing.\n";
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'ideamartbd.com'; 

foreach ($posts as $post) {
    $prodStmt = $pdo->prepare("SELECT name, description, basePrice, imageUrl, slug FROM Product WHERE id = ?");
    $prodStmt->execute([$post['productId']]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || empty($product['imageUrl'])) {
        $pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE id = ?")->execute([$post['id']]);
        
        $productName = $product['name'] ?? 'Unknown Product';
        $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'Auto-Poster Failed', ?, 'error')")->execute(["Post for '$productName' failed because the product or its image is missing."]);
        continue;
    }

    $productLink = "https://" . $host . "/p/" . $product['slug'];
    $caption = $post['caption'];
    if (empty($caption)) {
        $caption = $product['name'] . "\nPrice: ৳" . $product['basePrice'] . "\n\n🛍️ Order yours here: " . $productLink;
    } else if (strpos($caption, $productLink) === false) {
        $caption .= "\n\n🛍️ Order yours here: " . $productLink;
    }

    $imageUrls = explode(',', $product['imageUrl']);
    $finalImagesToPost = [];

    $formatUrl = function($url) use ($protocol, $host) {
        $url = trim($url);
        if (empty($url)) return '';
        if (preg_match('/^https?:\/\//i', $url)) return $url;
        return $protocol . $host . (str_starts_with($url, '/') ? $url : '/' . $url);
    };

    if (!empty($post['imageUrl'])) {
        $u = $formatUrl($post['imageUrl']);
        if ($u) $finalImagesToPost[] = $u;
    } else {
        for ($i = 0; $i < min(4, count($imageUrls)); $i++) {
            $u = $formatUrl($imageUrls[$i]);
            if ($u) $finalImagesToPost[] = $u;
        }
    }

    // 3. Post to Facebook
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

    if (count($finalImagesToPost) > 1) {
        // Multi-photo post
        $attachedMedia = [];
        foreach ($finalImagesToPost as $imgUrl) {
            // Upload unpublished photo
            $uploadUrl = "https://graph.facebook.com/v19.0/{$pageId}/photos";
            $uploadData = [
                'url' => $imgUrl,
                'published' => 'false',
                'access_token' => $accessToken
            ];
            $ch = curl_init($uploadUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($uploadData));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);
            $photoData = json_decode($res, true);
            if (isset($photoData['id'])) {
                $attachedMedia[] = ['media_fbid' => $photoData['id']];
            }
        }

        if (!empty($attachedMedia)) {
            $feedUrl = "https://graph.facebook.com/v19.0/{$pageId}/feed";
            $feedData = [
                'message' => $caption,
                'attached_media' => json_encode($attachedMedia),
                'access_token' => $accessToken
            ];
            $ch = curl_init($feedUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($feedData));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $resData = json_decode($response, true);
            curl_close($ch);
        }
    } else {
        // Single photo post
        $url = "https://graph.facebook.com/v19.0/{$pageId}/photos";
        $data = ['url' => $finalImagesToPost[0], 'message' => $caption, 'access_token' => $accessToken];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $resData = json_decode($response, true);
        curl_close($ch);
    }

    if (isset($resData['id']) || isset($resData['post_id'])) {
        $pdo->prepare("UPDATE ScheduledPost SET status = 'PUBLISHED', fbPostId = ? WHERE id = ?")->execute([$resData['id'] ?? $resData['post_id'], $post['id']]);
        echo "Successfully published post ID: {$post['id']}\n";
    } else {
        $errorMsg = $resData['error']['message'] ?? 'Unknown API Error';
        $pdo->prepare("UPDATE ScheduledPost SET status = 'FAILED' WHERE id = ?")->execute([$post['id']]);
        $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'Auto-Poster API Error', ?, 'error')")->execute(["Failed to publish '{$product['name']}' to Facebook. Error: " . substr($errorMsg, 0, 150)]);
        echo "Failed to publish post ID: {$post['id']} - " . $errorMsg . "\n";
    }
}
?>