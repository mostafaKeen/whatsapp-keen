<?php
/**
 * get_history.php
 * ----------------------------------------------------
 * Returns the message history JSON for a given entity.
 */

header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
$type = $_GET['type'] ?? null;

if (!$id || !$type) {
    echo json_encode(['error' => 'Missing ID or Type']);
    exit;
}

$filename = dirname(__DIR__, 2) . '/var/messages/' . strtolower($type) . '_' . $id . '.json';

if (file_exists($filename)) {
    $content = file_get_contents($filename);
    echo $content;
} else {
    echo json_encode([]);
}
