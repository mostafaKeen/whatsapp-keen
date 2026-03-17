<?php
declare(strict_types=1);

/**
 * get_lead_fields.php
 * Fetches lead filter options from Bitrix24:
 *   - sources (SOURCE_ID) from crm.status.list (entityId = SOURCE)
 *   - statuses (STATUS_ID) from crm.status.list (entityId = STATUS)
 *   - assigned users via user.get
 * Returns JSON with arrays for each filter dimension.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
error_reporting(0);

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');

function bitrix24Get(string $webhookUrl, string $method, array $params = []): array {
    $url = $webhookUrl . '/' . $method . '.json';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['result'] ?? [];
}

// Fetch Sources (SOURCE)
$sources = bitrix24Get($webhookUrl, 'crm.status.list', [
    'filter' => ['ENTITY_ID' => 'SOURCE'],
    'select' => ['STATUS_ID', 'NAME'],
]);
$sourceOptions = [];
foreach ($sources as $s) {
    $sourceOptions[] = ['id' => $s['STATUS_ID'], 'name' => $s['NAME']];
}

// Fetch Statuses (STATUS)
$statuses = bitrix24Get($webhookUrl, 'crm.status.list', [
    'filter' => ['ENTITY_ID' => 'STATUS'],
    'select' => ['STATUS_ID', 'NAME'],
]);
$statusOptions = [];
foreach ($statuses as $s) {
    $statusOptions[] = ['id' => $s['STATUS_ID'], 'name' => $s['NAME']];
}

// Fetch Active Users for "Assigned To" filter
$users = bitrix24Get($webhookUrl, 'user.get', [
    'filter' => ['ACTIVE' => true],
    'select' => ['ID', 'NAME', 'LAST_NAME'],
]);
$userOptions = [];
foreach ($users as $u) {
    $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
    if ($name) {
        $userOptions[] = ['id' => $u['ID'], 'name' => $name];
    }
}

echo json_encode([
    'success'       => true,
    'sourceOptions' => $sourceOptions,
    'statusOptions' => $statusOptions,
    'userOptions'   => $userOptions,
]);
