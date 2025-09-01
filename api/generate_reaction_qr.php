<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../lib/db.php';

try {
    // Generate reaction URL for the entire session (not tied to specific graduate)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    // Add a nonce so each QR generated is unique even within the same session
    $nonce = str_replace('.', '', uniqid('', true));
    $reactionUrl = $baseUrl . '/pages/reaction.php?n=' . $nonce;
    $qrData = $reactionUrl;
    $qrFilename = 'qr_reaction_session_' . $nonce . '.png';
    $qrPath = __DIR__ . '/../qrcodes/' . $qrFilename;
    
    // Ensure qrcodes directory exists
    if (!is_dir(__DIR__ . '/../qrcodes/')) {
        mkdir(__DIR__ . '/../qrcodes/', 0777, true);
    }
    
    // Generate QR code using Python script
    $pythonScript = __DIR__ . '/../integrations/generate_qr.py';
    
    // Handle Windows vs Unix systems
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $python = $isWindows ? 'python' : 'python3';
    
    $command = sprintf(
        '%s "%s" --data "%s" --out "%s" --size 300 2>&1',
        $python,
        $pythonScript,
        $qrData,
        $qrPath
    );
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('Failed to generate QR code: ' . implode("\n", $output));
    }
    
    // Return QR code info
    echo json_encode([
        'success' => true,
        'qr_code' => [
            'url' => '/qrcodes/' . $qrFilename,
            'reaction_url' => $reactionUrl
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?> 
