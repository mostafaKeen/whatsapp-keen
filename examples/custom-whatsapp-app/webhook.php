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

$connectorId = 'wosolkeen';

$rawBody = file_get_contents('php://input');

// --- Log RAW payload for future analysis ---
$logDir = __DIR__ . '/../../var/webhook_events';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$filename = $logDir . '/event_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
file_put_contents($filename, $rawBody);
// ------------------------------------------

$decoded = json_decode($rawBody, true);

if (!$decoded) {
    http_response_code(400);
    echo "Bad Request";
    exit;
}

// Gupshup Partner V3 webhook payload
$value = $decoded['entry'][0]['changes'][0]['value'] ?? null;

// 1. Handle Message Status Updates (enqueued, sent, delivered, read)
if (is_array($value) && !empty($value['statuses'][0])) {
    foreach ($value['statuses'] as $statusUpdate) {
        $gsId = $statusUpdate['gs_id'] ?? null;
        $metaId = $statusUpdate['id'] ?? $statusUpdate['meta_msg_id'] ?? null;
        $newStatus = $statusUpdate['status'] ?? null;
        
        if ($newStatus && ($gsId || $metaId)) {
            updateMessageStatusInLogs($gsId, $metaId, $newStatus);
        }
    }
}

// 2. Handle Incoming Messages
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
            error_log("Routing to Bitrix24: Connector=$connectorId, Phone=$cleanPhone, MsgId=$messageId");
            $b24Service->getIMOpenLinesScope()->connector()->sendMessages(
                $connectorId, 
                $connectorId, 
                [
                    0 => [
                        'im' => [
                            'chat_id' => $cleanPhone,
                            'message_id' => $messageId,
                        ],
                        'message' => [
                            'id' => $messageId,
                            'date' => (int)$timestamp,
                            'text' => $text,
                        ],
                        'user' => [
                            'id' => $cleanPhone,
                            'name' => $senderName,
                        ],
                        'chat' => [
                            'id' => $cleanPhone,
                            'url' => 'https://wa.me/' . $cleanPhone,
                        ],
                        'direction' => 'inbound',
                    ]
                ]
            );
            
            error_log("Successfully routed to Bitrix24 IMOpenLines.");
            
            // Also log to local JSON so the widget showing history updates
            logIncomingMessageLocally($cleanPhone, $text, $messageId, (string)$timestamp);
            
        } catch (\Throwable $e) {
            error_log("CRITICAL ERROR in inbound routing: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        }
    }
}

/**
 * Searches and updates message status in the messages directory.
 */
function updateMessageStatusInLogs($gsId, $metaId, $newStatus) {
    if (!$gsId && !$metaId) return;
    $dir = dirname(__DIR__, 2) . '/var/messages';
    if (!is_dir($dir)) return;
    
    $files = glob($dir . '/*.json');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $history = json_decode($content, true) ?: [];
        $updated = false;
        
        foreach ($history as &$entry) {
            $eId = $entry['id'] ?? null;
            if ($eId && ($eId === $gsId || $eId === $metaId)) {
                $entry['status'] = $newStatus;
                $updated = true;
                error_log("Matched message ID $eId in $file, setting status to $newStatus");
            }
        }
        
        if ($updated) {
            file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}

/**
 * Logs incoming messages to local JSON so the widget can display them.
 */
function logIncomingMessageLocally($phone, $text, $msgId, $timestamp) {
    $dir = dirname(__DIR__, 2) . '/var/messages';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    $files = glob($dir . '/*.json');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $history = json_decode($content, true) ?: [];
        if (!empty($history) && isset($history[0]['phone'])) {
            $cleanLogPhone = preg_replace('/[^0-9]/', '', (string)$history[0]['phone']);
            if ($cleanLogPhone === $phone) {
                // Check if already logged to avoid duplicates
                foreach ($history as $existing) {
                    if (isset($existing['id']) && $existing['id'] === $msgId) return;
                }
                
                $history[] = [
                    'id' => $msgId,
                    'timestamp' => date('Y-m-d H:i:s', (int)$timestamp),
                    'phone' => $phone,
                    'message' => $text,
                    'status' => 'received',
                    'direction' => 'inbound',
                    'source' => 'whatsapp'
                ];
                file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
                break;
            }
        }
    }
}

// Quickly acknowledge the Gupshup webhook so it doesn't retry
http_response_code(200);
echo json_encode(["status" => "ok"]);
exit;
