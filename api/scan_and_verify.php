<?php
require_once __DIR__ . '/../lib/db.php';
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

// Configuration
$config = [
    // Student reference photos live here; must match Python validator
    'student_photos_dir' => 'uploads',
    'uploads_dir' => 'uploads',
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'allowed_image_types' => ['image/jpeg', 'image/jpg', 'image/png'],
    'validation_threshold' => 0.65
];

// Response structure
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'timestamp' => date('Y-m-d H:i:s'),
    'request_type' => ''
];

try {
    // Get the request type
    $request_type = $_POST['request_type'] ?? '';
    $response['request_type'] = $request_type;
    
    switch ($request_type) {
        case 'qr_scan':
            handleQRScan($response, $config);
            break;
            
        case 'face_verification':
            handleFaceVerification($response, $config);
            break;
            
        case 'add_student_photo':
            handleAddStudentPhoto($response, $config);
            break;
            
        case 'get_verification_stats':
            handleGetVerificationStats($response, $config);
            break;
            
        default:
            $response['message'] = 'Invalid request type';
            $response['data'] = ['valid_types' => ['qr_scan', 'face_verification', 'add_student_photo', 'get_verification_stats']];
            break;
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    $response['data'] = ['error_details' => $e->getMessage()];
    error_log("Scan and verify API error: " . $e->getMessage());
}

// Send response
echo json_encode($response, JSON_PRETTY_PRINT);

/**
 * Handle QR code scan requests
 */
function handleQRScan(&$response, $config) {
    $qr_data = $_POST['qr_data'] ?? '';
    
    if (empty($qr_data)) {
        $response['message'] = 'QR data is required';
        return;
    }
    
    // Parse QR data (assuming format: student_id|name|other_info)
    $qr_parts = explode('|', $qr_data);
    
    if (count($qr_parts) < 2) {
        $response['message'] = 'Invalid QR code format';
        return;
    }
    
    $student_id = trim($qr_parts[0]);
    $student_name = trim($qr_parts[1]);
    
    // Check if student photo exists
    $photo_exists = checkStudentPhotoExists($student_id, $config['student_photos_dir']);
    
    $response['success'] = true;
    $response['message'] = 'QR code scanned successfully';
    $response['data'] = [
        'student_id' => $student_id,
        'student_name' => $student_name,
        'qr_data' => $qr_data,
        'photo_exists' => $photo_exists,
        'can_proceed_verification' => $photo_exists,
        'next_step' => $photo_exists ? 'face_verification' : 'add_photo_first'
    ];
}

/**
 * Handle face verification requests
 */
function handleFaceVerification(&$response, $config) {
    $student_id = $_POST['student_id'] ?? '';
    $image_data = $_POST['image_data'] ?? '';
    
    if (empty($student_id) || empty($image_data)) {
        $response['message'] = 'Student ID and image data are required';
        return;
    }
    
    // Check if student photo exists
    if (!checkStudentPhotoExists($student_id, $config['student_photos_dir'])) {
        $response['message'] = 'No reference photo found for this student';
        $response['data'] = ['verification_status' => 'student_not_found'];
        return;
    }
    
    // Save the captured image temporarily
    $temp_image_path = saveCapturedImage($image_data, $config['uploads_dir']);
    
    if (!$temp_image_path) {
        $response['message'] = 'Failed to process captured image';
        return;
    }
    
    // Start timing
    $start_time = microtime(true);
    
    // Perform face verification (this would integrate with Python face verification)
    $verification_result = performFaceVerification($student_id, $temp_image_path, $config);
    
    // Calculate processing time
    $end_time = microtime(true);
    $processing_time_ms = round(($end_time - $start_time) * 1000);
    
    // Clean up temporary file
    if (file_exists($temp_image_path)) {
        unlink($temp_image_path);
    }
    
    $response['success'] = $verification_result['success'];
    $response['message'] = $verification_result['message'];
    $response['data'] = $verification_result;
    
    // Track verification metrics
    trackFaceVerificationMetrics($student_id, $verification_result, $processing_time_ms);

    // If verified, persist to DB
    try {
        if (!empty($verification_result['success'])
            && !empty($verification_result['face_validation'])
            && !empty($verification_result['face_validation']['is_valid'])
            && !empty($student_id)) {
            $db = get_db();
            // Find graduate by student_id
            $stmt = $db->prepare('SELECT id FROM graduates WHERE student_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$student_id]);
            $grad = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($grad) {
                $upd = $db->prepare("UPDATE graduates SET face_verified_at = COALESCE(face_verified_at, datetime('now')) WHERE id = ?");
                $upd->execute([$grad['id']]);
                $response['data']['db_persisted'] = true;
            } else {
                $response['data']['db_persisted'] = false;
                $response['data']['db_note'] = 'Graduate not found for student_id';
            }
        }
    } catch (Throwable $t) {
        $response['data']['db_error'] = $t->getMessage();
    }
}

/**
 * Handle adding new student photos
 */
function handleAddStudentPhoto(&$response, $config) {
    $student_id = $_POST['student_id'] ?? '';
    $student_name = $_POST['student_name'] ?? '';
    $image_data = $_POST['image_data'] ?? '';
    
    if (empty($student_id) || empty($image_data)) {
        $response['message'] = 'Student ID and image data are required';
        return;
    }
    
    // Validate student ID format
    if (!preg_match('/^\d+$/', $student_id)) {
        $response['message'] = 'Invalid student ID format';
        return;
    }
    
    // Save the image with proper naming convention
    $filename = "student_{$student_id}_{$student_name}.png";
    $target_path = $config['student_photos_dir'] . '/' . $filename;
    
    // Ensure directory exists
    if (!is_dir($config['student_photos_dir'])) {
        mkdir($config['student_photos_dir'], 0755, true);
    }
    
    // Save image
    if (saveImageFromBase64($image_data, $target_path)) {
        $response['success'] = true;
        $response['message'] = 'Student photo added successfully';
        $response['data'] = [
            'student_id' => $student_id,
            'student_name' => $student_name,
            'filename' => $filename,
            'file_path' => $target_path
        ];
    } else {
        $response['message'] = 'Failed to save student photo';
    }
}

/**
 * Handle getting verification statistics
 */
function handleGetVerificationStats(&$response, $config) {
    $stats = getVerificationStatistics($config['student_photos_dir']);
    
    $response['success'] = true;
    $response['message'] = 'Statistics retrieved successfully';
    $response['data'] = $stats;
}

/**
 * Check if a student photo exists
 */
function checkStudentPhotoExists($student_id, $photos_dir) {
    // Resolve relative to php_app/
    if (!preg_match('/^([A-Za-z]:\\\\|\\/)/', $photos_dir)) { // if not absolute
        $photos_dir = realpath(__DIR__ . '/../' . $photos_dir);
    }
    if ($photos_dir === false || !is_dir($photos_dir)) {
        return false;
    }
    $pattern = "student_{$student_id}_*.{jpg,jpeg,png}";
    $files = glob($photos_dir . '/' . $pattern, GLOB_BRACE);
    return !empty($files);
}

/**
 * Save captured image from base64 data
 */
function saveCapturedImage($base64_data, $uploads_dir) {
    // Remove data URL prefix if present
    $base64_data = preg_replace('/^data:image\/\w+;base64,/', '', $base64_data);
    
    // Decode base64
    $image_data = base64_decode($base64_data);
    
    if ($image_data === false) {
        return false;
    }
    
    // Resolve relative to php_app/ and ensure directory exists
    if (!preg_match('/^([A-Za-z]:\\\\|\\/)/', $uploads_dir)) { // if not absolute
        $uploads_dir = realpath(__DIR__ . '/../') . '/' . $uploads_dir;
    }
    if (!is_dir($uploads_dir)) {
        mkdir($uploads_dir, 0755, true);
    }
    
    // Generate unique filename
    $filename = 'captured_' . uniqid() . '.png';
    $filepath = $uploads_dir . '/' . $filename;
    
    // Save file
    if (file_put_contents($filepath, $image_data)) {
        return $filepath;
    }
    
    return false;
}

/**
 * Save image from base64 data
 */
function saveImageFromBase64($base64_data, $target_path) {
    // Remove data URL prefix if present
    $base64_data = preg_replace('/^data:image\/\w+;base64,/', '', $base64_data);
    
    // Decode base64
    $image_data = base64_decode($base64_data);
    
    if ($image_data === false) {
        return false;
    }
    
    // Save file
    return file_put_contents($target_path, $image_data) !== false;
}

/**
 * Perform face verification by calling Python script
 */
function performFaceVerification($student_id, $captured_image_path, $config) {
    // Get the absolute path to the Python script (under php_app/integrations)
    $script_path = realpath(__DIR__ . '/../integrations/face_verification_cli.py');
    // Resolve photos dir relative to php_app/
    $photos_dir = realpath(__DIR__ . '/../' . $config['student_photos_dir']);
    
    if (!$script_path || !$photos_dir) {
        return [
            'success' => false,
            'student_id' => $student_id,
            'confidence' => 0.0,
            'message' => 'Python script or photos directory not found',
            'face_detected' => false,
            'known_student' => true,
            'verification_status' => 'script_error',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Build the command
    $command = sprintf(
        'python3 "%s" --verify --student_id "%s" --image_path "%s" --photos_dir "%s" --threshold %f --output_format json 2>&1',
        $script_path,
        escapeshellarg($student_id),
        escapeshellarg($captured_image_path),
        escapeshellarg($photos_dir),
        $config['validation_threshold']
    );
    
    // Execute the command
    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);
    
    // Parse the output
    $json_output = implode("\n", $output);
    $verification_result = json_decode($json_output, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If JSON parsing fails, return error
        return [
            'success' => false,
            'student_id' => $student_id,
            'confidence' => 0.0,
            'message' => 'Failed to parse Python script output: ' . $json_output,
            'face_detected' => false,
            'known_student' => true,
            'verification_status' => 'parsing_error',
            'timestamp' => date('Y-m-d H:i:s'),
            'debug_output' => $output,
            'return_code' => $return_code
        ];
    }
    
    // Add timestamp if not present
    if (!isset($verification_result['timestamp'])) {
        $verification_result['timestamp'] = date('Y-m-d H:i:s');
    }
    
    return $verification_result;
}

/**
 * Get verification statistics
 */
function getVerificationStatistics($photos_dir) {
    $stats = [
        'total_known_faces' => 0,
        'student_ids' => [],
        'photos_directory' => $photos_dir,
        'loaded_photos' => []
    ];
    
    if (!is_dir($photos_dir)) {
        return $stats;
    }
    
    $photo_files = glob($photos_dir . '/student_*.{jpg,jpeg,png}', GLOB_BRACE);
    
    foreach ($photo_files as $photo_file) {
        $filename = basename($photo_file);
        
        // Parse filename: student_32313232_jhgj.png
        if (preg_match('/^student_(\d+)_(.+)\.(jpg|jpeg|png)$/i', $filename, $matches)) {
            $student_id = $matches[1];
            $student_name = $matches[2];
            
            if (!in_array($student_id, $stats['student_ids'])) {
                $stats['student_ids'][] = $student_id;
            }
            
            $stats['loaded_photos'][] = [
                'filename' => $filename,
                'student_id' => $student_id,
                'student_name' => $student_name,
                'file_path' => $photo_file
            ];
        }
    }
    
    $stats['total_known_faces'] = count($stats['student_ids']);
    
    return $stats;
}

/**
 * Log API requests for debugging
 */
function logApiRequest($request_type, $data) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_type' => $request_type,
        'data' => $data,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $log_file = '../logs/api_requests.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Track face verification metrics in the database
 */
function trackFaceVerificationMetrics($student_id, $verification_result, $processing_time_ms) {
    try {
        $db = get_db();
        
        // Get current session ID from session
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : null;
        
        // Always use the last session as default if none is set
        if (!$currentSessionId) {
            try {
                $stmt = $db->query('SELECT id FROM sessions ORDER BY created_at DESC LIMIT 1');
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($session) {
                    $currentSessionId = (int)$session['id'];
                    // Also update the session to keep it consistent
                    $_SESSION['current_session_id'] = $currentSessionId;
                    error_log("Auto-selected last session ID: $currentSessionId for face verification");
                }
            } catch (Exception $e) {
                error_log("Failed to get session ID: " . $e->getMessage());
            }
        }
        
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
        
        // Extract verification data
        $is_successful = $verification_result['success'] ?? false;
        $confidence_score = 0.0;
        $error_message = null;
        
        if (isset($verification_result['face_validation'])) {
            $confidence_score = $verification_result['face_validation']['confidence'] ?? 0.0;
        }
        
        if (!$is_successful) {
            $error_message = $verification_result['message'] ?? 'Face verification failed';
        }
        
        $stmt = $db->prepare('
            INSERT INTO verification_metrics 
            (student_id, verification_type, verification_method, is_successful, confidence_score, processing_time_ms, session_id, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $insertOk = $stmt->execute([
            $student_id,
            'face',
            'face',
            $is_successful ? 1 : 0,
            $confidence_score,
            $processing_time_ms,
            $currentSessionId,
            $error_message
        ]);
        
        // Refresh attendance summary for the session so reports page shows latest data
        if ($insertOk && $currentSessionId) {
            updateAttendanceSummary($db, (int)$currentSessionId);
        }
        
        return $insertOk;
        
    } catch (Exception $e) {
        error_log("Failed to track face verification metrics: " . $e->getMessage());
        return false;
    }
}

/**
 * Update aggregated attendance summary for a session from verification_metrics
 */
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
