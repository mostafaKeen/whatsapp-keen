<?php
$data = json_decode(file_get_contents('templates_direct.json'), true);
if (!$data) die("Failed to decode JSON\n");
foreach($data['templates'] as $t) {
    if ($t['elementName'] === 'test12355') {
        echo "FOUND: " . $t['id'] . "\n";
        exit;
    }
}
echo "Not found\n";
?>
