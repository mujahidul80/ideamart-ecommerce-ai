<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}
require_once __DIR__ . '/../db.php';

// Fetch Products with Category, Total Stock, and Sold Count
try {
    $stmt = $pdo->query("
        SELECT p.*, c.name as category_name, 
               COALESCE(SUM(v.stock), 0) as total_stock,
               COALESCE(SUM(v.initialStock), 0) as initial_stock
        FROM Product p 
        LEFT JOIN Variant v ON p.id = v.productId 
        LEFT JOIN Category c ON p.categoryId = c.id
        GROUP BY p.id 
        ORDER BY p.id DESC
    ");
    $products = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $products = [];
}

// Extract unique categories for the filter dropdown
$uniqueCategories = array_unique(array_filter(array_column($products, 'category_name')));
sort($uniqueCategories);

// Fetch all active categories for bulk update
try {
    $catStmt = $pdo->query("SELECT id, name FROM Category WHERE status = 1 ORDER BY name ASC");
    $allCategories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $allCategories = [];
}

ob_start();
?>

<div class="max-w-7xl mx-auto font-sans pb-12" id="products-app">
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="px-6 sm:px-8 py-6 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="package" class="w-6 h-6 text-blue-600"></i> Product Catalog
                </h2>
                <p class="text-sm text-slate-500 mt-1">Manage your store inventory, pricing, and availability.</p>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="openAiCatModal()" class="bg-indigo-50 border border-indigo-200 hover:bg-indigo-100 text-indigo-700 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 text-sm whitespace-nowrap">
                    <i data-lucide="sparkles" class="w-4 h-4"></i> AI Categorize
                </button>
                <a href="add-product" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-semibold shadow-lg transition-all flex items-center gap-2 text-sm whitespace-nowrap">
                    <i data-lucide="plus" class="w-4 h-4"></i> 
                    <span>Add Product</span>
                </a>
            </div>
        </div>

        <!-- Filters & Search Bar -->
        <div class="p-4 sm:px-6 flex flex-col lg:flex-row gap-4 justify-between items-center bg-white border-b border-slate-100">
            <div class="flex items-center gap-3 w-full lg:w-auto">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Show</span>
                <select id="per-page" onchange="currentPage = 1; filterProducts()" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer shadow-sm">
                    <option value="10">10</option>
                    <option value="15" selected>15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden sm:inline">Entries</span>
                
                <!-- Bulk Actions (Hidden by default) -->
                <div class="hidden items-center gap-2 ml-4 animate-in fade-in slide-in-from-left-4" id="bulk-actions">
                    <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2.5 py-1.5 rounded-lg"><span id="selected-count">0</span> Selected</span>
                    <select id="bulk-category-select" class="px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer shadow-sm max-w-[140px] truncate">
                        <option value="">Move to...</option>
                        <?php foreach($allCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="bulkUpdateCategory()" class="px-2.5 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white rounded-lg text-xs font-bold transition-colors border border-indigo-200 hover:border-indigo-600 shadow-sm flex items-center gap-1.5" title="Move to Category"><i data-lucide="folder-input" class="w-3.5 h-3.5"></i> Apply</button>
                    <button onclick="bulkDeleteProducts()" class="px-2.5 py-1.5 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-lg text-xs font-bold transition-colors border border-rose-200 hover:border-rose-600 shadow-sm flex items-center gap-1.5" title="Delete Selected"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Delete</button>
                </div>
            </div>
            
            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                <div class="relative w-full sm:w-64 shrink-0 mb-3 lg:mb-0">
                    <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 w-4.5 h-4.5"></i>
                    <input type="text" id="product-search" oninput="currentPage = 1; filterProducts()" placeholder="Search product name or SKU ID..." class="pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none w-full transition-all shadow-sm" />
                </div>
                <select id="filter-category" onchange="filterProducts()" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer transition-all shadow-sm">
                    <option value="">All Categories</option>
                    <?php foreach($uniqueCategories as $uc): ?>
                        <option value="<?php echo htmlspecialchars($uc); ?>"><?php echo htmlspecialchars($uc); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filter-status" onchange="filterProducts()" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer transition-all shadow-sm">
                    <option value="">All Stock Status</option>
                    <option value="In Stock">In Stock (> 10)</option>
                    <option value="Low Stock">Low Stock (1-10)</option>
                    <option value="Out of Stock">Out of Stock (0)</option>
                </select>

                <select id="filter-type" onchange="filterProducts()" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer transition-all shadow-sm">
                    <option value="">All Types</option>
                    <option value="Standard">Standard</option>
                    <option value="Flash">Flash Deals</option>
                </select>
            </div>
        </div>

        <!-- Products Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-[11px] uppercase tracking-widest text-slate-500">
                        <th class="p-5 sm:px-6 font-bold w-12 text-center">
                            <input type="checkbox" id="select-all" onchange="toggleAll(this)" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        </th>
                        <th class="p-5 sm:px-6 font-bold w-12 text-center">SL</th>
                        <th class="p-5 font-bold">Product Details</th>
                        <th class="p-5 font-bold">Category</th>
                        <th class="p-5 font-bold">Pricing</th>
                        <th class="p-5 font-bold">Inventory</th>
                        <th class="p-5 sm:px-6 font-bold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-slate-700" id="products-table-body">
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="p-12 text-center">
                                <i data-lucide="package-x" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                                <p class="text-slate-500 font-medium">No products found in the catalog.</p>
                                <a href="add-product" class="text-blue-600 hover:underline font-bold mt-2 inline-block">Add your first product</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $serial = 1; ?>
                        <?php foreach ($products as $product): ?>
                            <?php 
                            $sold = max(0, $product['initial_stock'] - $product['total_stock']); 
                            $stockStatusColor = $product['total_stock'] > 10 ? 'emerald' : ($product['total_stock'] > 0 ? 'amber' : 'rose');
                            $stockStatusText = $product['total_stock'] > 10 ? 'In Stock' : ($product['total_stock'] > 0 ? 'Low Stock' : 'Out of Stock');
                            ?>
                            <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors product-row" 
                                data-search="<?php echo strtolower($product['name'] . ' ' . $product['slug'] . ' ' . $product['sku']); ?>"
                                data-category="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>"
                                data-status="<?php echo $stockStatusText; ?>"
                                data-type="<?php echo $product['isFlashDeal'] ? 'Flash' : 'Standard'; ?>"
                            >
                                <td class="p-5 sm:px-6 text-center">
                                    <input type="checkbox" class="product-checkbox w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer" value="<?php echo $product['id']; ?>" onchange="updateBulkActions()">
                                </td>
                                <td class="p-5 sm:px-6 text-center font-bold text-slate-400 serial-number"><?php echo $serial++; ?></td>
                                <td class="p-5 sm:px-8">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-xl bg-slate-100 border border-slate-200 overflow-hidden shrink-0">
                                            <?php $firstImage = $product['imageUrl'] ? explode(',', $product['imageUrl'])[0] : ''; ?>
                                            <img src="<?php echo $firstImage ? '..' . $firstImage : 'https://placehold.co/100x100?text=No+Image'; ?>" class="w-full h-full object-cover">
                                        </div>
                                        <div class="min-w-0 max-w-[200px] sm:max-w-xs">
                                            <p class="font-bold text-slate-800 truncate" title="<?php echo htmlspecialchars($product['name']); ?>"><?php echo htmlspecialchars($product['name']); ?></p>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-[10px] font-mono font-bold bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded">SKU: <?php echo htmlspecialchars($product['sku'] ?: $product['slug']); ?></span>
                                                <?php if ($product['isFlashDeal']): ?>
                                                    <span class="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded flex items-center gap-1">
                                                        <i data-lucide="zap" class="w-3 h-3"></i> Flash
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-5">
                                    <?php if ($product['category_name']): ?>
                                        <span class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold border border-indigo-100 whitespace-nowrap">
                                            <?php echo htmlspecialchars($product['category_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400 italic">Uncategorized</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-5">
                                    <div class="flex flex-col gap-0.5">
                                        <?php if (!empty($product['buyPrice'])): ?>
                                            <span class="text-[10px] font-bold text-slate-500">Buy: ৳<?php echo number_format($product['buyPrice']); ?></span>
                                        <?php endif; ?>
                                        <span class="font-black text-slate-900 text-sm">Sell: ৳<?php echo number_format($product['basePrice']); ?></span>
                                        <?php if ($product['isFlashDeal'] && $product['flashDealPrice']): ?>
                                            <span class="text-[10px] font-bold text-rose-500 line-through">৳<?php echo number_format($product['flashDealPrice']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-5">
                                    <div class="flex flex-col gap-1.5">
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-1 rounded border border-<?php echo $stockStatusColor; ?>-200 bg-<?php echo $stockStatusColor; ?>-50 text-<?php echo $stockStatusColor; ?>-700 text-[10px] font-bold uppercase tracking-widest flex items-center gap-1.5 w-fit whitespace-nowrap">
                                                <div class="w-1.5 h-1.5 rounded-full bg-<?php echo $stockStatusColor; ?>-500 <?php echo $product['total_stock'] <= 10 && $product['total_stock'] > 0 ? 'animate-pulse' : ''; ?>"></div>
                                                <?php echo $stockStatusText; ?>: <?php echo $product['total_stock']; ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] font-bold text-slate-500">Sold: <span class="text-blue-600"><?php echo $sold; ?> units</span></p>
                                    </div>
                                </td>
                                <td class="p-5 sm:px-6 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <?php if (!empty($product['source_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($product['source_url']); ?>" target="_blank" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="View Original Product">
                                                <i data-lucide="link" class="w-4.5 h-4.5"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="../product?slug=<?php echo $product['slug']; ?>" target="_blank" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="View in Store">
                                            <i data-lucide="external-link" class="w-4.5 h-4.5"></i>
                                        </a>
                                        <a href="edit-product?id=<?php echo $product['id']; ?>" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Edit">
                                            <i data-lucide="edit-3" class="w-4.5 h-4.5"></i>
                                        </a>
                                        <button onclick="deleteProduct(<?php echo $product['id']; ?>)" class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Delete">
                                            <i data-lucide="trash-2" class="w-4.5 h-4.5"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination UI -->
        <div id="pagination-controls" class="p-4 sm:px-8 border-t border-slate-100 bg-slate-50 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-xs font-bold text-slate-500" id="pagination-info">Showing 0 to 0 of 0 entries</p>
            <div class="flex items-center gap-2" id="pagination-buttons">
                <!-- JS Injected Buttons -->
            </div>
        </div>
    </div>
</div>

<!-- AI Categorize Modal -->
<div id="ai-cat-modal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200 flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 shrink-0">
            <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                <i data-lucide="sparkles" class="w-5 h-5 text-indigo-600"></i> AI Smart Categorization
            </h2>
            <button onclick="closeAiCatModal()" id="ai-cat-close-btn" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6 flex flex-col flex-1 overflow-hidden">
            <p class="text-sm text-slate-600 mb-4">This tool will find all uncategorized products and use AI (Gemini/OpenAI) to automatically assign them to the most relevant existing category from your store.</p>
            
            <label class="flex items-center gap-2 mb-4 cursor-pointer">
                <input type="checkbox" id="ai-cat-force-all" class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                <span class="text-sm font-semibold text-slate-700">Force re-categorize <span class="font-bold text-rose-500">ALL</span> products (including categorized ones)</span>
            </label>

            <div class="bg-slate-900 rounded-xl p-4 flex-1 overflow-y-auto custom-scrollbar font-mono text-xs text-slate-300 min-h-[250px] leading-relaxed" id="ai-cat-log">
                Waiting to start...
            </div>
        </div>
        <div class="p-5 border-t border-slate-100 bg-white flex justify-end gap-3 shrink-0">
            <button type="button" onclick="closeAiCatModal()" id="ai-cat-cancel-btn" class="px-5 py-3 text-sm font-bold text-slate-600 bg-slate-100 rounded-xl hover:bg-slate-200 transition-colors">Close</button>
            <button type="button" onclick="startAiCategorize()" id="ai-cat-start-btn" class="px-6 py-3 text-sm font-bold text-white bg-indigo-600 rounded-xl shadow-md hover:bg-indigo-700 transition-all flex items-center gap-2">
                <i data-lucide="play" class="w-4 h-4"></i> Start Process
            </button>
        </div>
    </div>
</div>

<script>
// Search & Filter Logic
let currentPage = 1;
let selectedIds = [];

function filterProducts() {
    const term = document.getElementById('product-search').value.toLowerCase();
    const category = document.getElementById('filter-category').value;
    const status = document.getElementById('filter-status').value;
    const type = document.getElementById('filter-type').value;
    const perPage = parseInt(document.getElementById('per-page').value);

    const allRows = Array.from(document.querySelectorAll('.product-row'));
    let visibleRows = [];

    allRows.forEach(row => {
        const rowSearch = row.getAttribute('data-search');
        const rowCategory = row.getAttribute('data-category');
        const rowStatus = row.getAttribute('data-status');
        const rowType = row.getAttribute('data-type');

        const matchSearch = term === '' || rowSearch.includes(term);
        const matchCategory = category === '' || rowCategory === category;
        const matchStatus = status === '' || rowStatus === status;
        const matchType = type === '' || rowType === type;

        if (matchSearch && matchCategory && matchStatus && matchType) {
            visibleRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / perPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    const start = (currentPage - 1) * perPage;
    const end = start + perPage;

    visibleRows.forEach((row, index) => {
        if (index >= start && index < end) {
            row.style.display = '';
            row.querySelector('.serial-number').innerText = index + 1;
        } else {
            row.style.display = 'none';
        }
    });

    renderPagination(totalItems, start, Math.min(end, totalItems), totalPages);
    
    // Reset bulk selection on filter/pagination change
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    checkboxes.forEach(cb => {
        if(cb.closest('tr').style.display !== 'none') {
            cb.checked = source.checked;
        }
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.product-checkbox:checked');
    selectedIds = Array.from(checkboxes).map(cb => cb.value);
    
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    
    if (selectedIds.length > 0) {
        bulkActions.classList.remove('hidden');
        bulkActions.classList.add('flex');
        selectedCount.innerText = selectedIds.length;
    } else {
        bulkActions.classList.add('hidden');
        bulkActions.classList.remove('flex');
        document.getElementById('select-all').checked = false;
    }
}

function renderPagination(total, start, end, totalPages) {
    document.getElementById('pagination-info').innerText = `Showing ${total > 0 ? start + 1 : 0} to ${end} of ${total} entries`;
    
    let btnsHtml = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-sm font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition-all">Prev</button>`;
    
    for(let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
            btnsHtml += `<button onclick="changePage(${i})" class="px-3 py-1.5 rounded-lg border ${currentPage === i ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'} text-sm font-bold transition-all w-9">${i}</button>`;
        } else if (i === currentPage - 2 || i === currentPage + 2) {
            btnsHtml += `<span class="px-1 text-slate-400">...</span>`;
        }
    }
    
    btnsHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-sm font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition-all">Next</button>`;
    
    document.getElementById('pagination-buttons').innerHTML = btnsHtml;
}

function changePage(page) {
    currentPage = page;
    filterProducts();
}

async function deleteProduct(id) {
    customConfirm("Are you sure you want to delete this product? All its variants and images will also be removed.", async () => {
        try {
            const res = await fetch(`../api/delete_product.php?id=${id}`, { method: 'DELETE' });
            const result = await res.json();
            if (result.success) {
                location.reload();
            } else {
                showToast(result.error || "Failed to delete product.", "error");
            }
        } catch(e) { 
            console.error(e);
            showToast("Error: " + (e.message || "Network error"), "error"); 
        }
    });
}

async function bulkDeleteProducts() {
    if (selectedIds.length === 0) return;
    
    customConfirm(`Are you sure you want to delete ${selectedIds.length} selected products? All variants and images will be removed.`, async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'bulk_delete');
            formData.append('product_ids', JSON.stringify(selectedIds));

            const res = await fetch('../api/delete_product.php', { method: 'POST', body: formData });
            const result = await res.json();
            
            if (result.success) {
                showToast(`Products deleted successfully.`, "success");
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || "Failed to delete products.", "error");
            }
        } catch(e) { 
            console.error(e);
            showToast("Error: " + (e.message || "Network error"), "error"); 
        }
    });
}

async function bulkUpdateCategory() {
    if (selectedIds.length === 0) return;
    
    const categoryId = document.getElementById('bulk-category-select').value;
    if (!categoryId) {
        if(window.showToast) showToast("Please select a category to move to.", "error");
        return;
    }
    
    customConfirm(`Are you sure you want to move ${selectedIds.length} selected products to this category?`, async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'bulk_category_update');
            formData.append('product_ids', JSON.stringify(selectedIds));
            formData.append('category_id', categoryId);

            const res = await fetch('../api/update_product_category.php', { method: 'POST', body: formData });
            const result = await res.json();
            
            if (result.success) {
                showToast(`Products moved to new category successfully.`, "success");
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || "Failed to update products.", "error");
            }
        } catch(e) { 
            console.error(e);
            showToast("Error: " + (e.message || "Network error"), "error"); 
        }
    });
}

