<?php
declare(strict_types=1);

// Enable error output for debugging
ini_set('display_errors', '1');
error_reporting(E_ALL);

$whatsappConfig = require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$appId = $whatsappConfig['gupshup_app_id'];
$apiToken = $whatsappConfig['gupshup_api_token'];

// Parameters from POST
$elementName = $_POST['elementName'] ?? '';

if (empty($elementName)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Element Name is required for deletion.']);
    exit;
}

// Gupshup Delete Template API
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/template/' . $elementName;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: ' . $apiToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');

if ($error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error]);
} else {
    http_response_code($httpCode);
    echo $response;
}
