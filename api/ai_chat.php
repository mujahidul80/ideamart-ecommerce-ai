<?php
error_reporting(0);
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_chat_message` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `session_id` VARCHAR(100) NOT NULL,
        `user_id` INT NULL DEFAULT NULL,
        `role` ENUM('user', 'model') NOT NULL,
        `content` TEXT NOT NULL,
        `products_json` TEXT NULL DEFAULT NULL,
        `is_cleared` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_session` (`session_id`),
        INDEX `idx_user` (`user_id`),
        INDEX `idx_cleared` (`is_cleared`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

$sessionId = session_id();
if (empty($sessionId)) {
    $sessionId = 'guest_' . md5($_SERVER['REMOTE_ADDR'] ?? 'default');
}
$userId = (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;

// If user is logged in, link any past guest session messages from this session to their user_id
if ($userId) {
    try {
        $linkStmt = $pdo->prepare("UPDATE ai_chat_message SET user_id = ? WHERE session_id = ? AND user_id IS NULL");
        $linkStmt->execute([$userId, $sessionId]);
    } catch (Exception $e) {}
}

// Handle GET / History retrieval
$action = $_GET['action'] ?? '';
$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: [];

if ($action === 'history' || (isset($data['action']) && $data['action'] === 'history')) {
    ob_end_clean();
    try {
        if ($userId) {
            $stmt = $pdo->prepare("SELECT role, content, products_json FROM ai_chat_message WHERE user_id = ? AND is_cleared = 0 ORDER BY id ASC LIMIT 50");
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->prepare("SELECT role, content, products_json FROM ai_chat_message WHERE session_id = ? AND is_cleared = 0 ORDER BY id ASC LIMIT 50");
            $stmt->execute([$sessionId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $messages = [];
        foreach ($rows as $r) {
            $prods = !empty($r['products_json']) ? json_decode($r['products_json'], true) : [];
            $messages[] = [
                'role' => $r['role'],
                'content' => $r['content'],
                'products' => is_array($prods) ? $prods : []
            ];
        }
        echo json_encode(['success' => true, 'messages' => $messages]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'messages' => []]);
    }
    exit;
}

// Handle Clear / New Chat
if ($action === 'clear' || (isset($data['action']) && $data['action'] === 'clear')) {
    ob_end_clean();
    try {
        if ($userId) {
            $stmt = $pdo->prepare("UPDATE ai_chat_message SET is_cleared = 1 WHERE user_id = ? AND is_cleared = 0");
            $stmt->execute([$userId]);
        }
        $stmt2 = $pdo->prepare("UPDATE ai_chat_message SET is_cleared = 1 WHERE session_id = ? AND is_cleared = 0");
        $stmt2->execute([$sessionId]);
        echo json_encode(['success' => true, 'cleared' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Normal chat message
$message = trim($data['message'] ?? '');
$history = $data['history'] ?? [];

if (empty($message)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'reply' => 'দয়া করে আপনার প্রশ্ন লিখুন।']);
    exit;
}

$currentTime = time();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
    $rateFile = sys_get_temp_dir() . '/chat_rate_' . md5($ip);
    $requests = @file_get_contents($rateFile);
    if ($requests) {
        $parts = explode(':', $requests);
        if ($currentTime - (int)$parts[1] < 60) {
            if ((int)$parts[0] > 30) {
                ob_end_clean();
                echo json_encode(['success' => false, 'reply' => 'আপনি খুব দ্রুত মেসেজ পাঠাচ্ছেন। দয়া করে কিছুক্ষণ অপেক্ষা করে আবার চেষ্টা করুন।']);
                exit;
            }
            @file_put_contents($rateFile, ((int)$parts[0] + 1) . ':' . $parts[1]);
        } else { @file_put_contents($rateFile, '1:' . $currentTime); }
    } else { @file_put_contents($rateFile, '1:' . $currentTime); }
}

// Load Store Settings & Knowledge
$stmt = $pdo->query("SELECT g.*, n.* FROM SettingGeneral g LEFT JOIN SettingNotification n ON g.id = n.id LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$shipping = $pdo->query("SELECT * FROM SettingDelivery LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$payment = $pdo->query("SELECT * FROM SettingPayment LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$storeName = $settings['storeName'] ?? 'Idea Mart';
$supportPhone = $settings['supportPhone'] ?? '';
$waRaw = !empty($settings['whatsappNumber']) ? preg_replace('/[^0-9]/', '', $settings['whatsappNumber']) : ($supportPhone ? preg_replace('/[^0-9]/', '', $supportPhone) : '');
if (!empty($waRaw) && substr($waRaw, 0, 2) !== '88' && strlen($waRaw) === 11) {
    $waNumber = '88' . $waRaw;
} else {
    $waNumber = $waRaw;
}
$waLink = !empty($waNumber) ? "https://wa.me/{$waNumber}" : "#";
$supportEmail = $settings['supportEmail'] ?? '';
$storeAddress = $settings['storeAddress'] ?? '';

$insideDhaka = !empty($shipping['deliveryInsideDhaka']) ? number_format((float)$shipping['deliveryInsideDhaka'], 0) : '60';
$outsideDhaka = !empty($shipping['deliveryOutsideDhaka']) ? number_format((float)$shipping['deliveryOutsideDhaka'], 0) : '120';
$codAvailable = !empty($payment['codEnabled']) ? 'হ্যাঁ (ক্যাশ অন ডেলিভারি চালু আছে)' : 'না';
$bkashAvailable = !empty($payment['bkashEnabled']) ? 'হ্যাঁ (বিকাশ অনলাইন পেমেন্ট চালু আছে)' : 'না';

if (!function_exists('getProductSynonymMap')) {
    function getProductSynonymMap() {
        return [
            'watch' => ['ঘড়ি', 'ঘড়ি', 'ঘড়ির', 'ঘড়ী', 'ঘড়িটি', 'ওয়াচ', 'ওয়াচ', 'স্মার্টওয়াচ', 'স্মার্টওয়াচ', 'smartwatch', 'smart watch', 'watch', 'clock', 'ultra'],
            'fan' => ['ফ্যান', 'ফ্যানের', 'পাখা', 'বাতাস', 'হাতপাখা', 'fan', 'mini fan', 'portable fan', 'rechargeable fan'],
            'earbud' => ['ইয়ারবাড', 'ইয়ারবাড', 'ইয়ারবাডস', 'ইয়ারবাডস', 'হেডফোন', 'ইয়ারফোন', 'ইয়ারফোন', 'ব্লুটুথ', 'airpod', 'airpods', 'earbud', 'earbuds', 'headphone', 'headset', 'tws', 'wireless'],
            'projector' => ['প্রজেক্টর', 'প্রজেক্টরের', 'projector', 'mini projector'],
            'camera' => ['ক্যামেরা', 'সিসিক্যামেরা', 'camera', 'cctv'],
            'router' => ['রাউটার', 'ওয়াইফাই', 'ওয়াইফাই', 'router', 'wifi'],
            'lighter' => ['লাইটার', 'lighter'],
            'spray' => ['স্প্রে', 'পারফিউম', 'আতর', 'বডি স্প্রে', 'spray', 'perfume'],
            'trimmer' => ['ট্রিমার', 'শেভার', 'দাড়ি কাটার মেশিন', 'trimmer', 'shaver'],
            'shoe' => ['জুতা', 'জুতো', 'স্নিকার্স', 'লোফার', 'কেডস', 'shoe', 'shoes', 'sneaker', 'sneakers'],
            'shirt' => ['শার্ট', 'টি-শার্ট', 'টিশার্ট', 'পোলো', 'shirt', 'tshirt', 't-shirt', 'polo'],
            'pant' => ['প্যান্ট', 'জিন্স', 'পায়জামা', 'pant', 'pants', 'jeans', 'trouser'],
            'bag' => ['ব্যাগ', 'ব্যাগপ্যাক', 'পার্স', 'লেডিস ব্যাগ', 'bag', 'backpack', 'purse', 'wallet'],
            'power' => ['পাওয়ার ব্যাংক', 'পাওয়ারব্যাংক', 'powerbank', 'power bank'],
            'charger' => ['চার্জার', 'ক্যাবল', 'তার', 'charger', 'cable'],
            'light' => ['লাইট', 'বাতি', 'টর্চ', 'ল্যাম্প', 'light', 'lamp', 'torch', 'led'],
            'bottle' => ['বোতল', 'ফ্লাস্ক', 'থার্মোফ্লাস্ক', 'bottle', 'flask'],
            'sunglass' => ['সানগ্লাস', 'চশমা', 'sunglass', 'sunglasses'],
            'toy' => ['খেলনা', 'টয়', 'toy', 'toys', 'kids']
        ];
    }
}

// Intent Detection: Distinguish pure greetings & pure account queries from product questions
$isPureGreeting = preg_match('/^(hi|hello|hey|salam|assalamu\s*alaikum|হাই|হ্যালো|সালাম|আসসালামু\s*আলাইকুম|কেমন\s*আছেন|good\s*(morning|evening|afternoon))$/ui', trim($message));
$isPureSupportOnly = preg_match('/^(login|otp|password|register|লগইন|পাসওয়ার্ড|ওটিপি|পিন|একাউন্ট|অ্যাকাউন্ট|সমস্যা|কাজ\s*করছে\s*না|কোড\s*পাচ্ছি\s*না)\b/ui', trim($message));

// Fetch All Active Store Categories for complete catalog knowledge
$categoriesList = [];
try {
    $catStmt = $pdo->query("SELECT id, name, slug FROM Category WHERE status = 1 OR status IS NULL ORDER BY name ASC");
    $categoriesList = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$categoriesSummary = !empty($categoriesList) ? implode(', ', array_column($categoriesList, 'name')) : "সকল ধরণের নিত্যপ্রয়োজনীয় ও ট্রেন্ডি গ্যাজেট/ফ্যাশন পণ্য";

// Fetch Active Store Coupons & Discounts
$activeCoupons = [];
try {
    $cpStmt = $pdo->query("SELECT code, discountType, discountAmount, minSpend, expiryDate FROM Coupon WHERE status = 1 AND (expiryDate IS NULL OR expiryDate > NOW()) LIMIT 5");
    $activeCoupons = $cpStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$couponsText = "";
if (!empty($activeCoupons)) {
    $cList = [];
    foreach ($activeCoupons as $cp) {
        $disc = ($cp['discountType'] === 'PERCENTAGE') ? "{$cp['discountAmount']}% ছাড়" : "৳" . number_format((float)$cp['discountAmount'], 0) . " ছাড়";
        $min = ($cp['minSpend'] > 0) ? " (সর্বনিম্ন খরচ: ৳" . number_format((float)$cp['minSpend'], 0) . ")" : "";
        $cList[] = "কোড '{$cp['code']}' ({$disc}{$min})";
    }
    $couponsText = "\n- সক্রিয় কুপন ও স্পেশাল ছাড়: " . implode(', ', $cList) . " (গ্রাহক ডিসকাউন্ট বা কুপন চাইলে এই কোডগুলো শেয়ার করুন)।";
}

// Live Customer Order Tracking Lookup (If Phone or Order ID is mentioned)
$orderContext = "";
if (preg_match('/(\b01[3-9]\d{8}\b|#?\b\d{1,6}\b)/', $message, $mOrder)) {
    $matchedTerm = $mOrder[1];
    $cleanOrderNum = ltrim($matchedTerm, '#');
    try {
        $foundOrders = [];
        if (preg_match('/^01[3-9]\d{8}$/', $cleanOrderNum)) {
            $oStmt = $pdo->prepare("SELECT o.id, o.status, o.total, o.courierName, o.trackingLink, o.createdAt, u.name as customerName 
                                    FROM `Order` o 
                                    JOIN `User` u ON o.userId = u.id 
                                    WHERE u.phone = ? OR u.phone = ? OR u.phone = ? 
                                    ORDER BY o.id DESC LIMIT 3");
            $oStmt->execute([$cleanOrderNum, '+88' . $cleanOrderNum, '88' . $cleanOrderNum]);
            $foundOrders = $oStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (is_numeric($cleanOrderNum) && (int)$cleanOrderNum > 0 && preg_match('/(order|অর্ডার|পার্সেল|track|ট্র্যাক|স্ট্যাটাস|কবে পাব|ডেলিভারি)/ui', $message)) {
            $oStmt = $pdo->prepare("SELECT o.id, o.status, o.total, o.courierName, o.trackingLink, o.createdAt, u.name as customerName 
                                    FROM `Order` o 
                                    JOIN `User` u ON o.userId = u.id 
                                    WHERE o.id = ? LIMIT 1");
            $oStmt->execute([(int)$cleanOrderNum]);
            $foundOrders = $oStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($foundOrders)) {
            $orderContext = "CUSTOMER LIVE ORDER STATUS (DATABASE MATCH):\n";
            foreach ($foundOrders as $fo) {
                $statusMap = [
                    'PENDING' => 'অপেক্ষমান (Pending)',
                    'PROCESSING' => 'প্রসেসিং হচ্ছে (Processing)',
                    'SHIPPED' => 'ডেলিভারির জন্য পাঠানো হয়েছে (Shipped)',
                    'DELIVERED' => 'ডেলিভারি সম্পন্ন (Delivered)',
                    'CANCELLED' => 'বাতিল (Cancelled)'
                ];
                $stName = $statusMap[$fo['status']] ?? $fo['status'];
                $courier = !empty($fo['courierName']) ? "কুরিয়ার: {$fo['courierName']}" : "কুরিয়ারে প্রক্রিয়াধীন";
                $tracking = !empty($fo['trackingLink']) ? "ট্র্যাকিং লিংক: {$fo['trackingLink']}" : "";
                $orderContext .= "- Order #{$fo['id']} ({$fo['customerName']}): মোট ৳" . number_format((float)$fo['total'], 0) . " | বর্তমান অবস্থা: {$stName} | {$courier} {$tracking} | অর্ডার তারিখ: {$fo['createdAt']}\n";
            }
            $orderContext .= "(Provide this exact live order update reassuringly to the customer)\n\n";
        }
    } catch (Exception $e) {}
}

// Fetch Complete Products Catalog with Inventory Quantity & Variant Breakdown
try {
    $pStmt = $pdo->query("
        SELECT p.id, p.name, p.slug, p.sku, p.basePrice, p.flashDealPrice, p.isFlashDeal, p.categoryId, p.imageUrl, p.description,
               MAX(c.name) as categoryName,
               COALESCE(SUM(v.stock), 0) as totalStock,
               GROUP_CONCAT(DISTINCT CONCAT(COALESCE(v.color, 'Default'), ' / ', COALESCE(v.size, 'Standard'), ' (স্টক: ', v.stock, ')') SEPARATOR '; ') as variantBreakdown
        FROM Product p
        LEFT JOIN Category c ON p.categoryId = c.id
        LEFT JOIN Variant v ON p.id = v.productId
        GROUP BY p.id
        ORDER BY p.id DESC
        LIMIT 150
    ");
    $allProducts = $pStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $pStmt = $pdo->query("SELECT id, name, basePrice, flashDealPrice, isFlashDeal, slug, imageUrl, description, sku, categoryId FROM Product ORDER BY id DESC LIMIT 150");
        $allProducts = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        $allProducts = [];
    }
}

// Build concise store-wide catalog overview of products & stock for immediate awareness
$catalogOverviewList = [];
foreach (array_slice($allProducts, 0, 20) as $ap) {
    $cPrice = ($ap['isFlashDeal'] && $ap['flashDealPrice']) ? $ap['flashDealPrice'] : $ap['basePrice'];
    $cStock = (int)($ap['totalStock'] ?? 0);
    $cStockStatus = ($cStock > 0) ? "স্টকে আছে ({$cStock} পিস)" : "স্টক শেষ";
    $cCat = !empty($ap['categoryName']) ? " [{$ap['categoryName']}]" : "";
    $catalogOverviewList[] = "• {$ap['name']}{$cCat}: ৳" . number_format((float)$cPrice, 0) . " ({$cStockStatus})";
}
$storeCatalogOverview = !empty($catalogOverviewList) ? implode("\n", $catalogOverviewList) : "বিভিন্ন ট্রেন্ডি ও নিত্যপ্রয়োজনীয় গ্যাজেট ও পণ্য উপলব্ধ।";

$carouselProducts = [];
$prodContext = "";

if (!$isPureGreeting && !$isPureSupportOnly) {
    $msgLower = mb_strtolower(trim($message), 'UTF-8');

    if (!empty($allProducts)) {
        $synonymMap = getProductSynonymMap();
        $matchedCategoryKeys = [];
        foreach ($synonymMap as $catKey => $aliases) {
            foreach ($aliases as $alias) {
                if (mb_strpos($msgLower, $alias) !== false) {
                    $matchedCategoryKeys[] = $catKey;
                    $matchedCategoryKeys[] = $alias;
                    break;
                }
            }
        }
        $matchedCategoryKeys = array_unique($matchedCategoryKeys);

        $ignore = ['er', 'ta', 'ti', 'te', 'ke', 'ye', 'in', 'on', 'to', 'at', 'or', 'so', 'me', 'my', 'we', 'us', 'it', 'is', 'am', 'are', 'ai', 'ei', 'oi', 'ar', 'aar', 'the', 'and', 'with', 'what', 'this', 'that', 'have', 'from', 'here', 'show', 'list', 'view', 'some', 'please', 'kichu', 'kisu', 'koto', 'kotoo', 'dam', 'price', 'dekhao', 'dekhan', 'ache', 'ace', 'bhai', 'vai', 'apnader', 'apnar', 'proyojon', 'chai', 'pabo', 'paowa', 'jabe', 'product', 'item', 'details', 'পণ্য', 'প্রোডাক্ট', 'দাম', 'কত', 'কতো', 'আছে', 'দেখান', 'দেখাও', 'কিনতে', 'চাই', 'ভাই', 'একটু', 'বলেন', 'পাব', 'হবে', 'দর', 'কি', 'কী', 'টা', 'টি', 'তে', 'কে', 'এর'];
        $rawWords = preg_split('/[\s,\.\?\!\-\_\/\:\;\(\)\[\]]+/u', $msgLower, -1, PREG_SPLIT_NO_EMPTY);
        $cleanWords = array_values(array_filter(array_diff($rawWords, $ignore), function($w) {
            if (preg_match('/[0-9]/', $w)) {
                return mb_strlen($w) >= 2;
            }
            return mb_strlen($w) >= 3;
        }));

        $scoredProducts = [];
        foreach ($allProducts as $p) {
            $pNameLower = mb_strtolower($p['name'], 'UTF-8');
            $pDescLower = mb_strtolower(strip_tags($p['description'] ?? ''), 'UTF-8');
            $score = 0;

            // Direct match
            if (mb_strpos($msgLower, $pNameLower) !== false) {
                $score += 100;
            }

            // Token matching
            foreach ($cleanWords as $word) {
                if (mb_strpos($pNameLower, $word) !== false) {
                    if (preg_match('/[0-9]/', $word) || mb_strlen($word) >= 4) {
                        $score += 40; // High score for model codes e.g. T900, Ultra, M10
                    } else {
                        $score += 25;
                    }
                } elseif (mb_strpos($pDescLower, $word) !== false) {
                    $score += 10;
                }
            }

            // Synonym/Category match
            foreach ($matchedCategoryKeys as $catKey) {
                if (mb_strpos($pNameLower, $catKey) !== false || mb_strpos($pDescLower, $catKey) !== false) {
                    $score += 35;
                    break;
                }
            }

            if ($score >= 20) {
                $scoredProducts[] = ['product' => $p, 'score' => $score];
            }
        }

        // Sort scored products by highest score
        usort($scoredProducts, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $finalProducts = [];
        $seenIds = [];
        foreach ($scoredProducts as $sp) {
            if (count($finalProducts) >= 6) break;
            $pid = (int)$sp['product']['id'];
            if (!in_array($pid, $seenIds)) {
                $finalProducts[] = $sp['product'];
                $seenIds[] = $pid;
            }
        }

        // Fallback: If customer has shopping intent and nothing matched specifically, pull latest 4 items
        $hasShoppingIntent = preg_match('/(পণ্য|প্রোডাক্ট|দাম|কত|কতো|প্রাইস|কিনতে|দেখাও|দেখান|কালেকশন|শপ|লিস্ট|অর্ডার|চাই|product|price|cost|buy|shop|collection|items|show|available)/ui', $msgLower);
        if ($hasShoppingIntent && empty($finalProducts)) {
            $finalProducts = array_slice($allProducts, 0, 4);
        }

        foreach ($finalProducts as $p) {
            $firstImage = !empty($p['imageUrl']) ? explode(',', $p['imageUrl'])[0] : '';
            $price = ($p['isFlashDeal'] && $p['flashDealPrice']) ? $p['flashDealPrice'] : $p['basePrice'];
            $carouselProducts[] = [
                'name' => $p['name'],
                'price' => number_format((float)$price, 0),
                'slug' => $p['slug'],
                'imageUrl' => $firstImage,
                'url' => 'p/' . $p['slug']
            ];
            
            $desc = substr(strip_tags($p['description'] ?? ''), 0, 150);
            $stockNum = (int)($p['totalStock'] ?? 0);
            $stockStatus = ($stockNum > 0) ? "ইন স্টক ({$stockNum} পিস উপলব্ধ)" : "আউট অব স্টক / স্টক শেষ";
            $varInfo = !empty($p['variantBreakdown']) ? " | কালার/সাইজ স্টক: " . $p['variantBreakdown'] : "";
            $catInfo = !empty($p['categoryName']) ? " | ক্যাটাগরি: " . $p['categoryName'] : "";
            $skuInfo = !empty($p['sku']) ? " [SKU: {$p['sku']}]" : "";
            $dealInfo = ($p['isFlashDeal'] && $p['flashDealPrice']) ? " (ফ্ল্যাশ ডিল অফার! পূর্বমূল্য: ৳" . number_format((float)$p['basePrice'], 0) . ")" : "";

            $prodContext .= "- {$p['name']}{$skuInfo}: মূল্য ৳" . number_format((float)$price, 0) . "{$dealInfo}{$catInfo} | স্টক স্ট্যাটাস: {$stockStatus}{$varInfo} | লিংক: p/{$p['slug']} | বিবরণ: {$desc}\n";
        }
    }
}

$customPrompt = !empty(trim($settings['siteAiPrompt'] ?? '')) ? trim($settings['siteAiPrompt']) : (!empty(trim($settings['fbBotPrompt'] ?? '')) ? trim($settings['fbBotPrompt']) : '');

$systemPrompt = "You are a warm, polite, and helpful human sales, inventory and customer support assistant at {$storeName} in Bangladesh.
Speak naturally like an experienced, courteous retail shop representative (e.g. 'জি ভাইয়া...', 'জি আপু...', smiling emojis 😊).

" . (!empty($customPrompt) ? "STORE OWNER CUSTOM INSTRUCTIONS (FOLLOW CAREFULLY):\n{$customPrompt}\n\n" : "") . "
CRITICAL GUIDELINES FOR HUMAN-LIKE BEHAVIOR:
1. SALAM / GREETING RULE (VERY STRICT):
   - NEVER say 'আসসালামু আলাইকুম' or greetings in every single message!
   - ONLY say 'আসসালামু আলাইকুম' if the customer says salam or greets first.
   - In ongoing conversations or regular questions (e.g. asking price, details, availability, stock quantity, delivery charge, return policy, payment options, etc.), DO NOT say salam. Instead, answer directly and politely starting with 'জি ভাইয়া...' or 'জি আপু...' (e.g. 'জি ভাইয়া, আমাদের এই ওয়াচটির বর্তমান অফার মূল্য মাত্র ৳...').

2. PRODUCT STOCK, QUANTITY & AVAILABILITY:
   - When a customer asks if a product is available, what products exist, or how many pieces/stock are left ('স্টকে আছে কি?', 'কয় পিস আছে?', 'কোন কোন কালার/সাইজ আছে?'):
     * Accurately state whether it is in stock or out of stock based on the database information provided below.
     * State the exact available piece quantity if asked (e.g. 'জি ভাইয়া, বর্তমানে আমাদের স্টকে ২০ পিস এভেইলেবল আছে।').
     * Mention available colors and sizes from the variant breakdown.
     * If out of stock, politely inform that stock is temporarily finished and will be restocked soon.

3. STORE PRODUCTS & CATEGORIES SUPPORT:
   - If a customer asks what products or categories are available in the store ('আপনাদের কী কী প্রোডাক্ট আছে?', 'কী কী পাওয়া যায়?', 'কালেকশন দেখান'):
     * Politely highlight the available store categories ({$categoriesSummary}) and mention top popular products with their prices.
     * Invite them to specify what item they are looking for so you can help them choose.

4. DISCOUNTS & COUPONS:
   - If a customer asks about active discounts or coupon codes, share the active promo codes ({$couponsText}) and advise how to use them.

5. ORDER TRACKING:
   - If the customer asks about their order with an Order ID or Phone number, refer to the live order information provided below and give a clear, reassuring status update.

6. DO NOT SPAM LINKS:
   - Do NOT output website or product links in every sentence.
   - ONLY provide a link if the customer explicitly asks for a link, asks where to order online, asks for the website, or asks for the tracking/login page.
   - If the customer asks about product price, features, stock, delivery charge, return policy, payment options, or just chats, answer clearly and warmly WITHOUT markdown links.
7. CLEAR & HELPFUL: Provide direct and accurate answers (2-3 natural sentences). Do not write endless essays, but do not be abruptly cold or robotic either.
8. RELATIVE LINKS ONLY: If and ONLY when a link is genuinely appropriate, use relative markdown links like [আমাদের শপ](shop), [অর্ডার ট্র্যাক](track-order), or [পণ্যের নাম](p/slug). Never invent external domains.

STORE KNOWLEDGE:
- উপলব্ধ ক্যাটাগরি: {$categoriesSummary}
- ডেলিভারি চার্জ: ঢাকা সিটিতে ৳{$insideDhaka}, ঢাকার বাইরে ৳{$outsideDhaka}। ক্যাশ অন ডেলিভারি (COD) চালু আছে।
- ডেলিভারি সময়: সাধারণত ঢাকার ভিতরে ১-২ দিন এবং ঢাকার বাইরে ২-৪ কার্যদিবস।
- পেমেন্ট পদ্ধতি: বিকাশ অনলাইন পেমেন্ট এবং ক্যাশ অন ডেলিভারি (COD) উভয়ই চালু আছে।
- রিটার্ন পলিসি: পণ্য গ্রহণের ৭ দিনের মধ্যে সহজ রিটার্ন ও এক্সচেঞ্জ সুবিধা রয়েছে।{$couponsText}
- অর্ডার ট্র্যাকিং: গ্রাহক যদি ট্র্যাকিং লিংক চায়, তবে [অর্ডার ট্র্যাক করুন](track-order) লিংক দিন।
- ওয়েবসাইট / শপ: গ্রাহক যদি ওয়েবসাইট বা সব পণ্য দেখার লিংক চায়, তবে [আমাদের শপ](shop) লিংক দিন।
- লগইন / পাসওয়ার্ড: লগইন বা পাসওয়ার্ড সমস্যার জন্য [লগইন পেইজ](login) অথবা [পাসওয়ার্ড রিসেট](forgot-password) লিংক দেওয়া যেতে পারে।
- কাস্টমার কেয়ার / সাপোর্ট: " . (!empty($waNumber) ? "হোয়াটসঅ্যাপে যোগাযোগ: [হোয়াটসঅ্যাপ](https://wa.me/{$waNumber})" : "") . (!empty($supportPhone) ? " অথবা ফোন: {$supportPhone}" : "") . "।

STORE CATALOG OVERVIEW (TOP PRODUCTS & CURRENT STOCK):
{$storeCatalogOverview}

" . (!empty($orderContext) ? "{$orderContext}\n" : "") . "
" . (!empty($prodContext) ? "MATCHED CATALOG PRODUCTS (Interactive product cards are also displayed in the chat interface below, so talk naturally about them without repeating raw URLs):\n{$prodContext}" : "");

// Build conversation history for API
$provider = $settings['activeAiProvider'] ?? 'gemini';
$reply = "দুঃখিত, সংযোগে সাময়িক সমস্যা হচ্ছে। দয়া করে কিছুক্ষণ পর আবার চেষ্টা করুন অথবা আমাদের সরাসরি হোয়াটসঅ্যাপে যোগাযোগ করুন।";

if ($provider === 'openai' && !empty($settings['openaiApiKey'])) {
    $apiKey = $settings['openaiApiKey'];
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];
    $hasCurrentMessage = false;
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'user' : 'assistant';
        $messages[] = ['role' => $role, 'content' => $msg['content']];
        if ($msg['role'] === 'user' && $msg['content'] === $message) {
            $hasCurrentMessage = true;
        }
    }
    if (!$hasCurrentMessage) {
        $messages[] = ['role' => 'user', 'content' => $message];
    }
    
    $postData = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 350,
        'temperature' => 0.6
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    
    if ($res !== false) {
        $json = json_decode($res, true);
        if (isset($json['choices'][0]['message']['content'])) {
            $reply = $json['choices'][0]['message']['content'];
        }
    }
} else if (!empty($settings['geminiApiKey'])) {
    $apiKeys = array_filter(array_map('trim', explode(',', $settings['geminiApiKey'] ?? '')));
    $configuredModel = !empty($settings['geminiModelVersion']) ? $settings['geminiModelVersion'] : 'gemini-2.5-flash';
    $candidateModels = array_unique([$configuredModel, 'gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-2.5-flash-lite']);
    
    $contents = [];
    $hasCurrentMessage = false;
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => $msg['content']]]
        ];
        if ($msg['role'] === 'user' && $msg['content'] === $message) {
            $hasCurrentMessage = true;
        }
    }
    if (!$hasCurrentMessage) {
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $message]]
        ];
    }
    
    $postData = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 350,
            'temperature' => 0.6
        ]
    ];
    $jsonBody = json_encode($postData, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($jsonBody === false) {
        $jsonBody = json_encode($postData);
    }
    
    foreach ($apiKeys as $apiKey) {
        if (empty($apiKey)) continue;
        foreach ($candidateModels as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $res = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($res !== false && $httpcode === 200) {
                $json = json_decode($res, true);
                if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                    $reply = $json['candidates'][0]['content']['parts'][0]['text'];
                    break 2;
                }
            }
        }
    }
}

// Fallback message if AI failed to respond
if (empty($reply) || strpos($reply, 'দুঃখিত, সংযোগে') === 0) {
    if (!empty($waNumber)) {
        $reply = "যেকোনো সহায়তার জন্য সরাসরি আমাদের [হোয়াটসঅ্যাপে মেসেজ দিন](https://wa.me/{$waNumber})" . (!empty($supportPhone) ? " বা কল করুন: {$supportPhone}" : "") . "।";
    }
}

// PERSIST TO DATABASE: Save both user message and AI reply into `ai_chat_message`
try {
    // 1. Insert user message
    $uIns = $pdo->prepare("INSERT INTO ai_chat_message (session_id, user_id, role, content, products_json) VALUES (?, ?, 'user', ?, NULL)");
    $uIns->execute([$sessionId, $userId, $message]);

    // 2. Insert AI reply
    $prodsJson = !empty($carouselProducts) ? json_encode($carouselProducts, JSON_UNESCAPED_UNICODE) : null;
    $aIns = $pdo->prepare("INSERT INTO ai_chat_message (session_id, user_id, role, content, products_json) VALUES (?, ?, 'model', ?, ?)");
    $aIns->execute([$sessionId, $userId, trim($reply), $prodsJson]);
} catch (Exception $e) {}

ob_end_clean();
echo json_encode([
    'success' => true,
    'reply' => trim($reply),
    'products' => $carouselProducts
]);