<?php
$data = json_decode(file_get_contents('templates_debug_utf8.json'), true);
if (!$data) {
    die("Error decoding JSON: " . json_last_error_msg());
}
foreach($data['templates'] as $t) {
    echo $t['elementName'] . " -> " . $t['id'] . "\n";
}
?>
