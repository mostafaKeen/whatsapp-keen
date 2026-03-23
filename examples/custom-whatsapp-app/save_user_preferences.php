<?php
declare(strict_types=1);

/**
 * save_user_preferences.php
 * Saves user-specific UI preferences (like visible filters) to a server-side JSON file.
 */

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$visibleFields = $data['visibleFields'] ?? [];

if (empty($visibleFields)) {
    echo json_encode(['status' => 'error', 'message' => 'No fields provided']);
    exit;
}

$prefDir = __DIR__ . '/../var';
if (!is_dir($prefDir)) {
    mkdir($prefDir, 0777, true);
}

$prefFile = $prefDir . '/user_preferences.json';
$prefs = [
    'visibleFields' => $visibleFields,
    'updated_at' => date('Y-m-d H:i:s')
];

if (file_put_contents($prefFile, json_encode($prefs, JSON_PRETTY_PRINT))) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to write preferences file']);
}
