<?php
$data = json_decode(file_get_contents('f:/keen/robodesk/scratch/templates_output_utf8.json'), true);
$targets = ['dugasta_campaign_final1234', 'dugasta_campaign_final_new', 'gardani_90'];
foreach ($data as $t) {
    if (in_array($t['elementName'], $targets)) {
        echo "Name: " . $t['elementName'] . " | ExtID: " . $t['externalId'] . " | Lang: " . $t['languageCode'] . "\n";
        echo "Meta: " . $t['meta'] . "\n\n";
    }
}
