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
header('X-Accel-Buffering: no'); // Essential for Nginx to prevent buffering SSE

// Send 2KB of padding to flush out any server-side buffers immediately
echo ":" . str_repeat(" ", 2048) . "\n\n";
@ob_flush();
@flush();

try {
    $apiStmt = $pdo->query("SELECT dropshopCookie FROM SettingApi LIMIT 1");
    $apiSettings = $apiStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $apiSettings = []; }

$dropshop_cookie = $apiSettings['dropshopCookie'] ?? "";

// Auto-create the products table if the user forgot to run the SQL file
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `title` varchar(255) NOT NULL,
        `original_price` decimal(10,2) NULL,
        `price` decimal(10,2) NOT NULL,
        `image` varchar(255) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `source_url` varchar(512) NOT NULL,
        `status` enum('draft','live') NOT NULL DEFAULT 'draft',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `original_price` DECIMAL(10,2) NULL AFTER `title`"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `category_name` VARCHAR(255) NULL AFTER `description`"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `Product` ADD COLUMN `source_url` VARCHAR(512) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` MODIFY `image` TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `stock` INT DEFAULT 10 AFTER `price`"); } catch (Exception $e) {}
} catch (Exception $e) {}

function send_message($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

function fetch_with_curl($url) {
    global $dropshop_cookie;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, ""); // Auto-decode gzip/deflate responses
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

$import_count = isset($_GET['count']) ? (int)$_GET['count'] : 10;
$target_url = isset($_GET['url']) && !empty($_GET['url']) ? trim($_GET['url']) : 'https://dropshop.com.bd/shop/';
$source = isset($_GET['source']) && $_GET['source'] === 'othoba' ? 'othoba' : 'dropshop'; // Default to dropshop

send_message('log', ['message' => 'Starting import process...']);

if (isset($_GET['resume']) && $_GET['resume'] == '1' && isset($_SESSION['import_state'])) {
    $state = $_SESSION['import_state'];
    $imported_count = $state['imported_count'] ?? 0;
    $page = $state['page'] ?? 1;
    $scraped_urls = $state['scraped_urls'] ?? [];
    $urls_queue = $state['urls_queue'] ?? [];
    $no_more_pages = $state['no_more_pages'] ?? false;
    unset($_SESSION['import_stop']);
} else {
    $imported_count = 0;
    $page = 1;
    $scraped_urls = [];
    $urls_queue = [];
    $no_more_pages = false;
    unset($_SESSION['import_stop']);
    unset($_SESSION['import_state']);
}
session_write_close();

while ($imported_count < $import_count) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $stop = $_SESSION['import_stop'] ?? false;

    // Fetch next page if queue is empty and we still have pages
    if (empty($urls_queue) && !$no_more_pages) {
        $shop_url = $target_url;
        if ($page > 1) {
            if ($source === 'othoba') { // Othoba pagination is now ?pagenumber=2
                $shop_url = rtrim($target_url, '/') . (strpos($target_url, '?') !== false ? '&' : '?') . 'pagenumber=' . $page;
            } else {
                $shop_url = rtrim($target_url, '/');
                if (strpos($shop_url, '?') !== false) {
                    $parts = explode('?', $shop_url);
                    $shop_url = $parts[0] . '/page/' . $page . '/?' . $parts[1];
                } else {
                    $shop_url .= '/page/' . $page . '/';
                }
            }
        }
        send_message('log', ['message' => "Scanning page $page..."]);
        
        $html = fetch_with_curl($shop_url);
        if (!$html) {
            send_message('log', ['message' => "Could not fetch page content. Stopping."]);
            $no_more_pages = true;
        } else {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            // Parse the base URL to construct absolute URLs if needed
            $parsed_url = parse_url($target_url);
            $base_url = ($parsed_url['scheme'] ?? 'https') . '://' . ($parsed_url['host'] ?? '');

            if ($source === 'othoba') {
                // Updated selector for Othoba's new product grid structure
                $product_links = $xpath->query("//div[contains(@class, 'product-wrap')]//figure[contains(@class, 'product-media')]/a");
            } else { // dropshop
                // Avoid out of stock products directly from the listing page if possible
                $product_links = $xpath->query("//*[contains(@class, 'product') and not(contains(@class, 'outofstock'))]//a[contains(@class, 'woocommerce-LoopProduct-link')]");
                if ($product_links->length == 0) {
                    $product_links = $xpath->query("//a[contains(@class, 'woocommerce-LoopProduct-link')]"); // Fallback
                }
            }
            
            if ($product_links->length == 0) {
                $no_more_pages = true;
            } else {
                $found_new = false;
                foreach ($product_links as $link) {
                    $href = $link->getAttribute('href');
                    // Make sure the URL is absolute
                    if (strpos($href, 'http') !== 0) {
                        if (substr($href, 0, 1) !== '/') { $href = '/' . $href; }
                        $href = $base_url . $href;
                    }

                    if (!in_array($href, $scraped_urls)) {
                        $urls_queue[] = $href;
                        $scraped_urls[] = $href;
                        $found_new = true;
                    }
                }
                
                if (!$found_new) {
                    send_message('log', ['message' => "No new products found on page $page. Stopping scan."]);
                    $no_more_pages = true;
                } else {
                    $page++;
                    sleep(1); // Small delay between page scans to prevent blocking
                }
            }
        }
    }

    if (empty($urls_queue)) {
        break; // No more products available to scrape
    }

    if ($stop) {
        $_SESSION['import_state'] = ['imported_count' => $imported_count, 'page' => $page, 'scraped_urls' => $scraped_urls, 'urls_queue' => $urls_queue, 'no_more_pages' => $no_more_pages];
        unset($_SESSION['import_stop']); 
        session_write_close();
        send_message('log', ['message' => 'Import paused by user.']); 
        send_message('paused', ['message' => 'Paused']); 
        exit;
    }

    $url = array_shift($urls_queue);
    $_SESSION['import_state'] = ['imported_count' => $imported_count, 'page' => $page, 'scraped_urls' => $scraped_urls, 'urls_queue' => $urls_queue, 'no_more_pages' => $no_more_pages];
    session_write_close();

    send_message('log', ['message' => 'Importing: ' . basename($url)]);
    
    $product_html = fetch_with_curl($url);
    if (!$product_html) continue;

    $product_dom = new DOMDocument();
    @$product_dom->loadHTML($product_html);
    $product_xpath = new DOMXPath($product_dom);

    if ($source === 'othoba') {
        // Othoba doesn't seem to have a clear out-of-stock indicator on the list page, check on product page
        $out_of_stock_node = $product_xpath->query("//*[contains(@class, 'stock') and contains(text(), 'Out of stock')]")->item(0);
        if ($out_of_stock_node) {
            send_message('log', ['message' => 'Skipped (Out of Stock): ' . basename($url)]);
            continue;
        }

        // Updated selectors based on new HTML structure
        $title_node = $product_xpath->query("//h1[contains(@class, 'product-title')]")->item(0);
        $title = $title_node ? trim($title_node->textContent) : 'Untitled';

        // Prioritize original price (old-price) over special/discounted price as requested.
        $price_node = $product_xpath->query("//div[contains(@class, 'product-price')]//span[contains(@class, 'price')]")->item(0);
        $price_raw = $price_node ? $price_node->textContent : '0';
        
        $images = [];
        // Othoba now uses a JSON object for gallery images
        $scripts = $product_xpath->query("//script[contains(text(), 'Magento_Swatches/js/swatch-renderer')]");
        foreach ($scripts as $script) {
            if (preg_match('/"data":\s*(\[.+?\])/', $script->textContent, $matches)) {
                $galleryData = json_decode($matches[1], true);
                if (is_array($galleryData)) {
                    foreach ($galleryData as $img) {
                        if (isset($img['full']) && !empty($img['full'])) {
                            $images[] = $img['full'];
                        }
                    }
                }
                break;
            }
        }

        $desc_node = $product_xpath->query("//div[contains(@class, 'product-details-content')]")->item(0);
        $description = $desc_node ? trim($desc_node->textContent) : '';

        $cat_node = $product_xpath->query("//nav[contains(@class, 'breadcrumb-nav')]//li[last()]/strong")->item(0);
        $category_name = $cat_node ? trim($cat_node->textContent) : 'Uncategorized';

        // Othoba doesn't show exact stock count, but we can check if it's available.
        $stock_node = $product_xpath->query("//div[contains(@class, 'stock') and contains(@class, 'available')]")->item(0);
        if($stock_node) {
            $stock_text = trim($stock_node->textContent);
            $stock = preg_match('/(\d+)/', $stock_text, $matches) ? (int)$matches[1] : 10; // Default to 10 if number not found
        } else { $stock = 10; }

    } else {
        // Dropshop Logic
        $out_of_stock_node = $product_xpath->query("//p[contains(@class, 'stock') and contains(@class, 'out-of-stock')]")->item(0);
        if (!$out_of_stock_node) {
            $out_of_stock_node = $product_xpath->query("//div[contains(@class, 'summary')]//*[contains(@class, 'out-of-stock')]")->item(0);
        }
        if ($out_of_stock_node) {
            send_message('log', ['message' => 'Skipped (Out of Stock): ' . basename($url)]);
            continue;
        }

        $title_node = $product_xpath->query("//h1[contains(@class, 'product_title')]")->item(0);
        $title = $title_node ? trim($title_node->textContent) : 'Untitled';

        $ins_price_node = $product_xpath->query("//p[contains(@class, 'price')]//ins//span[contains(@class, 'amount')]//bdi")->item(0);
        if ($ins_price_node) {
            $price_raw = $ins_price_node->textContent;
        } else {
            $price_node = $product_xpath->query("//p[contains(@class, 'price')]//span[contains(@class, 'amount')]//bdi")->item(0);
            $price_raw = $price_node ? $price_node->textContent : '0';
        }

        $images = [];
        $image_node = $product_xpath->query("//img[contains(@class, 'wp-post-image')]")->item(0);
        if ($image_node) {
            $img = $image_node->getAttribute('data-src') ?: $image_node->getAttribute('data-large_image') ?: $image_node->getAttribute('src');
            if ($img) $images[] = $img;
        }
        $gallery_links = $product_xpath->query("//div[contains(@class, 'woocommerce-product-gallery__image')]/a");
        foreach ($gallery_links as $link) {
            $href = $link->getAttribute('href');
            if ($href) $images[] = $href;
        }
        $gallery_imgs = $product_xpath->query("//div[contains(@class, 'woocommerce-product-gallery__image')]//img");
        foreach ($gallery_imgs as $node) {
            $img = $node->getAttribute('data-large_image') ?: $node->getAttribute('data-src') ?: $node->getAttribute('src');
            if ($img) $images[] = $img;
        }

        $desc_node = $product_xpath->query("//div[contains(@class, 'woocommerce-product-details__short-description')]")->item(0);
        $description = $desc_node ? trim($desc_node->textContent) : '';

        $cat_node = $product_xpath->query("//span[contains(@class, 'posted_in')]/a")->item(0);
        $category_name = $cat_node ? trim($cat_node->textContent) : 'Uncategorized';
        if ($category_name === 'Uncategorized') {
            $breadcrumb_nodes = $product_xpath->query("//nav[contains(@class, 'woocommerce-breadcrumb')]/a");
            if ($breadcrumb_nodes && $breadcrumb_nodes->length > 1) {
                $category_name = trim($breadcrumb_nodes->item($breadcrumb_nodes->length - 1)->textContent);
            }
        }

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
    
    $price_raw = str_replace(',', '', $price_raw); // Remove commas like 1,200
    preg_match('/[0-9]+(\.[0-9]+)?/', $price_raw, $matches);
    $price = (float)($matches[0] ?? 0);
    $original_price = $price; // Save the buying price

    // Auto Price Markup Logic for Dropshop only
    if ($source === 'dropshop') { // Price markup only for Dropshop
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

    $images = array_values(array_unique(array_filter($images)));
    $images = array_slice($images, 0, 4); // Limit to max 4 images to prevent heavy DB payloads and processing timeouts
    $image = implode(',', $images);

    try {
        // Check if exists in temp products table
        $stmtCheck = $pdo->prepare("SELECT id FROM products WHERE source_url = ?");
        $stmtCheck->execute([$url]);
        $existingTempId = $stmtCheck->fetchColumn();

        if ($existingTempId) {
            $updateStmt = $pdo->prepare("UPDATE products SET title=?, original_price=?, price=?, stock=?, image=?, description=?, category_name=? WHERE id=?");
            $updateStmt->execute([$title, $original_price, $price, $stock, $image, $description, $category_name, $existingTempId]);
            $last_id = $existingTempId;
            send_message('log', ['message' => 'Updated sync data for: ' . basename($url)]);
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO products (title, original_price, price, stock, image, description, category_name, source_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
            $insert_stmt->execute([$title, $original_price, $price, $stock, $image, $description, $category_name, $url]);
            $last_id = $pdo->lastInsertId();
        }

        // Check if it already exists in the live Main Shop
        $mainCheck = $pdo->prepare("SELECT id FROM Product WHERE source_url = ?");
        $mainCheck->execute([$url]);
        $inMainDb = $mainCheck->fetchColumn() ? true : false;

        $product_data = [
            'id' => $last_id,
            'title' => $title,
            'original_price' => $original_price,
            'price' => $price,
            'stock' => $stock,
            'image' => $image,
            'source_url' => $url,
            'category_name' => $category_name,
            'in_main_db' => $inMainDb
        ];

        send_message('product', ['product' => $product_data]);
        $imported_count++;

    } catch (PDOException $e) {
        send_message('log', ['message' => 'DB Error: ' . $e->getMessage()]);
    }
    
    sleep(1); // Be nice to their server
}

if ($imported_count === 0) {
    send_message('log', ['message' => 'Error: No valid product links found or all were out of stock.']);
    send_message('complete', ['message' => 'Import failed.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
unset($_SESSION['import_state']);
unset($_SESSION['import_stop']);
session_write_close();

send_message('log', ['message' => 'Successfully imported ' . $imported_count . ' products.']);
send_message('complete', ['message' => 'Import process finished.']);
?>