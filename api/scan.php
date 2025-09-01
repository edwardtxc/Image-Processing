<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

try {
    $root = realpath(__DIR__ . '/..');
    $script = $root . DIRECTORY_SEPARATOR . 'integrations' . DIRECTORY_SEPARATOR . 'scan_and_verify.py';
    if (!is_file($script)) {
        throw new RuntimeException('Scan script not found');
    }
    // Try multiple python commands for Windows compatibility
    $candidates = ['python'];
    $python = null;
    foreach ($candidates as $cand) {
        $check = @popen($cand . ' -V 2>&1', 'r');
        if ($check) {
            $ver = fgets($check);
            pclose($check);
            if ($ver) { $python = $cand; break; }
        }
    }
    if ($python === null) { $python = 'python'; }
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
    if (!\is_resource($proc)) {
        throw new RuntimeException('Failed to start scanner');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode !== 0 && trim($stdout) === '') {
        throw new RuntimeException('Scan failed: ' . $stderr);
    }
    $data = json_decode($stdout, true);
    if (!\is_array($data)) {
        throw new RuntimeException('Invalid scanner output: ' . $stdout);
    }
    // If student_id exists and face ok, mark attendance
    if (!empty($data['student_id'])) {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM graduates WHERE student_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$data['student_id']]);
        $grad = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($grad) {
            $db->prepare("UPDATE graduates SET attended_at = COALESCE(attended_at, datetime('now')), face_verified_at = COALESCE(face_verified_at, datetime('now')) WHERE id = ?")
               ->execute([$grad['id']]);
            $data['marked_attendance_for'] = $grad['full_name'];
        }
    }
    echo json_encode($data);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $t->getMessage()]);
}


