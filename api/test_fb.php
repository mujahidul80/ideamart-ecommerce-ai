<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

// Only allow authenticated admins
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT * FROM SettingNotification LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$report = [
    'auto_reply_enabled' => !empty($settings['fbAutoReplyEnabled']),
    'page_id' => $settings['fbPageId'] ?? '',
    'verify_token' => $settings['fbVerifyToken'] ?? '',
    'token_set' => !empty($settings['fbPageAccessToken']),
    'token_valid' => false,
    'page_name' => '',
    'graph_id' => '',
    'permissions' => [],
    'missing_permissions' => [],
    'token_error' => '',
    'ai_provider' => $settings['activeAiProvider'] ?? 'gemini',
    'ai_status' => 'Not tested',
    'ai_error' => '',
    'recent_errors' => []
];

$token = trim($settings['fbPageAccessToken'] ?? '');

if (empty($token)) {
    $report['token_error'] = 'Facebook Page Access Token is empty in settings.';
} else {
    // 1. Test Token with Graph API /me (Query only id, name and metadata to prevent '#100 nonexisting field (category)' error on User tokens)
    $ch = curl_init("https://graph.facebook.com/v19.0/me?fields=id,name&metadata=1&access_token=" . urlencode($token));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $res = curl_exec($ch);
    $curl_err = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!empty($curl_err)) {
        $report['token_error'] = "cURL connection failed: $curl_err";
    } else {
        $metaData = json_decode($res, true);
        if (isset($metaData['id'])) {
            $report['token_valid'] = true;
            $report['graph_id'] = $metaData['id'];
            $report['page_name'] = $metaData['name'] ?? 'Facebook Entity';
            $report['managed_pages'] = [];

            // Determine if token belongs to a User profile or a Page
            $isPage = (isset($metaData['metadata']['type']) && $metaData['metadata']['type'] === 'page');
            $isUser = (isset($metaData['metadata']['type']) && $metaData['metadata']['type'] === 'user');

            // If metadata is indeterminate, check /me/accounts
            $managedPagesData = null;
            $chAcc = curl_init("https://graph.facebook.com/v19.0/me/accounts?fields=id,name,category,access_token&access_token=" . urlencode($token));
            curl_setopt($chAcc, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chAcc, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chAcc, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($chAcc, CURLOPT_TIMEOUT, 10);
            $resAcc = curl_exec($chAcc);
            curl_close($chAcc);
            $managedPagesData = json_decode($resAcc, true);

            if (!empty($managedPagesData['data']) && is_array($managedPagesData['data'])) {
                $isUser = true;
                $isPage = false;
                foreach ($managedPagesData['data'] as $pg) {
                    $report['managed_pages'][] = [
                        'id' => $pg['id'],
                        'name' => $pg['name'] ?? 'Page',
                        'category' => $pg['category'] ?? 'Facebook Page',
                        'access_token' => $pg['access_token'] ?? ''
                    ];
                }
            } else {
                if (!$isUser) {
                    $isPage = true;
                }
            }

            $report['is_page'] = $isPage;
            $report['is_user'] = $isUser;

            // If it's a Page, query its category and ensure Webhook Subscription (subscribed_apps)
            if ($isPage) {
                $chCat = curl_init("https://graph.facebook.com/v19.0/me?fields=category&access_token=" . urlencode($token));
                curl_setopt($chCat, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chCat, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chCat, CURLOPT_TIMEOUT, 6);
                $resCat = curl_exec($chCat);
                curl_close($chCat);
                $catData = json_decode($resCat, true);
                $report['category'] = $catData['category'] ?? 'Facebook Page';

                // Check and auto-enable Page Webhook subscription (subscribed_apps)
                $pageId = $report['graph_id'];
                $chSub = curl_init("https://graph.facebook.com/v19.0/{$pageId}/subscribed_apps?access_token=" . urlencode($token));
                curl_setopt($chSub, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chSub, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chSub, CURLOPT_TIMEOUT, 8);
                $resSub = curl_exec($chSub);
                curl_close($chSub);
                $subData = json_decode($resSub, true);

                $isSubscribed = (!empty($subData['data']) && is_array($subData['data']) && count($subData['data']) > 0);
                if (!$isSubscribed) {
                    $chAutoSub = curl_init("https://graph.facebook.com/v19.0/{$pageId}/subscribed_apps?subscribed_fields=messages,messaging_postbacks,feed&access_token=" . urlencode($token));
                    curl_setopt($chAutoSub, CURLOPT_POST, 1);
                    curl_setopt($chAutoSub, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chAutoSub, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($chAutoSub, CURLOPT_TIMEOUT, 8);
                    $resAutoSub = curl_exec($chAutoSub);
                    curl_close($chAutoSub);
                    $autoSubData = json_decode($resAutoSub, true);
                    if (!empty($autoSubData['success'])) {
                        $isSubscribed = true;
                        $report['auto_subscribed_now'] = true;
                    } else {
                        $report['subscribe_error'] = $autoSubData['error']['message'] ?? 'Webhook not subscribed to Page';
                    }
                }
                $report['page_subscribed'] = $isSubscribed;
            } else {
                $report['category'] = null;
                $report['page_subscribed'] = false;
            }

            // Check permissions
            $chPerm = curl_init("https://graph.facebook.com/v19.0/me/permissions?access_token=" . urlencode($token));
            curl_setopt($chPerm, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chPerm, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chPerm, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($chPerm, CURLOPT_TIMEOUT, 10);
            $resPerm = curl_exec($chPerm);
            curl_close($chPerm);
            $permData = json_decode($resPerm, true);
            if (isset($permData['data']) && is_array($permData['data'])) {
                $granted = [];
                foreach ($permData['data'] as $p) {
                    if (($p['status'] ?? '') === 'granted') {
                        $granted[] = $p['permission'];
                    }
                }
                $report['permissions'] = $granted;

                $required = ['pages_messaging', 'pages_manage_posts', 'pages_read_engagement', 'pages_manage_engagement'];
                foreach ($required as $req) {
                    if (!in_array($req, $granted)) {
                        $report['missing_permissions'][] = $req;
                    }
                }
            }
        } elseif (isset($metaData['error'])) {
            $err = $metaData['error'];
            $report['token_error'] = ($err['message'] ?? 'Unknown Meta error') . " (Code: " . ($err['code'] ?? '') . ", Subcode: " . ($err['error_subcode'] ?? '') . ")";
        } else {
            $report['token_error'] = "Invalid response from Facebook Graph API: $res";
        }
    }
}

// 2. Test AI Connection
$provider = $settings['activeAiProvider'] ?? 'gemini';
if ($provider === 'openai') {
    $apiKey = $settings['openaiApiKey'] ?? '';
    if (empty($apiKey)) {
        $report['ai_status'] = 'Error';
        $report['ai_error'] = 'OpenAI API Key is missing.';
    } else {
        $chAi = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($chAi, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chAi, CURLOPT_POST, true);
        curl_setopt($chAi, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 5
        ]));
        curl_setopt($chAi, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        curl_setopt($chAi, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chAi, CURLOPT_TIMEOUT, 10);
        $resAi = curl_exec($chAi);
        $httpAi = curl_getinfo($chAi, CURLINFO_HTTP_CODE);
        curl_close($chAi);
        if ($httpAi === 200) {
            $report['ai_status'] = 'Connected (OpenAI gpt-4o-mini)';
        } else {
            $report['ai_status'] = 'Error';
            $report['ai_error'] = "OpenAI returned HTTP $httpAi: $resAi";
        }
    }
} else {
    $apiKeys = array_filter(array_map('trim', explode(',', $settings['geminiApiKey'] ?? '')));
    if (empty($apiKeys)) {
        $report['ai_status'] = 'Warning';
        $report['ai_error'] = 'Gemini API Key is not set. Default fallback text will be used.';
    } else {
        $configuredModel = !empty($settings['geminiModelVersion']) ? $settings['geminiModelVersion'] : 'gemini-2.5-flash';
        $candidateModels = array_unique([$configuredModel, 'gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-2.5-flash-lite']);
        $connected = false;
        $lastErr = '';

        foreach ($apiKeys as $keyToTest) {
            if (empty($keyToTest)) continue;
            foreach ($candidateModels as $model) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $keyToTest;
                $chAi = curl_init($url);
                curl_setopt($chAi, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chAi, CURLOPT_POST, true);
                curl_setopt($chAi, CURLOPT_POSTFIELDS, json_encode(['contents' => [['parts' => [['text' => 'Hi']]]]]));
                curl_setopt($chAi, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($chAi, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chAi, CURLOPT_TIMEOUT, 6);
                $resAi = curl_exec($chAi);
                $httpAi = curl_getinfo($chAi, CURLINFO_HTTP_CODE);
                curl_close($chAi);

                if ($httpAi === 200) {
                    $report['ai_status'] = "Connected (Gemini $model)";
                    $report['active_model'] = $model;
                    $connected = true;
                    break 2;
                } else {
                    $lastErr = "Model $model returned HTTP $httpAi: " . substr($resAi, 0, 150);
                }
            }
        }

        if (!$connected) {
            $report['ai_status'] = 'Error';
            $report['ai_error'] = $lastErr;
        }
    }
}

// 3. Read latest lines from fb_error.log if exists
$logPath = __DIR__ . '/fb_error.log';
if (file_exists($logPath)) {
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        $report['recent_errors'] = array_slice($lines, -5);
    }
}

echo json_encode(['success' => true, 'report' => $report]);
