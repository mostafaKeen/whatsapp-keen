<?php
declare(strict_types=1);

/**
 * get_user_preferences.php
 * Retrieves user-specific UI preferences from a server-side JSON file.
 */

header('Content-Type: application/json');

$prefFile = __DIR__ . '/../var/user_preferences.json';
$defaultFields = ["TITLE", "SOURCE_ID", "STATUS_ID", "ASSIGNED_BY_ID"];

if (file_exists($prefFile)) {
    $data = json_decode(file_get_contents($prefFile), true);
    $visibleFields = $data['visibleFields'] ?? $defaultFields;
} else {
    $visibleFields = $defaultFields;
}

echo json_encode([
    'status' => 'success',
    'visibleFields' => $visibleFields
]);
