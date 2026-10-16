<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    // Ensure rows exist to prevent silent UPDATE failures
    $pdo->exec("INSERT IGNORE INTO SettingNotification (id) VALUES (1)");
    $pdo->exec("INSERT IGNORE INTO SettingGeneral (id) VALUES (1)");

    // Get and format the array of Gemini API Keys into a comma-separated string
    $geminiApiKey = isset($_POST['geminiApiKey']) ? (is_array($_POST['geminiApiKey']) ? implode(',', array_filter(array_map('trim', $_POST['geminiApiKey']))) : trim($_POST['geminiApiKey'])) : '';

    // Sanitize Gemini Model Version
    $geminiModelVersion = !empty($_POST['geminiModelVersion']) ? trim($_POST['geminiModelVersion']) : 'gemini-2.5-flash';

    $fbPageAccessToken = trim($_POST['fbPageAccessToken'] ?? '');
    $fbPageId = trim($_POST['fbPageId'] ?? '');

    // Auto-resolve User Token to Page Access Token if possible
    if (!empty($fbPageAccessToken)) {
        $chMe = curl_init("https://graph.facebook.com/v19.0/me?fields=id,name&metadata=1&access_token=" . urlencode($fbPageAccessToken));
        curl_setopt($chMe, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chMe, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chMe, CURLOPT_TIMEOUT, 5);
        $resMe = curl_exec($chMe);
        curl_close($chMe);
        $meData = json_decode($resMe, true);
        $isPage = (isset($meData['metadata']['type']) && $meData['metadata']['type'] === 'page');

        if (!$isPage) {
            $chAcc = curl_init("https://graph.facebook.com/v19.0/me/accounts?fields=id,name,access_token&access_token=" . urlencode($fbPageAccessToken));
            curl_setopt($chAcc, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chAcc, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chAcc, CURLOPT_TIMEOUT, 5);
            $resAcc = curl_exec($chAcc);
            curl_close($chAcc);
            $accData = json_decode($resAcc, true);
            if (!empty($accData['data']) && is_array($accData['data'])) {
                foreach ($accData['data'] as $pg) {
                    if (empty($fbPageId) || (string)$pg['id'] === (string)$fbPageId) {
                        $fbPageAccessToken = $pg['access_token'];
                        if (empty($fbPageId)) $fbPageId = $pg['id'];
                        break;
                    }
                }
            }
        }
    }

    try { $pdo->exec("ALTER TABLE `SettingNotification` ADD COLUMN `siteAiPrompt` TEXT NULL"); } catch (Exception $e) {}

    // 1. Update Social & AI Notifications Settings
    $stmt = $pdo->prepare("UPDATE SettingNotification SET 
        fbAutoReplyEnabled = ?, fbPageId = ?, fbPageAccessToken = ?, 
        fbVerifyToken = ?, fbCommentReply = ?, fbInboxReply = ?,
        fbBotPrompt = ?, siteAiPrompt = ?, activeAiProvider = ?, 
        geminiApiKey = ?, geminiModelVersion = ?, openaiApiKey = ?, 
        geminiPrompt = ?, imagePrompt = ?, fbAutoPosterEnabled = ? WHERE id = 1");
    
    $stmt->execute([
        isset($_POST['fbAutoReplyEnabled']) && $_POST['fbAutoReplyEnabled'] == '1' ? 1 : 0,
        $fbPageId,
        $fbPageAccessToken,
        $_POST['fbVerifyToken'] ?? '',
        $_POST['fbCommentReply'] ?? 'ধন্যবাদ! বিস্তারিত জানতে আপনার ইনবক্স চেক করুন অথবা ইনবক্সে মেসেজ দিন। 😊',
        $_POST['fbInboxReply'] ?? 'Hello! Welcome to our store. How can we assist you today? 😊',
        $_POST['fbBotPrompt'] ?? '',
        $_POST['siteAiPrompt'] ?? '',
        $_POST['activeAiProvider'] ?? 'gemini',
        $geminiApiKey,
        $geminiModelVersion,
        $_POST['openaiApiKey'] ?? '',
        $_POST['geminiPrompt'] ?? '',
        $_POST['imagePrompt'] ?? '',
        isset($_POST['fbAutoPosterEnabled']) && $_POST['fbAutoPosterEnabled'] == '1' ? 1 : 0
    ]);

    // 2. Update Website Advanced Features Settings
    $stmtGen = $pdo->prepare("UPDATE SettingGeneral SET aiChatbotEnabled = ?, cartSyncEnabled = ?, fbtEnabled = ? WHERE id = 1");
    $stmtGen->execute([
        isset($_POST['aiChatbotEnabled']) && $_POST['aiChatbotEnabled'] == '1' ? 1 : 0,
        isset($_POST['cartSyncEnabled']) && $_POST['cartSyncEnabled'] == '1' ? 1 : 0,
        isset($_POST['fbtEnabled']) && $_POST['fbtEnabled'] == '1' ? 1 : 0
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>