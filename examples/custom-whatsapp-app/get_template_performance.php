<?php
/**
 * get_template_performance.php
 * ----------------------------------------------------
 * Proxy for fetching detailed Gupshup Template Performance Analytics.
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

$templateId = $_GET['templateId'] ?? '';
$range      = (int)($_GET['range'] ?? 7); // 7, 30, 60, 90

if (!$templateId) {
    echo json_encode(['status' => 'error', 'message' => 'Template ID is required.']);
    exit;
}

// Calculate timestamps
$diff = $range * 86400;
$end  = time();
$start = $end - $diff;

$url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics";
$queryParams = [
    'start'         => $start,
    'end'           => $end,
    'granularity'   => 'DAILY',
    'metric_types'  => 'SENT,DELIVERED,READ,CLICKED',
    'template_ids'  => $templateId,
    'limit'         => 30,
    'product_type'  => 'MARKETING_MESSAGES_LITE_API'
];

$fullUrl = $url . '?' . http_build_query($queryParams);

$ch = curl_init($fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $apiToken,
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
