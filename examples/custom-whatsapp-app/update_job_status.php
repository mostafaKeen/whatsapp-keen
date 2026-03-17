<?php
declare(strict_types=1);

$whatsappConfig = require __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$jobId = $_POST['job_id'] ?? '';
$newStatus = $_POST['status'] ?? ''; // e.g., 'paused', 'queued', 'cancelled'

if (empty($jobId) || empty($newStatus)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'job_id and status are required']);
    exit;
}

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobFile = $BASE_VAR_DIR . '/jobs/' . $jobId . '.json';

if (!file_exists($jobFile)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Job not found']);
    exit;
}

// Atomic update
$fp = fopen($jobFile, 'r+');
if (!flock($fp, LOCK_EX)) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Could not lock job file']);
    exit;
}

$jobData = json_decode(stream_get_contents($fp), true);
if ($jobData) {
    $jobData['status'] = $newStatus;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($jobData, JSON_PRETTY_PRINT));
}
flock($fp, LOCK_UN);
fclose($fp);

// If resuming, trigger the worker again
if ($newStatus === 'queued') {
    $workerPath = __DIR__ . '/worker.php';
    $cmd = "php \"$workerPath\" \"$jobId\"";
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        pclose(popen("start /B $cmd > NUL 2>&1", "r"));
    } else {
        exec("$cmd > /dev/null 2>&1 &");
    }
}

echo json_encode(['status' => 'success', 'new_status' => $newStatus]);
