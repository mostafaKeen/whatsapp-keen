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
$templateId = $_POST['templateId'] ?? '';
$elementName = $_POST['elementName'] ?? '';
$languageCode = $_POST['languageCode'] ?? '';
$category = $_POST['category'] ?? '';
$templateType = $_POST['templateType'] ?? 'TEXT';
$content = $_POST['content'] ?? '';
$example = $_POST['example'] ?? '';
$header = $_POST['header'] ?? '';
$footer = $_POST['footer'] ?? '';
$buttons = $_POST['buttons'] ?? '';
$exampleMedia = $_POST['exampleMedia'] ?? '';
$exampleHeader = $_POST['exampleHeader'] ?? '';
$mediaId = $_POST['mediaId'] ?? '';

if (empty($templateId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Template ID is required for editing.']);
    exit;
}

// Gupshup Edit Template API uses PUT
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/templates/' . $templateId;

// Prepare data following Gupshup docs
$postData = [
    'appId' => $appId,
    'templateId' => $templateId,
    'vertical' => 'TEXT',
    'enableSample' => 'true'
];

if (!empty($elementName)) $postData['elementName'] = $elementName;
if (!empty($languageCode)) $postData['languageCode'] = $languageCode;
if (!empty($content)) $postData['content'] = $content;
if (!empty($category)) $postData['category'] = $category;
if (!empty($templateType)) $postData['templateType'] = $templateType;
if (!empty($example)) $postData['example'] = $example;
if (!empty($header)) $postData['header'] = $header;
if (!empty($footer)) $postData['footer'] = $footer;
if (!empty($buttons)) $postData['buttons'] = $buttons;
if (!empty($exampleMedia)) $postData['exampleMedia'] = $exampleMedia;
if (!empty($exampleHeader)) $postData['exampleHeader'] = $exampleHeader;
if (!empty($mediaId)) $postData['mediaId'] = $mediaId;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: ' . $apiToken,
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
