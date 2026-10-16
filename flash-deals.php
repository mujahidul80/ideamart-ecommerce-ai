<?php
session_start();
require_once __DIR__ . '/db.php';

$categories = [];
$products = [];
$symbol = '৳';
$flashEndTime = 0;

try {

    // Fetch Store Settings
    $setStmt = $pdo->query("SELECT currencySymbol, flashDealEndTime FROM SettingGeneral LIMIT 1");
    $settings = $setStmt ? $setStmt->fetch(PDO::FETCH_ASSOC) : [];
    $symbol = $settings['currencySymbol'] ?? '৳';
    $flashEndTime = isset($settings['flashDealEndTime']) ? strtotime($settings['flashDealEndTime']) : 0;

    // Fetch Products
    $query = "SELECT p.*, c.name as categoryName FROM Product p LEFT JOIN Category c ON p.categoryId = c.id WHERE p.isFlashDeal = 1";
    $params = [];

    if (!empty($_GET['category'])) {
        $query .= " AND c.slug = ?";
        $params[] = $_GET['category'];
    }

    if (!empty($_GET['search'])) {
        $query .= " AND p.name LIKE ?";
        $params[] = '%' . $_GET['search'] . '%';
    }

    if (isset($_GET['min_price']) && $_GET['min_price'] !== '') {
        $query .= " AND COALESCE(p.flashDealPrice, p.basePrice) >= ?";
        $params[] = (float)$_GET['min_price'];
    }

    if (isset($_GET['max_price']) && $_GET['max_price'] !== '') {
        $query .= " AND COALESCE(p.flashDealPrice, p.basePrice) <= ?";
        $params[] = (float)$_GET['max_price'];
    }

    if (isset($_GET['in_stock']) && $_GET['in_stock'] == '1') {
        $query .= " AND (SELECT SUM(stock) FROM Variant WHERE productId = p.id) > 0";
    }

    $sort = $_GET['sort'] ?? 'newest';
    if ($sort === 'price_low') {
        $query .= " ORDER BY p.flashDealPrice ASC";
    } elseif ($sort === 'price_high') {
        $query .= " ORDER BY p.flashDealPrice DESC";
    } else {
        $query .= " ORDER BY p.id DESC"; // newest
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($products)) {
        $productIds = array_column($products, 'id');
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        
        $vStmt = $pdo->prepare("SELECT * FROM Variant WHERE productId IN ($placeholders)");
        $vStmt->execute($productIds);
        $allVariants = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $variantsByProduct = [];
        foreach ($allVariants as $variant) {
            $variantsByProduct[$variant['productId']][] = $variant;
        }
        
        foreach ($products as &$p) {
            $p['variants'] = $variantsByProduct[$p['id']] ?? [];
        }
    }
} catch (Exception $e) {
    // Handle potential missing columns silently to prevent 500 error
}

// Fetch Banners for Flash Deal Page
$flashBanners = [];
try {
    $bannerStmt = $pdo->query("SELECT * FROM Banner WHERE active = 1 AND resourceType = 'flash_deal_banner' ORDER BY id DESC");
    $flashBanners = $bannerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    ob_start();
    if(empty($products)): ?>
        <div class="col-span-full py-16 text-center bg-white rounded-3xl border border-slate-200 shadow-sm">
            <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor" class="text-slate-400" stroke="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-800 mb-2">No active deals found</h3>
            <p class="text-slate-500 mb-6 font-medium">Try adjusting your filters or check back later.</p>
            <a href="flash-deals" class="inline-flex items-center gap-2 bg-[#003e86] hover:bg-blue-800 text-white font-bold px-6 py-3 rounded-xl shadow-md transition-all">Clear all filters</a>
        </div>
    <?php else: ?>
        <?php foreach($products as $product): ?>
            <?php include '_product_card.php'; ?>
        <?php endforeach; ?>
    <?php endif;
    $product_html = ob_get_clean();

    echo json_encode([
        'html' => $product_html,
        'count' => count($products)
    ]);
    exit;
}

