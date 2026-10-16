<?php
/**
 * Footer Layout
 */
require_once __DIR__ . '/db.php';
try {
    $stmt = $pdo->query("SELECT * FROM SettingGeneral LIMIT 1");
    $storeSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $storeSettings = [];
}

$storeName = $storeSettings['storeName'] ?? "";
$safeDomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $storeName ?: 'store')) . '.com';
$email = !empty($storeSettings['supportEmail']) ? $storeSettings['supportEmail'] : "support@" . $safeDomain;
$phone = !empty($storeSettings['supportPhone']) ? $storeSettings['supportPhone'] : "+880 1234 567890";
$address = !empty($storeSettings['storeAddress']) ? $storeSettings['storeAddress'] : "";
$copyright = $storeName;
$footerDesc = !empty($storeSettings['footerDescription']) ? $storeSettings['footerDescription'] : "Your premium destination for high-quality products. We are dedicated to providing the best shopping experience for our customers.";
$text1 = !empty($storeSettings['footerCopyright']) ? $storeSettings['footerCopyright'] : "Locally Crafted in Bangladesh.";
$currentYear = date("Y");
$logoUrl = !empty($storeSettings['logoUrl']) ? ltrim($storeSettings['logoUrl'], '/') : null;

function makeAbsoluteUrl($url) {
    if (empty($url)) return '';
    return preg_match("~^(?:f|ht)tps?://~i", $url) ? $url : "https://" . $url;
}

$currentPage = basename($_SERVER['PHP_SELF']); 
$currentPageName = str_replace('.php', '', $currentPage);
$isAccountPage = in_array($currentPageName, ['account', 'login', 'forgot-password']);
$hideBottomNav = in_array($currentPageName, ['checkout', 'invoice']);
$hasFixedBottomBar = in_array($currentPageName, ['product', 'cart']);
$floatingMobileBottom = $hasFixedBottomBar ? 'bottom-[160px]' : ($hideBottomNav ? 'bottom-6' : 'bottom-24');
?>

