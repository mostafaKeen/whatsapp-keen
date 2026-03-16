<?php
/**
 * enable_template_analytics.php
 * ----------------------------------------------------
 * Enables template analytics for the Gupshup app.
 */

header('Content-Type: application/json');

// Setup
require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$appId    = $whatsappConfig['gupshup_app_id'] ?? '';

if (!$apiToken || !$appId) {
    echo json_encode(['status' => 'error', 'message' => 'Gupshup configuration missing.']);
    exit;
}

$url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics";

$data = [
    'enable' => 'true',
    'enableOnUi' => 'true'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $apiToken,
    'Content-Type: application/x-www-form-urlencoded'
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

$decoded = json_decode($response, true);
if ($httpCode !== 200) {
    echo json_encode([
        'status' => 'error',
        'message' => $decoded['message'] ?? 'API returned error code ' . $httpCode,
        'raw' => $decoded
    ]);
    exit;
}

echo $response;
