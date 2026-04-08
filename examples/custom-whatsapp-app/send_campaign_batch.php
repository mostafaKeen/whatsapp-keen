<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Dubai');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$whatsappConfig = require __DIR__ . '/../config.php';
$appId = $whatsappConfig['gupshup_app_id'];
$apiToken = $whatsappConfig['gupshup_api_token'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$jobId = $_POST['job_id'] ?? '';
$batchSize = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 5;

if (empty($jobId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'job_id is required']);
    exit;
}

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobDir = $BASE_VAR_DIR . '/jobs';
$jobFile = $jobDir . '/' . $jobId . '.json';

if (!file_exists($jobFile)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Job not found']);
    exit;
}

// Ensure atomic lock for this batch if multiple requests happen
$fp = fopen($jobFile, 'r+');
if (!flock($fp, LOCK_EX)) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Job is already being processed']);
    exit;
}

$fileContent = stream_get_contents($fp);
$jobData = json_decode($fileContent, true);

if (!$jobData || $jobData['status'] === 'completed' || $jobData['status'] === 'paused') {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode([
        'status' => 'success',
        'job_status' => $jobData['status'] ?? 'unknown',
        'processed' => $jobData['processed'] ?? 0,
        'total' => $jobData['total'] ?? 0
    ]);
    exit;
}

$jobData['status'] = 'running';

// Find next batch of pending targets
$batch = [];
$batchIndices = [];
foreach ($jobData['targets'] as $index => &$target) {
    if ($target['status'] === 'pending' || $target['status'] === 'rate_limited') {
        $batch[] = &$target;
        $batchIndices[] = $index;
        if (count($batch) >= $batchSize) {
            break;
        }
    }
}
unset($target);

