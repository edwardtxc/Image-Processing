<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

try {
    if (empty($_FILES['frame']['tmp_name'])) {
        throw new RuntimeException('frame image is required');
    }
    $tmpPath = $_FILES['frame']['tmp_name'];
    $tmpDir = __DIR__ . '/../uploads/tmp';
    if (!is_dir($tmpDir)) { mkdir($tmpDir, 0777, true); }
    $target = $tmpDir . '/' . uniqid('qr_', true) . '.png';
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Failed to save uploaded frame');
    }

    // Allow override via env var to ensure using the same Python as your working script
    $override = getenv('PYTHON_CMD');
    $candidates = $override ? [$override] : ['python'];
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

    $root = realpath(__DIR__ . '/..');
    $script = $root . DIRECTORY_SEPARATOR . 'integrations' . DIRECTORY_SEPARATOR . 'decode_qr.py';
    if (!is_file($script)) { throw new RuntimeException('decode_qr.py not found'); }
    $cmd = $python . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($target);
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
    if (!\is_resource($proc)) { throw new RuntimeException('Failed to start decode process'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    @unlink($target);
    if ($exitCode !== 0 && trim($stdout) === '') { throw new RuntimeException('Decode failed: ' . $stderr); }
    $data = json_decode($stdout, true);
    if (!\is_array($data)) { throw new RuntimeException('Invalid output: ' . $stdout); }
    echo json_encode($data);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $t->getMessage()]);
}


