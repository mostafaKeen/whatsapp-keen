<?php
declare(strict_types=1);

// Enable error output for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

// Prepare Bitrix24 Service Builder using Webhook URL for fast inbound processing
$b24Service = ServiceBuilderFactory::createServiceBuilderFromWebhook($whatsappConfig['webhook_url']);

$connectorId = 'whatsapp_direct';

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);

if (!$decoded) {
    http_response_code(400);
    echo "Bad Request";
    exit;
}

// Gupshup Partner V3 webhook payload
$value = $decoded['entry'][0]['changes'][0]['value'] ?? null;

if (is_array($value) && !empty($value['messages'][0])) {
    $msg = $value['messages'][0];
    
    // Extract phone and message details
    $phone = $msg['from'] ?? null;
    $text = $msg['text']['body'] ?? "[Media/Other message unsupported via open channels text API yet]";
    $messageId = $msg['id'] ?? uniqid();
    $timestamp = $msg['timestamp'] ?? time();
    $senderName = $msg['profile']['name'] ?? 'WhatsApp User';

    if ($phone) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        try {
            // Send the message into Bitrix24 IMOpenLines Channel!
            $b24Service->getIMOpenLinesScope()->connector()->sendMessages([
                0 => [ // message list
                    'im' => [
                        'chat_id' => $cleanPhone, // The external ID for the chat
                        'message_id' => $messageId,
                    ],
                    'message' => [
                        'id' => $messageId,
                        'date' => $timestamp,
                        'text' => $text,
                    ],
                    'user' => [
                        'id' => $cleanPhone, // The external ID for the user
                        'name' => $senderName,
                        'last_name' => '',
                    ],
                    'chat' => [
                        'id' => $cleanPhone,
                        'url' => 'https://wa.me/' . $cleanPhone,
                    ]
                ]
            ], $connectorId, null);
            
            error_log("Successfully routed Gupshup incoming message from $phone to Bitrix24 IMOpenLines.");
        } catch (\Exception $e) {
            error_log("Failed to send Gupshup message into Bitrix24: " . $e->getMessage());
        }
    }
}

// Quickly acknowledge the Gupshup webhook so it doesn't retry
http_response_code(200);
echo json_encode(["status" => "ok"]);
exit;
