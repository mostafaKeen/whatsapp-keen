<?php
$data = json_decode(file_get_contents('templates_debug.json'), true);
foreach($data['templates'] as $t) {
    if($t['elementName'] == 'test12355') {
        echo "Found ID: " . $t['id'] . "\n";
    }
}
?>
