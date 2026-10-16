<?php
/**
 * Reusable Product Card Component
 * Expects a $product variable to be available in the scope.
 */
global $symbol; // Use the global currency symbol

$firstImg = !empty($product['imageUrl']) ? ltrim(explode(',', $product['imageUrl'])[0], '/') : null;
$img = $firstImg ?: (!empty($product['variants'][0]['imageUrl']) ? ltrim($product['variants'][0]['imageUrl'], '/') : 'https://placehold.co/300x300');
$oldPrice = $product['basePrice'] ?? 0;
$newPrice = !empty($product['isFlashDeal']) ? ($product['flashDealPrice'] ?? $product['basePrice']) : ($product['basePrice'] ?? 0);
$discount = ($oldPrice > $newPrice && $oldPrice > 0) ? round((($oldPrice - $newPrice) / $oldPrice) * 100) : 0;

$totalStock = array_sum(array_column($product['variants'] ?? [], 'stock'));
$initialStock = array_sum(array_map(function($v) { return $v['initialStock'] ?? $v['stock'] ?? 1; }, $product['variants'] ?? []));
$sold = max(0, $initialStock - $totalStock);
$widthPercent = min(100, round(($sold / max(1, $initialStock)) * 100)) . '%';
?>
<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-xl hover:shadow-[#003e86]/10 transition-all duration-300 group flex flex-col hover:-translate-y-1 relative">
    <?php if ($discount > 0): ?>
        <div class="absolute top-2.5 left-2.5 z-10 bg-[#ba1a1a] text-white text-[9px] sm:text-[10px] font-black uppercase tracking-widest px-2 py-1 rounded-full shadow-sm w-fit">
            -<?php echo $discount; ?>% OFF
        </div>
    <?php endif; ?>
    
    <button type="button" onclick="toggleWishlist(<?php echo $product['id']; ?>, '<?php echo addslashes(htmlspecialchars($product['name'])); ?>', '<?php echo htmlspecialchars($img); ?>', <?php echo $newPrice; ?>, '<?php echo htmlspecialchars($product['slug']); ?>', event)" class="wishlist-btn-global absolute top-2.5 right-2.5 z-30 p-2 bg-white/90 backdrop-blur-sm text-slate-400 rounded-full shadow-sm hover:shadow-md hover:text-rose-500 transition-all" data-product-id="<?php echo $product['id']; ?>" title="Add to Wishlist">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
    </button>
    
    <a href="p/<?php echo htmlspecialchars($product['slug']); ?>" class="relative aspect-square overflow-hidden bg-slate-50 block p-3">
        <img src="<?php echo htmlspecialchars(ltrim($img, '/')); ?>" class="w-full h-full object-contain group-hover:scale-110 transition-transform duration-500 drop-shadow-sm mix-blend-multiply">
        <div class="absolute inset-0 bg-white/30 backdrop-blur-sm opacity-0 group-hover:opacity-100 transition-all duration-300 flex items-center justify-center z-20">
            <div class="bg-white text-[#003e86] p-2.5 rounded-full shadow-lg transform scale-50 group-hover:scale-110 transition-transform duration-300">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </div>
        </div>
    </a>
    <div class="p-3 sm:p-4 flex flex-col flex-1 border-t border-slate-50">
        <div class="text-[9px] sm:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1"><?php echo htmlspecialchars($product['categoryName'] ?? 'Uncategorized'); ?></div>
        <a href="p/<?php echo htmlspecialchars($product['slug']); ?>" class="font-bold text-slate-800 text-[13px] sm:text-sm mb-2 line-clamp-2 hover:text-[#003e86] transition-colors flex-1 leading-snug">
            <?php echo htmlspecialchars($product['name']); ?>
        </a>
        <div class="mt-auto flex flex-col gap-2.5">
            <div class="flex items-end justify-between pt-1.5 border-t border-slate-50">
                <div class="flex flex-col">
                    <?php if($discount > 0): ?>
                        <span class="text-[10px] sm:text-[11px] font-medium text-slate-400 line-through"><?php echo $symbol . number_format($oldPrice); ?></span>
                    <?php endif; ?>
                    <span class="font-black text-base sm:text-lg text-[#003e86]"><?php echo $symbol . number_format($newPrice); ?></span>
                </div>
                <button data-product-id="<?php echo $product['id']; ?>" onclick="quickAddToCart(<?php echo $product['id']; ?>, '<?php echo addslashes(htmlspecialchars($product['name'])); ?>', <?php echo $newPrice; ?>, '<?php echo htmlspecialchars($img); ?>', '<?php echo htmlspecialchars($product['slug']); ?>', event)" class="quick-add-to-cart-btn w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-blue-50 text-[#003e86] flex items-center justify-center hover:bg-[#003e86] hover:text-white transition-all shadow-sm z-30 shrink-0" title="Add to Cart">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>