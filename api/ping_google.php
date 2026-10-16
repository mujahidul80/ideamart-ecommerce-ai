<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$sitemapUrl = "{$protocol}{$host}/sitemap.php";

echo json_encode([
    'success' => true,
    'message' => 'Sitemap is ready at ' . $sitemapUrl . '. Note: Google discontinued the sitemap ping endpoint in Dec 2023; submit directly via Google Search Console.'
]);
?>