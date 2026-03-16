<?php
$data = json_decode(file_get_contents('templates_debug_utf8.json'), true);
if (!$data) {
    // Try reading with different encoding if raw fail
    $raw = file_get_contents('templates_debug_utf8.json');
    $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    $data = json_decode($raw, true);
}
if (!$data) die("Failed to load templates\n");
foreach($data['templates'] as $t) {
    if ($t['elementName'] === 'test12355') {
        echo $t['id'] . "\n";
        exit;
    }
}
echo "Not found\n";
?>
