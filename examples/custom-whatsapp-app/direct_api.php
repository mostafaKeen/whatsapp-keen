<?php
/**
 * Direct API Proxy for Bitrix24
 * Bypasses CRest's local app installation check for surgical diagnostics.
 */

$settings = json_decode(file_get_contents(__DIR__ . '/settings.json'), true);
$token = $settings['access_token'] ?? '';
$domain = $settings['domain'] ?? 'westgate.bitrix24.com';

function callB24Direct($method, $params, $token, $domain) {
    $url = "https://{$domain}/rest/{$method}.json";
    $params['auth'] = $token;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => 'curl_error', 'description' => $error];
    return json_decode($response, true);
}

echo "--- DIRECT API DIAGNOSTICS ---\n";

// 1. Identify Line ID
echo "[1] Fetching Active Connectors...\n";
$connectors = callB24Direct('imconnector.list', [], $token, $domain);

$lineId = null;
if (isset($connectors['result'])) {
    foreach ($connectors['result'] as $cId => $active) {
        echo " - Connector: $cId | Active: $active\n";
        // If we find our connector 'keen_nexus', we can probe its lines
        if ($cId === 'keen_nexus') {
            echo "   >>> FOUND OUR CONNECTOR: $cId\n";
        }
    }
} else {
    echo "Error fetching connectors: " . json_encode($connectors) . "\n";
}

// 1.1 Try getting all configs if previous failed
echo "\n[1.1] Fetching All Open Channel Configs (Targeted)...\n";
$configs = callB24Direct('imopenlines.config.get', [], $token, $domain);
if (isset($configs['result'])) {
    foreach ($configs['result'] as $c) {
        echo " - ID: {$c['ID']} | NAME: {$c['NAME']}\n";
        if (stripos($c['NAME'], 'Nexus') !== false) {
            $lineId = $c['ID'];
            echo "   >>> MATCH FOUND: LINE ID = $lineId\n";
        }
    }
} else {
    echo "   Note: imopenlines.config.get failed as expected or returned: " . ($configs['error'] ?? 'Unknown Error') . "\n";
}

// 2. Test Dialog Creation for Lead 16934
$testLines = [1, 2, 3];
echo "\n[2] Testing Dialog Creation for Lead 16934...\n";
foreach ($testLines as $l) {
    echo " Testing Line $l...\n";
    $dialog = callB24Direct('imopenlines.dialog.get', [
        'USER_CODE' => "keen_nexus|$l|201129274930",
        'CRM_ID' => '16934',
        'CRM_ENTITY_TYPE' => 'LEAD'
    ], $token, $domain);
    
    if (isset($dialog['result'])) {
        echo "   >>> SUCCESS ON LINE $l! Dialog ID: " . $dialog['result'] . "\n";
        echo "   Visitor is registered correctly on this line.\n";
        break; // Stop if we found a working line
    } else {
        echo "   FAILED ON LINE $l: " . ($dialog['error_description'] ?? json_encode($dialog)) . "\n";
    }
}

echo "--- END DIAGNOSTICS ---\n";
