<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

try {
    $db = get_db();
    $row = $db->query('SELECT graduate_id FROM current_announcement WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $response = [ 'graduate' => null ];
    if ($row && $row['graduate_id']) {
        $stmt = $db->prepare('SELECT id, student_id, full_name, program, photo_path, cgpa, category FROM graduates WHERE id = ?');
        $stmt->execute([$row['graduate_id']]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($g) { $response['graduate'] = $g; }
    }
    echo json_encode($response);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['error' => $t->getMessage()]);
}


