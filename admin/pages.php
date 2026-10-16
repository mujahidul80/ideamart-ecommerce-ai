<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}
require_once __DIR__ . '/../db.php';

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_page') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    
    if (empty($title) || empty($slug)) {
        echo json_encode(['success' => false, 'error' => 'Title and slug are required.']);
        exit;
    }
    
    try {
        if (!empty($id)) {
            $stmt = $pdo->prepare("UPDATE Page SET title = ?, slug = ?, content = ? WHERE id = ?");
            $stmt->execute([$title, $slug, $content, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO Page (title, slug, content) VALUES (?, ?, ?)");
            $stmt->execute([$title, $slug, $content]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch Pages
$stmt = $pdo->query("SELECT * FROM Page ORDER BY id ASC");
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<div class="max-w-7xl mx-auto font-sans pb-12" id="pages-app">
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        <!-- Header -->
        <div class="px-6 sm:px-8 py-6 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="file-text" class="w-6 h-6 text-blue-600"></i> Pages Management
                </h2>
                <p class="text-sm text-slate-500 mt-1">Manage footer links and static pages like Privacy Policy and About Us.</p>
            </div>
            <button onclick="openPageModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-semibold shadow-lg transition-all flex items-center gap-2 text-sm">
                <i data-lucide="plus" class="w-4 h-4"></i> Add New Page
            </button>
        </div>

        <!-- Pages Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-xs uppercase tracking-widest text-slate-500">
                        <th class="p-5 font-bold">Page Title</th>
                        <th class="p-5 font-bold">Slug (URL)</th>
                        <th class="p-5 font-bold">Last Updated</th>
                        <th class="p-5 font-bold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-slate-700">
                    <?php if (empty($pages)): ?>
                        <tr><td colspan="4" class="p-12 text-center text-slate-500">No pages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pages as $page): ?>
                            <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
                                <td class="p-5 font-bold text-slate-800"><?php echo htmlspecialchars($page['title']); ?></td>
                                <td class="p-5 text-slate-500 font-mono text-xs"><?php echo htmlspecialchars($page['slug']); ?></td>
                                <td class="p-5 text-slate-500"><?php echo date('M d, Y h:i A', strtotime($page['updatedAt'])); ?></td>
                                <td class="p-5 text-right">
                                    <button onclick='editPage(<?php echo json_encode($page, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Edit">
                                        <i data-lucide="edit-3" class="w-5 h-5"></i>
                                    </button>
                                    <a href="../page.php?slug=<?php echo $page['slug']; ?>" target="_blank" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors inline-block" title="View Page">
                                        <i data-lucide="external-link" class="w-5 h-5"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="page-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200 flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 shrink-0">
            <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2" id="modal-title">
                <i data-lucide="file-edit" class="w-5 h-5 text-blue-600"></i> Edit Page
            </h2>
            <button onclick="closePageModal()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form id="page-form" class="p-6 space-y-4 overflow-y-auto flex-1 custom-scrollbar flex flex-col">
            <input type="hidden" name="action" value="save_page">
            <input type="hidden" name="id" id="page-id">
            <input type="hidden" name="content" id="page-content">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 shrink-0">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-500 uppercase ml-1">Page Title <span class="text-rose-500">*</span></label>
                    <input required type="text" name="title" id="page-title" onkeyup="generateSlug()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none transition-all">
                </div>
                
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-500 uppercase ml-1">URL Slug <span class="text-rose-500">*</span></label>
                    <input required type="text" name="slug" id="page-slug" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono text-slate-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none transition-all">
                </div>
            </div>

            <div class="space-y-1.5 flex-1 flex flex-col min-h-[300px]">
                <label class="text-xs font-bold text-slate-500 uppercase ml-1">Page Content</label>
                <div class="bg-white border border-slate-200 rounded-t-xl overflow-hidden shrink-0" id="toolbar-container">
                    <span class="ql-formats">
                        <select class="ql-header">
                            <option value="1">Heading 1</option>
                            <option value="2">Heading 2</option>
                            <option value="3">Heading 3</option>
                            <option selected>Normal</option>
                        </select>
                        <select class="ql-color"></select>
                        <select class="ql-background"></select>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-bold"></button>
                        <button class="ql-italic"></button>
                        <button class="ql-underline"></button>
                        <button class="ql-strike"></button>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-list" value="ordered"></button>
                        <button class="ql-list" value="bullet"></button>
                        <select class="ql-align"></select>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-link"></button>
                        <button class="ql-image"></button>
                        <button class="ql-video"></button>
                    </span>
                </div>
                <div id="editor-container" class="flex-1 bg-white border border-t-0 border-slate-200 rounded-b-xl font-sans text-base text-slate-700"></div>
            </div>

            <div class="flex gap-3 pt-4 border-t border-slate-100 shrink-0">
                <button type="button" onclick="closePageModal()" class="flex-1 py-3 text-sm font-bold text-slate-600 bg-slate-100 rounded-xl hover:bg-slate-200 transition-colors">Cancel</button>
                <button type="submit" id="page-submit-btn" class="flex-[2] py-3 text-sm font-bold text-white bg-blue-600 rounded-xl shadow-md hover:bg-blue-700 transition-colors flex justify-center items-center gap-2">
                    <i data-lucide="save" class="w-4 h-4"></i> <span id="page-btn-text">Save Page</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.ql-toolbar.ql-snow { border-color: #e2e8f0; border-top-left-radius: 0.75rem; border-top-right-radius: 0.75rem; padding: 12px; }
.ql-container.ql-snow { border-color: #e2e8f0; border-bottom-left-radius: 0.75rem; border-bottom-right-radius: 0.75rem; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 15px; }
.ql-editor { padding: 1.5rem; }
.ql-editor h1 { font-size: 2em; font-weight: 800; margin-bottom: 0.5em; }
.ql-editor h2 { font-size: 1.5em; font-weight: 700; margin-bottom: 0.5em; }
.ql-editor h3 { font-size: 1.17em; font-weight: 700; margin-bottom: 0.5em; }
.ql-editor p { margin-bottom: 1em; line-height: 1.6; }
</style>

<script>
var quill = new Quill('#editor-container', {
    modules: { toolbar: '#toolbar-container' },
    theme: 'snow',
    placeholder: 'Write your page content here...'
});

function generateSlug() {
    const title = document.getElementById('page-title').value;
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '');
    document.getElementById('page-slug').value = slug;
}

function openPageModal() {
    document.getElementById('page-form').reset();
    document.getElementById('page-id').value = '';
    quill.root.innerHTML = '';
    
    document.getElementById('modal-title').innerHTML = '<i data-lucide="file-plus" class="w-5 h-5 text-blue-600"></i> Create New Page';
    document.getElementById('page-modal').classList.remove('hidden');
    document.getElementById('page-modal').classList.add('flex');
    lucide.createIcons();
}

function editPage(page) {
    document.getElementById('page-id').value = page.id;
    document.getElementById('page-title').value = page.title;
    document.getElementById('page-slug').value = page.slug;
    quill.root.innerHTML = page.content || '';
    
    document.getElementById('modal-title').innerHTML = '<i data-lucide="file-edit" class="w-5 h-5 text-blue-600"></i> Edit Page';
    document.getElementById('page-modal').classList.remove('hidden');
    document.getElementById('page-modal').classList.add('flex');
    lucide.createIcons();
}

function closePageModal() {
    document.getElementById('page-modal').classList.add('hidden');
    document.getElementById('page-modal').classList.remove('flex');
}

document.getElementById('page-form').onsubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('page-submit-btn');
    const btnText = document.getElementById('page-btn-text');
    
    document.getElementById('page-content').value = quill.root.innerHTML;
    
    btn.disabled = true;
    btnText.innerText = 'Saving...';
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('pages.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast("Page saved successfully!", "success");
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || "Failed to save page.", "error");
        }
    } catch (err) {
        showToast("Error: Network connection failed.", "error");
    } finally {
        btn.disabled = false;
        btnText.innerText = 'Save Changes';
    }
};
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>
