<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../lib/db.php';

// Function to trigger display notifications
function trigger_display_notification($graduate_id) {
    try {
        // Get graduate details
        $db = get_db();
        $graduate = $db->query("
            SELECT id, full_name, student_id, program 
            FROM graduates 
            WHERE id = ?
        ", [$graduate_id])->fetch(PDO::FETCH_ASSOC);
        
        if ($graduate) {
            // Log the notification trigger
            error_log("Display notification triggered for graduate: {$graduate['full_name']} (ID: {$graduate_id})");
            
            // The SSE system will automatically detect the database change
            // and send the update to all connected display pages
        }
    } catch (Exception $e) {
        error_log("Error triggering display notification: " . $e->getMessage());
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

try {
    $db = get_db();
    
    switch ($action) {
        case 'start_detection':
            // Start the Python detection system using the CLI script
            $python_script = __DIR__ . '/../integrations/stage_detection_cli.py';
            if (!file_exists($python_script)) {
                throw new RuntimeException('Detection script not found at: ' . $python_script);
            }
            
            // Test the Python script to ensure it works
            try {
                $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                $python = $isWindows ? 'python' : 'python3';
                
                // Test command - just check if script can run
                $test_command = sprintf(
                    '%s "%s" --help 2>&1',
                    $python,
                    $python_script
                );
                
                $output = [];
                $return_code = 0;
                exec($test_command, $output, $return_code);
                
                if ($return_code !== 0) {
                    throw new RuntimeException('Python script test failed. Output: ' . implode("\n", $output));
                }
                
                $response = ['success' => true, 'message' => 'Detection system ready - will process frames when captured'];
                
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Failed to test Python script: ' . $e->getMessage()];
            }
            break;
            
        case 'stop_detection':
            // Stop the Python detection system (CLI mode - no background process)
            $response = ['success' => true, 'message' => 'Detection system stopped'];
            break;
            
        case 'get_status':
            // Get current detection status and queue information
            $queue_count = $db->query("SELECT COUNT(*) FROM graduates WHERE queued_at IS NOT NULL AND announced_at IS NULL")->fetchColumn();
            $announced_count = $db->query("SELECT COUNT(*) FROM graduates WHERE announced_at IS NOT NULL")->fetchColumn();
            
            // Get next graduate in queue
            $next_graduate = $db->query("
                SELECT id, full_name, student_id, program, queued_at 
                FROM graduates 
                WHERE queued_at IS NOT NULL AND announced_at IS NULL 
                ORDER BY queued_at ASC 
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            // Get current announcement
            $current_announcement = $db->query("
                SELECT g.id, g.full_name, g.student_id, g.program, ca.updated_at
                FROM current_announcement ca
                LEFT JOIN graduates g ON ca.graduate_id = g.id
                WHERE ca.id = 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            // In CLI mode, detection status is managed by JavaScript, not PHP
            // PHP just provides the CLI script when needed
            $detection_running = false; // Let JavaScript control the actual running state
            
            $response = [
                'success' => true,
                'data' => [
                    'detection_running' => $detection_running,
                    'queue_count' => (int)$queue_count,
                    'announced_count' => (int)$announced_count,
                    'next_graduate' => $next_graduate,
                    'current_announcement' => $current_announcement
                ]
            ];
            break;
            
        case 'manual_announce':
            // Manually announce the next graduate
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
            
            // Trigger real-time notification for the display page
            $this->trigger_display_notification($next_graduate['id']);
            
            $response = ['success' => true, 'message' => 'Graduate announced manually'];
            break;
            
        case 'get_queue':
            // Get the current queue of graduates
            $queue = $db->query("
                SELECT id, full_name, student_id, program, queued_at, announced_at
                FROM graduates 
                WHERE queued_at IS NOT NULL 
                ORDER BY queued_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $queue
            ];
            break;
            
        case 'reset_queue':
            // Reset the queue (for testing purposes)
            if ($_POST['confirm'] === 'true') {
                $db->prepare("UPDATE graduates SET queued_at = NULL, announced_at = NULL")->execute();
                $db->prepare("UPDATE current_announcement SET graduate_id = NULL, updated_at = datetime('now') WHERE id = 1")->execute();
                
                $response = ['success' => true, 'message' => 'Queue reset successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Confirmation required'];
            }
            break;
            
        case 'process_stage_frame':
            // Process a stage detection frame using the Python CLI script
            if (empty($_FILES['frame']) || $_FILES['frame']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('No frame uploaded');
            }
            
            $python_script = __DIR__ . '/../integrations/stage_detection_cli.py';
            if (!file_exists($python_script)) {
                throw new RuntimeException('Detection script not found');
            }
            
            // Save uploaded frame temporarily
            $tmpPath = $_FILES['frame']['tmp_name'];
            $uploadsDir = realpath(__DIR__ . '/../uploads');
            if (!$uploadsDir) {
                $uploadsDir = __DIR__ . '/../uploads';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
            }
            $targetPath = $uploadsDir . '/stage_' . uniqid() . '.jpg';
            if (!move_uploaded_file($tmpPath, $targetPath)) {
                throw new RuntimeException('Failed to store uploaded frame');
            }
            
            try {
                // Execute Python script
                $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                $python = $isWindows ? 'python' : 'python3';
                $command = sprintf(
                    '%s "%s" --image_path "%s" --db_path "%s" --output_format json 2>&1',
                    $python,
                    $python_script,
                    $targetPath,
                    realpath(__DIR__ . '/../data/app.sqlite')
                );
                
                $output = [];
                $return_code = 0;
                exec($command, $output, $return_code);
                $json_output = implode("\n", $output);
                $result = json_decode($json_output, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid Python script output: ' . $json_output);
                }
                
                $response = [
                    'success' => true,
                    'message' => 'Stage detection processed successfully',
                    'data' => $result
                ];
                
            } finally {
                // Clean up temporary file
                if (file_exists($targetPath)) {
                    unlink($targetPath);
                }
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action'];
    }
    
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
