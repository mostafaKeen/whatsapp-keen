<?php
/**
 * get_template_performance.php
 * ----------------------------------------------------
 * Proxy for fetching detailed Gupshup Template Performance Analytics.
 * Implements Caching and Background Processing to handle 10 req/min limit.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Setup
require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
$appId    = $whatsappConfig['gupshup_app_id'] ?? '';

if (!$apiToken || !$appId) {
    echo json_encode(['status' => 'error', 'message' => 'Gupshup configuration missing.']);
    exit;
}

$templateId = $_GET['templateId'] ?? '';
$range      = (int)($_GET['range'] ?? 7); // 7, 30, 60, 90

if (!$templateId) {
    echo json_encode(['status' => 'error', 'message' => 'Template ID is required.']);
    exit;
}

// Calculate total time window
$totalDiff = $range * 86400;
$totalEnd  = time();
$totalStart = $totalEnd - $totalDiff;

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$cacheDir = $BASE_VAR_DIR . '/analytics_cache/' . $templateId;
$jobsDir = $BASE_VAR_DIR . '/analytics_jobs';

if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
if (!is_dir($jobsDir)) mkdir($jobsDir, 0777, true);

$allAnalytics = [];
$missingDates = [];

$startDateObj = new DateTime(date('Y-m-d', $totalStart));
$endDateObj = new DateTime(date('Y-m-d', $totalEnd));
$interval = new DateInterval('P1D');
$dateRange = new DatePeriod($startDateObj, $interval, $endDateObj->add($interval));

$todayAnalytics = null;
$fetchToday = false;

// Check if today is within our requested range
if ($endDateObj->format('Y-m-d') === date('Y-m-d')) {
    $fetchToday = true;
}

if ($fetchToday) {
    // Attempt to fetch fresh data for today (Synchronous)
    $todayStartTs = strtotime('today');
    $todayEndTs   = time();
    $todayUrl = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics?" . http_build_query([
        'start'         => $todayStartTs,
        'end'           => $todayEndTs,
        'granularity'   => 'DAILY',
        'metric_types'  => 'SENT,DELIVERED,READ,CLICKED',
        'template_ids'  => $templateId,
        'product_type'  => 'MARKETING_MESSAGES_LITE_API'
    ]);

    $ch = curl_init($todayUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $apiToken, 'accept: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $decoded = json_decode((string)$resp, true);
        if (!empty($decoded['template_analytics'][0])) {
            $todayAnalytics = $decoded['template_analytics'][0];
            $allAnalytics[] = $todayAnalytics;
        }
    }
}

foreach ($dateRange as $date) {
    $dateStr = $date->format('Y-m-d');
    
    // Skip today since we handled it fresh above
    if ($dateStr === date('Y-m-d')) continue;

    $cacheFile = $cacheDir . '/' . $dateStr . '.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            $allAnalytics[] = $cached;
        } else {
            $missingDates[] = $date->getTimestamp();
        }
    } else {
        $missingDates[] = $date->getTimestamp();
    }
}

$jobId = null;
if (!empty($missingDates)) {
    // Create one chunk per missing day for granular progress tracking as requested
    $chunks = [];
    foreach ($missingDates as $ts) {
        $chunks[] = [
            'start' => $ts,
            'end' => $ts + 86399,
            'status' => 'pending'
        ];
    }

    // Check for existing active job for this template to avoid redundant workers
    $activeJob = null;
    $files = scandir($jobsDir);
    foreach ($files as $file) {
        if (strpos($file, 'job_analytics_') === 0 && substr($file, -5) === '.json') {
            $jobData = json_decode(file_get_contents($jobsDir . '/' . $file), true);
            if ($jobData && $jobData['template_id'] === $templateId && in_array($jobData['status'], ['running', 'queued'])) {
                $jobId = $jobData['job_id'];
                break;
            }
        }
    }

    if (!$jobId) {
        $jobId = 'job_analytics_' . time() . '_' . substr(md5($templateId . microtime()), 0, 8);
        $jobData = [
            'job_id' => $jobId,
            'template_id' => $templateId,
            'range' => $range,
            'created_at' => date('Y-m-d H:i:s'),
            'total_chunks' => count($chunks),
            'processed_chunks' => 0,
            'status' => 'queued',
            'chunks' => $chunks
        ];
        file_put_contents($jobsDir . '/' . $jobId . '.json', json_encode($jobData, JSON_PRETTY_PRINT));

        // Start background worker
        $workerScript = __DIR__ . '/analytics_worker.php';
        
        // Log the trigger attempt
        error_log("Attempting to start analytics worker: php \"$workerScript\" \"$jobId\"");

        // OS specific background execution
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            // Windows - Use the same pattern that worked for campaigns
            $cmd = "php \"$workerScript\" \"$jobId\"";
            pclose(popen("start /B $cmd > NUL 2>&1", "r"));
        } else {
            // Linux/Unix
            $cmd = "php \"$workerScript\" \"$jobId\"";
            exec("nohup $cmd > /dev/null 2>&1 &");
        }
    }
}

echo json_encode([
    'product_type' => 'MARKETING_MESSAGES_LITE_API',
    'status' => 'success',
    'template_analytics' => $allAnalytics,
    'range' => $range,
    'job_id' => $jobId,
    'cached_days' => count($allAnalytics),
    'missing_days' => count($missingDates)
]);
