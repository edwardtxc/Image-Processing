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

// Configuration
$config = [
    'uploads_dir' => __DIR__ . '/../uploads',
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'allowed_image_types' => ['image/jpeg', 'image/jpg', 'image/png'],
    'python_script' => __DIR__ . '/../integrations/fingerprint_verification.py'
];

// Response structure
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    // Validate required parameters
    $image_data = $_POST['image_data'] ?? '';
    $session_id = (int)($_POST['session_id'] ?? 0);
    
    if (empty($image_data)) {
        $response['message'] = 'Fingerprint image data is required';
        echo json_encode($response);
        exit();
    }
    
    if ($session_id <= 0) {
        $response['message'] = 'Valid session ID is required';
        echo json_encode($response);
        exit();
    }
    
    // Get all students with fingerprints in this session
    $db = get_db();
    $stmt = $db->prepare('SELECT student_id, full_name, fingerprint_path FROM graduates WHERE session_id = ? AND fingerprint_path IS NOT NULL');
    $stmt->execute([$session_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($students)) {
        $response['message'] = 'No students with fingerprints found in this session';
        echo json_encode($response);
        exit();
    }
    
    // Process image data
    $image_path = null;
    
    // Handle data URL format
    if (preg_match('/^data:image\/(png|jpeg);base64,/', $image_data, $matches)) {
        $comma_pos = strpos($image_data, ',');
        $data = substr($image_data, $comma_pos + 1);
        $data = base64_decode($data);
        
        if ($data === false) {
            $response['message'] = 'Invalid image data format';
            echo json_encode($response);
            exit();
        }
        
        // Create temporary file for captured fingerprint
        $temp_filename = 'detect_fingerprint_' . uniqid() . '.png';
        $temp_path = $config['uploads_dir'] . '/' . $temp_filename;
        
        if (file_put_contents($temp_path, $data) === false) {
            $response['message'] = 'Failed to save captured fingerprint';
            echo json_encode($response);
            exit();
        }
        
        $image_path = $temp_path;
    } else {
        $response['message'] = 'Invalid image data format';
        echo json_encode($response);
        exit();
    }
    
    // Try to match fingerprint against each student
    $best_match = null;
    $best_score = 0;
    
    foreach ($students as $student) {
        $result = verifyFingerprintWithPython($image_path, $student['student_id'], $config);
        
        if ($result['success'] && isset($result['is_valid']) && $result['is_valid']) {
            $score = $result['match_score'] ?? 0;
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $student;
            }
        }
    }
    
    // Clean up temporary file
    if (file_exists($image_path)) {
        unlink($image_path);
    }
    
    if ($best_match && $best_score > 0) {
        $response['success'] = true;
        $response['message'] = 'Student detected successfully';
        $response['data'] = [
            'student_id' => $best_match['student_id'],
            'student_name' => $best_match['full_name'],
            'match_score' => $best_score,
            'confidence' => $best_score
        ];
    } else {
        $response['message'] = 'No matching student found for this fingerprint';
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    $response['data'] = ['error_details' => $e->getMessage()];
    error_log("Detect student from fingerprint API error: " . $e->getMessage());
}

// Send response
echo json_encode($response, JSON_PRETTY_PRINT);

/**
 * Verify fingerprint using Python script
 */
function verifyFingerprintWithPython($image_path, $student_id, $config) {
    try {
        // Check if Python script exists
        if (!file_exists($config['python_script'])) {
            return [
                'success' => false,
                'message' => 'Fingerprint verification script not found',
                'is_valid' => false,
                'match_score' => 0
            ];
        }
        
        // Check if Python is available
        $python = 'python';
        $test_cmd = $python . ' --version 2>&1';
        exec($test_cmd, $test_output, $test_exit_code);
        
        if ($test_exit_code !== 0) {
            return [
                'success' => false,
                'message' => 'Python is not available or not in PATH',
                'is_valid' => false,
                'match_score' => 0
            ];
        }
        
        // Prepare command
        $cmd = $python . ' ' . escapeshellarg($config['python_script']) .
               ' --captured ' . escapeshellarg($image_path) .
               ' --student-id ' . escapeshellarg($student_id) .
               ' --uploads-dir ' . escapeshellarg($config['uploads_dir']);
        
        // Execute Python script
        $output = [];
        $exit_code = 0;
        exec($cmd . ' 2>&1', $output, $exit_code);
        
        if ($exit_code !== 0) {
            $error_output = implode("\n", $output);
            error_log("Python script failed with exit code $exit_code: $error_output");
            return [
                'success' => false,
                'message' => 'Fingerprint verification failed: ' . $error_output,
                'is_valid' => false,
                'match_score' => 0
            ];
        }
        
        // Parse JSON output
        $json_output = implode("\n", $output);
        
        // Try to find JSON in the output (in case there are other messages)
        if (preg_match('/\{.*\}/s', $json_output, $matches)) {
            $json_output = $matches[0];
        }
        
        $result = json_decode($json_output, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg() . " for output: " . $json_output);
            return [
                'success' => false,
                'message' => 'Invalid response from fingerprint verification script: ' . json_last_error_msg(),
                'is_valid' => false,
                'match_score' => 0
            ];
        }
        
        // Return the result with required fields
        return [
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'Unknown error',
            'is_valid' => $result['is_valid'] ?? false,
            'match_score' => $result['match_score'] ?? 0
        ];
        
    } catch (Exception $e) {
        error_log("Exception in verifyFingerprintWithPython: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error executing fingerprint verification: ' . $e->getMessage(),
            'is_valid' => false,
            'match_score' => 0
        ];
    }
}
?>
