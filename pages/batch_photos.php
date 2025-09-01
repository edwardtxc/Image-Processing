<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/email_config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$db = get_db();
$message = '';
$error = '';

$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
if ($currentSessionId === 0) {
    $tmp = $db->query('SELECT id FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
    $currentSessionId = $tmp ? (int)$tmp : 0;
}

function send_simple_email($toEmail, $toName, $subject, $htmlBody, $attachments = []) {
    try {
        $configs = get_alternative_email_configs();
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $phpmailerPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($phpmailerPath)) { require_once $phpmailerPath; }
        }
        $last = '';
        foreach ($configs as $config) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $config['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
                $mail->SMTPSecure = $config['secure'];
                $mail->Port = $config['port'];
                $mail->Timeout = 30;
                $mail->SMTPDebug = 0;
                $mail->setFrom($config['from_email'], $config['from_name']);
                $mail->addAddress($toEmail, $toName);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = strip_tags($htmlBody);

                // Add attachments if provided
                if (is_array($attachments) && !empty($attachments)) {
                    foreach ($attachments as $att) {
                        $filePath = is_array($att) ? ($att['path'] ?? null) : $att;
                        $displayName = is_array($att) && isset($att['name']) ? $att['name'] : (is_string($filePath) ? basename($filePath) : null);
                        if (is_string($filePath) && file_exists($filePath)) {
                            $mail->addAttachment($filePath, $displayName ?: basename($filePath));
                        }
                    }
                }
                $mail->send();
                return ['success' => true];
            } catch (Exception $e) {
                $last = $e->getMessage();
            }
        }
        return ['success' => false, 'message' => ($last ?: 'All SMTP configs failed')];
    } catch (Throwable $t) {
        return ['success' => false, 'message' => $t->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_batch_photos') {
    try {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

        $stmt = $db->prepare('SELECT id, full_name, student_id, program, email, photo_path FROM graduates WHERE session_id IS ? AND email IS NOT NULL AND email != ""');
        $stmt->execute([$currentSessionId]);
        $graduates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0; $skipped = 0; $failed = 0; $failLogs = [];
        foreach ($graduates as $g) {
            // Collect attachment file paths
            $attachments = [];
            $pathFromDb = $g['photo_path'] ?? '';
            if (!empty($pathFromDb)) {
                $fsPath = __DIR__ . '/../' . ltrim($pathFromDb, '/');
                if (file_exists($fsPath) && is_file($fsPath)) {
                    $attachments[] = ['path' => $fsPath, 'name' => basename($fsPath)];
                }
            }
            $ps = $db->prepare('SELECT photo_path FROM ceremony_photos WHERE graduate_id = ? ORDER BY created_at ASC');
            $ps->execute([$g['id']]);
            foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $fsPath = __DIR__ . '/../' . ltrim($p, '/');
                if (file_exists($fsPath) && is_file($fsPath)) {
                    $attachments[] = ['path' => $fsPath, 'name' => basename($fsPath)];
                }
            }
            if (empty($attachments)) { $skipped++; continue; }

            $body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#333">'
                . '<p>Dear ' . h($g['full_name']) . ',</p>'
                . '<p>Your graduation photos are attached to this email. You can download them directly from the attachments.</p>'
                . '<p>Congratulations on your achievement! 🎓</p>'
                . '<p style="color:#666">This is an automated message from the Graduation System.</p>'
                . '</div>';

            $res = send_simple_email($g['email'], $g['full_name'], 'Your Graduation Photos', $body, $attachments);
            if ($res['success']) { $sent++; } else { $failed++; $failLogs[] = $g['email'] . ': ' . $res['message']; }
        }

        $message = "Batch email complete. Sent: $sent, Skipped (no photos): $skipped, Failed: $failed" . ($failed ? ('; ' . implode('; ', $failLogs)) : '');
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

// Preview stats
$summary = [
    'with_email' => 0,
    'with_any_photo' => 0,
    'total' => 0
];
$stmt = $db->prepare('SELECT id, email, photo_path FROM graduates WHERE session_id IS ?');
$stmt->execute([$currentSessionId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $summary['total']++;
    if (!empty($row['email'])) { $summary['with_email']++; }
    $has = !empty($row['photo_path']);
    if (!$has) {
        $ps = $db->prepare('SELECT 1 FROM ceremony_photos WHERE graduate_id = ? LIMIT 1');
        $ps->execute([$row['id']]);
        $has = (bool)$ps->fetchColumn();
    }
    if ($has) { $summary['with_any_photo']++; }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Send Graduation Photos</title>
    <link rel="stylesheet" href="../css/stage_detection.css">
</head>
<body>
    <div class="stage-container">
        <div class="stage-header">
            <h1>Batch Send Graduation Photos</h1>
            <p>Send photo attachments (attendance + ceremony captures) to all graduates in the current session.</p>
        </div>

        <?php if ($message): ?>
            <div class="message-display message-success"><?php echo h($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message-display message-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="camera-section">
            <h2>Session Summary</h2>
            <ul>
                <li>Total graduates in session: <strong><?php echo (int)$summary['total']; ?></strong></li>
                <li>With email: <strong><?php echo (int)$summary['with_email']; ?></strong></li>
                <li>With any photo available: <strong><?php echo (int)$summary['with_any_photo']; ?></strong></li>
            </ul>
        </div>

        <div class="camera-section">
            <h2>Send Emails</h2>
            <form method="post">
                <input type="hidden" name="action" value="send_batch_photos">
                <p>This will send photo attachments to all graduates in the current session who have at least one photo and a valid email.</p>
                <button type="submit" class="btn-start">Send Photos to All</button>
            </form>
        </div>
    </div>
</body>
</html>


