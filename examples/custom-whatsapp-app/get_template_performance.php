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

// Calculate total time window
$totalDiff = $range * 86400;
$totalEnd  = time();
$totalStart = $totalEnd - $totalDiff;

$allAnalytics = [];
$chunkSize = 5 * 86400; // 5 days in seconds

// Fetch in 5-day chunks to avoid rate limit/timeout on large ranges
for ($currentStart = $totalStart; $currentStart < $totalEnd; $currentStart += $chunkSize) {
    $currentEnd = min($currentStart + $chunkSize, $totalEnd);
    
    $url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics";
    $queryParams = [
        'start'         => $currentStart,
        'end'           => $currentEnd,
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

    if (!$error && $httpCode === 200) {
        $decoded = json_decode($response, true);
        if (isset($decoded['template_analytics']) && is_array($decoded['template_analytics'])) {
            $allAnalytics = array_merge($allAnalytics, $decoded['template_analytics']);
        }
    } else {
        // If a chunk fails, we log it but continue (or we could bail)
        error_log("Analytics chunk failed: " . ($error ?: "HTTP $httpCode"));
    }

    // Small sleep to stay under rate limit if range is large
    if ($range > 7) {
        usleep(200000); // 200ms
    }
}

// deduplicate by template_id + start if necessary, 
// though DAILY granularity over non-overlapping chunks should be clean.

echo json_encode([
    'product_type' => 'MARKETING_MESSAGES_LITE_API',
    'status' => 'success',
    'template_analytics' => $allAnalytics,
    'range' => $range,
    'chunks' => ceil($totalDiff / $chunkSize)
]);
