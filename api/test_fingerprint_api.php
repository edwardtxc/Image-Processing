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

// Simple test response
$response = [
    'success' => true,
    'message' => 'Fingerprint API test successful',
    'timestamp' => date('Y-m-d H:i:s'),
    'test_data' => [
        'student_id' => 'TEST001',
        'fingerprint_validation' => [
            'is_valid' => true,
            'match_score' => 0.85,
            'threshold' => 0.6,
            'confidence' => 0.85
        ]
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>
