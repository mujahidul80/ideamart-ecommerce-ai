<?php
require_once __DIR__ . '/../db.php';

ignore_user_abort(true);

// Fetch FB settings
try {
    $stmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit;
}

$verify_token = $settings['fbVerifyToken'] ?? '';
$page_access_token = $settings['fbPageAccessToken'] ?? '';
$is_enabled = !empty($settings['fbAutoReplyEnabled']);

// Helper function to generate dynamic reply using Gemini or OpenAI
function callAIText($prompt, $settings, $systemInstruction = '') {
    $provider = $settings['activeAiProvider'] ?? 'gemini';
    
    if ($provider === 'openai') {
        $apiKey = $settings['openaiApiKey'] ?? '';
        if (empty($apiKey)) return false;
        
        $url = 'https://api.openai.com/v1/chat/completions';
        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        } else {
            $messages[] = ['role' => 'system', 'content' => 'You are a warm, polite, and helpful customer service assistant in Bangladesh. Always reply politely in natural conversational Bengali.'];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 350,
            'temperature' => 0.6
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($res, true);
        return $json['choices'][0]['message']['content'] ?? false;
    } else {
        $apiKeys = array_filter(array_map('trim', explode(',', $settings['geminiApiKey'] ?? '')));
        if (empty($apiKeys)) return false;
        
        $configuredModel = !empty($settings['geminiModelVersion']) ? $settings['geminiModelVersion'] : 'gemini-2.5-flash';
        $candidateModels = array_unique([$configuredModel, 'gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-2.5-flash-lite']);
        
        foreach ($apiKeys as $apiKey) {
            foreach ($candidateModels as $model) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
                $data = [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'maxOutputTokens' => 350,
                        'temperature' => 0.6
                    ]
                ];
                if (!empty($systemInstruction)) {
                    $data['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
                }
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $res = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpcode === 200 && $res) {
                    $json = json_decode($res, true);
                    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                        return $json['candidates'][0]['content']['parts'][0]['text'];
                    }
                }
            }
        }
        return false;
    }
}

// Automatically resolve Page Access Token if a User Token was stored
function resolvePageAccessToken($token, $pageId, $pdo) {
    if (empty($token)) return '';
    
    // Quick cache check in memory or check /me
    $ch = curl_init("https://graph.facebook.com/v19.0/me?fields=id,name&metadata=1&access_token=" . urlencode($token));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    curl_close($ch);
    $meData = json_decode($res, true);
    
    $isPage = (isset($meData['metadata']['type']) && $meData['metadata']['type'] === 'page');
    if ($isPage) {
        return $token;
    }
    
    // If it's a User token, query managed pages from /me/accounts
    $chAcc = curl_init("https://graph.facebook.com/v19.0/me/accounts?fields=id,name,access_token&access_token=" . urlencode($token));
    curl_setopt($chAcc, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chAcc, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chAcc, CURLOPT_TIMEOUT, 5);
    $resAcc = curl_exec($chAcc);
    curl_close($chAcc);
    $accData = json_decode($resAcc, true);
    
    if (!empty($accData['data']) && is_array($accData['data'])) {
        foreach ($accData['data'] as $pg) {
            if (empty($pageId) || (string)$pg['id'] === (string)$pageId) {
                $realPageToken = $pg['access_token'];
                try {
                    $pdo->prepare("UPDATE SettingNotification SET fbPageAccessToken = ?, fbPageId = ? WHERE id = 1")
                        ->execute([$realPageToken, $pg['id']]);
                } catch (Exception $e) {}
                return $realPageToken;
            }
        }
    }
    
    return $token;
}

// Handle Webhook Verification (Always allow verification even if auto-reply is disabled)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe' && isset($_GET['hub_verify_token'])) {
        if ($_GET['hub_verify_token'] === $verify_token) {
            @ob_clean(); // Clean any unwanted whitespace or PHP warnings before echoing
            echo $_GET['hub_challenge'];
            http_response_code(200);
            exit;
        } else {
            http_response_code(403);
            exit;
        }
    }
}

