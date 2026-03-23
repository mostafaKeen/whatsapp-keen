<?php
declare(strict_types=1);

/**
 * get_bitrix_fields.php
 * Fetches all lead field definitions from Bitrix24
 */

header('Content-Type: application/json');

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');

function bitrix24Call(string $webhookUrl, string $method, array $params = []): array {
    $url = $webhookUrl . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

// 1. Get all Lead fields
$fieldsRes = bitrix24Call($webhookUrl, 'crm.lead.fields');
$fields = $fieldsRes['result'] ?? [];

// 2. Filter out internal or unwanted fields
$allowedTypes = ['string', 'enumeration', 'date', 'datetime', 'char', 'integer', 'double', 'boolean', 'status', 'user'];
$excludeFields = ['ID', 'PHONE', 'EMAIL', 'WEB', 'IM'];

$filteredFields = [];
foreach ($fields as $key => $f) {
    if (in_array($f['type'], $allowedTypes) && !in_array($key, $excludeFields)) {
        $filteredFields[$key] = [
            'id' => $key,
            'title' => $f['title'] ?: $f['formLabel'] ?: $f['listLabel'] ?: $key,
            'type' => $f['type'],
            'items' => $f['items'] ?? null
        ];
    }
}

// 3. Special handling for standard fields that use crm.status.list but don't have 'items' in fields
// SOURCE_ID, STATUS_ID
$statuses = bitrix24Call($webhookUrl, 'crm.status.list');
foreach ($statuses['result'] ?? [] as $s) {
    $entityId = $s['ENTITY_ID'];
    $statusId = $s['STATUS_ID'];
    $name = $s['NAME'];

    if ($entityId === 'SOURCE' && isset($filteredFields['SOURCE_ID'])) {
        $filteredFields['SOURCE_ID']['items'][] = ['ID' => $statusId, 'VALUE' => $name];
    }
    if ($entityId === 'STATUS' && isset($filteredFields['STATUS_ID'])) {
        $filteredFields['STATUS_ID']['items'][] = ['ID' => $statusId, 'VALUE' => $name];
    }
}

// 4. Fetch Users for ASSIGNED_BY_ID
$users = bitrix24Call($webhookUrl, 'user.get', ['filter' => ['ACTIVE' => true]]);
if (isset($filteredFields['ASSIGNED_BY_ID'])) {
    foreach ($users['result'] ?? [] as $u) {
        $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
        if ($name) {
            $filteredFields['ASSIGNED_BY_ID']['items'][] = ['ID' => $u['ID'], 'VALUE' => $name];
        }
    }
}

echo json_encode([
    'status' => 'success',
    'fields' => array_values($filteredFields)
]);
