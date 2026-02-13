<?php

// قراءة config
$config = json_decode(file_get_contents(__DIR__.'/config.json'), true);

// قراءة البيانات القادمة من Bitrix
$input = json_decode(file_get_contents("php://input"), true);

// تسجيل كل شيء للـ debugging
file_put_contents(__DIR__.'/log.txt',
    date('Y-m-d H:i:s')."\n".
    json_encode($input, JSON_PRETTY_PRINT)."\n\n",
    FILE_APPEND
);

// تأكد أن الحدث هو رسالة جديدة
if (isset($input['event']) && $input['event'] === 'ONIMCONNECTORMESSAGEADD') {

    $message = $input['data']['MESSAGES'][0]['message']['text'] ?? '';
    $phone   = $input['data']['MESSAGES'][0]['user']['id'] ?? '';

    if (!$message || !$phone) {
        http_response_code(400);
        exit('Missing message or phone');
    }

    // توليد channelId عشوائي
    $channelId = bin2hex(random_bytes(16));

    // تجهيز الطلب لـ RoboDesk
    $payload = [
        "template" => [
            "data" => [],
            "templateName" => "test",
            "text" => $message
        ],
        "templateMessage" => $message,
        "channel" => "whatsapp-meta",
        "language" => "en-US",
        "to" => $phone,
        "channelId" => $channelId
    ];

    $robodeskToken = $config['ROBODESK_TOKEN'] ?? 'YOUR_ROBODESK_TOKEN';
    $robodeskEndpoint = $config['ROBODESK_ENDPOINT'] ?? 'https://wosool-keen.robodesk.ai/api/conversation/start/sendMsg';

    $ch = curl_init($robodeskEndpoint);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "authorization: " . $robodeskToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // تسجيل رد RoboDesk
    file_put_contents(__DIR__.'/robodesk_response.txt',
        date('Y-m-d H:i:s')."\nHTTP: ".$httpCode."\n".$response."\n\n",
        FILE_APPEND
    );

    if ($httpCode !== 200) {
        http_response_code(500);
        exit('Failed sending to RoboDesk');
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'robodesk_http_code' => $httpCode,
        'robodesk_response'  => $response
    ]);
}
