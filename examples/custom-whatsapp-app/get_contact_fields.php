<?php
declare(strict_types=1);

/**
 * get_contact_fields.php
 * Fetches contact field definitions from Bitrix24 to find the dynamic "Segment" field.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
error_reporting(0);

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');

$apiUrl = $webhookUrl . '/crm.contact.fields.json';

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

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
    // Check all possible label properties for the word "Segment"
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
            break 2; // Found it
        }
    }
}

echo json_encode([
    'success' => true,
    'segmentField' => $segmentField,
    'allFields' => $data['result']
]);
