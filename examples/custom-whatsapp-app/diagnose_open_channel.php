<?php
declare(strict_types=1);

require_once __DIR__ . '/crest.php';
$whatsappConfig = require __DIR__ . '/../config.php';

echo "--- Bitrix24 Open Channel Diagnostics ---\n";

/**
 * Helper to call Bitrix24 via CRest or fallback Webhook
 */
function callB24(string $method, array $params = []) {
    global $whatsappConfig;
    
    // Attempt CRest first
    $res = CRest::call($method, $params);
    
    // If CRest fails with no_install_app, try raw webhook
    if (isset($res['error']) && $res['error'] === 'no_install_app') {
        echo "[LOG] CRest no_install_app. Attempting raw webhook fallback for $method...\n";
        $url = rtrim($whatsappConfig['webhook_url'], '/') . '/' . $method . '.json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode($raw, true) ?: ['error' => 'curl_failed', 'raw' => $raw];
    }
    
    return $res;
}

// 1. Get Open Channel Configs
echo "1. Fetching Open Channel configurations...\n";

// Try a loop of common initial IDs if config.get fails without ID
for ($i = 1; $i <= 50; $i++) {
    echo "[LOG] Probing Config ID: $i...\n";
    $configs = callB24('imopenlines.config.get', ['CONFIG_ID' => $i]);
    if (!empty($configs['result'])) {
        echo "[DEBUG] Found Config: " . json_encode($configs['result']) . "\n";
        $id = $configs['result']['ID'] ?? $configs['result']['id'] ?? null;
        $name = $configs['result']['NAME'] ?? $configs['result']['name'] ?? 'Unknown';
        echo "Found Line ID: $id - Name: $name\n";
        if (stripos((string)$name, 'Keen') !== false) {
            $keenNexusLine = $configs['result'];
        }
    }
}

if (!$keenNexusLine) {
    echo "ERROR: 'Keen Nexus' Open Channel line not found.\n";
    exit;
}

$lineId = (int)$keenNexusLine['ID'];
echo "SUCCESS: Found Keen Nexus on Line ID: $lineId\n";

// 2. Identify the line with keen_nexus connector
echo "2. Identifying the line with 'keen_nexus' connector...\n";

$foundLineId = null;

// First try imconnector.list
$allConnectors = callB24('imconnector.list');
echo "[DEBUG] imconnector.list: " . json_encode($allConnectors) . "\n";

if (!empty($allConnectors['result'])) {
    foreach ($allConnectors['result'] as $connectorId => $lines) {
        if ($connectorId === 'keen_nexus') {
            echo "SUCCESS: Found keen_nexus entries in imconnector.list.\n";
            // Check which line it belongs to
            foreach ($lines as $lineId => $status) {
                if ($status === true || $status === 1 || $status === 'Y') {
                    echo "Found active keen_nexus on Line ID: $lineId\n";
                    $foundLineId = (int)$lineId;
                    break 2;
                }
            }
        }
    }
}

if (!$foundLineId) {
    echo "[LOG] keen_nexus not found in imconnector.list. Probing imconnector.status for all known configs...\n";
    // Reuse the previous probe logic to find IDs, then check status
    for ($i = 1; $i <= 30; $i++) {
        $status = callB24('imconnector.status', ['CONNECTOR' => 'keen_nexus', 'LINE' => $i]);
        if (!empty($status['result']) && !empty($status['result']['ACTIVE']) && $status['result']['ACTIVE'] === 'Y') {
            echo "SUCCESS: Found active keen_nexus via imconnector.status on Line ID: $i\n";
            $foundLineId = $i;
            break;
        }
    }
}

if (!$foundLineId) {
    echo "ERROR: Could not identify any line with an active 'keen_nexus' connector.\n";
    exit;
}

$lineId = $foundLineId;

// 3. Test Message sending with verification
echo "3. Sending test message to identified Line $lineId...\n";
$phone = '971521234567'; // Sample phone
$arMessage = [
    'user' => [
        'id' => $phone,
        'name' => 'Diagnostics Test User',
    ],
    'message' => [
        'id' => 'diag_' . uniqid(),
        'date' => time(),
        'text' => 'Test message from senior integration engineer diagnostics script. Please confirm visibility.',
    ],
    'chat' => [
        'id' => $phone,
        'url' => '',
    ],
];

$sendRes = callB24('imconnector.send.messages', [
    'CONNECTOR' => 'keen_nexus',
    'LINE' => $lineId,
    'MESSAGES' => [$arMessage],
]);

echo "Send Response: " . json_encode($sendRes) . "\n";

if (!empty($sendRes['result'])) {
    echo "SUCCESS: imconnector.send.messages returned result.\n";
    
    // 4. Verify Session Creation
    echo "4. Verifying session/dialog creation...\n";
    sleep(2); // Give Bitrix24 a moment to process
    
    $dialogRes = callB24('imopenlines.dialog.get', [
        'CONNECTOR' => 'keen_nexus',
        'LINE' => $lineId,
        'USER_CODE' => $phone,
    ]);
    
    echo "Dialog/Session Status: " . json_encode($dialogRes) . "\n";
    
    if (!empty($dialogRes['result'])) {
        echo "SUCCESS: Session created! Dialog ID: " . ($dialogRes['result']['CHAT_ID'] ?? 'unknown') . "\n";
    } else {
        echo "FAIL: Session NOT created in Open Lines.\n";
    }
} else {
    echo "FAIL: imconnector.send.messages failed.\n";
}

echo "--- Diagnostics Complete ---\n";
