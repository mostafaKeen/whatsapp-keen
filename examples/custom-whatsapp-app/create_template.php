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
$languageCode = $_POST['languageCode'] ?? 'en_US';
$category = $_POST['category'] ?? 'MARKETING';
$templateType = $_POST['templateType'] ?? 'TEXT';
$content = $_POST['content'] ?? '';
$example = $_POST['example'] ?? '';
$header = $_POST['header'] ?? '';
$footer = $_POST['footer'] ?? '';

if (empty($elementName) || empty($content) || empty($example)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Element Name, Content, and Example are required.']);
    exit;
}

$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/templates';

$postData = [
    'elementName' => $elementName,
    'languageCode' => $languageCode,
    'category' => $category,
    'templateType' => $templateType,
    'vertical' => 'TEXT',
    'content' => $content,
    'example' => $example,
    'enableSample' => 'true'
];

if (!empty($header)) {
    $postData['header'] = $header;
}
if (!empty($footer)) {
    $postData['footer'] = $footer;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
// Gupshup documentation says: application/x-www-form-urlencoded
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: Bearer ' . $apiToken,
    'Content-Type: application/x-www-form-urlencoded'
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
