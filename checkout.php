<?php
session_start();
require_once __DIR__ . '/db.php';

// Fetch Store Settings
try {
    $setStmt = $pdo->query("SELECT g.currencySymbol, g.storeName, g.supportEmail, g.guestCheckoutEnabled, d.deliveryInsideDhaka, d.deliveryOutsideDhaka, p.codEnabled, p.bkashEnabled, p.bkashLogo FROM SettingGeneral g LEFT JOIN SettingDelivery d ON d.id=g.id LEFT JOIN SettingPayment p ON p.id=g.id LIMIT 1");
    $settings = $setStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $settings = [];
}
$symbol = $settings['currencySymbol'] ?? '৳';
$storeName = $settings['storeName'] ?? '';

$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

if (!$isLoggedIn && empty($settings['guestCheckoutEnabled'])) {
    header("Location: login?redirect=checkout");
    exit;
}

$user = ['name' => '', 'email' => '', 'phone' => ''];
$lastOrder = ['city' => 'Dhaka', 'deliveryAddress' => ''];
$userId = null;
$isBlocked = false;

if ($isLoggedIn) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM User WHERE id = ?");
    $stmt->execute([$userId]);
    $fetchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fetchedUser) {
        $user = $fetchedUser;
        $isBlocked = ($user['status'] ?? 'active') === 'blocked';
    }
    
    $orderStmt = $pdo->prepare("SELECT city, deliveryAddress FROM `Order` WHERE userId = ? ORDER BY id DESC LIMIT 1");
    $orderStmt->execute([$userId]);
    $fetchedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if ($fetchedOrder) {
        $lastOrder = $fetchedOrder;
    }
}

$defaultAddress = !empty($user['address']) ? $user['address'] : ($lastOrder['deliveryAddress'] ?? '');

