<?php
/**
 * SMS Gateway Helper for eCommerce
 * Supports bulk SMS gateways with dynamic placeholder replacements.
 */

function sendDynamicSMS($pdo, $phone, $message) {
    if (empty($phone) || empty($message)) {
        return ['success' => false, 'error' => 'Phone number or message is empty.'];
    }

    $stmt = $pdo->query("SELECT n.*, g.storeName FROM SettingNotification n LEFT JOIN SettingGeneral g ON n.id = g.id LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($settings['smsEnabled']) || empty($settings['smsApiUrl'])) {
        return ['success' => false, 'error' => 'SMS gateway is disabled or not configured in Admin panel.'];
    }

    // Clean phone number (keep digits only)
    $cleanPhone = preg_replace('/[^\d]/', '', (string)$phone);
    if (empty($cleanPhone)) {
        return ['success' => false, 'error' => 'Invalid phone number format.'];
    }

    // Format variations for Bangladesh gateways
    $phone88 = (str_starts_with($cleanPhone, '01') && strlen($cleanPhone) === 11) ? ('88' . $cleanPhone) : $cleanPhone;
    $phone11 = (str_starts_with($cleanPhone, '8801') && strlen($cleanPhone) === 13) ? substr($cleanPhone, 2) : $cleanPhone;

    $apiUrl = $settings['smsApiUrl'];

    // 1. Replace 88-prefixed phone placeholders if present
    $apiUrl = str_replace(
        ['MOBILE_NUMBER_88', '[TO_88]', '{to_88}'], 
        urlencode($phone88), 
        $apiUrl
    );

    // 2. Replace 11-digit phone placeholders if present
    $apiUrl = str_replace(
        ['MOBILE_NUMBER_11', '[TO_11]', '{to_11}'], 
        urlencode($phone11), 
        $apiUrl
    );

    // 3. Replace standard recipient phone placeholders
    $apiUrl = str_replace(
        ['MOBILE_NUMBER', '[TO]', '{to}', '[NUMBER]', '{number}', '[PHONE]', '{phone}'], 
        urlencode($cleanPhone), 
        $apiUrl
    );

    // 4. Replace Message body placeholder
    $apiUrl = str_replace(
        ['MESSAGE', '[MESSAGE]', '{message}', '[MSG]', '{msg}'], 
        urlencode($message), 
        $apiUrl
    );

    // 5. Replace Sender / Caller ID placeholder if configured
    $senderId = $settings['smsSenderId'] ?? '';
    if (!empty($senderId)) {
        $apiUrl = str_replace(
            ['SENDER_ID', '[SENDER_ID]', '{sender_id}', 'CALLER_ID', '[CALLER_ID]', '{caller_id}'], 
            urlencode($senderId), 
            $apiUrl
        );
    }

    // Execute HTTP Request with cURL
    $response = false;
    $httpCode = 0;
    $curlError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) eCommerce SMS Client');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'ignore_errors' => true
            ]
        ]);
        $response = @file_get_contents($apiUrl, false, $context);
    }

    if ($response === false || (!empty($curlError) && empty($response))) {
        $errorMsg = !empty($curlError) ? $curlError : 'Failed to connect to SMS Gateway endpoint.';
        try {
            $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'SMS Delivery Error', ?, 'alert')")
                ->execute(["Failed to send SMS to {$cleanPhone}. Error: {$errorMsg}"]);
        } catch (Exception $e) {}

        return [
            'success' => false, 
            'error' => $errorMsg,
            'url' => $apiUrl
        ];
    }

    return [
        'success' => true, 
        'http_code' => $httpCode, 
        'response' => (string)$response,
        'url' => $apiUrl
    ];
}

/**
 * Send Registration OTP SMS
 */
function sendRegistrationOtpSMS($pdo, $phone, $otpCode, $customerName = '') {
    $stmt = $pdo->query("SELECT n.otpSmsTemplate, g.storeName FROM SettingNotification n LEFT JOIN SettingGeneral g ON n.id = g.id LIMIT 1");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $storeName = $data['storeName'] ?? 'Our Store';
    $template = !empty($data['otpSmsTemplate']) ? $data['otpSmsTemplate'] : "Your OTP code is: {{otp_code}}. Do not share this with anyone. - {{store_name}}";
    
    $message = str_replace(
        ['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'],
        [$customerName ?: 'Customer', $otpCode, $storeName, $storeName],
        $template
    );

    return sendDynamicSMS($pdo, $phone, $message);
}

/**
 * Send Forgot Password OTP SMS
 */
function sendForgotPasswordOtpSMS($pdo, $phone, $otpCode, $customerName = '') {
    $stmt = $pdo->query("SELECT n.otpSmsTemplate, g.storeName FROM SettingNotification n LEFT JOIN SettingGeneral g ON n.id = g.id LIMIT 1");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $storeName = $data['storeName'] ?? 'Our Store';
    $template = !empty($data['otpSmsTemplate']) ? $data['otpSmsTemplate'] : "Your password reset OTP is: {{otp_code}}. Valid for 10 minutes. - {{store_name}}";
    
    $message = str_replace(
        ['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'],
        [$customerName ?: 'Customer', $otpCode, $storeName, $storeName],
        $template
    );

    return sendDynamicSMS($pdo, $phone, $message);
}

/**
 * Send Order Delivered SMS (Triggered ONLY when parcel is delivered)
 */
function sendOrderDeliveredSMS($pdo, $orderId, $customerPhone, $customerName = '') {
    $stmt = $pdo->query("SELECT n.orderDeliveredSmsTemplate, g.storeName FROM SettingNotification n LEFT JOIN SettingGeneral g ON n.id = g.id LIMIT 1");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $storeName = $data['storeName'] ?? 'Our Store';
    $template = !empty($data['orderDeliveredSmsTemplate']) ? $data['orderDeliveredSmsTemplate'] : "Dear {{customer_name}}, your order #{{order_id}} has been delivered. Thank you for shopping with {{store_name}}!";
    
    $paddedId = str_pad($orderId, 6, '0', STR_PAD_LEFT);
    $message = str_replace(
        ['{{customer_name}}', '{{order_id}}', '{{store_name}}', 'Idea Mart'],
        [$customerName ?: 'Valued Customer', $paddedId, $storeName, $storeName],
        $template
    );

    return sendDynamicSMS($pdo, $customerPhone, $message);
}
