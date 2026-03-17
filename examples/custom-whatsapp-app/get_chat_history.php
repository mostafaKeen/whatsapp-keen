<?php
declare(strict_types=1);

/**
 * get_chat_history.php
 * Returns the entire JSON history array for a specific entity.
 */

if (!isset($_GET['type']) || !isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing type or id']);
    exit;
}

$type = preg_replace('/[^a-z]/', '', strtolower($_GET['type']));
$id = preg_replace('/[^0-9]/', '', $_GET['id']);

if (empty($type) || empty($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type or id']);
    exit;
}

$whatsappConfig = require __DIR__ . '/../config.php';
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$MSG_DIR = $BASE_VAR_DIR . '/messages';

$filename = $MSG_DIR . '/' . $type . '_' . $id . '.json';

if (!file_exists($filename)) {
    echo json_encode([]);
    exit;
}

// Strictly enforce "do not view if ID not found in Bitrix"
if ($type === 'lead' || $type === 'contact') {
    $method = ($type === 'lead') ? 'crm.lead.get' : 'crm.contact.get';
    $url = rtrim($whatsappConfig['webhook_url'], '/') . '/' . $method . '.json?id=' . $id;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $resData = json_decode($response, true);
    if (isset($resData['error']) || empty($resData['result'])) {
        http_response_code(404);
        echo json_encode(['error' => 'The associated CRM record was not found or has been deleted.']);
        exit;
    }
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
echo file_get_contents($filename);
