<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized access');
}
require_once __DIR__ . '/../db.php';

// Turn off output buffering and increase execution time to prevent "Connection lost"
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) { @ob_end_clean(); }
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Send 2KB of padding to flush out any server-side buffers immediately
echo ":" . str_repeat(" ", 2048) . "\n\n";
@ob_flush();
@flush();

function send_message($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

try {
    $apiStmt = $pdo->query("SELECT dropshopCookie FROM SettingApi LIMIT 1");
    $apiSettings = $apiStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $apiSettings = []; }

$dropshop_cookie = $apiSettings['dropshopCookie'] ?? "";

function fetch_with_curl($url) {
    global $dropshop_cookie;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, ""); 
    if (!empty($dropshop_cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $dropshop_cookie);
    }
    $output = curl_exec($ch);
    if(curl_errno($ch)) {
        send_message('log', ['message' => 'cURL Error: ' . curl_error($ch)]);
        return false;
    }
    curl_close($ch);
    return $output;
}

send_message('log', ['message' => 'Starting Dropshop sync process...']);

if (isset($_GET['resume']) && $_GET['resume'] == '1' && isset($_SESSION['sync_state'])) {
    $state = $_SESSION['sync_state'];
    $urls = $state['urls'] ?? [];
    $updated_count = $state['updated_count'] ?? 0;
    $out_of_stock_count = $state['out_of_stock_count'] ?? 0;
    $total_urls = $state['total_urls'] ?? count($urls);
    unset($_SESSION['sync_stop']);
} else {
    // Get all unique source_urls from both products (temp) and Product (live) tables
    $urls = [];
    try {
        // Get URLs from temp table
        $stmt = $pdo->query("SELECT DISTINCT source_url FROM products WHERE source_url LIKE '%dropshop.com.bd%'");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['source_url'])) $urls[] = $row['source_url'];
            }
        }
    } catch (Exception $e) {}

    try {
        // Get URLs from live table
        $stmt2 = $pdo->query("SELECT DISTINCT source_url FROM Product WHERE source_url LIKE '%dropshop.com.bd%'");
        if ($stmt2) {
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['source_url']) && !in_array($row['source_url'], $urls)) {
                    $urls[] = $row['source_url'];
                }
            }
        }
    } catch (Exception $e) {}

    $updated_count = 0;
    $out_of_stock_count = 0;
    $total_urls = count($urls);
    unset($_SESSION['sync_stop']);
    unset($_SESSION['sync_state']);
}
session_write_close();

if (empty($urls)) {
    send_message('log', ['message' => 'No Dropshop products found to sync.']);
    send_message('complete', ['message' => 'Sync finished.']);
    exit;
}

send_message('log', ['message' => 'Found ' . count($urls) . ' products left to sync (Total: ' . $total_urls . ').']);

