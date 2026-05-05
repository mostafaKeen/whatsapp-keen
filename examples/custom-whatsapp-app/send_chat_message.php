<?php
declare(strict_types=1);

/**
 * send_chat_message.php
 * Sends a free-form message to a specific phone number using Gupshup /msg API,
 * logs it in the local JSON files, and updates the Bitrix Lead/Contact Activity.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$message = trim($_POST['message'] ?? '');
$phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
$type = preg_replace('/[^a-z]/', '', strtolower($_POST['type'] ?? ''));
$id = preg_replace('/[^0-9]/', '', $_POST['id'] ?? '');

if (empty($message) || empty($phone) || empty($type) || empty($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = $whatsappConfig['webhook_url'] ?? '';

// Strictly enforce existence check
if ($type === 'lead' || $type === 'contact') {
    $method = ($type === 'lead') ? 'crm.lead.get' : 'crm.contact.get';
    $url = rtrim($webhookUrl, '/') . '/' . $method . '.json?id=' . $id;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $resData = json_decode($response, true);
    if (isset($resData['error']) || empty($resData['result'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cannot send message: the associated CRM record (' . $type . ' ' . $id . ') has been deleted.']);
        exit;
    }
}

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$MSG_DIR = $BASE_VAR_DIR . '/messages';

$appId = $whatsappConfig['gupshup_app_id'] ?? '';
$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$source = $whatsappConfig['gupshup_source'] ?? '';
$appName = $whatsappConfig['gupshup_app_name'] ?? '';

// 1. Send via Gupshup /msg API
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/msg';

$postData = [
    'channel' => 'whatsapp',
    'source' => $source,
    'destination' => $phone,
    'src.name' => $appName,
    'message' => json_encode([
        'type' => 'text',
        'text' => $message
    ])
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $apiToken,
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode((string)$response, true);

if ($httpCode < 200 || $httpCode >= 300 || ($decoded['status'] ?? '') === 'error') {
    $errorReason = $decoded['message'] ?? $response ?? 'Unknown Gupshup Error';
    
    // Log the failed message in history
    $filename = $MSG_DIR . '/' . $type . '_' . $id . '.json';
    $history = file_exists($filename) ? json_decode(file_get_contents($filename), true) : [];
    if (!is_array($history)) $history = [];
    $history[] = [
        'id' => uniqid('wa_fail_'),
        'timestamp' => date('Y-m-d H:i:s'),
        'phone' => '+' . $phone,
        'message' => $message,
        'message_type' => 'text',
        'status' => 'failed',
        'error_reason' => $errorReason,
        'direction' => 'outbound',
        'source' => $source
    ];
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));

    http_response_code(400);
    echo json_encode(['error' => 'Gupshup API Error', 'details' => $errorReason]);
    exit;
}

$msgId = $decoded['messageId'] ?? uniqid('wa_sent_');

// 2. Log Locally
$filename = $MSG_DIR . '/' . $type . '_' . $id . '.json';
$history = file_exists($filename) ? json_decode(file_get_contents($filename), true) : [];
if (!is_array($history)) $history = [];

$logEntry = [
    'id' => $msgId,
    'timestamp' => date('Y-m-d H:i:s'),
    'phone' => '+' . $phone,
    'message' => $message,
    'message_type' => 'text',
    'status' => 'sent',
    'direction' => 'outbound',
    'source' => $source
];
$history[] = $logEntry;
file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));

// 3. Update Bitrix Activity
$webhookUrl = $whatsappConfig['webhook_url'] ?? '';
if (!empty($webhookUrl)) {
    $fields = [
        'OWNER_TYPE_ID' => $type === 'lead' ? 1 : 3,
        'OWNER_ID' => $id,
        'TYPE_ID' => 1,
        'COMMUNICATION_TYPE_ID' => 'PHONE',
        'DIRECTION' => 2, // Outbound
        'SUBJECT' => 'WhatsApp Reply (Direct)',
        'DESCRIPTION' => $message . "\n\n(Sent via Web UI : $source)",
        'COMPLETED' => 'Y',
        'RESPONSIBLE_ID' => 1
    ];
    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.activity.add.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['fields' => $fields]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message_id' => $msgId, 'entry' => $logEntry]);
