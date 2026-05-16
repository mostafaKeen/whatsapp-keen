<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Dubai');

/**
 * WhatsApp Scheduler Engine
 * Run this script via cron every minute:
 * * * * * * php scheduler_worker.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$whatsappConfig = require __DIR__ . '/../config.php';
$varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$scheduledFile = $varDir . '/scheduled_tasks.json';
$jobDir = $varDir . '/jobs';

if (!file_exists($scheduledFile)) {
    echo "No scheduled tasks file found.\n";
    exit;
}

$tasks = json_decode(file_get_contents($scheduledFile), true) ?: [];
$now = new DateTime();
$currentTime = $now->format('H:i');
$currentDate = $now->format('Y-m-d');
$updated = false;

echo "Current Time: $currentTime | Current Date: $currentDate\n";

foreach ($tasks as $id => &$task) {
    if ($task['status'] !== 'active') continue;
    
    // Check if it's time to run
    // Using >= in case cron was missed by a minute, but checking last_run prevents multiple runs same day
    if ($currentTime >= $task['time'] && ($task['last_run'] ?? '') !== $currentDate) {
        
        echo "Triggering task: {$task['name']} ($id)\n";
        
        // Prepare Job Data (compatible with worker.php)
        $jobId = 'sched_' . time() . '_' . bin2hex(random_bytes(2));
        $jobData = [
            'job_id' => $jobId,
            'template_id' => $task['template_id'],
            'template_name' => $task['template_name'],
            'source' => $whatsappConfig['gupshup_source'] ?? '',
            'app_name' => $whatsappConfig['gupshup_app_name'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'total' => count($task['numbers']),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'status' => 'queued',
            'template_type' => $task['template_type'] ?? 'TEXT',
            'language' => $task['language'] ?? 'en_US',
            'template_content' => $task['template_content'] ?? '',
            'media_url' => $task['media_url'] ?? '',
            'responsible_id' => $task['responsible_id'] ?? '',
            'targets' => []
        ];
        
        foreach ($task['numbers'] as $num) {
            $jobData['targets'][] = [
                'phone' => preg_replace('/[^0-9]/', '', (string)$num),
                'status' => 'pending',
                'error' => null,
                'params' => $task['params'] ?? [], 
                'responsible_id' => $task['responsible_id'] ?? ''
            ];
        }
        
        if (!is_dir($jobDir)) mkdir($jobDir, 0777, true);
        file_put_contents($jobDir . '/' . $jobId . '.json', json_encode($jobData, JSON_PRETTY_PRINT));
        
        // Update task run date
        $task['last_run'] = $currentDate;
        $updated = true;
        
        // Spawn worker
        $workerPath = __DIR__ . '/worker.php';
        $cmd = "php \"$workerPath\" \"$jobId\"";
        echo "Executing: $cmd\n";
        
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            pclose(popen("start /B $cmd > NUL 2>&1", "r"));
        } else {
            exec("$cmd > /dev/null 2>&1 &");
        }
    }
}

if ($updated) {
    file_put_contents($scheduledFile, json_encode($tasks, JSON_PRETTY_PRINT));
    echo "Tasks updated.\n";
} else {
    echo "Nothing to run.\n";
}
