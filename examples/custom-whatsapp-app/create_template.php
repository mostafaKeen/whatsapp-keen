<?php
declare(strict_types=1);

/**
 * create_template.php - Production Ready Gupshup Template Creator
 * -------------------------------------------------------------
 * This script handles media upload and template registration for the Gupshup Partner API.
 * It eliminates common errors like #100, #132012, and #132000 by ensuring strict 
 * payload formatting and handle resolution.
 */

// Production error handling
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

// 1. Setup & Config
$whatsappConfig = require __DIR__ . '/../config.php';
$appId = $whatsappConfig['gupshup_app_id'] ?? '';
$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';

if (!$appId || !$apiToken) {
    sendError(500, "Gupshup credentials missing in config.php");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, "Method Not Allowed. Use POST.");
}

// 2. Input Sanitation
$inputs = [
    'elementName'   => trim($_POST['elementName'] ?? ''),
    'languageCode'  => trim($_POST['languageCode'] ?? 'en_US'),
    'category'      => trim($_POST['category'] ?? 'MARKETING'),
    'templateType'  => strtoupper(trim($_POST['templateType'] ?? 'TEXT')),
    'content'       => trim($_POST['content'] ?? ''),
    'header'        => trim($_POST['header'] ?? ''),
    'footer'        => trim($_POST['footer'] ?? ''),
    'buttons'       => trim($_POST['buttons'] ?? ''),
    'mediaUrl'      => trim($_POST['mediaUrl'] ?? ''),
    'vertical'      => trim($_POST['vertical'] ?? '')
];

if (empty($inputs['elementName']) || empty($inputs['content'])) {
    sendError(400, "Element Name and Content are mandatory.");
}

// 3. Media Processing (If applicable)
$mediaHandle = '';
$isMediaTemplate = in_array($inputs['templateType'], ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF']);

if ($isMediaTemplate) {
    if (empty($inputs['mediaUrl'])) {
        sendError(400, "Media URL is required for " . $inputs['templateType'] . " templates.");
    }
    
    $uploadResult = uploadMedia($appId, $apiToken, $inputs['mediaUrl'], $inputs['templateType']);
    if (!$uploadResult['success']) {
        sendError(500, "Media Upload Failed: " . $uploadResult['error']);
    }
    $mediaHandle = $uploadResult['handle'];
}

// 4. Build Payload
try {
    $payload = buildTemplatePayload($inputs, $mediaHandle);
} catch (Exception $e) {
    sendError(400, "Payload Build Error: " . $e->getMessage());
}

// 5. Register Template with Gupshup
$registerUrl = "https://partner.gupshup.io/partner/app/{$appId}/templates";
$response = makeApiCall($registerUrl, $apiToken, $payload);

if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
    echo $response['body']; // Pass through Gupshup success response
} else {
    http_response_code($response['httpCode']);
    echo $response['body'];
}

// --- HELPER FUNCTIONS ---

/**
 * Uploads media to Gupshup and returns a handleId.
 */
function uploadMedia(string $appId, string $token, string $mediaUrl, string $type): array {
    $url = "https://partner.gupshup.io/partner/app/{$appId}/upload/media";
    
    $mimeMap = [
        'IMAGE'    => 'image/jpeg',
        'VIDEO'    => 'video/mp4',
        'DOCUMENT' => 'application/pdf',
        'GIF'      => 'video/mp4'
    ];
    $fileType = $mimeMap[$type] ?? 'image/jpeg';

    $postData = [
        'file'      => $mediaUrl,
        'file_type' => $fileType
    ];

    $res = makeApiCall($url, $token, $postData, true); // multipart/form-data for uploads
    
    if ($res['httpCode'] !== 200) {
        return ['success' => false, 'error' => "HTTP {$res['httpCode']}: " . $res['body']];
    }

    $decoded = json_decode($res['body'], true);
    $handle = extractMediaHandle($decoded);

    if (!$handle) {
        return ['success' => false, 'error' => "Could not resolve handleId from response: " . $res['body']];
    }

    return ['success' => true, 'handle' => $handle];
}

/**
 * Extracts handleId from various possible Gupshup response formats.
 */
function extractMediaHandle(?array $data): ?string {
    if (!$data) return null;

    // Format 1: { "handleId": { "message": "..." } }
    if (isset($data['handleId']['message'])) {
        return (string)$data['handleId']['message'];
    }

    // Format 2: { "handleId": "..." }
    if (isset($data['handleId']) && is_string($data['handleId'])) {
        return $data['handleId'];
    }

    // Format 3: { "message": "..." } (sometimes returned directly)
    if (isset($data['message']) && is_string($data['message']) && strlen($data['message']) > 20) {
        return $data['message'];
    }

    // Format 4: { "file": "..." }
    if (isset($data['file'])) {
        return (string)$data['file'];
    }

    return null;
}

/**
 * Constructs the final template creation payload.
 */
function buildTemplatePayload(array $inputs, string $mediaHandle = ''): array {
    // Map Category to Gupshup Verticals
    $vertical = strtoupper($inputs['category']);
    if ($vertical === 'UTILITY') $vertical = 'TRANSACTIONAL';
    if ($vertical === 'AUTHENTICATION') $vertical = 'OTP';
    if ($inputs['templateType'] === 'CAROUSEL') $vertical = 'products';

    $payload = [
        'elementName'   => $inputs['elementName'],
        'languageCode'  => $inputs['languageCode'],
        'category'      => $inputs['category'],
        'templateType'  => $inputs['templateType'],
        'vertical'      => $inputs['vertical'] ?: $vertical,
        'content'       => $inputs['content'],
        'enableSample'  => 'true'
    ];

    // Header logic
    if (in_array($inputs['templateType'], ['IMAGE', 'VIDEO', 'DOCUMENT', 'GIF'])) {
        if (!$mediaHandle) throw new Exception("Media handle required for this template type.");
        $payload['exampleMedia'] = $mediaHandle;
    } else {
        if (!empty($inputs['header'])) {
            $payload['header'] = $inputs['header'];
        }
    }

    if (!empty($inputs['footer'])) {
        $payload['footer'] = $inputs['footer'];
    }

    // Buttons (Pass through raw JSON if provided)
    if (!empty($inputs['buttons'])) {
        $btnData = json_decode($inputs['buttons'], true);
        if ($btnData === null) throw new Exception("Invalid JSON format for buttons.");
        $payload['buttons'] = $inputs['buttons']; // Gupshup expects stringified JSON in form-data
    }

    // Example logic for params
    preg_match_all('/\{\{(\d+)\}\}/', $inputs['content'], $matches);
    if (!empty($matches[0])) {
        // Gupshup needs a comma-separated example string or it will fail with #132000
        $examples = [];
        foreach ($matches[1] as $idx) {
            $examples[] = "Sample Value " . $idx;
        }
        $payload['example'] = implode(',', $examples);
    }

    return $payload;
}

/**
 * Robust CURL wrapper for Gupshup API.
 */
function makeApiCall(string $url, string $token, array $data, bool $isMultipart = false): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = [
        'Authorization: Bearer ' . $token,
        'accept: application/json'
    ];

    if ($isMultipart) {
        // Use raw array for multipart/form-data
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        // Use http_build_query for application/x-www-form-urlencoded
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
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
 * Standard error response.
 */
function sendError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}
