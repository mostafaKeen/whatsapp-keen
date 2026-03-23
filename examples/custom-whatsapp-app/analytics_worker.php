<?php
declare(strict_types=1);

/**
 * Gupshup Analytics Background Worker
 * Fetches daily analytics in chunks and stores them in cache.
 * Usage: php analytics_worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if ($argc < 2) {
    die("Usage: php analytics_worker.php <job_id>\n");
}

$jobId = $argv[1];
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Config file not found.\n");
}
$whatsappConfig = require $configPath;

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobFile = $BASE_VAR_DIR . '/analytics_jobs/' . $jobId . '.json';
$cacheDir = $BASE_VAR_DIR . '/analytics_cache';

if (!file_exists($jobFile)) {
    die("Job file not found: $jobFile\n");
}

$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$appId = $whatsappConfig['gupshup_app_id'] ?? '';

set_time_limit(0);

echo "Starting analytics job: $jobId\n";

function updateJobFile(string $jobFile, array $jobData): void {
    file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT));
}

$jobData = json_decode(file_get_contents($jobFile), true);
if (!$jobData) {
    die("Invalid job data.\n");
}

$jobData['status'] = 'running';
$jobData['started_at'] = date('Y-m-d H:i:s');
updateJobFile($jobFile, $jobData);

$templateId = $jobData['template_id'];

$url = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics";

foreach ($jobData['chunks'] as $index => &$chunk) {
    if ($chunk['status'] === 'completed') continue;

    // Check for pause/cancel
    $checkData = json_decode(file_get_contents($jobFile), true);
    if ($checkData && ($checkData['status'] === 'paused' || $checkData['status'] === 'cancelled')) {
        echo "Job " . $checkData['status'] . ". Exiting.\n";
        exit;
    }

    // Process chunk day by day to be absolutely safe with 429s as requested
    $chunkStart = (int)$chunk['start'];
    $chunkEnd = (int)$chunk['end'];
    
    $currentDayTs = $chunkStart;
    while ($currentDayTs < $chunkEnd) {
        $dayStart = $currentDayTs;
        $dayEnd = min($dayStart + 86399, $chunkEnd);
        $dateStr = date('Y-m-d', $dayStart);
        
        echo "Fetching date $dateStr (" . ($index+1) . "/" . count($jobData['chunks']) . ")\n";
        
        $queryParams = [
            'start'         => $dayStart,
            'end'           => $dayEnd,
            'granularity'   => 'DAILY',
            'metric_types'  => 'SENT,DELIVERED,READ,CLICKED',
            'template_ids'  => $templateId,
            'product_type'  => 'MARKETING_MESSAGES_LITE_API'
        ];

        $ch = curl_init($url . '?' . http_build_query($queryParams));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $apiToken, 'accept: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 429) {
            echo "429 Too Many Requests on $dateStr. Waiting 60 seconds...\n";
            sleep(60);
            continue; // Retry this day
        }

        if (!$error && $httpCode === 200) {
            $decoded = json_decode((string)$response, true);
            if (isset($decoded['template_analytics'][0])) {
                $dayData = $decoded['template_analytics'][0];
                $templateCacheDir = $cacheDir . '/' . $templateId;
                if (!is_dir($templateCacheDir)) mkdir($templateCacheDir, 0777, true);
                
                // Only cache if it's NOT today's date
                if ($dateStr !== date('Y-m-d')) {
                    $cacheFile = $templateCacheDir . '/' . $dateStr . '.json';
                    file_put_contents($cacheFile, json_encode($dayData));
                }
            }
            $currentDayTs += 86400; // Move to next day
            echo "Done. Waiting 7 seconds...\n";
            sleep(7);
        } else {
            echo "Error on $dateStr: " . ($error ?: "HTTP $httpCode") . "\n";
            // If it's a non-retriable error, we mark chunk as error but continue to next days
            $chunk['status'] = 'error';
            $currentDayTs += 86400; // Skip this day and move to next
            continue; 
        }
    }

    if ($chunk['status'] !== 'error') {
        $chunk['status'] = 'completed';
        $jobData['processed_chunks']++;
    }
    updateJobFile($jobFile, $jobData);
}

// Final Job Status Update
$allDone = true;
foreach ($jobData['chunks'] as $c) {
    if ($c['status'] !== 'completed') $allDone = false;
}

if ($allDone) {
    $jobData['status'] = 'completed';
    $jobData['completed_at'] = date('Y-m-d H:i:s');
} else {
    $jobData['status'] = 'partial';
}

updateJobFile($jobFile, $jobData);
echo "Job finished with status: " . $jobData['status'] . "\n";
