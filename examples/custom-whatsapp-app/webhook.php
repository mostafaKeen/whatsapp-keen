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

// Setup
require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$MSG_DIR      = $BASE_VAR_DIR . '/messages';
$LOG_DIR      = $BASE_VAR_DIR . '/webhook_events';
$JOB_DIR      = $BASE_VAR_DIR . '/jobs';
$WEBHOOK_URL  = $whatsappConfig['webhook_url']; // Bitrix24 direct webhook URL

if (!is_dir($MSG_DIR)) mkdir($MSG_DIR, 0755, true);
if (!is_dir($LOG_DIR)) mkdir($LOG_DIR, 0755, true);
if (!is_dir($JOB_DIR)) mkdir($JOB_DIR, 0755, true); 
$MEDIA_BASE_DIR = $BASE_VAR_DIR . '/media';
if (!is_dir($MEDIA_BASE_DIR)) mkdir($MEDIA_BASE_DIR, 0755, true);

$rawBody = file_get_contents('php://input');

// Log raw event
$logFile = $LOG_DIR . '/event_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
file_put_contents($logFile, $rawBody);

$decoded = json_decode($rawBody, true);
if (!is_array($decoded)) {
    exit;
}

// Extract Gupshup App ID context if provided at root
$appId = $decoded['gs_app_id'] ?? ($whatsappConfig['gupshup_app_id'] ?? '');

