<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../lib/db.php';

try {
    $graduateId = $_GET['graduate_id'] ?? null;
    
    if (!$graduateId) {
        throw new Exception('Graduate ID is required');
    }
    
    $db = get_db();
    
    $stmt = $db->prepare('
        SELECT id, student_id, full_name, program, cgpa, category 
        FROM graduates 
        WHERE id = ?
    ');
    $stmt->execute([$graduateId]);
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$graduate) {
        throw new Exception('Graduate not found');
    }
    
    echo json_encode([
        'success' => true,
        'graduate' => $graduate
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
