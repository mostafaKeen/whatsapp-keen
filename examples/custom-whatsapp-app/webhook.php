<?php
declare(strict_types=1);

/**
 * webhook.php
 * -----------------------------------------------
 * Gupshup Meta-passthrough V3 Webhook Receiver
 * - Handles: inbound messages, status updates, billing events
 * - Finds Lead/Contact by phone via Bitrix24 API
 * - Creates new Lead if not found
 * - Stores inbound messages in var/messages/{type}_{id}.json
 * - Updates outbound message statuses in history files
 * -----------------------------------------------
 */

// Immediately respond 200 OK so Gupshup doesn't retry
http_response_code(200);
echo json_encode(["status" => "ok"]);

// Run logic asynchronously (output already flushed)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_flush(); flush();
}

// Setup
require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$MSG_DIR    = dirname(__DIR__, 2) . '/var/messages';
$LOG_DIR    = dirname(__DIR__, 2) . '/var/webhook_events';
$WEBHOOK_URL = $whatsappConfig['webhook_url']; // Bitrix24 direct webhook URL

if (!is_dir($MSG_DIR)) mkdir($MSG_DIR, 0755, true);
if (!is_dir($LOG_DIR)) mkdir($LOG_DIR, 0755, true);

$rawBody = file_get_contents('php://input');

// Log raw event
$logFile = $LOG_DIR . '/event_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
file_put_contents($logFile, $rawBody);

$decoded = json_decode($rawBody, true);
if (!is_array($decoded)) {
    exit;
}

// Parse Meta V3 envelope
foreach ($decoded['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $field = $change['field'] ?? '';
        $value = $change['value'] ?? null;

        if ($field === 'billing-event' || !is_array($value)) {
            // Billing events - just log, no action needed
            continue;
        }

        // ─── 1. STATUS EVENTS ────────────────────────────────────────────
        if (!empty($value['statuses'])) {
            foreach ($value['statuses'] as $st) {
                $gsId    = $st['gs_id']      ?? null;
                $id      = $st['id']         ?? null;
                $metaId  = $st['meta_msg_id'] ?? null;
                $status  = $st['status']     ?? null;
                $recipientPhone = preg_replace('/[^0-9]/', '', $st['recipient_id'] ?? '');

                if ($status && ($gsId || $id || $metaId)) {
                    // Try to update existing history files
                    updateMessageStatusInLogs($MSG_DIR, $gsId, $id, $metaId, $status);

                    // If not found by message ID (new session), try by phone
                    if ($recipientPhone) {
                        updateStatusByPhone($MSG_DIR, $recipientPhone, $gsId, $id, $metaId, $status);
                    }

                    error_log("Status event: status=$status, gs_id=$gsId, id=$id");
                }
            }
        }

        // ─── 2. INBOUND MESSAGES ─────────────────────────────────────────
        if (!empty($value['messages'])) {
            foreach ($value['messages'] as $msg) {
                $phone     = preg_replace('/[^0-9]/', '', $msg['from'] ?? '');
                $messageId = $msg['id'] ?? uniqid('wa_');
                $timestamp = $msg['timestamp'] ?? time();
                $type      = $msg['type'] ?? 'text';

                // Extract text or caption
                $text = match($type) {
                    'text'     => $msg['text']['body']         ?? '',
                    'image'    => $msg['image']['caption']     ?? '[Image]',
                    'video'    => $msg['video']['caption']     ?? '[Video]',
                    'document' => $msg['document']['caption']  ?? ($msg['document']['filename'] ?? '[Document]'),
                    'audio'    => '[Voice Message]',
                    'sticker'  => '[Sticker]',
                    default    => '[Unsupported message type: ' . $type . ']',
                };

                // Get sender name from contacts array
                $senderName = '';
                foreach ($value['contacts'] ?? [] as $contact) {
                    if (preg_replace('/[^0-9]/', '', $contact['wa_id'] ?? '') === $phone) {
                        $senderName = $contact['profile']['name'] ?? '';
                        break;
                    }
                }
                if (!$senderName) $senderName = 'WhatsApp +' . $phone;

                if (!$phone) continue;

                // Look up Lead/Contact by phone (or create new Lead)
                $entity = findOrCreateLeadByPhone($phone, $senderName, $WEBHOOK_URL);

                if ($entity) {
                    $filename = $MSG_DIR . '/' . strtolower($entity['type']) . '_' . $entity['id'] . '.json';

                    // Prevent duplicate inbound messages
                    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
                    foreach ($history as $existing) {
                        if (($existing['id'] ?? '') === $messageId) {
                            error_log("Duplicate inbound message skipped: $messageId");
                            continue 2; // skip this message
                        }
                    }

                    $history[] = [
                        'id'           => $messageId,
                        'timestamp'    => date('Y-m-d H:i:s', (int)$timestamp),
                        'phone'        => '+' . $phone,
                        'message'      => $text,
                        'message_type' => $type,
                        'file_url'     => null,
                        'status'       => 'received',
                        'direction'    => 'inbound',
                        'source'       => 'whatsapp',
                        'sender_name'  => $senderName,
                    ];
                    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
                    error_log("Saved inbound msg from +$phone to {$entity['type']}_{$entity['id']}");
                } else {
                    // Fallback: save by phone number
                    $filename = $MSG_DIR . '/phone_' . $phone . '.json';
                    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
                    $history[] = [
                        'id'           => $messageId,
                        'timestamp'    => date('Y-m-d H:i:s', (int)$timestamp),
                        'phone'        => '+' . $phone,
                        'message'      => $text,
                        'message_type' => $type,
                        'file_url'     => null,
                        'status'       => 'received',
                        'direction'    => 'inbound',
                        'source'       => 'whatsapp',
                        'sender_name'  => $senderName,
                    ];
                    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
                    error_log("No Lead found for +$phone, saved to fallback file");
                }
            }
        }
    }
}