if (empty($batch)) {
    $jobData['status'] = 'completed';
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($jobData, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode([
        'status' => 'success',
        'job_status' => 'completed',
        'processed' => $jobData['processed'],
        'total' => $jobData['total'],
        'success' => $jobData['success'],
        'failed' => $jobData['failed']
    ]);
    exit;
}

// API URL
$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/template/msg';

// Setup multi-curl or single curl loop? For simplicity and rate limit safety, we do sequential curl loop
$mh = curl_multi_init();
$curls = [];
$responses = [];

// Prepare requests
$postDataMap = []; // Store payload per index for error logging
foreach ($batch as $i => &$t) {
    // Setup params and message based on template type
    $params = [];
    $messageData = null;
    $mediaUrl = $jobData['media_url'] ?? '';
    $tempType = strtoupper($jobData['template_type'] ?? 'TEXT');

    if (!empty($mediaUrl)) {
        if ($tempType === 'IMAGE' || $tempType === 'VIDEO' || $tempType === 'DOCUMENT') {
            $typeLower = strtolower($tempType);
            $messageData = [
                'type' => $typeLower,
                $typeLower => [
                    'link' => $mediaUrl
                ]
            ];
            // Body variables for media templates go in template.params
            $params = $t['params'] ?? [];
        } else {
            // Text template with media URL passed - treat as first param or use mapping
            if (!empty($t['params'])) {
                $params = $t['params'];
            } else {
                $params[] = $mediaUrl;
            }
        }
    } else {
        // Just text template params
        $params = $t['params'] ?? [];
    }

    $templateObj = [
        'id' => $jobData['template_id'], 
        'params' => array_values($params) // Ensure indexed array — top-level body variables only
    ];

    // ================================================================
    // CAROUSEL Handling (Steps 1-7)
    // Gupshup Partner API structure:
    //   template = {"id":"...", "params":["body_var1"]}   (top-level body variables ONLY)
    //   message  = {"type":"carousel", "cards":[...]}     (card headers + card body params)
    // Card headers MUST go inside message.cards[].components, NOT in template.params
    // ================================================================
    if ($tempType === 'CAROUSEL') {
        $meta = json_decode($jobData['template_meta'] ?? '{}', true);
        
        if (!$meta || empty($meta['cards'])) {
            // No card metadata — cannot send carousel, fail this target
            $batch[$i]['status'] = 'failed';
            $batch[$i]['error'] = 'Carousel template metadata missing or has no cards';
            $jobData['processed']++;
            $jobData['failed']++;
            logDetailedError($jobId, $batch[$i], ['error' => 'Missing carousel card metadata'], '', 0);
            continue;
        }

        $carouselCards = [];
        $carouselValid = true;
        $carouselError = '';
        
        foreach ($meta['cards'] as $cIdx => $card) {
            $cardComponents = [];
            $headerType = strtoupper($card['headerType'] ?? 'IMAGE');
            
            // ---- STEP 1 & 2: Inspect header type and build accordingly ----
            if ($headerType === 'IMAGE' || $headerType === 'VIDEO') {
                // CASE A: Media header (IMAGE or VIDEO)
                $cardMediaUrl = $card['mediaUrl'] ?? '';
                
                // STEP 3 & 4: Validate media URL
                if (empty($cardMediaUrl)) {
                    $carouselValid = false;
                    $carouselError = "Carousel card $cIdx has empty mediaUrl for $headerType header";
                    break;
                }
                if (strpos($cardMediaUrl, 'https://') !== 0 && strpos($cardMediaUrl, 'http://') !== 0) {
                    $carouselValid = false;
                    $carouselError = "Carousel card $cIdx mediaUrl is not a valid URL: $cardMediaUrl";
                    break;
                }
                
                $mediaTypeLower = strtolower($headerType); // 'image' or 'video'
                $cardComponents[] = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => $mediaTypeLower,
                            $mediaTypeLower => ['link' => $cardMediaUrl]
                        ]
                    ]
                ];
                
            } elseif ($headerType === 'TEXT') {
                // CASE B: Text header with variable
                // Check if card has a headerText or if sampleHeader is available
                $headerValue = $card['headerText'] ?? $card['sampleHeader'] ?? $card['header'] ?? '';
                if (empty($headerValue)) {
                    $carouselValid = false;
                    $carouselError = "Carousel card $cIdx has TEXT header but no header value provided";
                    break;
                }
                $cardComponents[] = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => (string)$headerValue
                        ]
                    ]
                ];
            } else {
                // Unknown header type
                $carouselValid = false;
                $carouselError = "Carousel card $cIdx has unsupported header type: $headerType";
                break;
            }
            
            // ---- STEP 2 continued: Card BODY parameters ----
            $cardBody = $card['body'] ?? '';
            preg_match_all('/\{\{\d+\}\}/', $cardBody, $cardMatches);
            $cardBodyParams = [];
            // Card-level body variables are independent from top-level body
            // Currently our cards don't have body variables, so params stays empty
            // If cards had variables, mapping would need to be implemented here
            
            $cardComponents[] = [
                'type' => 'body',
                'parameters' => $cardBodyParams
            ];

            $carouselCards[] = [
                'card_index' => $cIdx,
                'components' => $cardComponents
            ];
        }
        
        // ---- STEP 6: Reject if any card failed validation ----
        if (!$carouselValid) {
            $batch[$i]['status'] = 'failed';
            $batch[$i]['error'] = $carouselError;
            $jobData['processed']++;
            $jobData['failed']++;
            logDetailedError($jobId, $batch[$i], ['validation_error' => $carouselError], '', 0);
            continue;
        }
        
        // ---- STEP 3: Final validation — ensure every card has a header component ----
        foreach ($carouselCards as $checkCard) {
            $hasHeader = false;
            foreach ($checkCard['components'] as $comp) {
                if ($comp['type'] === 'header') {
                    if (empty($comp['parameters'])) {
                        $carouselValid = false;
                        $carouselError = "Carousel card {$checkCard['card_index']} has header with empty parameters array";
                        break 2;
                    }
                    // Check no null values in parameters
                    foreach ($comp['parameters'] as $param) {
                        if ($param === null) {
                            $carouselValid = false;
                            $carouselError = "Carousel card {$checkCard['card_index']} has null header parameter";
                            break 3;
                        }
                        // For image type, verify link is not empty
                        if (($param['type'] ?? '') === 'image' && empty($param['image']['link'] ?? '')) {
                            $carouselValid = false;
                            $carouselError = "Carousel card {$checkCard['card_index']} has image header with empty link";
                            break 3;
                        }
                        // For text type, verify text is not empty
                        if (($param['type'] ?? '') === 'text' && empty($param['text'] ?? '')) {
                            $carouselValid = false;
                            $carouselError = "Carousel card {$checkCard['card_index']} has text header with empty value";
                            break 3;
                        }
                    }
                    $hasHeader = true;
                }
            }
            if (!$hasHeader) {
                $carouselValid = false;
                $carouselError = "Carousel card {$checkCard['card_index']} is missing header component entirely";
                break;
            }
        }
        
        if (!$carouselValid) {
            $batch[$i]['status'] = 'failed';
            $batch[$i]['error'] = $carouselError;
            $jobData['processed']++;
            $jobData['failed']++;
            logDetailedError($jobId, $batch[$i], ['validation_error' => $carouselError], '', 0);
            continue;
        }

        // ---- STEP 5: Build the message object with carousel cards ----
        $messageData = [
            'type' => 'carousel',
            'cards' => $carouselCards
        ];
    }

    // ---- STEP 5: Build the final POST payload ----
    $postData = [
        'channel' => 'whatsapp',
        'source' => $jobData['source'],
        'sandbox' => 'false',
        'destination' => $t['phone'],
        'template' => json_encode($templateObj),
        'src.name' => $jobData['app_name']
    ];

    if ($messageData) {
        $postData['message'] = json_encode($messageData);
    }
    
    // ---- STEP 7: Log full outgoing payload before sending ----
    logOutgoingPayload($jobId, $t['phone'], $postData);

    $postDataMap[$i] = $postData;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Connection: keep-alive',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: ' . $apiToken
    ]);

    $curls[$i] = $ch;

}
unset($t);

