<?php
session_start();
require_once __DIR__ . '/db.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header("Location: index.php");
    exit;
}

// Fetch Product & Category
$stmt = $pdo->prepare("
    SELECT p.*, c.name as categoryName, c.slug as categorySlug
    FROM Product p 
    LEFT JOIN Category c ON p.categoryId = c.id 
    WHERE p.slug = ?
");
$stmt->execute([$slug]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: 404");
    exit;
}

// Fetch global dropshop advance amount
$dropshopAdvanceAmount = 200;
$advancePaymentNotice = 'অর্ডারটি কনফার্ম করতে {amount} ৳ অ্যাডভান্স পেমেন্ট আবশ্যক। বাকি টাকা প্রোডাক্ট হাতে পেয়ে দিতে পারবেন।';
$settings = [];
try {
    $apiStmt = $pdo->query("SELECT dropshopAdvanceAmount FROM SettingApi LIMIT 1");
    $apiSettings = $apiStmt->fetch(PDO::FETCH_ASSOC);
    
    $genStmt = $pdo->query("SELECT advancePaymentNotice, currencySymbol, flashDealEndTime, fbtEnabled FROM SettingGeneral LIMIT 1");
    $settings = $genStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($apiSettings && isset($apiSettings['dropshopAdvanceAmount'])) {
        $dropshopAdvanceAmount = $apiSettings['dropshopAdvanceAmount'];
    }
    if ($settings && !empty($settings['advancePaymentNotice'])) {
        $advancePaymentNotice = $settings['advancePaymentNotice'];
    }
} catch (Exception $e) {}

// Page-specific SEO Meta Data
$pageMetaTitle = !empty($product['seoTitle']) ? $product['seoTitle'] : $product['name'];
$pageMetaDescription = !empty($product['seoDescription']) ? $product['seoDescription'] : (!empty($product['description']) ? substr(strip_tags(str_replace(["\r", "\n"], ' ', $product['description'])), 0, 160) . '...' : '');
$firstImage = !empty($product['imageUrl']) ? explode(',', $product['imageUrl'])[0] : null;
$pageMetaImage = $firstImage;

// Fetch Variants
$vStmt = $pdo->prepare("SELECT * FROM Variant WHERE productId = ?");
$vStmt->execute([$product['id']]);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

$symbol = $settings['currencySymbol'] ?? '৳';

// Organize variants
$sizes = [];
$colors = [];
$totalStock = 0;
$hasRealVariants = false;
$allImages = [];

if (!empty($product['imageUrl'])) {
    foreach(explode(',', $product['imageUrl']) as $img) {
        $allImages[] = ltrim($img, '/');
    }
}

foreach ($variants as $v) {
    if ($v['size'] !== 'Standard' && !in_array($v['size'], $sizes)) $sizes[] = $v['size'];
    if ($v['color'] !== 'Default' && !in_array($v['color'], $colors)) $colors[] = $v['color'];
    if ($v['size'] !== 'Standard' || $v['color'] !== 'Default') $hasRealVariants = true;
    
    $totalStock += $v['stock'];
    if ($v['imageUrl'] && !in_array(ltrim($v['imageUrl'], '/'), $allImages)) {
        $allImages[] = ltrim($v['imageUrl'], '/');
    }
}

if (empty($allImages)) {
    $allImages[] = 'https://placehold.co/600x600?text=No+Image';
}

$isFlashDeal = $product['isFlashDeal'] && strtotime($settings['flashDealEndTime'] ?? '0') > time();
$currentPrice = $isFlashDeal ? ($product['flashDealPrice'] ?? $product['basePrice']) : $product['basePrice'];
$oldPrice = $product['basePrice'];
$discount = ($isFlashDeal && $oldPrice > 0) ? round((($oldPrice - $currentPrice) / $oldPrice) * 100) : 0;

// Fetch Frequently Bought Together
$fbtEnabled = !isset($settings['fbtEnabled']) || $settings['fbtEnabled'];
$fbtProducts = [];
if ($fbtEnabled) {
    $fbtStmt = $pdo->prepare("SELECT p.id, p.name, p.slug, p.basePrice, p.imageUrl, p.isFlashDeal, p.flashDealPrice FROM Product p WHERE p.categoryId = ? AND p.id != ? AND p.categoryId IS NOT NULL ORDER BY p.id DESC LIMIT 20");
    $fbtStmt->execute([$product['categoryId'], $product['id']]);
    $fbtProducts = $fbtStmt->fetchAll(PDO::FETCH_ASSOC);
    
    shuffle($fbtProducts);
    $fbtProducts = array_slice($fbtProducts, 0, 10);

    if (!empty($fbtProducts)) {
        $fbtIds = array_column($fbtProducts, 'id');
        $placeholders = str_repeat('?,', count($fbtIds) - 1) . '?';
        $vStmt = $pdo->prepare("SELECT * FROM Variant WHERE productId IN ($placeholders)");
        $vStmt->execute($fbtIds);
        $fbtVariants = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        $variantsByFbt = [];
        foreach ($fbtVariants as $variant) { $variantsByFbt[$variant['productId']][] = $variant; }
        foreach ($fbtProducts as &$fbt) { $fbt['variants'] = $variantsByFbt[$fbt['id']] ?? []; }
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

<div class="min-h-screen pt-24 pb-6 md:pb-24 bg-white">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6">
        
        <nav class="flex items-center gap-2 text-sm font-medium text-slate-500 mb-8 overflow-x-auto whitespace-nowrap pb-2">
            <a href="./" class="hover:text-blue-600 transition-colors">Home</a>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            <a href="shop?category=<?php echo $product['categorySlug'] ?? ''; ?>" class="hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($product['categoryName'] ?? 'Uncategorized'); ?></a>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            <span class="text-slate-800 font-bold truncate"><?php echo htmlspecialchars($product['name']); ?></span>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-16 items-start">
            
            <div class="space-y-4 lg:col-span-5 lg:sticky lg:top-28">
                <div class="aspect-square rounded-2xl sm:rounded-3xl bg-slate-50 border border-slate-200 overflow-hidden relative group flex items-center justify-center p-4 sm:p-10 mx-0 shadow-inner">
                    <?php if ($isFlashDeal): ?>
                        <div class="absolute top-4 left-4 z-10 bg-gradient-to-r from-amber-500 to-orange-500 text-white px-3 py-1.5 rounded-xl text-xs font-black uppercase tracking-widest shadow-lg flex items-center gap-1.5">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg> Flash Deal
                        </div>
                    <?php endif; ?>
                    <img id="main-image" src="<?php echo htmlspecialchars($allImages[0]); ?>" class="w-full h-full object-contain mix-blend-multiply drop-shadow-sm sm:group-hover:scale-105 transition-transform duration-500" alt="<?php echo htmlspecialchars($product['name']); ?>">
                </div>
                
                <?php if (count($allImages) > 1): ?>
                <div class="flex gap-3 sm:gap-4 overflow-x-auto pb-2 custom-scrollbar snap-x mt-4">
                    <?php foreach ($allImages as $idx => $img): ?>
                        <button onclick="changeMainImage('<?php echo htmlspecialchars($img); ?>', this)" class="w-16 h-16 sm:w-24 sm:h-24 shrink-0 snap-start rounded-xl sm:rounded-2xl bg-slate-50 border-2 <?php echo $idx === 0 ? 'border-[#003e86]' : 'border-transparent hover:border-blue-300'; ?> overflow-hidden transition-all image-thumbnail" data-img="<?php echo htmlspecialchars($img); ?>">
                            <img src="<?php echo htmlspecialchars($img); ?>" class="w-full h-full object-cover mix-blend-multiply" alt="">
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="flex flex-col lg:col-span-7 pb-20 md:pb-0">
                <div class="mb-5 sm:mb-6">
                    <div class="flex justify-between items-start gap-4 mb-3 sm:mb-4">
                        <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black text-slate-900 leading-tight"><?php echo htmlspecialchars($product['name']); ?></h1>
                        <button type="button" onclick="toggleWishlist(<?php echo $product['id']; ?>, '<?php echo addslashes(htmlspecialchars($product['name'])); ?>', '<?php echo htmlspecialchars($allImages[0]); ?>', <?php echo $currentPrice; ?>, '<?php echo htmlspecialchars($product['slug']); ?>', event)" class="wishlist-btn-global p-2.5 sm:p-3 bg-rose-50 text-slate-400 hover:text-rose-500 rounded-full transition-all shrink-0 shadow-sm border border-slate-200" data-product-id="<?php echo $product['id']; ?>" title="Add to Wishlist">
                            <svg width="20" height="20" class="sm:w-6 sm:h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex items-end gap-3 sm:gap-4 mb-4">
                        <div class="flex flex-col">
                            <?php if ($isFlashDeal && $oldPrice > $currentPrice): ?>
                                <span id="product-old-price" class="text-slate-400 line-through text-sm sm:text-base font-bold"><?php echo $symbol . number_format($oldPrice); ?></span>
                            <?php endif; ?>
                            <span id="product-current-price" class="text-3xl sm:text-4xl font-black text-[#003e86] leading-none"><?php echo $symbol . number_format($currentPrice); ?></span>
                        </div>
                        <?php if ($discount > 0): ?>
                            <span class="bg-rose-100 text-rose-700 px-2.5 py-1 rounded-lg text-xs font-black uppercase tracking-widest mb-1.5">-<?php echo $discount; ?>% OFF</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex flex-wrap items-center justify-between gap-3 sm:gap-4 pb-5 sm:pb-6 border-b border-slate-100">
                        <div class="flex flex-wrap items-center gap-3 sm:gap-4 text-xs sm:text-sm font-bold text-slate-500">
                            <div class="flex items-center gap-1.5 <?php echo $totalStock > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-rose-600 bg-rose-50'; ?> px-3 py-1.5 rounded-lg border <?php echo $totalStock > 0 ? 'border-emerald-200' : 'border-rose-200'; ?>">
                                <div class="w-1.5 h-1.5 rounded-full <?php echo $totalStock > 0 ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'; ?>"></div>
                                <?php echo $totalStock > 0 ? "In Stock ({$totalStock})" : "Out of Stock"; ?>
                            </div>
                            <span class="flex items-center gap-1.5"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg> SKU: <?php echo htmlspecialchars($product['sku'] ?? $product['slug']); ?></span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <?php $shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/p/" . $product['slug']; ?>
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-widest mr-1 hidden sm:block">Share:</span>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($shareUrl); ?>" target="_blank" class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Share on Facebook">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                            </a>
                            <a href="https://wa.me/?text=<?php echo urlencode($product['name'] . " - " . $shareUrl); ?>" target="_blank" class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="Share on WhatsApp">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.5 19.5 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            </a>
                            <button type="button" onclick="navigator.clipboard.writeText('<?php echo $shareUrl; ?>'); showToast('Link copied to clipboard!', 'success');" class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center hover:bg-slate-200 transition-all shadow-sm" title="Copy Link">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <?php 
                $showAdvanceNotice = false;
                $advanceNoticeAmount = 0;
                
                if (!empty($product['isAdvanceRequired'])) {
                    $showAdvanceNotice = true;
                    $advanceNoticeAmount = $product['advanceAmount'] > 0 ? $product['advanceAmount'] : $dropshopAdvanceAmount;
                } elseif (!empty($product['source_url'])) {
                    $showAdvanceNotice = true;
                    $advanceNoticeAmount = $dropshopAdvanceAmount;
                }
                if ($showAdvanceNotice && $advanceNoticeAmount > 0): 
                    $formattedAmount = number_format($advanceNoticeAmount);
                    $finalNoticeText = str_replace('{amount}', '<span class="font-black text-indigo-900 bg-indigo-100 px-1.5 py-0.5 rounded">' . $formattedAmount . '</span>', htmlspecialchars($advancePaymentNotice));
                ?>
                <div class="mb-5 sm:mb-6 p-3 sm:p-4 bg-indigo-50 border border-indigo-200 rounded-xl flex items-start gap-3 shadow-sm animate-in fade-in">
                    <svg width="20" height="20" class="shrink-0 text-indigo-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-[11px] sm:text-xs font-semibold text-indigo-800 leading-relaxed">
                            <?php echo $finalNoticeText; ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <form id="add-to-cart-form" class="space-y-6 mb-4 md:mb-8">
                    <input type="hidden" name="productId" value="<?php echo $product['id']; ?>">
                    
                    <?php if ($hasRealVariants): ?>
                    <div class="space-y-4 sm:space-y-5">
                        <?php if (!empty($sizes)): ?>
                        <div>
                            <h3 class="text-xs sm:text-sm font-bold text-slate-900 mb-2 sm:mb-3 uppercase tracking-widest">Select Size</h3>
                            <div class="flex flex-wrap gap-2 sm:gap-3">
                                <?php foreach ($sizes as $idx => $size): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="size" value="<?php echo htmlspecialchars($size); ?>" class="peer sr-only" <?php echo $idx === 0 ? 'checked' : ''; ?>>
                                        <div class="px-4 sm:px-5 py-2 sm:py-2.5 rounded-xl border-2 border-slate-200 text-xs sm:text-sm font-bold text-slate-600 peer-checked:border-[#003e86] peer-checked:bg-blue-50 peer-checked:text-[#003e86] hover:border-slate-300 transition-all">
                                            <?php echo htmlspecialchars($size); ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($colors)): ?>
                        <div>
                            <h3 class="text-xs sm:text-sm font-bold text-slate-900 mb-2 sm:mb-3 uppercase tracking-widest">Select Color</h3>
                            <div class="flex flex-wrap gap-2 sm:gap-3">
                                <?php foreach ($colors as $idx => $color): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="color" value="<?php echo htmlspecialchars($color); ?>" class="peer sr-only" <?php echo $idx === 0 ? 'checked' : ''; ?>>
                                        <div class="px-4 sm:px-5 py-2 sm:py-2.5 rounded-xl border-2 border-slate-200 text-xs sm:text-sm font-bold text-slate-600 peer-checked:border-[#003e86] peer-checked:bg-blue-50 peer-checked:text-[#003e86] hover:border-slate-300 transition-all">
                                            <?php echo htmlspecialchars($color); ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="size" value="Standard">
                        <input type="hidden" name="color" value="Default">
                    <?php endif; ?>

                    <div id="main-action-buttons" class="flex flex-col md:flex-row gap-3 pt-2 md:pt-6 md:border-t md:border-slate-100 mt-2 md:mt-6">
                        <div class="hidden md:flex items-center justify-start gap-4">
                            <div class="flex items-center bg-slate-50 border border-slate-200 rounded-lg p-1 h-12 w-28 shrink-0">
                                <button type="button" onclick="updateQty(-1)" class="w-10 md:w-8 h-full flex items-center justify-center text-slate-500 hover:bg-white hover:shadow-sm rounded-md transition-all font-bold text-lg">-</button>
                                <input type="number" name="quantity" id="qty-input" value="1" min="1" max="<?php echo $totalStock > 0 ? $totalStock : 1; ?>" class="w-full h-full bg-transparent border-none text-center font-black text-slate-900 focus:ring-0 p-0 text-sm" readonly>
                                <button type="button" onclick="updateQty(1)" class="w-10 md:w-8 h-full flex items-center justify-center text-slate-500 hover:bg-white hover:shadow-sm rounded-md transition-all font-bold text-lg">+</button>
                            </div>
                        </div>
                        <div class="hidden md:flex flex-1 gap-2 sm:gap-3">
                            <button type="button" id="btn-add-to-cart" onclick="animateAndAddToCart(event, this)" <?php echo $totalStock <= 0 ? 'disabled' : ''; ?> class="add-to-cart-btn-sync btn-anim-wrapper flex-1 h-12 bg-blue-50 hover:bg-[#003e86] hover:text-white text-[#003e86] disabled:bg-slate-100 disabled:text-slate-400 border border-blue-200 hover:border-[#003e86] disabled:border-slate-200 rounded-lg sm:rounded-xl font-bold text-xs sm:text-sm transition-all active:scale-[0.98]">
                                <div class="default-content">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                                    <span class="hidden sm:inline">Add to Cart</span><span class="sm:hidden">Cart</span>
                                </div>
                                <div class="anim-layer text-current">
                                    <div class="anim-truck"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                                    <div class="anim-box"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                                    <div class="anim-speed-line"></div>
                                </div>
                            </button>
                            <button type="button" onclick="buyNow()" <?php echo $totalStock <= 0 ? 'disabled' : ''; ?> class="buy-now-btn-sync flex-1 h-12 bg-[#003e86] hover:bg-blue-800 disabled:bg-slate-300 text-white rounded-lg sm:rounded-xl font-bold text-xs sm:text-sm shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-1 active:scale-[0.98]">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                Buy Now
                            </button>
                        </div>
                    </div>
                </form>

                <div class="pt-6 border-t border-slate-100 mt-auto">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-[#003e86]"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Product Details
                    </h3>
                    <div id="product-description-container" class="relative">
                        <div id="product-description" class="prose prose-sm sm:prose-base text-slate-600 max-w-none leading-relaxed overflow-hidden transition-all duration-300 relative">
                            <?php echo nl2br(htmlspecialchars($product['description'] ?? 'No description available.')); ?>
                        </div>
                        <div id="description-fade" class="absolute bottom-0 left-0 w-full h-20 bg-gradient-to-t from-white to-transparent hidden pointer-events-none"></div>
                    </div>
                    <button type="button" id="read-more-btn" onclick="toggleDescription()" class="hidden mt-2 text-sm font-bold text-[#003e86] hover:underline flex items-center gap-1 transition-all">
                        Read More <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        
        <?php if (!empty($fbtProducts) && $fbtEnabled): ?>
        <div class="mt-16 sm:mt-24 mb-10">
            <div class="flex items-center justify-between mb-5 sm:mb-6">
                <h2 class="text-xl md:text-2xl font-black text-slate-900 border-l-4 border-[#003e86] pl-3 md:pl-4 tracking-tight">Frequently Bought Together</h2>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-6 pb-6 pt-2">
                <?php 
                $mainProduct = $product;
                foreach ($fbtProducts as $fbtItem): 
                    $product = $fbtItem;
                    include '_product_card.php';
                endforeach; 
                $product = $mainProduct;
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="sticky-action-bar" class="fixed bottom-[calc(64px+env(safe-area-inset-bottom))] md:bottom-0 left-0 w-full bg-white/95 backdrop-blur-md border-t border-slate-200 p-2.5 sm:p-3 z-[60] flex items-center justify-center gap-2 sm:gap-3 transform translate-y-[200%] transition-transform duration-300 shadow-[0_-10px_20px_rgba(0,0,0,0.15)]">
    <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl p-1 h-11 w-[80px] sm:w-[110px] md:w-28 shrink-0 shadow-sm">
        <button type="button" onclick="updateQty(-1)" class="w-7 sm:w-10 h-full flex items-center justify-center text-slate-500 hover:text-[#003e86] hover:bg-white hover:shadow-sm rounded-lg transition-all font-bold text-lg active:scale-95">-</button>
        <input type="text" class="qty-input-sync w-full h-full bg-transparent border-none text-center font-black text-slate-900 focus:ring-0 p-0 text-sm pointer-events-none" value="1" readonly>
        <button type="button" onclick="updateQty(1)" class="w-7 sm:w-10 h-full flex items-center justify-center text-slate-500 hover:text-[#003e86] hover:bg-white hover:shadow-sm rounded-lg transition-all font-bold text-lg active:scale-95">+</button>
    </div>
    <button type="button" onclick="animateAndAddToCart(event, this)" <?php echo $totalStock <= 0 ? 'disabled' : ''; ?> class="add-to-cart-btn-sync btn-anim-wrapper flex-1 md:flex-none md:w-48 lg:w-64 h-11 bg-blue-50 hover:bg-[#003e86] hover:text-white text-[#003e86] disabled:bg-slate-100 disabled:text-slate-400 border border-blue-200 hover:border-[#003e86] disabled:border-slate-200 rounded-xl font-bold text-xs sm:text-sm transition-all active:scale-[0.98]">
        <div class="default-content">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
            <span class="hidden sm:inline">Add to Cart</span><span class="sm:hidden">Cart</span>
        </div>
        <div class="anim-layer text-current">
            <div class="anim-truck"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
            <div class="anim-box"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
            <div class="anim-speed-line"></div>
        </div>
    </button>
    <button type="button" onclick="buyNow()" <?php echo $totalStock <= 0 ? 'disabled' : ''; ?> class="buy-now-btn-sync flex-1 md:flex-none md:w-48 lg:w-64 h-11 bg-[#003e86] hover:bg-blue-800 disabled:bg-slate-300 text-white rounded-xl font-bold text-xs sm:text-sm shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-1.5 active:scale-[0.98]">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 sm:w-4 sm:h-4"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        Buy Now
    </button>
</div>

<script>
// Image Gallery Logic
function changeMainImage(src, btn) {
    document.getElementById('main-image').src = src;
    document.querySelectorAll('.image-thumbnail').forEach(el => {
        el.classList.remove('border-[#003e86]');
        el.classList.add('border-transparent');
    });
    btn.classList.remove('border-transparent');
    btn.classList.add('border-[#003e86]');
}

// Variant Image Changer Logic
const productVariants = <?php echo json_encode($variants); ?>;
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="size"], input[name="color"]').forEach(radio => {
        radio.addEventListener('change', function() {
            checkCartState();
            updateVariantImage();
        });
    });
});

