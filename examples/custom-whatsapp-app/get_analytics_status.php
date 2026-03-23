<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$jobId = $_GET['job_id'] ?? '';
if (!$jobId) {
    echo json_encode(['status' => 'error', 'message' => 'Job ID is required.']);
    exit;
}

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobFile = $BASE_VAR_DIR . '/analytics_jobs/' . $jobId . '.json';

if (!file_exists($jobFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Job not found.']);
    exit;
}

$jobData = json_decode(file_get_contents($jobFile), true);

echo json_encode([
    'status' => 'success',
    'data' => $jobData
]);
