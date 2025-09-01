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

// Get current session ID from session
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : null;

// Always use the last session as default if none is set
if (!$currentSessionId) {
    try {
        $db = get_db();
        $stmt = $db->query('SELECT id FROM sessions ORDER BY created_at DESC LIMIT 1');
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($session) {
            $currentSessionId = (int)$session['id'];
            // Also update the session to keep it consistent
            $_SESSION['current_session_id'] = $currentSessionId;
            error_log("Auto-selected last session ID: $currentSessionId for fingerprint verification");
        }
    } catch (Exception $e) {
        error_log("Failed to get session ID: " . $e->getMessage());
    }
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
    $student_id = trim($_POST['student_id'] ?? '');
    $image_data = $_POST['image_data'] ?? '';
    
    if (empty($student_id)) {
        $response['message'] = 'Student ID is required';
        echo json_encode($response);
        exit();
    }
    
    if (empty($image_data)) {
        $response['message'] = 'Fingerprint image data is required';
        echo json_encode($response);
        exit();
    }
    
    // Check if student exists and has fingerprint
    $db = get_db();
    $stmt = $db->prepare('SELECT id, full_name, fingerprint_path FROM graduates WHERE student_id = ?');
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $response['message'] = 'Student not found';
        echo json_encode($response);
        exit();
    }
    
    if (empty($student['fingerprint_path'])) {
        $response['message'] = 'No reference fingerprint found for this student';
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
        $temp_filename = 'captured_fingerprint_' . $student_id . '_' . uniqid() . '.png';
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
    
    // Start timing
    $start_time = microtime(true);
    
    // Verify fingerprint using Python script
    $result = verifyFingerprintWithPython($image_path, $student_id, $config);
    
    // Calculate processing time
    $end_time = microtime(true);
    $processing_time_ms = round(($end_time - $start_time) * 1000);
    
    // Clean up temporary file
    if (file_exists($image_path)) {
        unlink($image_path);
    }
    
    if ($result['success']) {
        // Update database if verification successful
        if (isset($result['is_valid']) && $result['is_valid']) {
            $update_stmt = $db->prepare('UPDATE graduates SET fingerprint_verified_at = datetime(\'now\') WHERE student_id = ?');
            $update_stmt->execute([$student_id]);
        }
        
        $response['success'] = true;
        $response['message'] = 'Fingerprint verification completed';
        $response['data'] = [
            'student_id' => $student_id,
            'student_name' => $student['full_name'],
            'fingerprint_validation' => [
                'is_valid' => $result['is_valid'] ?? false,
                'match_score' => $result['match_score'] ?? 0.0,
                'threshold' => $result['threshold'] ?? 0.25,
                'confidence' => $result['match_score'] ?? 0.0
            ]
        ];
        
        // Track verification metrics
        $trackingResult = trackVerificationMetrics($db, $student_id, 'fingerprint', 'fingerprint', 
            $result['is_valid'] ?? false, 
            $result['match_score'] ?? 0.0, 
            $processing_time_ms,
            $currentSessionId ?? null
        );

        // Refresh attendance summary if metrics were recorded
        if ($trackingResult && $currentSessionId) {
            updateAttendanceSummary($db, (int)$currentSessionId);
        }

        if (!$trackingResult) {
            error_log("Failed to track fingerprint verification metrics for student: $student_id");
        } else {
            error_log("Successfully tracked fingerprint verification metrics for student: $student_id, session: $currentSessionId");
        }
    } else {
        $response['message'] = $result['message'] ?? 'Verification failed';
        $response['data'] = [
            'student_id' => $student_id,
            'student_name' => $student['full_name'],
            'fingerprint_validation' => [
                'is_valid' => false,
                'match_score' => 0.0,
                'threshold' => 0.25,
                'confidence' => 0.0
            ]
        ];
        
        // Track failed verification metrics
        $trackingResult = trackVerificationMetrics($db, $student_id, 'fingerprint', 'fingerprint', 
            false, 
            0.0, 
            $processing_time_ms,
            $currentSessionId ?? null,
            $result['message'] ?? 'Verification failed'
        );

        // Refresh attendance summary if metrics were recorded
        if ($trackingResult && $currentSessionId) {
            updateAttendanceSummary($db, (int)$currentSessionId);
        }

        if (!$trackingResult) {
            error_log("Failed to track failed fingerprint verification metrics for student: $student_id");
        } else {
            error_log("Successfully tracked failed fingerprint verification metrics for student: $student_id, session: $currentSessionId");
        }
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    $response['data'] = ['error_details' => $e->getMessage()];
    error_log("Fingerprint verification API error: " . $e->getMessage());
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
                'data' => null
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
                'data' => null
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
                'data' => null
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
                'data' => null
            ];
        }
        
        // Ensure result has required structure
        if (!isset($result['success'])) {
            $result['success'] = false;
        }
        if (!isset($result['data'])) {
            $result['data'] = null;
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Exception in verifyFingerprintWithPython: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error executing fingerprint verification: ' . $e->getMessage(),
            'data' => null
        ];
    }
}

