<?php
declare(strict_types=1);

/**
 * get_lead_fields.php
 * Fetches lead field definitions from Bitrix24 to find the dynamic "Segment" field.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
error_reporting(0);

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');

$apiUrl = $webhookUrl . '/crm.lead.fields.json';

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['result'])) {
    http_response_code($httpCode);
    echo $response;
    exit;
}

$segmentField = null;
foreach ($data['result'] as $key => $field) {
    $labels = [
        $field['listLabel'] ?? '',
        $field['formLabel'] ?? '',
        $field['filterLabel'] ?? '',
        $field['title'] ?? ''
    ];
    
    foreach ($labels as $label) {
        if ($label && stripos($label, 'Segment') !== false) {
            $segmentField = [
                'key' => $key,
                'title' => $label,
                'items' => $field['items'] ?? []
            ];
            break 2;
        }
    }
}

echo json_encode([
    'success' => true,
    'segmentField' => $segmentField
]);
