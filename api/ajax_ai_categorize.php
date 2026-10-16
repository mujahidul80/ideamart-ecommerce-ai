<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db.php';

// Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "event: log\ndata: {\"message\":\"<span class='text-rose-500'>Unauthorized access.</span>\"}\n\nevent: complete\ndata: {\"message\":\"Error\"}\n\n";
    exit;
}

@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) { @ob_end_clean(); }
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo ":" . str_repeat(" ", 2048) . "\n\n";
@ob_flush(); @flush();

function send_message($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    @ob_flush(); @flush();
}

// 1. Fetch AI Settings
$stmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$provider = $settings['activeAiProvider'] ?? 'gemini';

// 2. Fetch Categories
$catStmt = $pdo->query("SELECT id, name, parentId FROM Category");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
$catJson = empty($categories) ? "[]" : json_encode($categories);

// 3. Fetch Uncategorized Products (NULL or belonging to "Uncategorized")
$forceAll = isset($_GET['force_all']) && $_GET['force_all'] == '1';
$resumeId = isset($_GET['resume_id']) ? (int)$_GET['resume_id'] : 0;

try {
    if ($forceAll) {
        $prodStmt = $pdo->prepare("SELECT id, name, description FROM Product WHERE id > ? ORDER BY id ASC");
        $prodStmt->execute([$resumeId]);
    } else {
        $prodStmt = $pdo->prepare("
            SELECT p.id, p.name, p.description 
            FROM Product p 
            LEFT JOIN Category c ON p.categoryId = c.id 
            WHERE (p.categoryId IS NULL OR p.categoryId = 0 OR c.id IS NULL OR TRIM(c.name) = 'Uncategorized' OR TRIM(c.name) = '')
            AND p.id > ?
            ORDER BY p.id ASC
        ");
        $prodStmt->execute([$resumeId]);
    }
    $uncategorized = $prodStmt ? $prodStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    send_message('log', ['message' => '<span class="text-rose-500 font-bold">DB Error: ' . $e->getMessage() . '</span>']);
    send_message('complete', ['message' => 'Process stopped.']);
    exit;
}

if (empty($uncategorized)) {
    send_message('log', ['message' => '<span class="text-emerald-400 font-bold">Awesome! All products are already categorized.</span>']);
    send_message('complete', ['message' => 'Process finished.']);
    exit;
}

send_message('log', ['message' => 'Found <span class="text-blue-400 font-bold">' . count($uncategorized) . '</span> uncategorized products.']);

$updated = 0;
$failed = 0;

foreach ($uncategorized as $prod) {
    $pId = $prod['id'];
    $pName = $prod['name'];
    $pDesc = mb_substr(strip_tags($prod['description'] ?? ''), 0, 300); // Limiting text to save AI tokens
    
    send_message('log', ['message' => "Analyzing: $pName"]);

    $prompt = "You are an e-commerce categorizer. Here is our current category list in JSON: \n$catJson\n\nProduct Name: \"$pName\"\nDescription: \"$pDesc\"\n\nTask: Find the best matching category ID from the list. If NO category fits well, suggest a new main category and (optionally) a sub-category.\nOutput ONLY a JSON object (no markdown, no other text) exactly like this:\n{\n  \"matched_id\": 12,\n  \"new_main_category\": \"Electronics\",\n  \"new_sub_category\": \"Smartphones\"\n}\nIf you find a match, set 'matched_id' to the integer ID, and leave the new category fields empty. If you do not find a match, set 'matched_id' to 0 and provide 'new_main_category' (and 'new_sub_category' if applicable).";

    $reply = '';

    // Call AI
    if ($provider === 'openai' && !empty($settings['openaiApiKey'])) {
        $url = 'https://api.openai.com/v1/chat/completions';
        $data = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'system', 'content' => 'You are a strict JSON bot. Output pure JSON without markdown tags like ```json.'], ['role' => 'user', 'content' => $prompt]]];
        $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['openaiApiKey']]); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch); curl_close($ch); $json = json_decode($res, true);
        $reply = trim($json['choices'][0]['message']['content'] ?? '');
    } else {
        $apiKeys = array_filter(array_map('trim', explode(',', $settings['geminiApiKey'] ?? '')));
        $configuredModel = !empty($settings['geminiModelVersion']) ? $settings['geminiModelVersion'] : 'gemini-2.5-flash';
        $candidateModels = array_unique([$configuredModel, 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite', 'gemini-3.8-flash', 'gemini-3.5-flash']);
        $data = ['contents' => [['parts' => [['text' => $prompt]]]]];

        foreach ($apiKeys as $apiKey) {
            if (empty($apiKey)) continue;
            foreach ($candidateModels as $model) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $res = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpcode === 200 && $res !== false) {
                    $json = json_decode($res, true);
                    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                        $reply = trim($json['candidates'][0]['content']['parts'][0]['text']);
                        break 2;
                    }
                }
            }
        }
    }

    $assignedId = 0;
    $newMain = '';
    $newSub = '';

    preg_match('/\{.*\}/s', $reply, $matches);
    if (!empty($matches[0])) {
        $parsed = json_decode($matches[0], true);
        if ($parsed) {
            $assignedId = (int)($parsed['matched_id'] ?? 0);
            $newMain = trim($parsed['new_main_category'] ?? '');
            $newSub = trim($parsed['new_sub_category'] ?? '');
        }
    }

    if ($assignedId > 0) {
        $pdo->prepare("UPDATE Product SET categoryId = ? WHERE id = ?")->execute([$assignedId, $pId]);
        
        // Find category name for visual log
        $catName = 'Unknown';
        foreach($categories as $c) { if($c['id'] == $assignedId) { $catName = $c['name']; break; } }
        
        send_message('log', ['message' => "&rarr; <span class='text-emerald-400'>Matched Category: <strong>$catName</strong></span>", 'processed_id' => $pId]);
        $updated++;
    } elseif (!empty($newMain)) {
        // Try to find if main category already exists (in case AI hallucinated matching or generated existing text)
        $stmt = $pdo->prepare("SELECT id FROM Category WHERE LOWER(name) = LOWER(?) AND (parentId IS NULL OR parentId = 0) LIMIT 1");
        $stmt->execute([$newMain]);
        $mainId = $stmt->fetchColumn();

        if (!$mainId) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $newMain));
            $slug = trim($slug, '-');
            $slugCheck = $pdo->prepare("SELECT COUNT(*) FROM Category WHERE slug = ?");
            $slugCheck->execute([$slug]);
            if ($slugCheck->fetchColumn() > 0) $slug .= '-' . substr(time(), -5);

            $pdo->prepare("INSERT INTO Category (name, slug, status) VALUES (?, ?, 1)")->execute([$newMain, $slug]);
            $mainId = $pdo->lastInsertId();
            
            $categories[] = ['id' => $mainId, 'name' => $newMain, 'parentId' => null];
            $catJson = json_encode($categories);
        }

        $finalId = $mainId;
        $finalName = $newMain;

        if (!empty($newSub)) {
            $stmt = $pdo->prepare("SELECT id FROM Category WHERE LOWER(name) = LOWER(?) AND parentId = ? LIMIT 1");
            $stmt->execute([$newSub, $mainId]);
            $subId = $stmt->fetchColumn();

            if (!$subId) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $newSub));
                $slug = trim($slug, '-');
                $slugCheck = $pdo->prepare("SELECT COUNT(*) FROM Category WHERE slug = ?");
                $slugCheck->execute([$slug]);
                if ($slugCheck->fetchColumn() > 0) $slug .= '-' . substr(time(), -5);

                $pdo->prepare("INSERT INTO Category (name, slug, parentId, status) VALUES (?, ?, ?, 1)")->execute([$newSub, $slug, $mainId]);
                $subId = $pdo->lastInsertId();
                
                $categories[] = ['id' => $subId, 'name' => $newSub, 'parentId' => $mainId];
                $catJson = json_encode($categories);
            }
            $finalId = $subId;
            $finalName = $newMain . ' &gt; ' . $newSub;
        }

        $pdo->prepare("UPDATE Product SET categoryId = ? WHERE id = ?")->execute([$finalId, $pId]);
        send_message('log', ['message' => "&rarr; <span class='text-blue-400'>Created & Matched: <strong>$finalName</strong></span>", 'processed_id' => $pId]);
        $updated++;
    } else {
        send_message('log', ['message' => "&rarr; <span class='text-amber-400'>Skipped (Could not determine category)</span>", 'processed_id' => $pId]);
        $failed++;
    }
    
    sleep(1); // Be polite to API rate limits
}

// Cleanup unused/empty categories
$cleanupCount = 0;
for ($i = 0; $i < 3; $i++) {
    $delStmt = $pdo->exec("DELETE FROM Category WHERE id NOT IN (SELECT DISTINCT categoryId FROM Product WHERE categoryId IS NOT NULL) AND id NOT IN (SELECT DISTINCT parentId FROM Category WHERE parentId IS NOT NULL)");
    if ($delStmt == 0) break;
    $cleanupCount += $delStmt;
}
if ($cleanupCount > 0) {
    send_message('log', ['message' => "<br><span class='text-amber-400 font-bold'>🧹 Cleaned up $cleanupCount empty/unused categories.</span>"]);
}

send_message('log', ['message' => "<br><span class='text-white font-bold'>Done! Successfully categorized $updated products. Missed $failed products.</span>"]);
send_message('complete', ['message' => '']);
exit;
?>