function updateVariantImage() {
    const sizeRadio = document.querySelector('input[name="size"]:checked');
    const colorRadio = document.querySelector('input[name="color"]:checked');
    const size = sizeRadio ? sizeRadio.value : 'Standard';
    const color = colorRadio ? colorRadio.value : 'Default';

    const variant = productVariants.find(v => v.size === size && v.color === color);
    
    if (variant) {
        let cleanVariantImg = '';
        if (variant.imageUrl) {
            const firstImg = variant.imageUrl.split(',')[0];
            cleanVariantImg = firstImg.replace(/^\/+/, '');
            
            const mainImage = document.getElementById('main-image');
            if (mainImage) {
                mainImage.src = cleanVariantImg;
                
                document.querySelectorAll('.image-thumbnail').forEach(btn => {
                    const btnImg = btn.getAttribute('data-img');
                    if (btnImg && btnImg.includes(cleanVariantImg)) {
                        btn.classList.add('border-[#003e86]');
                        btn.classList.remove('border-transparent');
                    } else {
                        btn.classList.remove('border-[#003e86]');
                        btn.classList.add('border-transparent');
                    }
                });
            }
        }
        
        // Update stock max value based on variant
        const variantStock = parseInt(variant.stock) || 0;
        const qtyInput = document.getElementById('qty-input');
        if (qtyInput) {
            qtyInput.setAttribute('max', variantStock > 0 ? variantStock : 1);
            if (parseInt(qtyInput.value) > variantStock && variantStock > 0) {
                qtyInput.value = variantStock;
                updateQty(0);
            }
        }

        // Disable buttons if out of stock
        const addBtn = document.getElementById('btn-add-to-cart');
        const buyBtn = document.querySelector('#main-action-buttons .buy-now-btn-sync');
        const stickyAddBtn = document.querySelector('#sticky-action-bar .add-to-cart-btn-sync');
        const stickyBuyBtn = document.querySelector('#sticky-action-bar .buy-now-btn-sync');

        const isOutOfStock = variantStock <= 0;
        
        if (addBtn) addBtn.disabled = isOutOfStock;
        if (buyBtn) buyBtn.disabled = isOutOfStock;
        if (stickyAddBtn) stickyAddBtn.disabled = isOutOfStock;
        if (stickyBuyBtn) stickyBuyBtn.disabled = isOutOfStock;

        // Update stock badge UI
        const stockBadge = document.querySelector('.text-emerald-600.bg-emerald-50') || document.querySelector('.text-rose-600.bg-rose-50');
        if (stockBadge) {
            if (isOutOfStock) {
                stockBadge.className = 'flex items-center gap-1.5 text-rose-600 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-200';
                stockBadge.innerHTML = `<div class="w-1.5 h-1.5 rounded-full bg-rose-500"></div>Out of Stock`;
            } else {
                stockBadge.className = 'flex items-center gap-1.5 text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-200';
                stockBadge.innerHTML = `<div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></div>In Stock (${variantStock})`;
            }
        }

        const stickyImage = document.getElementById('sticky-main-image');
        if (stickyImage && cleanVariantImg) stickyImage.src = cleanVariantImg;
    }
}