// We process sequentially so we can stop immediately if we hit 429
$rateLimited = false;
foreach ($curls as $i => $ch) {
    if ($rateLimited) {
        // If we hit a rate limit, leave the rest as pending
        break;
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 429) {
        $rateLimited = true;
        // Revert this phone back to pending/rate_limited
        $batch[$i]['status'] = 'rate_limited';
        $batch[$i]['error'] = 'HTTP 429 Too Many Requests';
        continue;
    }

    $jobData['processed']++;
    $decoded = json_decode($response, true);
    $respStatus = strtolower($decoded['status'] ?? '');
    $msgId = $decoded['messageId'] ?? null;

    if ($httpCode >= 200 && $httpCode < 300 && in_array($respStatus, ['success', 'submitted'])) {
        $batch[$i]['status'] = 'sent';
        $batch[$i]['message_id'] = $msgId;
        $jobData['success']++;

        // Check if phone belongs to a Lead in Bitrix24
        $lead = findLeadByPhone($batch[$i]['phone'], $whatsappConfig['webhook_url']);
        
        if ($lead) {
            // Log to lead-specific history
            logToLeadHistory($lead['id'], $jobData['template_name'], $batch[$i]['phone'], $msgId, $jobData['source'], $jobData['media_url'] ?? null);
            // Add Bitrix Activity (with media if present)
            addCampaignActivityToBitrix($whatsappConfig['webhook_url'], $lead['id'], $jobData['template_name'], $jobData['source'], $jobData['media_url'] ?? null);
        }

        // Always log to the general bulk log
        logToHistory($jobData['template_name'], $batch[$i]['phone'], $msgId, $jobData['source'], $jobData['media_url'] ?? null);

    } else {
        $batch[$i]['status'] = 'failed';
        $batch[$i]['error'] = $error ?: ($decoded['message'] ?? 'HTTP ' . $httpCode);
        $jobData['failed']++;
        
        // Log for debugging
        logDetailedError($jobId, $batch[$i], $postDataMap[$i] ?? [], $response, $httpCode, $error);
    }

    // Rate limiting: Delay between 200ms and 500ms to avoid spam flagging
    usleep(rand(200000, 500000));
}

if ($rateLimited) {
    $jobData['status'] = 'paused'; // pause so it doesn't spam errors
}

// Check if everything is done
$allDone = true;
foreach ($jobData['targets'] as $chk) {
    if ($chk['status'] === 'pending' || $chk['status'] === 'rate_limited') {
        $allDone = false;
        break;
    }
}
if ($allDone && !$rateLimited) {
    $jobData['status'] = 'completed';
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($jobData, JSON_PRETTY_PRINT));
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    'status' => 'success',
    'job_status' => $jobData['status'],
    'rate_limited' => $rateLimited,
    'processed' => $jobData['processed'],
    'total' => $jobData['total'],
    'success' => $jobData['success'],
    'failed' => $jobData['failed']
]);

