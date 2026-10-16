<?php
session_start();
require_once __DIR__ . '/db.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header("Location: index.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM Page WHERE slug = ?");
    $stmt->execute([$slug]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page) {
        header("Location: 404");
        exit;
    }
} catch (Exception $e) {
    header("Location: 404");
    exit;
}

$pageMetaTitle = $page['title'];

include 'Header.php';
?>

<style>
.page-content h1 { font-size: 2.25rem; font-weight: 800; color: #0f172a; margin-bottom: 1rem; margin-top: 2rem; }
.page-content h2 { font-size: 1.875rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem; margin-top: 2rem; }
.page-content h3 { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem; margin-top: 1.5rem; }
.page-content p { margin-bottom: 1.25rem; line-height: 1.75; color: #475569; }
.page-content a { color: #2563eb; text-decoration: underline; font-weight: 500; }
.page-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1.25rem; color: #475569; }
.page-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1.25rem; color: #475569; }
.page-content li { margin-bottom: 0.5rem; }
.page-content strong { font-weight: 700; color: #0f172a; }
.page-content blockquote { border-left: 4px solid #cbd5e1; padding-left: 1rem; font-style: italic; color: #64748b; margin-bottom: 1.25rem; }
.page-content img { max-width: 100%; height: auto; border-radius: 0.75rem; margin-bottom: 1.25rem; }
</style>

<div class="min-h-screen pt-28 md:pt-32 pb-28 md:pb-24 bg-slate-50 font-sans">
    <div class="max-w-4xl mx-auto px-6">
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-8 md:p-12">
            <div class="page-content"><?php echo $page['content']; ?></div>
        </div>
    </div>
</div>

<?php include 'Footer.php'; ?>