while (!empty($urls)) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $stop = $_SESSION['sync_stop'] ?? false;
    if ($stop) {
        $_SESSION['sync_state'] = ['urls' => $urls, 'updated_count' => $updated_count, 'out_of_stock_count' => $out_of_stock_count, 'total_urls' => $total_urls];
        unset($_SESSION['sync_stop']); session_write_close();
        send_message('log', ['message' => 'Sync paused by user.']); send_message('paused', ['message' => 'Paused']); exit;
    }

    $url = array_shift($urls);
    $_SESSION['sync_state'] = ['urls' => $urls, 'updated_count' => $updated_count, 'out_of_stock_count' => $out_of_stock_count, 'total_urls' => $total_urls];
    session_write_close();

    send_message('log', ['message' => 'Checking: ' . basename($url)]);
    
    $product_html = fetch_with_curl($url);
    if (!$product_html) {
        send_message('log', ['message' => 'Failed to fetch: ' . basename($url)]);
        continue;
    }

    $product_dom = new DOMDocument();
    @$product_dom->loadHTML($product_html);
    $product_xpath = new DOMXPath($product_dom);

    $out_of_stock_node = $product_xpath->query("//p[contains(@class, 'stock') and contains(@class, 'out-of-stock')]")->item(0);
    if (!$out_of_stock_node) {
        $out_of_stock_node = $product_xpath->query("//div[contains(@class, 'summary')]//*[contains(@class, 'out-of-stock')]")->item(0);
    }
    $is_out_of_stock = $out_of_stock_node ? true : false;
    
    if ($is_out_of_stock) {
        $stock = 0;
        send_message('log', ['message' => 'Out of Stock: ' . basename($url)]);
        $out_of_stock_count++;
    } else {
        $stock = 10; // Default fallback
        $stock_node = $product_xpath->query("//p[contains(@class, 'stock') and contains(@class, 'in-stock')]")->item(0);
        if ($stock_node) {
            preg_match('/(\d+)/', $stock_node->textContent, $stock_matches);
            if (!empty($stock_matches[1])) $stock = (int)$stock_matches[1];
        }
        if ($stock === 10) {
            $qty_node = $product_xpath->query("//input[@name='quantity']")->item(0);
            if ($qty_node && $qty_node->hasAttribute('max') && is_numeric($qty_node->getAttribute('max')) && $qty_node->getAttribute('max') > 0) {
                $stock = (int)$qty_node->getAttribute('max');
            }
        }
    }

    $price = 0;
    $original_price = 0;

    if (!$is_out_of_stock) {
        $ins_price_node = $product_xpath->query("//p[contains(@class, 'price')]//ins//span[contains(@class, 'amount')]//bdi")->item(0);
        if ($ins_price_node) {
            $price_raw = $ins_price_node->textContent;
        } else {
            $price_node = $product_xpath->query("//p[contains(@class, 'price')]//span[contains(@class, 'amount')]//bdi")->item(0);
            $price_raw = $price_node ? $price_node->textContent : '0';
        }
        
        $price_raw = str_replace(',', '', $price_raw);
        preg_match('/[0-9]+(\.[0-9]+)?/', $price_raw, $matches);
        $price = (float)($matches[0] ?? 0);
        $original_price = $price; 

        if ($price > 0) {
            if ($price <= 500) {
                $price += 150;
            } elseif ($price <= 1000) {
                $price += 200;
            } elseif ($price <= 2000) {
                $price += 250;
            } else {
                $price += 300;
            }
        }
    }

    $title = 'Unknown';
    $image = '';
    $old_price = 0;
    
    $stmt = $pdo->prepare("SELECT title, image, price FROM products WHERE source_url = ?");
    $stmt->execute([$url]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $title = $res['title'];
        $image = $res['image'];
        $old_price = $res['price'];
    } else {
        $stmt2 = $pdo->prepare("SELECT name as title, imageUrl as image, basePrice as price FROM Product WHERE source_url = ?");
        $stmt2->execute([$url]);
        $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($res2) {
            $title = $res2['title'];
            $image = $res2['image'];
            $old_price = $res2['price'];
        }
    }

    try {
        $sync_data = [
            'source_url' => $url,
            'title' => $title,
            'image' => $image,
            'old_price' => $old_price,
            'new_price' => $price,
            'new_original_price' => $original_price,
            'new_stock' => $is_out_of_stock ? 0 : $stock,
            'is_out_of_stock' => $is_out_of_stock
        ];
        send_message('sync_product', ['product' => $sync_data]);
        
        $updated_count++;
        send_message('log', ['message' => 'Fetched sync data for: ' . basename($url) . " ($updated_count / $total_urls)"]);
    } catch (PDOException $e) {
        send_message('log', ['message' => 'DB Error: ' . $e->getMessage()]);
    }
    
    sleep(1); // Be nice to their server
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
unset($_SESSION['sync_state']);
unset($_SESSION['sync_stop']);
session_write_close();

send_message('log', ['message' => "Successfully fetched $updated_count products ($out_of_stock_count are out of stock). Please click 'Update Selected' to save."]);
send_message('complete', ['message' => 'Fetch process finished.']);
exit;
?>