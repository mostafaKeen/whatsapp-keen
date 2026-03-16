<?php
$webhookUrl = 'https://benchmark.bitrix24.com/rest/98/jg9bi0gd1gt21nm1/';

$payload = [
    'fields' => [
        'OWNER_TYPE_ID' => 1,
        'OWNER_ID' => 1,
        'TYPE_ID' => 1,
        'COMMUNICATION_TYPE_ID' => 'PHONE',
        'SUBJECT' => 'Test file upload',
        'DESCRIPTION' => 'Test body',
        'COMPLETED' => 'Y',
        'FILES' => [
            ['fileData' => ['test.txt', base64_encode('Hello world from API')]]
        ]
    ]
];

$ch = curl_init($webhookUrl . 'crm.activity.add.json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
// Proper json payload for Bitrix24
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
echo $res;
