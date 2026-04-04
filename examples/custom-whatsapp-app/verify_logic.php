<?php
$filename = 'f:/keen/robodesk/var/messages/lead_4774.json';
$history = json_decode(file_get_contents($filename), true);
$targetId = '461e69a1-6411-4e54-9ecc-2e8dcc16c918';
$emoji = '🔥';
$updated = false;
foreach ($history as &$entry) {
    if (($entry['id'] ?? '') === $targetId) {
        $entry['react'] = $emoji;
        $updated = true;
        break;
    }
}
if ($updated) {
    echo "SUCCESS: Found $targetId and added $emoji\n";
} else {
    echo "FAILED: Message not found\n";
}
?>
