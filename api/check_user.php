<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$response = [
    'success' => true,
    'phone_checked' => false,
    'phone_registered' => false,
    'phone_msg' => '',
    'email_checked' => false,
    'email_registered' => false,
    'email_msg' => ''
];

$phone = trim($_REQUEST['phone'] ?? '');
$email = trim($_REQUEST['email'] ?? '');

// 1. Check Phone Number
if (!empty($phone)) {
    $cleanPhone = preg_replace('/[^\d]/', '', $phone);
    if (strlen($cleanPhone) >= 10) {
        $response['phone_checked'] = true;
        
        $phone11 = (str_starts_with($cleanPhone, '8801') && strlen($cleanPhone) === 13) ? substr($cleanPhone, 2) : $cleanPhone;
        $phone88 = (str_starts_with($cleanPhone, '01') && strlen($cleanPhone) === 11) ? ('88' . $cleanPhone) : $cleanPhone;
        $phonePlus88 = '+' . $phone88;

        try {
            $stmt = $pdo->prepare("SELECT id FROM User WHERE phone IN (?, ?, ?, ?) LIMIT 1");
            $stmt->execute([$cleanPhone, $phone11, $phone88, $phonePlus88]);
            if ($stmt->fetch()) {
                $response['phone_registered'] = true;
                $response['phone_msg'] = 'এই ফোন নম্বরটি ইতিমধ্যে নিবন্ধিত আছে।';
            } else {
                $response['phone_registered'] = false;
                $response['phone_msg'] = 'ফোন নম্বরটি ব্যবহার করা যাবে।';
            }
        } catch (Exception $e) {}
    }
}

// 2. Check Email Address
if (!empty($email)) {
    $response['email_checked'] = true;
    $disposableDomains = [
        'mailinator.com', 'guerrillamail.com', 'temp-mail.org', '10minutemail.com', 
        'yopmail.com', 'throwawaymail.com', 'getairmail.com', 'tempmail.com', 
        'dispostable.com', 'sharklasers.com', 'spam4.me', 'trashmail.com', 
        'tempmail.net', 'fakemail.net', 'temp-mail.io', 'minuteinbox.com', 
        'mohmal.com', 'nada.ltd'
    ];
    $domain = strtolower(substr(strrchr($email, "@"), 1));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['email_registered'] = true;
        $response['email_msg'] = 'ইমেইল ফরম্যাট সঠিক নয়।';
    } else if (in_array($domain, $disposableDomains)) {
        $response['email_registered'] = true;
        $response['email_msg'] = 'অস্থায়ী বা ডিসপোজেবল ইমেইল গ্রহণযোগ্য নয়।';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM User WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $response['email_registered'] = true;
                $response['email_msg'] = 'এই ইমেইলটি ইতিমধ্যে নিবন্ধিত আছে।';
            } else {
                $response['email_registered'] = false;
                $response['email_msg'] = 'ইমেইলটি ব্যবহার করা যাবে।';
            }
        } catch (Exception $e) {}
    }
}

echo json_encode($response);
