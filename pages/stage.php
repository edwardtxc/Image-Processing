<?php
require_once __DIR__ . '/../lib/db.php';
@session_start();

$message = '';
$error = '';

// Handle manual actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'manual_announce') {
        try {
            $db = get_db();
            $db->beginTransaction();
            
            $next_graduate = $db->query("
                SELECT id FROM graduates 
                WHERE queued_at IS NOT NULL AND announced_at IS NULL 
                ORDER BY queued_at ASC 
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            if (!$next_graduate) {
                throw new RuntimeException('No queued graduates to announce');
            }
            
            $db->prepare("UPDATE graduates SET announced_at = datetime('now') WHERE id = ?")->execute([$next_graduate['id']]);
            $db->prepare("UPDATE current_announcement SET graduate_id = ?, updated_at = datetime('now') WHERE id = 1")->execute([$next_graduate['id']]);
            
            $db->commit();
            $message = 'Graduate announced manually';
            
        } catch (Throwable $t) {
            if (get_db()->inTransaction()) { get_db()->rollBack(); }
            $error = $t->getMessage();
        }
    }
}

// Get current queue information scoped to session
$db = get_db();
$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : null;
if (!$currentSessionId) {
    $tmp = $db->query('SELECT id FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
    $currentSessionId = $tmp ? (int)$tmp : null;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM graduates WHERE session_id IS ? AND queued_at IS NOT NULL AND announced_at IS NULL");
$stmt->execute([$currentSessionId]);
$queue_count = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM graduates WHERE session_id IS ? AND announced_at IS NOT NULL");
$stmt->execute([$currentSessionId]);
$announced_count = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT id, full_name, student_id, program, queued_at 
    FROM graduates 
    WHERE session_id IS ? AND queued_at IS NOT NULL AND announced_at IS NULL 
    ORDER BY queued_at ASC 
    LIMIT 1
");
$stmt->execute([$currentSessionId]);
$next_graduate = $stmt->fetch(PDO::FETCH_ASSOC);

// Current announcement is the latest announced graduate within the session
$stmt = $db->prepare("
    SELECT id, full_name, student_id, program, announced_at as updated_at
    FROM graduates
    WHERE session_id IS ? AND announced_at IS NOT NULL
    ORDER BY announced_at DESC
    LIMIT 1
");
$stmt->execute([$currentSessionId]);
$current_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stage Detection System</title>
    <link rel="stylesheet" href="../css/stage_detection.css">
</head>
<body>
    <div class="stage-container">
        <!-- Header -->
        <div class="stage-header">
            <h1>Stage Detection System</h1>
            <p>Real-time object detection and sequencing for graduation ceremony</p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message-display message-success"><?php echo h($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message-display message-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <!-- Camera Section -->
        <div class="camera-section">
            <h2>Live Camera Feed</h2>
            <div class="camera-container">
                <video id="stage-camera" autoplay muted playsinline></video>
                <canvas id="stage-canvas"></canvas>
            </div>
            <div class="camera-controls">
                <label for="camera-select" style="margin-right:8px;">Camera:</label>
                <select id="camera-select" style="max-width:320px;"></select>
                <button id="start-camera">Start Camera</button>
                <button id="stop-camera" disabled>Stop Camera</button>
            </div>
        </div>

        <!-- Detection Controls -->
        <div class="detection-controls" id="detection-controls">
            <h2>Detection System Controls</h2>
            <div class="control-buttons">
                <button id="start-detection" class="btn-start">Start Detection</button>
                <button id="stop-detection" class="btn-stop" disabled>Stop Detection</button>
                <button id="manual-announce" class="btn-manual">Manual Announce</button>
                <button id="reset-queue" class="btn-reset">Reset Queue</button>
            </div>
        </div>

        <!-- Status Display -->
        <div class="status-display">
            <div class="status-card">
                <h3>Detection Status</h3>
                <div class="detection-status" id="detection-status">Stopped</div>
                <p>Status: <span id="detection-status-text">Detection system is currently stopped. Click "Start Detection" to begin automatic sequencing.</span></p>
            </div>
            
            <div class="status-card">
                <h3>Queue Information</h3>
                <div id="queue-status">
                    <div class="queue-summary">
                        <div class="queue-item">
                            <span class="label">In Queue</span>
                            <span class="value"><?php echo (int)$queue_count; ?></span>
                        </div>
                        <div class="queue-item">
                            <span class="label">Announced</span>
                            <span class="value"><?php echo (int)$announced_count; ?></span>
                        </div>
                    </div>
                    
                    <?php if ($next_graduate): ?>
                        <div class="next-graduate">
                            <h4>Next in Queue:</h4>
                            <div class="graduate-info">
                                <strong><?php echo h($next_graduate['full_name']); ?></strong><br>
                                <small><?php echo h($next_graduate['student_id']); ?> - <?php echo h($next_graduate['program']); ?></small>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_announcement && $current_announcement['id']): ?>
                        <div class="current-announcement">
                            <h4>Currently Announced:</h4>
                            <div class="graduate-info">
                                <strong><?php echo h($current_announcement['full_name']); ?></strong><br>
                                <small><?php echo h($current_announcement['student_id']); ?> - <?php echo h($current_announcement['program']); ?></small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    
    <!-- JavaScript -->
    <script src="../js/stage_detection.js"></script>
    
    <script>
        // Additional initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Update detection status text
            const statusText = document.getElementById('detection-status-text');
            if (statusText) {
                statusText.textContent = 'Detection system is currently stopped. Click "Start Detection" to begin automatic sequencing.';
            }
            
            // Add zone indicator updates
            function updateZoneIndicators() {
                const indicators = document.querySelectorAll('.zone-indicator');
                indicators.forEach((indicator, index) => {
                    const status = indicator.querySelector('.zone-status');
                    if (index === 1) { // Center zone
                        status.style.background = '#28a745';
                        status.style.boxShadow = '0 0 8px rgba(40, 167, 69, 0.5)';
                    }
                });
            }
            
            // Update zones every few seconds to simulate activity
            setInterval(updateZoneIndicators, 3000);

            // Auto-start camera and detection
            if (window.stageDetectionSystem && typeof window.stageDetectionSystem.startCamera === 'function') {
                window.stageDetectionSystem.startCamera()
                    .then(function() {
                        if (typeof window.stageDetectionSystem.startDetection === 'function') {
                            window.stageDetectionSystem.startDetection();
                        }
                    })
                    .catch(function(err) {
                        console.error('Auto-start camera failed:', err);
                    });
            }
        });
    </script>
</body>
</html>


