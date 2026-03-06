<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$whatsappConfig = require __DIR__ . '/../config.php';

$templateId = $_POST['templateId'] ?? '';
$templateName = $_POST['templateName'] ?? '';
$numbersRaw = $_POST['numbers'] ?? '';

// Credentials from config
$source = $whatsappConfig['gupshup_source'] ?? ''; 
$appName = $whatsappConfig['gupshup_app_name'] ?? '';

// Support variables mapping - for now we just expect an array of arrays or flat if static
// For simplicity, we just extract numbers
$numbers = array_values(array_filter(array_map('trim', explode("\n", $numbersRaw))));

if (empty($templateId) || empty($numbers)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Template ID and Numbers are required.']);
    exit;
}

if (empty($source) || empty($appName)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Source number and App Name are missing from config.php. Please add "gupshup_source" and "gupshup_app_name" to your config file.']);
    exit;
}

$jobId = 'job_' . time() . '_' . bin2hex(random_bytes(4));
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobDir = $BASE_VAR_DIR . '/jobs';
if (!is_dir($jobDir)) {
    mkdir($jobDir, 0777, true);
}

$jobData = [
    'job_id' => $jobId,
    'template_id' => $templateId,
    'template_name' => $templateName,
    'source' => $source,
    'app_name' => $appName,
    'total' => 0,
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'status' => 'queued',
    'targets' => []
];

foreach ($numbers as $num) {
    // Basic cleanup, strip non-numeric
    $cleanNum = preg_replace('/[^0-9]/', '', $num);
    if (!empty($cleanNum)) {
        $jobData['targets'][] = [
            'phone' => $cleanNum,
            'status' => 'pending', 
            'error' => null
        ];
    }
}
$jobData['total'] = count($jobData['targets']);

if ($jobData['total'] === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No valid phone numbers found.']);
    exit;
}

file_put_contents($jobDir . '/' . $jobId . '.json', json_encode($jobData, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'job_id' => $jobId,
    'total' => $jobData['total']
]);
