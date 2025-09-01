<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../lib/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $emoji = $input['emoji'] ?? null;
    
    if (!$emoji) {
        throw new Exception('Emoji is required');
    }
    
    // Validate emoji
    $allowedEmojis = ['👏', '🎉', '❤️', '🔥', '⭐', '🏆', '💪', '🎓'];
    if (!in_array($emoji, $allowedEmojis)) {
        throw new Exception('Invalid emoji');
    }
    
    $db = get_db();
    
    // Get client IP and user agent
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Insert session reaction (no graduate_id needed)
    $stmt = $db->prepare('
        INSERT INTO reactions (emoji, ip_address, user_agent, created_at) 
        VALUES (?, ?, ?, datetime("now"))
    ');
    $stmt->execute([$emoji, $ipAddress, $userAgent]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Reaction submitted successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
