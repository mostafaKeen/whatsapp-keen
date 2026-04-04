<?php
// test_webhook_reaction.php

$payload = [
    'entry' => [
        [
            'changes' => [
                [
                    'field' => 'messages',
                    'value' => [
                        'contacts' => [['profile' => ['name' => 'Mostafa Osama'], 'wa_id' => '971585377879']],
                        'messages' => [
                            [
                                'from' => '971585377879',
                                'id' => 'wamid.HBgMMjAxMTI5Mjc0OTMwFQIAEhggQUM2ODJCNkNGQzlCRThDNjNGMzNENzA2N0Q0ODIwREQA',
                                'reaction' => [
                                    'emoji' => '🔥',
                                    'message_id' => '461e69a1-6411-4e54-9ecc-2e8dcc16c918'
                                ],
                                'timestamp' => time(),
                                'type' => 'reaction'
                            ]
                        ],
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '447344651319', 'phone_number_id' => '1073108572554776']
                    ]
                ]
            ],
            'id' => '1399213745558436'
        ]
    ],
    'gs_app_id' => 'f3f5e489-9373-4b32-b6cf-82833e757563',
    'object' => 'whatsapp_business_account'
];

$ch = curl_init('http://localhost/keen/robodesk/examples/custom-whatsapp-app/webhook.php');
// Note: If running locally without a web server, we might need to include the file directly
// but since this is a complex environment, let's try to run it via CLI if possible or just mock the logic.

// Actually, I can just run the webhook logic directly from a script by mocking $_SERVER and php://input
?>
