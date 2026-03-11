<?php
/**
 * debug_placement.php
 * -----------------------------------------------------------
 * Run this page from the SERVER directly (not inside Bitrix24 iframe)
 * to force-register placements and see exact API results.
 * DELETE this file after you are done debugging!
 * -----------------------------------------------------------
 */

// Load config
$config = require __DIR__ . '/../config.php';

// Webhook URL (used directly — no CRest needed)
$webhookUrl = $config['webhook_url'];

// ---- Build Handler URL ----
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
// We compute the path relative to this file -> placement.php sits alongside
$selfDir   = dirname($_SERVER['REQUEST_URI']);
$handlerUrl = $protocol . '://' . $host . $selfDir . '/placement.php';

echo '<pre>';
echo "=== DEBUG PLACEMENT BINDING ===\n\n";
echo "Webhook URL: " . htmlspecialchars($webhookUrl) . "\n";
echo "Handler URL: " . htmlspecialchars($handlerUrl) . "\n\n";

// ---- First: Check current bindings ----
echo "--- Current placement.get ---\n";
$currentBindings = callWebhook($webhookUrl, 'placement.get', ['PLACEMENT' => 'CRM_LEAD_DETAIL_TAB']);
echo json_encode($currentBindings, JSON_PRETTY_PRINT) . "\n\n";

// ---- Unbind old ones to avoid duplicates ----
$placements = ['CRM_LEAD_DETAIL_TAB', 'CRM_DEAL_DETAIL_TAB'];

foreach ($placements as $placement) {
    $existing = callWebhook($webhookUrl, 'placement.get', ['PLACEMENT' => $placement]);
    if (!empty($existing['result'])) {
        foreach ($existing['result'] as $binding) {
            if ($binding['HANDLER'] === $handlerUrl) {
                echo "--- Unbinding existing [$placement] ---\n";
                $unbind = callWebhook($webhookUrl, 'placement.unbind', [
                    'PLACEMENT' => $placement,
                    'HANDLER'   => $handlerUrl,
                ]);
                echo json_encode($unbind, JSON_PRETTY_PRINT) . "\n";
            }
        }
    }
}

echo "\n--- Binding Placements ---\n";

foreach ($placements as $placement) {
    echo "\nBinding: $placement\n";
    $result = callWebhook($webhookUrl, 'placement.bind', [
        'PLACEMENT' => $placement,
        'HANDLER'   => $handlerUrl,
        'TITLE'     => 'KEEN WABA',
        'DESCRIPTION' => 'WhatsApp Business Integration',
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}

echo "\n--- Verify Current Bindings After ---\n";
$lead = callWebhook($webhookUrl, 'placement.get', ['PLACEMENT' => 'CRM_LEAD_DETAIL_TAB']);
echo "Lead Tab:\n" . json_encode($lead, JSON_PRETTY_PRINT) . "\n\n";
$deal = callWebhook($webhookUrl, 'placement.get', ['PLACEMENT' => 'CRM_DEAL_DETAIL_TAB']);
echo "Deal Tab:\n" . json_encode($deal, JSON_PRETTY_PRINT) . "\n\n";

echo "=== DONE ===\n";
echo "Now refresh your Lead or Deal in Bitrix24 and look for the KEEN WABA tab!\n";
echo '</pre>';

// ---- Helper ----
function callWebhook($webhookUrl, $method, $params = []) {
    $url = rtrim($webhookUrl, '/') . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['curl_error' => $error];
    return json_decode($response, true) ?: ['raw' => $response];
}