exit;

// ─── FUNCTIONS ─────────────────────────────────────────────────────────────

/**
 * Search Bitrix24 for a Lead or Contact by phone.
 * Creates a new Lead if not found.
 * Returns ['type' => 'lead', 'id' => 123] or null.
 */
function findOrCreateLeadByPhone(string $phone, string $name, string $webhookUrl): ?array {
    // Try multiple phone formats
    $variants = [
        $phone,
        '+' . $phone,
        '00' . $phone,
    ];

    $res = bitrix24Call($webhookUrl, 'crm.duplicate.findbycomm', [
        'type'        => 'PHONE',
        'values'      => $variants,
        'entity_type' => ['CONTACT', 'LEAD'],
    ]);

    if (!empty($res['result']['CONTACT'])) {
        return ['type' => 'contact', 'id' => (int)$res['result']['CONTACT'][0]];
    }
    if (!empty($res['result']['LEAD'])) {
        return ['type' => 'lead', 'id' => (int)$res['result']['LEAD'][0]];
    }

    // Not found — create new Lead
    error_log("No Lead/Contact found for +$phone, creating new Lead...");
    $createRes = bitrix24Call($webhookUrl, 'crm.lead.add', [
        'fields' => [
            'TITLE'     => 'WA Inquiry: +' . $phone,
            'NAME'      => $name ?: 'WhatsApp User',
            'PHONE'     => [['VALUE' => '+' . $phone, 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID' => 'WA_GUPSHUP',
            'COMMENTS'  => 'Auto-created from WhatsApp Gupshup Webhook',
        ],
    ]);

    if (!empty($createRes['result'])) {
        error_log("Created new Lead ID: " . $createRes['result']);
        return ['type' => 'lead', 'id' => (int)$createRes['result']];
    }

    error_log("Failed to create Lead for +$phone: " . json_encode($createRes));
    return null;
}

/**
 * Direct Bitrix24 webhook API call using cURL.
 */
function bitrix24Call(string $webhookUrl, string $method, array $params = []): array {
    $url = rtrim($webhookUrl, '/') . '/' . $method . '.json';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Bitrix24 API error ($method): $error");
        return [];
    }

    return json_decode($response, true) ?: [];
}

/**
 * Search all history files and update message status by message ID.
 */
function updateMessageStatusInLogs(string $dir, ?string $gsId, ?string $id, ?string $metaId, string $status): bool {
    if (!is_dir($dir)) return false;

    $files = glob($dir . '/*.json');
    $found = false;

    foreach ($files as $file) {
        $history = json_decode(file_get_contents($file), true) ?: [];
        $updated = false;

        foreach ($history as &$entry) {
            $eId = $entry['id'] ?? null;
            if ($eId && (
                ($gsId   && $eId === $gsId)   ||
                ($id     && $eId === $id)      ||
                ($metaId && $eId === $metaId)
            )) {
                $entry['status'] = $status;
                $updated = true;
                $found   = true;
                error_log("Updated status of $eId → $status in " . basename($file));
            }
        }
        unset($entry);

        if ($updated) {
            file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }

    return $found;
}

/**
 * Fallback: update status by phone number across all files.
 * Useful when status arrives before message ID is indexed.
 */
function updateStatusByPhone(string $dir, string $phone, ?string $gsId, ?string $id, ?string $metaId, string $status): void {
    if (!is_dir($dir)) return;

    $files = glob($dir . '/*.json');
    foreach ($files as $file) {
        $history = json_decode(file_get_contents($file), true) ?: [];
        $updated = false;

        // Check if this file belongs to the right phone
        $hasPhone = false;
        foreach ($history as $entry) {
            $entryPhone = preg_replace('/[^0-9]/', '', $entry['phone'] ?? '');
            if ($entryPhone === $phone) {
                $hasPhone = true;
                break;
            }
        }
        if (!$hasPhone) continue;

        foreach ($history as &$entry) {
            $eId = $entry['id'] ?? null;
            if ($eId && (
                ($gsId   && $eId === $gsId)   ||
                ($id     && $eId === $id)      ||
                ($metaId && $eId === $metaId)
            )) {
                $entry['status'] = $status;
                $updated = true;
            }
        }
        unset($entry);

        if ($updated) {
            file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}
