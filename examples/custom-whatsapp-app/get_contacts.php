<?php
declare(strict_types=1);

/**
 * get_contacts.php
 * Fetches contacts from Bitrix24 for use in the campaign selection UI.
 */

// Enable error output for debugging
ini_set('display_errors', '0');
error_reporting(0);

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = $whatsappConfig['webhook_url'];

// Remove any trailing slash from webhook URL
$webhookUrl = rtrim($webhookUrl, '/');

$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
$segmentField = isset($_GET['segmentField']) ? $_GET['segmentField'] : '';

// Build the API URL for crm.contact.list
$apiUrl = $webhookUrl . '/crm.contact.list.json';

$select = ['ID', 'NAME', 'LAST_NAME', 'PHONE'];
if ($segmentField) {
    $select[] = $segmentField;
}

$postData = [
    'select' => $select,
    'order'  => ['NAME' => 'ASC'],
    'start'  => $start
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error]);
} else {
    http_response_code($httpCode);
    echo $response;
}
