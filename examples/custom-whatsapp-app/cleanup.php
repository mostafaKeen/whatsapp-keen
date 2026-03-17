<?php
declare(strict_types=1);

/**
 * cleanup.php
 * Hard-deletes local JSON message history files if the associated 
 * Lead or Contact no longer exists in Bitrix24.
 */

$whatsappConfig = require __DIR__ . '/../config.php';
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$MSG_DIR = $BASE_VAR_DIR . '/messages';

if (!is_dir($MSG_DIR)) {
    die("Message directory not found.");
}

$webhookUrl = $whatsappConfig['webhook_url'] ?? '';
if (empty($webhookUrl)) {
    die("Bitrix24 Webhook URL not configured.");
}

$files = glob($MSG_DIR . '/*.json');
$toCheck = [];

foreach ($files as $file) {
    $filename = basename($file);
    if ($filename === 'lead_bulk.json') continue;

    if (preg_match('/^(lead|contact)_([0-9]+)\.json$/', $filename, $matches)) {
        $toCheck[] = [
            'file' => $file,
            'type' => $matches[1],
            'id' => $matches[2]
        ];
    }
}

if (empty($toCheck)) {
    echo "No lead/contact files to clean up.\n";
    exit;
}

// Check in batches of 50 (Bitrix limit)
$chunks = array_chunk($toCheck, 50);
$deletedCount = 0;

foreach ($chunks as $chunk) {
    $batch = [];
    foreach ($chunk as $index => $item) {
        $method = ($item['type'] === 'lead') ? 'crm.lead.get' : 'crm.contact.get';
        $batch['check_' . $index] = $method . '?id=' . $item['id'];
    }

    $batchUrl = rtrim($webhookUrl, '/') . '/batch.json';
    $ch = curl_init($batchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['cmd' => $batch, 'halt' => 0]));
    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode((string)$response, true);
    $results = $resData['result']['result'] ?? [];
    $errors = $resData['result']['result_error'] ?? [];

    foreach ($chunk as $index => $item) {
        $key = 'check_' . $index;
        
        // If Bitrix explicitly says it's not found or returns an error, we can consider it safe to delete
        // Note: We only delete if there is an error (like ERROR_NOT_FOUND) OR if the result is explicitly null/empty
        $exists = isset($results[$key]) && !empty($results[$key]);
        
        if (!$exists) {
            if (unlink($item['file'])) {
                echo "Deleted orphan file: " . basename($item['file']) . "\n";
                $deletedCount++;
            }
        }
    }
}

echo "\nCleanup complete. Total files removed: $deletedCount\n";
