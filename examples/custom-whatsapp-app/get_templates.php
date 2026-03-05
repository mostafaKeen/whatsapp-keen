<?php
declare(strict_types=1);

// Enable error output for debugging
ini_set('display_errors', '1');
error_reporting(E_ALL);

$whatsappConfig = require __DIR__ . '/../config.php';

$appId = $whatsappConfig['gupshup_app_id'];
$apiToken = $whatsappConfig['gupshup_api_token'];

// Typical Gupshup Partner API to get templates
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/templates';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: Bearer ' . $apiToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error]);
} else {
    http_response_code($httpCode);
    echo $response;
}
