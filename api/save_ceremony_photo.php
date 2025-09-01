<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../lib/db.php';

try {
    if (empty($_POST['graduate_id'])) {
        throw new RuntimeException('graduate_id is required');
    }
    $graduateId = (int)$_POST['graduate_id'];

    if (empty($_FILES['frame']) || $_FILES['frame']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('frame image is required');
    }

    $db = get_db();
    // Validate graduate exists
    $stmt = $db->prepare('SELECT id FROM graduates WHERE id = ?');
    $stmt->execute([$graduateId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Invalid graduate');
    }

    // Save image
    $dir = __DIR__ . '/../uploads/captured';
    if (!is_dir($dir)) { mkdir($dir, 0777, true); }
    $filename = 'ceremony_' . $graduateId . '_' . date('Ymd_His') . '.jpg';
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['frame']['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to store image');
    }
    $relative = 'uploads/captured/' . $filename;

    // Insert DB record
    $stmt = $db->prepare('INSERT INTO ceremony_photos (graduate_id, photo_path, created_at) VALUES (?, ?, datetime("now"))');
    $stmt->execute([$graduateId, $relative]);

    echo json_encode(['success' => true, 'photo_path' => $relative]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


