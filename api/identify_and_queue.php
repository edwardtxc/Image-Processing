<?php
require_once __DIR__ . '/../lib/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$config = [
    'student_photos_dir' => 'uploads',
    'uploads_dir' => 'uploads',
    'identification_threshold' => 0.60  // Lower threshold for better detection
];

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    if (empty($_FILES['frame']) || $_FILES['frame']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No frame uploaded');
    }

    // Get current session ID
    $currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
    if ($currentSessionId <= 0) {
        throw new RuntimeException('No session selected');
    }

    // Persist uploaded frame temporarily
    $tmpPath = $_FILES['frame']['tmp_name'];
    $uploadsDir = realpath(__DIR__ . '/../' . $config['uploads_dir']);
    if ($uploadsDir === false) {
        $uploadsDir = realpath(__DIR__ . '/..') . '/' . $config['uploads_dir'];
        if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0755, true); }
    }
    $targetPath = $uploadsDir . '/identify_' . uniqid() . '.jpg';
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        // Fallback copy if move fails (Windows sometimes)
        if (!copy($_FILES['frame']['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to store uploaded frame');
        }
    }

    // Build list of eligible student_ids: face_verified but not yet queued, from current session
    $db = get_db();
    $stmt = $db->prepare("SELECT student_id FROM graduates WHERE session_id = ? AND face_verified_at IS NOT NULL AND queued_at IS NULL ORDER BY face_verified_at ASC");
    $stmt->execute([$currentSessionId]);
    $eligible = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Write eligible ids to a temp file for Python to filter 1:N search
    $allowedIdsPath = $uploadsDir . '/eligible_' . uniqid() . '.txt';
    file_put_contents($allowedIdsPath, implode("\n", array_map('strval', $eligible)));

    // Resolve script and photos dir
    $script = realpath(__DIR__ . '/../integrations/face_identification_cli.py');
    $photosDir = realpath(__DIR__ . '/../' . $config['student_photos_dir']);
    if (!$script || !$photosDir) {
        throw new RuntimeException('Identification script or photos dir missing');
    }

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $python = $isWindows ? 'python' : 'python3';
    $dq = function($s) { return '"' . str_replace('"', '""', $s) . '"'; };
    $arg = function($s) use ($isWindows, $dq) { return $isWindows ? $dq($s) : escapeshellarg($s); };
    $cmd = $python
        . ' ' . $arg($script)
        . ' --image_path ' . $arg($targetPath)
        . ' --photos_dir ' . $arg($photosDir)
        . ' --allowed_ids_path ' . $arg($allowedIdsPath)
        . ' --threshold ' . ($isWindows ? $dq((string)$config['identification_threshold']) : escapeshellarg((string)$config['identification_threshold']))
        . ' --min_margin ' . ($isWindows ? $dq('0.01') : escapeshellarg('0.01'))
        . ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $json = implode("\n", $output);
    $ident = json_decode($json, true);

    // Cleanup temp files
    if (file_exists($targetPath)) unlink($targetPath);
    if (file_exists($allowedIdsPath)) unlink($allowedIdsPath);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($ident)) {
        $response['success'] = false;
        $response['message'] = 'Invalid identification output';
        $response['data'] = [
            'raw' => $json,
            'output' => $output,
            'return_code' => $code,
            'cmd' => $cmd,
            'photos_dir' => $photosDir,
            'uploads_dir' => $uploadsDir,
            'allowed_ids_path' => $allowedIdsPath,
            'eligible_count' => is_array($eligible) ? count($eligible) : 0,
            'session_id' => $currentSessionId
        ];
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }

    $response['data'] = $ident;
    $response['data']['paths'] = [
        'photos_dir' => $photosDir,
        'uploads_dir' => $uploadsDir,
        'eligible_count' => is_array($eligible) ? count($eligible) : 0,
        'session_id' => $currentSessionId
    ];

    if (!empty($ident['success']) && !empty($ident['identification']['best_student_id'])) {
        $studentId = $ident['identification']['best_student_id'];
        // Queue the graduate atomically (first match by student_id in current session)
        $db->beginTransaction();
        $row = $db->prepare('SELECT id FROM graduates WHERE student_id = ? AND session_id = ? AND queued_at IS NULL ORDER BY face_verified_at ASC LIMIT 1');
        $row->execute([$studentId, $currentSessionId]);
        $found = $row->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $db->prepare('UPDATE graduates SET queued_at = datetime("now") WHERE id = ?')->execute([$found['id']]);
            $db->commit();
            $response['success'] = true;
            $response['message'] = 'Queued graduate ' . $studentId;
            $response['data']['queued_id'] = (int)$found['id'];
        } else {
            $db->rollBack();
            $response['success'] = false;
            $response['message'] = 'Eligible graduate not found in current session or already queued';
        }
    } else {
        $response['success'] = false;
        $response['message'] = $ident['message'] ?? 'Identification failed';
    }
} catch (Throwable $t) {
    $response['success'] = false;
    $response['message'] = $t->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>

 
