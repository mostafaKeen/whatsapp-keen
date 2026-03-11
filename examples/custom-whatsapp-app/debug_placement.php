<?php
/**
 * debug_placement.php
 * -----------------------------------------------------------
 * Diagnostic page - run from server browser directly.
 * Uses the APPLICATION OAuth token from settings.json
 * (saved during install) to bind placements.
 * DELETE this file after done debugging!
 * -----------------------------------------------------------
 */

$settingsFile = __DIR__ . '/settings.json';
$config       = require __DIR__ . '/../config.php';

echo '<pre>';
echo "=== DEBUG PLACEMENT BINDING (App OAuth Mode) ===\n\n";

// --- Check settings.json
if (!file_exists($settingsFile)) {
    echo "❌ ERROR: settings.json NOT FOUND.\n";
    echo "The app was never installed properly (install.php never ran).\n";
    echo "You must open your Bitrix24 app list, find KEEN WABA, and REINSTALL it.\n";
    echo "That triggers install.php and saves the OAuth token to settings.json.\n";
    echo '</pre>';
    exit;
}

$settings = json_decode(file_get_contents($settingsFile), true);

echo "✅ settings.json found:\n";
echo json_encode($settings, JSON_PRETTY_PRINT) . "\n\n";

if (empty($settings['access_token']) || empty($settings['client_endpoint'])) {
    echo "❌ ERROR: settings.json is missing access_token or client_endpoint.\n";
    echo "The installation did not complete properly. Re-install the app from Bitrix24.\n";
    echo '</pre>';
    exit;
}

// Detect if token is expired
$now     = time();
$expires = isset($settings['expires_in']) ? (int)$settings['expires_in'] : 0;
if ($expires > 0 && $expires < $now) {
    echo "⚠️  WARNING: Token appears expired (expires_in={$expires}, now={$now})\n\n";
}

$accessToken    = $settings['access_token'];
$clientEndpoint = rtrim($settings['client_endpoint'], '/');

echo "Access Token: " . $accessToken . "\n";
echo "Client Endpoint: " . $clientEndpoint . "\n\n";

// Build handler URL
$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'];
$selfDir    = dirname($_SERVER['REQUEST_URI']);
$handlerUrl = $protocol . '://' . $host . $selfDir . '/placement.php';

echo "Handler URL: " . $handlerUrl . "\n\n";

echo "--- Cleaning ALL existing placements for this app ---\n";
// Call without PLACEMENT filter to see everything
$allPlacements = callAppMethod($clientEndpoint, $accessToken, 'placement.get', []);
if (!empty($allPlacements['result'])) {
    foreach ($allPlacements['result'] as $binding) {
        echo "Unbinding [" . $binding['placement'] . "] handler: " . $binding['handler'] . "...\n";
        callAppMethod($clientEndpoint, $accessToken, 'placement.unbind', [
            'PLACEMENT' => $binding['placement'],
            'HANDLER'   => $binding['handler'],
        ]);
    }
} else {
    echo "No existing bindings found to clean.\n";
}

echo "\n--- Binding Placements ---\n";
foreach ($placements as $placement) {
    echo "\nBinding: $placement\n";
    $result = callAppMethod($clientEndpoint, $accessToken, 'placement.bind', [
        'PLACEMENT'   => $placement,
        'HANDLER'     => $handlerUrl,
        'TITLE'       => 'KEEN WABA',
        'DESCRIPTION' => 'WhatsApp Business Integration',
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}

echo "\n--- Verify Bindings ---\n";
foreach ($placements as $placement) {
    $r = callAppMethod($clientEndpoint, $accessToken, 'placement.get', ['PLACEMENT' => $placement]);
    echo "$placement: " . json_encode($r, JSON_PRETTY_PRINT) . "\n\n";
}

echo "=== DONE ===\n";
echo "Now open a Lead or Deal in Bitrix24 and look for the KEEN WABA tab!\n";
echo '</pre>';

function callAppMethod($clientEndpoint, $accessToken, $method, $params = []) {
    $url              = $clientEndpoint . '/' . $method . '.json';
    $params['auth']   = $accessToken;
    $ch               = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $resp  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['curl_error' => $error];
    return json_decode($resp, true) ?: ['raw' => $resp];
}
