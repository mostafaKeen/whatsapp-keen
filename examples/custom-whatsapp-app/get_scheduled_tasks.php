<?php
declare(strict_types=1);
header('Content-Type: application/json');

$whatsappConfig = require __DIR__ . '/../config.php';
$varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$scheduledFile = $varDir . '/scheduled_tasks.json';

if (!file_exists($scheduledFile)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

$tasks = json_decode(file_get_contents($scheduledFile), true) ?: [];

echo json_encode(['status' => 'success', 'data' => array_values($tasks)]);
