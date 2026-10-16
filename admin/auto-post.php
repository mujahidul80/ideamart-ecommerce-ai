<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}
require_once __DIR__ . '/../db.php';

// Fetch existing settings from Database
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `SettingAutoPost` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `postingTimes` TEXT NULL,
        `maxPostsPerDay` INT DEFAULT 3,
        `isActive` BOOLEAN DEFAULT TRUE,
        `updatedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM SettingAutoPost");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO SettingAutoPost (id, postingTimes, maxPostsPerDay, isActive) VALUES (1, '[\"10:00\", \"15:00\", \"20:00\"]', 3, 1)");
    }
    
    $stmt = $pdo->query("SELECT * FROM SettingAutoPost WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $settings = ['postingTimes' => '[]', 'maxPostsPerDay' => 3, 'isActive' => 1];
}

$postingTimes = json_decode($settings['postingTimes'], true) ?: [];

ob_start();
?>

<div class="max-w-4xl mx-auto font-sans pb-12" id="autopost-app">
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        <!-- Header -->
        <div class="px-6 sm:px-8 py-6 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="clock" class="w-6 h-6 text-blue-600"></i> Daily Posting Times & Rules
                </h2>
                <p class="text-sm text-slate-500 mt-1">Schedule automatic social media posts (Saved to Database, operates on BD Time).</p>
            </div>
            <button onclick="savePostingRules()" id="save-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transition-all flex items-center justify-center gap-2">
                <i data-lucide="save" class="w-4 h-4"></i>
                <span id="save-btn-text">Save Rules</span>
            </button>
        </div>

        <div class="p-6 sm:p-8 space-y-8">
            <!-- Status Toggle -->
            <div class="flex items-center justify-between bg-blue-50/50 p-6 rounded-2xl border border-blue-100">
                <div>
                    <h3 class="font-bold text-slate-800">Enable Auto Posting</h3>
                    <p class="text-xs text-slate-500 mt-1">Turn on/off the automatic social media posting system.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="isActive" class="sr-only peer" <?php echo $settings['isActive'] ? 'checked' : ''; ?>>
                    <div class="w-14 h-7 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Max Posts -->
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1">Max Posts Per Day</label>
                    <input type="number" id="maxPosts" value="<?php echo $settings['maxPostsPerDay']; ?>" min="1" max="24" class="w-full px-5 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl font-bold text-slate-700 outline-none focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                </div>
            </div>

            <!-- Times Setup -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1">Posting Schedule (BD Time)</label>
                    <button onclick="addTimeField()" class="text-xs font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1">
                        <i data-lucide="plus" class="w-3 h-3"></i> Add Time
                    </button>
                </div>
                <div id="times-container" class="space-y-3">
                    <?php foreach($postingTimes as $time): ?>
                        <div class="flex items-center gap-3 time-row">
                            <input type="time" value="<?php echo $time; ?>" class="time-input flex-1 px-5 py-3 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-700 outline-none focus:bg-white focus:border-blue-600 transition-all">
                            <button onclick="this.parentElement.remove()" class="p-3 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
                                <i data-lucide="trash-2" class="w-5 h-5"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

function addTimeField() {
    const container = document.getElementById('times-container');
    const row = document.createElement('div');
    row.className = 'flex items-center gap-3 time-row';
    row.innerHTML = `
        <input type="time" value="12:00" class="time-input flex-1 px-5 py-3 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-700 outline-none focus:bg-white focus:border-blue-600 transition-all">
        <button onclick="this.parentElement.remove()" class="p-3 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
            <i data-lucide="trash-2" class="w-5 h-5"></i>
        </button>
    `;
    container.appendChild(row);
    lucide.createIcons();
}

async function savePostingRules() {
    const btn = document.getElementById('save-btn');
    const btnText = document.getElementById('save-btn-text');
    btn.disabled = true;
    btnText.innerText = 'Saving...';
    
    const timesArray = [...new Set(Array.from(document.querySelectorAll('.time-input')).map(i => i.value).filter(Boolean))].sort();
    
    const formData = new FormData();
    formData.append('isActive', document.getElementById('isActive').checked ? 1 : 0);
    formData.append('maxPostsPerDay', document.getElementById('maxPosts').value);
    formData.append('postingTimes', JSON.stringify(timesArray));

    try {
        const res = await fetch('../api/save_posting_rules.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (result.success) showToast("Posting rules successfully saved to the database!", "success");
        else showToast(result.error || "Failed to save rules.", "error");
    } catch (error) { showToast("Network error occurred", "error"); } 
    finally { btn.disabled = false; btnText.innerText = 'Save Rules'; }
}
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>