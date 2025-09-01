<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../lib/db.php';

try {
    $db = get_db();
    
    // Get recent reactions for the entire session (last 30 seconds)
    $stmt = $db->prepare('
        SELECT emoji, COUNT(*) as count 
        FROM reactions 
        WHERE created_at >= datetime("now", "-30 seconds")
        GROUP BY emoji 
        ORDER BY count DESC
    ');
    $stmt->execute();
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total reactions for the session
    $stmt = $db->prepare('
        SELECT COUNT(*) as total 
        FROM reactions 
    ');
    $stmt->execute();
    $totalReactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'reactions' => $reactions,
        'total_reactions' => $totalReactions
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
