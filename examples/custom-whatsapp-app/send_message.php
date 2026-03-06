<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$whatsappConfig = require __DIR__ . '/../config.php';
$appId = $whatsappConfig['gupshup_app_id'];
$apiToken = $whatsappConfig['gupshup_api_token'];

$phone = $_POST['phone'] ?? '';
$message = $_POST['message'] ?? '';
$entityId = $_POST['entityId'] ?? 'unknown';
$entityType = $_POST['entityType'] ?? 'lead';

if (empty($phone) && !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Phone and either message or file are required']);
    exit;
}

// Clean phone number
$phone = preg_replace('/[^0-9]/', '', $phone);

$messageType = 'text';
$fileUrl = null;
$originalFileName = '';

// Handle File Upload
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $originalFileName = basename($_FILES['file']['name']);
    $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $newFileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $newFileName;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        // Construct public URL - assuming standard server setup
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        // Ensure accurate path referencing if running from a subdirectory
        $baseDir = dirname($_SERVER['PHP_SELF']);
        $fileUrl = $protocol . '://' . $host . $baseDir . '/uploads/' . $newFileName;

        // Determine Message Type
        $mime = $_FILES['file']['type'];
        if (strpos($mime, 'image/') === 0) {
            $messageType = 'image';
        } elseif (strpos($mime, 'video/') === 0) {
            $messageType = 'video';
        } elseif (strpos($mime, 'audio/') === 0) {
            $messageType = 'audio';
        } else {
            $messageType = 'document';
        }
    }
}

// Construct Payload based on Meta/Gupshup v3 API
$payload = [
    'messaging_product' => 'whatsapp',
    'to' => $phone,
    'type' => $messageType
];

if ($messageType === 'text') {
    $payload['text'] = ['body' => $message];
} else {
    $payload[$messageType] = [
        'link' => $fileUrl
    ];
    
    // Add caption to image/video/document if message exists
    if (!empty($message) && in_array($messageType, ['image', 'video', 'document'])) {
        $payload[$messageType]['caption'] = $message;
    }

    // Add filename for documents
    if ($messageType === 'document') {
        $payload['document']['filename'] = $originalFileName;
    }
}

$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/v3/message';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'accept: application/json',
    'Authorization: Bearer ' . $apiToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL Error: ' . $error]);
} else {
    $decodedResponse = json_decode($response, true);
    if ($httpCode >= 400) {
        http_response_code($httpCode);
        $errorMessage = $decodedResponse['message'] ?? $decodedResponse['error'] ?? 'Gupshup returned an error.';
        echo json_encode([
            'error' => $errorMessage,
            'http_code' => $httpCode,
            'raw_response' => $response,
            'payload_sent' => $payload
        ]);
    } else {
        // Log the message to JSON file
        $msgId = $decodedResponse['messageId'] ?? null; // Gupshup Partner API v3 usually returns messageId
        $timestamp = date('Y-m-d H:i:s');
        logMessageToJson($entityId, $entityType, $phone, $message, $fileUrl, $messageType, $timestamp, $msgId);

        http_response_code($httpCode);
        echo json_encode([
            'message' => 'Success',
            'timestamp' => $timestamp,
            'messageId' => $msgId,
            'file_url' => $fileUrl,
            'message_type' => $messageType,
            'raw_response' => $decodedResponse ?? $response
        ]);
    }
}

function logMessageToJson($id, $type, $phone, $message, $fileUrl = null, $messageType = 'text', $timestamp = null, $msgId = null) {
    if (!$timestamp) $timestamp = date('Y-m-d H:i:s');
    $dir = __DIR__ . '/messages';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = $dir . '/' . $type . '_' . $id . '.json';
    
    $logEntry = [
        'id' => $msgId, // Gupshup/Meta message ID
        'timestamp' => $timestamp,
        'phone' => $phone,
        'message' => $message,
        'message_type' => $messageType,
        'file_url' => $fileUrl,
        'status' => 'sent',
        'direction' => 'outbound',
        'source' => 'custom_widget'
    ];

    $history = [];
    if (file_exists($filename)) {
        $content = file_get_contents($filename);
        $history = json_decode($content, true) ?: [];
    }

    $history[] = $logEntry;
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
}
