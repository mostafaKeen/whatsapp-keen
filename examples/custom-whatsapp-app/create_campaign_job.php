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
$templateType = $_POST['templateType'] ?? 'TEXT';
$numbersRaw = $_POST['numbers'] ?? '';
$mediaUrl = $_POST['mediaUrl'] ?? '';

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
    echo json_encode(['status' => 'error', 'message' => 'Source number and App Name are missing from config.php.']);
    exit;
}

// Media URL Validation Helper (Step 3 & 6)
function validateMediaUrl($url) {
    if (empty($url)) return "Media URL is required for this template header.";
    if (strpos($url, 'https://') !== 0) return "Media URL must be public HTTPS and accessible.";
    if (strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) return "Localhost URLs are not allowed.";
    
    // Quick pre-flight check (Step 3)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) return "Media URL is not reachable (HTTP $code). Please ensure it is publicly accessible.";
    return true;
}

if (!empty($mediaUrl) || in_array($templateType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
    $v = validateMediaUrl($mediaUrl);
    if ($v !== true) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $v]);
        exit;
    }
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
    'created_at' => date('Y-m-d H:i:s'),
    'total' => 0,
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'delivered' => 0,
    'read' => 0,
    'webhook_failed' => 0,
    'status' => 'queued',
    'template_type' => $templateType,
    'media_url' => $mediaUrl,
    'targets' => []
];

$processedNumbers = [];
foreach ($numbers as $num) {
    // Basic cleanup, strip non-numeric
    $cleanNum = preg_replace('/[^0-9]/', '', $num);
    if (!empty($cleanNum) && !isset($processedNumbers[$cleanNum])) {
        $jobData['targets'][] = [
            'phone' => $cleanNum,
            'status' => 'pending', 
            'error' => null
        ];
        $processedNumbers[$cleanNum] = true;
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