include 'Header.php';
?>

<div class="min-h-screen pt-24 md:pt-28 pb-28 md:pb-24 bg-slate-50 font-sans">
    
    <div class="max-w-[1200px] mx-auto px-6">
        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Sidebar -->
            <div class="w-full lg:w-72 shrink-0 self-start lg:sticky lg:top-28 lg:h-[calc(100vh-140px)]">
                <!-- Mobile Filter Overlay -->
                <div id="filter-overlay" onclick="toggleFilterDrawer()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[90] hidden transition-opacity lg:hidden"></div>

                <form onsubmit="applyFilters(event)" id="filter-drawer" class="fixed inset-y-0 left-0 w-72 sm:w-80 bg-white z-[100] transform -translate-x-full transition-transform duration-300 flex flex-col shadow-2xl lg:static lg:h-full lg:translate-x-0 lg:w-full lg:z-10 lg:shadow-none lg:bg-transparent lg:border-none">
                    
                    <!-- Mobile Drawer Header -->
                    <div class="flex items-center justify-between p-5 border-b border-slate-100 lg:hidden bg-slate-50 shrink-0">
                        <h2 class="font-black text-slate-800 text-lg uppercase tracking-wider">Filters</h2>
                        <button type="button" onclick="toggleFilterDrawer()" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto custom-scrollbar p-6 lg:bg-white lg:rounded-t-3xl lg:shadow-sm lg:border-t lg:border-x lg:border-slate-200 space-y-8">
                        <!-- Search -->
                        <div>
                            <h3 class="font-bold text-slate-800 mb-4 uppercase tracking-widest text-xs flex items-center gap-2">
                                <div class="w-6 h-6 rounded-lg bg-blue-50 text-[#003e86] flex items-center justify-center shrink-0">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                </div>
                                Search Deals
                            </h3>
                            <div class="relative">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Search deals..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-[#003e86]/20 focus:border-[#003e86] outline-none transition-all text-slate-700 placeholder-slate-400">
                            </div>
                        </div>

                        <!-- Price Range -->
                        <div>
                            <h3 class="font-bold text-slate-800 mb-4 uppercase tracking-widest text-xs flex items-center gap-2">
                                <div class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                </div>
                                Price Range
                            </h3>
                            <div class="flex items-center gap-2">
                                <input type="number" name="min_price" value="<?php echo htmlspecialchars($_GET['min_price'] ?? ''); ?>" placeholder="Min ৳" onblur="if(window.innerWidth >= 1024 && this.value !== '<?php echo htmlspecialchars($_GET['min_price'] ?? ''); ?>') applyFilters();" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition-all">
                                <span class="text-slate-400 font-bold">-</span>
                                <input type="number" name="max_price" value="<?php echo htmlspecialchars($_GET['max_price'] ?? ''); ?>" placeholder="Max ৳" onblur="if(window.innerWidth >= 1024 && this.value !== '<?php echo htmlspecialchars($_GET['max_price'] ?? ''); ?>') applyFilters();" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition-all">
                            </div>
                        </div>

                        <!-- Availability -->
                        <div>
                            <h3 class="font-bold text-slate-800 mb-4 uppercase tracking-widest text-xs flex items-center gap-2">
                                <div class="w-6 h-6 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                </div>
                                Availability
                            </h3>
                            <label class="flex items-center gap-3 cursor-pointer group bg-slate-50 hover:bg-slate-100 p-3.5 rounded-xl border border-slate-200 transition-colors">
                                <div class="relative flex items-center justify-center shrink-0">
                                    <input type="checkbox" name="in_stock" value="1" onchange="if(window.innerWidth >= 1024) applyFilters();" <?php echo isset($_GET['in_stock']) && $_GET['in_stock'] == '1' ? 'checked' : ''; ?> class="peer appearance-none w-5 h-5 border-2 border-slate-300 rounded-md checked:bg-amber-500 checked:border-amber-500 transition-all cursor-pointer">
                                    <svg class="absolute w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                                <span class="text-sm font-bold text-slate-700 group-hover:text-amber-700 transition-colors">In Stock Only</span>
                            </label>
                        </div>

                        <!-- Sort -->
                        <div>
                            <h3 class="font-bold text-slate-800 mb-4 uppercase tracking-widest text-xs flex items-center gap-2">
                                <div class="w-6 h-6 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="m21 8-4-4-4 4"/><path d="M17 4v16"/></svg>
                                </div>
                                Sort By
                            </h3>
                            <select name="sort" onchange="if(window.innerWidth >= 1024) applyFilters();" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-rose-500/20 focus:border-rose-600 outline-none transition-all cursor-pointer">
                                <option value="newest" <?php echo ($_GET['sort'] ?? '') === 'newest' ? 'selected' : ''; ?>>Newest Deals</option>
                                <option value="price_low" <?php echo ($_GET['sort'] ?? '') === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                <option value="price_high" <?php echo ($_GET['sort'] ?? '') === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Apply Button Container (Sticky Bottom) -->
                    <div class="p-5 border-t border-slate-100 bg-white lg:rounded-b-3xl lg:border-b lg:border-x lg:border-slate-200 lg:shadow-sm shrink-0">
                        <button type="submit" class="w-full bg-[#003e86] hover:bg-blue-800 text-white font-bold py-3.5 rounded-xl shadow-md transition-all active:scale-95 flex items-center justify-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> Apply Filters</button>
                    </div>
                </form>
            </div>
            
            <!-- Right Content Area (Banner & Products) -->
            <div class="flex-1 min-w-0">
                
                <!-- Flash Banner Slider -->
                <?php if (!empty($flashBanners)): ?>
                <div class="mb-6 lg:mb-8">
                    <div class="rounded-2xl overflow-hidden shadow-sm border border-slate-200 aspect-[16/9] lg:aspect-[21/9] w-full cursor-grab" id="flash-slider">
                        <div class='w-full h-full relative overflow-hidden group'>
                            <div class='slider-track flex w-full h-full' id='flash-slider-track'>
                                <?php foreach($flashBanners as $ban): ?>
                                    <?php $img = $ban['imageUrl'] ? ltrim($ban['imageUrl'], '/') : 'https://placehold.co/1200x400'; $url = $ban['targetUrl'] ?: '#'; ?>
                                    <a href='<?php echo htmlspecialchars($url); ?>' class='w-full h-full shrink-0 block relative'>
                                        <img src='<?php echo htmlspecialchars($img); ?>' class='w-full h-full object-cover' alt='<?php echo htmlspecialchars($ban['title']); ?>' />
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php if(count($flashBanners) > 1): ?>
                                <div class='absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2 z-10'>
                                    <?php foreach($flashBanners as $idx => $ban): ?>
                                        <button class='slider-dot h-2 rounded-full transition-all <?php echo $idx === 0 ? 'w-8 bg-[#003e86]' : 'w-2 bg-white/60 hover:bg-white'; ?>' data-slide-index='<?php echo $idx; ?>'></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Section Header with Timer -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5 lg:mb-6">
                    <div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-800 flex items-center gap-2">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="animate-pulse"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            Flash Deals
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-500 mt-1">Grab the best products at unbeatable prices.</p>
                    </div>
                    
                    <div class="flex items-center justify-between w-full sm:w-auto gap-4">
                        <?php if ($flashEndTime > time()): ?>
                        <div class="flex items-center gap-2 bg-rose-50 border border-rose-100 px-3 py-2 rounded-xl shadow-sm">
                            <span class="text-[10px] sm:text-xs font-black uppercase tracking-widest text-rose-500 shrink-0 hidden sm:inline">Ends In:</span>
                            <div id="flash-deal-timer-page" data-endtime="<?php echo date('c', $flashEndTime); ?>" class="flex items-center gap-1 sm:gap-1.5">
                                <!-- Timer Injected Here -->
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <button type="button" onclick="toggleFilterDrawer()" class="lg:hidden flex items-center gap-2 bg-white px-4 py-2.5 rounded-xl border border-slate-200 shadow-sm font-bold text-slate-700 active:scale-95 transition-transform shrink-0 hover:bg-slate-50">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            <span class="text-sm">Filter</span>
                        </button>
                    </div>
                </div>

                <!-- Products Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-4 md:gap-5" id="product-grid-container">
                    <?php if(empty($products)): ?>
                        <div class="col-span-full py-16 text-center bg-white rounded-3xl border border-slate-200 shadow-sm">
                            <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor" class="text-slate-400" stroke="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-800 mb-2">No active deals found</h3>
                            <p class="text-slate-500 mb-6 font-medium">Try adjusting your filters or check back later.</p>
                            <a href="flash-deals" class="inline-flex items-center gap-2 bg-[#003e86] hover:bg-blue-800 text-white font-bold px-6 py-3 rounded-xl shadow-md transition-all">Clear all filters</a>
                        </div>
                    <?php else: ?>
                        <?php foreach($products as $product): ?>
                            <?php include '_product_card.php'; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleFilterDrawer() {
    const drawer = document.getElementById('filter-drawer');
    const overlay = document.getElementById('filter-overlay');
    drawer.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}

    async function applyFilters(e, fromHistory = false) {
        if (e) e.preventDefault();
        
        const form = document.getElementById('filter-drawer');
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);

        const url = 'flash-deals.php?' + params.toString() + '&ajax=1';
        const userFriendlyUrl = 'flash-deals?' + params.toString();

        if (!fromHistory) {
            window.history.pushState({params: params.toString()}, '', userFriendlyUrl);
        }

        const productGrid = document.getElementById('product-grid-container');
        
        productGrid.style.opacity = '0.5';
        productGrid.style.minHeight = '300px';

        try {
            const response = await fetch(url);
            const data = await response.json();
            
            productGrid.style.opacity = '0';
            setTimeout(() => {
                productGrid.innerHTML = data.html;
                productGrid.style.opacity = '1';
                lucide.createIcons();
            }, 200);
            
        } catch (error) {
            console.error('Error fetching products:', error);
            productGrid.innerHTML = '<div class="col-span-full text-center py-10 text-rose-500 font-bold">Failed to load products. Please try again.</div>';
        } finally {
            productGrid.style.minHeight = '';
            
            if (window.innerWidth < 1024 && e) {
                toggleFilterDrawer();
            }
        }
    }

    window.addEventListener('popstate', function(event) {
        if (event.state && typeof event.state.params !== 'undefined') {
            const params = new URLSearchParams(event.state.params);
            const form = document.getElementById('filter-drawer');
            
            form.querySelectorAll('input[type="text"], input[type="number"], select').forEach(el => el.value = params.get(el.name) || '');
            form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(el => {
                el.checked = params.get(el.name) === el.value;
            });
            
            applyFilters(null, true);
        }
    });

    // Flash Banner Slider Logic
    function setupBannerSlider(sliderId) {
        const slider = document.getElementById(sliderId);
        if (!slider) return;

        const track = slider.querySelector('.slider-track');
        const dots = slider.querySelectorAll('.slider-dot');
        const totalSlides = dots.length;
        let currentSlide = 0;
        let autoPlayInterval;

        if (totalSlides <= 1) return;

        function goToSlide(index) {
            currentSlide = (index + totalSlides) % totalSlides;
            track.style.transform = `translateX(-${currentSlide * 100}%)`;
            dots.forEach((dot, i) => {
                dot.className = `slider-dot h-2 rounded-full transition-all ${i === currentSlide ? 'w-8 bg-[#003e86]' : 'w-2 bg-white/60 hover:bg-white'}`;
            });
        }

        function startAutoPlay() {
            stopAutoPlay();
            autoPlayInterval = setInterval(() => goToSlide(currentSlide + 1), 4000);
        }

        function stopAutoPlay() { clearInterval(autoPlayInterval); }

        dots.forEach((dot, i) => {
            dot.addEventListener('click', () => { goToSlide(i); startAutoPlay(); });
        });

        let isDragging = false, startPos = 0, dragDist = 0;

        function getPositionX(e) { return e.type.includes('mouse') ? e.pageX : e.touches[0].clientX; }

        function dragStart(e) {
            isDragging = true;
            startPos = getPositionX(e);
            stopAutoPlay();
            track.style.transition = 'none';
            slider.style.cursor = 'grabbing';
        }

        function dragMove(e) {
            if (isDragging) {
                dragDist = getPositionX(e) - startPos;
                track.style.transform = `translateX(calc(-${currentSlide * 100}% + ${dragDist}px))`;
            }
        }

        function dragEnd() {
            if (!isDragging) return;
            isDragging = false;
            slider.style.cursor = 'grab';
            track.style.transition = 'transform 0.5s ease-out';
            if (dragDist < -50) goToSlide(currentSlide + 1);
            else if (dragDist > 50) goToSlide(currentSlide - 1);
            else goToSlide(currentSlide);
            dragDist = 0;
            startAutoPlay();
        }

        slider.addEventListener('mousedown', dragStart); slider.addEventListener('mousemove', dragMove);
        slider.addEventListener('mouseup', dragEnd); slider.addEventListener('mouseleave', dragEnd);
        slider.addEventListener('touchstart', dragStart, { passive: true }); slider.addEventListener('touchmove', dragMove, { passive: true });
        slider.addEventListener('touchend', dragEnd);
        startAutoPlay();
    }

    setupBannerSlider('flash-slider');

    // Flash Deal Timer Logic for Page
    const timerEl = document.getElementById('flash-deal-timer-page');
    if (timerEl) {
        const endTime = new Date(timerEl.getAttribute('data-endtime')).getTime();
        const timerInterval = setInterval(() => {
            const now = new Date().getTime();
            const distance = endTime - now;
            if (distance < 0) {
                clearInterval(timerInterval);
                timerEl.innerHTML = "<span class='bg-white/20 text-white px-4 py-2 rounded-xl text-sm font-bold border border-white/30'>EXPIRED</span>";
                return;
            }
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            timerEl.innerHTML = `
                <div class="bg-rose-100 text-rose-700 w-8 h-8 flex flex-col items-center justify-center rounded-md border border-rose-200"><span class="text-xs font-black leading-none">${days}</span><span class="text-[6px] uppercase font-bold tracking-wider">Days</span></div><span class="text-rose-400 font-black text-xs">:</span>
                <div class="bg-rose-100 text-rose-700 w-8 h-8 flex flex-col items-center justify-center rounded-md border border-rose-200"><span class="text-xs font-black leading-none">${hours.toString().padStart(2, '0')}</span><span class="text-[6px] uppercase font-bold tracking-wider">Hrs</span></div><span class="text-rose-400 font-black text-xs">:</span>
                <div class="bg-rose-100 text-rose-700 w-8 h-8 flex flex-col items-center justify-center rounded-md border border-rose-200"><span class="text-xs font-black leading-none">${minutes.toString().padStart(2, '0')}</span><span class="text-[6px] uppercase font-bold tracking-wider">Min</span></div><span class="text-rose-400 font-black text-xs">:</span>
                <div class="bg-rose-100 text-rose-700 w-8 h-8 flex flex-col items-center justify-center rounded-md border border-rose-200"><span class="text-xs font-black leading-none">${seconds.toString().padStart(2, '0')}</span><span class="text-[6px] uppercase font-bold tracking-wider">Sec</span></div>
            `;
        }, 1000);
    }
</script>

<?php include 'Footer.php'; ?>