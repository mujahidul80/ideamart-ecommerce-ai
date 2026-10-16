<?php
session_start();
require_once __DIR__ . '/db.php';

// Redirect Retention handler
$redirectTarget = 'account';
if (!empty($_GET['redirect'])) {
    $r = trim($_GET['redirect']);
    if (in_array($r, ['checkout', 'cart', 'account']) || (preg_match('/^[a-zA-Z0-9_\-\/\?&=]+$/', $r) && !preg_match('/^(https?:|\/\/)/i', $r))) {
        $_SESSION['auth_redirect'] = $r;
        $redirectTarget = $r;
    }
} elseif (!empty($_SESSION['auth_redirect'])) {
    $redirectTarget = $_SESSION['auth_redirect'];
}

// If already logged in, redirect to intended destination
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $dest = !empty($_SESSION['auth_redirect']) ? $_SESSION['auth_redirect'] : 'account';
    unset($_SESSION['auth_redirect']);
    header("Location: " . $dest);
    exit;
}

$loginError = '';
$registerError = '';
$registerOtpError = '';
$registerOtpMsg = '';
$activeTab = 'login';

try {
    $setStmt = $pdo->query("SELECT g.*, n.* FROM SettingGeneral g LEFT JOIN SettingNotification n ON g.id = n.id LIMIT 1");
    $settings = $setStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $settings = [];
}
$isOtpRegEnabled = (!empty($settings['otpRegistrationEnabled']));
$storeName = $settings['storeName'] ?? '';
$logoUrl = !empty($settings['logoUrl']) ? ltrim($settings['logoUrl'], '/') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $activeTab = 'login';
        $contact = trim($_POST['contact'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($contact) || empty($password)) {
            $loginError = 'Please enter your email/phone and password.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM User WHERE (email = ? OR phone = ?) AND role = 'customer'");
            $stmt->execute([$contact, $contact]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                if (($user['status'] ?? 'active') === 'blocked') {
                    $loginError = 'Your account has been blocked. Please contact support.';
                } else {
                    if (isset($_POST['remember_me'])) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('user_remember', $token, time() + (86400 * 30), '/'); // 30 days
                        try { $pdo->prepare("UPDATE `User` SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]); } catch (Exception $e) {}
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    
                    $dest = !empty($_SESSION['auth_redirect']) ? $_SESSION['auth_redirect'] : 'account';
                    unset($_SESSION['auth_redirect']);
                    header("Location: " . $dest);
                    exit;
                }
            } else {
                $loginError = 'Invalid email address or password.';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'register') {
        $activeTab = 'register';
        $name = trim($_POST['name'] ?? '');
        $emailRaw = trim($_POST['email'] ?? '');
        $email = $emailRaw === '' ? null : $emailRaw;
        $password = trim($_POST['password'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($name) || empty($email) || empty($phone) || empty($password)) {
            $registerError = 'Name, email, phone, and password are required.';
        } else if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $registerError = 'The email address format is invalid. Please enter a correct email.';
        } else if ($email && in_array(strtolower(substr(strrchr($email, "@"), 1)), ['mailinator.com', 'guerrillamail.com', 'temp-mail.org', '10minutemail.com', 'yopmail.com', 'throwawaymail.com', 'getairmail.com', 'tempmail.com', 'dispostable.com', 'sharklasers.com', 'spam4.me', 'trashmail.com', 'tempmail.net', 'fakemail.net', 'temp-mail.io', 'minuteinbox.com', 'mohmal.com', 'nada.ltd'])) {
            $registerError = 'Temporary or disposable email addresses are not allowed. Please use your primary email address.';
        } else {
            $isPhoneDuplicate = false;
            $isEmailDuplicate = false;
            try {
                $cleanPhone = preg_replace('/[^\d]/', '', $phone);
                $phone11 = (str_starts_with($cleanPhone, '8801') && strlen($cleanPhone) === 13) ? substr($cleanPhone, 2) : $cleanPhone;
                $phone88 = (str_starts_with($cleanPhone, '01') && strlen($cleanPhone) === 11) ? ('88' . $cleanPhone) : $cleanPhone;
                
                $phoneStmt = $pdo->prepare("SELECT id FROM User WHERE phone IN (?, ?, ?, ?) LIMIT 1");
                $phoneStmt->execute([$cleanPhone, $phone, $phone11, $phone88]);
                $isPhoneDuplicate = (bool)$phoneStmt->fetchColumn();
                
                if ($email) {
                    $emailStmt = $pdo->prepare("SELECT id FROM User WHERE email = ? LIMIT 1");
                    $emailStmt->execute([$email]);
                    $isEmailDuplicate = (bool)$emailStmt->fetchColumn();
                }
            } catch (Exception $e) {}

            $isAjax = !empty($_POST['is_ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

            if ($isPhoneDuplicate && $isEmailDuplicate) {
                $registerError = 'এই ফোন নম্বর এবং ইমেইল উভয়টিই ইতিমধ্যে নিবন্ধিত আছে।';
            } else if ($isPhoneDuplicate) {
                $registerError = 'এই ফোন নম্বরটি ইতিমধ্যে নিবন্ধিত আছে। দয়া করে লগইন করুন অথবা অন্য নম্বর দিন।';
            } else if ($isEmailDuplicate) {
                $registerError = 'এই ইমেইলটি ইতিমধ্যে নিবন্ধিত আছে। দয়া করে লগইন করুন অথবা অন্য ইমেইল দিন।';
            }

            if (!empty($registerError)) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'error' => $registerError, 'duplicate_phone' => $isPhoneDuplicate, 'duplicate_email' => $isEmailDuplicate]);
                    exit;
                }
            } else {
                if ($isOtpRegEnabled) {
                    $deliveryMethod = $_POST['delivery_method'] ?? 'sms';
                    
                    if ($deliveryMethod === 'sms' && (empty($settings['smsEnabled']) || empty($settings['smsApiUrl']))) {
                        $registerError = 'SMS service is currently unavailable. Please use Email.';
                        if ($isAjax) { echo json_encode(['success' => false, 'error' => $registerError]); exit; }
                    } else if ($deliveryMethod === 'email' && empty($email)) {
                        $registerError = 'Email address is required to receive OTP via Email.';
                        if ($isAjax) { echo json_encode(['success' => false, 'error' => $registerError]); exit; }
                    } else {
                        $otp = mt_rand(100000, 999999);
                        $_SESSION['pending_reg'] = [
                            'name' => $name,
                            'email' => $email,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'phone' => $phone,
                            'otp' => $otp,
                            'deliveryMethod' => $deliveryMethod,
                            'expires' => time() + 600
                        ];
                        $activeTab = 'register-otp';
                        
                        if ($deliveryMethod === 'email') {
                            $template = $settings['otpEmailTemplate'] ?? "Your OTP is: {{otp_code}}";
                            $htmlContent = str_replace(['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'], [$name, $otp, $storeName, $storeName], $template);
                            require_once __DIR__ . '/api/mailer_helper.php';
                            sendDynamicEmail($pdo, $email, "Verification OTP - " . $storeName, $htmlContent);
                            $registerOtpMsg = "OTP sent to your email successfully!";
                        } else if ($deliveryMethod === 'sms') {
                            require_once __DIR__ . '/api/sms_helper.php';
                            sendRegistrationOtpSMS($pdo, $phone, $otp, $name);
                            $registerOtpMsg = "OTP sent to your phone via SMS successfully!";
                        }

                        if ($isAjax) {
                            echo json_encode([
                                'success' => true,
                                'otp_required' => true,
                                'target' => ($deliveryMethod === 'sms' ? $phone : $email),
                                'method' => $deliveryMethod,
                                'msg' => $registerOtpMsg
                            ]);
                            exit;
                        }
                    }
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    try {
                        $stmt = $pdo->prepare("INSERT INTO User (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')");
                        $stmt->execute([$name, $email, $hashedPassword, $phone]);
                        $newUserId = $pdo->lastInsertId();
                        
                        $notifStmt = $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (?, ?, 'Thank you for registering. Your account is ready.', 'info')");
                        $notifStmt->execute([$newUserId, "Welcome to $storeName!"]);

                        if (!empty($email) && !empty($settings['emailEnabled'])) {
                            $template = $settings['welcomeEmailTemplate'] ?? "";
                            if ($template) {
                                $htmlContent = str_replace(['{{customer_name}}', '{{store_name}}', 'Idea Mart'], [$name, $storeName, $storeName], $template);
                                require_once __DIR__ . '/api/mailer_helper.php';
                                sendDynamicEmail($pdo, $email, "Welcome to " . $storeName . "!", $htmlContent);
                            }
                        }
                        // Welcome SMS disabled (SMS reserved for OTP and delivery only)

                        $_SESSION['user_id'] = $newUserId;
                        $_SESSION['user_name'] = $name;

                        $dest = !empty($_SESSION['auth_redirect']) ? $_SESSION['auth_redirect'] : 'account';
                        unset($_SESSION['auth_redirect']);

                        if ($isAjax) {
                            echo json_encode(['success' => true, 'otp_required' => false, 'redirect' => $dest]);
                            exit;
                        }

                        header("Location: " . $dest);
                        exit;
                    } catch (Exception $e) {
                        $registerError = 'Registration failed. Please try again.';
                        if ($isAjax) { echo json_encode(['success' => false, 'error' => $registerError]); exit; }
                    }
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'resend_reg_otp') {
        header('Content-Type: application/json');
        if (isset($_SESSION['pending_reg'])) {
            $regData = $_SESSION['pending_reg'];
            $otp = mt_rand(100000, 999999);
            $_SESSION['pending_reg']['otp'] = $otp;
            $_SESSION['pending_reg']['expires'] = time() + 600;
            
            $deliveryMethod = $regData['deliveryMethod'] ?? 'email';
            
            if ($deliveryMethod === 'email') {
                $template = $settings['otpEmailTemplate'] ?? "Your OTP is: {{otp_code}}";
                $htmlContent = str_replace(['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'], [$regData['name'], $otp, $storeName, $storeName], $template);
                require_once __DIR__ . '/api/mailer_helper.php';
                sendDynamicEmail($pdo, $regData['email'], "Verification OTP - " . $storeName, $htmlContent);
                echo json_encode(['success' => true, 'target' => $regData['email'], 'method' => 'email']);
            } else if ($deliveryMethod === 'sms') {
                require_once __DIR__ . '/api/sms_helper.php';
                sendRegistrationOtpSMS($pdo, $regData['phone'], $otp, $regData['name']);
                echo json_encode(['success' => true, 'target' => $regData['phone'], 'method' => 'sms']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid delivery method']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Session expired. Please start over.']);
        }
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'verify_reg_otp') {
        $activeTab = 'register-otp';
        $otp = trim($_POST['otp'] ?? '');
        
        if (isset($_SESSION['pending_reg']) && $_SESSION['pending_reg']['expires'] > time()) {
            if ($otp == $_SESSION['pending_reg']['otp']) {
                $regData = $_SESSION['pending_reg'];
                try {
                    $stmt = $pdo->prepare("INSERT INTO User (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')");
                    $stmt->execute([$regData['name'], $regData['email'], $regData['password'], $regData['phone']]);
                    $newUserId = $pdo->lastInsertId();
                    
                    $notifStmt = $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (?, ?, 'Thank you for registering. Your account is ready.', 'info')");
                    $notifStmt->execute([$newUserId, "Welcome to $storeName!"]);

                    if (!empty($regData['email']) && !empty($settings['emailEnabled'])) {
                        $template = $settings['welcomeEmailTemplate'] ?? "";
                        if ($template) {
                            $htmlContent = str_replace(['{{customer_name}}', '{{store_name}}', 'Idea Mart'], [$regData['name'], $storeName, $storeName], $template);
                            require_once __DIR__ . '/api/mailer_helper.php';
                            sendDynamicEmail($pdo, $regData['email'], "Welcome to " . $storeName . "!", $htmlContent);
                        }
                    }
                    // Welcome SMS disabled (SMS reserved for OTP and delivery only)

                    $_SESSION['user_id'] = $newUserId;
                    $_SESSION['user_name'] = $regData['name'];
                    unset($_SESSION['pending_reg']);
                    
                    $dest = !empty($_SESSION['auth_redirect']) ? $_SESSION['auth_redirect'] : 'account';
                    unset($_SESSION['auth_redirect']);
                    header("Location: " . $dest);
                    exit;
                } catch (Exception $e) {
                    $registerError = 'Registration failed. Please try again.';
                }
            } else {
                $registerOtpError = "Invalid OTP.";
            }
        } else {
            $registerError = "Session expired. Please register again.";
            $activeTab = 'register';
        }
    }
}

include 'Header.php';
?>

<div class="min-h-screen pt-8 pb-16 sm:py-16 bg-gradient-to-b from-slate-100 via-slate-50 to-white font-sans flex flex-col justify-center relative overflow-hidden">
    <!-- Ambient background ambient glow circles -->
    <div class="hidden sm:block absolute -top-40 -left-40 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="hidden sm:block absolute top-1/2 -right-40 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
    
    <div class="max-w-[450px] w-full mx-auto px-4 sm:px-5 relative z-10">
        
        <!-- Store Branding Header (Compact) -->
        <div class="text-center mb-6">
            <?php if ($logoUrl): ?>
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($storeName); ?>" class="h-12 sm:h-14 w-auto mx-auto mb-3 object-contain">
            <?php else: ?>
                <div class="w-12 h-12 bg-gradient-to-tr from-[#003e86] to-blue-600 text-white rounded-2xl flex items-center justify-center mx-auto mb-3 shadow-lg shadow-blue-900/20">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            <?php endif; ?>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight"><?php echo htmlspecialchars($storeName ?: 'My Account'); ?></h1>
            <p class="text-xs font-semibold text-slate-500 mt-1">Sign in or create an account to manage orders</p>
        </div>

        <!-- Compact Main Card -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-2xl shadow-slate-200/70 border border-slate-100">
            <!-- Modern Sliding Tab Switcher -->
            <div class="bg-slate-100/90 p-1 rounded-2xl flex mb-6 relative border border-slate-200/80 shadow-inner">
                <div id="toggle-slider" class="absolute left-1 top-1 bottom-1 w-[calc(50%-4px)] bg-white rounded-xl shadow-sm border border-slate-200/60 transition-transform duration-300 ease-out"></div>
                <button type="button" onclick="switchForm('login')" id="btn-login" class="flex-1 py-2.5 text-sm font-black z-10 text-[#003e86] transition-colors relative flex items-center justify-center gap-1.5">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>
                    Sign In
                </button>
                <button type="button" onclick="switchForm('register')" id="btn-register" class="flex-1 py-2.5 text-sm font-bold z-10 text-slate-500 hover:text-slate-800 transition-colors relative flex items-center justify-center gap-1.5">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                    Create Account
                </button>
            </div>

            <!-- 1. LOGIN CARD -->
            <div id="login-card" class="hidden">
                <div class="mb-6">
                    <h2 class="text-xl font-black text-slate-900 tracking-tight mb-1">Welcome Back 👋</h2>
                    <p class="text-xs text-slate-500 font-medium">Enter your registered email or phone number to sign in.</p>
                </div>
                        
                    <?php if($loginError): ?>
                    <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-600 text-sm font-bold rounded-2xl flex items-center gap-3 animate-in fade-in slide-in-from-top-2 shadow-sm">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> 
                        <span><?php echo $loginError; ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="login">
                        <input type="hidden" name="action" value="login">
                        <div class="space-y-4 mb-7">
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Email or Phone</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </div>
                                    <input type="text" name="contact" required placeholder="017XXXXXXXX or you@example.com" class="w-full pl-11 pr-4 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                </div>
                            </div>
                            
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </div>
                                    <input type="password" name="password" id="login-password" required placeholder="••••••••" class="w-full pl-11 pr-12 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                    <button type="button" onclick="togglePasswordVisibility('login-password', 'login-eye-icon')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#003e86] transition-colors p-1" title="Toggle password">
                                        <svg id="login-eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                                <div class="flex flex-wrap justify-between items-center gap-3 pt-3">
                                    <label class="relative inline-flex items-center cursor-pointer group">
                                        <input type="checkbox" name="remember_me" class="sr-only peer">
                                        <div class="w-10 h-5.5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4.5 after:w-4.5 after:transition-all peer-checked:bg-[#003e86]"></div>
                                        <span class="ml-2.5 text-xs font-bold text-slate-600 group-hover:text-slate-900 transition-colors">Remember Me</span>
                                    </label>
                                    <a href="forgot-password" class="text-xs font-bold text-[#003e86] hover:text-blue-800 transition-colors flex items-center gap-1">
                                        Forgot Password?
                                    </a>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-4 bg-gradient-to-r from-[#003e86] via-[#004899] to-blue-700 hover:from-blue-900 hover:to-indigo-800 text-white rounded-2xl font-black text-base shadow-xl shadow-blue-900/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                            <span>Sign In to Account</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </button>
                    </form>
                </div>

                <!-- 2. REGISTER CARD -->
                <div id="register-card" class="hidden">
                    <div class="mb-6">
                        <h2 class="text-2xl font-black text-slate-900 tracking-tight mb-1.5">Join Us Today ✨</h2>
                        <p class="text-sm text-slate-500 font-medium">Create your profile to start tracking orders and earning perks.</p>
                    </div>
                        
                    <!-- Inline Error Alert for Register -->
                    <div id="register-ajax-error" class="<?php echo !empty($registerError) ? 'flex' : 'hidden'; ?> mb-5 p-4 bg-rose-50 border border-rose-200 text-rose-600 text-sm font-bold rounded-2xl items-center gap-3 animate-in fade-in slide-in-from-top-2 shadow-sm">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> 
                        <span id="register-ajax-error-text"><?php echo htmlspecialchars($registerError ?? ''); ?></span>
                    </div>

                    <form id="register-form" method="POST" action="login" onsubmit="handleRegisterSubmit(event)">
                        <input type="hidden" name="action" value="register">
                        <div class="space-y-4 mb-6">
                            <!-- Full Name -->
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Full Name</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </div>
                                    <input type="text" id="reg-name" name="name" required placeholder="John Doe" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" class="w-full pl-11 pr-4 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                </div>
                            </div>

                            <!-- Phone with Live Validation -->
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Phone Number <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    </div>
                                    <input type="tel" id="reg-phone" name="phone" required placeholder="017XXXXXXXX" oninput="checkPhoneAvailability(this.value)" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" class="w-full pl-11 pr-4 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                </div>
                                <div id="reg-phone-feedback" class="text-xs font-semibold mt-1 px-1 hidden transition-all"></div>
                            </div>

                            <!-- Email with Live Validation -->
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Email Address <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12.75V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8"/><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                    </div>
                                    <input type="email" id="reg-email" name="email" required placeholder="you@example.com" oninput="checkEmailAvailability(this.value)" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="w-full pl-11 pr-4 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                </div>
                                <div id="reg-email-feedback" class="text-xs font-semibold mt-1 px-1 hidden transition-all"></div>
                            </div>

                            <!-- Password -->
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </div>
                                    <input type="password" name="password" id="reg-password" required placeholder="Create a secure password" value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>" class="w-full pl-11 pr-12 py-3.5 bg-slate-50/80 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                                    <button type="button" onclick="togglePasswordVisibility('reg-password', 'reg-eye-icon')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#003e86] transition-colors p-1" title="Toggle password">
                                        <svg id="reg-eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- OTP Delivery Option -->
                        <?php if (!empty($settings['smsEnabled']) && $isOtpRegEnabled): ?>
                        <div class="mb-6 space-y-1.5">
                            <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block mb-2">Send Verification OTP via</label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="cursor-pointer">
                                    <input type="radio" name="delivery_method" value="sms" class="peer sr-only" checked>
                                    <div class="py-3 px-3 flex items-center justify-center gap-2 border-2 border-slate-200 rounded-2xl peer-checked:border-blue-600 peer-checked:bg-blue-50/80 peer-checked:text-blue-900 font-bold text-xs text-slate-600 transition-all bg-slate-50/50 hover:bg-slate-100">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> 
                                        <span>SMS OTP</span>
                                    </div>
                                </label>
                                <label class="cursor-pointer">
                                    <input type="radio" name="delivery_method" value="email" class="peer sr-only">
                                    <div class="py-3 px-3 flex items-center justify-center gap-2 border-2 border-slate-200 rounded-2xl peer-checked:border-blue-600 peer-checked:bg-blue-50/80 peer-checked:text-blue-900 font-bold text-xs text-slate-600 transition-all bg-slate-50/50 hover:bg-slate-100">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> 
                                        <span>Email OTP</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="delivery_method" value="email">
                        <?php endif; ?>

                        <button type="submit" id="btn-create-account" class="w-full py-4 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 hover:from-slate-800 hover:to-slate-700 text-white rounded-2xl font-black text-base shadow-xl shadow-slate-900/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                            <span id="btn-create-text">Create Account</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </button>
                    </form>
                </div>

                <!-- 3. REGISTER OTP CARD -->
                <div id="register-otp-card" class="hidden">
                    <div class="text-center mb-6">
                        <!-- Recipient Target Badge -->
                        <div id="reg-otp-badge" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 border border-blue-200 rounded-full text-blue-700 text-xs font-bold mb-3 shadow-sm animate-in fade-in zoom-in">
                            <span id="reg-otp-badge-icon">📱</span>
                            <span id="reg-otp-badge-text">SMS Sent to:</span>
                            <span id="reg-otp-target-display" class="font-mono font-black text-slate-900 bg-white px-2.5 py-1 rounded-lg border border-blue-200"><?php echo htmlspecialchars($_SESSION['pending_reg']['phone'] ?? ''); ?></span>
                        </div>
                        <h2 class="text-2xl font-black text-slate-900 tracking-tight mb-1.5">Verify Your Account 🔐</h2>
                        <p class="text-sm text-slate-500 font-medium" id="reg-otp-subtitle">Please enter the 6-digit OTP code sent to your phone via SMS.</p>
                    </div>
                        
                    <?php if(!empty($registerOtpMsg)): ?>
                    <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-bold rounded-2xl flex items-center gap-3 animate-in fade-in slide-in-from-top-2 shadow-sm">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> 
                        <span id="register-otp-success-msg"><?php echo $registerOtpMsg; ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if(!empty($registerOtpError)): ?>
                    <div class="mb-5 p-4 bg-rose-50 border border-rose-200 text-rose-600 text-sm font-bold rounded-2xl flex items-center gap-3 animate-in fade-in slide-in-from-top-2 shadow-sm">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> 
                        <span><?php echo $registerOtpError; ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="login">
                        <input type="hidden" name="action" value="verify_reg_otp">
                        <div class="space-y-4 mb-7">
                            <div class="space-y-2">
                                <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest text-center block">Enter 6-Digit OTP</label>
                                <input type="text" name="otp" required placeholder="------" maxlength="6" class="w-full px-5 py-4 bg-slate-50 hover:bg-slate-100/70 border-2 border-slate-200 rounded-2xl text-3xl tracking-[0.7em] text-center font-mono font-black focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all text-slate-800">
                                
                                <div class="flex justify-between items-center mt-3 px-2 max-w-[280px] mx-auto">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-blue-600 animate-ping"></span>
                                        <span id="register-timer" class="text-xs font-mono font-bold text-slate-600">02:30</span>
                                    </div>
                                    <button type="button" id="btn-resend-reg-otp" onclick="resendRegOtp()" class="text-xs font-bold text-[#003e86] hover:text-blue-800 hidden hover:underline">
                                        Resend Code
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-4 bg-gradient-to-r from-[#003e86] to-blue-700 hover:from-blue-900 hover:to-indigo-800 text-white rounded-2xl font-black text-base shadow-xl shadow-blue-900/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                            <span>Verify & Complete Registration</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </button>
                        
                        <div class="mt-5 text-center">
                            <button type="button" onclick="switchForm('register')" class="text-xs font-bold text-slate-400 hover:text-slate-700 transition-colors">
                                ← Change phone/email details
                            </button>
                        </div>
                    </form>
                </div>

        </div>
    </div>
</div>

<script>
function switchForm(formType, isInitial = false) {
    const loginCard = document.getElementById('login-card');
    const registerCard = document.getElementById('register-card');
    const registerOtpCard = document.getElementById('register-otp-card');
    const slider = document.getElementById('toggle-slider');
    const btnLogin = document.getElementById('btn-login');
    const btnRegister = document.getElementById('btn-register');

    loginCard.classList.add('hidden');
    registerCard.classList.add('hidden');
    if(registerOtpCard) registerOtpCard.classList.add('hidden');

    if (formType === 'login') {
        if (!isInitial) {
            loginCard.classList.remove('hidden');
            loginCard.classList.remove('animate-in', 'fade-in', 'slide-in-from-right-8');
            loginCard.classList.add('animate-in', 'fade-in', 'slide-in-from-left-8', 'duration-500');
        } else {
            loginCard.classList.remove('hidden');
        }
        slider.style.transform = 'translateX(0)';
        btnLogin.classList.add('font-black', 'text-[#003e86]');
        btnLogin.classList.remove('font-bold', 'text-slate-500');
        btnRegister.classList.add('font-bold', 'text-slate-500');
        btnRegister.classList.remove('font-black', 'text-[#003e86]');
    } else if (formType === 'register-otp') {
        if (registerOtpCard) registerOtpCard.classList.remove('hidden');
        slider.style.transform = 'translateX(100%)';
        btnRegister.classList.add('font-black', 'text-[#003e86]');
        btnRegister.classList.remove('font-bold', 'text-slate-500');
        btnLogin.classList.add('font-bold', 'text-slate-500');
        btnLogin.classList.remove('font-black', 'text-[#003e86]');
    } else {
        if (!isInitial) {
            registerCard.classList.remove('hidden');
            registerCard.classList.remove('animate-in', 'fade-in', 'slide-in-from-left-8');
            registerCard.classList.add('animate-in', 'fade-in', 'slide-in-from-right-8', 'duration-500');
        } else {
            registerCard.classList.remove('hidden');
        }
        slider.style.transform = 'translateX(100%)';
        btnRegister.classList.add('font-black', 'text-[#003e86]');
        btnRegister.classList.remove('font-bold', 'text-slate-500');
        btnLogin.classList.add('font-bold', 'text-slate-500');
        btnLogin.classList.remove('font-black', 'text-[#003e86]');
    }
}

function togglePasswordVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
    }
}

let regTimerInterval;
function startRegTimer(duration) {
    clearInterval(regTimerInterval);
    const timerEl = document.getElementById('register-timer');
    const resendBtn = document.getElementById('btn-resend-reg-otp');
    if (!timerEl || !resendBtn) return;
    
    let timer = duration;
    timerEl.classList.remove('hidden');
    resendBtn.classList.add('hidden');
    
    regTimerInterval = setInterval(function () {
        let minutes = parseInt(timer / 60, 10);
        let seconds = parseInt(timer % 60, 10);
        minutes = minutes < 10 ? "0" + minutes : minutes;
        seconds = seconds < 10 ? "0" + seconds : seconds;
        timerEl.textContent = minutes + ":" + seconds;
        if (--timer < 0) {
            clearInterval(regTimerInterval);
            timerEl.classList.add('hidden');
            resendBtn.classList.remove('hidden');
        }
    }, 1000);
}

let phoneCheckTimer, emailCheckTimer;
let isPhoneRegistered = false, isEmailRegistered = false;

function checkPhoneAvailability(phoneVal) {
    clearTimeout(phoneCheckTimer);
    const feedback = document.getElementById('reg-phone-feedback');
    const input = document.getElementById('reg-phone');
    const cleanDigits = (phoneVal || '').replace(/\D/g, '');
    if (cleanDigits.length < 11) {
        feedback.classList.add('hidden');
        input.classList.remove('border-rose-400', 'border-emerald-400', 'bg-rose-50/20', 'bg-emerald-50/20');
        isPhoneRegistered = false;
        return;
    }
    phoneCheckTimer = setTimeout(async () => {
        try {
            const res = await fetch('api/check_user.php?phone=' + encodeURIComponent(phoneVal));
            const data = await res.json();
            if (data.phone_checked) {
                feedback.classList.remove('hidden');
                if (data.phone_registered) {
                    isPhoneRegistered = true;
                    feedback.className = 'text-xs font-bold text-rose-600 mt-1 px-1 flex items-center gap-1.5 animate-in fade-in';
                    feedback.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' + data.phone_msg;
                    input.classList.add('border-rose-400', 'bg-rose-50/20');
                    input.classList.remove('border-emerald-400', 'bg-emerald-50/20');
                } else {
                    isPhoneRegistered = false;
                    feedback.className = 'text-xs font-bold text-emerald-600 mt-1 px-1 flex items-center gap-1.5 animate-in fade-in';
                    feedback.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' + data.phone_msg;
                    input.classList.add('border-emerald-400', 'bg-emerald-50/20');
                    input.classList.remove('border-rose-400', 'bg-rose-50/20');
                }
            }
        } catch (e) {}
    }, 350);
}

function checkEmailAvailability(emailVal) {
    clearTimeout(emailCheckTimer);
    const feedback = document.getElementById('reg-email-feedback');
    const input = document.getElementById('reg-email');
    if (!emailVal || !emailVal.includes('@')) {
        feedback.classList.add('hidden');
        input.classList.remove('border-rose-400', 'border-emerald-400', 'bg-rose-50/20', 'bg-emerald-50/20');
        isEmailRegistered = false;
        return;
    }
    emailCheckTimer = setTimeout(async () => {
        try {
            const res = await fetch('api/check_user.php?email=' + encodeURIComponent(emailVal));
            const data = await res.json();
            if (data.email_checked) {
                feedback.classList.remove('hidden');
                if (data.email_registered) {
                    isEmailRegistered = true;
                    feedback.className = 'text-xs font-bold text-rose-600 mt-1 px-1 flex items-center gap-1.5 animate-in fade-in';
                    feedback.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' + data.email_msg;
                    input.classList.add('border-rose-400', 'bg-rose-50/20');
                    input.classList.remove('border-emerald-400', 'bg-emerald-50/20');
                } else {
                    isEmailRegistered = false;
                    feedback.className = 'text-xs font-bold text-emerald-600 mt-1 px-1 flex items-center gap-1.5 animate-in fade-in';
                    feedback.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' + data.email_msg;
                    input.classList.add('border-emerald-400', 'bg-emerald-50/20');
                    input.classList.remove('border-rose-400', 'bg-rose-50/20');
                }
            }
        } catch (e) {}
    }, 350);
}

async function handleRegisterSubmit(e) {
    e.preventDefault();
    const errorBox = document.getElementById('register-ajax-error');
    const errorText = document.getElementById('register-ajax-error-text');
    errorBox.classList.add('hidden');
    errorBox.classList.remove('flex');

    if (isPhoneRegistered) {
        errorBox.classList.remove('hidden');
        errorBox.classList.add('flex');
        errorText.innerText = 'এই ফোন নম্বরটি ইতিমধ্যে নিবন্ধিত আছে। অন্য নম্বর দিন।';
        document.getElementById('reg-phone').focus();
        return;
    }

    if (isEmailRegistered) {
        errorBox.classList.remove('hidden');
        errorBox.classList.add('flex');
        errorText.innerText = 'এই ইমেইলটি ইতিমধ্যে নিবন্ধিত আছে। অন্য ইমেইল দিন।';
        document.getElementById('reg-email').focus();
        return;
    }

    const btn = document.getElementById('btn-create-account');
    const btnText = document.getElementById('btn-create-text');
    btn.disabled = true;
    const originalText = btnText.innerText;
    btnText.innerText = 'Creating Account...';

    const form = document.getElementById('register-form');
    const formData = new FormData(form);
    formData.append('is_ajax', '1');

    try {
        const res = await fetch('login', { method: 'POST', body: formData });
        const result = await res.json();
        btn.disabled = false;
        btnText.innerText = originalText;

        if (result.success) {
            if (result.otp_required) {
                // Show recipient phone/email in OTP Card
                if (result.target) {
                    const targetEl = document.getElementById('reg-otp-target-display');
                    if (targetEl) targetEl.innerText = result.target;
                    
                    const badgeText = document.getElementById('reg-otp-badge-text');
                    const badgeIcon = document.getElementById('reg-otp-badge-icon');
                    const subtitle = document.getElementById('reg-otp-subtitle');
                    if (result.method === 'sms') {
                        if (badgeIcon) badgeIcon.innerText = '📱';
                        if (badgeText) badgeText.innerText = 'SMS Sent to:';
                        if (subtitle) subtitle.innerText = 'Please enter the 6-digit OTP code sent to your phone via SMS.';
                    } else {
                        if (badgeIcon) badgeIcon.innerText = '✉️';
                        if (badgeText) badgeText.innerText = 'Email Sent to:';
                        if (subtitle) subtitle.innerText = 'Please enter the 6-digit OTP code sent to your email.';
                    }
                }
                if (window.showToast) showToast(result.msg || "OTP Sent!", "success");
                switchForm('register-otp');
                startRegTimer(150);
            } else if (result.redirect) {
                window.location.href = result.redirect;
            }
        } else {
            errorBox.classList.remove('hidden');
            errorBox.classList.add('flex');
            errorText.innerText = result.error || 'Registration failed. Please try again.';
            if (result.duplicate_phone) document.getElementById('reg-phone').focus();
            else if (result.duplicate_email) document.getElementById('reg-email').focus();
        }
    } catch (err) {
        btn.disabled = false;
        btnText.innerText = originalText;
        // Fallback to regular form submit if fetch fails
        form.submit();
    }
}

async function resendRegOtp() {
    const btn = document.getElementById('btn-resend-reg-otp');
    btn.innerText = 'Sending...';
    btn.disabled = true;
    const formData = new FormData();
    formData.append('action', 'resend_reg_otp');
    try {
        const res = await fetch('login.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (result.success) {
            if (result.target) {
                const targetEl = document.getElementById('reg-otp-target-display');
                if (targetEl) targetEl.innerText = result.target;
            }
            if (window.showToast) showToast("OTP Resent Successfully", "success");
            startRegTimer(150);
        } else {
            if (window.showToast) showToast(result.error, "error");
        }
    } catch (e) {
        if (window.showToast) showToast("Network error. Please try again.", "error");
    } finally {
        btn.innerText = 'Resend Code';
        btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    switchForm('<?php echo htmlspecialchars($activeTab); ?>', true);
    if ('<?php echo htmlspecialchars($activeTab); ?>' === 'register-otp') {
        startRegTimer(150);
    }
});
</script>
<?php include 'Footer.php'; ?>