// Parse Meta V3 envelope
foreach ($decoded['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $field = $change['field'] ?? '';
        $value = $change['value'] ?? null;

        if (!is_array($value)) {
            continue;
        }

        // ─── 0. BILLING EVENTS ───────────────────────────────────────────
        if ($field === 'billing-event') {
            $billing = $value['billing'] ?? null;
            if ($billing) {
                handleBillingEvent($BASE_VAR_DIR, $billing);
            }
            continue;
        }

        // ─── 0.5 TEMPLATE UPDATES ────────────────────────────────────────
        if ($field === 'message_template_status_update') {
            handleTemplateUpdate($BASE_VAR_DIR, $value);
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

                //Extract errors if exist
                $errorMsg = null;
                if (!empty($st['errors']) && is_array($st['errors'])) {
                    $errParts = [];
                    foreach ($st['errors'] as $err) {
                        $code = isset($err['code']) ? "[Code: " . $err['code'] . "] " : "";
                        $msg = $err['message'] ?? ($err['title'] ?? 'Unknown error');
                        $detail = !empty($err['error_data']['details']) ? " - " . $err['error_data']['details'] : "";
                        $errParts[] = $code . $msg . $detail;
                    }
                    $errorMsg = implode("; ", $errParts);
                }

                if (!$status) continue;

                if ($status === 'enqueued' && ($gsId || $id) && $recipientPhone) {
                    backfillMessageId($MSG_DIR, $recipientPhone, $gsId ?? $id);
                }

                if ($status !== 'enqueued' && ($gsId || $id || $metaId)) {
                    $found = updateMessageStatusInLogs($MSG_DIR, $gsId, $id, $metaId, $status);
                    if (!$found && $recipientPhone) {
                        updateStatusByPhone($MSG_DIR, $recipientPhone, $gsId, $id, $metaId, $status);
                    }
                    updateCampaignJobStatus($JOB_DIR, $gsId ?? $id ?? $metaId, $status, $errorMsg);
                }
                error_log("Status event: status=$status, gs_id=$gsId, id=$id, phone=$recipientPhone");
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
                    'location' => '[Location: ' . ($msg['location']['name'] ?? $msg['location']['address'] ?? ($msg['location']['latitude'] . ',' . $msg['location']['longitude'])) . ']',
                    'contacts' => '[Contact: ' . ($msg['contacts'][0]['name']['formatted_name'] ?? 'Contact Card') . ']',
                    'interactive' => match($msg['interactive']['type'] ?? '') {
                        'button_reply' => $msg['interactive']['button_reply']['title'] ?? '[Button Reply]',
                        'list_reply'   => $msg['interactive']['list_reply']['title']   ?? '[List Selection]',
                        default        => '[Interactive Message]'
                    },
                    'button'   => $msg['button']['text'] ?? '[Button Click]',
                    default    => '[Unsupported message type: ' . $type . ']',
                };

                // 2A. Extract Media Links
                $mediaId = null;
                $mediaUrl = null;
                if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
                    $mediaId  = $msg[$type]['id']  ?? null;
                    $mediaUrl = $msg[$type]['url'] ?? null;
                    if ($mediaUrl) {
                        $text .= " [Attached Media]";
                    }
                }

                // Add extra context if it's location or interactive
                $extraData = [];
                if ($type === 'location') {
                    $extraData = [
                        'latitude'  => $msg['location']['latitude']  ?? null,
                        'longitude' => $msg['location']['longitude'] ?? null,
                        'address'   => $msg['location']['address']   ?? null,
                        'name'      => $msg['location']['name']      ?? null,
                        'map_url'   => isset($msg['location']['latitude']) ? "https://www.google.com/maps?q={$msg['location']['latitude']},{$msg['location']['longitude']}" : null
                    ];
                } elseif ($type === 'interactive') {
                    $extraData = [
                        'interactive_type' => $msg['interactive']['type'] ?? null,
                        'reply_id'         => $msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? null
                    ];
                } elseif ($type === 'contacts') {
                    $extraData = [
                        'contact_name' => $msg['contacts'][0]['name']['formatted_name'] ?? null,
                        'contact_phones' => array_column($msg['contacts'][0]['phones'] ?? [], 'phone')
                    ];
                }

                // Get sender name
                $senderName = '';
                foreach ($value['contacts'] ?? [] as $contact) {
                    if (preg_replace('/[^0-9]/', '', $contact['wa_id'] ?? '') === $phone) {
                        $senderName = $contact['profile']['name'] ?? '';
                        break;
                    }
                }
                if (!$senderName) $senderName = 'WhatsApp +' . $phone;

                if (!$phone) continue;

                // 2A. Check for Template Reply (Campaign Matching)
                $campaignInfo = matchCampaignJobByPhone($JOB_DIR, $phone);
                $campaignPrefix = ($campaignInfo) ? "Reply to template: [" . $campaignInfo['template_name'] . "]. Message: " : "Gupshup Message: ";

                // 2B. Look up Lead/Contact by phone (or create new Lead)
                $entity = findOrCreateLeadByPhone($phone, $senderName, $WEBHOOK_URL, $campaignInfo);

                if ($entity) {
                    // 2C. Add the message as a comment/TIMELINE entry to the found lead
                    addMessageToBitrixEntity($WEBHOOK_URL, $entity, $text, $campaignInfo, null, $mediaId);

                    $filename = $MSG_DIR . '/' . strtolower($entity['type']) . '_' . $entity['id'] . '.json';
                    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
                    
                    // Prevent duplicate inbound messages
                    foreach ($history as $existing) {
                        if (($existing['id'] ?? '') === $messageId) {
                            error_log("Duplicate inbound message skipped: $messageId");
                            continue 2;
                        }
                    }

                    $history[] = [
                        'id'           => $messageId,
                        'timestamp'    => date('Y-m-d H:i:s', (int)$timestamp),
                        'phone'        => '+' . $phone,
                        'message'      => $text,
                        'campaign_name'=> $campaignInfo['template_name'] ?? null,
                        'message_type' => $type,
                        'status'       => 'received',
                        'direction'    => 'inbound',
                        'source'       => 'whatsapp',
                        'sender_name'  => $senderName,
                        'extra'        => $extraData,
                        'media_id'     => $mediaId,
                        'external_url' => $mediaUrl
                    ];
    
                    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
                } else {
                    // Fallback to phone file
                    $filename = $MSG_DIR . '/phone_' . $phone . '.json';
                    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
                    $history[] = [
                        'id'           => $messageId,
                        'timestamp'    => date('Y-m-d H:i:s', (int)$timestamp),
                        'phone'        => '+' . $phone,
                        'message'      => $text,
                        'message_type' => $type,
                        'status'       => 'received',
                        'direction'    => 'inbound',
                        'source'       => 'whatsapp',
                        'sender_name'  => $senderName,
                        'extra'        => $extraData,
                        'media_id'     => $mediaId,
                        'external_url' => $mediaUrl
                    ];
                    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
                }
            }
        }
    }
}
exit;




/**
 * Searches jobs directory for a match for the incoming phone number.
 */
function matchCampaignJobByPhone(string $jobDir, string $phone): ?array {
    $cleanP = ltrim(preg_replace('/[^0-9]/', '', (string)$phone), '0'); // Strip any leading zeros
    
    // Sort files by modified time descending to get the most recent campaign job first
    $files = glob($jobDir . '/*.json');
    if (!$files) return null;
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['targets'])) continue;
        foreach($data['targets'] as $t) {
            $cleanT = ltrim(preg_replace('/[^0-9]/', '', (string)$t['phone']), '0');
            // Check if one ends with the other to handle country code prefix differences
            if (str_ends_with($cleanP, $cleanT) || str_ends_with($cleanT, $cleanP)) {
                return [
                    'job_id' => $data['job_id'],
                    'template_name' => $data['template_name'],
                    'template_id' => $data['template_id'],
                    'responsible_id' => $data['responsible_id'] ?? null
                ];
            }
        }
    }
    return null;
}

