<?php
declare(strict_types=1);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'],
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'],
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'],
]);

$request = Request::createFromGlobals();

// Bitrix24 sends outbound messages to this handler
// Refer to: https://apidocs.bitrix24.com/api-reference/imopenlines/imconnector/index.html

if ($request->get('event') === 'ONIMCONNECTORMESSAGESADD') {
    // This is where you would call your WhatsApp API to send the message
    $data = $request->get('data');
    $message = $data['message']['text'];
    $chatId = $data['chat']['id'];

    // LOGIC: Send to WhatsApp via your whatsapp_api_token
    // Example: sendToWhatsApp($message, $chatId, $whatsappConfig['whatsapp_api_token']);

    error_log(sprintf("Outbound message to WhatsApp: %s in chat %s", $message, $chatId));
    
    echo json_encode(['SUCCESS' => true]);
    exit;
}

// Inbound flow (simulate or handle WhatsApp webhook)
if ($request->get('source') === 'whatsapp') {
    // This is where your WhatsApp provider would hit your webhook
    $b24Service = ServiceBuilderFactory::createServiceBuilderFromWebhook($whatsappConfig['webhook_url']);
    
    // Send to Bitrix24
    // $b24Service->getIMOpenLinesScope()->connector()->sendMessages(...)
    
    echo "Message received from WhatsApp and sent to Bitrix24";
}
