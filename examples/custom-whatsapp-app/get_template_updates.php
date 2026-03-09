<?php
declare(strict_types=1);

$whatsappConfig = require __DIR__ . '/../config.php';
header('Content-Type: application/json');

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$file = $BASE_VAR_DIR . '/template_updates.json';

if (!file_exists($file)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

$data = json_decode(file_get_contents($file), true) ?: [];

echo json_encode([
    'status' => 'success',
    'data' => $data
]);
