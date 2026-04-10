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
$phone       = $data['message_to']   ?? $data['phone_number'] ?? ($data['properties']['phone_number'] ?? ($data['PHONE'] ?? ''));

if (!$btMessageId || !$phone) {
    msLog("Missing required parameters (MESSAGE_ID or PHONE)");
    header('HTTP/1.1 400 Bad Request');
    exit;
}

// Format phone for Gupshup (numeric only)
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);

$apiKey = $whatsappConfig['gupshup_api_token'];
$appId  = $whatsappConfig['gupshup_app_id'];

$payload = [
    'messaging_product' => 'whatsapp',
    'to'                => $cleanPhone,
    'type'              => 'text',
    'text'              => ['body' => $messageText]
];

// Send to Gupshup Partner V3 API
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/v3/message';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'accept: application/json',
    'Authorization: ' . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

$decodedResponse = json_decode($response, true);
msLog("Gupshup Partner API response ($httpCode)", $decodedResponse ?: ['raw' => $response]);

if ($error) {
    msLog("CURL Error: " . $error);
    updateBitrixStatus($btMessageId, 'failed');
    recordToChatHistory($cleanPhone, $messageText, $btMessageId, 'failed', $data['auth']['user_id'] ?? null);
} elseif ($httpCode === 201 || $httpCode === 202 || $httpCode === 200) {
    // Gupshup accepted the message
    $gsId = $decodedResponse['messages'][0]['id'] ?? ($decodedResponse['messageId'] ?? ($decodedResponse['id'] ?? ($decodedResponse['gs_id'] ?? null)));
    
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
    
    // Update Message Service status to "sent"
    updateBitrixStatus($btMessageId, 'sent');
    
    // Record in Chat History
    recordToChatHistory($cleanPhone, $messageText, $gsId ?: $btMessageId, 'sent', $data['auth']['user_id'] ?? null);

} else {
    // API Failure
    msLog("Failed to send message via Gupshup API (Status $httpCode)");
    updateBitrixStatus($btMessageId, 'failed');
    recordToChatHistory($cleanPhone, $messageText, $btMessageId, 'failed', $data['auth']['user_id'] ?? null);
}

/**
 * Updates the Message Service status in Bitrix24.
 */
function updateBitrixStatus($btMessageId, $status) {
    CRest::call('messageservice.message.status.update', [
        'MESSAGE_ID' => $btMessageId,
        'STATUS'     => $status
    ]);
}

/**
 * Records the outbound message in the Open Channel (Contact Center) history.
 */
function recordToChatHistory($phone, $text, $msgId, $status, $managerId = null) {
    $settingsFile = __DIR__ . '/settings.json';
    $lineId = '1';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $lineId = (string)($settings['open_line_id'] ?? '1');
    }

    $arMessage = [
        'user' => [
            'id' => $phone,
            'phone' => '+' . $phone,
            'skip_phone_validate' => 'Y'
        ],
        'message' => [
            'id' => $msgId,
            'date' => time(),
            'text' => $text,
        ],
        'chat' => [
            'id' => $phone,
        ]
    ];

    if ($managerId) {
        $arMessage['message']['user_id'] = $managerId;
    }

    $res = CRest::call('imconnector.send.messages', [
        'CONNECTOR' => 'keen_nexus',
        'LINE' => $lineId,
        'MESSAGES' => [$arMessage],
    ]);

    // If immediate failure, mark as failed in chat history too
    if ($status === 'failed' && !empty($res['result'])) {
        CRest::call('imconnector.send.status.delivery', [
            'CONNECTOR' => 'keen_nexus',
            'LINE' => $lineId,
            'MESSAGES' => [
                [
                    'im' => $res['result'][0]['message']['id'] ?? null,
                    'message' => ['id' => [$msgId]],
                    'chat' => ['id' => $phone],
                    'error' => 'Gupshup API Error'
                ]
            ]
        ]);
    }
}

echo json_encode(['SUCCESS' => true]);