const baseUnitPrice = <?php echo $currentPrice; ?>;
const baseOldPrice = <?php echo $oldPrice; ?>;
const currencySymbol = '<?php echo $symbol; ?>';

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

document.addEventListener('DOMContentLoaded', () => {
    const mainActions = document.getElementById('main-action-buttons');
    const stickyBar = document.getElementById('sticky-action-bar');

    if (mainActions && stickyBar) {
        const handleStickyBar = () => {
            if (window.innerWidth < 768) {
                stickyBar.classList.remove('translate-y-[200%]');
                return;
            }
            
            const rect = mainActions.getBoundingClientRect();
            if (rect.bottom < 0) {
                stickyBar.classList.remove('translate-y-[200%]');
            } else {
                stickyBar.classList.add('translate-y-[200%]');
            }
        };
        
        window.addEventListener('scroll', handleStickyBar, { passive: true });
        window.addEventListener('resize', handleStickyBar, { passive: true });
        handleStickyBar();
    }
});

// Add to Cart Animation Logic
function animateAndAddToCart(event, btnElement = null) {
    const btn = btnElement || document.getElementById('btn-add-to-cart');
    if(!btn || btn.disabled || btn.classList.contains('is-animating')) return;
    
    const form = document.getElementById('add-to-cart-form');
    const formData = new FormData(form);
    const productId = formData.get('productId');
    const size = formData.get('size') || 'Standard';
    const color = formData.get('color') || 'Default';

    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    const existingIndex = cart.findIndex(item => 
        (item.productId == productId || item.id == productId) && 
        item.size === size && 
        item.color === color
    );
    
    if (existingIndex >= 0) {
        showToast("Already in cart", "info");
        return;
    }
    
    btn.classList.add('is-animating');
    btn.style.pointerEvents = 'none';
    
    setTimeout(() => {
        btn.classList.remove('is-animating');
        btn.style.pointerEvents = 'auto';
        addToCart(event);
    }, 2500);
}