// Handle Incoming Webhook Events
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (isset($data['object']) && $data['object'] === 'page') {
        // Send immediate 200 OK to Meta so Facebook doesn't wait, time out, or send repeated retries
        ignore_user_abort(true);
        set_time_limit(180);
        
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        ob_start();
        echo 'EVENT_RECEIVED';
        $contentLength = ob_get_length();
        header("HTTP/1.1 200 OK");
        header("Status: 200 OK");
        header("Content-Type: text/plain");
        header("Content-Length: $contentLength");
        header("Connection: close");
        ob_end_flush();
        @ob_flush();
        flush();
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        if (!$is_enabled) {
            exit;
        }
        
        // Auto-resolve Page Access Token if User token was provided
        $page_access_token = resolvePageAccessToken($page_access_token, $settings['fbPageId'] ?? '', $pdo);
        
        foreach ($data['entry'] as $entry) {
            
            // 1. Handle Messenger Inbox Messages (handles standard messaging AND Meta Business Suite standby events)
            $messaging_events = array_merge($entry['messaging'] ?? [], $entry['standby'] ?? []);
            if (!empty($messaging_events)) {
                foreach ($messaging_events as $messaging_event) {
                    $sender_id = $messaging_event['sender']['id'] ?? '';
                    if (empty($sender_id)) continue;
                    if (!empty($settings['fbPageId']) && (string)$sender_id === (string)$settings['fbPageId']) continue; // Prevent infinite bot loop
                    
                    // Simple file-based rate limit (max 15 msg per min)
                    $rateFile = sys_get_temp_dir() . '/fb_rate_' . md5($sender_id);
                    $requests = @file_get_contents($rateFile);
                    if ($requests) {
                        $parts = explode(':', $requests);
                        if (time() - (int)$parts[1] < 60) {
                            if ((int)$parts[0] > 15) continue; 
                            @file_put_contents($rateFile, ((int)$parts[0] + 1) . ':' . $parts[1]);
                        } else {
                            @file_put_contents($rateFile, '1:' . time());
                        }
                    } else {
                        @file_put_contents($rateFile, '1:' . time());
                    }

                    if (isset($messaging_event['message']) && empty($messaging_event['message']['is_echo'])) {
                        $message_text = trim($messaging_event['message']['text'] ?? '');
                        $message_lower = mb_strtolower($message_text);
                        $attachments = $messaging_event['message']['attachments'] ?? [];
                        
                        $defaultInboxReply = !empty($settings['fbInboxReply']) ? $settings['fbInboxReply'] : 'Hello! Welcome to our store. How can we assist you today? 😊';
                        $reply = $defaultInboxReply;
                        
                        $imageUrl = null;
                        foreach ($attachments as $att) {
                            if ($att['type'] === 'image' && isset($att['payload']['url'])) {
                                $imageUrl = $att['payload']['url'];
                                break;
                            }
                        }

                        // Smart Vision AI: Analyze image to find product
                        if ($imageUrl) {
                            $aiKeywords = identifyProductFromImage($imageUrl, $settings);
                            if (!empty($aiKeywords)) {
                                $message_lower .= ' ' . mb_strtolower(trim($aiKeywords));
                            }
                        }

                        // Generate Smart Multilingual Reply via AI
                        $foundProduct = findProductFromText($pdo, $message_lower);
                        $hasAiKey = ($settings['activeAiProvider'] === 'openai' && !empty($settings['openaiApiKey'])) || ($settings['activeAiProvider'] !== 'openai' && !empty($settings['geminiApiKey']));
                        
                        if ($hasAiKey) {
                            $storeTitle = $settings['storeName'] ?? 'Idea Mart';
                            $customPrompt = !empty(trim($settings['fbBotPrompt'] ?? '')) ? trim($settings['fbBotPrompt']) : '';
                            
                            $systemInstruction = "You are a warm, polite, and helpful human sales and customer support assistant at {$storeTitle} in Bangladesh.\n"
                                . "Speak naturally like a friendly, courteous shop representative (e.g. 'জি ভাইয়া...', 'জি আপু...', smiling emojis 😊).\n\n"
                                . (!empty($customPrompt) ? "STORE OWNER CUSTOM INSTRUCTIONS (FOLLOW CAREFULLY):\n{$customPrompt}\n\n" : "")
                                . "CRITICAL RULES:\n"
                                . "1. SALAM / GREETING RULE (VERY STRICT): NEVER say 'আসসালামু আলাইকুম' or greetings in every message! ONLY say 'আসসালামু আলাইকুম' if the customer says salam or greets first. In regular questions (e.g. asking price, details, availability, delivery charge), jump straight into answering politely starting with 'জি ভাইয়া...' or 'জি আপু...' (e.g. 'জি ভাইয়া, এটার অফার মূল্য মাত্র ৳...').\n"
                                . "2. PRODUCT DETAILS: If a product is matched in context, state its name and price clearly and warmly.\n"
                                . "3. DO NOT SPAM WEBSITE LINKS: Do NOT send website links in every sentence. ONLY provide a product link or website link if the customer specifically asks for an online order link, website link, or how to buy online. If they ask about price, details, greetings, or other questions, answer warmly and helpfully WITHOUT any links.\n"
                                . "4. HUMAN-LIKE TONE: Reply in 1-3 natural, courteous sentences in Bengali (or English if the customer wrote in English). Never sound robotic, cold, or abrupt.";

                            $wantsLink = preg_match('/(link|website|web|সাইট|ওয়েবসাইট|লিংক|অর্ডার লিংক|অনলাইন|কোথায় পাব|web site|site|order link)/ui', $message_text);

                            $context = "";
                            if ($foundProduct) {
                                $price = $foundProduct['isFlashDeal'] && $foundProduct['flashDealPrice'] ? $foundProduct['flashDealPrice'] : $foundProduct['basePrice'];
                                $link = "https://" . ($_SERVER['HTTP_HOST'] ?? 'ideamartbd.com') . "/p/" . $foundProduct['slug'];
                                $stockNum = (int)($foundProduct['totalStock'] ?? 0);
                                $stockStatus = ($stockNum > 0) ? "In Stock ({$stockNum} pieces available)" : "Stock Out / Temporarily Finished";
                                $varInfo = !empty($foundProduct['variantBreakdown']) ? " | Available Variants & Colors: " . $foundProduct['variantBreakdown'] : "";
                                $catInfo = !empty($foundProduct['categoryName']) ? " | Category: " . $foundProduct['categoryName'] : "";
                                
                                $context .= "Product Found in Database: {$foundProduct['name']}.\nPrice: ৳" . number_format($price) . "{$catInfo}.\nStock Status: {$stockStatus}{$varInfo}.\n";
                                if ($wantsLink) {
                                    $context .= "The customer specifically asked for a link or online order. Order Link: {$link}\n";
                                } else {
                                    $context .= "Mention the price (৳" . number_format($price) . ") and stock availability/quantity warmly. Do NOT include the link unless asked.\n";
                                }
                            } elseif ($wantsLink) {
                                $context .= "The customer asked for the store website link: https://" . ($_SERVER['HTTP_HOST'] ?? 'ideamartbd.com') . "\n";
                            }

                            $userPrompt = "Customer Message: \"$message_text\"\n{$context}Reply naturally:";
                            $aiReply = callAIText($userPrompt, $settings, $systemInstruction);
                            if ($aiReply && !empty(trim($aiReply))) {
                                $reply = trim($aiReply);
                            }
                        } elseif ($foundProduct) {
                            // Static Fallback if API fails
                            $price = $foundProduct['isFlashDeal'] && $foundProduct['flashDealPrice'] ? $foundProduct['flashDealPrice'] : $foundProduct['basePrice'];
                            $stockNum = (int)($foundProduct['totalStock'] ?? 0);
                            $stockStatus = ($stockNum > 0) ? "ইন স্টক ({$stockNum} পিস উপলব্ধ) ✅" : "সাময়িকভাবে স্টক শেষ ⚠️";
                            $reply = "জি ভাইয়া/আপু, " . $foundProduct['name'] . " আমাদের কাছে " . $stockStatus . "\nমূল্য: ৳" . number_format($price) . "\nঅর্ডার করতে ইনবক্সে আপনার নাম, ঠিকানা ও ফোন নাম্বার দিন অথবা ভিজিট করুন: https://" . ($_SERVER['HTTP_HOST'] ?? 'ideamartbd.com') . "/p/" . $foundProduct['slug'];
                        }

                        $reply = mb_substr(trim($reply), 0, 1950);
                        sendFbRequest("me/messages", ['recipient' => ['id' => $sender_id], 'message' => ['text' => $reply], 'messaging_type' => 'RESPONSE'], $page_access_token);

                        // Send carousel if product matched OR if user has shopping/product intent
                        $isGreetingOnly = preg_match('/^(hi|hello|hey|salam|assalamu\s*alaikum|হাই|হ্যালো|সালাম|আসসালামু\s*আলাইকুম|কেমন\s*আছেন|good\s*morning|good\s*afternoon|good\s*evening)$/ui', trim($message_text));
                        $hasShoppingIntent = !empty($foundProduct) || preg_match('/(দাম|কত|কতো|প্রাইস|price|cost|product|পণ্য|প্রোডাক্ট|কালেকশন|দেখাও|দেখান|আছে|কিনতে|order|অর্ডার|চাই|পাব|হবে)/ui', $message_lower);

                        if (!$isGreetingOnly && ($foundProduct || $hasShoppingIntent)) {
                            $carouselItems = getCarouselProductsForMessage($pdo, $message_lower, $foundProduct, 4);
                            if (!empty($carouselItems)) {
                                sendFbCarousel($sender_id, $carouselItems, $page_access_token);
                            }
                        }
                    }
                }
            }

            // 2. Handle Feed Comments
            if (isset($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (($change['field'] ?? '') === 'feed' && ($change['value']['item'] ?? '') === 'comment' && ($change['value']['verb'] ?? '') === 'add') {
                        $comment_id = $change['value']['comment_id'] ?? '';
                        $sender_id = $change['value']['from']['id'] ?? '';
                        $sender_name = $change['value']['from']['name'] ?? 'there';
                        $message_text = trim($change['value']['message'] ?? '');
                        $message_lower = mb_strtolower($message_text);
                        $photo_url = $change['value']['photo'] ?? null;
                        
                        if (empty($comment_id)) continue;
                        // Avoid responding to comments made by the Page itself
                        if (!empty($settings['fbPageId']) && (string)$sender_id === (string)$settings['fbPageId']) continue;
                        
                        // Deduplicate: avoid replying multiple times to the exact same comment
                        $cmtHash = md5($comment_id);
                        $dedupFile = sys_get_temp_dir() . '/fb_cmt_' . $cmtHash;
                        if (@file_exists($dedupFile) && (time() - @filemtime($dedupFile)) < 300) {
                            continue;
                        }
                        @touch($dedupFile);
                        
                        // Check intent in English, Banglish, and Bengali Unicode
                        $hasPricingIntent = preg_match('/price|details|dam|koto|chai|nibo|how much|available|ki|cost|deliver|order|দাম|কত|কতো|দর|অর্ডার|নিতে|চাই|পাবো|ডেলিভারি|প্রাইস|ডিটেইলস|বিস্তারিত|সাইজ|কালার|রং|পণ্য|আছে|কোথায়|ফোন|নাম্বার/ui', $message_text);

                        // Smart Vision AI for Comments with photos
                        if ($photo_url) {
                            $aiKeywords = identifyProductFromImage($photo_url, $settings);
                            if (!empty($aiKeywords)) {
                                $message_lower .= ' ' . mb_strtolower(trim($aiKeywords));
                                $hasPricingIntent = true;
                            }
                        }
                        
                        $hasAiKey = ($settings['activeAiProvider'] === 'openai' && !empty($settings['openaiApiKey'])) || ($settings['activeAiProvider'] !== 'openai' && !empty($settings['geminiApiKey']));
                        
                        // If AI is configured, reply to ANY real customer comment (unless sentiment is negative/spam)
                        if ($hasAiKey) {
                            $hasPricingIntent = true;
                        }

                        if ($hasPricingIntent) {
                            $foundProduct = findProductFromText($pdo, $message_lower);
                            $defaultCommentReply = !empty($settings['fbCommentReply']) ? $settings['fbCommentReply'] : 'ধন্যবাদ! বিস্তারিত জানতে আপনার ইনবক্স চেক করুন অথবা ইনবক্সে মেসেজ দিন। 😊';
                            $reply_msg = $defaultCommentReply;
                            
                            if ($hasAiKey) {
                                $storeTitle = $settings['storeName'] ?? 'Idea Mart';
                                $customPrompt = !empty(trim($settings['fbBotPrompt'] ?? '')) ? trim($settings['fbBotPrompt']) : '';

                                $commentSysInstruction = "You are a warm, polite Facebook Page representative for {$storeTitle}.\n"
                                    . (!empty($customPrompt) ? "STORE OWNER CUSTOM INSTRUCTIONS:\n{$customPrompt}\n\n" : "")
                                    . "INSTRUCTIONS:\n"
                                    . "1. If abusive, offensive, foul language, or spam: output exactly [HIDE] and nothing else.\n"
                                    . "2. DO NOT say 'আসসালামু আলাইকুম' or greetings unless the commenter greeted first. Reply politely starting with 'জি ভাইয়া...' or 'জি আপু...' (e.g. 'জি ভাইয়া, এটার বর্তমান অফার মূল্য মাত্র ৳...').\n"
                                    . "3. DO NOT post naked website links or spam URLs in public comments. State the price/details politely and invite them to check inbox or message for order/details.\n"
                                    . "4. Keep it natural, human-like, and warm with smiling emojis 😊.";

                                $commentContext = "";
                                if ($foundProduct) {
                                    $price = $foundProduct['isFlashDeal'] && $foundProduct['flashDealPrice'] ? $foundProduct['flashDealPrice'] : $foundProduct['basePrice'];
                                    $commentContext .= "Product: {$foundProduct['name']}. Price: ৳" . number_format($price) . ".\n";
                                }

                                $commentPrompt = "Customer Comment: \"$message_text\"\n{$commentContext}Reply politely:";
                                $aiReply = callAIText($commentPrompt, $settings, $commentSysInstruction);
                                if ($aiReply && !empty(trim($aiReply))) {
                                    $aiReply = trim($aiReply);
                                    if (strpos($aiReply, '[HIDE]') === 0 || strpos($aiReply, '[HIDE]') !== false) {
                                        // Auto-Hide the Negative/Abusive Comment
                                        sendFbRequest($comment_id, ['is_hidden' => true], $page_access_token);
                                        try {
                                            $notifStmt = $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'Negative Comment Hidden', ?, 'alert')");
                                            $notifStmt->execute(["An abusive/negative comment by $sender_name was hidden automatically. Comment: \"$message_text\""]);
                                        } catch (Exception $e) {}
                                        
                                        continue; // Skip reply
                                    } else {
                                        $reply_msg = $aiReply;
                                    }
                                }
                            }
                            
                            $reply_msg = mb_substr(trim($reply_msg), 0, 1950);
                            $commentRes = sendFbRequest("{$comment_id}/comments", ['message' => "Hi {$sender_name}, {$reply_msg}"], $page_access_token);
                            
                            // If public comment reply fails (e.g. 403 / #200 Permissions error or nested comment), attempt Private Reply!
                            if (empty($commentRes['code']) || $commentRes['code'] >= 400) {
                                $privRes = sendFbRequest("me/messages", [
                                    'recipient' => ['comment_id' => $comment_id],
                                    'message' => ['text' => "Hi {$sender_name}, {$reply_msg}"]
                                ], $page_access_token);

                                if (empty($privRes['code']) || $privRes['code'] >= 400) {
                                    $errResp = $commentRes['response'] ?? '';
                                    if (strpos($errResp, '(#200)') !== false || strpos($errResp, 'Permissions error') !== false) {
                                        try {
                                            $nStmt = $pdo->prepare("INSERT INTO Notification (userId, title, message, type) VALUES (NULL, 'Facebook Bot Permission Alert', ?, 'alert')");
                                            $nStmt->execute(["Facebook (#200 Permissions error) on comment ($comment_id). Please ensure your Page Access Token has 'pages_manage_engagement' permission and your Meta App is in Live Mode."]);
                                        } catch (Exception $e) {}
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    } else {
        http_response_code(404);
    }
}

function sendFbRequest($endpoint, $data, $token) {
    if (empty($token)) {
        @file_put_contents(__DIR__ . '/fb_error.log', date('Y-m-d H:i:s') . " - FB Error: Empty Page Access Token in settings.\n", FILE_APPEND);
        return false;
    }
    $url = "https://graph.facebook.com/v19.0/{$endpoint}?access_token=" . urlencode($token);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    
    // Log API errors to a file for easy debugging
    if ($httpcode >= 400 || !empty($curl_err)) {
        $logEntry = date('Y-m-d H:i:s') . " - FB API Error [$httpcode] (Endpoint: $endpoint): " . ($curl_err ?: $result) . "\n";
        @file_put_contents(__DIR__ . '/fb_error.log', $logEntry, FILE_APPEND);
    }
    return ['code' => $httpcode, 'response' => $result];
}

function getProductSynonymMap() {
    return [
        'watch' => ['ঘড়ি', 'ঘড়ি', 'ঘড়ির', 'ঘড়ী', 'ঘড়িটি', 'ওয়াচ', 'ওয়াচ', 'স্মার্টওয়াচ', 'স্মার্টওয়াচ', 'smartwatch', 'smart watch', 'watch', 'clock'],
        'fan' => ['ফ্যান', 'ফ্যানের', 'পাখা', 'বাতাস', 'হাতপাখা', 'fan', 'mini fan', 'portable fan', 'rechargeable fan'],
        'earbud' => ['ইয়ারবাড', 'ইয়ারবাড', 'ইয়ারবাডস', 'ইয়ারবাডস', 'হেডফোন', 'ইয়ারফোন', 'ইয়ারফোন', 'ব্লুটুথ', 'airpod', 'airpods', 'earbud', 'earbuds', 'headphone', 'headset', 'tws', 'wireless'],
        'projector' => ['প্রজেক্টর', 'প্রজেক্টরের', 'projector', 'mini projector'],
        'camera' => ['ক্যামেরা', 'সিসিক্যামেরা', 'camera', 'cctv'],
        'router' => ['রাউটার', 'ওয়াইফাই', 'ওয়াইফাই', 'router', 'wifi'],
        'lighter' => ['লাইটার', 'lighter'],
        'spray' => ['স্প্রে', 'পারফিউম', 'আতর', 'বডি স্প্রে', 'spray', 'perfume'],
        'trimmer' => ['ট্রিমার', 'শেভার', 'দাড়ি কাটার মেশিন', 'trimmer', 'shaver'],
        'shoe' => ['জুতা', 'জুতো', 'স্নিকার্স', 'লোফার', 'কেডস', 'shoe', 'shoes', 'sneaker', 'sneakers'],
        'shirt' => ['শার্ট', 'টি-শার্ট', 'টিশার্ট', 'পোলো', 'shirt', 'tshirt', 't-shirt', 'polo'],
        'pant' => ['প্যান্ট', 'জিন্স', 'পায়জামা', 'pant', 'pants', 'jeans', 'trouser'],
        'bag' => ['ব্যাগ', 'ব্যাগপ্যাক', 'পার্স', 'লেডিস ব্যাগ', 'bag', 'backpack', 'purse', 'wallet'],
        'power' => ['পাওয়ার ব্যাংক', 'পাওয়ারব্যাংক', 'powerbank', 'power bank'],
        'charger' => ['চার্জার', 'ক্যাবল', 'তার', 'charger', 'cable'],
        'light' => ['লাইট', 'বাতি', 'টর্চ', 'ল্যাম্প', 'light', 'lamp', 'torch', 'led'],
        'bottle' => ['বোতল', 'ফ্লাস্ক', 'থার্মোফ্লাস্ক', 'bottle', 'flask'],
        'sunglass' => ['সানগ্লাস', 'চশমা', 'sunglass', 'sunglasses'],
        'toy' => ['খেলনা', 'টয়', 'toy', 'toys', 'kids']
    ];
}

function findProductFromText($pdo, $text) {
    if (empty(trim($text))) return null;
    $textLower = mb_strtolower(trim($text), 'UTF-8');
    
    // Ignore greeting-only queries
    if (preg_match('/^(hi|hello|hey|salam|assalamu\s*alaikum|হাই|হ্যালো|সালাম|আসসালামু\s*আলাইকুম|কেমন\s*আছেন|good\s*(morning|evening|afternoon))$/ui', $textLower)) {
        return null;
    }

    try {
        $stmt = $pdo->query("
            SELECT p.id, p.name, p.slug, p.sku, p.basePrice, p.flashDealPrice, p.isFlashDeal, p.imageUrl, p.description,
                   MAX(c.name) as categoryName,
                   COALESCE(SUM(v.stock), 0) as totalStock,
                   GROUP_CONCAT(DISTINCT CONCAT(COALESCE(v.color, 'Default'), ' / ', COALESCE(v.size, 'Standard'), ' (স্টক: ', v.stock, ')') SEPARATOR '; ') as variantBreakdown
            FROM Product p
            LEFT JOIN Category c ON p.categoryId = c.id
            LEFT JOIN Variant v ON p.id = v.productId
            GROUP BY p.id
            ORDER BY p.id DESC
            LIMIT 150
        ");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $stmt = $pdo->query("SELECT id, name, basePrice, flashDealPrice, isFlashDeal, slug, imageUrl, description FROM Product ORDER BY id DESC LIMIT 150");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            return null;
        }
    }

    if (empty($products)) return null;

    $synonymMap = getProductSynonymMap();

    // Check mapped category terms
    $mappedCategoryKeywords = [];
    foreach ($synonymMap as $catKey => $aliases) {
        foreach ($aliases as $alias) {
            if (mb_strpos($textLower, $alias) !== false) {
                $mappedCategoryKeywords[] = $catKey;
                $mappedCategoryKeywords[] = $alias;
                break;
            }
        }
    }
    $mappedCategoryKeywords = array_unique($mappedCategoryKeywords);

    // Extract search tokens
    $ignore = ['er', 'ta', 'ti', 'te', 'ke', 'ye', 'in', 'on', 'to', 'at', 'or', 'so', 'me', 'my', 'we', 'us', 'it', 'is', 'am', 'are', 'ai', 'ei', 'oi', 'ar', 'aar', 'price', 'details', 'dam', 'koto', 'kotoo', 'the', 'of', 'for', 'a', 'an', 'is', 'what', 'inbox', 'hi', 'hello', 'vai', 'bhai', 'apnader', 'kemon', 'achen', 'please', 'chai', 'nibo', 'lagbe', 'হবে', 'পাব', 'দাম', 'কত', 'কতো', 'দর', 'আছে', 'কি', 'ভাই', 'একটু', 'বলেন', 'দেখান', 'দেখাও', 'টা', 'টি', 'তে', 'কে', 'এর'];
    $rawTokens = preg_split('/[\s,\.\?\!\-\_\/\:\;\(\)\[\]]+/u', $textLower, -1, PREG_SPLIT_NO_EMPTY);
    $cleanTokens = array_values(array_filter(array_diff($rawTokens, $ignore), function($w) {
        if (preg_match('/[0-9]/', $w)) {
            return mb_strlen($w) >= 2;
        }
        return mb_strlen($w) >= 3;
    }));

    $bestProduct = null;
    $highestScore = 0;

    foreach ($products as $p) {
        $pNameLower = mb_strtolower($p['name'], 'UTF-8');
        $pDescLower = mb_strtolower(strip_tags($p['description'] ?? ''), 'UTF-8');
        $score = 0;

        // 1. Direct match
        if (mb_strpos($textLower, $pNameLower) !== false) {
            $score += 100;
        }

        // 2. Token match against Name
        foreach ($cleanTokens as $token) {
            if (mb_strpos($pNameLower, $token) !== false) {
                if (preg_match('/[0-9]/', $token) || mb_strlen($token) >= 4) {
                    $score += 40; // High weight for model numbers like T900, M10, Ultra
                } else {
                    $score += 25;
                }
            } elseif (mb_strpos($pDescLower, $token) !== false) {
                $score += 10;
            }
        }

        // 3. Synonym / Category Keyword match
        foreach ($mappedCategoryKeywords as $catKey) {
            if (mb_strpos($pNameLower, $catKey) !== false || mb_strpos($pDescLower, $catKey) !== false) {
                $score += 35;
                break;
            }
        }

        if ($score > $highestScore) {
            $highestScore = $score;
            $bestProduct = $p;
        }
    }

    return ($highestScore >= 20) ? $bestProduct : null;
}

function getCarouselProductsForMessage($pdo, $message_lower, $foundProduct = null, $limit = 4) {
    $products = [];
    $seenIds = [];

    if ($foundProduct && !empty($foundProduct['id'])) {
        $products[] = $foundProduct;
        $seenIds[] = (int)$foundProduct['id'];
    }

    $synonymMap = getProductSynonymMap();
    $catMatches = [];
    foreach ($synonymMap as $catKey => $aliases) {
        foreach ($aliases as $alias) {
            if (mb_strpos($message_lower, $alias) !== false) {
                $catMatches[] = $catKey;
                break;
            }
        }
    }

    // Search by category terms first
    if (!empty($catMatches)) {
        foreach ($catMatches as $cTerm) {
            if (count($products) >= $limit) break;
            $excludeSql = !empty($seenIds) ? "AND id NOT IN (" . implode(',', $seenIds) . ")" : "";
            $stmt = $pdo->prepare("SELECT id, name, basePrice, flashDealPrice, isFlashDeal, slug, imageUrl FROM Product WHERE (name LIKE ? OR description LIKE ?) $excludeSql ORDER BY id DESC LIMIT " . ($limit - count($products)));
            $stmt->execute(["%$cTerm%", "%$cTerm%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $r) {
                if (!in_array((int)$r['id'], $seenIds)) {
                    $products[] = $r;
                    $seenIds[] = (int)$r['id'];
                }
            }
        }
    }

    // If still have space, search tokens
    $ignore = ['price', 'details', 'dam', 'koto', 'kotoo', 'er', 'the', 'of', 'for', 'a', 'an', 'is', 'what', 'inbox', 'hi', 'hello', 'vai', 'bhai', 'apnader', 'kemon', 'achen', 'please', 'chai', 'nibo', 'lagbe', 'হবে', 'পাব', 'দাম', 'কত', 'কতো', 'দর', 'আছে', 'কি', 'ভাই'];
    $words = preg_split('/[\s,\.\?\!\-\_\/\:\;\(\)\[\]]+/u', $message_lower, -1, PREG_SPLIT_NO_EMPTY);
    $cleanWords = array_values(array_filter(array_diff($words, $ignore), function($w) { return mb_strlen($w) >= 3; }));

    if (!empty($cleanWords) && count($products) < $limit) {
        $clauses = [];
        $params = [];
        foreach ($cleanWords as $w) {
            $clauses[] = "name LIKE ? OR description LIKE ?";
            $params[] = "%$w%";
            $params[] = "%$w%";
        }
        $whereSql = implode(' OR ', $clauses);
        $excludeSql = !empty($seenIds) ? "AND id NOT IN (" . implode(',', $seenIds) . ")" : "";
        $rem = $limit - count($products);
        $stmt = $pdo->prepare("SELECT id, name, basePrice, flashDealPrice, isFlashDeal, slug, imageUrl FROM Product WHERE ($whereSql) $excludeSql ORDER BY id DESC LIMIT $rem");
        $stmt->execute($params);
        $matched = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($matched as $m) {
            if (!in_array((int)$m['id'], $seenIds)) {
                $products[] = $m;
                $seenIds[] = (int)$m['id'];
            }
        }
    }

    // Fallback: If customer has shopping intent and products < limit, fetch latest items
    $isShoppingIntent = !empty($foundProduct) || preg_match('/product|পণ্য|প্রোডাক্ট|দাম|কত|কতো|price|cost|দর|item|কিনতে|দেখাও|দেখান|আছে|list|show|shop|order|অর্ডার|picture|ছবি|কালেকশন/ui', $message_lower);
    if ($isShoppingIntent && count($products) < $limit) {
        $excludeSql = !empty($seenIds) ? "WHERE id NOT IN (" . implode(',', $seenIds) . ")" : "";
        $remLimit = max(1, $limit - count($products));
        $stmt = $pdo->query("SELECT id, name, basePrice, flashDealPrice, isFlashDeal, slug, imageUrl FROM Product $excludeSql ORDER BY id DESC LIMIT $remLimit");
        $extras = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($extras as $ex) {
            if (!in_array((int)$ex['id'], $seenIds)) {
                $products[] = $ex;
                $seenIds[] = (int)$ex['id'];
            }
        }
    }

    return array_slice($products, 0, $limit);
}

function sendFbCarousel($sender_id, $products, $page_access_token) {
    if (empty($products) || empty($page_access_token)) return false;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'ideamartbd.com';

    $elements = [];
    foreach ($products as $p) {
        $firstImg = !empty($p['imageUrl']) ? explode(',', $p['imageUrl'])[0] : '';
        if (!empty($firstImg) && !preg_match('/^https?:\/\//i', $firstImg)) {
            $firstImg = $protocol . $host . (str_starts_with($firstImg, '/') ? $firstImg : '/' . $firstImg);
        }
        if (empty($firstImg)) {
            $firstImg = $protocol . $host . '/assets/placeholder.png';
        }

        $link = $protocol . $host . '/p/' . $p['slug'];
        $price = $p['isFlashDeal'] && !empty($p['flashDealPrice']) ? $p['flashDealPrice'] : $p['basePrice'];

        $elements[] = [
            'title' => mb_substr($p['name'], 0, 75),
            'image_url' => $firstImg,
            'subtitle' => "মূল্য: ৳" . number_format((float)$price, 0) . " | ইন স্টক 🛍️",
            'default_action' => [
                'type' => 'web_url',
                'url' => $link,
                'webview_height_ratio' => 'tall'
            ],
            'buttons' => [
                [
                    'type' => 'web_url',
                    'url' => $link,
                    'title' => '🛍️ অর্ডার করুন'
                ]
            ]
        ];
        if (count($elements) >= 10) break;
    }

    if (empty($elements)) return false;

    $payload = [
        'recipient' => ['id' => $sender_id],
        'message' => [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'image_aspect_ratio' => 'square',
                    'elements' => $elements
                ]
            ]
        ],
        'messaging_type' => 'RESPONSE'
    ];

    return sendFbRequest("me/messages", $payload, $page_access_token);
}

function identifyProductFromImage($imageUrl, $settings) {
    $provider = $settings['activeAiProvider'] ?? 'gemini';
    $prompt = "Identify the main e-commerce product in this image. Reply with ONLY the most likely product name or 2-3 highly relevant search keywords. Do not use full sentences.";
    
    if ($provider === 'openai') {
        $apiKey = $settings['openaiApiKey'] ?? '';
        if (empty($apiKey)) return '';
        
        $url = 'https://api.openai.com/v1/chat/completions';
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]]
                    ]
                ]
            ],
            'max_tokens' => 50
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($res, true);
        return $json['choices'][0]['message']['content'] ?? '';
        
    } else {
        $apiKeys = array_filter(array_map('trim', explode(',', $settings['geminiApiKey'] ?? '')));
        if (empty($apiKeys)) return '';
        
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $imageData = curl_exec($ch);
        curl_close($ch);
        
        if (!$imageData) return '';
        $base64 = base64_encode($imageData);
        $configuredModel = !empty($settings['geminiModelVersion']) ? $settings['geminiModelVersion'] : 'gemini-2.5-flash';
        $candidateModels = array_unique([$configuredModel, 'gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-2.5-flash-lite']);

        foreach ($apiKeys as $apiKey) {
            if (empty($apiKey)) continue;
            foreach ($candidateModels as $model) {
                $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
                $postData = [
                    'contents' => [[ 'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $base64]]
                    ]]]
                ];
                $ch = curl_init($geminiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpcode === 200 && $response !== false) {
                    $resData = json_decode($response, true);
                    if (isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
                        return $resData['candidates'][0]['content']['parts'][0]['text'];
                    }
                }
            }
        }
        return '';
    }
}
?>