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
$allowedTypes = [
    'string', 'enumeration', 'date', 'datetime', 'char', 'integer', 'double', 'boolean', 
    'status', 'user', 'crm_status', 'crm_currency', 'money', 'url', 'file'
];
$excludeFields = ['ID', 'PHONE', 'EMAIL', 'WEB', 'IM', 'LINK'];

$filteredFields = [];
foreach ($fields as $key => $f) {
    $type = $f['type'];
    
    if (in_array($type, $allowedTypes) && !in_array($key, $excludeFields)) {
        // Aggressive label detection
        $title = null;
        $labelKeys = [
            'formLabel', 'listLabel', 'filterLabel', 'editFormLabel', 'listColumnLabel',
            'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL', 'EDIT_FORM_LABEL'
        ];
        
        foreach ($labelKeys as $lk) {
            $candidate = $f[$lk] ?? null;
            if ($candidate && !empty($candidate) && strtolower((string)$candidate) !== strtolower((string)$key)) {
                $title = $candidate;
                break;
            }
        }
        
        // Check inside settings too
        if (!$title && isset($f['settings'])) {
            foreach ($labelKeys as $lk) {
                $candidate = $f['settings'][$lk] ?? null;
                if ($candidate && !empty($candidate) && strtolower((string)$candidate) !== strtolower((string)$key)) {
                    $title = $candidate;
                    break;
                }
            }
        }

        // Final fallback to title then key
        if (!$title) {
            $title = $f['title'] ?? $key;
        }

        // Aggressive item detection
        $items = $f['items'] ?? $f['ITEMS'] ?? ($f['settings']['items'] ?? $f['settings']['ITEMS'] ?? null);
        
        // Handle boolean fields with a Yes/No select
        if ($type === 'boolean' && !$items) {
            $items = [
                ['ID' => '1', 'VALUE' => 'Yes'],
                ['ID' => '0', 'VALUE' => 'No']
            ];
        }

        $filteredFields[$key] = [
            'id'    => $key,
            'title' => (string)$title,
            'type'  => $type,
            'items' => $items
        ];
    }
}

// 3. Special handling for standard/status fields that use crm.status.list
$statusFields = [];
foreach ($filteredFields as $key => $f) {
    if (isset($fields[$key]['statusType'])) {
        $statusFields[$fields[$key]['statusType']][] = $key;
    }
}

if (!empty($statusFields)) {
    $statuses = bitrix24Call($webhookUrl, 'crm.status.list');
    foreach ($statuses['result'] ?? [] as $s) {
        $entityId = $s['ENTITY_ID'];
        $statusId = $s['STATUS_ID'];
        $name = $s['NAME'];

        if (isset($statusFields[$entityId])) {
            foreach ($statusFields[$entityId] as $fieldKey) {
                $filteredFields[$fieldKey]['items'][] = ['ID' => $statusId, 'VALUE' => $name];
            }
        }
    }
}

// Debug logging (temporary)
file_put_contents(__DIR__ . '/../var/bitrix_fields_raw.json', json_encode($fields, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/../var/bitrix_fields_filtered.json', json_encode(array_values($filteredFields), JSON_PRETTY_PRINT));

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
