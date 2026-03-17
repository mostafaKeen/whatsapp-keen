<?php
declare(strict_types=1);

/**
 * get_conversations.php
 * Reads all files from var/messages, extracts the last message, 
 * and returns a sorted list of conversations (newest first).
 */

$whatsappConfig = require __DIR__ . '/../config.php';
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$MSG_DIR = $BASE_VAR_DIR . '/messages';

if (!is_dir($MSG_DIR)) {
    echo json_encode([]);
    exit;
}

$files = glob($MSG_DIR . '/*.json');
$conversations = [];

foreach ($files as $file) {
    $filename = basename($file);
    if ($filename === 'lead_bulk.json') continue; // Skip general outbound history

    $content = file_get_contents($file);
    $history = json_decode($content, true);

    if (empty($history) || !is_array($history)) continue;

    // Get the very last message in the array
    $lastMsg = end($history);
    
    // Parse entity type and ID from filename (e.g. "lead_16500.json" or "contact_140.json" or "phone_97150.json")
    $entityType = 'unknown';
    $entityId = '';
    
    if (preg_match('/^(lead|contact|phone)_([0-9]+)\.json$/', $filename, $matches)) {
        $entityType = $matches[1];
        $entityId = $matches[2];
    } else {
        continue;
    }

    // Try to find a name from any inbound message in the history
    $name = 'Unknown Contact';
    for ($i = count($history) - 1; $i >= 0; $i--) {
        if (!empty($history[$i]['sender_name']) && $history[$i]['direction'] === 'inbound') {
            $name = $history[$i]['sender_name'];
            if (str_starts_with($name, 'WhatsApp +')) {
                // If the only name we have is the fallback, keep looking to see if an earlier one has a real name
                continue;
            }
            break;
        }
    }

    $conversations[] = [
        'type' => $entityType,
        'id' => $entityId,
        'phone' => $lastMsg['phone'] ?? '',
        'name' => $name,
        'last_message' => $lastMsg['message'] ?? '',
        'last_message_direction' => $lastMsg['direction'] ?? '',
        'last_message_status' => $lastMsg['status'] ?? '',
        'last_message_timestamp' => $lastMsg['timestamp'] ?? '',
        'unread' => 0 // In future, count 'received' inbound messages that haven't been 'read'
    ];
}

// Sort by timestamp descending (newest activity first)
usort($conversations, function($a, $b) {
    return (int)strtotime($b['last_message_timestamp'] ?: 'now') - (int)strtotime($a['last_message_timestamp'] ?: 'now');
});

// Filter out deleted leads/contacts
if (!empty($conversations) && !empty($whatsappConfig['webhook_url'])) {
    $batch = [];
    foreach ($conversations as $index => $conv) {
        if ($conv['type'] === 'lead') {
            $batch['check_' . $index] = 'crm.lead.get?id=' . $conv['id'];
        } elseif ($conv['type'] === 'contact') {
            $batch['check_' . $index] = 'crm.contact.get?id=' . $conv['id'];
        }
    }

    if (!empty($batch)) {
        $batchUrl = rtrim($whatsappConfig['webhook_url'], '/') . '/batch.json';
        $ch = curl_init($batchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['cmd' => $batch, 'halt' => 0]));
        $response = curl_exec($ch);
        curl_close($ch);

        $resData = json_decode($response, true);
        $results = $resData['result']['result'] ?? [];
        $errors = $resData['result']['result_error'] ?? [];

        $filteredConversations = [];
        foreach ($conversations as $index => $conv) {
            $key = 'check_' . $index;
            
            // If it's a generic phone-only chat, keep it
            if ($conv['type'] === 'phone') {
                $filteredConversations[] = $conv;
                continue;
            }

            // If Bitrix found the record and it's not null, keep it
            if (isset($results[$key]) && !empty($results[$key])) {
                $filteredConversations[] = $conv;
            }
            // If there's no error AND no result, or an explicit 'not found' error, it's deleted
        }

        // Final Deduplication by Phone Number
        $deduplicated = [];
        foreach ($filteredConversations as $conv) {
            $phone = $conv['phone'];
            if (!$phone) {
                $deduplicated[] = $conv;
                continue;
            }
            
            if (!isset($deduplicated[$phone])) {
                $deduplicated[$phone] = $conv;
            } else {
                // Keep the one with the more recent timestamp
                $currentTs = strtotime($conv['last_message_timestamp'] ?: '0');
                $existingTs = strtotime($deduplicated[$phone]['last_message_timestamp'] ?: '0');
                if ($currentTs > $existingTs) {
                    $deduplicated[$phone] = $conv;
                }
            }
        }
        $conversations = array_values($deduplicated);
    }
}

header('Content-Type: application/json');
echo json_encode($conversations);