/**
 * Track verification metrics in the database
 */
function trackVerificationMetrics($db, $student_id, $verification_type, $verification_method, $is_successful, $confidence_score, $processing_time_ms, $session_id = null, $error_message = null) {
    try {
        // Initialize metrics table if it doesn't exist
        $db->exec('
            CREATE TABLE IF NOT EXISTS verification_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id TEXT NOT NULL,
                verification_type TEXT NOT NULL,
                verification_method TEXT NOT NULL,
                is_successful BOOLEAN NOT NULL,
                confidence_score REAL,
                processing_time_ms INTEGER,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                session_id INTEGER,
                error_message TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ');
        
        $stmt = $db->prepare('
            INSERT INTO verification_metrics 
            (student_id, verification_type, verification_method, is_successful, confidence_score, processing_time_ms, session_id, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        return $stmt->execute([
            $student_id,
            $verification_type,
            $verification_method,
            $is_successful ? 1 : 0,
            $confidence_score,
            $processing_time_ms,
            $session_id,
            $error_message
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to track verification metrics: " . $e->getMessage());
        return false;
    }
}

function updateAttendanceSummary($db, $session_id) {
    try {
        // Ensure table and unique index exist
        $db->exec('
            CREATE TABLE IF NOT EXISTS attendance_summary (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                total_students INTEGER DEFAULT 0,
                face_verified_count INTEGER DEFAULT 0,
                fingerprint_verified_count INTEGER DEFAULT 0,
                total_verification_time_ms INTEGER DEFAULT 0,
                average_verification_time_ms REAL DEFAULT 0,
                success_rate REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_attendance_summary_session ON attendance_summary(session_id)');

        // Compute fresh metrics
        $stmt = $db->prepare('
            SELECT 
                COUNT(DISTINCT student_id) as total_students,
                SUM(CASE WHEN verification_method = "face" AND is_successful = 1 THEN 1 ELSE 0 END) as face_verified_count,
                SUM(CASE WHEN verification_method = "fingerprint" AND is_successful = 1 THEN 1 ELSE 0 END) as fingerprint_verified_count,
                SUM(CASE WHEN is_successful = 1 THEN processing_time_ms ELSE 0 END) as total_verification_time_ms,
                AVG(CASE WHEN is_successful = 1 THEN processing_time_ms ELSE NULL END) as average_verification_time_ms,
                (CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_successful = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) ELSE 0 END) as success_rate
            FROM verification_metrics 
            WHERE session_id = ?
        ');
        $stmt->execute([$session_id]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Upsert summary row
        $up = $db->prepare('
            INSERT OR REPLACE INTO attendance_summary 
            (session_id, total_students, face_verified_count, fingerprint_verified_count, total_verification_time_ms, average_verification_time_ms, success_rate, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        $up->execute([
            $session_id,
            $metrics['total_students'] ?? 0,
            $metrics['face_verified_count'] ?? 0,
            $metrics['fingerprint_verified_count'] ?? 0,
            $metrics['total_verification_time_ms'] ?? 0,
            $metrics['average_verification_time_ms'] ?? 0,
            $metrics['success_rate'] ?? 0
        ]);
    } catch (Throwable $t) {
        error_log('Failed to update attendance summary: ' . $t->getMessage());
    }
}
?>