// AI Categorization Logic
let aiCatEventSource = null;
let lastAiProcessedId = 0;

function openAiCatModal() {
    document.getElementById('ai-cat-log').innerHTML = 'Ready to start...<br>';
    document.getElementById('ai-cat-modal').classList.remove('hidden');
    document.getElementById('ai-cat-modal').classList.add('flex');
    lastAiProcessedId = 0;
    const startBtn = document.getElementById('ai-cat-start-btn');
    startBtn.innerHTML = '<i data-lucide="play" class="w-4 h-4"></i> Start Process';
    startBtn.classList.remove('hidden');
    startBtn.disabled = false;
    lucide.createIcons();
}

function closeAiCatModal() {
    if (aiCatEventSource) aiCatEventSource.close();
    document.getElementById('ai-cat-modal').classList.add('hidden');
    document.getElementById('ai-cat-modal').classList.remove('flex');
    if (document.getElementById('ai-cat-log').innerHTML.includes('Done!')) {
        location.reload();
    }
}

function startAiCategorize() {
    const logBox = document.getElementById('ai-cat-log');
    const startBtn = document.getElementById('ai-cat-start-btn');
    const closeBtn = document.getElementById('ai-cat-close-btn');
    const cancelBtn = document.getElementById('ai-cat-cancel-btn');
    const forceAll = document.getElementById('ai-cat-force-all').checked ? 1 : 0;

    const isRetry = startBtn.innerText.includes('Retry');
    if (!isRetry) lastAiProcessedId = 0;

    logBox.innerHTML += '<span class="text-blue-400 mt-2 block">Connecting to AI Server...</span>';
    startBtn.disabled = true; closeBtn.disabled = true; cancelBtn.disabled = true;
    startBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Processing...';
    lucide.createIcons();

    if (aiCatEventSource) aiCatEventSource.close();
    aiCatEventSource = new EventSource('../api/ajax_ai_categorize.php?force_all=' + forceAll + '&resume_id=' + lastAiProcessedId);

    aiCatEventSource.addEventListener('log', function(e) {
        const data = JSON.parse(e.data);
        if (data.processed_id) {
            lastAiProcessedId = data.processed_id;
        }
        logBox.innerHTML += `<div>${data.message}</div>`;
        logBox.scrollTop = logBox.scrollHeight;
    });

    aiCatEventSource.addEventListener('complete', function(e) {
        startBtn.classList.add('hidden');
        closeBtn.disabled = false; cancelBtn.disabled = false;
        cancelBtn.innerText = 'Close & Reload';
        aiCatEventSource.close();
    });

    aiCatEventSource.onerror = function(e) {
        if (aiCatEventSource.readyState !== EventSource.CLOSED) {
            logBox.innerHTML += `<div class="text-rose-400 mt-2 font-bold">Error: Connection lost. You can retry to continue from where it stopped.</div>`;
            logBox.scrollTop = logBox.scrollHeight;
            closeBtn.disabled = false; cancelBtn.disabled = false; startBtn.disabled = false;
            startBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Retry';
            lucide.createIcons();
            aiCatEventSource.close();
        }
    };
}

document.addEventListener('DOMContentLoaded', filterProducts);
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>