// Buy Now Animation Logic
function animateAndBuyNow(event, btnElement = null) {
    const btn = btnElement || document.querySelector('.buy-now-btn-sync');
    if(!btn || btn.disabled || btn.classList.contains('is-animating')) return;
    
    btn.classList.add('is-animating');
    btn.style.pointerEvents = 'none';
    
    setTimeout(() => {
        buyNow();
    }, 2500);
}

// Quantity Logic
function updateQty(delta) {
    const mainInput = document.getElementById('qty-input');
    if (!mainInput) return;
    
    let val = parseInt(mainInput.value) + delta;
    const max = parseInt(mainInput.getAttribute('max'));
    
    if (val > max) {
        val = max;
        if (max > 0) showToast("Maximum available stock reached!", "error");
    }
    if (val < 1) val = 1;
    
    mainInput.value = val;
    document.querySelectorAll('.qty-input-sync').forEach(input => input.value = val);
        
    // Update the displayed prices with animation based on quantity
    animateCountUp('product-current-price', baseUnitPrice * val, currencySymbol);
    if (document.getElementById('product-old-price') && baseOldPrice > baseUnitPrice) {
        animateCountUp('product-old-price', baseOldPrice * val, currencySymbol);
    }
    if (document.getElementById('sticky-price')) {
        animateCountUp('sticky-price', baseUnitPrice * val, currencySymbol);
    }
}

