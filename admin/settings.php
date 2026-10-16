<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}

require_once __DIR__ . '/../db.php';

try { $pdo->exec("ALTER TABLE `SettingGeneral` ADD COLUMN `advancePaymentNotice` TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `SettingGeneral` ADD COLUMN `autoGooglePing` BOOLEAN DEFAULT FALSE"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `SettingGeneral` DROP COLUMN `footerBottomText`"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_advance_notice') {
    header('Content-Type: application/json');
    try {
        $pdo->prepare("UPDATE SettingGeneral SET advancePaymentNotice = ? WHERE id = 1")->execute([$_POST['advancePaymentNotice'] ?? '']);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$stmt = $pdo->query("SELECT * FROM SettingGeneral LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $settings = ['storeName' => '', 'logoUrl' => '', 'faviconUrl' => '', 'metaDescription' => '', 'currency' => 'BDT', 'currencySymbol' => '৳', 'guestCheckoutEnabled' => 1];
} else {
    $settings['metaDescription'] = $settings['metaDescription'] ?? '';
    $settings['guestCheckoutEnabled'] = isset($settings['guestCheckoutEnabled']) ? $settings['guestCheckoutEnabled'] : 1;
}
$safeDomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', !empty($settings['storeName']) ? $settings['storeName'] : 'store')) . '.com';
ob_start();
?>

<div class="max-w-6xl mx-auto pb-12 font-sans" id="settings-app">
    <form id="settings-form" enctype="multipart/form-data" class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="px-6 sm:px-8 py-6 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="settings" class="w-6 h-6 text-blue-600"></i> Global Settings
                </h2>
                <p class="text-sm text-slate-500 mt-1">Configure your store's branding, SEO, and regional preferences.</p>
            </div>
            <button 
                type="button" 
                onclick="saveAllSettings()"
                id="save-btn"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transition-all flex items-center justify-center gap-2"
            >
                <i data-lucide="save" class="w-4 h-4"></i> 
                <span>Save Changes</span>
            </button>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="flex border-b border-slate-200 px-6 sm:px-8 pt-2 gap-6 bg-slate-50/50 overflow-x-auto custom-scrollbar">
            <button type="button" onclick="switchSettingsTab('general')" id="tab-btn-general" class="settings-tab-btn pb-3 font-semibold text-sm transition-colors relative flex items-center gap-2 text-blue-600 whitespace-nowrap">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> General
                <div class="settings-tab-indicator absolute bottom-0 left-0 w-full h-0.5 bg-blue-600 rounded-t-full"></div>
            </button>
            <button type="button" onclick="switchSettingsTab('tracking')" id="tab-btn-tracking" class="settings-tab-btn pb-3 font-semibold text-sm transition-colors relative flex items-center gap-2 text-slate-500 whitespace-nowrap">
                <i data-lucide="bar-chart-2" class="w-4 h-4"></i> SEO & Tracking
                <div class="settings-tab-indicator absolute bottom-0 left-0 w-full h-0.5 bg-blue-600 rounded-t-full hidden"></div>
            </button>
            <button type="button" onclick="switchSettingsTab('preferences')" id="tab-btn-preferences" class="settings-tab-btn pb-3 font-semibold text-sm transition-colors relative flex items-center gap-2 text-slate-500 whitespace-nowrap">
                <i data-lucide="sliders" class="w-4 h-4"></i> Preferences
                <div class="settings-tab-indicator absolute bottom-0 left-0 w-full h-0.5 bg-blue-600 rounded-t-full hidden"></div>
            </button>
        </div>

        <!-- Tab Content: General -->
        <div id="tab-content-general" class="settings-tab-content block p-6 sm:p-8 space-y-8 animate-in fade-in">
            
            <!-- Store Details Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="store" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Store Details</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Store Legal Name</label>
                        <input name="storeName" value="<?php echo htmlspecialchars($settings['storeName']); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="e.g. My Store">
                        <p class="text-xs text-slate-500 mt-1.5">This name will be displayed on invoices and emails.</p>
                    </div>
                </div>
            </div>

            <!-- Brand Assets Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="image" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Brand Assets</h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Logo Upload -->
                    <div class="space-y-3">
                        <label class="block text-sm font-semibold text-slate-700">Primary Logo</label>
                        <div onclick="document.getElementById('logoInput').click()" class="border-2 border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center hover:bg-slate-50 hover:border-indigo-300 transition-all cursor-pointer relative overflow-hidden h-40 bg-white group">
                            <img id="logo-preview" src="<?php echo $settings['logoUrl'] ? '..' . $settings['logoUrl'] : ''; ?>" class="max-h-full max-w-full object-contain <?php echo empty($settings['logoUrl']) ? 'hidden' : ''; ?>">
                            <div class="flex flex-col items-center <?php echo !empty($settings['logoUrl']) ? 'hidden' : ''; ?> group-hover:opacity-80" id="logo-placeholder">
                                <i data-lucide="upload-cloud" class="w-8 h-8 text-slate-400 mb-2"></i>
                                <span class="text-xs font-semibold text-slate-600">Upload Logo</span>
                            </div>
                            <input id="logoInput" name="logoFile" type="file" class="hidden" onchange="previewImage(this, 'logo-preview', 'logo-placeholder')">
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2">Recommended size: 250x80px (PNG, SVG or WEBP)</p>
                    </div>
                    <!-- Favicon Upload -->
                    <div class="space-y-3">
                        <label class="block text-sm font-semibold text-slate-700">Favicon</label>
                        <div onclick="document.getElementById('faviconInput').click()" class="border-2 border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center hover:bg-slate-50 hover:border-indigo-300 transition-all cursor-pointer relative overflow-hidden h-40 bg-white group">
                            <img id="favicon-preview" src="<?php echo $settings['faviconUrl'] ? '..' . $settings['faviconUrl'] : ''; ?>" class="h-16 w-16 object-contain <?php echo empty($settings['faviconUrl']) ? 'hidden' : ''; ?>">
                            <div class="flex flex-col items-center <?php echo !empty($settings['faviconUrl']) ? 'hidden' : ''; ?> group-hover:opacity-80" id="favicon-placeholder">
                                <i data-lucide="upload-cloud" class="w-8 h-8 text-slate-400 mb-2"></i>
                                <span class="text-xs font-semibold text-slate-600">Upload Favicon</span>
                            </div>
                            <input id="faviconInput" name="faviconFile" type="file" class="hidden" onchange="previewImage(this, 'favicon-preview', 'favicon-placeholder')">
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2">Recommended size: 32x32px (PNG or ICO)</p>
                    </div>
                </div>
            </div>

            <!-- Contact Information Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="phone-call" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Contact Information</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Support Email</label>
                        <input name="supportEmail" value="<?php echo htmlspecialchars($settings['supportEmail'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="support@<?php echo $safeDomain; ?>">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Support Phone</label>
                        <input name="supportPhone" value="<?php echo htmlspecialchars($settings['supportPhone'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="+880 1234 567890">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Store Address</label>
                        <textarea name="storeAddress" rows="3" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all resize-none" placeholder="123 E-commerce Street, Dhaka, Bangladesh"><?php echo htmlspecialchars($settings['storeAddress'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Footer Settings Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="layout-panel-bottom" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Footer Settings</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Footer Description</label>
                        <textarea name="footerDescription" rows="3" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all resize-none" placeholder="Your premium destination for high-quality products..."><?php echo htmlspecialchars($settings['footerDescription'] ?? ''); ?></textarea>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Copyright Text</label>
                        <input name="footerCopyright" value="<?php echo htmlspecialchars($settings['footerCopyright'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="Locally Crafted in Bangladesh.">
                    </div>
                </div>
            </div>
        </div> <!-- End General Tab -->

        <!-- Tab Content: SEO & Tracking -->
        <div id="tab-content-tracking" class="settings-tab-content hidden p-6 sm:p-8 space-y-8 animate-in fade-in">
            <!-- SEO Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="search" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">SEO & Discoverability (Meta Data)</h3>
                </div>
                <div class="p-6 space-y-6">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Meta Title</label>
                        <input name="metaTitle" value="<?php echo htmlspecialchars($settings['metaTitle'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="e.g. Best Online Store in Bangladesh">
                        <p class="text-xs text-slate-500 mt-1.5">Used as the page title for search engines and social sharing.</p>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Meta Description</label>
                        <textarea name="metaDescription" rows="4" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all resize-none" placeholder="Briefly describe your store for search engines..."><?php echo htmlspecialchars($settings['metaDescription'] ?? ''); ?></textarea>
                        <div class="flex justify-between items-center text-xs text-slate-500 mt-1">
                            <span>Used for Google search results.</span>
                            <span>Optimal length: 150-160 characters.</span>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <label class="block text-sm font-semibold text-slate-700">Social Share Image (OG Image)</label>
                        <div onclick="document.getElementById('metaImageInput').click()" class="border-2 border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center hover:bg-slate-50 hover:border-indigo-300 transition-all cursor-pointer relative overflow-hidden h-48 bg-white group">
                            <img id="meta-preview" src="<?php echo !empty($settings['metaImage']) ? '..' . $settings['metaImage'] : ''; ?>" class="max-h-full max-w-full object-contain <?php echo empty($settings['metaImage']) ? 'hidden' : ''; ?>">
                            <div class="flex flex-col items-center <?php echo !empty($settings['metaImage']) ? 'hidden' : ''; ?> group-hover:opacity-80" id="meta-placeholder">
                                <i data-lucide="image" class="w-8 h-8 text-slate-400 mb-2"></i>
                                <span class="text-xs font-semibold text-slate-600">Upload Meta Image</span>
                            </div>
                            <input id="metaImageInput" name="metaImageFile" type="file" class="hidden" accept="image/*" onchange="previewImage(this, 'meta-preview', 'meta-placeholder')">
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2">This image will appear when you share your website link on Facebook, WhatsApp, etc. Recommended size: 1200x630px.</p>
                    </div>
                </div>
            </div>

            <!-- Analytics & Pixels Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="pie-chart" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Analytics & Tracking</h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Facebook Pixel ID</label>
                        <input name="fbPixelId" value="<?php echo htmlspecialchars($settings['fbPixelId'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="e.g. 123456789012345">
                        <p class="text-[11px] text-slate-500 mt-1.5">Enter your Meta Pixel ID to track page views and events.</p>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Google Analytics ID</label>
                        <input name="googleAnalyticsId" value="<?php echo htmlspecialchars($settings['googleAnalyticsId'] ?? ''); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all" placeholder="e.g. G-XXXXXXXXXX">
                        <p class="text-[11px] text-slate-500 mt-1.5">Enter your GA4 Measurement ID.</p>
                    </div>
                </div>
            </div>

            <!-- Sitemap & Indexing Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="globe" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Sitemap & Search Engine Indexing</h3>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600">A sitemap helps Google and other search engines crawl and index your products faster.</p>
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-4 border border-slate-200 rounded-xl bg-slate-50">
                        <div>
                            <h4 class="font-bold text-slate-800">Dynamic XML Sitemap & Robots.txt</h4>
                            <p class="text-xs text-slate-500 mt-1">Automatically updates when new products are added.</p>
                            <div class="flex items-center gap-3 mt-2">
                                <a href="../sitemap.php" target="_blank" class="text-xs font-bold text-indigo-600 hover:underline inline-flex items-center gap-1"><i data-lucide="external-link" class="w-3 h-3"></i> View Sitemap</a>
                                <a href="../robots.txt" target="_blank" class="text-xs font-bold text-slate-500 hover:text-slate-800 hover:underline inline-flex items-center gap-1"><i data-lucide="external-link" class="w-3 h-3"></i> View Robots.txt</a>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="generateSitemapFiles()" id="generate-sitemap-btn" class="px-4 py-2.5 bg-indigo-50 border border-indigo-200 text-indigo-700 hover:bg-indigo-100 rounded-xl font-bold text-xs shadow-sm transition-colors flex items-center justify-center gap-2">
                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Generate Files
                            </button>
                            <button type="button" onclick="pingGoogleSitemap()" id="ping-google-btn" class="px-4 py-2.5 bg-slate-800 border border-slate-800 text-white hover:bg-slate-900 rounded-xl font-bold text-xs shadow-sm transition-colors flex items-center justify-center gap-2">
                                <i data-lucide="rss" class="w-3.5 h-3.5"></i> Ping Google
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-4 mt-2 border-t border-slate-100">
                        <div>
                            <span class="block text-sm font-semibold text-slate-800">Auto-Ping on New Product</span>
                            <span class="text-xs text-slate-500">Automatically notify Google when you add or publish a new product.</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="autoGooglePing" value="1" class="sr-only peer" <?php echo !empty($settings['autoGooglePing']) ? 'checked' : ''; ?>>
                            <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                </div>
            </div>

        </div>

        <!-- Tab Content: Preferences -->
        <div id="tab-content-preferences" class="settings-tab-content hidden p-6 sm:p-8 space-y-8 animate-in fade-in">
            
            <!-- Regional Settings Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="globe" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Region & Currency</h3>
                </div>
                <div class="p-6 space-y-5">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Store Currency</label>
                        <input name="currency" value="<?php echo htmlspecialchars($settings['currency']); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-bold text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700">Currency Symbol</label>
                        <input name="currencySymbol" value="<?php echo htmlspecialchars($settings['currencySymbol']); ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-bold text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all">
                    </div>
                </div>
            </div>

            <!-- Checkout Settings Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="shopping-cart" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Checkout Preferences</h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="block text-sm font-semibold text-slate-800">Guest Checkout</span>
                            <span class="text-xs text-slate-500">Allow users to buy without account</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="guestCheckoutEnabled" value="1" class="sr-only peer" <?php echo $settings['guestCheckoutEnabled'] ? 'checked' : ''; ?>>
                            <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                    <div class="space-y-2 pt-4 border-t border-slate-100">
                        <label class="block text-sm font-semibold text-slate-700">Advance Payment Notice Text</label>
                        <textarea name="advancePaymentNotice" rows="2" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-sm font-medium text-slate-900 focus:ring-2 focus:ring-indigo-600/20 focus:border-indigo-600 outline-none transition-all resize-none"><?php echo htmlspecialchars($settings['advancePaymentNotice'] ?? 'অর্ডারটি কনফার্ম করতে {amount} ৳ অ্যাডভান্স পেমেন্ট আবশ্যক। বাকি টাকা প্রোডাক্ট হাতে পেয়ে দিতে পারবেন।'); ?></textarea>
                        <p class="text-[11px] text-slate-500 mt-1.5">Use <code>{amount}</code> to show the dynamic advance amount. This text will be shown on the product page.</p>
                    </div>
                </div>
            </div>

            <!-- Store Features Settings Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                    <i data-lucide="layout-grid" class="w-5 h-5 text-slate-400"></i>
                    <h3 class="text-base font-bold text-slate-800">Store Features (Homepage)</h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="block text-sm font-semibold text-slate-800">Show Features Section</span>
                            <span class="text-xs text-slate-500">Fast Delivery, Secure Payments, 24/7 Support etc.</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="storeFeaturesEnabled" value="1" class="sr-only peer" <?php echo (!isset($settings['storeFeaturesEnabled']) || $settings['storeFeaturesEnabled']) ? 'checked' : ''; ?>>
                            <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                </div>
            </div>
        </div> <!-- End Preferences Tab -->
    </form>
</div>

<script>
// ইমেজ প্রিভিউ লজিক
function previewImage(input, previewId, placeholderId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById(previewId);
            img.src = e.target.result;
            img.classList.remove('hidden');
            const placeholder = document.getElementById(placeholderId);
            if (placeholder) placeholder.classList.add('hidden');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function switchSettingsTab(tab) {
    document.querySelectorAll('.settings-tab-btn').forEach(btn => {
        btn.classList.remove('text-blue-600');
        btn.classList.add('text-slate-500');
        btn.querySelector('.settings-tab-indicator').classList.add('hidden');
    });
    document.querySelectorAll('.settings-tab-content').forEach(content => {
        content.classList.add('hidden');
        content.classList.remove('block');
    });

    const activeBtn = document.getElementById('tab-btn-' + tab);
    activeBtn.classList.remove('text-slate-500');
    activeBtn.classList.add('text-blue-600');
    activeBtn.querySelector('.settings-tab-indicator').classList.remove('hidden');

    document.getElementById('tab-content-' + tab).classList.remove('hidden');
    document.getElementById('tab-content-' + tab).classList.add('block');
}

// সেভ লজিক (AJAX)
async function saveAllSettings() {
    const btn = document.getElementById('save-btn');
    const form = document.getElementById('settings-form');
    const formData = new FormData(form);
    
    formData.set('guestCheckoutEnabled', form.guestCheckoutEnabled.checked ? 1 : 0);
    formData.set('storeFeaturesEnabled', form.storeFeaturesEnabled.checked ? 1 : 0);
    if (form.autoGooglePing) formData.set('autoGooglePing', form.autoGooglePing.checked ? 1 : 0);

    const noticeFormData = new FormData();
    noticeFormData.append('action', 'save_advance_notice');
    noticeFormData.append('advancePaymentNotice', form.advancePaymentNotice.value);
    await fetch('settings.php', { method: 'POST', body: noticeFormData });

    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Saving...';
    lucide.createIcons();

    try {
        const response = await fetch('../api/save_settings.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if(result.success) {
            showToast("Settings updated successfully!", "success");
        } else {
            showToast(result.error || "Update failed.", "error");
        }
    } catch (err) {
        showToast("Error connecting to server.", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> Save All Changes';
        lucide.createIcons();
    }
}

async function generateSitemapFiles() {
    const btn = document.getElementById('generate-sitemap-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Generating...';
    lucide.createIcons();

    try {
        const response = await fetch('../api/setup_sitemap.php', { method: 'POST' });
        const result = await response.json();
        if(result.success) {
            showToast("Sitemap & Robots.txt generated successfully!", "success");
        } else {
            showToast(result.error || "Failed to generate files.", "error");
        }
    } catch (err) {
        showToast("Error connecting to server.", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function pingGoogleSitemap() {
    const btn = document.getElementById('ping-google-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Pinging...';
    lucide.createIcons();

    try {
        const response = await fetch('../api/ping_google.php', { method: 'POST' });
        const result = await response.json();
        if(result.success) {
            showToast("Google has been notified successfully!", "success");
        } else {
            showToast(result.error || "Failed to ping Google.", "error");
        }
    } catch (err) {
        showToast("Error connecting to server.", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>