<footer class="hidden md:block bg-white border-t border-[#c2c6d4] pt-16 pb-8 mt-12 font-sans">
    <div class="max-w-[1200px] mx-auto px-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-8 lg:gap-10 mb-12">
            
            <!-- Brand Info -->
            <div class="sm:col-span-2 lg:col-span-4 space-y-5">
                <?php if ($logoUrl): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($storeName); ?>" class="h-10 sm:h-12 w-auto object-contain" />
                <?php else: ?>
                    <h3 class="text-2xl font-black text-[#003e86] tracking-tight uppercase">
                        <?php echo htmlspecialchars($storeName); ?>
                    </h3>
                <?php endif; ?>
                <p class="text-sm text-slate-500 leading-relaxed lg:max-w-sm">
                    <?php echo htmlspecialchars($footerDesc); ?>
                </p>
                
                <!-- Dynamic Social Links -->
                <div class="flex items-center gap-3 pt-2">
                    <?php if (!empty($storeSettings['facebookUrl'])): ?>
                    <a href="<?php echo htmlspecialchars(makeAbsoluteUrl($storeSettings['facebookUrl'])); ?>" target="_blank" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:bg-[#003e86] hover:text-white transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($storeSettings['instagramUrl'])): ?>
                    <a href="<?php echo htmlspecialchars(makeAbsoluteUrl($storeSettings['instagramUrl'])); ?>" target="_blank" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:bg-pink-600 hover:text-white transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($storeSettings['twitterUrl'])): ?>
                    <a href="<?php echo htmlspecialchars(makeAbsoluteUrl($storeSettings['twitterUrl'])); ?>" target="_blank" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($storeSettings['youtubeUrl'])): ?>
                    <a href="<?php echo htmlspecialchars(makeAbsoluteUrl($storeSettings['youtubeUrl'])); ?>" target="_blank" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:bg-red-600 hover:text-white transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="lg:col-span-3">
                <h4 class="text-base font-bold text-[#191c1d] mb-5 uppercase tracking-wider">Contact Us</h4>
                <ul class="space-y-4">
                    <?php if (!empty($address)): ?>
                    <li class="flex items-start gap-3 text-sm font-medium text-slate-600">
                        <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-[#003e86] shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        <span class="mt-1 leading-relaxed"><?php echo nl2br(htmlspecialchars($address)); ?></span>
                    </li>
                    <?php endif; ?>
                    <li class="flex items-center gap-3 text-sm font-medium text-slate-600">
                        <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-[#003e86] shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        </div>
                        <a href="tel:<?php echo htmlspecialchars($phone); ?>" class="hover:text-[#003e86] transition-colors"><?php echo htmlspecialchars($phone); ?></a>
                    </li>
                    <li class="flex items-center gap-3 text-sm font-medium text-slate-600">
                        <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-[#003e86] shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        </div>
                        <a href="mailto:<?php echo urlencode($email); ?>" class="hover:text-[#003e86] transition-colors"><?php echo htmlspecialchars($email); ?></a>
                    </li>
                </ul>
            </div>

            <!-- Quick Links -->
            <div class="lg:col-span-2">
                <h4 class="text-base font-bold text-[#191c1d] mb-5 uppercase tracking-wider">Quick Links</h4>
                <ul class="space-y-3">
                    <li><a href="shop" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">All Products</a></li>
                    <li><a href="flash-deals" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">Flash Deals</a></li>
                    <li><a href="page?slug=about-us" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">About Us</a></li>
                    <li><a href="page?slug=contact-support" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">Contact Support</a></li>
                </ul>
            </div>

            <!-- Policies -->
            <div class="lg:col-span-3">
                <h4 class="text-base font-bold text-[#191c1d] mb-5 uppercase tracking-wider">Legal & Policies</h4>
                <ul class="space-y-3">
                    <li><a href="page?slug=privacy-policy" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">Privacy Policy</a></li>
                    <li><a href="page?slug=terms-of-service" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">Terms of Service</a></li>
                    <li><a href="page?slug=return-policy" class="text-sm text-slate-500 hover:text-[#003e86] hover:translate-x-1 transition-all inline-block font-medium">Return & Refund Policy</a></li>
                </ul>
            </div>
        </div>

        <!-- Bottom Footer Section -->
        <div class="pt-8 border-t border-slate-200 flex items-center justify-center text-center">
            <p class="text-sm font-medium text-slate-600">
                &copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($copyright); ?>. <?php echo htmlspecialchars($text1); ?>
            </p>
        </div>
    </div>
</footer>

<!-- Global Mobile Spacer to prevent content overlapping with fixed bottom nav -->
<?php if (!$hideBottomNav): ?>
    <?php if ($hasFixedBottomBar): ?>
        <div class="block md:hidden h-[180px] w-full shrink-0"></div>
    <?php else: ?>
        <div class="block md:hidden h-[90px] w-full shrink-0"></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($storeSettings['whatsappNumber'])): 
    $waNum = preg_replace('/[^0-9]/', '', $storeSettings['whatsappNumber']);
?>
<!-- WhatsApp Floating Chat Button -->
<a href="https://wa.me/<?php echo $waNum; ?>" target="_blank" class="fixed <?php echo $floatingMobileBottom; ?> md:bottom-6 left-6 z-[90] bg-[#25D366] text-white p-3.5 sm:p-4 rounded-full shadow-2xl hover:scale-110 transition-transform duration-300 flex items-center justify-center group" title="Chat with us on WhatsApp">
    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
    <!-- Tooltip -->
    <span class="absolute left-full ml-4 bg-white text-slate-800 text-xs font-bold px-3 py-1.5 rounded-xl shadow-lg opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap before:content-[''] before:absolute before:right-full before:top-1/2 before:-translate-y-1/2 before:border-4 before:border-transparent before:border-r-white hidden sm:block">
        Chat with us
    </span>
</a>
<?php endif; ?>

<?php if (!isset($storeSettings['aiChatbotEnabled']) || $storeSettings['aiChatbotEnabled']): ?>
<!-- AI Shopping Assistant Widget -->
<div id="ai-chat-widget" class="fixed <?php echo $floatingMobileBottom; ?> md:bottom-6 right-6 z-[95] flex flex-col items-end">
    <div id="ai-chat-window" class="hidden w-[320px] bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden mb-4 transition-all duration-300 transform origin-bottom-right scale-95 opacity-0">
        <div class="bg-gradient-to-r from-indigo-600 to-blue-600 p-3.5 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                </div>
                <div>
                    <h3 class="font-bold text-sm leading-tight">AI Assistant</h3>
                    <p class="text-[10px] text-blue-100">Online & ready to help</p>
                </div>
            </div>
            <div class="flex items-center gap-1.5">
                <button type="button" onclick="startNewAiChat()" title="Start New Chat" class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors flex items-center gap-1 text-[11px] font-semibold border border-white/20 shadow-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    <span>New</span>
                </button>
                <button onclick="toggleAiChat()" class="p-1.5 text-white/80 hover:text-white rounded-lg hover:bg-white/10 transition-colors"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
            </div>
        </div>
        <div id="ai-chat-messages" class="h-[300px] overflow-y-auto p-4 bg-slate-50 space-y-3 custom-scrollbar text-sm flex flex-col">
            <div class="flex gap-2.5 w-5/6 items-start"><div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 mt-0.5 shadow-xs"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/></svg></div><div class="bg-white px-3.5 py-2.5 rounded-2xl rounded-tl-none border border-slate-200/90 text-slate-700 shadow-xs text-xs sm:text-sm leading-relaxed">আসসালামু আলাইকুম! কীভাবে সাহায্য করতে পারি? 👋</div></div>
        </div>
        <!-- Quick Action Chips -->
        <div id="ai-quick-chips" class="px-3 py-1.5 bg-slate-50 border-t border-slate-200/60 flex items-center gap-1.5 overflow-x-auto no-scrollbar whitespace-nowrap text-[11px]">
            <button type="button" onclick="sendAiQuickMessage('ডেলিভারি চার্জ কত?')" class="px-2.5 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 border border-slate-200 rounded-full font-medium text-slate-600 transition-colors shadow-2xs cursor-pointer">🚚 ডেলিভারি চার্জ</button>
            <button type="button" onclick="sendAiQuickMessage('অর্ডার ট্র্যাক করব কিভাবে?')" class="px-2.5 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 border border-slate-200 rounded-full font-medium text-slate-600 transition-colors shadow-2xs cursor-pointer">🔍 অর্ডার ট্র্যাক</button>
            <button type="button" onclick="sendAiQuickMessage('আমাদের সব পণ্য দেখান')" class="px-2.5 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 border border-slate-200 rounded-full font-medium text-slate-600 transition-colors shadow-2xs cursor-pointer">🛍️ সকল পণ্য</button>
            <button type="button" onclick="sendAiQuickMessage('কাস্টমার কেয়ারের সাথে কথা বলব কিভাবে?')" class="px-2.5 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 border border-slate-200 rounded-full font-medium text-slate-600 transition-colors shadow-2xs cursor-pointer">📞 সাপোর্ট</button>
        </div>
        <form id="ai-chat-form" onsubmit="sendAiMessage(event)" class="p-3 bg-white border-t border-slate-100 flex gap-2">
            <input type="text" id="ai-chat-input" placeholder="Type a message..." required class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-indigo-500">
            <button type="submit" id="ai-chat-submit" class="bg-indigo-600 hover:bg-indigo-700 text-white w-10 h-10 rounded-xl flex items-center justify-center shrink-0 shadow-sm transition-colors"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="translate-x-[-1px]"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg></button>
        </form>
    </div>
    <button onclick="toggleAiChat()" id="ai-chat-btn" class="bg-indigo-600 text-white w-14 h-14 rounded-full shadow-2xl hover:scale-110 transition-transform duration-300 flex items-center justify-center"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/></svg></button>
</div>
<?php endif; ?>

<?php if (!$hideBottomNav): ?>
<!-- Mobile Bottom Navigation App Bar -->
<div class="md:hidden fixed bottom-0 left-0 w-full bg-white border-t border-slate-200 flex items-center justify-between z-[60] px-2 pb-[env(safe-area-inset-bottom)] h-[calc(64px+env(safe-area-inset-bottom))] shadow-[0_-5px_15px_rgba(0,0,0,0.05)]">
    <!-- 1. Home -->
    <a href="./" class="flex flex-col items-center justify-center w-[20%] h-full relative group">
        <div class="flex items-center justify-center transition-transform duration-300 <?php echo $currentPageName == 'index' ? 'text-[#003e86] -translate-y-1' : 'text-slate-400'; ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="<?php echo $currentPageName == 'index' ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <span class="text-[9px] font-bold mt-1 transition-colors <?php echo $currentPageName == 'index' ? 'text-[#003e86]' : 'text-slate-400'; ?>">Home</span>
    </a>
    
    <!-- 2. Menu -->
    <button onclick="toggleMobileMenu()" class="flex flex-col items-center justify-center w-[20%] h-full relative group">
        <div class="flex items-center justify-center transition-transform duration-300 text-slate-400">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
        </div>
        <span class="text-[9px] font-bold mt-1 transition-colors text-slate-400">Menu</span>
    </button>

    <!-- 3. Shop (Center Prominent) -->
    <div class="flex justify-center w-[20%] h-full relative">
        <a href="shop" class="absolute -top-5 flex flex-col items-center justify-center w-14 h-14 bg-[#003e86] text-white rounded-full shadow-[0_8px_20px_rgba(0,62,134,0.4)] border-4 border-white transition-transform duration-300 hover:scale-105 active:scale-95 <?php echo $currentPageName == 'shop' ? 'ring-2 ring-[#003e86]/20 ring-offset-2' : ''; ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="<?php echo $currentPageName == 'shop' ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
        </a>
        <span class="absolute bottom-1.5 text-[9px] font-bold pointer-events-none <?php echo $currentPageName == 'shop' ? 'text-[#003e86]' : 'text-slate-400'; ?>">Shop</span>
    </div>

    <!-- 4. Cart -->
    <a href="cart" class="flex flex-col items-center justify-center w-[20%] h-full relative group">
        <div class="flex items-center justify-center relative transition-transform duration-300 <?php echo $currentPageName == 'cart' ? 'text-[#003e86] -translate-y-1' : 'text-slate-400'; ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="<?php echo $currentPageName == 'cart' ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
            <span id="mobile-bottom-cart-count" class="absolute -top-1.5 -right-2 bg-rose-500 text-white text-[9px] font-black min-w-[16px] h-4 flex items-center justify-center rounded-full border-2 border-white shadow-sm px-1 hidden">0</span>
        </div>
        <span class="text-[9px] font-bold mt-1 transition-colors <?php echo $currentPageName == 'cart' ? 'text-[#003e86]' : 'text-slate-400'; ?>">Cart</span>
    </a>

    <!-- 5. Account -->
    <a href="<?php echo (function_exists('isUserLoggedIn') && isUserLoggedIn()) ? 'account' : 'login'; ?>" class="flex flex-col items-center justify-center w-[20%] h-full relative group">
        <div class="flex items-center justify-center transition-transform duration-300 <?php echo $isAccountPage ? 'text-[#003e86] -translate-y-1' : 'text-slate-400'; ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="<?php echo $isAccountPage ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <span class="text-[9px] font-bold mt-1 transition-colors <?php echo $isAccountPage ? 'text-[#003e86]' : 'text-slate-400'; ?>">Account</span>
    </a>
</div>
<?php endif; ?>

<script>
(function() {
    const currentChatUser = <?php echo (function_exists('isUserLoggedIn') && isUserLoggedIn()) ? (int)($_SESSION['user_id'] ?? 0) : 0; ?>;
    const chatHistoryKey = currentChatUser > 0 ? ('ai_chat_history_u_' + currentChatUser) : 'ai_chat_history_guest';
    const chatHtmlKey = currentChatUser > 0 ? ('ai_chat_html_u_' + currentChatUser) : 'ai_chat_html_guest';
    const defaultWelcomeHtml = `<div class="flex gap-2 w-5/6"><div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/></svg></div><div class="bg-white p-3 rounded-2xl rounded-tl-none border border-slate-200 text-slate-700 shadow-sm">আসসালামু আলাইকুম! কীভাবে সাহায্য করতে পারি? 👋</div></div>`;

    window.aiChatHistory = [];

    window.sendAiQuickMessage = function(text) {
        const input = document.getElementById('ai-chat-input');
        if (input) {
            input.value = text;
            const form = document.getElementById('ai-chat-form');
            if (form) {
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    };

    const escapeHtml = (unsafe) => {
        if (!unsafe) return '';
        return unsafe.toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    };

    function formatMessageText(rawText) {
        if (!rawText) return '';

        // 1. Clean headers and excessive asterisks/clutter
        let text = rawText
            .replace(/^#{1,6}\s+/gm, '') // Remove markdown headers (###)
            .replace(/\n{3,}/g, '\n\n') // Collapse excessive newlines
            .trim();

        const linkRegex = /(?:!)?\[([^\]]*)\]\((https?:\/\/[^\)]+|[a-zA-Z0-9_\-\/]+)\)|(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
        let finalHtml = '';
        let lastIndex = 0;

        text.replace(linkRegex, (match, mdText, mdUrl, plainUrl, offset) => {
            let chunk = escapeHtml(text.substring(lastIndex, offset));
            // Format bold text inside chunk
            chunk = chunk.replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900">$1</strong>');
            finalHtml += chunk;

            let rawUrl = (mdUrl || plainUrl || '').trim();
            let url = rawUrl;

            // 1. Remove imaginary domain names (ideamart.com.bd, ideamart.com, ideamartbd.com, etc.)
            url = url.replace(/^https?:\/\/(?:www\.)?ideamart(?:bd)?\.(?:com\.bd|com|bd|net)\/?/i, '');

            // 2. Remove localhost domain or localhost/ecommerce prefix if generated
            url = url.replace(/^https?:\/\/localhost(?::\d+)?(?:\/ecommerce)?\/?/i, '');

            // 3. Strip leading slash so relative routes resolve within the current app directory
            if (url.startsWith('/') && !url.startsWith('//')) {
                url = url.substring(1);
            }

            // 4. Default empty or hash to shop
            if (!url || url === '#' || url === '.') {
                url = 'shop';
            }

            const isExternal = /^https?:\/\//i.test(url);
            const targetAttr = isExternal ? 'target="_blank" rel="noopener noreferrer"' : 'target="_self"';
            const linkTitle = mdText ? escapeHtml(mdText) : (isExternal ? 'লিংক ওপেন করুন' : 'এখানে দেখুন');

            finalHtml += `<span class="block my-1.5"><a href="${encodeURI(url)}" ${targetAttr} class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold rounded-xl text-xs transition-colors border border-indigo-200 no-underline shadow-xs w-fit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg> ${linkTitle}</a></span>`;
            lastIndex = offset + match.length;
        });

        let remainingChunk = escapeHtml(text.substring(lastIndex));
        remainingChunk = remainingChunk.replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900">$1</strong>');
        finalHtml += remainingChunk;

        // Clean list bullet lines
        finalHtml = finalHtml.replace(/^[*\-•]\s+(.*)$/gm, '<div class="flex items-start gap-1.5 my-0.5"><span class="text-indigo-500 font-bold leading-tight">•</span><span>$1</span></div>');

        // Convert standard newlines to <br> where not already handled
        return finalHtml.replace(/\n/g, "<br>");
    }

    function appendMessageDom(role, content, products) {
        const messagesContainer = document.getElementById('ai-chat-messages');
        if (!messagesContainer) return;

        if (role === 'user') {
            const userMsgHtml = `<div class="flex gap-2 w-5/6 self-end justify-end ml-auto"><div class="bg-indigo-600 text-white px-3.5 py-2.5 rounded-2xl rounded-tr-none shadow-xs text-xs sm:text-sm leading-relaxed">${escapeHtml(content)}</div></div>`;
            messagesContainer.insertAdjacentHTML('beforeend', userMsgHtml);
        } else {
            const formatted = formatMessageText(content);
            const aiMsgHtml = `
                <div class="flex gap-2.5 w-5/6 items-start">
                    <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 mt-0.5 shadow-xs">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/></svg>
                    </div>
                    <div class="bg-white px-3.5 py-2.5 rounded-2xl rounded-tl-none border border-slate-200/90 text-slate-700 shadow-xs text-xs sm:text-sm leading-relaxed">
                        ${formatted}
                    </div>
                </div>
            `;
            messagesContainer.insertAdjacentHTML('beforeend', aiMsgHtml);

            if (products && products.length > 0) {
                let carouselHtml = `
                    <div class="ml-10 mt-1 mb-2">
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                            <span>🛍️ সম্পর্কিত পণ্যসমূহ</span>
                            <span class="text-[9px] text-indigo-500 lowercase font-medium">(ডানে স্ক্রোল করুন 👉)</span>
                        </p>
                        <div class="flex gap-2.5 overflow-x-auto pb-2.5 pt-0.5 custom-scrollbar snap-x snap-mandatory scroll-smooth pr-2">
                `;
                products.forEach(p => {
                    const img = p.imageUrl ? p.imageUrl : 'assets/placeholder.png';
                    carouselHtml += `
                        <div class="w-[145px] shrink-0 bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md transition-all snap-start flex flex-col group">
                            <a href="${p.url}" target="_self" class="block aspect-square w-full bg-slate-50 relative overflow-hidden">
                                <img src="${img}" alt="${escapeHtml(p.name)}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                <span class="absolute top-1.5 right-1.5 bg-emerald-600 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full shadow-sm">ইন স্টক</span>
                            </a>
                            <div class="p-2.5 flex-1 flex flex-col justify-between">
                                <div>
                                    <a href="${p.url}" target="_self" class="font-bold text-xs text-slate-800 line-clamp-2 hover:text-indigo-600 transition-colors leading-snug">${escapeHtml(p.name)}</a>
                                    <p class="font-black text-xs text-indigo-600 mt-1">৳ ${p.price}</p>
                                </div>
                                <a href="${p.url}" target="_self" class="mt-2.5 w-full py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-[11px] rounded-xl text-center transition-all flex items-center justify-center gap-1 shadow-sm">
                                    <span>অর্ডার করুন</span>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                </a>
                            </div>
                        </div>
                    `;
                });
                carouselHtml += `</div></div>`;
                messagesContainer.insertAdjacentHTML('beforeend', carouselHtml);
            }
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function saveChatStorage() {
        try {
            localStorage.setItem(chatHistoryKey, JSON.stringify(window.aiChatHistory || []));
            const messagesContainer = document.getElementById('ai-chat-messages');
            if (messagesContainer) {
                localStorage.setItem(chatHtmlKey, messagesContainer.innerHTML);
            }
        } catch(e) {}
    }

    // 1. Initial Quick Render from Local Cache
    try {
        const cachedHtml = localStorage.getItem(chatHtmlKey);
        const cachedHistory = localStorage.getItem(chatHistoryKey);
        const messagesContainer = document.getElementById('ai-chat-messages');
        if (cachedHtml && messagesContainer) {
            messagesContainer.innerHTML = cachedHtml;
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        if (cachedHistory) {
            window.aiChatHistory = JSON.parse(cachedHistory) || [];
        }
    } catch(e) {}

    // 2. Sync / Restore History from Database (Persistent for logged in user and guest session)
    async function syncDatabaseHistory() {
        try {
            const res = await fetch('api/ai_chat.php?action=history');
            const data = await res.json();
            if (data.success && Array.isArray(data.messages) && data.messages.length > 0) {
                const messagesContainer = document.getElementById('ai-chat-messages');
                if (messagesContainer) {
                    messagesContainer.innerHTML = '';
                    window.aiChatHistory = [];
                    data.messages.forEach(msg => {
                        window.aiChatHistory.push({ role: msg.role, content: msg.content });
                        appendMessageDom(msg.role, msg.content, msg.products || []);
                    });
                    saveChatStorage();
                }
            } else if (data.success && (!data.messages || data.messages.length === 0)) {
                // If database has no active messages (e.g. after New Chat), ensure fresh state
                if (!window.aiChatHistory || window.aiChatHistory.length === 0) {
                    const messagesContainer = document.getElementById('ai-chat-messages');
                    if (messagesContainer) {
                        messagesContainer.innerHTML = defaultWelcomeHtml;
                    }
                }
            }
        } catch(e) {}
    }
    syncDatabaseHistory();

    // AI Chatbot Window Toggle
    window.toggleAiChat = function(forceOpen) {
        const chatWindow = document.getElementById('ai-chat-window');
        if (!chatWindow) return;
        const isHidden = chatWindow.classList.contains('hidden');
        if (forceOpen === true || (forceOpen === undefined && isHidden)) {
            chatWindow.classList.remove('hidden');
            setTimeout(() => {
                chatWindow.classList.remove('scale-95', 'opacity-0');
            }, 10);
            const input = document.getElementById('ai-chat-input');
            if (input) input.focus();
            const container = document.getElementById('ai-chat-messages');
            if (container) container.scrollTop = container.scrollHeight;
            try { localStorage.setItem('ai_chat_open_state', '1'); } catch(e) {}
        } else {
            chatWindow.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                chatWindow.classList.add('hidden');
            }, 300);
            try { localStorage.setItem('ai_chat_open_state', '0'); } catch(e) {}
        }
    };

    // Restore Open State
    try {
        if (localStorage.getItem('ai_chat_open_state') === '1') {
            window.toggleAiChat(true);
        }
    } catch(e) {}

    // Start New Chat (Clear current session in Database & Storage)
    window.startNewAiChat = async function() {
        if (confirm('নতুন চ্যাট শুরু করতে চান? পূর্বের আলোচনা ক্লিয়ার হয়ে যাবে।')) {
            window.aiChatHistory = [];
            try {
                localStorage.removeItem(chatHistoryKey);
                localStorage.removeItem(chatHtmlKey);
            } catch(e) {}
            const messagesContainer = document.getElementById('ai-chat-messages');
            if (messagesContainer) {
                messagesContainer.innerHTML = defaultWelcomeHtml;
            }
            try {
                await fetch('api/ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear' })
                });
            } catch(e) {}
            const input = document.getElementById('ai-chat-input');
            if (input) input.focus();
        }
    };

    window.sendAiMessage = async function(event) {
        event.preventDefault();
        const input = document.getElementById('ai-chat-input');
        const submitBtn = document.getElementById('ai-chat-submit');
        const messagesContainer = document.getElementById('ai-chat-messages');
        
        const message = input.value.trim();
        if (!message) return;
        
        // Add user message to chat UI & history
        appendMessageDom('user', message);
        window.aiChatHistory.push({ role: 'user', content: message });
        saveChatStorage();
        
        input.value = '';
        input.disabled = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<svg class="animate-spin w-5 h-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>';
        
        try {
            const res = await fetch('api/ai_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: message, history: window.aiChatHistory })
            });
            const data = await res.json();
            const replyText = data.reply ? data.reply : "দুঃখিত, সংযোগে সাময়িক সমস্যা হচ্ছে। দয়া করে কিছুক্ষণ পর আবার চেষ্টা করুন।";

            // Add model reply to UI & history
            window.aiChatHistory.push({ role: 'model', content: replyText });
            appendMessageDom('model', replyText, data.products || []);
            saveChatStorage();
        } catch (e) {
            appendMessageDom('model', 'নেটওয়ার্কে সমস্যা হচ্ছে। অনুগ্রহ করে আপনার ইন্টারনেট সংযোগ চেক করে আবার চেষ্টা করুন।');
            saveChatStorage();
        } finally {
            input.disabled = false;
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="translate-x-[-1px]"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>';
            input.focus();
        }
    };
})();
</script>
</body>
</html>