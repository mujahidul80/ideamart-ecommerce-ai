<?php
session_start();

// Auto-login via cookie for Admin
if (!isset($_SESSION['admin_logged_in']) && isset($_COOKIE['admin_remember'])) {
    require_once __DIR__ . '/../db.php';
    try {
        $cookieStmt = $pdo->prepare("SELECT id, name FROM `User` WHERE remember_token = ? AND role = 'admin' LIMIT 1");
        $cookieStmt->execute([$_COOKIE['admin_remember']]);
        $cookieAdmin = $cookieStmt->fetch(PDO::FETCH_ASSOC);
        if ($cookieAdmin) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $cookieAdmin['id'];
            $_SESSION['admin_name'] = $cookieAdmin['name'];
            session_write_close();
            header("Location: dashboard");
            exit;
        }
    } catch (Exception $e) {}
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard");
    exit;
}

$storeName = '';
try {
    require_once __DIR__ . '/../db.php';
    $setStmt = $pdo->query("SELECT storeName FROM SettingGeneral LIMIT 1");
    if ($setStmt) {
        $setRow = $setStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($setRow['storeName'])) $storeName = $setRow['storeName'];
    }
} catch (Exception $e) {}

// Auto-create default admin if missing to prevent login issues
try {
    require_once __DIR__ . '/../db.php';
    $checkAdmin = $pdo->query("SELECT id FROM `User` WHERE role = 'admin'");
    if (!$checkAdmin->fetch()) {
        $hashed = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO `User` (name, email, password, role) VALUES ('Super Admin', 'admin@store.com', '$hashed', 'admin')");
    }
} catch (Exception $e) {}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            require_once __DIR__ . '/../db.php';
            
            $stmt = $pdo->prepare("SELECT * FROM `User` WHERE email = :email AND role = 'admin'");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                if (password_verify($password, $user['password'])) {
                    if (isset($_POST['remember_me'])) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('admin_remember', $token, time() + (86400 * 30), '/'); // 30 days
                        try { $pdo->prepare("UPDATE `User` SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]); } catch (Exception $e) {}
                    }

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_name'] = $user['name'];
                    session_write_close();
                    header("Location: dashboard");
                    exit;
                } else {
                    $error = "Incorrect password. Please try again.";
                }
            } else {
                $error = "Email address not found in the system.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal | <?php echo htmlspecialchars($storeName ?: 'Store Admin'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .ambient-mesh {
            background-image: 
                radial-gradient(at 15% 15%, rgba(37, 99, 235, 0.18) 0px, transparent 50%),
                radial-gradient(at 85% 85%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(14, 165, 233, 0.08) 0px, transparent 60%);
        }
    </style>
</head>
<body class="bg-[#070913] text-slate-100 min-h-screen flex items-center justify-center p-4 relative overflow-hidden ambient-mesh">

    <!-- Ambient glowing lights in background -->
    <div class="absolute -top-32 -left-32 w-80 h-80 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-80 h-80 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-full max-w-[440px] relative z-10">
        
        <!-- Header Branding -->
        <div class="text-center mb-7">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-xl shadow-blue-600/30 border border-blue-400/30 mb-3.5 transform transition-transform hover:scale-105 duration-300">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">
                <?php echo htmlspecialchars($storeName ?: 'Admin Console'); ?>
            </h1>
            <div class="flex items-center justify-center gap-2 mt-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest">
                    Security Command Center
                </p>
            </div>
        </div>

        <!-- Glassmorphism Login Card -->
        <div class="bg-slate-900/80 backdrop-blur-2xl rounded-3xl border border-slate-800 shadow-2xl shadow-black/80 overflow-hidden relative">
            <!-- Top luminous border highlight -->
            <div class="h-[2px] w-full bg-gradient-to-r from-transparent via-blue-500 to-transparent"></div>

            <div class="p-7 sm:p-9">
                <div class="mb-6 text-center sm:text-left">
                    <h2 class="text-xl font-black text-white tracking-tight">Executive Sign In</h2>
                    <p class="text-xs text-slate-400 mt-1 font-medium">Authenticate your credentials to access system telemetry.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-5 p-3.5 bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold rounded-2xl flex items-center gap-2.5 animate-in fade-in">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5">
                    <!-- Email Field -->
                    <div class="space-y-1.5">
                        <label for="email" class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1 block">Admin Email</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12.75V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8"/><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            </div>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                required
                                class="w-full pl-11 pr-4 py-3.5 bg-slate-950/60 border border-slate-800 rounded-2xl focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 text-white placeholder:text-slate-600 text-sm font-semibold transition-all"
                                placeholder="admin@store.com"
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="space-y-1.5">
                        <label for="password" class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1 block">Security Key / Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                required
                                class="w-full pl-11 pr-12 py-3.5 bg-slate-950/60 border border-slate-800 rounded-2xl focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 text-white placeholder:text-slate-600 text-sm font-semibold transition-all"
                                placeholder="••••••••••••"
                            >
                            <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-blue-400 transition-colors" title="Toggle Password">
                                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me Toggle -->
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2.5 cursor-pointer group">
                            <input type="checkbox" name="remember_me" class="w-4 h-4 rounded bg-slate-950 border-slate-700 text-blue-600 focus:ring-blue-500/30 focus:ring-offset-slate-900 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-400 group-hover:text-slate-300 transition-colors">Keep Session Active</span>
                        </label>
                    </div>

                    <!-- Submit CTA -->
                    <button 
                        type="submit" 
                        class="w-full bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-600 hover:from-blue-500 hover:to-indigo-500 text-white py-3.5 rounded-2xl font-black text-sm tracking-wide shadow-xl shadow-blue-600/25 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                    >
                        <span>Access Admin Dashboard</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                </form>
            </div>

            <!-- Footer Badge -->
            <div class="p-4 bg-slate-950/70 border-t border-slate-800/80 text-center">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center justify-center gap-2">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-400"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    256-Bit SSL Encrypted Admin Console
                </p>
            </div>
        </div>

        <!-- Return to Storefront -->
        <div class="text-center mt-6">
            <a href="../" class="text-xs font-bold text-slate-400 hover:text-white transition-colors inline-flex items-center gap-2 group">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="transform group-hover:-translate-x-1 transition-transform"><path d="m15 18-6-6 6-6"/></svg>
                Return to Storefront
            </a>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.innerHTML = '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>';
            } else {
                passInput.type = 'password';
                eyeIcon.innerHTML = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
            }
        }
    </script>
</body>
</html>