/**
 * Pushes the inbound message to Bitrix24 as a activity with optional media.
 */
function addMessageToBitrixEntity(string $webhookUrl, array $entity, string $text, ?array $campaign, ?string $mediaPath = null, ?string $mediaId = null): void {
    $title = ($campaign) ? "WhatsApp Reply to Campaign: [" . $campaign['template_name'] . "]" : "Inbound WhatsApp Message";
    
    $fields = [
        'OWNER_TYPE_ID' => $entity['type'] === 'lead' ? 1 : 3, // 1=Lead, 3=Contact
        'OWNER_ID'      => $entity['id'],
        'TYPE_ID'       => 1, 
        'COMMUNICATION_TYPE_ID' => 'PHONE',
        'DIRECTION'     => 1, // Inbound
        'SUBJECT'       => $title,
        'DESCRIPTION'   => $text,
        'COMPLETED'     => 'Y',
        'RESPONSIBLE_ID'=> ($campaign && isset($campaign['responsible_id']) && $campaign['responsible_id']) ? $campaign['responsible_id'] : 1,
    ];

    // If we have media, attach it
    if ($mediaPath && file_exists($mediaPath)) {
        $filename = basename($mediaPath);
        $content = base64_encode(file_get_contents($mediaPath));
        $fields['FILES'] = [
            ['fileData' => [$filename, $content]]
        ];
    }

    bitrix24Call($webhookUrl, 'crm.activity.add', ['fields' => $fields]);
}


// ─── FUNCTIONS ─────────────────────────────────────────────────────────────

/**
 * Search Bitrix24 for a Lead or Contact by phone.
 * Creates a new Lead if not found.
 * Returns ['type' => 'lead', 'id' => 123] or null.
 */
