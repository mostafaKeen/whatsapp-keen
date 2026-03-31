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

// Filter out deleted leads/contacts AND respect ownership
if (!empty($conversations) && !empty($whatsappConfig['webhook_url'])) {
    require_once __DIR__ . '/SessionManager.php';
    $sessionManager = new SessionManager();
    $storedAuth = $sessionManager->getAuth();
    
    $batch = [];
    $batch['me'] = 'user.current'; // Get current browsing user ID
    $batch['is_admin'] = 'user.admin'; // Check if current user is admin
    
    foreach ($conversations as $index => $conv) {
        if ($conv['type'] === 'lead') {
            $batch['check_' . $index] = 'crm.lead.get?id=' . $conv['id'];
        } elseif ($conv['type'] === 'contact') {
            $batch['check_' . $index] = 'crm.contact.get?id=' . $conv['id'];
        }
    }

    if (!empty($batch)) {
        // Use the browsing user's auth if available, otherwise fallback to the system webhook
        if ($storedAuth && !empty($storedAuth['AUTH_ID']) && !empty($storedAuth['DOMAIN'])) {
            $domain = htmlspecialchars($storedAuth['DOMAIN']);
            $batchUrl = 'https://' . $domain . '/rest/batch.json';
            $postFields = http_build_query([
                'cmd' => $batch, 
                'halt' => 0,
                'auth' => $storedAuth['AUTH_ID']
            ]);
        } else {
            $batchUrl = rtrim($whatsappConfig['webhook_url'], '/') . '/batch.json';
            $postFields = http_build_query(['cmd' => $batch, 'halt' => 0]);
        }

        $ch = curl_init($batchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $response = curl_exec($ch);
        curl_close($ch);

        $resData = json_decode($response, true);
        $results = $resData['result']['result'] ?? [];
        $me = $results['me'] ?? null;
        $currentUserId = $me ? (int)$me['ID'] : 0;
        $isAdmin = isset($results['is_admin']) && $results['is_admin'] === true;

        $filteredConversations = [];
        foreach ($conversations as $index => $conv) {
            $key = 'check_' . $index;
            
            // If it's a generic phone-only chat, only show to admins
            if ($conv['type'] === 'phone') {
                if ($isAdmin) {
                    $filteredConversations[] = $conv;
                }
                continue;
            }

            // Check record assignment
            if (isset($results[$key]) && !empty($results[$key])) {
                $record = $results[$key];
                $assignedId = (int)($record['ASSIGNED_BY_ID'] ?? 0);
                
                if ($isAdmin || ($currentUserId > 0 && $assignedId === $currentUserId)) {
                    $filteredConversations[] = $conv;
                }
            }
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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
echo json_encode($conversations);
