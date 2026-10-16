<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Fast direct paths to check for PHPMailer without slow recursive disk crawling
$candidatePaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/PHPMailer/src',
    __DIR__ . '/PHPMailer',
    dirname(__DIR__) . '/PHPMailer/src',
    dirname(__DIR__) . '/PHPMailer',
    dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src'
];

$phpmailerSrc = false;
foreach ($candidatePaths as $path) {
    if (str_ends_with($path, 'autoload.php')) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    } else {
        if (file_exists($path . '/PHPMailer.php') && file_exists($path . '/Exception.php') && file_exists($path . '/SMTP.php')) {
            $phpmailerSrc = $path;
            require_once $phpmailerSrc . '/Exception.php';
            require_once $phpmailerSrc . '/PHPMailer.php';
            require_once $phpmailerSrc . '/SMTP.php';
            break;
        }
    }
}

function sendDynamicEmail($pdo, $toEmail, $subject, $htmlContent) {
    global $phpmailerSrc;
    if (!class_exists(PHPMailer::class)) {
        return ['success' => false, 'error' => 'PHPMailer files not found! Searched in ' . dirname(__DIR__) . '. Please install PHPMailer via Composer or run setup_mail.php from your browser.'];
    }

    $stmt = $pdo->query("SELECT n.*, g.storeName FROM SettingNotification n LEFT JOIN SettingGeneral g ON n.id = g.id LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($settings['emailEnabled']) || empty($settings['smtpHost']) || empty($settings['smtpUser'])) {
        return ['success' => false, 'error' => 'Email sending is disabled or SMTP not configured.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $settings['smtpHost'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtpUser'];
        $mail->Password   = $settings['smtpPass'];
        
        // Dynamic Port from settings (Default to 465)
        $mail->Port       = !empty($settings['smtpPort']) ? (int)$settings['smtpPort'] : 465;
        
        // Auto-configure encryption based on Port (465 = SSL, 587 = TLS)
        $mail->SMTPSecure = ($mail->Port === 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS; 

        // Bypass SSL verification for Localhost (XAMPP/WAMP)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        $mail->CharSet    = 'UTF-8';

        $storeName = !empty($settings['storeName']) ? $settings['storeName'] : '';
        $mail->setFrom($settings['smtpUser'], $storeName);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlContent;

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}
?>