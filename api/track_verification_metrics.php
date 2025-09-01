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

// Ensure PHP session is active and capture current session id like other pages do
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : null;

// Initialize database tables for metrics if they don't exist
function initialize_metrics_tables($db) {
    // Verification metrics table
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
    
    // Attendance summary table
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
    // Ensure per-session uniqueness for upserts
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_attendance_summary_session ON attendance_summary(session_id)');
}

// Track verification metrics
function track_verification($db, $data) {
    $stmt = $db->prepare('
        INSERT INTO verification_metrics 
        (student_id, verification_type, verification_method, is_successful, confidence_score, processing_time_ms, session_id, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    // Coerce is_successful robustly to 0/1
    $isSuccessful = 0;
    if (isset($data['is_successful'])) {
        if (is_bool($data['is_successful'])) {
            $isSuccessful = $data['is_successful'] ? 1 : 0;
        } elseif (is_numeric($data['is_successful'])) {
            $isSuccessful = intval($data['is_successful']) ? 1 : 0;
        } elseif (is_string($data['is_successful'])) {
            $val = strtolower(trim($data['is_successful']));
            $isSuccessful = in_array($val, ['1', 'true', 'yes', 'y']) ? 1 : 0;
        }
    }
    
    return $stmt->execute([
        $data['student_id'],
        $data['verification_type'],
        $data['verification_method'],
        $isSuccessful,
        $data['confidence_score'] ?? null,
        $data['processing_time_ms'] ?? null,
        $data['session_id'] ?? null,
        $data['error_message'] ?? null
    ]);
}

// Update attendance summary
function update_attendance_summary($db, $session_id) {
    // Get current metrics for the session
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
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Insert or update attendance summary
    $stmt = $db->prepare('
        INSERT OR REPLACE INTO attendance_summary 
        (session_id, total_students, face_verified_count, fingerprint_verified_count, 
         total_verification_time_ms, average_verification_time_ms, success_rate, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ');
    
    return $stmt->execute([
        $session_id,
        $metrics['total_students'] ?? 0,
        $metrics['face_verified_count'] ?? 0,
        $metrics['fingerprint_verified_count'] ?? 0,
        $metrics['total_verification_time_ms'] ?? 0,
        $metrics['average_verification_time_ms'] ?? 0,
        $metrics['success_rate'] ?? 0
    ]);
}

// Handle POST request to track verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = get_db();
        initialize_metrics_tables($db);
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        // Default to current session if caller didn't provide one
        if (empty($input['session_id']) && $currentSessionId) {
            $input['session_id'] = $currentSessionId;
        }
        
        $required_fields = ['student_id', 'verification_type', 'verification_method', 'is_successful'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Track the verification
        $success = track_verification($db, $input);
        
        if ($success && isset($input['session_id'])) {
            update_attendance_summary($db, $input['session_id']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Verification metrics tracked successfully',
            'data' => $input
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to track verification metrics',
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Handle GET request to retrieve metrics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = get_db();
        initialize_metrics_tables($db);
        
        $session_id = $_GET['session_id'] ?? null;
        // Default to current session if not provided
        if (!$session_id && $currentSessionId) {
            $session_id = $currentSessionId;
        }
        $verification_type = $_GET['verification_type'] ?? null;
        $date_from = $_GET['date_from'] ?? null;
        $date_to = $_GET['date_to'] ?? null;
        
        $where_conditions = [];
        $params = [];
        
        if ($session_id) {
            $where_conditions[] = 'vm.session_id = ?';
            $params[] = $session_id;
        }
        
        if ($verification_type) {
            $where_conditions[] = 'vm.verification_type = ?';
            $params[] = $verification_type;
        }
        
        if ($date_from) {
            $where_conditions[] = 'date(vm.timestamp) >= date(?)';
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = 'date(vm.timestamp) <= date(?)';
            $params[] = $date_to;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Get verification metrics
        $stmt = $db->prepare("
            SELECT 
                vm.*,
                g.full_name,
                s.name as session_name
            FROM verification_metrics vm
            LEFT JOIN graduates g ON vm.student_id = g.student_id
            LEFT JOIN sessions s ON vm.session_id = s.id
            $where_clause
            ORDER BY vm.timestamp DESC
            LIMIT 1000
        ");
        $stmt->execute($params);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get summary statistics (include total_verification_time_ms for UI)
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_verifications,
                SUM(CASE WHEN is_successful = 1 THEN 1 ELSE 0 END) as successful_verifications,
                AVG(CASE WHEN is_successful = 1 THEN confidence_score ELSE NULL END) as avg_confidence,
                AVG(CASE WHEN is_successful = 1 THEN processing_time_ms ELSE NULL END) as avg_processing_time,
                SUM(CASE WHEN is_successful = 1 THEN processing_time_ms ELSE 0 END) as total_verification_time_ms,
                MIN(timestamp) as first_verification,
                MAX(timestamp) as last_verification
            FROM verification_metrics vm
            $where_clause
        ");
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get attendance summary
        $stmt = $db->prepare("
            SELECT * FROM attendance_summary
            " . ($session_id ? 'WHERE session_id = ?' : '') . "
            ORDER BY updated_at DESC
        ");
        if ($session_id) {
            $stmt->execute([$session_id]);
        } else {
            $stmt->execute();
        }
        $attendance_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'metrics' => $metrics,
                'summary' => $summary,
                'attendance_summary' => $attendance_summary
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to retrieve verification metrics',
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>