function logToHistory($templateName, $phone, $msgId, $source, $mediaUrl = null) {
    $dir = dirname(__DIR__, 2) . '/var/messages';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    // Log it under a generic entity ID or using the phone as entity? Let's use lead_bulk
    $filename = $dir . '/lead_bulk.json';
    $logEntry = [
        'id' => $msgId,
        'timestamp' => date('Y-m-d H:i:s'),
        'phone' => $phone,
        'message' => 'Template: ' . $templateName,
        'message_type' => 'template',
        'status' => 'sent',
        'direction' => 'outbound',
        'source' => $source,
        'external_url' => $mediaUrl
    ];
    $history = [];
    if (file_exists($filename)) {
        $history = json_decode(file_get_contents($filename), true) ?: [];
    }
    $history[] = $logEntry;
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
}

function logDetailedError($jobId, $target, $payload, $response, $httpCode, $curlError = null) {
    global $whatsappConfig;
    $logDir = ($whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var')) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    
    $file = $logDir . '/campaign_send_errors.json';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'job_id' => $jobId,
        'phone' => $target['phone'],
        'payload' => $payload,
        'http_status' => $httpCode,
        'response' => json_decode($response, true) ?: $response,
        'curl_error' => $curlError
    ];
    
    $logs = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($logs)) $logs = [];
    array_unshift($logs, $entry);
    if (count($logs) > 500) array_pop($logs); // Keep last 500
    file_put_contents($file, json_encode($logs, JSON_PRETTY_PRINT));
}

/**
 * STEP 7: Log full outgoing payload before sending to API for debugging.
 */
function logOutgoingPayload($jobId, $phone, $postData) {
    global $whatsappConfig;
    $logDir = ($whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var')) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    
    $file = $logDir . '/campaign_outgoing_payloads.log';
    $entry = date('[Y-m-d H:i:s]') . " job=$jobId phone=$phone\n";
    $entry .= "  template=" . ($postData['template'] ?? 'N/A') . "\n";
    if (!empty($postData['message'])) {
        $entry .= "  message=" . $postData['message'] . "\n";
    }
    $entry .= "---\n";
    
    file_put_contents($file, $entry, FILE_APPEND);
}

/**
 * Searches Bitrix24 for a Lead by phone.
 */
function findLeadByPhone(string $phone, string $webhookUrl): ?array {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $variants = [ $cleanPhone, '+' . $cleanPhone ];

    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.duplicate.findbycomm.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'type'        => 'PHONE',
        'values'      => $variants,
        'entity_type' => ['LEAD'],
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $res = curl_exec($ch);
    $decoded = json_decode($res, true);
    curl_close($ch);

    if (!empty($decoded['result']['LEAD'])) {
        return ['type' => 'lead', 'id' => (int)$decoded['result']['LEAD'][0]];
    }
    return null;
}

/**
 * Logs a message to a specific lead's history file.
 */
function logToLeadHistory($leadId, $templateName, $phone, $msgId, $source, $mediaUrl = null) {
    global $BASE_VAR_DIR;
    $dir = $BASE_VAR_DIR . '/messages';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    $filename = $dir . '/lead_' . $leadId . '.json';
    $logEntry = [
        'id' => $msgId,
        'timestamp' => date('Y-m-d H:i:s'),
        'phone' => $phone,
        'message' => 'Campaign: ' . $templateName,
        'message_type' => 'template',
        'status' => 'sent',
        'direction' => 'outbound',
        'source' => $source,
        'external_url' => $mediaUrl
    ];
    
    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
    $history[] = $logEntry;
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
}

/**
 * Adds an outbound campaign message activity to the Lead's timeline, attaching media if provided.
 */
function addCampaignActivityToBitrix($webhookUrl, $leadId, $templateName, $source, $mediaUrl = null) {
    $fields = [
        'OWNER_TYPE_ID' => 1, // Lead
        'OWNER_ID'      => $leadId,
        'TYPE_ID'       => 1, // Meeting/Generic
        'COMMUNICATION_TYPE_ID' => 'PHONE',
        'DIRECTION'     => 2, // Outbound
        'SUBJECT'       => "WhatsApp Campaign: $templateName",
        'DESCRIPTION'   => "Sent template message via Gupshup ($source)" . ($mediaUrl ? "\nMedia: $mediaUrl" : ""),
        'COMPLETED'     => 'Y',
        'RESPONSIBLE_ID'=> 1
    ];

    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.activity.add.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['fields' => $fields]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}