function findOrCreateLeadByPhone(string $phone, string $name, string $webhookUrl, ?array $campaign = null): ?array {

    // Try multiple phone formats
    $variants = [
        $phone,
        '+' . $phone,
        '00' . $phone,
    ];

    $res = bitrix24Call($webhookUrl, 'crm.duplicate.findbycomm', [
        'type'        => 'PHONE',
        'values'      => $variants,
        'entity_type' => ['LEAD'], // Only search for Leads to satisfy "please create lead" requirement
    ]);

    if (!empty($res['result']['LEAD'])) {
        return ['type' => 'lead', 'id' => (int)$res['result']['LEAD'][0]];
    }

    // Not found — create new Lead
    error_log("No Lead/Contact found for +$phone, creating new Lead...");
    
    $leadFields = [
        'TITLE'     => ($campaign ? 'CAMPAIGN: ' . $campaign['template_name'] : 'WA Inquiry') . ': +' . $phone,
        'NAME'      => $name ?: 'WhatsApp User',
        'PHONE'     => [['VALUE' => '+' . $phone, 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID' => 'WA_GUPSHUP',
        'COMMENTS'  => 'Auto-created from WhatsApp Gupshup Webhook' . ($campaign ? "\nReplied to: " . $campaign['template_name'] : ""),
    ];
    
    if ($campaign && isset($campaign['responsible_id']) && !empty($campaign['responsible_id'])) {
        $leadFields['ASSIGNED_BY_ID'] = $campaign['responsible_id'];
    }

    $createRes = bitrix24Call($webhookUrl, 'crm.lead.add', [
        'fields' => $leadFields,
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
 * Backfill: find the most recent outbound entry with null id for a given
 * phone number and assign the Gupshup ID to it.
 * Called when 'enqueued' event arrives — this IS the ID assignment event.
 */
function backfillMessageId(string $dir, string $phone, string $newId): void {
    if (!is_dir($dir)) return;

    $files = glob($dir . '/*.json');
    foreach ($files as $file) {
        $history = json_decode(file_get_contents($file), true) ?: [];
        $updated = false;

        // Work backwards to find the latest null-ID outbound message for this phone
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entryPhone = preg_replace('/[^0-9]/', '', $history[$i]['phone'] ?? '');
            $isMatch = ($entryPhone === $phone || $entryPhone === ltrim($phone, '0'));

            if ($isMatch && ($history[$i]['id'] ?? null) === null && ($history[$i]['direction'] ?? '') === 'outbound') {
                $history[$i]['id']     = $newId;
                $history[$i]['status'] = 'sent'; // enqueued → sent in UI terms
                $updated = true;
                error_log("Backfilled ID $newId into " . basename($file) . " entry #$i for phone $phone");
                break; // Only fill the most recent one
            }
        }

        if ($updated) {
            file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
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


/**
 * Update campaign job target status by message ID for webhook analysis
 */
function updateCampaignJobStatus(string $jobDir, ?string $messageId, string $status, ?string $errorMsg = null): void {
    if (!$messageId || !is_dir($jobDir)) return;

    $files = glob($jobDir . '/*.json');
    foreach ($files as $file) {
        $fp = fopen($file, 'r+');
        if (!$fp) continue;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            continue;
        }

        $fileContent = stream_get_contents($fp);
        $jobData = json_decode($fileContent, true);

        if (!$jobData || !isset($jobData['targets'])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            continue;
        }

        $updated = false;
        foreach ($jobData['targets'] as &$target) {
            $tMsgId = $target['message_id'] ?? null;
            if ($tMsgId && $tMsgId === $messageId) {
                // Determine actual status
                $actualStatus = $status;
                if ($status === 'failed') {
                    $actualStatus = 'webhook_failed';
                }
                
                $oldStatus = $target['status'] ?? '';
                $statusOrder = ['sent' => 0, 'delivered' => 1, 'read' => 2, 'webhook_failed' => -1];
                $oldRank = $statusOrder[$oldStatus] ?? 0;
                $newRank = $statusOrder[$actualStatus] ?? 0;
                
                if ($newRank > $oldRank || $actualStatus === 'webhook_failed') {
                    $target['status'] = $actualStatus;
                    if ($errorMsg) {
                        $target['error'] = $errorMsg;
                    }
                    $updated = true;
                }
                break;
            }
        }
        unset($target);

        if ($updated) {
            $d = 0; $r = 0; $wf = 0;
            foreach ($jobData['targets'] as $t) {
                $s = $t['status'] ?? '';
                if ($s === 'delivered') { $d++; }
                elseif ($s === 'read') { $r++; $d++; } // read implicitly counts as delivered
                elseif ($s === 'webhook_failed') { $wf++; }
            }
            $jobData['delivered'] = $d;
            $jobData['read'] = $r;
            $jobData['webhook_failed'] = $wf;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($jobData, JSON_PRETTY_PRINT));
            
            flock($fp, LOCK_UN);
            fclose($fp);
            break; // Message ID found and updated, can stop searching
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Handle template status updates from Meta
 */
function handleTemplateUpdate(string $baseDir, array $data): void {
    $file = $baseDir . '/template_updates.json';
    $updates = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event'     => $data['event'] ?? 'UNKNOWN',
        'template_name' => $data['message_template_name'] ?? 'unknown',
        'template_id'   => $data['message_template_id'] ?? null,
        'reason'    => $data['reason'] ?? null,
        'language'  => $data['message_template_language'] ?? null
    ];
    
    // Prepend to show latest first
    array_unshift($updates, $entry);
    // Keep last 100 updates
    $updates = array_slice($updates, 0, 100);
    
    file_put_contents($file, json_encode($updates, JSON_PRETTY_PRINT));
}

/**
 * Handle billing events
 */
function handleBillingEvent(string $baseDir, array $billing): void {
    $file = $baseDir . '/billing_history.json';
    $history = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    
    $deductions = $billing['deductions'] ?? [];
    $refs = $billing['references'] ?? [];
    
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'destination' => $refs['destination'] ?? null,
        'gs_id'       => $refs['gs_id'] ?? null,
        'category'    => $deductions['category'] ?? null,
        'type'        => $deductions['type'] ?? null,
        'billable'    => $deductions['billable'] ?? null,
        'model'       => $deductions['model'] ?? null
    ];
    
    array_unshift($history, $entry);
    $history = array_slice($history, 0, 500);
    
    file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Try to link billing to message history if possible
    if (!empty($entry['gs_id'])) {
        updateMessageBillingInLogs($baseDir . '/messages', $entry['gs_id'], $entry['category'], (bool)$entry['billable']);
    }
}

/**
 * Update message entry with billing info
 */
function updateMessageBillingInLogs(string $dir, string $gsId, ?string $category, bool $billable): void {
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.json');
    foreach ($files as $file) {
        $history = json_decode(file_get_contents($file), true) ?: [];
        $updated = false;
        foreach ($history as &$entry) {
            if (($entry['id'] ?? null) === $gsId) {
                $entry['billing_category'] = $category;
                $entry['is_billable'] = $billable;
                $updated = true;
            }
        }
        if ($updated) {
            file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}
