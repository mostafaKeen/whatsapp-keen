<?php
declare(strict_types=1);

/**
 * WhatsApp Campaign Background Worker
 * This script is intended to be run via CLI to process a campaign job.
 * Usage: php worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if ($argc < 2) {
    die("Usage: php worker.php <job_id>\n");
}

$jobId = $argv[1];
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Config file not found.\n");
}
$whatsappConfig = require $configPath;

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobDir = $BASE_VAR_DIR . '/jobs';
$jobFile = $jobDir . '/' . $jobId . '.json';

if (!file_exists($jobFile)) {
    die("Job file not found: $jobFile\n");
}

// Internal config
$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$appId = $whatsappConfig['gupshup_app_id'] ?? '';
$webhookUrl = $whatsappConfig['webhook_url'] ?? '';

// Prevent script timeout
set_time_limit(0);

echo "Starting job: $jobId\n";

function updateJobFile(string $jobFile, array $jobData): void {
    file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT));
}

$jobData = json_decode(file_get_contents($jobFile), true);
if (!$jobData) {
    die("Invalid job data.\n");
}

$jobData['status'] = 'running';
updateJobFile($jobFile, $jobData);

$url = 'https://partner.gupshup.io/partner/app/' . $appId . '/template/msg';

foreach ($jobData['targets'] as $index => &$target) {
    // Reload job data occasionally to check if user paused/cancelled
    if ($index % 5 === 0) {
        $checkData = json_decode(file_get_contents($jobFile), true);
        if ($checkData && ($checkData['status'] === 'paused' || $checkData['status'] === 'cancelled')) {
            echo "Job " . $checkData['status'] . ". Exiting worker.\n";
            exit;
        }
    }

    if ($target['status'] !== 'pending' && $target['status'] !== 'rate_limited') {
        continue;
    }

    // Build Payload
    $params = $target['params'] ?? [];
    $mediaUrl = $jobData['media_url'] ?? '';
    $tempType = strtoupper($jobData['template_type'] ?? 'TEXT');
    $messageData = null;

    if (!empty($mediaUrl)) {
        if ($tempType === 'IMAGE' || $tempType === 'VIDEO' || $tempType === 'DOCUMENT') {
            $typeLower = strtolower($tempType);
            $messageData = [
                'type' => $typeLower,
                $typeLower => ['link' => $mediaUrl]
            ];
        } else if (empty($params)) {
             $params[] = $mediaUrl;
        }
    }

    $postData = [
        'channel' => 'whatsapp',
        'source' => $jobData['source'],
        'sandbox' => 'false',
        'destination' => $target['phone'],
        'template' => json_encode([
            'id' => $jobData['template_id'],
            'params' => array_values($params)
        ]),
        'src.name' => $jobData['app_name']
    ];
    if ($messageData) {
        $postData['message'] = json_encode($messageData);
    }

    // Call API
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    $respStatus = strtolower($decoded['status'] ?? '');
    $msgId = $decoded['messageId'] ?? null;

    if ($httpCode === 429) {
        $target['status'] = 'rate_limited';
        $target['error'] = 'Rate Limited (429)';
        $jobData['status'] = 'paused';
        updateJobFile($jobFile, $jobData);
        echo "Rate limited. Pausing job.\n";
        exit;
    }

    $jobData['processed']++;
    if ($httpCode >= 200 && $httpCode < 300 && in_array($respStatus, ['success', 'submitted'])) {
        $target['status'] = 'sent';
        $target['message_id'] = $msgId;
        $jobData['success']++;

        // Compile the actual message text
        $compiledMsg = $jobData['template_content'] ?? ('Campaign: ' . $jobData['template_name']);
        if (!empty($params)) {
             $pVals = array_values($params);
             for ($i = 0; $i < count($pVals); $i++) {
                 $idx = $i + 1;
                 $compiledMsg = str_replace('{{' . $idx . '}}', (string)$pVals[$i], $compiledMsg);
             }
        }

        // Log to history and update Bitrix
        $respId = $target['responsible_id'] ?? ($jobData['responsible_id'] ?? null);
        handleBitrixAndLogging($target['phone'], $msgId, $jobData, $compiledMsg, $whatsappConfig, $respId);
    } else {
        $target['status'] = 'failed';
        $target['error'] = $error ?: ($decoded['message'] ?? 'HTTP ' . $httpCode);
        $jobData['failed']++;
        logDetailedError($jobId, $target, $postData, $response, $httpCode, $error, $whatsappConfig);
    }

    // Save progress after each target for maximum reliability
    updateJobFile($jobFile, $jobData);
    
    echo "Processed: " . $target['phone'] . " | Status: " . $target['status'] . "\n";

    // Rate limiting: Delay between 200ms and 500ms to avoid spam flagging
    usleep(rand(200000, 500000)); 
}

$jobData['status'] = 'completed';
updateJobFile($jobFile, $jobData);
echo "Job completed.\n";

/**
 * Logic extracted from send_campaign_batch.php
 */
