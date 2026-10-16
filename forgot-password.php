<?php
session_start();
require_once __DIR__ . '/db.php';

try {
    $setStmt = $pdo->query("SELECT g.*, n.* FROM SettingGeneral g LEFT JOIN SettingNotification n ON g.id = n.id LIMIT 1");
    $settings = $setStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $settings = [];
}
$isOtpEnabled = (!isset($settings['otpSystemEnabled']) || $settings['otpSystemEnabled']);
$logoUrl = !empty($settings['logoUrl']) ? ltrim($settings['logoUrl'], '/') : null;
$storeName = $settings['storeName'] ?? '';

function maskEmailAddress($email) {
    if (empty($email) || strpos($email, '@') === false) return $email;
    list($name, $domain) = explode('@', $email, 2);
    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName = substr($name, 0, 2) . str_repeat('*', min(4, max(1, $len - 3))) . substr($name, -1);
    }
    return $maskedName . '@' . $domain;
}

function maskPhoneNumber($phone) {
    if (empty($phone)) return $phone;
    $clean = preg_replace('/[^\d]/', '', $phone);
    if (strlen($clean) >= 11) {
        return substr($clean, 0, 3) . '****' . substr($clean, -4);
    }
    return $phone;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$isOtpEnabled) {
        echo json_encode(['success' => false, 'error' => 'Password reset is currently disabled by the administrator.']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'request_otp') {
        // Rate limit: max 1 request per 60 seconds
        $lastRequestTime = $_SESSION['last_otp_request_time'] ?? 0;
        if (time() - $lastRequestTime < 60) {
            $wait = 60 - (time() - $lastRequestTime);
            echo json_encode(['success' => false, 'error' => "Please wait {$wait} seconds before requesting another OTP."]);
            exit;
        }

        $contact = trim($_POST['contact'] ?? '');
        $cleanContact = preg_replace('/[^\d]/', '', $contact);
        $phone11 = (str_starts_with($cleanContact, '8801') && strlen($cleanContact) === 13) ? substr($cleanContact, 2) : $cleanContact;
        $phone88 = (str_starts_with($cleanContact, '01') && strlen($cleanContact) === 11) ? ('88' . $cleanContact) : $cleanContact;
        $phonePlus88 = '+88' . $phone11;

        $stmt = $pdo->prepare("SELECT id, name, email, phone FROM User WHERE (email = ? OR phone IN (?, ?, ?, ?, ?)) AND role = 'customer' LIMIT 1");
        $stmt->execute([$contact, $contact, $cleanContact, $phone11, $phone88, $phonePlus88]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $smsAvailable = (!empty($settings['smsEnabled']) && !empty($settings['smsApiUrl']));
            $hasPhone = !empty($user['phone']);
            $hasEmail = (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL));
            
            $isPhoneInput = (!empty($cleanContact) && strlen($cleanContact) >= 10);
            $deliveryMethod = $_POST['delivery_method'] ?? ($isPhoneInput ? 'sms' : 'email');

            $actualMethod = '';
            $fallbackUsed = false;
            $fallbackMsg = '';

            // Check if user or system wants SMS
            if ($deliveryMethod === 'sms' || $isPhoneInput) {
                if ($smsAvailable && $hasPhone) {
                    $actualMethod = 'sms';
                } else {
                    // SMS is OFF or unavailable -> Fallback to Email!
                    if ($hasEmail) {
                        $actualMethod = 'email';
                        $fallbackUsed = true;
                        $fallbackMsg = 'এসএমএস সার্ভিস বন্ধ থাকায় আপনার অ্যাকাউন্টে নিবন্ধিত ইমেইলে (' . maskEmailAddress($user['email']) . ') ওটিপি কোড পাঠানো হয়েছে।';
                    } else {
                        echo json_encode([
                            'success' => false, 
                            'error' => 'এসএমএস সার্ভিস বর্তমানে বন্ধ এবং আপনার অ্যাকাউন্টে কোনো ইমেইল যুক্ত নেই। অনুগ্রহ করে কাস্টমার সাপোর্টে যোগাযোগ করুন।'
                        ]);
                        exit;
                    }
                }
            } else {
                // Email requested
                if ($hasEmail) {
                    $actualMethod = 'email';
                } else if ($smsAvailable && $hasPhone) {
                    $actualMethod = 'sms';
                    $fallbackUsed = true;
                    $fallbackMsg = 'অ্যাকাউন্টে কোনো ইমেইল না থাকায় মোবাইল নম্বরে এসএমএস এর মাধ্যমে ওটিপি পাঠানো হয়েছে।';
                } else {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'আপনার অ্যাকাউন্টে কোনো নিবন্ধিত ইমেইল বা চালু মোবাইল নম্বর পাওয়া যায়নি।'
                    ]);
                    exit;
                }
            }

            $otp = mt_rand(100000, 999999);
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $pdo->prepare("UPDATE User SET otpCode = ?, otpExpiresAt = ? WHERE id = ?")->execute([$otp, $expires, $user['id']]);
            $_SESSION['last_otp_request_time'] = time();
            $_SESSION['otp_attempts_' . $user['id']] = 0; // reset attempts for new OTP
            
            if ($actualMethod === 'sms') {
                require_once __DIR__ . '/api/sms_helper.php';
                $smsRes = sendForgotPasswordOtpSMS($pdo, $user['phone'], $otp, $user['name']);
                if (!empty($smsRes['success'])) {
                    echo json_encode([
                        'success' => true, 
                        'userId' => $user['id'], 
                        'target' => maskPhoneNumber($user['phone']),
                        'method' => 'sms',
                        'fallback' => $fallbackUsed,
                        'msg' => $fallbackUsed ? $fallbackMsg : 'আপনার মোবাইল নম্বরে এসএমএস এর মাধ্যমে ওটিপি পাঠানো হয়েছে।'
                    ]);
                    exit;
                } else {
                    // Gateway failed, fallback to email if available
                    if ($hasEmail) {
                        $template = $settings['otpEmailTemplate'] ?? "Your OTP is: {{otp_code}}";
                        $htmlContent = str_replace(['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'], [$user['name'], $otp, $storeName, $storeName], $template);
                        require_once __DIR__ . '/api/mailer_helper.php';
                        sendDynamicEmail($pdo, $user['email'], "Password Reset OTP - " . $storeName, $htmlContent);
                        echo json_encode([
                            'success' => true, 
                            'userId' => $user['id'], 
                            'target' => maskEmailAddress($user['email']),
                            'method' => 'email',
                            'fallback' => true,
                            'msg' => 'এসএমএস গেটওয়ে সমস্যায় আপনার ইমেইলে (' . maskEmailAddress($user['email']) . ') ওটিপি কোড পাঠানো হয়েছে।'
                        ]);
                        exit;
                    } else {
                        echo json_encode(['success' => false, 'error' => 'এসএমএস ওটিপি পাঠাতে ব্যর্থ হয়েছে: ' . ($smsRes['error'] ?? 'গেটওয়ে সংযোগ ত্রুটি')]);
                        exit;
                    }
                }
            } else {
                // Send OTP via Email
                $template = $settings['otpEmailTemplate'] ?? "Your OTP is: {{otp_code}}";
                $htmlContent = str_replace(['{{customer_name}}', '{{otp_code}}', '{{store_name}}', 'Idea Mart'], [$user['name'], $otp, $storeName, $storeName], $template);
                require_once __DIR__ . '/api/mailer_helper.php';
                sendDynamicEmail($pdo, $user['email'], "Password Reset OTP - " . $storeName, $htmlContent);
                echo json_encode([
                    'success' => true, 
                    'userId' => $user['id'], 
                    'target' => maskEmailAddress($user['email']),
                    'method' => 'email',
                    'fallback' => $fallbackUsed,
                    'msg' => $fallbackUsed ? $fallbackMsg : 'আপনার নিবন্ধিত ইমেইলে ওটিপি কোড পাঠানো হয়েছে!'
                ]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'এই ইমেইল বা মোবাইল নম্বরের সাথে কোনো অ্যাকাউন্ট পাওয়া যায়নি।']);
        }
        exit;
    }
    
    if ($action === 'verify_otp') {
        $userId = (int)($_POST['userId'] ?? 0);
        $otp = trim($_POST['otp'] ?? '');
        
        $attempts = $_SESSION['otp_attempts_' . $userId] ?? 0;
        if ($attempts >= 5) {
            $pdo->prepare("UPDATE User SET otpCode = NULL, otpExpiresAt = NULL WHERE id = ?")->execute([$userId]);
            echo json_encode(['success' => false, 'error' => 'Too many incorrect attempts. For security, this OTP has expired. Please request a new one.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM User WHERE id = ? AND otpCode = ? AND otpExpiresAt > NOW()");
        $stmt->execute([$userId, $otp]);
        if ($stmt->fetch()) {
            $_SESSION['otp_verified_' . $userId] = true;
            unset($_SESSION['otp_attempts_' . $userId]);
            echo json_encode(['success' => true]);
        } else {
            $attempts++;
            $_SESSION['otp_attempts_' . $userId] = $attempts;
            $remaining = 5 - $attempts;
            if ($remaining <= 0) {
                $pdo->prepare("UPDATE User SET otpCode = NULL, otpExpiresAt = NULL WHERE id = ?")->execute([$userId]);
                echo json_encode(['success' => false, 'error' => 'Too many incorrect attempts. This OTP has been invalidated.']);
            } else {
                echo json_encode(['success' => false, 'error' => "Invalid or expired OTP. {$remaining} attempts remaining."]);
            }
        }
        exit;
    }
    
    if ($action === 'reset_password') {
        $userId = (int)($_POST['userId'] ?? 0);
        $otp = trim($_POST['otp'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT id FROM User WHERE id = ? AND otpCode = ? AND otpExpiresAt > NOW()");
        $stmt->execute([$userId, $otp]);
        if ($stmt->fetch()) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE User SET password = ?, otpCode = NULL, otpExpiresAt = NULL WHERE id = ?")->execute([$hashed, $userId]);
            unset($_SESSION['otp_attempts_' . $userId]);
            unset($_SESSION['otp_verified_' . $userId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Session expired or invalid OTP. Please start over.']);
        }
        exit;
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
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
            <?php endif; ?>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Reset Password</h1>
            <p class="text-xs font-semibold text-slate-500 mt-1">Recover your account access in 3 simple steps</p>
        </div>

        <?php if (!$isOtpEnabled): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 p-8 rounded-3xl shadow-sm text-center">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mx-auto mb-3"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                <h2 class="text-lg font-bold mb-2">Service Unavailable</h2>
                <p class="text-sm font-medium">Password reset via OTP is currently disabled by the administrator. Please contact customer care.</p>
                <a href="login" class="mt-6 inline-block bg-rose-600 text-white font-bold px-6 py-2.5 rounded-xl hover:bg-rose-700 transition-colors">Return to Login</a>
            </div>
        <?php else: ?>
            <!-- Compact Multi-step Card Container -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-2xl shadow-slate-200/70 border border-slate-100">
                
                <!-- Step 1: Request OTP -->
                <form id="step-1" class="w-full animate-in fade-in duration-300" onsubmit="handleRequestOTP(event)">
                    <div class="mb-6">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-50 border border-blue-200 text-blue-700 text-[11px] font-bold rounded-full mb-2">
                            Step 1 of 3 • Identify Account
                        </span>
                        <h2 class="text-xl font-black text-slate-900 tracking-tight mb-1">Forgot your password? 🔑</h2>
                        <p class="text-xs text-slate-500 font-medium">Enter your registered email or phone number to receive a verification OTP.</p>
                    </div>
                    
                    <!-- Inline Error Message Box -->
                    <div id="request-error" class="hidden mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-600 text-xs font-bold rounded-2xl items-center gap-2.5 animate-in fade-in shadow-sm">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> 
                        <span id="request-error-text"></span>
                    </div>

                    <div class="space-y-1.5 mb-5">
                        <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">Email or Phone Number</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <input type="text" id="contact-input" required placeholder="017XXXXXXXX or you@example.com" class="w-full pl-11 pr-4 py-3.5 bg-slate-50 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all">
                        </div>
                    </div>

                    <?php if (!empty($settings['smsEnabled'])): ?>
                    <div class="mb-6 space-y-1.5">
                        <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block mb-2">Send Reset OTP via</label>
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

                    <button type="submit" id="btn-1" class="w-full py-4 bg-gradient-to-r from-[#003e86] to-blue-700 hover:from-blue-900 hover:to-indigo-800 text-white rounded-2xl font-black text-base shadow-xl shadow-blue-900/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>Send OTP Code</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                    </button>
                    
                    <div class="mt-6 text-center">
                        <a href="login" class="text-xs font-bold text-slate-500 hover:text-[#003e86] transition-colors inline-flex items-center gap-1.5">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                            Back to Sign In
                        </a>
                    </div>
                </form>

                <!-- Step 2: Verify OTP -->
                <form id="step-2" class="w-full hidden animate-in fade-in duration-300" onsubmit="handleVerifyOTP(event)">
                    <div class="text-center mb-6">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-50 border border-blue-200 text-blue-700 text-[11px] font-bold rounded-full mb-3">
                            Step 2 of 3 • Code Verification
                        </span>
                        
                        <!-- Target Recipient Badge -->
                        <div id="otp-badge" class="block mb-3">
                            <div class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-50 border border-blue-200 rounded-full text-blue-700 text-xs font-bold shadow-sm">
                                <span id="otp-badge-icon">📱</span>
                                <span id="otp-badge-text">SMS Sent to:</span>
                                <span id="otp-target-display" class="font-mono font-black text-slate-900 bg-white px-2 py-0.5 rounded-lg border border-blue-200"></span>
                            </div>
                        </div>

                        <h2 class="text-xl font-black text-slate-900 tracking-tight mb-1">Enter Verification Code 🔐</h2>
                        <p class="text-xs text-slate-500 font-medium" id="otp-subtext">We've sent a 6-digit code. Please enter it below.</p>
                    </div>

                    <div class="space-y-2 mb-6">
                        <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 text-center block">6-Digit OTP</label>
                        <input type="text" id="otp-input" required placeholder="------" maxlength="6" class="w-full px-5 py-4 bg-slate-50 hover:bg-slate-100/70 border-2 border-slate-200 rounded-2xl text-3xl tracking-[0.7em] text-center font-mono font-black focus:bg-white focus:ring-4 focus:ring-blue-600/10 focus:border-[#003e86] outline-none transition-all text-slate-800">
                        <div class="flex justify-between items-center mt-3 px-2 max-w-[280px] mx-auto">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-blue-600 animate-ping"></span>
                                <span id="forgot-timer" class="text-xs font-mono font-bold text-slate-600">02:30</span>
                            </div>
                            <button type="button" id="btn-resend-forgot-otp" onclick="resendForgotOtp()" class="text-xs font-bold text-[#003e86] hover:text-blue-800 hidden hover:underline">
                                Resend Code
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="btn-2" class="w-full py-4 bg-gradient-to-r from-[#003e86] to-blue-700 hover:from-blue-900 hover:to-indigo-800 text-white rounded-2xl font-black text-base shadow-xl shadow-blue-900/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>Verify Code</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>

                    <div class="mt-5 text-center">
                        <button type="button" onclick="slideTo('step-1')" class="text-xs font-bold text-slate-400 hover:text-slate-700 transition-colors">
                            ← Change email or phone
                        </button>
                    </div>
                </form>

                <!-- Step 3: Reset Password -->
                <form id="step-3" class="w-full hidden animate-in fade-in duration-300" onsubmit="handleResetPassword(event)">
                    <div class="mb-6">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-bold rounded-full mb-2">
                            Step 3 of 3 • Password Update
                        </span>
                        <h2 class="text-xl font-black text-slate-900 tracking-tight mb-1">Create New Password ✨</h2>
                        <p class="text-xs text-slate-500 font-medium">Your new password must be secure and at least 6 characters.</p>
                    </div>

                    <div class="space-y-1.5 mb-6">
                        <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest ml-1 block">New Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                            <input type="password" id="new-password" required placeholder="••••••••" class="w-full pl-11 pr-12 py-3.5 bg-slate-50 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-emerald-600/10 focus:border-emerald-600 outline-none transition-all">
                            <button type="button" onclick="togglePasswordVisibility('new-password', 'reset-eye-icon')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition-colors p-1" title="Toggle password">
                                <svg id="reset-eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="btn-3" class="w-full py-4 bg-gradient-to-r from-emerald-600 to-teal-700 hover:from-emerald-700 hover:to-teal-800 text-white rounded-2xl font-black text-base shadow-xl shadow-emerald-600/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>Save New Password</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                    </button>
                </form>

            </div>
        <?php endif; ?>
    </div>
</div>

<script>
let currentUserId = null;
let currentOtp = null;

function slideTo(stepId) {
    document.getElementById('step-1').classList.add('hidden');
    document.getElementById('step-2').classList.add('hidden');
    document.getElementById('step-3').classList.add('hidden');
    
    document.getElementById(stepId).classList.remove('hidden');
}

let forgotTimerInterval;
function startForgotTimer(duration) {
    clearInterval(forgotTimerInterval);
    const timerEl = document.getElementById('forgot-timer');
    const resendBtn = document.getElementById('btn-resend-forgot-otp');
    let timer = duration;
    
    timerEl.classList.remove('hidden');
    resendBtn.classList.add('hidden');
    
    forgotTimerInterval = setInterval(function () {
        let minutes = parseInt(timer / 60, 10);
        let seconds = parseInt(timer % 60, 10);
        minutes = minutes < 10 ? "0" + minutes : minutes;
        seconds = seconds < 10 ? "0" + seconds : seconds;
        timerEl.textContent = minutes + ":" + seconds;

        if (--timer < 0) {
            clearInterval(forgotTimerInterval);
            timerEl.classList.add('hidden');
            resendBtn.classList.remove('hidden');
        }
    }, 1000);
}

async function handleRequestOTP(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-1'); btn.disabled = true; btn.innerText = "Sending...";
    
    const errorBox = document.getElementById('request-error');
    errorBox.classList.add('hidden');
    errorBox.classList.remove('flex');

    const formData = new FormData();
    formData.append('action', 'request_otp'); formData.append('contact', document.getElementById('contact-input').value);
    
    const deliveryMethodEl = document.querySelector('input[name="delivery_method"]:checked') || document.querySelector('input[name="delivery_method"][type="hidden"]');
    if (deliveryMethodEl) formData.append('delivery_method', deliveryMethodEl.value);

    const res = await fetch('forgot-password.php', { method: 'POST', body: formData });
    const result = await res.json();
    btn.disabled = false; btn.innerHTML = 'Send OTP <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>';
    if (result.success) { 
        currentUserId = result.userId; 
        if (result.target) {
            document.getElementById('otp-target-display').innerText = result.target;
            if (result.method === 'sms') {
                document.getElementById('otp-badge-icon').innerText = '📱';
                document.getElementById('otp-badge-text').innerText = 'SMS Sent to:';
                document.getElementById('otp-subtext').innerText = 'আপনার মোবাইল নম্বরে ৬ ডিজিটের ওটিপি পাঠানো হয়েছে।';
            } else {
                document.getElementById('otp-badge-icon').innerText = '✉️';
                document.getElementById('otp-badge-text').innerText = result.fallback ? 'Email (Fallback):' : 'Email Sent to:';
                document.getElementById('otp-subtext').innerText = result.fallback 
                    ? 'এসএমএস সার্ভিস বন্ধ থাকায় আপনার অ্যাকাউন্টে যুক্ত ইমেইলে ওটিপি কোড পাঠানো হয়েছে।'
                    : 'আপনার নিবন্ধিত ইমেইলে ৬ ডিজিটের ওটিপি পাঠানো হয়েছে।';
            }
        }
        showToast(result.msg, "success"); 
        slideTo('step-2'); 
        startForgotTimer(150);
    } else { 
        errorBox.classList.remove('hidden');
        errorBox.classList.add('flex');
        document.getElementById('request-error-text').innerText = result.error;
    }
}

async function resendForgotOtp() {
    const btn = document.getElementById('btn-resend-forgot-otp');
    btn.innerText = 'Sending...';
    btn.disabled = true;
    const formData = new FormData();
    formData.append('action', 'request_otp'); 
    formData.append('contact', document.getElementById('contact-input').value);
    const deliveryMethodEl = document.querySelector('input[name="delivery_method"]:checked') || document.querySelector('input[name="delivery_method"][type="hidden"]');
    if (deliveryMethodEl) formData.append('delivery_method', deliveryMethodEl.value);
    try {
        const res = await fetch('forgot-password.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (result.success) {
            currentUserId = result.userId; 
            if (result.target) {
                document.getElementById('otp-target-display').innerText = result.target;
            }
            if(window.showToast) showToast("OTP Resent Successfully", "success");
            startForgotTimer(150);
        } else {
            if(window.showToast) showToast(result.error, "error");
        }
    } catch (e) {
        if(window.showToast) showToast("Network error. Please try again.", "error");
    } finally {
        btn.innerText = 'Resend Code';
        btn.disabled = false;
    }
}

async function handleVerifyOTP(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-2'); btn.disabled = true; btn.innerText = "Verifying...";
    const otp = document.getElementById('otp-input').value;
    const formData = new FormData(); formData.append('action', 'verify_otp'); formData.append('userId', currentUserId); formData.append('otp', otp);
    const res = await fetch('forgot-password.php', { method: 'POST', body: formData });
    const result = await res.json();
    btn.disabled = false; btn.innerText = 'Verify Code';
    if (result.success) { currentOtp = otp; showToast("OTP Verified successfully!", "success"); slideTo('step-3'); } else { showToast(result.error, "error"); }
}

async function handleResetPassword(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-3'); btn.disabled = true; btn.innerText = "Updating...";
    const formData = new FormData(); formData.append('action', 'reset_password'); formData.append('userId', currentUserId); formData.append('otp', currentOtp); formData.append('password', document.getElementById('new-password').value);
    const res = await fetch('forgot-password.php', { method: 'POST', body: formData });
    const result = await res.json();
    if (result.success) { showToast("Password Reset Successful! Redirecting...", "success"); setTimeout(() => window.location.href = 'login', 2000); } else { btn.disabled = false; btn.innerText = 'Reset Password'; showToast(result.error, "error"); }
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
</script>

<?php include 'Footer.php'; ?>