<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . $host;

    // Generate robots.txt
    $robotsContent = "User-agent: *\nDisallow: /admin/\nDisallow: /api/\n\nSitemap: {$baseUrl}/sitemap.php";
    $robotsPath = __DIR__ . '/../robots.txt';
    
    if (file_put_contents($robotsPath, $robotsContent) === false) {
        throw new Exception("Could not write to robots.txt. Check folder permissions.");
    }

    // Generate sitemap.php dynamically
    $sitemapContent = <<<'PHP'
<?php
require_once __DIR__ . '/db.php';
header("Content-Type: text/xml; charset=utf-8");

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

echo "  <url>\n    <loc>{$baseUrl}/</loc>\n    <changefreq>daily</changefreq>\n    <priority>1.0</priority>\n  </url>\n";
echo "  <url>\n    <loc>{$baseUrl}/shop</loc>\n    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n";
echo "  <url>\n    <loc>{$baseUrl}/flash-deals</loc>\n    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n";

try {
    $stmt = $pdo->query("SELECT slug FROM Product ORDER BY id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo "  <url>\n    <loc>" . htmlspecialchars("{$baseUrl}/p/" . $row['slug']) . "</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.8</priority>\n  </url>\n"; }
} catch (Exception $e) {}

try {
    $stmt = $pdo->query("SELECT slug FROM Category WHERE status = 1");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo "  <url>\n    <loc>" . htmlspecialchars("{$baseUrl}/shop?category=" . $row['slug']) . "</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>\n"; }
} catch (Exception $e) {}

echo '</urlset>';
?>
PHP;

    $sitemapPath = __DIR__ . '/../sitemap.php';
    if (file_put_contents($sitemapPath, $sitemapContent) === false) {
        throw new Exception("Could not write to sitemap.php. Check folder permissions.");
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>