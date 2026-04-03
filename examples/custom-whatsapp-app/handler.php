<?php
declare(strict_types=1);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/crest.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'],
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'],
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'],
]);

$request = Request::createFromGlobals();

// Bitrix24 sends outbound messages to this handler
// Refer to: https://apidocs.bitrix24.com/api-reference/imopenlines/imconnector/index.html

if ($request->get('event') === 'ONIMCONNECTORMESSAGEADD' || $request->get('event') === 'ONIMCONNECTORMESSAGESADD') {
    $data = $request->get('data');
    if (empty($data['MESSAGES']) || !is_array($data['MESSAGES'])) {
        error_log("No messages found in event data.");
        echo json_encode(['SUCCESS' => false, 'error' => 'No messages']);
        exit;
    }

    $appId = $whatsappConfig['gupshup_app_id'];
    $apiToken = $whatsappConfig['gupshup_api_token'];
    
    // Get line ID to report delivery status
    $lineFile = (dirname(__DIR__, 2) . '/var/line_id.txt');
    if (isset($whatsappConfig['var_dir'])) {
        $lineFile = $whatsappConfig['var_dir'] . '/line_id.txt';
    }
    $lineId = file_exists($lineFile) ? intval(file_get_contents($lineFile)) : 0;

    foreach ($data['MESSAGES'] as $arMessage) {
        $message = $arMessage['message']['text'] ?? '';
        $chatId = $arMessage['chat']['id'] ?? '';
        $imMsgId = $arMessage['im'] ?? null;
        
        $phone = preg_replace('/[^0-9]/', '', $chatId);
        
        if (empty($phone) || empty($message)) continue;

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'text',
            'text' => ['body' => $message]
        ];
        
        $url = 'https://partner.gupshup.io/partner/app/' . $appId . '/v3/message';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'accept: application/json',
            'Authorization: ' . $apiToken
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Gupshup send error: " . $error);
        } else {
            error_log("Gupshup response ($httpCode) for phone $phone: " . $response);
            $decoded = json_decode($response, true);
            $msgId = $decoded['messageId'] ?? $decoded['id'] ?? $decoded['gs_id'] ?? null;
            logMessageToJson($phone, $message, $msgId);
            
            // Push delivery status to Bitrix24
            if ($lineId && $imMsgId) {
                CRest::call('imconnector.send.status.delivery', [
                    'CONNECTOR' => 'keen_nexus',
                    'LINE' => $lineId,
                    'MESSAGES' => [
                        [
                            'im' => $imMsgId,
                            'message' => ['id' => [$msgId ?? uniqid()]],
                            'chat' => ['id' => $chatId]
                        ]
                    ]
                ]);
            }
        }
    }

    echo json_encode(['SUCCESS' => true]);
    exit;
}

/**
 * Logs the message to a file based on phone number.
 * Note: In IMOpenLines handler, we don't always have the CRM Entity ID immediately,
 * so we use the phone number based lookup or just log by phone.
 */
function logMessageToJson(string $phone, string $message, $msgId = null) {
    global $whatsappConfig;
    $BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
    $dir = $BASE_VAR_DIR . '/messages';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Since we don't have entityId/Type from the Bitrix event payload easily without extra REST calls,
    // we search for existing files that match this phone number in the messages directory.
    $files = glob($dir . '/*.json');
    $logged = false;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $history = json_decode($content, true) ?: [];
        if (!empty($history) && isset($history[0]['phone'])) {
            // Clean both phones for comparison
            $cleanLogPhone = preg_replace('/[^0-9]/', '', (string)$history[0]['phone']);
            $cleanTargetPhone = preg_replace('/[^0-9]/', '', $phone);
            
            if ($cleanLogPhone === $cleanTargetPhone) {
                $history[] = [
                    'id' => $msgId,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'phone' => $phone,
                    'message' => $message,
                    'status' => 'sent',
                    'direction' => 'outbound',
                    'source' => 'crm_chat'
                ];
                file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
                $logged = true;
                break;
            }
        }
    }
}
