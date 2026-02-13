<?php
// install.php
// Called automatically by Bitrix on app install

require 'bitrix.php';

$data = $_REQUEST;

// Log install payload
file_put_contents(__DIR__ . '/install_log.json', json_encode($data, JSON_PRETTY_PRINT));

// Build config (preserve existing Token/Endpoint if file exists)
$existingConfig = [];
if (file_exists(__DIR__ . '/config.json')) {
    $existingConfig = json_decode(file_get_contents(__DIR__ . '/config.json'), true) ?: [];
}

$newConfig = [
    'DOMAIN'       => $data['DOMAIN'] ?? ($existingConfig['DOMAIN'] ?? ''),
    'APP_SID'      => $data['APP_SID'] ?? ($existingConfig['APP_SID'] ?? ''),
    'AUTH_ID'      => $data['AUTH_ID'] ?? ($existingConfig['AUTH_ID'] ?? ''),
    'AUTH_EXPIRES' => $data['AUTH_EXPIRES'] ?? ($existingConfig['AUTH_EXPIRES'] ?? ''),
    'REFRESH_ID'   => $data['REFRESH_ID'] ?? ($existingConfig['REFRESH_ID'] ?? ''),
    'SERVER_ENDPOINT' => $data['SERVER_ENDPOINT'] ?? ($existingConfig['SERVER_ENDPOINT'] ?? 'https://oauth.bitrix.info/rest/'),
    'member_id'    => $data['member_id'] ?? ($existingConfig['member_id'] ?? ''),
    
    // RoboDesk Defaults (preserve if exists)
    'ROBODESK_TOKEN' => $existingConfig['ROBODESK_TOKEN'] ?? 'YOUR_ROBODESK_TOKEN',
    'ROBODESK_ENDPOINT' => $existingConfig['ROBODESK_ENDPOINT'] ?? 'https://wosool-keen.robodesk.ai/api/conversation/start/sendMsg',
    'OPEN_LINE_ID' => $existingConfig['OPEN_LINE_ID'] ?? 5,
    'CONNECTOR_ID' => $existingConfig['CONNECTOR_ID'] ?? 'wosolkeen'
];

file_put_contents(__DIR__ . '/config.json', json_encode($newConfig, JSON_PRETTY_PRINT));

// ----------------------------
// 1️⃣ Bind app to Contact Center UI
// ----------------------------
callBitrix('placement.bind', [
    'PLACEMENT' => 'CONTACT_CENTER',
    'HANDLER'   => 'https://keenenter.com/robodesk/handler.php',
    'TITLE'     => 'wosol-keen-whatsapp'
]);

// ----------------------------
// 2️⃣ Register Open Channel Connector
// ----------------------------

// Simple SVG icon (valid format)
$svgIcon = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">'
    . '<circle cx="16" cy="16" r="14" fill="#1900ff"/>'
    . '<text x="50%" y="50%" font-size="12" fill="#ffffff" text-anchor="middle" dominant-baseline="central">W</text>'
    . '</svg>';

callBitrix('imconnector.register', [
    'ID'   => 'wosolkeen',
    'NAME' => 'wosol-keen-whatsapp',
    'PLACEMENT_HANDLER' => 'https://keenenter.com/robodesk/handler.php',
    'ICON' => [
        'DATA_IMAGE' => $svgIcon,
        'COLOR'      => '#1900ff',
        'SIZE'       => '90%',
        'POSITION'   => 'center'
    ],
    'DEL_EXTERNAL_MESSAGES' => 'Y',
    'EDIT_INTERNAL_MESSAGES' => 'Y',
    'DEL_INTERNAL_MESSAGES' => 'Y',
    'NEWSLETTER' => 'Y',
    'NEED_SYSTEM_MESSAGES' => 'Y',
    'NEED_SIGNATURE' => 'Y',
    'CHAT_GROUP' => 'Y',
    'COMMENT' => 'Robodesk WhatsApp Connector'
]);

echo "Application installed and Contact Center binding completed successfully.";
