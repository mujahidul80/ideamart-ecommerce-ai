<?php
ob_start();
session_start();
require_once __DIR__ . '/db.php';

$pendingOrder = $_SESSION['pending_bkash_order'] ?? null;
if (!$pendingOrder) {
    header("Location: 404");
    exit;
}

// Fetch bKash Settings
$setStmt = $pdo->query("SELECT * FROM SettingPayment LIMIT 1");
$paymentSettings = $setStmt->fetch(PDO::FETCH_ASSOC);

if (empty($paymentSettings['bkashEnabled'])) {
    die("bKash payment is disabled.");
}

$appKey = trim($paymentSettings['bkashAppKey'] ?? '');
$appSecret = trim($paymentSettings['bkashAppSecret'] ?? '');
$username = trim($paymentSettings['bkashUsername'] ?? '');
$password = trim($paymentSettings['bkashPassword'] ?? '');
$baseUrl = !empty($paymentSettings['bkashBaseUrl']) ? rtrim($paymentSettings['bkashBaseUrl'], '/') : "https://tokenized.pay.bka.sh/v1.2.0-beta";

if (empty($appKey) || empty($appSecret) || empty($username) || empty($password)) {
    die("bKash is not configured properly in the admin panel.");
}

$amount = $pendingOrder['paymentMethod'] === 'BKASH_ADVANCE' ? ($pendingOrder['advanceAmount'] ?? 200) : $pendingOrder['total'];

do {
    $invoiceId = mt_rand(100000, 999999);
    $check = $pdo->prepare("SELECT id FROM `Order` WHERE id = ?");
    $check->execute([$invoiceId]);
} while ($check->fetch());

$_SESSION['bkash_invoice_id'] = $invoiceId;

// Generate Callback URL based on current host and directory
$dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$dir = rtrim($dir, '/');
$callbackUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $dir . "/bkash-callback.php";

// Output Loading Screen
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing bKash Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans">
    <div class="bg-white p-8 rounded-3xl shadow-sm max-w-sm w-full text-center border border-slate-200 animate-in fade-in zoom-in-95 duration-300">
        <div class="w-20 h-20 p-2 bg-white rounded-full flex items-center justify-center shadow-lg border-4 border-pink-100 mx-auto mb-6">
            <img src="https://scripts.pay.bka.sh/logo/bkash_logo.svg" alt="bKash" class="w-full h-full object-contain">
        </div>
        <h2 class="text-lg font-bold text-slate-800 mb-2">Connecting to bKash</h2>
        <p class="text-slate-500 font-medium mb-8 text-sm">Please wait while we redirect you to the secure payment gateway...</p>
        <div class="flex justify-center mb-2">
            <svg class="animate-spin h-8 w-8 text-[#e2136e]" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
        </div>
    </div>
<?php
ob_end_flush();
flush();

// Grant Token
function getBkashToken($baseUrl, $appKey, $appSecret, $username, $password) {
    $post_token = ['app_key' => $appKey, 'app_secret' => $appSecret];
    $url = "$baseUrl/tokenized/checkout/token/grant";
    $posttoken = json_encode($post_token);
    $header = [
        'Content-Type: application/json',
        "password: $password",
        "username: $username"
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $posttoken);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    $resultdata = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($resultdata === false) return ['statusMessage' => "cURL Error: " . $err];
    return json_decode($resultdata, true);
}

// Create Payment
function createBkashPayment($baseUrl, $appKey, $token, $amount, $merchantInvoiceNumber, $callbackUrl, $payerRef) {
    $amountFormatted = number_format((float)$amount, 2, '.', '');
    $post_create = ['mode' => '0011', 'payerReference' => $payerRef, 'callbackURL' => $callbackUrl, 'amount' => $amountFormatted, 'currency' => 'BDT', 'intent' => 'sale', 'merchantInvoiceNumber' => $merchantInvoiceNumber];
    $url = "$baseUrl/tokenized/checkout/create";
    $postcreate = json_encode($post_create);
    $header = [
        'Content-Type: application/json',
        "Authorization: $token",
        "X-APP-Key: $appKey"
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postcreate);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    $resultdata = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($resultdata === false) return ['statusMessage' => "cURL Error: " . $err];
    return json_decode($resultdata, true);
}

$tokenResponse = getBkashToken($baseUrl, $appKey, $appSecret, $username, $password);

if (isset($tokenResponse['id_token']) && $tokenResponse['id_token']) {
    $token = $tokenResponse['id_token'];
    $_SESSION['bkash_token'] = $token;
    
    $merchantInvoiceNumber = "INV" . str_pad($invoiceId, 6, "0", STR_PAD_LEFT);
    $payerRef = preg_replace('/[^0-9]/', '', $pendingOrder['customer_phone'] ?? '01700000000');
    if (empty($payerRef)) $payerRef = '1';
    
    $createResponse = createBkashPayment($baseUrl, $appKey, $token, $amount, $merchantInvoiceNumber, $callbackUrl, $payerRef);

    if (isset($createResponse['bkashURL']) && $createResponse['bkashURL']) {
        echo '<script>window.location.href = ' . json_encode($createResponse['bkashURL']) . ';</script>';
    } else {
        unset($_SESSION['pending_bkash_order']);
        $msg = $createResponse['statusMessage'] ?? 'Unknown Error';
        echo '<script>window.location.href = "checkout?payment_failed=1&msg=' . urlencode("bKash Payment Creation Failed: " . $msg) . '";</script>';
    }
} else {
    unset($_SESSION['pending_bkash_order']);
    $msg = $tokenResponse['statusMessage'] ?? 'Unknown Error';
    echo '<script>window.location.href = "checkout?payment_failed=1&msg=' . urlencode("bKash Token Generation Failed. " . $msg) . '";</script>';
}
?>
</body>
</html>