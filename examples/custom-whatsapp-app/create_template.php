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
$buttons = $_POST['buttons'] ?? ''; // JSON string from UI
$exampleHeader = $_POST['exampleHeader'] ?? '';
$exampleMedia = $_POST['exampleMedia'] ?? ''; // Media handle if already uploaded or URL
$mediaUrl = $_POST['mediaUrl'] ?? '';
$mediaId = $_POST['mediaId'] ?? '';

if (empty($elementName) || empty($content)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Element Name and Content are required.']);
    exit;
}

$vertical = $category;
if ($vertical === 'UTILITY') $vertical = 'TRANSACTIONAL';
if ($vertical === 'AUTHENTICATION') $vertical = 'OTP';

// Logic for Media Templates (IMAGE, VIDEO, DOCUMENT, GIF)
if (in_array($templateType, ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF'])) {
    if (empty($mediaUrl)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'mediaUrl is required for ' . $templateType . ' template']);
        exit;
    }

    // 1. Upload media to Gupshup to get handleId
    $uploadUrl = "https://partner.gupshup.io/partner/app/$appId/upload/media";
    $mimeMap = [
        'IMAGE' => 'image/jpeg',
        'VIDEO' => 'video/mp4',
        'DOCUMENT' => 'application/pdf',
        'GIF' => 'video/mp4'
    ];
    $fileType = $mimeMap[$templateType] ?? 'image/jpeg';

    $uploadCh = curl_init($uploadUrl);
    curl_setopt($uploadCh, CURLOPT_POST, true);
    curl_setopt($uploadCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($uploadCh, CURLOPT_HTTPHEADER, ["Authorization: $apiToken"]);
    
    // Gupshup upload/media accepts a URL as the 'file' parameter
    curl_setopt($uploadCh, CURLOPT_POSTFIELDS, [
        'file' => $mediaUrl,
        'file_type' => $fileType
    ]);
    curl_setopt($uploadCh, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $uploadResponse = curl_exec($uploadCh);
    $uploadHttpCode = curl_getinfo($uploadCh, CURLINFO_HTTP_CODE);
    $uploadError = curl_error($uploadCh);
    curl_close($uploadCh);

    if ($uploadError || $uploadHttpCode !== 200) {
        http_response_code(500);
        $msg = $uploadError ?: "Media upload failed with HTTP $uploadHttpCode: $uploadResponse";
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }

    $uploadData = json_decode($uploadResponse, true);
    $mediaHandle = $uploadData['handleId']['message'] ?? '';

    if (empty($mediaHandle)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not retrieve media handle from Gupshup response.']);
        exit;
    }

    // 2. Set exampleMedia for template creation
    $exampleMedia = $mediaHandle;
}

$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/templates';

$postData = [
    'elementName' => $elementName,
    'languageCode' => $languageCode,
    'category' => $category,
    'templateType' => $templateType,
    'vertical' => $vertical,
    'content' => $content,
    'example' => $example,
    'enableSample' => 'true',
    'allowTemplateCategoryChange' => $_POST['allowTemplateCategoryChange'] ?? 'false'
];

if (!empty($footer)) {
    $postData['footer'] = $footer;
}
if (!empty($buttons)) {
    $postData['buttons'] = $buttons;
}

// Map exampleMedia for media templates
if (!empty($exampleMedia)) {
    $postData['exampleMedia'] = $exampleMedia;
}

// Include regular header if not a media template
if (!in_array($templateType, ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF'])) {
    if (!empty($header)) {
        $postData['header'] = $header;
    }
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
// Gupshup documentation says: application/x-www-form-urlencoded
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
