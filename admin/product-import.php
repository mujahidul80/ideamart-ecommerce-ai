<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}
require_once __DIR__ . '/../db.php';

try { $pdo->exec("ALTER TABLE `SettingApi` ADD COLUMN `dropshopAdvanceAmount` DECIMAL(10,2) DEFAULT 200.00"); } catch (Exception $e) {}

// Handle AJAX Save Cookie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cookie') {
    header('Content-Type: application/json');
    try {
        $cookie = $_POST['dropshopCookie'] ?? '';
        $advanceAmount = !empty($_POST['dropshopAdvanceAmount']) ? floatval($_POST['dropshopAdvanceAmount']) : 200.00;
        try { $pdo->exec("ALTER TABLE `SettingApi` ADD COLUMN `dropshopCookie` TEXT NULL"); } catch (Exception $e) {}
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM SettingApi");
        if ($stmt->fetchColumn() == 0) { $pdo->exec("INSERT INTO SettingApi (id) VALUES (1)"); }
        
        $stmt = $pdo->prepare("UPDATE SettingApi SET dropshopCookie = ?, dropshopAdvanceAmount = ? WHERE id = 1");
        $stmt->execute([$cookie, $advanceAmount]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle Stop Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stop_import') {
    header('Content-Type: application/json');
    $_SESSION['import_stop'] = true;
    echo json_encode(['success' => true]);
    exit;
}

// Fetch current cookie
try {
    $apiStmt = $pdo->query("SELECT dropshopCookie, dropshopAdvanceAmount FROM SettingApi LIMIT 1");
    $apiSettings = $apiStmt->fetch(PDO::FETCH_ASSOC);
    $currentCookie = $apiSettings['dropshopCookie'] ?? '';
    $currentAdvanceAmount = $apiSettings['dropshopAdvanceAmount'] ?? '200.00';
} catch (Exception $e) { 
    $currentCookie = ''; 
    $currentAdvanceAmount = '200.00'; 
}

ob_start();
?>

<div class="max-w-7xl mx-auto font-sans pb-12" id="import-app">
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden animate-in fade-in slide-in-from-bottom-4 duration-500">
        
        <!-- Header -->
        <div class="px-6 sm:px-8 py-6 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="download-cloud" class="w-6 h-6 text-blue-600"></i> Dropshop Product Importer
                </h2>
                <p class="text-sm text-slate-500 mt-1">Automatically import products from Dropshop.com.bd</p>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="startSync()" id="start-sync-btn" class="bg-indigo-50 border border-indigo-200 hover:bg-indigo-100 text-indigo-700 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 text-sm">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i> Sync Existing
                </button>
                <a href="products" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 px-5 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 text-sm">
                    <i data-lucide="list" class="w-4 h-4"></i> View Products
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 p-6 sm:p-8">
            <!-- Left: Controls & Progress -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <h3 class="text-sm font-bold text-slate-800 mb-4">Configuration & Controls</h3>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase">Import Source</label>
                            <select id="import-source" onchange="updateSourceDefaults()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500/20 text-slate-700 cursor-pointer">
                                <option value="othoba">Othoba.com</option>
                                <option value="dropshop">Dropshop.com.bd</option>
                            </select>
                        </div>

                        <div class="space-y-2" id="dropshop-cookie-container" style="display: none;">
                            <label class="text-xs font-bold text-slate-500 uppercase">Dropshop Logged-in Cookie</label>
                            <textarea id="dropshop-cookie" rows="3" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono outline-none focus:ring-2 focus:ring-blue-500/20" placeholder="wordpress_logged_in_xxx=yyy; woocommerce_items_in_cart=1;"><?php echo htmlspecialchars($currentCookie); ?></textarea>
                            <p class="text-[10px] text-slate-400">Required to bypass login and fetch wholesale prices.</p>
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase">Advance Payment Amount (৳)</label>
                            <input type="number" step="1" id="dropshop-advance-amount" value="<?php echo htmlspecialchars($currentAdvanceAmount); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500/20 text-slate-700">
                            <p class="text-[10px] text-slate-400">Minimum advance required for these imported products.</p>
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase">Target URL (Category or Shop Page)</label>
                            <input type="url" id="import-url" value="https://dropshop.com.bd/shop/" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500/20 text-slate-700">
                            <p class="text-[10px] text-slate-400">Default is all products. You can paste a specific category link here.</p>
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase">Products to Import</label>
                            <input type="number" id="import-count" value="10" min="1" max="100" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl font-bold text-center text-lg outline-none focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div class="flex gap-2">
                            <button onclick="startImport()" id="start-import-btn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-2 text-sm active:scale-95 disabled:bg-slate-300">
                                <i data-lucide="play" class="w-4 h-4"></i> Start
                            </button>
                            <button onclick="pauseImport()" id="pause-import-btn" class="hidden flex-1 bg-amber-500 hover:bg-amber-600 text-white px-4 py-3 rounded-xl font-bold shadow-lg shadow-amber-900/20 transition-all flex items-center justify-center gap-2 text-sm active:scale-95">
                                <i data-lucide="pause" class="w-4 h-4"></i> Pause
                            </button>
                            <button onclick="resumeImport()" id="resume-import-btn" class="hidden flex-1 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-3 rounded-xl font-bold shadow-lg shadow-emerald-900/20 transition-all flex items-center justify-center gap-2 text-sm active:scale-95">
                                <i data-lucide="play-circle" class="w-4 h-4"></i> Resume
                            </button>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <h3 class="text-sm font-bold text-slate-800 mb-4">Import Log</h3>
                    <div id="progress-log" class="h-64 bg-slate-900 text-white font-mono text-xs rounded-xl p-4 overflow-y-auto custom-scrollbar flex flex-col-reverse"></div>
                </div>
            </div>

            <!-- Right: Results -->
            <div class="lg:col-span-3 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-slate-800" id="imported-count-title">Imported Products (0)</h3>
                    <div id="publish-actions" class="hidden items-center gap-2">
                        <button id="btn-publish-live" onclick="publishSelected('live')" class="px-3 py-1.5 bg-emerald-50 text-emerald-600 text-xs font-bold rounded-lg hover:bg-emerald-100">Publish Selected</button>
                        <button id="btn-publish-draft" onclick="publishSelected('draft')" class="px-3 py-1.5 bg-rose-50 text-rose-600 text-xs font-bold rounded-lg hover:bg-rose-100">Unpublish Selected</button>
                        <button id="btn-update-synced" onclick="updateSyncedSelected()" class="hidden px-3 py-1.5 bg-indigo-50 text-indigo-600 text-xs font-bold rounded-lg hover:bg-indigo-100">Update Selected</button>
                    </div>
                </div>
                <div id="results-container" class="space-y-3 max-h-[75vh] overflow-y-auto custom-scrollbar pr-2">
                    <div class="text-center py-10 text-slate-400">
                        <i data-lucide="list-checks" class="w-10 h-10 mx-auto mb-2"></i>
                        <p class="text-sm font-medium">Imported products will appear here.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
let importedProducts = [];
let importEventSource = null;
let syncEventSource = null;
let syncedProducts = [];
let syncItemIndex = 0;

function updateSourceDefaults() {
    const source = document.getElementById('import-source').value;
    const urlInput = document.getElementById('import-url');
    const cookieContainer = document.getElementById('dropshop-cookie-container');

    if (source === 'othoba') {
        urlInput.value = 'https://www.othoba.com/electronics';
        cookieContainer.style.display = 'none';
    } else { // dropshop
        urlInput.value = 'https://dropshop.com.bd/shop/';
        cookieContainer.style.display = 'block';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize with Othoba as default
    updateSourceDefaults();
});


async function startImport(isResume = false) {
    const count = document.getElementById('import-count').value;
    const cookie = document.getElementById('dropshop-cookie').value;
    const advanceAmount = document.getElementById('dropshop-advance-amount').value;
    const targetUrl = document.getElementById('import-url').value;
    const source = document.getElementById('import-source').value;
    const log = document.getElementById('progress-log');
    const resultsContainer = document.getElementById('results-container');
    const startBtn = document.getElementById('start-import-btn');
    const pauseBtn = document.getElementById('pause-import-btn');
    const resumeBtn = document.getElementById('resume-import-btn');

    if (!isResume) {
        log.innerHTML = '';
        resultsContainer.innerHTML = '<div class="text-center py-10 text-slate-400"><i data-lucide="loader-2" class="w-10 h-10 mx-auto mb-2 animate-spin"></i><p class="text-sm font-medium">Preparing...</p></div>';
        importedProducts = [];
        document.getElementById('imported-count-title').innerText = `Imported Products (0)`;
        document.getElementById('publish-actions').classList.add('hidden');
        document.getElementById('publish-actions').classList.remove('flex');
        
        const btnPubLive = document.getElementById('btn-publish-live');
        const btnPubDraft = document.getElementById('btn-publish-draft');
        const btnUpdateSync = document.getElementById('btn-update-synced');
        if(btnPubLive) btnPubLive.classList.remove('hidden');
        if(btnPubDraft) btnPubDraft.classList.remove('hidden');
        if(btnUpdateSync) btnUpdateSync.classList.add('hidden');
    } else {
        log.innerHTML += `<p class="text-blue-400 font-bold">Resuming import...</p>`;
        log.scrollTop = log.scrollHeight;
    }

    startBtn.classList.add('hidden');
    resumeBtn.classList.add('hidden');
    pauseBtn.classList.remove('hidden');
    pauseBtn.disabled = false;
    lucide.createIcons();
    if (syncEventSource) syncEventSource.close();

    try {
        const formData = new FormData();
        formData.append('action', 'save_cookie');
        formData.append('dropshopCookie', cookie);
        formData.append('dropshopAdvanceAmount', advanceAmount);
        const res = await fetch('product-import.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (!result.success) {
            showToast("Failed to save cookie.", "error");
            pauseBtn.classList.add('hidden');
            startBtn.classList.remove('hidden');
            startBtn.disabled = false;
            if (!isResume) resultsContainer.innerHTML = '';
            return;
        }
    } catch (e) {
        showToast("Network error saving cookie.", "error");
        pauseBtn.classList.add('hidden');
        startBtn.classList.remove('hidden');
        startBtn.disabled = false;
        if (!isResume) resultsContainer.innerHTML = '';
        return;
    }

    if (!isResume) {
        resultsContainer.innerHTML = '<div class="text-center py-10 text-slate-400"><i data-lucide="loader-2" class="w-10 h-10 mx-auto mb-2 animate-spin"></i><p class="text-sm font-medium">Importing...</p></div>';
        lucide.createIcons();
    }

    if (importEventSource) {
        importEventSource.close();
    }
    
    let url = `../api/ajax_import.php?source=${source}&count=${count}&url=${encodeURIComponent(targetUrl)}`;
    if (isResume) url += '&resume=1';
    importEventSource = new EventSource(url);

    importEventSource.addEventListener('log', function(e) {
        const data = JSON.parse(e.data);
        log.innerHTML += `<p>${data.message}</p>`;
        log.scrollTop = log.scrollHeight;
    });

    importEventSource.addEventListener('product', function(e) {
        const data = JSON.parse(e.data);
        if (!importedProducts.find(p => p.id === data.product.id)) {
            if (importedProducts.length === 0) resultsContainer.innerHTML = '';
            importedProducts.push(data.product);
            resultsContainer.innerHTML += renderProductRow(data.product);
            document.getElementById('imported-count-title').innerText = `Imported Products (${importedProducts.length})`;
            lucide.createIcons();
            document.getElementById('publish-actions').classList.remove('hidden');
            document.getElementById('publish-actions').classList.add('flex');
        }
    });

    importEventSource.addEventListener('paused', function(e) {
        pauseBtn.classList.add('hidden');
        pauseBtn.innerHTML = '<i data-lucide="pause" class="w-4 h-4"></i> Pause';
        resumeBtn.classList.remove('hidden');
        if (importedProducts.length > 0) {
            document.getElementById('publish-actions').classList.remove('hidden');
            document.getElementById('publish-actions').classList.add('flex');
        }
        importEventSource.close();
    });

    importEventSource.addEventListener('complete', function(e) {
        const data = JSON.parse(e.data);
        log.innerHTML += `<p class="text-emerald-400 font-bold">${data.message}</p>`;
        log.scrollTop = log.scrollHeight;
        pauseBtn.classList.add('hidden');
        startBtn.classList.remove('hidden');
        startBtn.disabled = false;
        if (importedProducts.length > 0) {
            document.getElementById('publish-actions').classList.remove('hidden');
            document.getElementById('publish-actions').classList.add('flex');
        }
        importEventSource.close();
    });

    importEventSource.onerror = function(e) {
        if (importEventSource.readyState !== EventSource.CLOSED) {
            log.innerHTML += `<p class="text-rose-400 font-bold">Error: Connection to server lost.</p>`;
            pauseBtn.classList.add('hidden');
            startBtn.classList.remove('hidden');
            startBtn.disabled = false;
            if (importedProducts.length > 0) {
                document.getElementById('publish-actions').classList.remove('hidden');
                document.getElementById('publish-actions').classList.add('flex');
            }
            importEventSource.close();
        }
    };
}

async function pauseImport() {
    const pauseBtn = document.getElementById('pause-import-btn');
    pauseBtn.disabled = true;
    pauseBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Pausing...';
    lucide.createIcons();

    try {
        const formData = new FormData();
        formData.append('action', 'stop_import');
        await fetch('product-import.php', { method: 'POST', body: formData });
    } catch (e) {
        console.error(e);
    }
}

function resumeImport() {
    startImport(true);
}

async function startSync() {
    const cookie = document.getElementById('dropshop-cookie').value;
    const advanceAmount = document.getElementById('dropshop-advance-amount').value;
    const log = document.getElementById('progress-log');
    const resultsContainer = document.getElementById('results-container');
    const startBtn = document.getElementById('start-import-btn');
    const syncBtn = document.getElementById('start-sync-btn');

    try {
        const formData = new FormData();
        formData.append('action', 'save_cookie');
        formData.append('dropshopCookie', cookie);
        formData.append('dropshopAdvanceAmount', advanceAmount);
        const res = await fetch('product-import.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (!result.success) {
            showToast("Failed to save cookie.", "error");
            return;
        }
    } catch (e) {
        showToast("Network error saving cookie.", "error");
        return;
    }

    log.innerHTML = '';
    resultsContainer.innerHTML = '<div class="text-center py-10 text-slate-400"><i data-lucide="loader-2" class="w-10 h-10 mx-auto mb-2 animate-spin"></i><p class="text-sm font-medium">Fetching existing dropshop products...</p></div>';
    lucide.createIcons();
    
    const btnPubLive = document.getElementById('btn-publish-live');
    const btnPubDraft = document.getElementById('btn-publish-draft');
    const btnUpdateSync = document.getElementById('btn-update-synced');
    if(btnPubLive) btnPubLive.classList.add('hidden');
    if(btnPubDraft) btnPubDraft.classList.add('hidden');
    if(btnUpdateSync) btnUpdateSync.classList.remove('hidden');
    
    document.getElementById('publish-actions').classList.add('hidden');
    document.getElementById('publish-actions').classList.remove('flex');
    
    syncedProducts = [];
    syncItemIndex = 0;
    document.getElementById('imported-count-title').innerText = `Fetched Products (0)`;

    startBtn.disabled = true;
    syncBtn.disabled = true;
    syncBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Fetching...';
    lucide.createIcons();

    if (importEventSource) importEventSource.close();
    if (syncEventSource) syncEventSource.close();

    syncEventSource = new EventSource('../api/ajax_sync_dropshop.php');

    syncEventSource.addEventListener('log', function(e) {
        const data = JSON.parse(e.data);
        log.innerHTML += `<p>${data.message}</p>`;
        log.scrollTop = log.scrollHeight;
    });

    syncEventSource.addEventListener('sync_product', function(e) {
        const data = JSON.parse(e.data);
        data.product.index = syncItemIndex++;
        syncedProducts.push(data.product);
        if (syncedProducts.length === 1) resultsContainer.innerHTML = '';
        resultsContainer.innerHTML += renderSyncProductRow(data.product);
        document.getElementById('imported-count-title').innerText = `Fetched Products (${syncedProducts.length})`;
        lucide.createIcons();
        
        document.getElementById('publish-actions').classList.remove('hidden');
        document.getElementById('publish-actions').classList.add('flex');
    });

    syncEventSource.addEventListener('complete', function(e) {
        const data = JSON.parse(e.data);
        log.innerHTML += `<p class="text-emerald-400 font-bold">${data.message}</p>`;
        log.scrollTop = log.scrollHeight;
        startBtn.disabled = false;
        syncBtn.disabled = false;
        syncBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Sync Existing';
        lucide.createIcons();
        syncEventSource.close();
    });

    syncEventSource.onerror = function(e) {
        if (syncEventSource.readyState !== EventSource.CLOSED) {
            log.innerHTML += `<p class="text-rose-400 font-bold">Error: Connection to server lost.</p>`;
            startBtn.disabled = false;
            syncBtn.disabled = false;
            syncBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Sync Existing';
            lucide.createIcons();
            syncEventSource.close();
        }
    };
}

function renderProductRow(product) {
    const badge = product.in_main_db 
        ? `<span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[9px] font-black uppercase ml-2 border border-amber-200">In Shop (Sync)</span>` 
        : `<span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[9px] font-black uppercase ml-2 border border-emerald-200">New Product</span>`;
        
    let firstImg = product.image ? product.image.split(',')[0] : 'https://placehold.co/100';

    return `
    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex items-center gap-3">
            <input type="checkbox" class="product-checkbox w-5 h-5 shrink-0 accent-blue-600 cursor-pointer" value="${product.id}" checked>
            <img src="${firstImg}" class="w-14 h-14 rounded-lg object-cover border shrink-0 bg-white">
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate mb-2" title="${product.title}">${product.title} ${badge}</p>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200" title="Buying Price">Buy ৳${product.original_price || 0}</span>
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200" title="Selling Price">Sell ৳</span>
                    <input type="number" id="price-${product.id}" value="${product.price}" class="w-24 px-2 py-1.5 text-sm font-black text-[#003e86] outline-none">
                </div>
                <div class="flex items-center bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200">Qty</span>
                    <input type="number" id="qty-${product.id}" value="${product.stock || 10}" class="w-20 px-2 py-1.5 text-sm font-black text-emerald-600 outline-none text-center">
                </div>
            </div>
        </div>
        <a href="${product.source_url}" target="_blank" class="p-2 text-slate-400 hover:text-blue-600 shrink-0 self-start sm:self-center"><i data-lucide="external-link" class="w-5 h-5"></i></a>
    </div>
    `;
}

function renderSyncProductRow(product) {
    const badge = product.is_out_of_stock 
        ? `<span class="bg-rose-100 text-rose-700 px-2 py-0.5 rounded text-[9px] font-black uppercase ml-2 border border-rose-200">Out of Stock</span>` 
        : `<span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[9px] font-black uppercase ml-2 border border-emerald-200">In Stock</span>`;
        
    let firstImg = product.image ? product.image.split(',')[0] : 'https://placehold.co/100';
    if(firstImg && !firstImg.startsWith('http')) firstImg = '..' + firstImg;

    return `
    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex items-center gap-3">
            <input type="checkbox" class="sync-product-checkbox w-5 h-5 shrink-0 accent-indigo-600 cursor-pointer" data-index="${product.index}" value="${product.source_url}" checked>
            <img src="${firstImg}" class="w-14 h-14 rounded-lg object-cover border shrink-0 bg-white">
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate mb-2" title="${product.title}">${product.title} ${badge}</p>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200" title="Old Price">Old ৳${product.old_price || 0}</span>
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200" title="New Selling Price">New ৳</span>
                    <input type="number" id="sync-price-${product.index}" value="${product.new_price}" class="w-24 px-2 py-1.5 text-sm font-black text-indigo-600 outline-none">
                    <input type="hidden" id="sync-orig-price-${product.index}" value="${product.new_original_price}">
                </div>
                <div class="flex items-center bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-100 px-2 py-2 border-r border-slate-200">Qty</span>
                    <input type="number" id="sync-qty-${product.index}" value="${product.new_stock}" class="w-20 px-2 py-1.5 text-sm font-black text-emerald-600 outline-none text-center">
                </div>
            </div>
        </div>
        <a href="${product.source_url}" target="_blank" class="p-2 text-slate-400 hover:text-blue-600 shrink-0 self-start sm:self-center" title="View on ${product.source_url.includes('othoba') ? 'Othoba' : 'Dropshop'}"><i data-lucide="external-link" class="w-5 h-5"></i></a>
    </div>
    `;
}

async function publishSelected(status) {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => {
        const id = cb.value;
        const price = document.getElementById(`price-${id}`).value;
        const qty = document.getElementById(`qty-${id}`).value;
        return { id: id, price: price, qty: qty };
    });
    
    if (selected.length === 0) {
        showToast('Please select products to publish.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('items', JSON.stringify(selected));
    formData.append('status', status);

    try {
        const response = await fetch('../api/publish_products.php', {
            method: 'POST',
            body: formData
        });
        const text = await response.text();
        try {
            const result = JSON.parse(text);
            if (result.success) {
                showToast(`Successfully updated ${selected.length} products to '${status}'.`, 'success');
            } else {
                showToast(result.error || 'Failed to publish products.', 'error');
            }
        } catch (parseError) {
            console.error("Server Error Response: ", text);
                let errText = text.replace(/(<([^>]+)>)/gi, "").substring(0, 100);
                showToast('Server Error: ' + (errText || 'Invalid response from server'), 'error');
        }
    } catch (e) {
        console.error("Network Error: ", e);
        showToast('Network error occurred.', 'error');
    }
}

async function updateSyncedSelected() {
    const selected = Array.from(document.querySelectorAll('.sync-product-checkbox:checked')).map(cb => {
        const index = cb.getAttribute('data-index');
        const url = cb.value;
        const price = document.getElementById(`sync-price-${index}`).value;
        const original_price = document.getElementById(`sync-orig-price-${index}`).value;
        const qty = document.getElementById(`sync-qty-${index}`).value;
        return { source_url: url, price: price, original_price: original_price, qty: qty };
    });
    
    if (selected.length === 0) {
        showToast('Please select products to update.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('items', JSON.stringify(selected));

    try {
        const response = await fetch('../api/update_synced_products.php', {
            method: 'POST',
            body: formData
        });
        const text = await response.text();
        try {
            const result = JSON.parse(text);
            if (result.success) {
                showToast(`Successfully updated ${selected.length} products.`, 'success');
            } else {
                showToast(result.error || 'Failed to update products.', 'error');
            }
        } catch (parseError) {
            console.error("Server Error Response: ", text);
            showToast('Server Error: Invalid response from server', 'error');
        }
    } catch (e) {
        console.error("Network Error: ", e);
        showToast('Network error occurred.', 'error');
    }
}
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>