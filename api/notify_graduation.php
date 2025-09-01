<?php
/**
 * Graduation Notification API
 * Called by the stage detection system to notify about new graduations
 * This triggers real-time updates to all connected display pages
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../lib/db.php';

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    $db = get_db();
    
    // Get the action and graduate ID
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $graduate_id = $_POST['graduate_id'] ?? $_GET['graduate_id'] ?? null;
    
    switch ($action) {
        case 'announce_graduation':
            if (!$graduate_id) {
                throw new RuntimeException('Graduate ID is required');
            }
            
            // Verify the graduate exists and is queued
            $graduate = $db->query("
                SELECT id, full_name, student_id, program 
                FROM graduates 
                WHERE id = ? AND queued_at IS NOT NULL
            ", [$graduate_id])->fetch(PDO::FETCH_ASSOC);
            
            if (!$graduate) {
                throw new RuntimeException('Graduate not found or not queued');
            }
            
            // Update the current announcement
            $db->beginTransaction();
            
            $db->prepare("UPDATE graduates SET announced_at = datetime('now') WHERE id = ?")->execute([$graduate_id]);
            $db->prepare("UPDATE current_announcement SET graduate_id = ?, updated_at = datetime('now') WHERE id = 1")->execute([$graduate_id]);
            
            $db->commit();
            
            // Log the announcement
            error_log("Graduation announced: Graduate ID {$graduate_id} - {$graduate['full_name']}");
            
            $response = [
                'success' => true, 
                'message' => 'Graduation announced successfully',
                'data' => [
                    'graduate_id' => $graduate_id,
                    'graduate_name' => $graduate['full_name'],
                    'timestamp' => date('c')
                ]
            ];
            break;
            
        case 'clear_announcement':
            // Clear the current announcement
            $db->prepare("UPDATE current_announcement SET graduate_id = NULL, updated_at = datetime('now') WHERE id = 1")->execute();
            
            $response = [
                'success' => true, 
                'message' => 'Announcement cleared successfully',
                'data' => [
                    'timestamp' => date('c')
                ]
            ];
            break;
            
        case 'get_current_announcement':
            // Get the current announcement
            $current = $db->query("
                SELECT g.id, g.full_name, g.student_id, g.program, ca.updated_at
                FROM current_announcement ca
                LEFT JOIN graduates g ON ca.graduate_id = g.id
                WHERE ca.id = 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $current
            ];
            break;
            
        default:
            throw new RuntimeException('Unknown action: ' . $action);
    }
    
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
?>
