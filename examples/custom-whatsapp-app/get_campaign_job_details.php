<?php
declare(strict_types=1);

$whatsappConfig = require __DIR__ . '/../config.php';
header('Content-Type: application/json');

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$job_id = $_GET['job_id'] ?? '';

if (!$job_id || !preg_match('/^[a-zA-Z0-9_]+$/', $job_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid job ID']);
    exit;
}

$file = $BASE_VAR_DIR . '/jobs/' . $job_id . '.json';

if (!file_exists($file)) {
    echo json_encode(['status' => 'error', 'message' => 'Job file not found']);
    exit;
}

$data = json_decode(file_get_contents($file), true) ?: [];

echo json_encode([
    'status' => 'success',
    'data' => $data
]);