function handleBitrixAndLogging($phone, $msgId, $jobData, $compiledMsg, $config, $respId = null) {
    if ($respId === 'round_robin') $respId = null; // Should be specific by now
    $lead = findLeadByPhone($phone, $config['webhook_url']);
    if ($lead) {
        if ($respId) {
            updateLeadAssignment($config['webhook_url'], $lead['id'], $respId);
        }
        logToLeadHistory($lead['id'], $jobData['template_name'], $compiledMsg, $phone, $msgId, $jobData['source'], $jobData['media_url'] ?? null, $config);
        addCampaignActivityToBitrix($config['webhook_url'], $lead['id'], $jobData['template_name'], $compiledMsg, $jobData['source'], $jobData['media_url'] ?? null, $respId);
    }
    logToGeneralHistory($jobData['template_name'], $compiledMsg, $phone, $msgId, $jobData['source'], $jobData['media_url'] ?? null, $config);
}

function findLeadByPhone($phone, $webhookUrl) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $variants = [ $cleanPhone, '+' . $cleanPhone ];
    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.duplicate.findbycomm.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['type' => 'PHONE', 'values' => $variants, 'entity_type' => ['LEAD']]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $decoded = json_decode((string)$res, true);
    curl_close($ch);
    if (!empty($decoded['result']['LEAD'])) return ['type' => 'lead', 'id' => (int)$decoded['result']['LEAD'][0]];
    return null;
}

function logToLeadHistory($leadId, $templateName, $compiledMsg, $phone, $msgId, $source, $mediaUrl, $config) {
    $dir = ($config['var_dir'] ?? (dirname(__DIR__, 2) . '/var')) . '/messages';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = $dir . '/lead_' . $leadId . '.json';
    $logEntry = [
        'id' => $msgId, 'timestamp' => date('Y-m-d H:i:s'), 'phone' => $phone,
        'message' => $compiledMsg, 'campaign_name' => $templateName, 'message_type' => 'template',
        'status' => 'sent', 'direction' => 'outbound', 'source' => $source, 'external_url' => $mediaUrl
    ];
    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
    $history[] = $logEntry;
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
}

function updateLeadAssignment($webhookUrl, $leadId, $respId) {
    if (!$respId) return;
    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.lead.update.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'id' => $leadId,
        'fields' => ['ASSIGNED_BY_ID' => $respId]
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function addCampaignActivityToBitrix($webhookUrl, $leadId, $templateName, $compiledMsg, $source, $mediaUrl, $respId = null) {
    $fields = [
        'OWNER_TYPE_ID' => 1, 'OWNER_ID' => $leadId, 'TYPE_ID' => 1, 'COMMUNICATION_TYPE_ID' => 'PHONE',
        'DIRECTION' => 2, 'SUBJECT' => "WhatsApp Campaign: $templateName",
        'DESCRIPTION' => $compiledMsg . "\n\n(Sent via Gupshup : $source)" . ($mediaUrl ? "\nMedia: $mediaUrl" : ""),
        'COMPLETED' => 'Y', 'RESPONSIBLE_ID' => $respId ?: 1
    ];
    $ch = curl_init(rtrim($webhookUrl, '/') . '/crm.activity.add.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['fields' => $fields]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function logToGeneralHistory($templateName, $compiledMsg, $phone, $msgId, $source, $mediaUrl, $config) {
    $dir = ($config['var_dir'] ?? (dirname(__DIR__, 2) . '/var')) . '/messages';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = $dir . '/lead_bulk.json';
    $logEntry = [
        'id' => $msgId, 'timestamp' => date('Y-m-d H:i:s'), 'phone' => $phone,
        'message' => $compiledMsg, 'campaign_name' => $templateName, 'message_type' => 'template',
        'status' => 'sent', 'direction' => 'outbound', 'source' => $source, 'external_url' => $mediaUrl
    ];
    $history = file_exists($filename) ? (json_decode(file_get_contents($filename), true) ?: []) : [];
    $history[] = $logEntry;
    file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
}

function logDetailedError($jobId, $target, $payload, $response, $httpCode, $curlError, $config) {
    $logDir = ($config['var_dir'] ?? (dirname(__DIR__, 2) . '/var')) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    $file = $logDir . '/campaign_send_errors.json';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'), 'job_id' => $jobId, 'phone' => $target['phone'],
        'payload' => $payload, 'http_status' => $httpCode,
        'response' => json_decode((string)$response, true) ?: $response, 'curl_error' => $curlError
    ];
    $logs = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    array_unshift($logs, $entry);
    if (count($logs) > 500) array_pop($logs);
    file_put_contents($file, json_encode($logs, JSON_PRETTY_PRINT));
}
