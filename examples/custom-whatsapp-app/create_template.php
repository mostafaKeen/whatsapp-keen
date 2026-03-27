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
$templateType = $_POST['templateType'] ?? 'TEXT'; // UI value: TEXT, IMAGE, VIDEO, DOCUMENT, GIF
$content = $_POST['content'] ?? '';
$example = $_POST['example'] ?? '';
$headerText = $_POST['header'] ?? '';
$footer = $_POST['footer'] ?? '';
$buttons = $_POST['buttons'] ?? ''; // JSON string from UI
$exampleHeader = $_POST['exampleHeader'] ?? '';
$exampleMedia = $_POST['exampleMedia'] ?? ''; // Sample media URL for media templates

if (empty($elementName) || empty($content)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Element Name and Content are required.']);
    exit;
}

// For media templates, exampleMedia (sample URL) is required by Gupshup
$mediaTypes = ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF'];
$isMediaTemplate = in_array($templateType, $mediaTypes);

if ($isMediaTemplate && empty($exampleMedia)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Templates with a ' . $templateType . ' header type require a Sample Media URL. Please provide a direct HTTPS link to a sample file.']);
    exit;
}

// Gupshup vertical mapping
$vertical = $category;
if ($vertical === 'UTILITY') $vertical = 'TRANSACTIONAL';
if ($vertical === 'AUTHENTICATION') $vertical = 'OTP';

$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/templates';

// Gupshup Partner API requires:
//   - templateType = "MEDIA" for all image/video/document/gif headers
//   - a nested "header" object: {"type":"IMAGE","example":{"header_handle":["https://..."]}}
$postData = [
    'elementName'                  => $elementName,
    'languageCode'                 => $languageCode,
    'category'                     => $category,
    'templateType'                 => $isMediaTemplate ? 'MEDIA' : $templateType,
    'vertical'                     => $vertical,
    'content'                      => $content,
    'example'                      => $example,
    'enableSample'                 => true,
    'allowTemplateCategoryChange'  => ($_POST['allowTemplateCategoryChange'] ?? 'false') === 'true',
];

// Build the header object for media templates
if ($isMediaTemplate) {
    $postData['header'] = [
        'type'    => $templateType, // IMAGE, VIDEO, DOCUMENT, GIF
        'example' => [
            'header_handle' => [$exampleMedia],
        ],
    ];
} elseif (!empty($headerText)) {
    // Text-only templates can have a plain text header
    $postData['header'] = $headerText;
}

if (!empty($footer)) {
    $postData['footer'] = $footer;
}
if (!empty($buttons)) {
    $decodedButtons = json_decode($buttons, true);
    if (is_array($decodedButtons)) {
        $postData['buttons'] = $decodedButtons;
    }
}
if (!empty($exampleHeader)) {
    $postData['exampleHeader'] = $exampleHeader;
}

// Switch to JSON body so nested objects (header, buttons) serialize correctly
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: ' . $apiToken,
    'Content-Type: application/json',
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
