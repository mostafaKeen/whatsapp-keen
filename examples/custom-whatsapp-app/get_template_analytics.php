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

// Calculate total time window
$totalEnd   = time();
$totalStart = $totalEnd - $diff;

$chunkSize = 5 * 86400; // 5 days
$aggregatedData = []; // templateId => [metric_type => value]

// Fetch in 5-day chunks
for ($currentStart = $totalStart; $currentStart < $totalEnd; $currentStart += $chunkSize) {
    $currentEnd = min($currentStart + $chunkSize, $totalEnd);
    
    $url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics/{$templateId}/compare";
    $queryParams = [
        'start'        => $currentStart,
        'end'          => $currentEnd,
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

    if (!$error && $httpCode === 200) {
        $decoded = json_decode($response, true);
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $item) {
                $tId = $item['template_id'];
                $mType = $item['metric_type'];
                $val = (float)$item['metric_value'];
                
                if (!isset($aggregatedData[$tId])) {
                    $aggregatedData[$tId] = [];
                }
                
                if (!isset($aggregatedData[$tId][$mType])) {
                    $aggregatedData[$tId][$mType] = [
                        'sum' => 0,
                        'count' => 0
                    ];
                }
                
                $aggregatedData[$tId][$mType]['sum'] += $val;
                $aggregatedData[$tId][$mType]['count']++;
            }
        }
    }

    if ($range > 7) {
        usleep(200000); // 200ms
    }
}

// Format back to original structure
$finalData = [];
foreach ($aggregatedData as $tId => $metrics) {
    foreach ($metrics as $mType => $stats) {
        $value = $stats['sum'];
        // For BLOCK_RATE, we take the average across chunks
        if ($mType === 'BLOCK_RATE' && $stats['count'] > 0) {
            $value = $stats['sum'] / $stats['count'];
        }
        
        $finalData[] = [
            'template_id'  => $tId,
            'metric_type'  => $mType,
            'metric_value' => $value
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'data'   => $finalData,
    'range'  => $range,
    'chunks' => ceil(($totalEnd - $totalStart) / $chunkSize)
]);
