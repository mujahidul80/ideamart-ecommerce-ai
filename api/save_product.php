<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('Unauthorized');
}

ini_set('memory_limit', '512M'); // Increase memory limit for large images
ini_set('max_execution_time', '300');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'] ?? '';
    $slug = $_POST['slug'] ?? '';
    $sku = $_POST['sku'] ?? '';
    $serial_number = $_POST['serial_number'] ?? '';
    $description = $_POST['description'] ?? '';
    $buyPrice = !empty($_POST['buyPrice']) ? $_POST['buyPrice'] : null;
    $basePrice = $_POST['basePrice'] ?? 0;
    $flashDealPrice = !empty($_POST['flashDealPrice']) ? $_POST['flashDealPrice'] : null;
    $isFlashDeal = isset($_POST['isFlashDeal']) ? 1 : 0;
    
    $isAdvanceRequired = !empty($_POST['isAdvanceRequired']) ? 1 : 0;
    $advanceAmount = isset($_POST['advanceAmount']) && is_numeric($_POST['advanceAmount']) ? (float)$_POST['advanceAmount'] : 0.00;
    if (!$isAdvanceRequired) { $advanceAmount = 0.00; }
    
    $mainCategoryId = !empty($_POST['mainCategoryId']) ? $_POST['mainCategoryId'] : null;
    $subCategoryId = !empty($_POST['subCategoryId']) ? $_POST['subCategoryId'] : null;
    $categoryId = $subCategoryId ?: $mainCategoryId;

    $variants = $_POST['variants'] ?? [];
    $mainStock = $_POST['stock'] ?? 0;

    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
    }

    // If slug is still empty after processing
    if (empty($slug)) {
        $slug = 'p-' . substr(time(), -6);
    }

    // Ensure slug is unique to prevent 1062 Duplicate entry error
    $slugCheck = $pdo->prepare("SELECT COUNT(*) FROM Product WHERE slug = ? AND id != ?");
    $slugCheck->execute([$slug, $id ?: 0]);
    if ($slugCheck->fetchColumn() > 0) {
        $slug .= '-' . substr(uniqid(), -5);
    }

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

    $imageUrls = [];
    $existingImages = isset($_POST['existing_images']) ? $_POST['existing_images'] : [];

    if (!empty($id)) {
        $stmt = $pdo->prepare("SELECT imageUrl FROM Product WHERE id = ?");
        $stmt->execute([$id]);
        $oldImageUrl = $stmt->fetchColumn();
        if (!empty($oldImageUrl)) {
            $oldImagesArray = explode(',', $oldImageUrl);
            foreach ($oldImagesArray as $oldPath) {
                if (!in_array($oldPath, $existingImages)) {
                    // Delete removed images from server
                    if (file_exists(__DIR__ . '/..' . $oldPath)) {
                        @unlink(__DIR__ . '/..' . $oldPath);
                    }
                }
            }
        }
        $imageUrls = $existingImages; // Start with the remaining existing images
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $newUploaded = [];
        $fileCount = count($_FILES['images']['name']);
        $fileCount = min($fileCount, 4 - count($imageUrls)); // Limit to maximum 4 images per product
        
        if ($fileCount > 0) {
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                if (!is_dir('../uploads')) { mkdir('../uploads', 0777, true); }
                $tmpName = $_FILES['images']['tmp_name'][$i];
                $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                if (!in_array($mimeType, $allowedMimes)) {
                    continue;
                }
                
                $safeExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (!in_array($ext, $safeExts)) {
                    continue;
                }

                $fileName = time() . '_' . $i . '_prod_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $slug) . '.webp';
                $targetPath = '../uploads/' . $fileName;
                $converted = false;
                
                $imgInfo = @getimagesize($tmpName);
                $isSafeSize = true;
                if ($imgInfo !== false && isset($imgInfo[0]) && isset($imgInfo[1])) {
                    if (($imgInfo[0] * $imgInfo[1]) > 5000000) {
                        $isSafeSize = false; // More than 5 Megapixels
                    }
                }
                
                if ($isSafeSize && function_exists('imagewebp') && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $img = null;
                    if ($ext === 'jpg' || $ext === 'jpeg') $img = @imagecreatefromjpeg($tmpName);
                    elseif ($ext === 'png') {
                        $img = @imagecreatefrompng($tmpName);
                    }
                    elseif ($ext === 'webp') $img = @imagecreatefromwebp($tmpName);
                    elseif ($ext === 'gif') $img = @imagecreatefromgif($tmpName);
                    
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
                        
                        if (in_array($ext, ['png', 'webp', 'gif'])) {
                            imagealphablending($newImg, false);
                            imagesavealpha($newImg, true);
                            $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
                            imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
                            imagealphablending($newImg, true); // re-enable for watermarks
                        } else {
                            $whiteBg = imagecolorallocate($newImg, 255, 255, 255);
                            imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $whiteBg);
                        }
                        
                        imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                        imagedestroy($img);
                        $img = $newImg;
                        
                        // Apply Watermark
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
                                    
                                    // Scale watermark (Professional logo size, minimum 100px for clarity)
                                    $newWmWidth = min($imgWidth * 0.20, $wmWidth);
                                    if ($newWmWidth < 100) $newWmWidth = 100;
                                    $newWmHeight = ($newWmWidth / $wmWidth) * $wmHeight;
                                    
                                    $scaledWm = imagecreatetruecolor($newWmWidth, $newWmHeight);
                                    imagealphablending($scaledWm, false); imagesavealpha($scaledWm, true);
                                    $transparent = imagecolorallocatealpha($scaledWm, 255, 255, 255, 127);
                                    imagefilledrectangle($scaledWm, 0, 0, $newWmWidth, $newWmHeight, $transparent);
                                    imagecopyresampled($scaledWm, $watermark, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);
                                    
                                    // Position: Top-Left inside the actual image
                                    $padding = 20;
                                    $destX = $padding;
                                    $destY = $padding;
                                    
                                    imagealphablending($img, true);
                                    imagecopy($img, $scaledWm, $destX, $destY, 0, 0, $newWmWidth, $newWmHeight);
                                    
                                    imagedestroy($watermark);
                                    imagedestroy($scaledWm);
                                }
                            }
                        }

                        // Apply Text Watermark at Bottom Center (Professional, No Box, Arial / Clean Font)
                        if (!empty($storeName)) {
                            $imgWidth = imagesx($img);
                            $imgHeight = imagesy($img);
                            
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
                                
                                // Position: Bottom-Right
                                $padding = 20;
                                $x1 = $newWidth - $textWidth1 - $padding;
                                $x2 = $newWidth - $textWidth2 - $padding;
                                $y2 = $newHeight - $padding;
                                $y1 = $y2 - 30; // Space between lines
                                
                                if ($x1 < 10) $x1 = 10;
                                if ($x2 < 10) $x2 = 10;
                                
                                $textColor = imagecolorallocate($img, 50, 50, 50); // Dark text
                                $shadowColor = imagecolorallocatealpha($img, 255, 255, 255, 20); // Strong white outline/shadow
                                
                                // Draw stroke/shadow for readability
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
                                // Fallback to clean built-in font if arial.ttf is missing
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
                                
                                if ($x1 < 10) $x1 = 10;
                                if ($x2 < 10) $x2 = 10;
                                
                                $textColor = imagecolorallocate($img, 50, 50, 50); // Dark text
                                $shadowColor = imagecolorallocatealpha($img, 255, 255, 255, 20);
                                
                                imagestring($img, $font, $x1 + 1, $y1 + 1, $line1, $shadowColor);
                                imagestring($img, $font, $x1 - 1, $y1 - 1, $line1, $shadowColor);
                                imagestring($img, $font, $x1, $y1, $line1, $textColor);
                                
                                imagestring($img, $font, $x2 + 1, $y2 + 1, $line2, $shadowColor);
                                imagestring($img, $font, $x2 - 1, $y2 - 1, $line2, $shadowColor);
                                imagestring($img, $font, $x2, $y2, $line2, $textColor);
                            }
                        }

                        if (imagewebp($img, $targetPath, 95)) { $converted = true; } // High quality WebP
                        imagedestroy($img);
                    }
                }
                
                if (!$converted) {
                    $fileName = time() . '_' . $i . '_prod_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $slug) . '.' . $ext;
                    move_uploaded_file($tmpName, '../uploads/' . $fileName);
                }
                
                $newUploaded[] = '/uploads/' . $fileName;
            }
        }

        if (!empty($newUploaded)) {
            $imageUrls = array_merge($imageUrls, $newUploaded);
        }
        }
    }

    $imageUrls = array_slice($imageUrls, 0, 4); // Double check limit
    $finalImageUrl = !empty($imageUrls) ? implode(',', $imageUrls) : null;
    $firstImage = !empty($imageUrls) ? $imageUrls[0] : null;

    if (!empty($id)) {
        $stmt = $pdo->prepare("UPDATE Product SET name = ?, slug = ?, sku = ?, serial_number = ?, description = ?, buyPrice = ?, basePrice = ?, flashDealPrice = ?, isFlashDeal = ?, isAdvanceRequired = ?, advanceAmount = ?, categoryId = ?, imageUrl = ? WHERE id = ?");
        $stmt->execute([$name, $slug, $sku, $serial_number, $description, $buyPrice, $basePrice, $flashDealPrice, $isFlashDeal, $isAdvanceRequired, $advanceAmount, $categoryId, $finalImageUrl, $id]);
        
        $pdo->prepare("DELETE FROM Variant WHERE productId = ?")->execute([$id]);
        if (empty($variants)) {
            $pdo->prepare("INSERT INTO Variant (productId, size, color, stock, initialStock, imageUrl) VALUES (?, 'Standard', 'Default', ?, ?, ?)")->execute([$id, $mainStock, $mainStock, $firstImage]);
        } else {
            foreach ($variants as $v) {
                $vSize = !empty($v['size']) ? $v['size'] : 'Standard';
                $vColor = !empty($v['color']) ? $v['color'] : 'Default';
                $vStock = !empty($v['stock']) ? $v['stock'] : 0;
                $pdo->prepare("INSERT INTO Variant (productId, size, color, stock, initialStock, imageUrl) VALUES (?, ?, ?, ?, ?, ?)")->execute([$id, $vSize, $vColor, $vStock, $vStock, $firstImage]);
            }
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO Product (name, slug, sku, serial_number, description, buyPrice, basePrice, flashDealPrice, isFlashDeal, isAdvanceRequired, advanceAmount, categoryId, imageUrl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $slug, $sku, $serial_number, $description, $buyPrice, $basePrice, $flashDealPrice, $isFlashDeal, $isAdvanceRequired, $advanceAmount, $categoryId, $finalImageUrl]);
        $id = $pdo->lastInsertId();
        
        if (empty($variants)) {
            $pdo->prepare("INSERT INTO Variant (productId, size, color, stock, initialStock, imageUrl) VALUES (?, 'Standard', 'Default', ?, ?, ?)")->execute([$id, $mainStock, $mainStock, $firstImage]);
        } else {
            foreach ($variants as $v) {
                $vSize = !empty($v['size']) ? $v['size'] : 'Standard';
                $vColor = !empty($v['color']) ? $v['color'] : 'Default';
                $vStock = !empty($v['stock']) ? $v['stock'] : 0;
                $pdo->prepare("INSERT INTO Variant (productId, size, color, stock, initialStock, imageUrl) VALUES (?, ?, ?, ?, ?, ?)")->execute([$id, $vSize, $vColor, $vStock, $vStock, $firstImage]);
            }
        }
    }
    
    header("Location: ../admin/products");
    exit;
}