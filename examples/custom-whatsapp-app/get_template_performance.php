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

// Support clearing cache
$clearCache = isset($_GET['clearCache']) && $_GET['clearCache'] == '1';
if ($clearCache) {
    // Delete cache folder
    $templateCacheDir = $cacheDir . '/' . $templateId;
    if (is_dir($templateCacheDir)) {
        $files = glob($templateCacheDir . '/*');
        foreach ($files as $file) if (is_file($file)) unlink($file);
        rmdir($templateCacheDir);
    }
    // Cancel/Delete active jobs for this template to start fresh
    $files = scandir($jobsDir);
    foreach ($files as $file) {
        if (strpos($file, 'job_analytics_') === 0 && substr($file, -5) === '.json') {
            $jd = json_decode(file_get_contents($jobsDir . '/' . $file), true);
            if ($jd && $jd['template_id'] === $templateId && in_array($jd['status'], ['running', 'queued'])) {
                $jd['status'] = 'cancelled';
                file_put_contents($jobsDir . '/' . $file, json_encode($jd));
            }
        }
    }
    // Re-run missing dates check as cache is now gone
    $allAnalytics = [];
    $missingDates = [];
    for ($i = 0; $i < $range; $i++) {
        $ts = strtotime("-" . $i . " days", $todayStart);
        $dateStr = date('Y-m-d', $ts);
        if ($dateStr === date('Y-m-d')) continue;
        $missingDates[] = $ts;
    }
}

$jobId = null;
if (!empty($missingDates)) {
    // Check for existing active job for this template
    $existingJobFile = null;
    $files = scandir($jobsDir);
    foreach ($files as $file) {
        if (strpos($file, 'job_analytics_') === 0 && substr($file, -5) === '.json') {
            $existingJobData = json_decode(file_get_contents($jobsDir . '/' . $file), true);
            if ($existingJobData && 
                $existingJobData['template_id'] === $templateId && 
                in_array($existingJobData['status'], ['running', 'queued'])) {
                $jobId = $existingJobData['job_id'];
                $existingJobFile = $jobsDir . '/' . $file;
                break;
            }
        }
    }

    if ($jobId && $existingJobFile) {
        // ENHANCEMENT: Merge new missing dates into existing job
        $jobData = json_decode(file_get_contents($existingJobFile), true);
        $existingStarts = array_column($jobData['chunks'], 'start');
        $added = 0;
        foreach ($missingDates as $ts) {
            if (!in_array($ts, $existingStarts)) {
                $jobData['chunks'][] = [
                    'start' => $ts,
                    'end' => $ts + 86399,
                    'status' => 'pending'
                ];
                $added++;
            }
        }
        if ($added > 0) {
            $jobData['total_chunks'] = count($jobData['chunks']);
            $jobData['range'] = max((int)$jobData['range'], $range);
            $jobData['total_range_days'] = max((int)$jobData['total_range_days'], $range);
            file_put_contents($existingJobFile, json_encode($jobData, JSON_PRETTY_PRINT));
        }
    } else {
        // Create one chunk per missing day for granular progress tracking
        $chunks = [];
        foreach ($missingDates as $ts) {
            $chunks[] = [
                'start' => $ts,
                'end' => $ts + 86399,
                'status' => 'pending'
            ];
        }

        $jobId = 'job_analytics_' . time() . '_' . substr(md5($templateId . microtime()), 0, 8);
        $jobData = [
            'job_id' => $jobId,
            'template_id' => $templateId,
            'range' => $range,
            'total_range_days' => $range,
            'cached_days' => count($allAnalytics),
            'created_at' => date('Y-m-d H:i:s'),
            'total_chunks' => count($chunks),
            'processed_chunks' => 0,
            'status' => 'queued',
            'chunks' => $chunks
        ];
        file_put_contents($jobsDir . '/' . $jobId . '.json', json_encode($jobData, JSON_PRETTY_PRINT));

        // Start background worker
        $workerScript = __DIR__ . '/analytics_worker.php';
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $cmd = "php \"$workerScript\" \"$jobId\"";
            pclose(popen("start /B $cmd > NUL 2>&1", "r"));
        } else {
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
