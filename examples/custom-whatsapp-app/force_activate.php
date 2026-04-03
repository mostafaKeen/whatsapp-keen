<?php
/**
 * Force-activate the Keen Nexus connector using the direct webhook URL.
 * Bypasses CRest (which needs settings.json from iframe auth).
 */
$whatsappConfig = require __DIR__ . '/../config.php';
$WEBHOOK_URL = rtrim($whatsappConfig['webhook_url'], '/');
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');

if (!is_dir($BASE_VAR_DIR)) mkdir($BASE_VAR_DIR, 0775, true);

$connector_id = 'keen_nexus';
$lineId = 1;

function wCall(string $url, string $method, array $params = []): array {
    $ch = curl_init("$url/$method.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'curl', 'message' => $err];
    return json_decode($r, true) ?: [];
}

echo "=== Keen Nexus Connector Setup ===\n\n";

echo "1. Registering connector '$connector_id'...\n";
$reg = wCall($WEBHOOK_URL, 'imconnector.register', [
    'ID' => $connector_id,
    'NAME' => 'Keen Nexus',
    'ICON' => [
        'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22%2325D366%22%3E%3Cpath%20d%3D%22M17.472%2014.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94%201.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198%200-.52.074-.792.372-.272.297-1.04%201.016-1.04%202.479%200%201.462%201.065%202.875%201.213%203.074.149.198%202.096%203.2%205.077%204.487.709.306%201.262.489%201.694.625.712.227%201.36.195%201.871.118.571-.085%201.758-.719%202.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421%207.403h-.004a9.87%209.87%200%2001-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86%209.86%200%2001-1.51-5.26c.001-5.45%204.436-9.884%209.888-9.884%202.64%200%205.122%201.03%206.988%202.898a9.825%209.825%200%20012.893%206.994c-.003%205.45-4.437%209.884-9.885%209.884m8.413-18.297A11.815%2011.815%200%200012.05%200C5.495%200%20.16%205.335.157%2011.892c0%202.096.547%204.142%201.588%205.945L.057%2024l6.305-1.654a11.882%2011.882%200%20005.683%201.448h.005c6.554%200%2011.89-5.335%2011.893-11.893a11.821%2011.821%200%2000-3.48-8.413Z%22/%3E%3C/svg%3E',
        'COLOR' => '#e8f5e9',
        'SIZE' => '100%',
        'POSITION' => 'center',
    ],
    'PLACEMENT_HANDLER' => '',
]);
echo "   " . json_encode($reg, JSON_PRETTY_PRINT) . "\n\n";

echo "2. Activating on line $lineId...\n";
$act = wCall($WEBHOOK_URL, 'imconnector.activate', [
    'CONNECTOR' => $connector_id,
    'LINE' => $lineId,
    'ACTIVE' => 1,
]);
echo "   " . json_encode($act, JSON_PRETTY_PRINT) . "\n\n";

echo "3. Setting connector data...\n";
$data = wCall($WEBHOOK_URL, 'imconnector.connector.data.set', [
    'CONNECTOR' => $connector_id,
    'LINE' => $lineId,
    'DATA' => [
        'id' => 'keen_nexus_line_1',
        'url_im' => '',
        'name' => 'Keen Nexus WhatsApp',
    ],
]);
echo "   " . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

echo "4. Saving line_id.txt...\n";
file_put_contents($BASE_VAR_DIR . '/line_id.txt', (string)$lineId);
echo "   Saved line ID: $lineId to $BASE_VAR_DIR/line_id.txt\n\n";

echo "=== Done! ===\n";
echo "Now go to Bitrix24 > Contact Center and check for 'Keen Nexus'.\n";
