<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$jobId = $_GET['job_id'] ?? '';
$whatsappConfig = require __DIR__ . '/../config.php';
$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobFile = $BASE_VAR_DIR . '/jobs/' . basename($jobId) . '.json';

if (!file_exists($jobFile)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Job not found']);
    exit;
}

header('Content-Type: application/json');
echo file_get_contents($jobFile);
