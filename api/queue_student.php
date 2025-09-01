<?php
// Disable error display to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON content type immediately
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Include database with error handling
try {
    require_once __DIR__ . '/../lib/db.php';
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Response structure
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $response['message'] = 'Invalid JSON input';
        echo json_encode($response);
        exit();
    }
    
    $student_id = trim($input['student_id'] ?? '');
    $session_id = (int)($input['session_id'] ?? 0);
    
    if (empty($student_id)) {
        $response['message'] = 'Student ID is required';
        echo json_encode($response);
        exit();
    }
    
    if ($session_id <= 0) {
        $response['message'] = 'Valid session ID is required';
        echo json_encode($response);
        exit();
    }
    
    // Check if student exists and is verified
    $db = get_db();
    $stmt = $db->prepare('SELECT id, full_name, fingerprint_verified_at, queued_at FROM graduates WHERE student_id = ? AND session_id = ?');
    $stmt->execute([$student_id, $session_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $response['message'] = 'Student not found in this session';
        echo json_encode($response);
        exit();
    }
    
    if (empty($student['fingerprint_verified_at'])) {
        $response['message'] = 'Student must be fingerprint verified before queuing';
        echo json_encode($response);
        exit();
    }
    
    if (!empty($student['queued_at'])) {
        $response['message'] = 'Student is already queued';
        $response['data'] = [
            'student_id' => $student_id,
            'student_name' => $student['full_name'],
            'queued_at' => $student['queued_at']
        ];
        echo json_encode($response);
        exit();
    }
    
    // Queue the student
    $update_stmt = $db->prepare('UPDATE graduates SET queued_at = datetime(\'now\') WHERE student_id = ? AND session_id = ?');
    $result = $update_stmt->execute([$student_id, $session_id]);
    
    if ($result) {
        $response['success'] = true;
        $response['message'] = 'Student queued successfully';
        $response['data'] = [
            'student_id' => $student_id,
            'student_name' => $student['full_name'],
            'queued_at' => date('Y-m-d H:i:s')
        ];
    } else {
        $response['message'] = 'Failed to queue student';
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    $response['data'] = ['error_details' => $e->getMessage()];
    error_log("Queue student API error: " . $e->getMessage());
}

// Send response
echo json_encode($response, JSON_PRETTY_PRINT);
?>
