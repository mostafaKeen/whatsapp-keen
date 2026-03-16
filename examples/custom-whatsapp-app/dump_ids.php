<?php
$data = json_decode(file_get_contents('templates_direct.json'), true);
if (!$data || !isset($data['templates'])) {
    die("Failed to decode JSON or missing templates\n");
}
$count = 0;
foreach($data['templates'] as $t) {
    if(isset($t['elementName'], $t['id'])) {
        echo $t['elementName'] . " : " . $t['id'] . "\n";
        $count++;
        if($count >= 10) break;
    }
}
?>
