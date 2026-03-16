<?php
/**
 * get_template_analytics.php
 * ----------------------------------------------------
 * Proxy for fetching Gupshup Template Comparison Analytics.
 * Handles date range calculations and API authentication.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Setup
require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$appId    = $whatsappConfig['gupshup_app_id'] ?? '';

if (!$apiToken || !$appId) {
    echo json_encode(['status' => 'error', 'message' => 'Gupshup configuration missing.']);
    exit;
}

$templateId   = $_GET['templateId'] ?? '';
$templateList = $_GET['templateList'] ?? ''; // Comparisons, comma separated
$range        = (int)($_GET['range'] ?? 7);  // 7, 30, 60, 90

if (!$templateId) {
    echo json_encode(['status' => 'error', 'message' => 'Template ID is required.']);
    exit;
}

// Handle empty template list gracefully
if (empty($templateList)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

// Gupshup API requires specific differences in seconds:
// 7 days: 604800, 30 days: 2592000, 60 days: 5184000, 90 days: 7776000
$ranges = [
    7  => 604800,
    30 => 2592000,
    60 => 5184000,
    90 => 7776000
];

$diff = $ranges[$range] ?? 604800;
$end   = time();
$start = $end - $diff;

$url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics/{$templateId}/compare";
$queryParams = [
    'start'        => $start,
    'end'          => $end,
    'templateList' => $templateList
];

$fullUrl = $url . '?' . http_build_query($queryParams);

$ch = curl_init($fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $apiToken,
    'token: ' . $apiToken,
    'accept: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['status' => 'error', 'message' => 'CURL Error: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    $decoded = json_decode($response, true);
    echo json_encode([
        'status' => 'error',
        'message' => $decoded['message'] ?? 'API returned error code ' . $httpCode,
        'raw' => $decoded
    ]);
    exit;
}

echo $response;