function processCartAction(redirect = false, event = null) {
    const form = document.getElementById('add-to-cart-form');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    const existingIndex = cart.findIndex(item => 
        item.productId === data.productId && 
        item.size === data.size && 
        item.color === data.color
    );

    if (existingIndex >= 0) {
        if (redirect) {
            window.location.href = 'checkout';
        }
        return;
    } else {
        data.quantity = parseInt(data.quantity);
        data.name = <?php echo json_encode($product['name']); ?>;
        data.price = <?php echo $currentPrice; ?>;
        
        let img = <?php echo json_encode($allImages[0]); ?>;
        const variant = productVariants.find(v => v.size === (data.size || 'Standard') && v.color === (data.color || 'Default'));
        if (variant && variant.imageUrl) {
            img = variant.imageUrl.split(',')[0].replace(/^\/+/, '');
        }
        data.image = img;
        data.slug = <?php echo json_encode($product['slug']); ?>;
        cart.push(data);
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    window.dispatchEvent(new Event('cartUpdated'));
    
    if (redirect) {
        window.location.href = 'checkout';
    } else {
        if (event && window.flyToCart) {
            flyToCart(data.image, event);
        }
    }
}

function addToCart(event) {
    processCartAction(false, event);
}

function buyNow() {
    const form = document.getElementById('add-to-cart-form');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    data.quantity = parseInt(data.quantity) || 1;
    data.name = <?php echo json_encode($product['name']); ?>;
    data.price = <?php echo $currentPrice; ?>;
    
    let img = <?php echo json_encode($allImages[0]); ?>;
    const variant = productVariants.find(v => v.size === (data.size || 'Standard') && v.color === (data.color || 'Default'));
    if (variant && variant.imageUrl) {
        img = variant.imageUrl.split(',')[0].replace(/^\/+/, '');
    }
    data.image = img;
    data.slug = <?php echo json_encode($product['slug']); ?>;
    
    sessionStorage.setItem('buyNowCart', JSON.stringify([data]));
    window.location.href = 'checkout?buy_now=1';
}

// Auto update add to cart button state
function checkCartState() {
    const form = document.getElementById('add-to-cart-form');
    if (!form) return;
    const formData = new FormData(form);
    const productId = formData.get('productId');
    const size = formData.get('size') || 'Standard';
    const color = formData.get('color') || 'Default';

    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    const existingIndex = cart.findIndex(item => 
        (item.productId == productId || item.id == productId) && 
        item.size === size && 
        item.color === color
    );

    const btns = document.querySelectorAll('.add-to-cart-btn-sync');
    if (btns.length === 0) return;
    
    btns.forEach(btn => {
        const defaultContent = btn.querySelector('.default-content');
        if (existingIndex >= 0) {
            if (defaultContent) {
                defaultContent.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> <span class="hidden sm:inline">Added to Cart</span><span class="sm:hidden">Added</span>`;
            }
            btn.classList.remove('bg-blue-50', 'text-[#003e86]', 'hover:bg-[#003e86]', 'hover:text-white', 'border-blue-200', 'hover:border-[#003e86]');
            btn.classList.add('bg-emerald-500', 'text-white', 'hover:bg-emerald-600', 'border-emerald-500');
            btn.title = "Added to Cart";
        } else {
            if (defaultContent) {
                defaultContent.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> <span class="hidden sm:inline">Add to Cart</span><span class="sm:hidden">Cart</span>`;
            }
            btn.classList.remove('bg-emerald-500', 'text-white', 'hover:bg-emerald-600', 'border-emerald-500');
            btn.classList.add('bg-blue-50', 'text-[#003e86]', 'hover:bg-[#003e86]', 'hover:text-white', 'border-blue-200', 'hover:border-[#003e86]');
            btn.title = "Add to Cart";
        }
    });
}

// Description Read More Logic
document.addEventListener('DOMContentLoaded', () => {
    const desc = document.getElementById('product-description');
    const fade = document.getElementById('description-fade');
    const btn = document.getElementById('read-more-btn');
    
    if (desc && desc.scrollHeight > 250) {
        desc.style.maxHeight = '250px';
        fade.classList.remove('hidden');
        btn.classList.remove('hidden');
        btn.classList.add('flex');
    }
});

function toggleDescription() {
    const desc = document.getElementById('product-description');
    const fade = document.getElementById('description-fade');
    const btn = document.getElementById('read-more-btn');
    
    if (desc.style.maxHeight === '250px') {
        desc.style.maxHeight = desc.scrollHeight + 'px';
        fade.classList.add('hidden');
        btn.innerHTML = 'Read Less <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>';
    } else {
        desc.style.maxHeight = '250px';
        fade.classList.remove('hidden');
        btn.innerHTML = 'Read More <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    }
}

// Frontend Toast Function (Fallback)
function showToast(message, type = 'success') {
    if (window.innerWidth < 768 && type !== 'error') return;

    let container = document.getElementById('global-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'global-toast-container';
        let bottomClass = (window.location.pathname.includes('product') || window.location.pathname.includes('cart')) ? 'bottom-[150px]' : 'bottom-24';
        container.className = `fixed ${bottomClass} md:bottom-8 left-4 right-4 md:left-auto md:right-8 z-[100] space-y-2 md:space-y-4 flex flex-col items-center md:items-end pointer-events-none`;
        document.body.appendChild(container);
    }
    
    const bg = type === 'success' ? 'bg-[#003e86]' : (type === 'error' ? 'bg-rose-600' : 'bg-amber-500');
    const toast = document.createElement('div');
    toast.className = `flex items-center justify-center gap-2 md:gap-3 px-4 md:px-6 py-3 md:py-4 rounded-xl md:rounded-2xl shadow-xl text-white transition-all duration-300 animate-in slide-in-from-bottom-2 md:slide-in-from-right pointer-events-auto ${bg} max-w-sm w-full md:w-auto text-center md:text-left`;
    toast.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
        <span class="text-xs md:text-sm font-bold tracking-tight">${message}</span>
    `;
    container.appendChild(toast);
    setTimeout(() => { 
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(1rem)';
        setTimeout(() => toast.remove(), 300); 
    }, 3000);
}

// Auto-update Cart Counter Badge
window.addEventListener('cartUpdated', () => {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const count = cart.reduce((acc, item) => acc + item.quantity, 0);
    
    const badges = document.querySelectorAll('a[href="/cart"] span, a[href="cart.php"] span');
    badges.forEach(badge => {
        badge.innerText = count;
        badge.classList.add('scale-150');
        setTimeout(() => badge.classList.remove('scale-150'), 200);
    });
    if (typeof checkCartState === 'function') checkCartState();
});

// Initialize badge on load
window.dispatchEvent(new Event('cartUpdated'));

document.addEventListener('DOMContentLoaded', checkCartState);
document.querySelectorAll('input[type=radio]').forEach(radio => {
    radio.addEventListener('change', checkCartState);
});
</script>

<?php include 'Footer.php'; ?>