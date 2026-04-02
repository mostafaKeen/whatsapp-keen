<?php
declare(strict_types=1);

require_once(__DIR__.'/crest.php');

$whatsappConfig = require __DIR__ . '/../config.php';
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');

$connector_id = 'keen_nexus';
$widgetName = 'Keen Nexus WhatsApp';

if (!is_dir($BASE_VAR_DIR)) {
    mkdir($BASE_VAR_DIR, 0775, true);
}

// User clicked "Connect" in Contact Center
if (!empty($_REQUEST['PLACEMENT_OPTIONS']) && $_REQUEST['PLACEMENT'] === 'SETTING_CONNECTOR') {
    $options = json_decode($_REQUEST['PLACEMENT_OPTIONS'], true);
    
    if (isset($options['LINE'])) {
        $lineId = intval($options['LINE']);
        
        $result = CRest::call(
            'imconnector.activate',
            [
                'CONNECTOR' => $connector_id,
                'LINE' => $lineId,
                'ACTIVE' => intval($options['ACTIVE_STATUS']),
            ]
        );
        
        if (!empty($result['result'])) {
            $widgetUri = ''; // No external widget needed for Whatsapp Open channel
            
            $resultWidgetData = CRest::call(
                'imconnector.connector.data.set',
                [
                    'CONNECTOR' => $connector_id,
                    'LINE' => $lineId,
                    'DATA' => [
                        'id' => $connector_id . 'line' . $lineId,
                        'url_im' => $widgetUri,
                        'name' => $widgetName
                    ],
                ]
            );
            
            if (!empty($resultWidgetData['result'])) {
                // Save the line ID to associate inbound messages
                file_put_contents($BASE_VAR_DIR . '/line_id.txt', (string)$lineId);
                echo 'Connector successfully activated.';
            } else {
                echo 'Activated line, but failed to set widget data.';
            }
        } else {
            echo 'Activation failed: ' . json_encode($result);
        }
    } else {
        echo 'Missing line parameter in placement options.';
    }
} else {
    echo 'Invalid placement request.';
}
