<?php
declare(strict_types=1);
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

    $postData = [
        'channel' => 'whatsapp',
        'source' => $jobData['source'],
        'sandbox' => 'false',
        'destination' => $t['phone'],
        'template' => json_encode([
            'id' => $jobData['template_id'], 
            'params' => array_values($params) // Ensure indexed array
        ]),
        'src.name' => $jobData['app_name']
    ];

    if ($messageData) {
        $postData['message'] = json_encode($messageData);
    }
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

    if ($httpCode >= 200 && $httpCode < 300 && in_array($respStatus, ['success', 'submitted'])) {
        // Check if phone belongs to a Lead in Bitrix24
        $lead = findLeadByPhone($batch[$i]['phone'], $whatsappConfig['webhook_url']);
        
        if ($lead) {
            // Log to lead-specific history
            logToLeadHistory($lead['id'], $jobData['template_name'], $batch[$i]['phone'], $batch[$i]['message_id'], $jobData['source'], $jobData['media_url'] ?? null);
            // Add Bitrix Activity (with media if present)
            addCampaignActivityToBitrix($whatsappConfig['webhook_url'], $lead['id'], $jobData['template_name'], $jobData['source'], $jobData['media_url'] ?? null);
        }

        // Always log to the general bulk log
        logToHistory($jobData['template_name'], $batch[$i]['phone'], $batch[$i]['message_id'], $jobData['source'], $jobData['media_url'] ?? null);

    } else {
        $batch[$i]['status'] = 'failed';
        $batch[$i]['error'] = $error ?: ($decoded['message'] ?? 'HTTP ' . $httpCode);
        $jobData['failed']++;
        
        // Log for debugging
        logDetailedError($jobId, $batch[$i], $postDataMap[$i] ?? [], $response, $httpCode, $error);
    }
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
