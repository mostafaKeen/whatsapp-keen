<?php
// webhook.php
// Robodesk will POST JSON payload describing incoming message.
// Example expected payload (adjust to Robodesk real structure):
// {
//   "phone": "+201234567890",
//   "message": "Hello, I need help",
//   "external_id": "whatever"   // optional
// }

require 'bitrix.php';

try {
    $config = loadConfig();
    $CONNECTOR = $config['CONNECTOR_ID'] ?? 'wosolkeen';
    $LINE_ID   = $config['OPEN_LINE_ID'] ?? 5; // Default to 5 if missing
} catch (Exception $e) {
    // If config fails, fallback or error out. 
    // For webhook, we might want to log this.
    file_put_contents(__DIR__ . '/error_log', date('[Y-m-d H:i:s] ') . "Config load error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    exit;
}

$raw = file_get_contents('php://input');
file_put_contents(__DIR__ . '/last_request.json', $raw); // simple logging

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

// Adjust field mapping based on RoboDesk actual payload
$phone = $data['phone'] ?? ($data['from'] ?? '');
$messageText = $data['message'] ?? ($data['text'] ?? '');
// If RoboDesk sends nested objects, adjust here.
// e.g. if it sends { "data": { "message": "..." } }

if (empty($phone) || empty($messageText)) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_phone_or_message']);
    exit;
}

// Compose message structure required by imconnector.send.messages
$messages = [
    [
        'user' => [
            'id' => $phone,   // external user id in connector
            'name' => $phone,
            // 'skip_phone_validate' => 'Y' // Sometimes needed if phone format varies
        ],
        'message' => [
            'text' => $messageText
        ],
        'chat' => [
            'id' => $phone // Unique chat ID for this user
        ]
    ]
];

$params = [
    'CONNECTOR' => $CONNECTOR,
    'LINE' => (string)$LINE_ID,
    'MESSAGES' => $messages
];

$res = callBitrix('imconnector.send.messages', $params);

// log and respond
file_put_contents(__DIR__ . '/webhook_log.txt', date('[Y-m-d H:i:s] ') . "phone={$phone} line={$LINE_ID} res=" . substr(json_encode($res),0,1000) . PHP_EOL, FILE_APPEND);

header('Content-Type: application/json');
echo json_encode($res);
