<?php
ob_start();
session_start();

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        while (ob_get_level()) { @ob_end_clean(); }
        echo json_encode(['success' => false, 'error' => 'Fatal Error: ' . $err['message']]);
    }
});

require_once __DIR__ . '/../db.php';

@ini_set('display_errors', 0);
header('Content-Type: application/json');

@ini_set('memory_limit', '1024M');
@set_time_limit(0);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$items = json_decode($_POST['items'] ?? '[]', true);
$status = isset($_POST['status']) && $_POST['status'] === 'live' ? 'live' : 'draft';

if (empty($items) || !is_array($items)) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'error' => 'No products provided.']);
    exit;
}

if (!function_exists('fetch_image_content')) {
    function fetch_image_content($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) {
            return ($downloaded > 5 * 1024 * 1024) ? 1 : 0; // Abort if > 5MB to prevent memory exhaustion
        });
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}

try {
    // Fetch Settings for Watermark
    $logoUrl = null;
    $storeName = '';
    $fbPageName = '';
    try {
        $logoStmt = $pdo->query("SELECT logoUrl, storeName, facebookUrl FROM SettingGeneral LIMIT 1");
        $genData = $logoStmt->fetch(PDO::FETCH_ASSOC);
        if ($genData) {
            $logoUrl = $genData['logoUrl'];
            $storeName = $genData['storeName'];
            $fbUrl = $genData['facebookUrl'];
            if (!empty($fbUrl)) {
                $parts = explode('/', rtrim(str_replace(['http://', 'https://', 'www.'], '', $fbUrl), '/'));
                $fbPageName = end($parts);
            }
            if (empty($fbPageName)) $fbPageName = $storeName;
        }
    } catch (Exception $e) {}

    // Ensure source_url column exists in main Product table to track imports
    try { $pdo->exec("ALTER TABLE `Product` ADD COLUMN `source_url` VARCHAR(512) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `Product` ADD COLUMN `buyPrice` DECIMAL(10,2) NULL AFTER `description`"); } catch (Exception $e) {}

    $pdo->beginTransaction();
    
    if ($status === 'live') {
        $stmtFetch = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmtCheckDup = $pdo->prepare("SELECT id FROM Product WHERE source_url = ?");
        $stmtInsertProd = $pdo->prepare("INSERT INTO Product (name, slug, sku, description, buyPrice, basePrice, imageUrl, source_url, categoryId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtUpdateProd = $pdo->prepare("UPDATE Product SET buyPrice = ?, basePrice = ?, categoryId = ? WHERE source_url = ?");
        $stmtInsertVar = $pdo->prepare("INSERT INTO Variant (productId, size, color, stock, initialStock, imageUrl) VALUES (?, 'Standard', 'Default', ?, ?, ?)");
        $stmtUpdateStatus = $pdo->prepare("UPDATE products SET status = 'live', price = ? WHERE id = ?");

        foreach ($items as $item) {
            $id = intval($item['id']);
            $newPrice = floatval($item['price']);
            $qty = isset($item['qty']) ? intval($item['qty']) : 10;
            
            $stmtFetch->execute([$id]);
            $tempProd = $stmtFetch->fetch(PDO::FETCH_ASSOC);
            
            if ($tempProd) {
                $catId = null;
                if (!empty($tempProd['category_name']) && $tempProd['category_name'] !== 'Uncategorized') {
                    $catName = $tempProd['category_name'];
                    $cStmt = $pdo->prepare("SELECT id FROM Category WHERE name = ? LIMIT 1");
                    $cStmt->execute([$catName]);
                    $catId = $cStmt->fetchColumn();
                    
                    if (!$catId) {
                        $catSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $catName));
                        $catSlug = trim($catSlug, '-');
                        $slugCheck = $pdo->prepare("SELECT COUNT(*) FROM Category WHERE slug = ?");
                        $slugCheck->execute([$catSlug]);
                        if ($slugCheck->fetchColumn() > 0) $catSlug .= '-' . substr(uniqid(), -5);
                        
                        $pdo->prepare("INSERT INTO Category (name, slug, status) VALUES (?, ?, 1)")->execute([$catName, $catSlug]);
                        $catId = $pdo->lastInsertId();
                    }
                }
                
                $stmtCheckDup->execute([$tempProd['source_url']]);
                $existingProdId = $stmtCheckDup->fetchColumn();
                
                if (!$existingProdId) {
                    // Generate unique slug & sku
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $tempProd['title']));
                    $slug = trim($slug, '-') . '-' . substr(uniqid(), -5);
                    $sku = 'IMP-' . strtoupper(substr(uniqid(), -6));
                    
                    $rawImageUrls = !empty($tempProd['image']) ? explode(',', $tempProd['image']) : [];
                    $processedImages = [];
                    
                    // Download and Watermark images if they are external URLs
                    foreach (array_slice($rawImageUrls, 0, 4) as $singleImgUrl) {
                        $singleImgUrl = trim($singleImgUrl);
                        if (empty($singleImgUrl)) continue;
                        
                        if (strpos($singleImgUrl, 'http') === 0) {
                            $imgData = fetch_image_content($singleImgUrl);
                            $processed = false;
                        if ($imgData) {
                            
                            if (!is_dir('../uploads')) @mkdir('../uploads', 0777, true);
                            $tmpFile = '../uploads/tmp_import_' . uniqid() . '.img';
                            file_put_contents($tmpFile, $imgData);
                            
                            $imgInfo = @getimagesize($tmpFile);
                            $img = null;
                            if ($imgInfo !== false) {
                                // Prevent Out-of-Memory (OOM) on extremely large images (Max ~20MP)
                                if (isset($imgInfo[0]) && isset($imgInfo[1]) && ($imgInfo[0] * $imgInfo[1] > 20000000)) {
                                    @unlink($tmpFile);
                                    $processedImages[] = $finalImageUrl;
                                    continue;
                                }
                                $mime = $imgInfo['mime'];
                                if ($mime == 'image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($tmpFile);
                                elseif ($mime == 'image/png' && function_exists('imagecreatefrompng')) { $img = @imagecreatefrompng($tmpFile); if ($img) { imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); } }
                                elseif ($mime == 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($tmpFile);
                                elseif ($mime == 'image/gif' && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($tmpFile);
                            }
                            if (!$img && function_exists('imagecreatefromstring')) $img = @imagecreatefromstring($imgData);
                            @unlink($tmpFile);
                            
                            if ($img) {
                                $width = imagesx($img);
                                $height = imagesy($img);
                                $targetSize = 1080;
                                
                                $ratio = $width / $height;
                                if ($width <= $targetSize && $height <= $targetSize) {
                                    $newWidth = $width;
                                    $newHeight = $height;
                                } else {
                                    if ($ratio > 1) {
                                        $newWidth = $targetSize;
                                        $newHeight = round($targetSize / $ratio);
                                    } else {
                                        $newHeight = $targetSize;
                                        $newWidth = round($targetSize * $ratio);
                                    }
                                }
                                
                                $newImg = imagecreatetruecolor($newWidth, $newHeight);
                                
                                if (isset($mime) && in_array($mime, ['image/png', 'image/webp', 'image/gif'])) {
                                    imagealphablending($newImg, false);
                                    imagesavealpha($newImg, true);
                                    $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
                                    imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
                                    imagealphablending($newImg, true);
                                } else {
                                    $whiteBg = imagecolorallocate($newImg, 255, 255, 255);
                                    imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $whiteBg);
                                }
                                
                                imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                                imagedestroy($img);
                                $img = $newImg;

                                // Apply Logo Watermark
                                if (!empty($logoUrl)) {
                                    $logoPath = realpath(__DIR__ . '/..' . $logoUrl);
                                    if ($logoPath && file_exists($logoPath)) {
                                        $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                                        $watermark = null;
                                        if ($logoExt === 'png') $watermark = @imagecreatefrompng($logoPath);
                                        elseif ($logoExt === 'jpg' || $logoExt === 'jpeg') $watermark = @imagecreatefromjpeg($logoPath);
                                        elseif ($logoExt === 'webp') $watermark = @imagecreatefromwebp($logoPath);
                                        
                                        if ($watermark) {
                                            $imgWidth = imagesx($img);
                                            $imgHeight = imagesy($img);
                                            $wmWidth = imagesx($watermark);
                                            $wmHeight = imagesy($watermark);
                                            
                                            $newWmWidth = min($imgWidth * 0.20, $wmWidth);
                                            if ($newWmWidth < 100) $newWmWidth = 100;
                                            $newWmHeight = ($newWmWidth / $wmWidth) * $wmHeight;
                                            
                                            $scaledWm = imagecreatetruecolor($newWmWidth, $newWmHeight);
                                            imagealphablending($scaledWm, false); imagesavealpha($scaledWm, true);
                                            $transparent = imagecolorallocatealpha($scaledWm, 255, 255, 255, 127);
                                            imagefilledrectangle($scaledWm, 0, 0, $newWmWidth, $newWmHeight, $transparent);
                                            imagecopyresampled($scaledWm, $watermark, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);
                                            
                                            $padding = 20;
                                            imagealphablending($img, true);
                                            imagecopy($img, $scaledWm, $padding, $padding, 0, 0, $newWmWidth, $newWmHeight);
                                            
                                            imagedestroy($watermark);
                                            imagedestroy($scaledWm);
                                        }
                                    }
                                }

                                // Apply Text Watermark
                                if (!empty($storeName)) {
                                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                                    $line1 = $protocol . ($_SERVER['HTTP_HOST'] ?? 'yourstore.com');
                                    $line2 = "FB: " . $fbPageName;
                                    $fontPath = __DIR__ . '/arial.ttf';
                                    
                                    if (file_exists($fontPath)) {
                                        $fontSize = 20;
                                        $bbox1 = imagettfbbox($fontSize, 0, $fontPath, $line1);
                                        $textWidth1 = $bbox1[2] - $bbox1[0];
                                        $bbox2 = imagettfbbox($fontSize, 0, $fontPath, $line2);
                                        $textWidth2 = $bbox2[2] - $bbox2[0];
                                        
                                        $padding = 20;
                                        $x1 = $newWidth - $textWidth1 - $padding;
                                        $x2 = $newWidth - $textWidth2 - $padding;
                                        $y2 = $newHeight - $padding;
                                        $y1 = $y2 - 30;
                                        
                                        $textColor = imagecolorallocate($img, 50, 50, 50);
                                        $shadowColor = imagecolorallocatealpha($img, 255, 255, 255, 20);
                                        
                                        imagettftext($img, $fontSize, 0, $x1+1, $y1+1, $shadowColor, $fontPath, $line1);
                                        imagettftext($img, $fontSize, 0, $x1-1, $y1-1, $shadowColor, $fontPath, $line1);
                                        imagettftext($img, $fontSize, 0, $x1+1, $y1-1, $shadowColor, $fontPath, $line1);
                                        imagettftext($img, $fontSize, 0, $x1-1, $y1+1, $shadowColor, $fontPath, $line1);
                                        imagettftext($img, $fontSize, 0, $x1, $y1, $textColor, $fontPath, $line1);
                                        
                                        imagettftext($img, $fontSize, 0, $x2+1, $y2+1, $shadowColor, $fontPath, $line2);
                                        imagettftext($img, $fontSize, 0, $x2-1, $y2-1, $shadowColor, $fontPath, $line2);
                                        imagettftext($img, $fontSize, 0, $x2+1, $y2-1, $shadowColor, $fontPath, $line2);
                                        imagettftext($img, $fontSize, 0, $x2-1, $y2+1, $shadowColor, $fontPath, $line2);
                                        imagettftext($img, $fontSize, 0, $x2, $y2, $textColor, $fontPath, $line2);
                                    } else {
                                        $font = 4;
                                        $charWidth = imagefontwidth($font);
                                        $charHeight = imagefontheight($font);
                                        $textWidth1 = strlen($line1) * $charWidth;
                                        $textWidth2 = strlen($line2) * $charWidth;
                                        $padding = 20;
                                        $x1 = $newWidth - $textWidth1 - $padding;
                                        $x2 = $newWidth - $textWidth2 - $padding;
                                        $y2 = $newHeight - $padding - $charHeight;
                                        $y1 = $y2 - $charHeight - 10;
                                        
                                        $textColor = imagecolorallocate($img, 50, 50, 50);
                                        $shadowColor = imagecolorallocatealpha($img, 255, 255, 255, 20);
                                        
                                        imagestring($img, $font, $x1 + 1, $y1 + 1, $line1, $shadowColor);
                                        imagestring($img, $font, $x1 - 1, $y1 - 1, $line1, $shadowColor);
                                        imagestring($img, $font, $x1, $y1, $line1, $textColor);
                                        imagestring($img, $font, $x2 + 1, $y2 + 1, $line2, $shadowColor);
                                        imagestring($img, $font, $x2 - 1, $y2 - 1, $line2, $shadowColor);
                                        imagestring($img, $font, $x2, $y2, $line2, $textColor);
                                    }
                                }

                                $fileName = time() . '_' . uniqid() . '_import';
                                $targetPathBase = '../uploads/' . $fileName;
                                if (!is_dir('../uploads')) @mkdir('../uploads', 0777, true);
                                
                                $saved = false;
                                if (function_exists('imagewebp')) {
                                    if (@imagewebp($img, $targetPathBase . '.webp', 95)) {
                                        $savedPath = '/uploads/' . $fileName . '.webp';
                                        $saved = true;
                                    }
                                }
                                if (!$saved && function_exists('imagejpeg')) {
                                    if (@imagejpeg($img, $targetPathBase . '.jpg', 95)) {
                                        $savedPath = '/uploads/' . $fileName . '.jpg';
                                        $saved = true;
                                    }
                                }
                                
                                if ($saved) {
                                    $processedImages[] = $savedPath;
                                    $processed = true;
                                }
                                imagedestroy($img);
                            }
                        }
                        
                        // Fallback if GD library is missing or image processing failed
                        if (!$processed && $imgData) {
                            if (class_exists('finfo')) {
                                $finfo = new finfo(FILEINFO_MIME_TYPE);
                                $mime = $finfo->buffer($imgData);
                                if (strpos($mime, 'image/') === 0) {
                                    $ext = str_replace('image/', '', $mime);
                                    if ($ext === 'jpeg') $ext = 'jpg';
                                    $fileName = time() . '_' . uniqid() . '_import.' . $ext;
                                    if (!is_dir('../uploads')) @mkdir('../uploads', 0777, true);
                                    if (file_put_contents('../uploads/' . $fileName, $imgData)) {
                                        $processedImages[] = '/uploads/' . $fileName;
                                    }
                                } else {
                                    $processedImages[] = $singleImgUrl; // Not an image, keep original URL
                                }
                            } else {
                                $fileName = time() . '_' . uniqid() . '_import.jpg';
                                if (!is_dir('../uploads')) @mkdir('../uploads', 0777, true);
                                if (file_put_contents('../uploads/' . $fileName, $imgData)) {
                                    $processedImages[] = '/uploads/' . $fileName;
                                }
                            }
                        }
                        } else {
                            $processedImages[] = $singleImgUrl;
                        }
                    }
                    
                    $finalImageUrl = implode(',', array_unique($processedImages));
                    if (!empty($finalImageUrl)) {
                        $pdo->prepare("UPDATE products SET image = ? WHERE id = ?")->execute([$finalImageUrl, $id]);
                    }

                    $buyPrice = isset($tempProd['original_price']) ? floatval($tempProd['original_price']) : 0;
                    $stmtInsertProd->execute([$tempProd['title'], $slug, $sku, $tempProd['description'], $buyPrice, $newPrice, $finalImageUrl, $tempProd['source_url'], $catId]);
                    $newProductId = $pdo->lastInsertId();
                    
                    $firstImg = !empty($processedImages) ? $processedImages[0] : null;
                    $stmtInsertVar->execute([$newProductId, $qty, $qty, $firstImg]);
                } else {
                    // Update price only (Keep original stock as requested)
                    $buyPrice = isset($tempProd['original_price']) ? floatval($tempProd['original_price']) : 0;
                    $stmtUpdateProd->execute([$buyPrice, $newPrice, $catId, $tempProd['source_url']]);
                }
                
                $stmtUpdateStatus->execute([$newPrice, $id]);
            }
        }
    } else {
        // Unpublish (Remove from Live Store)
        $stmtFetch = $pdo->prepare("SELECT source_url FROM products WHERE id = ?");
        $stmtDelProd = $pdo->prepare("DELETE FROM Product WHERE source_url = ?");
        $stmtUpdateStatus = $pdo->prepare("UPDATE products SET status = 'draft' WHERE id = ?");
        
        foreach ($items as $item) {
            $id = intval($item['id']);
            $stmtFetch->execute([$id]);
            $src = $stmtFetch->fetchColumn();
            if ($src) { $stmtDelProd->execute([$src]); }
            $stmtUpdateStatus->execute([$id]);
        }
    }
    
    $pdo->commit();

    // Auto Ping Google if enabled and products are published to live
    try {
        $setStmt = $pdo->query("SELECT autoGooglePing FROM SettingGeneral LIMIT 1");
        $genSet = $setStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($genSet['autoGooglePing']) && $status === 'live') {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $sitemapUrl = urlencode("{$protocol}{$host}/sitemap.php");
            $pingUrl = "http://www.google.com/ping?sitemap=" . $sitemapUrl;
            $ch = curl_init($pingUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Quick execute
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Exception $e) {}

    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(['success' => true, 'message' => 'Products synced with live store successfully.']);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'error' => 'Server Error: ' . $e->getMessage()]);
}
?>