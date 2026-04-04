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

// Filter out deleted leads/contacts AND respect native Bitrix24 permissions
if (!empty($conversations) && !empty($whatsappConfig['webhook_url'])) {
    require_once __DIR__ . '/SessionManager.php';
    $sessionManager = new SessionManager();
    $storedAuth = $sessionManager->getAuth();
    
    // Collect unique IDs found on disk to check permissions
    $leadIdsToCheck = [];
    $contactIdsToCheck = [];
    foreach ($conversations as $conv) {
        if ($conv['type'] === 'lead') $leadIdsToCheck[] = $conv['id'];
        elseif ($conv['type'] === 'contact') $contactIdsToCheck[] = $conv['id'];
    }

    $batch = [];
    $batch['is_admin'] = 'user.admin';
    
    if (!empty($leadIdsToCheck)) {
        $batch['leads'] = 'crm.lead.list?filter[ID]=' . json_encode($leadIdsToCheck) . '&select[]=ID';
    }
    if (!empty($contactIdsToCheck)) {
        $batch['contacts'] = 'crm.contact.list?filter[ID]=' . json_encode($contactIdsToCheck) . '&select[]=ID';
    }

    if (!empty($batch)) {
        // Use the browsing user's auth if available to respect their native permissions
        if ($storedAuth && !empty($storedAuth['AUTH_ID']) && !empty($storedAuth['DOMAIN'])) {
            $domain = htmlspecialchars($storedAuth['DOMAIN']);
            $batchUrl = 'https://' . $domain . '/rest/batch.json';
            $postFields = http_build_query([
                'cmd' => $batch, 
                'halt' => 0,
                'auth' => $storedAuth['AUTH_ID']
            ]);
        } else {
            // Fallback to system webhook (usually shows everything)
            $batchUrl = rtrim($whatsappConfig['webhook_url'], '/') . '/batch.json';
            $postFields = http_build_query(['cmd' => $batch, 'halt' => 0]);
        }

        $ch = curl_init($batchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For shared hosting / dev envs
        $response = curl_exec($ch);
        curl_close($ch);

        $resData = json_decode($response, true);
        $results = $resData['result']['result'] ?? [];
        $isAdmin = isset($results['is_admin']) && $results['is_admin'] === true;
        
        // Build maps of allowed IDs based on what Bitrix24 returned
        $allowedLeads = [];
        if (isset($results['leads']) && is_array($results['leads'])) {
            foreach ($results['leads'] as $l) $allowedLeads[] = (string)$l['ID'];
        }
        
        $allowedContacts = [];
        if (isset($results['contacts']) && is_array($results['contacts'])) {
            foreach ($results['contacts'] as $c) $allowedContacts[] = (string)$c['ID'];
        }

        $filteredConversations = [];
        foreach ($conversations as $conv) {
            // If it's a generic phone-only chat, only show to admins
            if ($conv['type'] === 'phone') {
                if ($isAdmin) $filteredConversations[] = $conv;
                continue;
            }

            // Check if Bitrix24 returned this ID (which means the user has permission to see it)
            if ($conv['type'] === 'lead' && in_array((string)$conv['id'], $allowedLeads)) {
                $filteredConversations[] = $conv;
            } elseif ($conv['type'] === 'contact' && in_array((string)$conv['id'], $allowedContacts)) {
                $filteredConversations[] = $conv;
            } elseif ($isAdmin) {
                // If the user is admin, allow everything even if not explicitly in the filtered lists
                // (e.g. for records that might be in a different category or state but still exist)
                $filteredConversations[] = $conv;
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
                // Keep the one with the more recent activity
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
