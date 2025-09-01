<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

try {
    if (empty($_POST['student_id'])) {
        throw new RuntimeException('student_id is required');
    }
    if (empty($_FILES['frame']['tmp_name'])) {
        throw new RuntimeException('frame image is required');
    }
    $studentId = trim((string)$_POST['student_id']);
    $tmpPath = $_FILES['frame']['tmp_name'];

    $destDir = __DIR__ . '/../uploads/captured';
    if (!is_dir($destDir)) { mkdir($destDir, 0777, true); }
    $filename = $studentId . '_' . date('Ymd_His') . '.jpg';
    $dest = $destDir . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $dest)) {
        throw new RuntimeException('Failed to save captured image');
    }
    
    $response = [
        'ok' => true, 
        'path' => 'uploads/captured/' . $filename
    ];
    
    echo json_encode($response);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $t->getMessage()]);
}


