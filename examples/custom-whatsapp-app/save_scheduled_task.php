<?php
declare(strict_types=1);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$whatsappConfig = require __DIR__ . '/../config.php';
$varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$scheduledFile = $varDir . '/scheduled_tasks.json';

$action = $_POST['action'] ?? 'save';
$tasks = file_exists($scheduledFile) ? (json_decode(file_get_contents($scheduledFile), true) ?: []) : [];

if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (isset($tasks[$id])) {
        unset($tasks[$id]);
        file_put_contents($scheduledFile, json_encode($tasks, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'message' => 'Task deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Task not found']);
    }
    exit;
}

// Save or Update
$id = $_POST['id'] ?? ('task_' . time() . '_' . bin2hex(random_bytes(4)));
$name = $_POST['name'] ?? 'Untitled Task';
$templateId = $_POST['templateId'] ?? '';
$templateName = $_POST['templateName'] ?? '';
$templateType = $_POST['templateType'] ?? 'TEXT';
$templateLang = $_POST['language'] ?? 'en_US';
$templateContent = $_POST['templateContent'] ?? '';
$mediaUrl = $_POST['mediaUrl'] ?? '';
$numbersRaw = $_POST['numbers'] ?? '';
$time = $_POST['time'] ?? '09:00';
$status = $_POST['status'] ?? 'active';
$responsibleId = $_POST['responsibleId'] ?? '';

if (empty($templateId) || empty($numbersRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Template and Numbers are required']);
    exit;
}

$numbers = array_values(array_unique(array_filter(array_map('trim', explode("\n", $numbersRaw)))));

$tasks[$id] = [
    'id' => $id,
    'name' => $name,
    'template_id' => $templateId,
    'template_name' => $templateName,
    'template_type' => $templateType,
    'language' => $templateLang,
    'template_content' => $templateContent,
    'media_url' => $mediaUrl,
    'numbers' => $numbers,
    'time' => $time,
    'status' => $status,
    'responsible_id' => $responsibleId,
    'last_run' => $tasks[$id]['last_run'] ?? null,
    'created_at' => $tasks[$id]['created_at'] ?? date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
];

file_put_contents($scheduledFile, json_encode($tasks, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'success', 'message' => 'Task saved', 'task' => $tasks[$id]]);
