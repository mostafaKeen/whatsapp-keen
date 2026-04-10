<?php
declare(strict_types=1);

/**
 * msg_handler.php
 * --------------------------------------------------
 * Handles outbound WhatsApp messages from Bitrix24 
 * (triggered via CRM Automation rules or "Send SMS").
 * --------------------------------------------------
 */

require_once __DIR__ . '/crest.php';
$whatsappConfig = require __DIR__ . '/../config.php';

// Log incoming request
function msLog($message, $data = []) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/messageservice.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message " . (empty($data) ? "" : json_encode($data)) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Read input (support both raw JSON and form-data)
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true) ?: [];
$data = array_merge($_POST, $input);

msLog("Incoming Message Service request", $data);

// Extract parameters (Bitrix24 standard + User suggested keys)
$btMessageId = $data['message_id']   ?? $data['MESSAGE_ID'] ?? null;
$messageText = $data['message_body'] ?? $data['MESSAGE']    ?? '';
$phone       = $data['phone_number'] ?? $data['PHONE']      ?? '';

if (!$btMessageId || !$phone) {
    msLog("Missing required parameters (MESSAGE_ID or PHONE)");
    header('HTTP/1.1 400 Bad Request');
    exit;
}

// Format phone for Gupshup (numeric only)
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);

$apiKey = $whatsappConfig['gupshup_api_token'];
$source = $whatsappConfig['gupshup_source'];

$payload = [
    'channel'     => 'whatsapp',
    'source'      => $source,
    'destination' => $cleanPhone,
    'message'     => $messageText
];

// Send to Gupshup V1 API
$ch = curl_init('https://api.gupshup.io/sm/api/v1/msg');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'apikey: ' . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

$decodedResponse = json_decode($response, true);
msLog("Gupshup API response ($httpCode)", $decodedResponse ?: ['raw' => $response]);

if ($error) {
    msLog("CURL Error: " . $error);
    CRest::call('messageservice.message.status.update', [
        'MESSAGE_ID' => $btMessageId,
        'STATUS'     => 'failed'
    ]);
} elseif ($httpCode === 202 && isset($decodedResponse['messageId'])) {
    // Gupshup accepted the message
    $gsId = $decodedResponse['messageId'];
    
    // Store mapping for status update when delivery webhook arrives
    $pendingDir = $whatsappConfig['var_dir'] . '/ms_pending';
    if (!is_dir($pendingDir)) {
        mkdir($pendingDir, 0777, true);
    }
    
    file_put_contents($pendingDir . '/' . $gsId . '.json', json_encode([
        'bt_message_id' => $btMessageId,
        'timestamp'     => time()
    ]));
    
    msLog("Mapping stored: GS $gsId -> BT $btMessageId. Waiting for delivery webhook.");
    
    // Per user instructions: "Update status to 'sent'" if Gupshup returns success 
    // Wait, the user's latest comment was "no success or fail based on delivery" 
    // which I interpreted as "Wait for delivery status". 
    // However, Gupshup "accepting" the message is usually equivalent to "sent" from the provider's perspective.
    // I will call "sent" now, then "delivered" later if the webhook arrives.
    
    CRest::call('messageservice.message.status.update', [
        'MESSAGE_ID' => $btMessageId,
        'STATUS'     => 'sent'
    ]);

} else {
    // API Failure
    CRest::call('messageservice.message.status.update', [
        'MESSAGE_ID' => $btMessageId,
        'STATUS'     => 'failed'
    ]);
    msLog("Failed to send message via Gupshup API (Status $httpCode)");
}

echo json_encode(['SUCCESS' => true]);
