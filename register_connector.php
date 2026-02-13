<?php
// register_connector.php
require 'bitrix.php';

try {
    $config = loadConfig();
} catch (Exception $e) {
    die('<pre>Error: ' . $e->getMessage() . PHP_EOL . 'Install the app first (install.php) and ensure config.json exists.</pre>');
}

$connectorId   = $config['CONNECTOR_ID'] ?? 'wosolkeen';
$connectorName = 'wosol-keen-whatsapp';
$lineId        = $config['OPEN_LINE_ID'] ?? 5;

// Prepare Icon - use SVG which is more reliable for script-based install than file reads sometimes
$svgIcon = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">'
    . '<circle cx="16" cy="16" r="14" fill="#1900ff"/>'
    . '<text x="50%" y="50%" font-size="12" fill="#ffffff" text-anchor="middle" dominant-baseline="central">W</text>'
    . '</svg>';

$iconData = base64_encode($svgIcon);

// 1. Register
$regParams = [
    'ID'   => $connectorId,
    'NAME' => $connectorName,
    'PLACEMENT_HANDLER' => 'https://keenenter.com/robodesk/handler.php',
    'ICON' => [
        'DATA_IMAGE' => 'data:image/svg+xml;base64,' . $iconData,
        'COLOR'      => '#1900ff',
        'SIZE'       => '90%',
        'POSITION'   => 'center'
    ]
];

$reg = callBitrix('imconnector.register', $regParams);

// 2. Activate
$act = callBitrix('imconnector.activate', [
    'CONNECTOR' => $connectorId,
    'LINE'      => $lineId,
    'ACTIVE'    => 1,
]);

echo "<pre>Register Result:\n";
print_r($reg);
echo "\nActivate Result:\n";
print_r($act);
echo "\nDone.</pre>";