// Handle AJAX Request for fetching user by phone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_user_by_phone') {
    @ob_clean();
    header('Content-Type: application/json');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($phone) || strlen($phone) !== 11) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT u.name, u.email, u.address as userAddress, o.city, o.deliveryAddress as orderAddress FROM User u LEFT JOIN `Order` o ON u.id = o.userId WHERE u.phone = ? ORDER BY o.id DESC LIMIT 1");
        $stmt->execute([$phone]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $data['deliveryAddress'] = !empty($data['userAddress']) ? $data['userAddress'] : $data['orderAddress'];
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$allActiveCoupons = [];
$usedCoupons = [];
if ($isLoggedIn) {
    $couponsStmt = $pdo->prepare("SELECT * FROM Coupon WHERE status = 1 AND (expiryDate IS NULL OR expiryDate > NOW()) AND (usageLimit IS NULL OR usedCount < usageLimit) ORDER BY id DESC");
    $couponsStmt->execute();
    $allActiveCoupons = $couponsStmt->fetchAll(PDO::FETCH_ASSOC);

    $usedCouponsStmt = $pdo->prepare("SELECT couponCode, COUNT(*) as count FROM `Order` WHERE userId = ? AND status != 'CANCELLED' AND couponCode IS NOT NULL GROUP BY couponCode");
    $usedCouponsStmt->execute([$userId]);
    $usedCoupons = $usedCouponsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}


// Handle Apply Coupon AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_coupon') {
    @ob_clean();
    header('Content-Type: application/json');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subtotal = floatval($_POST['subtotal'] ?? 0);

    if (empty($code)) { echo json_encode(['success' => false, 'message' => 'Please enter a coupon code.']); exit; }
    if (empty($phone) && !$isLoggedIn) { echo json_encode(['success' => false, 'message' => 'Please enter your mobile number first.']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM Coupon WHERE code = ? AND status = 1");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) { echo json_encode(['success' => false, 'message' => 'Invalid or inactive coupon.']); exit; }
    if ($coupon['expiryDate'] && strtotime($coupon['expiryDate']) < time()) { echo json_encode(['success' => false, 'message' => 'This coupon has expired.']); exit; }
    if ($coupon['usageLimit'] > 0 && $coupon['usedCount'] >= $coupon['usageLimit']) { echo json_encode(['success' => false, 'message' => 'Coupon usage limit reached.']); exit; }
    if ($coupon['minSpend'] > 0 && $subtotal < $coupon['minSpend']) { echo json_encode(['success' => false, 'message' => 'Minimum spend of ৳' . number_format($coupon['minSpend']) . ' required.']); exit; }

    $cUserId = null; $orderCount = 0;
    if ($isLoggedIn) {
        $cUserId = $_SESSION['user_id'];
        $uStmt = $pdo->prepare("SELECT phone, email FROM User WHERE id = ?");
        $uStmt->execute([$cUserId]);
        $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uData) { $phone = $phone ?: $uData['phone']; $email = $email ?: $uData['email']; }
    } else if (!empty($phone)) {
        $uStmt = $pdo->prepare("SELECT id FROM User WHERE phone = ? LIMIT 1");
        $uStmt->execute([$phone]);
        $cUserId = $uStmt->fetchColumn();
    }

    if ($cUserId) {
        $oStmt = $pdo->prepare("SELECT COUNT(*) FROM `Order` WHERE userId = ? AND status != 'CANCELLED'");
        $oStmt->execute([$cUserId]);
        $orderCount = $oStmt->fetchColumn();
    }

    if ($coupon['couponType'] === 'WELCOME') {
        if ($orderCount > 0) { echo json_encode(['success' => false, 'message' => 'This coupon is valid for first-time orders only.']); exit; }
    } else if ($coupon['couponType'] === 'USER_SPECIFIC') {
        $allowed = array_map('trim', explode(',', $coupon['applicableData'] ?? ''));
        if (!in_array($phone, $allowed) && !in_array($email, $allowed)) { echo json_encode(['success' => false, 'message' => 'This coupon is not applicable for your account.']); exit; }
    }

    if ($coupon['usagePerUser'] > 0 && $cUserId) {
        $uStmt = $pdo->prepare("SELECT COUNT(*) FROM `Order` WHERE couponCode = ? AND userId = ? AND status != 'CANCELLED'");
        $uStmt->execute([$code, $cUserId]);
        $usedByUser = $uStmt->fetchColumn();
        if ($usedByUser >= $coupon['usagePerUser']) { echo json_encode(['success' => false, 'message' => 'You have already used this coupon maximum allowed times.']); exit; }
    }

    $discount = 0;
    if ($coupon['discountType'] === 'PERCENTAGE') {
        $discount = ($subtotal * $coupon['discountAmount']) / 100;
    } else {
        $discount = $coupon['discountAmount'];
    }
    if ($discount > $subtotal) { $discount = $subtotal; }

    echo json_encode(['success' => true, 'discount' => $discount, 'code' => $coupon['code'], 'message' => 'Coupon applied successfully!']);
    exit;
}

// Handle Order Placement via AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    @ob_clean();
    header('Content-Type: application/json');
    try {
        $phone = trim($_POST['phone'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? 'CASH ON DELIVERY';
        $cartData = json_decode($_POST['cart_data'], true);
        
        if (empty($phone) || !preg_match('/^[0-9]{11}$/', $phone)) {
            throw new Exception("Please enter a valid 11-digit mobile number.");
        }
        
        if (empty($name) || empty($city) || empty($address)) {
            throw new Exception("Please fill out all required delivery information.");
        }
        if (empty($cartData)) {
            throw new Exception("Your cart is empty.");
        }

        // Calculate total required advance payment
        $productIds = [];
        foreach ($cartData as $item) {
            $id = (int)($item['productId'] ?? $item['id']);
            if ($id > 0 && !in_array($id, $productIds)) {
                $productIds[] = $id;
            }
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

        if (!empty($productIds)) {
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
        }

        // Fetch API Settings for Fraud Checker
        try {
            $apiStmt = $pdo->query("SELECT * FROM SettingApi LIMIT 1");
            $apiSettings = $apiStmt ? $apiStmt->fetch(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {
            $apiSettings = [];
        }
        
        // Fraud Checker Block Logic
        if (!empty($apiSettings['fraudCheckerAutoBlock']) && $paymentMethod === 'CASH ON DELIVERY') {
            $minRate = (int)($apiSettings['fraudCheckerMinRate'] ?? 50);
            
            // Check success rate internally based on phone
            $historyStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_resolved,
                    SUM(CASE WHEN o.status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered
                FROM `Order` o 
                JOIN User u ON o.userId = u.id 
                WHERE u.phone = ? AND o.status IN ('DELIVERED', 'CANCELLED')
            ");
            $historyStmt->execute([$phone]);
            $history = $historyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($history && $history['total_resolved'] > 0) {
                $successRate = ($history['delivered'] / $history['total_resolved']) * 100;
                if ($successRate < $minRate) {
                    throw new Exception("Your delivery success rate is " . round($successRate) . "%. Minimum required is {$minRate}%. Cash on Delivery is disabled for your account. Please use an advance payment method.");
                }
            }
        }
        
        // Calculate Subtotal safely by fetching actual prices from Database
        $subtotal = 0;
        foreach ($cartData as &$item) {
            $productId = (int)($item['productId'] ?? $item['id']);
            $qty = intval($item['quantity']);
            $size = $item['size'] ?? 'Standard';
            $color = $item['color'] ?? 'Default';
            
            // Check Stock Availability
            $stockStmt = $pdo->prepare("SELECT id, stock FROM Variant WHERE productId = ? AND size = ? AND color = ?");
            $stockStmt->execute([$productId, $size, $color]);
            $variantData = $stockStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$variantData || $variantData['stock'] < $qty) {
                throw new Exception("Product '{$item['name']}' with selected size/color is out of stock.");
            }
            
            $item['variantId'] = $variantData['id']; // We can use this later
            
            $priceStmt = $pdo->prepare("SELECT basePrice, flashDealPrice, isFlashDeal FROM Product WHERE id = ?");
            $priceStmt->execute([$productId]);
            $pData = $priceStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pData) {
                $setStmt = $pdo->query("SELECT flashDealEndTime FROM SettingGeneral LIMIT 1");
                $settingsData = $setStmt->fetch(PDO::FETCH_ASSOC);
                $isFlashDealActive = !empty($pData['isFlashDeal']) && strtotime($settingsData['flashDealEndTime'] ?? '0') > time();
                
                $actualPrice = $isFlashDealActive && !empty($pData['flashDealPrice']) ? floatval($pData['flashDealPrice']) : floatval($pData['basePrice']);
                $item['price'] = $actualPrice; // Override frontend price with real DB price
                $subtotal += $actualPrice * $qty;
            } else {
                throw new Exception("One or more products in your cart are no longer available.");
            }
        }
        unset($item);
        
        $couponCode = $_POST['coupon_code'] ?? null;
        $discountAmount = 0;

        if (!empty($couponCode)) {
            $stmt = $pdo->prepare("SELECT * FROM Coupon WHERE code = ? AND status = 1");
            $stmt->execute([$couponCode]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($coupon && (!$coupon['expiryDate'] || strtotime($coupon['expiryDate']) >= time())) {
                if ($coupon['minSpend'] == 0 || $subtotal >= $coupon['minSpend']) {
                    // Additional security checks for coupon could be added here
                    $isValid = true;
                    if ($coupon['couponType'] === 'USER_SPECIFIC') {
                        $allowed = array_map('trim', explode(',', $coupon['applicableData'] ?? ''));
                        if (!in_array($phone, $allowed) && !in_array($email, $allowed)) {
                            $isValid = false;
                        }
                    }
                    if ($isValid) {
                        if ($coupon['discountType'] === 'PERCENTAGE') {
                            $discountAmount = ($subtotal * $coupon['discountAmount']) / 100;
                        } else {
                            $discountAmount = $coupon['discountAmount'];
                        }
                        if ($discountAmount > $subtotal) $discountAmount = $subtotal;
                        
                        $pdo->prepare("UPDATE Coupon SET usedCount = usedCount + 1 WHERE id = ?")->execute([$coupon['id']]);
                    } else {
                        $couponCode = null;
                    }
                } else {
                    $couponCode = null;
                }
            } else {
                $couponCode = null;
            }
        }
        
        // Determine Shipping Charge
        $shippingCharge = (strtolower($city) === 'dhaka') ? floatval($settings['deliveryInsideDhaka']) : floatval($settings['deliveryOutsideDhaka']);
        $total = $subtotal + $shippingCharge - $discountAmount;
        if ($total < 0) $total = 0;

        if ($totalRequiredAdvance > 0 && $paymentMethod === 'CASH ON DELIVERY') {
            throw new Exception("আপনার কার্টে থাকা প্রোডাক্টগুলোর জন্য অগ্রিম পেমেন্ট আবশ্যক। দয়া করে 'Advance Payment' অথবা 'Full Payment' সিলেক্ট করুন।");
        }

        $advanceAmount = 0;
        if ($paymentMethod === 'BKASH_ADVANCE') {
            $advanceAmount = min($totalRequiredAdvance, $total);
        } elseif ($paymentMethod === 'BKASH') {
            $advanceAmount = $total;
        }
        
        $pdo->beginTransaction();
        
        $orderUserId = null;
        if (!$isLoggedIn) {
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT id FROM User WHERE phone = ? OR email = ? LIMIT 1");
                $stmt->execute([$phone, $email]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM User WHERE phone = ? LIMIT 1");
                $stmt->execute([$phone]);
            }
            $existingUserId = $stmt->fetchColumn();
            
            if ($existingUserId) {
                $orderUserId = $existingUserId;
                $pdo->prepare("UPDATE User SET name = ?, phone = ? WHERE id = ?")->execute([$name, $phone, $orderUserId]);
            } else {
                $safeDomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $storeName ?: 'store')) . '.com';
                $dummyEmail = !empty($email) ? $email : 'guest_' . time() . '@' . $safeDomain;
                $dummyPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                
                do {
                    $orderUserId = mt_rand(100000, 999999);
                    $check = $pdo->prepare("SELECT id FROM User WHERE id = ?");
                    $check->execute([$orderUserId]);
                } while ($check->fetch());
                
                $stmt = $pdo->prepare("INSERT INTO User (id, name, email, phone, password, role) VALUES (?, ?, ?, ?, ?, 'customer')");
                $stmt->execute([$orderUserId, $name, $dummyEmail, $phone, $dummyPass]);
            }
        } else {
            $orderUserId = $userId;
            $checkStatus = $pdo->prepare("SELECT status FROM User WHERE id = ?");
            $checkStatus->execute([$orderUserId]);
            if ($checkStatus->fetchColumn() === 'blocked') {
                throw new Exception("Your account has been blocked. You cannot place orders.");
            }
            if (empty($user['phone'])) {
                $pdo->prepare("UPDATE User SET phone = ? WHERE id = ?")->execute([$phone, $orderUserId]);
            }
        }

        if ($paymentMethod !== 'BKASH' && $paymentMethod !== 'BKASH_ADVANCE') {
            // Generate Unique Order ID (6 digits)
            do {
                $orderId = mt_rand(100000, 999999);
                $check = $pdo->prepare("SELECT id FROM `Order` WHERE id = ?");
                $check->execute([$orderId]);
            } while ($check->fetch());
            
            // Insert into Order table
            $stmt = $pdo->prepare("INSERT INTO `Order` (id, userId, total, shippingCharge, deliveryAddress, city, paymentMethod, status, couponCode, discountAmount, advanceAmount) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?)");
            $stmt->execute([$orderId, $orderUserId, $total, $shippingCharge, $address, $city, $paymentMethod, $couponCode, $discountAmount, $advanceAmount]);
            
            // Insert into OrderItem table and reduce stock
            foreach ($cartData as $item) {
                $productId = $item['productId'] ?? $item['id'];
                $variantId = $item['variantId'] ?? null;
                $qty = intval($item['quantity']);
                $price = floatval($item['price']);
                
                $stmt = $pdo->prepare("INSERT INTO OrderItem (orderId, productId, variantId, quantity, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$orderId, $productId, $variantId, $qty, $price]);
                
                if ($variantId) {
                    $pdo->prepare("UPDATE Variant SET stock = stock - ? WHERE id = ?")->execute([$qty, $variantId]);
                }
            }

        // Send Notification
        $notifMsg = "Your order #$orderId has been placed successfully and is pending confirmation.";
        $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (?, 'Order Placed', ?, 'order')")->execute([$orderUserId, $notifMsg]);
        $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'New Order Received', ?, 'order')")->execute(["Order #$orderId has been placed by " . $name . "."]);

        $notifStmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
        $notifSettings = $notifStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Send Order Placed Email
        if (!empty($notifSettings['emailEnabled']) && !empty($email)) {
            $template = $notifSettings['orderPlacedTemplate'] ?? '';
            if ($template) {
                $itemsHtml = '';
                foreach ($cartData as $item) {
                    $itemName = htmlspecialchars($item['name'] ?? 'Item');
                    $qty = intval($item['quantity']);
                    $price = floatval($item['price']) * $qty;
                    $itemsHtml .= '<tr><td style="padding-bottom: 10px;">' . $itemName . ' x ' . $qty . '</td><td style="text-align: right; padding-bottom: 10px; font-weight: 600; color: #0f172a;">' . $symbol . number_format($price) . '</td></tr>';
                }
                if ($discountAmount > 0) {
                    $itemsHtml .= '<tr><td style="padding-bottom: 10px; color: #10b981;">Discount</td><td style="text-align: right; padding-bottom: 10px; font-weight: 600; color: #10b981;">-' . $symbol . number_format($discountAmount) . '</td></tr>';
                }
                $itemsHtml .= '<tr><td style="padding-bottom: 10px;">Shipping (' . htmlspecialchars($city) . ')</td><td style="text-align: right; padding-bottom: 10px; font-weight: 600; color: #0f172a;">' . $symbol . number_format($shippingCharge) . '</td></tr>';
                
                $supportEmail = $settings['supportEmail'] ?? 'support@' . $_SERVER['HTTP_HOST'];
                $totalStr = $symbol . number_format($total);
                
                $search = ['{{customer_name}}', '{{order_id}}', '{{store_name}}', 'Idea Mart', '{{order_items_html}}', '{{total_amount}}', '{{shipping_address}}', '{{customer_phone}}', '{{support_email}}'];
                $replace = [$name, str_pad($orderId, 6, '0', STR_PAD_LEFT), $storeName, $storeName, $itemsHtml, $totalStr, nl2br(htmlspecialchars($address)), htmlspecialchars($phone), $supportEmail];
                $htmlContent = str_replace($search, $replace, $template);
                
                require_once __DIR__ . '/api/mailer_helper.php';
                $result = sendDynamicEmail($pdo, $email, "Order Confirmed - #" . str_pad($orderId, 6, '0', STR_PAD_LEFT), $htmlContent);
                if (is_array($result) && empty($result['success'])) {
                    $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'Email Delivery Error', ?, 'alert')")->execute(["Failed to send order confirmation email to $email for Order #$orderId. Error: " . ($result['error'] ?? 'Unknown')]);
                }
            }
        }
        
        // Order Placed SMS disabled (SMS reserved for OTP and Parcel Delivery only)
        
        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId, 'email' => $email, 'payment_method' => $paymentMethod]);
        exit;
        } else {
            $_SESSION['pending_bkash_order'] = [
                'userId' => $orderUserId,
                'total' => $total,
                'shippingCharge' => $shippingCharge,
                'deliveryAddress' => $address,
                'city' => $city,
                'paymentMethod' => $paymentMethod,
                'couponCode' => $couponCode,
                'discountAmount' => $discountAmount,
                'advanceAmount' => $advanceAmount,
                'cartData' => $cartData,
                'customer_name' => $name,
                'customer_email' => $email,
                'customer_phone' => $phone
            ];
            $pdo->commit();
            echo json_encode(['success' => true, 'order_id' => 'pending', 'email' => $email, 'payment_method' => $paymentMethod]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

include 'Header.php';
?>

<style>
    /* Realistic Box to Truck Checkout Animation */
    .btn-anim-wrapper { position: relative; overflow: hidden; }
    .btn-anim-wrapper .default-content { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 100%; }
    .btn-anim-wrapper.is-animating .default-content { opacity: 0; transform: translateY(25px) scale(0.9); }
    
    .anim-layer { position: absolute; inset: 0; pointer-events: none; display: flex; align-items: center; justify-content: center; opacity: 0; }
    .btn-anim-wrapper.is-animating .anim-layer { opacity: 1; }
    
    .anim-truck { position: absolute; opacity: 0; color: inherit; transform: translateX(-60px); }
    .btn-anim-wrapper.is-animating .anim-truck { animation: truckSequence 2.5s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
    
    .anim-box { position: absolute; opacity: 0; color: inherit; margin-top: -4px; margin-left: -10px; z-index: 10; }
    .btn-anim-wrapper.is-animating .anim-box { animation: boxLoadSequence 2.5s cubic-bezier(0.4, 0, 0.2, 1) forwards; }

    .anim-speed-line { position: absolute; width: 12px; height: 3px; background: currentColor; border-radius: 4px; opacity: 0; bottom: 10px; left: 20%; }
    .btn-anim-wrapper.is-animating .anim-speed-line { animation: speedLineSequence 2.5s ease-in-out forwards; }

    @keyframes truckSequence {
        0% { opacity: 0; transform: translateX(-60px); }
        15% { opacity: 1; transform: translateX(0); }
        20% { opacity: 1; transform: translateX(0) translateY(2px); } /* Suspension bump */
        25% { opacity: 1; transform: translateX(0) translateY(0); }
        60% { opacity: 1; transform: translateX(0); }
        100% { opacity: 0; transform: translateX(120px); }
    }
    
    @keyframes boxLoadSequence {
        0%, 15% { opacity: 0; transform: translateY(-40px) scale(0.8) rotate(-10deg); }
        25% { opacity: 1; transform: translateY(0) scale(1) rotate(0deg); }
        30% { opacity: 1; transform: translateY(-6px) scale(1) rotate(0deg); }
        35% { opacity: 1; transform: translateY(0) scale(1) rotate(0deg); }
        60% { opacity: 1; transform: translateY(0) scale(1); }
        100% { opacity: 0; transform: translate(120px, 0) scale(1); }
    }
    
    @keyframes speedLineSequence {
        0%, 60% { opacity: 0; transform: translateX(0); }
        65% { opacity: 0.6; transform: translateX(-10px) scaleX(1.5); }
        100% { opacity: 0; transform: translateX(-40px) scaleX(3); }
    }
</style>

<div class="min-h-screen pt-24 md:pt-28 pb-28 md:pb-24 bg-slate-50 font-sans">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6">
        
        <?php if (isset($_GET['success']) && isset($_GET['order_id'])): ?>
            <!-- SUCCESS STATE -->
            <div class="max-w-xl mx-auto bg-white p-6 sm:p-12 rounded-3xl sm:rounded-[2rem] shadow-xl border border-slate-100 text-center animate-in zoom-in duration-500 mt-4 sm:mt-8">
                <div class="w-24 h-24 bg-emerald-100 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                </div>
                <h1 class="text-2xl sm:text-4xl font-black text-slate-900 mb-3 tracking-tight">Order Confirmed!</h1>
                <p class="text-slate-500 font-medium mb-6 sm:mb-8 text-sm sm:text-base leading-relaxed">Thank you for your purchase. We've received your order and our team will start processing it soon.</p>
                
                <div class="bg-slate-50 rounded-2xl p-4 sm:p-5 mb-6 sm:mb-8 border border-slate-200 shadow-inner inline-block min-w-[200px]">
                    <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-1">Your Order ID</p>
                    <p class="text-2xl sm:text-3xl font-black text-[#003e86]">#<?php echo htmlspecialchars($_GET['order_id']); ?></p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-3 text-sm sm:text-base">
                    <a href="shop" class="flex-1 py-3.5 sm:py-4 bg-white border-2 border-slate-200 text-slate-700 hover:border-[#003e86] hover:text-[#003e86] hover:bg-blue-50 rounded-xl font-bold transition-all active:scale-95">Continue Shopping</a>
                    <form action="track-order" method="POST" class="flex-1">
                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($_GET['order_id']); ?>">
                        <input type="hidden" name="contact" value="<?php echo htmlspecialchars($_GET['email'] ?? $user['email']); ?>">
                        <button type="submit" class="w-full py-3.5 sm:py-4 bg-[#003e86] hover:bg-blue-800 text-white rounded-xl font-bold shadow-xl shadow-blue-900/20 transition-all active:scale-95">Track Order</button>
                    </form>
                </div>
            </div>
            <script>
                if (sessionStorage.getItem('clearing_buy_now')) {
                    sessionStorage.removeItem('buyNowCart');
                    sessionStorage.removeItem('clearing_buy_now');
                }
                if (sessionStorage.getItem('clearing_cart')) {
                    localStorage.removeItem('cart');
                    sessionStorage.removeItem('clearing_cart');
                }
                window.dispatchEvent(new Event('cartUpdated'));
            </script>
        <?php elseif (isset($_GET['payment_failed'])): ?>
            <!-- FAILURE STATE -->
            <div class="max-w-xl mx-auto bg-white p-6 sm:p-12 rounded-3xl sm:rounded-[2rem] shadow-xl border border-slate-100 text-center animate-in zoom-in duration-500 mt-4 sm:mt-8">
                <div class="w-24 h-24 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <h1 class="text-2xl sm:text-4xl font-black text-slate-900 mb-3 tracking-tight">Payment Failed</h1>
                <p class="text-slate-500 font-medium mb-6 sm:mb-8 text-sm sm:text-base leading-relaxed">
                    <?php echo htmlspecialchars($_GET['msg'] ?? "Unfortunately, your payment could not be processed or was cancelled. Your order was not placed. You can try again."); ?>
                </p>
                
                <div class="flex flex-col sm:flex-row gap-3 text-sm sm:text-base">
                    <a href="checkout" class="flex-1 py-3.5 sm:py-4 bg-[#003e86] hover:bg-blue-800 text-white rounded-xl font-bold shadow-xl shadow-blue-900/20 transition-all active:scale-95">Try Again</a>
                    <a href="shop" class="flex-1 py-3.5 sm:py-4 bg-white border-2 border-slate-200 text-slate-700 hover:border-[#003e86] hover:text-[#003e86] hover:bg-blue-50 rounded-xl font-bold transition-all active:scale-95">Return to Shop</a>
                </div>
            </div>
        <?php else: ?>
            <!-- CHECKOUT FORM -->
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 mb-5 sm:mb-8 tracking-tight">Checkout</h1>
            
            <?php if ($isBlocked): ?>
            <div class="mb-5 sm:mb-8 bg-rose-50 border border-rose-200 rounded-2xl sm:rounded-3xl p-4 sm:p-6 shadow-sm flex items-start gap-3 sm:gap-4 text-rose-700">
                <div class="w-12 h-12 bg-rose-100 rounded-full flex items-center justify-center shrink-0">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                </div>
                <div>
                    <h3 class="font-black text-base md:text-lg">Account Suspended</h3>
                    <p class="text-sm font-medium mt-1 leading-relaxed text-rose-600">Your account has been restricted by the administrator. You cannot place new orders.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isLoggedIn): ?>
            <div class="mb-5 sm:mb-6 bg-gradient-to-r from-blue-50 to-indigo-50/60 border border-blue-200/80 rounded-2xl p-3.5 sm:p-4 flex items-center justify-between gap-3 text-xs sm:text-sm shadow-sm">
                <div class="flex items-center gap-2.5 sm:gap-3 text-slate-700">
                    <div class="w-8 h-8 rounded-xl bg-blue-600/10 text-[#003e86] flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <p class="font-medium text-slate-700">Already have an account? <span class="hidden sm:inline text-slate-500">Log in for 1-click checkout & member perks.</span></p>
                </div>
                <a href="login?redirect=checkout" class="shrink-0 px-3.5 py-1.5 bg-[#003e86] hover:bg-blue-800 text-white font-bold rounded-lg text-xs transition-all shadow-sm shadow-blue-900/10 active:scale-95">Log In</a>
            </div>
            <?php endif; ?>

            <form id="checkout-form" class="grid grid-cols-1 lg:grid-cols-12 gap-5 sm:gap-8 lg:gap-12">
                <!-- Left: Shipping Details -->
                <div class="lg:col-span-7 space-y-8">
                    
                <!-- Step 1: Mobile Number Section -->
                    <div class="bg-white p-4 sm:p-8 rounded-2xl sm:rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-[#003e86]"></div>
                        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
                            <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-blue-50 text-[#003e86] flex items-center justify-center font-black text-xs sm:text-sm shrink-0">1</div>
                            <h2 class="text-lg sm:text-xl font-black text-slate-800 tracking-tight">Contact Information</h2>
                        </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1 block">Mobile Number <span class="text-rose-500">*</span></label>
                        <input type="tel" id="checkout-phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required placeholder="e.g. 01700000000" pattern="[0-9]{11}" maxlength="11" class="w-full px-4 py-2.5 sm:px-5 sm:py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-bold focus:bg-white focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed" <?php echo ($isLoggedIn && !empty($user['phone'])) ? 'readonly' : ''; ?> <?php echo $isBlocked ? 'disabled' : ''; ?>>
                        <p id="phone-msg" class="text-[11px] text-[#003e86] hidden ml-1 font-bold mt-2 flex items-center gap-1.5"><svg class="animate-spin w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2v4"/></svg> Checking details...</p>
                        </div>
                    </div>

                <!-- Hidden Sections for Guest -->
                <div id="hidden-checkout-sections" class="animate-in fade-in slide-in-from-top-4 duration-500 <?php echo $isLoggedIn ? 'space-y-4 sm:space-y-8' : 'hidden space-y-4 sm:space-y-8'; ?>">
                    <!-- Shipping Address -->
                    <div class="bg-white p-4 sm:p-8 rounded-2xl sm:rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-[#003e86]"></div>
                        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
                            <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-blue-50 text-[#003e86] flex items-center justify-center font-black text-xs sm:text-sm shrink-0">2</div>
                            <h2 class="text-lg sm:text-xl font-black text-slate-800 tracking-tight">Delivery Details</h2>
                        </div>
                        <div class="space-y-3 sm:space-y-5">
                            <input type="hidden" id="checkout-email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1 block">Full Name <span class="text-rose-500">*</span></label>
                                <input type="text" id="checkout-name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required <?php echo $isLoggedIn ? 'readonly' : ''; ?> class="w-full px-4 py-2.5 sm:px-5 sm:py-3.5 <?php echo $isLoggedIn ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-50 text-slate-800'; ?> border border-slate-200 rounded-xl text-xs sm:text-sm font-bold focus:bg-white focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed" <?php echo $isBlocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1 block">City / Region <span class="text-rose-500">*</span></label>
                                <select id="city-select" name="city" onchange="renderSummary()" required class="w-full px-4 py-2.5 sm:px-5 sm:py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-bold focus:bg-white focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" <?php echo $isBlocked ? 'disabled' : ''; ?>>
                                    <option value="Dhaka" <?php echo ($lastOrder['city'] ?? '') === 'Dhaka' ? 'selected' : ''; ?>>Inside Dhaka</option>
                                    <option value="Outside Dhaka" <?php echo ($lastOrder['city'] ?? '') === 'Outside Dhaka' ? 'selected' : ''; ?>>Outside Dhaka</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1 block">Full Address <span class="text-rose-500">*</span></label>
                                <textarea id="checkout-address" name="address" required rows="3" placeholder="House/Flat No, Area, Thana..." class="w-full px-4 py-2.5 sm:px-5 sm:py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-bold focus:bg-white focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all resize-none disabled:opacity-50 disabled:cursor-not-allowed" <?php echo $isBlocked ? 'disabled' : ''; ?>><?php echo htmlspecialchars($defaultAddress); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="bg-white p-4 sm:p-8 rounded-2xl sm:rounded-[2rem] border border-slate-100 shadow-sm relative transition-all hover:shadow-md">
                        <div class="flex items-center gap-3 sm:gap-4 mb-4 sm:mb-6">
                            <div class="w-7 h-7 sm:w-10 sm:h-10 rounded-xl sm:rounded-2xl bg-gradient-to-br from-blue-500 to-[#003e86] text-white flex items-center justify-center font-black text-xs sm:text-base shadow-lg shadow-blue-500/30 transform -rotate-3 shrink-0">3</div>
                            <h2 class="text-lg sm:text-2xl font-black text-slate-800 tracking-tight">Payment Method</h2>
                        </div>
                        <div id="advance-payment-alert" class="hidden mb-5 p-4 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-2xl text-sm font-bold flex items-center gap-3 shadow-sm">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>এই অর্ডারটি কনফার্ম করতে অগ্রিম পেমেন্ট আবশ্যক। দয়া করে সম্পূর্ণ পেমেন্ট অথবা শুধুমাত্র অগ্রিম পেমেন্ট অপশনটি সিলেক্ট করুন।</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:gap-5">
                            <?php if (!empty($settings['codEnabled'])): ?>
                            <label class="payment-radio relative flex sm:flex-col items-center sm:items-start gap-3 sm:gap-4 p-3.5 sm:p-5 rounded-xl sm:rounded-[1.5rem] border-2 border-emerald-500 bg-emerald-50/50 cursor-pointer transition-all hover:shadow-lg hover:shadow-emerald-500/10 hover:-translate-y-1 duration-300 col-span-2 sm:col-span-1">
                                <div class="hidden sm:block absolute top-0 right-0 bg-emerald-500 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-bl-[1rem] rounded-tr-[1.25rem] shadow-sm">Pay at Home</div>
                                <input type="radio" name="payment_method" value="CASH ON DELIVERY" checked class="peer sr-only">
                                <div class="w-12 h-10 sm:w-20 sm:h-14 bg-white rounded-xl sm:rounded-2xl flex items-center justify-center shadow-sm border border-emerald-100 shrink-0">
                                    <svg width="20" height="20" class="sm:w-6 sm:h-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="font-black text-slate-800 text-sm sm:text-base block truncate">Cash on Delivery</span>
                                    <span class="text-[10px] sm:text-[11px] text-slate-500 font-semibold mt-0.5 leading-relaxed block truncate">Pay with cash upon delivery.</span>
                                </div>
                                <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 border-emerald-500 flex items-center justify-center bg-emerald-500 text-white transition-all radio-check shrink-0 shadow-sm">
                                    <svg width="10" height="10" class="sm:w-3.5 sm:h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                            </label>
                            <?php endif; ?>
                            <?php if (!empty($settings['bkashEnabled'])): ?>
                            <label class="payment-radio relative flex sm:flex-col items-center sm:items-start gap-3 sm:gap-4 p-3.5 sm:p-5 rounded-xl sm:rounded-[1.5rem] border-2 border-slate-200 bg-white cursor-pointer transition-all hover:shadow-lg hover:shadow-pink-500/10 hover:border-pink-300 hover:-translate-y-1 duration-300">
                                <div class="hidden sm:block absolute top-0 right-0 bg-pink-500 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-bl-[1rem] rounded-tr-[1.25rem] shadow-sm">Fast & Secure</div>
                                <input type="radio" name="payment_method" value="BKASH" <?php echo empty($settings['codEnabled']) ? 'checked' : ''; ?> class="peer sr-only">
                                <div class="w-12 h-10 sm:w-20 sm:h-14 bg-white rounded-xl sm:rounded-2xl flex items-center justify-center shadow-sm border border-slate-100 p-1.5 sm:p-2 shrink-0">
                                    <?php if (!empty($settings['bkashLogo'])): ?>
                                        <img src="<?php echo htmlspecialchars(ltrim($settings['bkashLogo'], '/')); ?>" alt="bKash" class="w-full h-full object-contain">
                                    <?php else: ?>
                                        <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full">
                                          <path d="M256 0C114.615 0 0 114.615 0 256C0 397.385 114.615 512 256 512C397.385 512 512 397.385 512 256C512 114.615 397.385 0 256 0Z" fill="#E2136E"/>
                                          <path d="M331.066 333.155H240.231L204.606 373.35H132.551V148.271H331.066V333.155ZM226.438 231.815H273.197V269.497H226.438V231.815Z" fill="white"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="font-black text-slate-800 text-sm sm:text-base block truncate">Full Payment (bKash)</span>
                                    <span class="text-[10px] sm:text-[11px] text-slate-500 font-semibold mt-0.5 leading-relaxed block truncate">Pay the total amount securely now.</span>
                                </div>
                                <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 border-slate-300 flex items-center justify-center text-transparent transition-all radio-check shrink-0 shadow-sm">
                                    <svg width="10" height="10" class="sm:w-3.5 sm:h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                            </label>
                            <?php endif; ?>
                            
                            <label id="bkash-advance-option" class="payment-radio relative flex sm:flex-col items-center sm:items-start gap-3 sm:gap-4 p-3.5 sm:p-5 rounded-xl sm:rounded-[1.5rem] border-2 border-slate-200 bg-white cursor-pointer transition-all hover:shadow-lg hover:shadow-indigo-500/10 hover:border-indigo-300 hover:-translate-y-1 duration-300 hidden">
                                <div class="hidden sm:block absolute top-0 right-0 bg-indigo-500 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-bl-[1rem] rounded-tr-[1.25rem] shadow-sm">Partial Payment</div>
                                <input type="radio" name="payment_method" value="BKASH_ADVANCE" class="peer sr-only">
                                <div class="w-12 h-10 sm:w-20 sm:h-14 bg-white rounded-xl sm:rounded-2xl flex items-center justify-center shadow-sm border border-slate-100 p-1.5 sm:p-2 shrink-0">
                                    <?php if (!empty($settings['bkashLogo'])): ?>
                                        <img src="<?php echo htmlspecialchars(ltrim($settings['bkashLogo'], '/')); ?>" alt="bKash" class="w-full h-full object-contain">
                                    <?php else: ?>
                                        <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full opacity-90">
                                          <path d="M256 0C114.615 0 0 114.615 0 256C0 397.385 114.615 512 256 512C397.385 512 512 397.385 512 256C512 114.615 397.385 0 256 0Z" fill="#E2136E"/>
                                          <path d="M331.066 333.155H240.231L204.606 373.35H132.551V148.271H331.066V333.155ZM226.438 231.815H273.197V269.497H226.438V231.815Z" fill="white"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span id="bkash-advance-title" class="font-black text-slate-800 text-sm sm:text-base block truncate">Advance Payment (bKash)</span>
                                    <span id="bkash-advance-label" class="text-[10px] sm:text-[11px] text-slate-500 font-semibold mt-0.5 leading-relaxed block">Pay Advance online, Rest on Delivery.</span>
                                </div>
                                <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 border-slate-300 flex items-center justify-center text-transparent transition-all radio-check shrink-0 shadow-sm">
                                    <svg width="10" height="10" class="sm:w-3.5 sm:h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                            </label>

                            <?php if (empty($settings['codEnabled']) && empty($settings['bkashEnabled'])): ?>
                                <p class="text-sm text-rose-500 font-bold bg-rose-50 p-3 sm:p-4 rounded-xl col-span-2">No payment methods available right now. Please contact support.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Right: Order Summary -->
                <div class="lg:col-span-5">
                    <div class="bg-white rounded-2xl sm:rounded-[2rem] shadow-[0_0_40px_-10px_rgba(0,0,0,0.08)] border border-slate-100 p-4 sm:p-8 sticky top-28 overflow-hidden relative">
                        <h2 class="text-lg sm:text-xl font-black text-slate-900 mb-4 sm:mb-6">Order Summary</h2>
                        <div id="checkout-items" class="max-h-[35vh] overflow-y-auto custom-scrollbar pr-2 mb-4 sm:mb-6"></div>
                        
                        <!-- Coupon Accordion -->
                        <div class="border-t border-slate-100 pt-5 pb-2">
                            <button type="button" onclick="const s = document.getElementById('coupon-section'); s.classList.toggle('hidden'); this.querySelector('.chevron').classList.toggle('rotate-180');" class="w-full flex items-center justify-between text-sm font-bold text-slate-700 hover:text-[#003e86] transition-colors group py-1">
                                <span class="flex items-center gap-2">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400 group-hover:text-[#003e86] transition-colors"><path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572"/></svg>
                                    Have a coupon code?
                                </span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chevron transition-transform duration-300 text-slate-400"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            
                            <div id="coupon-section" class="hidden mt-4 animate-in slide-in-from-top-2 duration-300">
                                <div class="flex gap-2">
                                    <input type="text" id="coupon-input" placeholder="Enter promo code" class="flex-1 px-3 py-2 sm:px-4 sm:py-3 bg-white border border-slate-200 rounded-xl text-xs sm:text-sm font-bold uppercase focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all shadow-sm" autocomplete="off">
                                    <button type="button" onclick="applyCoupon()" id="apply-coupon-btn" class="px-4 py-2 sm:px-5 sm:py-3 bg-slate-900 text-white rounded-xl font-bold text-xs sm:text-sm shadow-md hover:bg-black transition-all active:scale-95">Apply</button>
                                </div>
                                <p id="coupon-msg" class="text-[11px] font-bold mt-2 hidden ml-1"></p>

                                <?php if ($isLoggedIn && !empty($allActiveCoupons)): ?>
                                <div class="mt-4 space-y-2">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Available for you</p>
                                    <div class="space-y-2 max-h-40 overflow-y-auto custom-scrollbar pr-1">
                                <?php foreach($allActiveCoupons as $c): 
                                    $usedCount = $usedCoupons[$c['code']] ?? 0;
                                    if ($c['usagePerUser'] > 0 && $usedCount >= $c['usagePerUser']) continue;
                                ?>
                                        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between gap-2 hover:border-[#003e86]/30 hover:bg-blue-50/30 transition-all cursor-pointer group" onclick="applyCouponCode('<?php echo htmlspecialchars($c['code']); ?>')">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-[#003e86] shadow-sm shrink-0">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 5 4 4"/><path d="M13 7 8.7 2.7a2.41 2.41 0 0 0-3.4 0L2.7 5.3a2.41 2.41 0 0 0 0 3.4L7 13"/><path d="m8 6 2-2"/><path d="m2 22 5.5-1.5L21.17 6.83a2.82 2.82 0 0 0-4-4L3.5 16.5Z"/><path d="m18 16 2-2"/><path d="m17 11 4.3 4.3c.94.94.94 2.46 0 3.4l-2.6 2.6c-.94.94-2.46.94-3.4 0L11 17"/></svg>
                                                </div>
                                                <div>
                                                    <p class="font-mono font-black text-slate-800 tracking-wider uppercase text-sm group-hover:text-[#003e86] transition-colors"><?php echo htmlspecialchars($c['code']); ?></p>
                                                    <p class="text-[10px] text-slate-500 font-medium mt-0.5">
                                                        <?php echo $c['discountType'] === 'PERCENTAGE' ? rtrim(rtrim($c['discountAmount'], '0'), '.') . '%' : '৳' . rtrim(rtrim($c['discountAmount'], '0'), '.'); ?> OFF
                                                        <?php echo $c['minSpend'] > 0 ? ' on ৳' . rtrim(rtrim($c['minSpend'], '0'), '.') . '+' : ''; ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-[10px] font-bold text-slate-500 group-hover:text-[#003e86] transition-colors">Apply</span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="space-y-3 text-sm font-medium text-slate-500 border-t border-slate-100 pt-5 mb-5">
                            <div class="flex justify-between items-center"><span class="font-bold text-xs sm:text-sm">Subtotal</span><span id="checkout-subtotal" class="font-black text-slate-800 text-xs sm:text-sm">৳0</span></div>
                            <div id="discount-row" class="flex justify-between items-center hidden"><span id="checkout-discount-label" class="font-bold text-emerald-600 text-xs sm:text-sm">Discount</span><span id="checkout-discount" class="font-black text-emerald-600 text-xs sm:text-sm">-৳0</span></div>
                            <div class="flex justify-between items-center"><span class="font-bold text-xs sm:text-sm">Shipping Fee</span><span id="checkout-shipping" class="font-black text-slate-800 text-xs sm:text-sm">৳0</span></div>
                        </div>

                        <div class="flex justify-between items-center bg-slate-50 p-3.5 sm:p-5 rounded-xl sm:rounded-2xl mb-5 sm:mb-8 border border-slate-200">
                            <span class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-widest">Total</span>
                            <span id="checkout-total" class="text-xl sm:text-2xl font-black text-[#003e86]">৳0</span>
                        </div>

                        <!-- Dynamic Payment Breakdown -->
                        <div id="payment-breakdown" class="hidden space-y-3 text-xs sm:text-sm font-medium text-slate-500 mb-5 sm:mb-8 p-4 sm:p-5 bg-indigo-50 border border-indigo-100 rounded-xl sm:rounded-2xl">
                            <div class="flex justify-between items-center"><span class="font-bold text-indigo-800" id="payable-now-label">Payable Now</span><span id="payable-now-amount" class="font-black text-indigo-700 text-sm sm:text-base">৳0</span></div>
                            <div class="flex justify-between items-center"><span class="font-bold text-slate-600" id="due-later-label">Due on Delivery</span><span id="due-later-amount" class="font-black text-slate-800 text-sm sm:text-base">৳0</span></div>
                        </div>

                        <?php 
                        $canCheckout = true;
                        if (!$isLoggedIn && empty($settings['guestCheckoutEnabled'])) $canCheckout = false;
                        if ($isBlocked) $canCheckout = false;
                        ?>
                        <!-- Desktop Place Order Button -->
                        <button type="submit" id="place-order-btn" class="btn-anim-wrapper hidden md:flex w-full bg-[#003e86] hover:bg-blue-800 text-white py-4 rounded-xl font-bold shadow-[0_8px_20px_-6px_rgba(0,62,134,0.3)] transition-all active:scale-95 items-center justify-center gap-2 text-lg <?php echo $canCheckout ? '' : 'opacity-50 cursor-not-allowed'; ?>" <?php echo $canCheckout ? '' : 'disabled'; ?>>
                            <div class="default-content">
                                Place Order <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="translate-x-1"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </div>
                            <div class="anim-layer text-white">
                                <div class="anim-truck"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                                <div class="anim-box"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                                <div class="anim-speed-line"></div>
                            </div>
                        </button>
                        
                        <div class="mt-6 flex flex-wrap items-center justify-center gap-4 opacity-60 grayscale filter text-slate-600">
                            <div class="flex items-center gap-1.5"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg><span class="text-[10px] font-bold uppercase tracking-widest">SSL Secure</span></div>
                            <div class="hidden sm:block w-1 h-1 rounded-full bg-slate-400"></div>
                            <div class="flex items-center gap-1.5"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 18H3c-.6 0-1-.4-1-1V7c0-.6.4-1 1-1h10c.6 0 1 .4 1 1v11"/><path d="M14 9h4l4 4v4c0 .6-.4 1-1 1h-2"/><circle cx="7" cy="18" r="2"/><path d="M15 18H9"/><circle cx="17" cy="18" r="2"/></svg><span class="text-[10px] font-bold uppercase tracking-widest">Fast Delivery</span></div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Mobile Sticky Place Order Bar (App Style) -->
            <div class="md:hidden fixed bottom-0 left-0 w-full bg-white/95 backdrop-blur-xl border-t border-slate-200 p-3 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] z-[90] flex items-center justify-between gap-4 shadow-[0_-10px_20px_rgba(0,0,0,0.05)]">
                <div class="flex flex-col min-w-[80px]">
                    <span id="checkout-total-label-mobile" class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Total</span>
                    <span id="checkout-total-mobile" class="text-xl font-black text-[#003e86] leading-none">৳0</span>
                </div>
                <button type="submit" form="checkout-form" id="place-order-btn-mobile" class="btn-anim-wrapper flex-1 bg-[#003e86] hover:bg-blue-800 text-white py-3 rounded-xl font-bold text-sm shadow-lg shadow-blue-900/20 transition-all active:scale-95 flex items-center justify-center gap-2 <?php echo $canCheckout ? '' : 'opacity-50 cursor-not-allowed'; ?>" <?php echo $canCheckout ? '' : 'disabled'; ?>>
                    <div class="default-content">
                        Place Order <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </div>
                    <div class="anim-layer text-white">
                        <div class="anim-truck"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                        <div class="anim-box"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                        <div class="anim-speed-line"></div>
                    </div>
                </button>
            </div>

            <script>
            const settings = <?php echo json_encode($settings); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const isBuyNow = urlParams.get('buy_now') === '1';
            let cart = isBuyNow ? (JSON.parse(sessionStorage.getItem('buyNowCart')) || []) : (JSON.parse(localStorage.getItem('cart')) || []);
            
            window.animateCountUp = function(elementId, endValue, prefix = '৳', suffix = '') {
                const el = document.getElementById(elementId);
                if (!el) return;
                const startValue = parseFloat(el.innerText.replace(/[^\d.-]/g, '')) || 0;
                const hasDecimals = (endValue % 1 !== 0);
                const formatNumber = (num) => {
                    const isNegative = num < 0;
                    return (isNegative ? '-' : '') + prefix + Math.abs(num).toLocaleString('en-US', {
                        minimumFractionDigits: hasDecimals ? 2 : 0,
                        maximumFractionDigits: hasDecimals ? 2 : 0
                    }) + suffix;
                };
                if (startValue === endValue) {
                    el.innerText = formatNumber(endValue);
                    return;
                }
                const duration = 400;
                let startTime = null;
                const step = (timestamp) => {
                    if (!startTime) startTime = timestamp;
                    const progress = Math.min((timestamp - startTime) / duration, 1);
                    const easeOutProgress = 1 - Math.pow(1 - progress, 4);
                    const currentValue = startValue + easeOutProgress * (endValue - startValue);
                    el.innerText = formatNumber(currentValue);
                    if (progress < 1) requestAnimationFrame(step); 
                    else el.innerText = formatNumber(endValue);
                };
                requestAnimationFrame(step);
            };

            if (cart.length === 0) { window.location.href = 'shop'; }

            window.addEventListener('cartUpdated', () => {
                if (!isBuyNow) cart = JSON.parse(localStorage.getItem('cart')) || [];
                if (cart.length === 0) {
                    window.location.href = 'shop';
                } else {
                    renderSummary();
                    checkCartForAdvance();
                }
            });

            const phoneInput = document.getElementById('checkout-phone');
            const nameInput = document.getElementById('checkout-name');
            const emailInput = document.getElementById('checkout-email');
            const citySelect = document.getElementById('city-select');
            const addressInput = document.getElementById('checkout-address');
            const phoneMsg = document.getElementById('phone-msg');
            const hiddenSections = document.getElementById('hidden-checkout-sections');
            const placeOrderBtn = document.getElementById('place-order-btn');

            let fetchTimeout;

            phoneInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, ''); // Allow only numbers
                
                if (this.value.length === 11) {
                    <?php if(!$isLoggedIn): ?>
                    clearTimeout(fetchTimeout);
                    phoneMsg.className = 'text-[11px] text-[#003e86] ml-1 font-bold mt-2 flex items-center gap-1.5';
                    phoneMsg.innerHTML = '<svg class="animate-spin w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2v4"/></svg> Checking details...';
                    
                    hiddenSections.classList.remove('hidden');
                    placeOrderBtn.disabled = false;
                    placeOrderBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    
                    fetchTimeout = setTimeout(async () => {
                        try {
                            const formData = new FormData();
                            formData.append('action', 'fetch_user_by_phone');
                            formData.append('phone', phoneInput.value);
                            
                            const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
                            const result = await res.json();
                            
                            if (result.success && result.data) {
                                phoneMsg.innerHTML = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> Welcome back! Your details are pre-filled.';
                                phoneMsg.className = 'text-[11px] text-emerald-600 ml-1 font-bold mt-2 flex items-center gap-1.5';
                                
                                if (result.data.name) nameInput.value = result.data.name;
                                if (result.data.email) document.getElementById('checkout-email').value = result.data.email;
                                if (result.data.deliveryAddress) {
                                    document.getElementById('checkout-address').value = result.data.deliveryAddress;
                                }
                                if (result.data.city) {
                                    document.getElementById('city-select').value = result.data.city;
                                    renderSummary();
                                }
                            } else {
                                phoneMsg.innerHTML = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg> New number. Please fill out your delivery details.';
                                phoneMsg.className = 'text-[11px] text-[#003e86] ml-1 font-bold mt-2 flex items-center gap-1.5';
                            }
                        } catch(e) { console.error(e); phoneMsg.classList.add('hidden'); }
                    }, 500);
                    <?php endif; ?>
                } else {
                    phoneMsg.classList.add('hidden');
                    <?php if(!$isLoggedIn): ?>
                    hiddenSections.classList.add('hidden');
                    placeOrderBtn.disabled = true;
                    placeOrderBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    <?php endif; ?>
                }
            });

            // Payment Method Styling
            document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.payment-radio').forEach(label => {
                        label.classList.remove('border-[#003e86]', 'bg-blue-50/50');
                        label.classList.add('border-slate-200', 'bg-white');
                        const check = label.querySelector('.radio-check');
                        if (check) {
                            check.classList.remove('border-[#003e86]', 'bg-[#003e86]', 'text-white');
                            check.classList.add('border-slate-300', 'text-transparent');
                        }
                    });
                    if(this.checked) {
                        const parent = this.closest('.payment-radio');
                        parent.classList.remove('border-slate-200', 'bg-white');
                        parent.classList.add('border-[#003e86]', 'bg-blue-50/50');
                        
                        const check = parent.querySelector('.radio-check');
                        if (check) {
                            check.classList.remove('border-slate-300', 'text-transparent');
                            check.classList.add('border-[#003e86]', 'bg-[#003e86]', 'text-white');
                        }
                    }
                    updatePaymentBreakdown();
                });
                
                // Trigger initial visual state
                if(radio.checked) {
                    radio.dispatchEvent(new Event('change'));
                }
            });

            let appliedCoupon = null;
            let discountAmount = 0;

            function applyCouponCode(code) {
                document.getElementById('coupon-input').value = code;
                applyCoupon();
            }

            async function applyCoupon() {
                const codeInput = document.getElementById('coupon-input');
                const msg = document.getElementById('coupon-msg');
                const btn = document.getElementById('apply-coupon-btn');
                const code = codeInput.value.trim();
                
                if (!code) { msg.innerText = 'Please enter a coupon code.'; msg.className = 'text-[11px] font-bold mt-2 ml-1 text-rose-500'; msg.classList.remove('hidden'); return; }
                
                btn.disabled = true; btn.innerText = '...';
                let subtotal = cart.reduce((acc, item) => acc + ((Number(String(item.price).replace(/,/g, '')) || 0) * (parseInt(item.quantity) || 1)), 0);
                const phone = document.getElementById('checkout-phone').value;
                const email = document.getElementById('checkout-email').value;
                
                const formData = new FormData(); formData.append('action', 'apply_coupon'); formData.append('code', code); formData.append('subtotal', subtotal); formData.append('phone', phone); formData.append('email', email);
                
                try {
                    const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
                    const result = await res.json();
                    
                    if (result.success) {
                        appliedCoupon = result.code; discountAmount = result.discount;
                        msg.innerText = result.message; msg.className = 'text-[11px] font-bold mt-2 ml-1 text-emerald-600'; msg.classList.remove('hidden');
                        document.getElementById('discount-row').classList.remove('hidden');
                        document.getElementById('checkout-discount-label').innerText = 'Discount (' + appliedCoupon + ')';
                        animateCountUp('checkout-discount', discountAmount, '-৳');
                    } else {
                        appliedCoupon = null; discountAmount = 0;
                        msg.innerText = result.message; msg.className = 'text-[11px] font-bold mt-2 ml-1 text-rose-500'; msg.classList.remove('hidden');
                        document.getElementById('discount-row').classList.add('hidden');
                    }
                    updateTotal(subtotal);
                } catch (e) {
                    console.error(e); msg.innerText = 'Network error. Try again.'; msg.className = 'text-[11px] font-bold mt-2 ml-1 text-rose-500'; msg.classList.remove('hidden');
                } finally {
                    btn.disabled = false; btn.innerText = 'Apply';
                }
            }

            function renderSummary() {
                let subtotal = 0; let html = '';
                cart.forEach(item => {
                    let itemPrice = Number(String(item.price).replace(/,/g, '')) || 0;
                    let itemQty = parseInt(item.quantity) || 1;
                    subtotal += itemPrice * itemQty;
                    html += `<div class="flex items-center gap-3 sm:gap-4 group mb-3 sm:mb-4 last:mb-0"><div class="w-12 h-12 sm:w-16 sm:h-16 rounded-xl sm:rounded-2xl bg-white border border-slate-100 shadow-sm overflow-hidden shrink-0 relative p-0.5 sm:p-1"><img src="${item.image}" class="w-full h-full object-cover mix-blend-multiply rounded-lg sm:rounded-xl"><span class="absolute top-0 right-0 bg-slate-900 text-white text-[8px] sm:text-[10px] font-black w-4 h-4 sm:w-5 sm:h-5 flex items-center justify-center rounded-bl-lg sm:rounded-bl-xl rounded-tr-xl sm:rounded-tr-2xl shadow-sm border-b border-l border-white">${itemQty}</span></div><div class="flex-1 min-w-0"><p class="text-xs sm:text-sm font-bold text-slate-800 truncate leading-tight">${item.name}</p><p class="text-[9px] sm:text-[10px] font-bold text-slate-400 mt-0.5 sm:mt-1 uppercase tracking-widest">${item.size !== 'Standard' ? item.size : ''} ${item.color !== 'Default' ? '• ' + item.color : ''}</p></div><p class="font-black text-slate-800 text-xs sm:text-sm">৳${(itemPrice * itemQty).toLocaleString()}</p></div>`;
                });
                document.getElementById('checkout-items').innerHTML = html;
                animateCountUp('checkout-subtotal', subtotal);
                updateTotal(subtotal);
            }

            function updateTotal(subtotal) {
                const citySelect = document.getElementById('city-select');
                if (!citySelect) return;
                const city = citySelect.value;
                const shipping = city === 'Dhaka' ? Number(settings.deliveryInsideDhaka || 0) : Number(settings.deliveryOutsideDhaka || 0);
                animateCountUp('checkout-shipping', shipping);
                let finalTotal = subtotal + shipping - Number(discountAmount || 0);
                if (finalTotal < 0) finalTotal = 0;
                animateCountUp('checkout-total', finalTotal);
                window.checkoutFinalTotal = finalTotal;
                updateAdvanceLabel();
                updatePaymentBreakdown();
            }

            function updateAdvanceLabel() {
                if (!window.requiredAdvanceAmount || window.requiredAdvanceAmount <= 0) return;
                
                const advanceLabel = document.getElementById('bkash-advance-label');
                const advanceTitle = document.getElementById('bkash-advance-title');
                
                let finalTotal = window.checkoutFinalTotal || 0;
                let advanceToPay = Math.min(window.requiredAdvanceAmount, finalTotal);
                let remaining = Math.max(0, finalTotal - advanceToPay);
                
                if (advanceTitle) advanceTitle.innerText = "Advance Payment (bKash)";
                if (advanceLabel) advanceLabel.innerHTML = `Pay ৳${advanceToPay.toLocaleString(undefined, {minimumFractionDigits: 2})} now & ৳${remaining.toLocaleString(undefined, {minimumFractionDigits: 2})} with Cash on Delivery.`;
            }

            function updatePaymentBreakdown() {
                const breakdownContainer = document.getElementById('payment-breakdown');
                if (!breakdownContainer) return;

                const selectedPayment = document.querySelector('input[name="payment_method"]:checked');
                const finalTotal = window.checkoutFinalTotal || 0;
                const mobileLabel = document.getElementById('checkout-total-label-mobile');
                const mobileTotal = document.getElementById('checkout-total-mobile');
                
                if (!selectedPayment) {
                    breakdownContainer.classList.add('hidden');
                    return;
                }

                const method = selectedPayment.value;
                const payableNowLabel = document.getElementById('payable-now-label');
                const payableNowAmount = document.getElementById('payable-now-amount');
                const dueLaterLabel = document.getElementById('due-later-label');
                const dueLaterAmount = document.getElementById('due-later-amount');
                
                let advanceToPay = Math.min(window.requiredAdvanceAmount || 0, finalTotal);
                let remaining = Math.max(0, finalTotal - advanceToPay);

                breakdownContainer.classList.remove('hidden');
                dueLaterLabel.parentElement.classList.remove('hidden');

                if (method === 'BKASH_ADVANCE') {
                    payableNowLabel.innerText = 'Advance Payable (bKash)';
                    animateCountUp('payable-now-amount', advanceToPay);
                    dueLaterLabel.innerText = 'Due on Delivery (Cash)';
                    animateCountUp('due-later-amount', remaining);
                    if (mobileLabel) mobileLabel.innerText = 'Pay Advance';
                    if (mobileTotal) animateCountUp('checkout-total-mobile', advanceToPay);
                } else if (method === 'BKASH') {
                    payableNowLabel.innerText = 'Payable Now (bKash Full)';
                    animateCountUp('payable-now-amount', finalTotal);
                    dueLaterLabel.parentElement.classList.add('hidden');
                    if (mobileLabel) mobileLabel.innerText = 'Pay Full';
                    if (mobileTotal) animateCountUp('checkout-total-mobile', finalTotal);
                } else if (method === 'CASH ON DELIVERY') {
                    payableNowLabel.innerText = 'Payable Now';
                    animateCountUp('payable-now-amount', 0);
                    dueLaterLabel.innerText = 'Due on Delivery (Cash)';
                    animateCountUp('due-later-amount', finalTotal);
                    if (mobileLabel) mobileLabel.innerText = 'Total';
                    if (mobileTotal) animateCountUp('checkout-total-mobile', finalTotal);
                } else {
                    breakdownContainer.classList.add('hidden');
                }
            }

            async function checkCartForAdvance() {
                if (cart.length === 0) return;
                const cartParam = encodeURIComponent(JSON.stringify(cart.map(item => ({id: item.productId || item.id, qty: item.quantity}))));

                try {
                    const response = await fetch(`api/check_advance_status.php?cart=${cartParam}`);
                    const result = await response.json();

                    window.requiredAdvanceAmount = result.requiredAdvance;

                    const codOption = document.querySelector('input[value="CASH ON DELIVERY"]');
                    const fullBkashOption = document.querySelector('input[value="BKASH"]');
                    const advanceOption = document.getElementById('bkash-advance-option');
                    const advanceAlert = document.getElementById('advance-payment-alert');

                    if (result.requiredAdvance > 0) {
                        if (advanceAlert) advanceAlert.classList.remove('hidden');
                        if(codOption) {
                            codOption.closest('label').style.display = 'none';
                            if (codOption.checked) {
                                codOption.checked = false;
                                const advRadio = advanceOption ? advanceOption.querySelector('input[type="radio"]') : null;
                                const fullBkashRadio = fullBkashOption ? fullBkashOption : null;
                                if (advRadio) { advRadio.checked = true; advRadio.dispatchEvent(new Event('change')); }
                                else if (fullBkashRadio) { fullBkashRadio.checked = true; fullBkashRadio.dispatchEvent(new Event('change')); }
                            }
                        }
                        if(fullBkashOption) {
                            fullBkashOption.closest('label').style.display = 'flex';
                        }
                        if(advanceOption) {
                            advanceOption.style.display = 'flex';
                            updateAdvanceLabel();
                        }
                    } else {
                        if (advanceAlert) advanceAlert.classList.add('hidden');
                        if(codOption) {
                            codOption.closest('label').style.display = 'flex';
                            const advRadio = advanceOption ? advanceOption.querySelector('input[type="radio"]') : null;
                            const fullChecked = fullBkashOption && fullBkashOption.checked;
                            if ((advRadio && advRadio.checked) || !fullChecked) {
                                if (advRadio) advRadio.checked = false;
                                if (!codOption.checked) {
                                    codOption.checked = true;
                                    codOption.dispatchEvent(new Event('change'));
                                }
                            }
                        }
                        if(fullBkashOption) {
                            fullBkashOption.closest('label').style.display = 'flex';
                        }
                        if(advanceOption) advanceOption.style.display = 'none';
                    }
                    updatePaymentBreakdown();
                } catch (e) { 
                    console.error("Advance check failed", e); 
                }
            }

            document.getElementById('checkout-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const selectedPayment = document.querySelector('input[name="payment_method"]:checked');
                if (!selectedPayment) {
                    if (window.showToast) showToast('Please select a payment method (Full or Advance).', 'error');
                    else alert('Please select a payment method.');
                    return;
                }
                
                const addressEl = document.getElementById('checkout-address');
                if (addressEl && !addressEl.value.trim()) {
                    if (window.showToast) showToast('Please enter your full delivery address.', 'error');
                    else alert('Please enter your delivery address.');
                    addressEl.focus();
                    return;
                }
                
                const btnDesktop = document.getElementById('place-order-btn');
                const btnMobile = document.getElementById('place-order-btn-mobile');
                
                if(btnDesktop) { btnDesktop.classList.add('is-animating'); btnDesktop.style.pointerEvents = 'none'; }
                if(btnMobile) { btnMobile.classList.add('is-animating'); btnMobile.style.pointerEvents = 'none'; }
                
                const formData = new FormData(e.target);
                formData.append('action', 'place_order');
                formData.append('cart_data', JSON.stringify(cart));
                if (appliedCoupon) formData.append('coupon_code', appliedCoupon);
                
                // Set flag to clear the correct cart after success
                if (isBuyNow) {
                    sessionStorage.setItem('clearing_buy_now', '1');
                } else {
                    sessionStorage.setItem('clearing_cart', '1');
                }

                const startTime = Date.now();
                try {
                    const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
                    const result = await res.json();
                    
                    const elapsed = Date.now() - startTime;
                    const remainingTime = Math.max(0, 2500 - elapsed);
                    
                    setTimeout(() => {
                        if (result.success) {
                            if (result.payment_method === 'BKASH' || result.payment_method === 'BKASH_ADVANCE') {
                            window.location.href = `pay-bkash?order_id=${result.order_id}`;
                        } else {
                            window.location.href = `checkout?success=1&order_id=${result.order_id}&email=${result.email}`;
                        }
                        } else {
                            showToast(result.error || "Order placement failed.", "error");
                            if(btnDesktop) { btnDesktop.classList.remove('is-animating'); btnDesktop.style.pointerEvents = 'auto'; }
                            if(btnMobile) { btnMobile.classList.remove('is-animating'); btnMobile.style.pointerEvents = 'auto'; }
                        }
                    }, remainingTime);
                    
                } catch (err) {
                    console.error(err);
                    showToast("Network error. Please try again.", "error");
                    if(btnDesktop) { btnDesktop.classList.remove('is-animating'); btnDesktop.style.pointerEvents = 'auto'; }
                    if(btnMobile) { btnMobile.classList.remove('is-animating'); btnMobile.style.pointerEvents = 'auto'; }
                }
            });

            document.addEventListener('DOMContentLoaded', () => {
                renderSummary();
                checkCartForAdvance();
            });
            </script>
        <?php endif; ?>
    </div>
</div>

<?php include 'Footer.php'; ?>