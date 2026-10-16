<?php
require_once __DIR__ . '/db.php';
header("Content-Type: text/xml; charset=utf-8");

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// 1. Core Pages
echo "  <url>\n    <loc>{$baseUrl}/</loc>\n    <changefreq>daily</changefreq>\n    <priority>1.0</priority>\n  </url>\n";
echo "  <url>\n    <loc>{$baseUrl}/shop</loc>\n    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n";
echo "  <url>\n    <loc>{$baseUrl}/flash-deals</loc>\n    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n";

// 2. All Live Products
try {
    // Assuming all products in Product table are live/published
    $stmt = $pdo->query("SELECT slug FROM Product ORDER BY id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $url = htmlspecialchars("{$baseUrl}/p/" . $row['slug']);
        echo "  <url>\n    <loc>{$url}</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.8</priority>\n  </url>\n";
    }
} catch (Exception $e) {}

// 3. Categories
try {
    $stmt = $pdo->query("SELECT slug FROM Category WHERE status = 1");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $url = htmlspecialchars("{$baseUrl}/shop?category=" . $row['slug']);
        echo "  <url>\n    <loc>{$url}</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>\n";
    }
} catch (Exception $e) {}

// 4. Static Pages (About Us, Privacy Policy etc)
try {
    $stmt = $pdo->query("SELECT slug, updatedAt FROM Page");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $url = htmlspecialchars("{$baseUrl}/page?slug=" . $row['slug']);
        $date = date('Y-m-d', strtotime($row['updatedAt']));
        echo "  <url>\n    <loc>{$url}</loc>\n    <lastmod>{$date}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>\n";
    }
} catch (Exception $e) {}

echo '</urlset>';
?>