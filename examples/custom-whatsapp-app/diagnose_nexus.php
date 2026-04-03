<?php
require_once __DIR__ . '/crest.php';
$whatsappConfig = require __DIR__ . '/../config.php';

// Use the latest auth token provided in the logs if CRest settings are empty
$auth = '0984cf690081cc7b007447610000234af0f107f736adc5c24d50a43f3a508c20847712';

function callB24($method, $params, $auth) {
    $url = 'https://westgate.bitrix24.com/rest/' . $method . '.json';
    $params['auth'] = $auth;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $res = curl_exec($ch);
    return json_decode($res, true);
}

echo "--- START DIAGNOSTICS ---\n";

// 1. Get Open Channel Configs
echo "Step 1: Listing Open Channels...\n";
$configs = CRest::call('imopenlines.config.get');
$lineId = null;
if (isset($configs['result'])) {
    foreach($configs['result'] as $c) {
        echo "ID: {$c['ID']} | NAME: {$c['NAME']}\n";
        if (stripos($c['NAME'], 'Nexus') !== false) {
            $lineId = $c['ID'];
            echo ">>> MATCH FOUND: LINE ID = $lineId\n";
        }
    }
} else {
    echo "Error fetching configs: " . json_encode($configs) . "\n";
}

// 2. Check Connectors on discovered Line
if ($lineId) {
    echo "\nStep 2: Checking connectors for Line $lineId...\n";
    $connectors = CRest::call('imconnector.list');
    echo "Active Connectors: " . json_encode($connectors['result'] ?? []) . "\n";
}

// 3. Test Dialog Creation for a Lead
$testLeadId = '16934';
$testPhone = '201129274930';
echo "\nStep 3: Testing Dialog Creation for Lead $testLeadId...\n";
$dialog = CRest::call('imopenlines.dialog.get', [
    'USER_CODE' => 'keen_nexus|' . ($lineId ?? 1) . '|' . $testPhone,
    'CRM_ID' => $testLeadId,
    'CRM_ENTITY_TYPE' => 'LEAD'
]);

echo "Dialog Result: " . json_encode($dialog['result'] ?? $dialog['error_description'] ?? 'FAIL') . "\n";

echo "--- END DIAGNOSTICS ---\n";
