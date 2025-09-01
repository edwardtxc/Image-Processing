<?php
require_once __DIR__ . '/../lib/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

header('Content-Type: application/json');

$response = ['ok' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }
    
    $studentId = trim($_POST['student_id'] ?? '');
    $sessionId = (int)($_POST['session_id'] ?? 0);
    
    if (empty($studentId)) {
        throw new RuntimeException('Student ID is required');
    }
    
    if ($sessionId <= 0) {
        throw new RuntimeException('Invalid session ID');
    }
    
    $db = get_db();
    
    // Check if student exists and belongs to the specified session
    $stmt = $db->prepare('SELECT id FROM graduates WHERE student_id = ? AND session_id = ? AND registered_at IS NOT NULL');
    $stmt->execute([$studentId, $sessionId]);
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($graduate) {
        $response['ok'] = true;
        $response['message'] = 'Student belongs to this session';
    } else {
        $response['ok'] = false;
        $response['message'] = 'Student not found in this session or not registered';
    }
    
} catch (Throwable $t) {
    $response['ok'] = false;
    $response['message'] = $t->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
