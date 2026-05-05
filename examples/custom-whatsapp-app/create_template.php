<?php
declare(strict_types=1);

/**
 * create_template.php - Final Production Fix for Gupshup Partner API
 * -----------------------------------------------------------------
 * Resolves: 
 * - (#100) Invalid media ID
 * - (#132012) Parameter format mismatch
 * - (#132000) Parameter count mismatch
 * - "Template Not Supported" error
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

// 1. Configuration & Auth Setup
$whatsappConfig = require __DIR__ . '/../config.php';
$appId = $whatsappConfig['gupshup_app_id'] ?? '';
$apiToken = $whatsappConfig['gupshup_api_token'] ?? ''; // Raw API Key

if (!$appId || !$apiToken) {
    sendError(500, "Gupshup configuration (app_id/api_token) is missing in config.php");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, "Method Not Allowed. Use POST.");
}

// 2. Map All Input Parameters
$templateType = strtoupper(trim($_POST['templateType'] ?? 'TEXT'));
$category = strtoupper(trim($_POST['category'] ?? 'MARKETING'));
$elementName = trim($_POST['elementName'] ?? '');
$languageCode = trim($_POST['languageCode'] ?? 'en_US');
$content = trim($_POST['content'] ?? '');
$headerText = trim($_POST['header'] ?? '');
$footerText = trim($_POST['footer'] ?? '');
$buttonsJson = trim($_POST['buttons'] ?? '');
$mediaUrl = trim($_POST['mediaUrl'] ?? '');
$exampleFromPost = trim($_POST['example'] ?? ''); // Allow manual example values

if (empty($elementName) || empty($content)) {
    sendError(400, "Template Name (elementName) and Body Content (content) are required.");
}

// 3. Media Upload (If IMAGE/VIDEO/DOCUMENT)
$mediaHandle = '';
$isMediaTemplate = in_array($templateType, ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF']);

if ($isMediaTemplate) {
    if (empty($mediaUrl)) {
        sendError(400, "Media URL is required for a $templateType template.");
    }
    
    $uploadResult = uploadMedia($appId, $apiToken, $mediaUrl, $templateType);
    if (!$uploadResult['success']) {
        sendError(500, "Media Upload Failed: " . $uploadResult['error']);
    }
    $mediaHandle = $uploadResult['handle'];
}

// 4. Build Final Template Registration Payload
$payload = [
    'elementName'   => $elementName,
    'languageCode'  => $languageCode,
    'category'      => $category,
    'templateType'  => $templateType,
    'vertical'      => $category === 'UTILITY' ? 'TRANSACTIONAL' : ($category === 'AUTHENTICATION' ? 'OTP' : 'MARKETING'),
    'content'       => $content,
    'enableSample'  => 'true'
];

// Header handling
if ($isMediaTemplate) {
    // Crucial: For Gupshup Partner API, IMAGE templates MUST have exampleMedia
    $payload['exampleMedia'] = $mediaHandle;
} elseif (!empty($headerText)) {
    $payload['header'] = $headerText;
}

if (!empty($footerText)) {
    $payload['footer'] = $footerText;
}

// Buttons
if (!empty($buttonsJson)) {
    $payload['buttons'] = $buttonsJson;
}

// Variable Examples (#132000 Fix)
// If the user provided specific examples, use them. 
// Otherwise, extract placeholders from content and provide generic samples.
if (!empty($exampleFromPost)) {
    $payload['example'] = $exampleFromPost;
} else {
    preg_match_all('/\{\{(\d+)\}\}/', $content, $matches);
    if (!empty($matches[0])) {
        // We MUST provide exactly as many comma-separated values as there are variables
        $uniqueIdx = array_unique($matches[1]);
        sort($uniqueIdx);
        $samples = [];
        foreach ($uniqueIdx as $i) {
            $samples[] = "Value" . $i;
        }
        $payload['example'] = implode(',', $samples);
    }
}

// 5. Submit to Gupshup
$registerUrl = "https://partner.gupshup.io/partner/app/{$appId}/templates";
$response = makeApiCall($registerUrl, $apiToken, $payload, false);

// Output the raw Gupshup response for transparency
http_response_code($response['httpCode']);
echo $response['body'];

// --- SUPPORT FUNCTIONS ---

/**
 * Handles media upload to Gupshup /upload/media endpoint.
 */
function uploadMedia(string $appId, string $token, string $mediaUrl, string $type): array {
    $url = "https://partner.gupshup.io/partner/app/{$appId}/upload/media";
    
    $mimeMap = [
        'IMAGE'    => 'image/jpeg',
        'VIDEO'    => 'video/mp4',
        'DOCUMENT' => 'application/pdf',
        'GIF'      => 'video/mp4'
    ];
    
    // Multi-part data payload
    $data = [
        'file' => $mediaUrl,
        'file_type' => $mimeMap[$type] ?? 'image/jpeg'
    ];

    $res = makeApiCall($url, $token, $data, true);
    
    if ($res['httpCode'] !== 200) {
        return ['success' => false, 'error' => "Media API returned HTTP {$res['httpCode']}: " . $res['body']];
    }

    $decoded = json_decode($res['body'], true);
    
    // Extract handleId from multiple possible Gupshup formats
    $handle = null;
    if (isset($decoded['handleId']['message'])) {
        $handle = $decoded['handleId']['message'];
    } elseif (isset($decoded['handleId']) && is_string($decoded['handleId'])) {
        $handle = $decoded['handleId'];
    } elseif (isset($decoded['message']) && strlen($decoded['message']) > 15) {
        $handle = $decoded['message'];
    }

    if (!$handle) {
        return ['success' => false, 'error' => "Failed to extract handleId from response: " . $res['body']];
    }

    return ['success' => true, 'handle' => $handle];
}

/**
 * Standardized API caller for Gupshup.
 */
function makeApiCall(string $url, string $token, array $data, bool $isMultipart): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Production settings: No Bearer if the raw key is used in Partner API
    $headers = [
        "Authorization: $token", // Raw API Key as per user's working examples
        "accept: application/json"
    ];

    if ($isMultipart) {
        // CURL automatically sets multipart/form-data for arrays
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        $headers[] = "Content-Type: application/x-www-form-urlencoded";
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['httpCode' => 500, 'body' => json_encode(['status' => 'error', 'message' => "CURL Error: $error"])];
    }

    return ['httpCode' => $httpCode, 'body' => $body];
}

/**
 * Clean exit with error message.
 */
function sendError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}
