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

header('Content-Type: application/json');
echo file_get_contents($filename);
