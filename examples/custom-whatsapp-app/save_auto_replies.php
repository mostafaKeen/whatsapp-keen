<?php
declare(strict_types=1);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$config = require __DIR__ . '/../config.php';
$varDir = $config['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$autoReplyFile = $varDir . '/auto_replies.json';

// Get raw POST body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Sanitize and structure the data
$settings = [
    'enabled' => (bool)($data['enabled'] ?? false),
    'rules' => []
];

foreach ($data['rules'] ?? [] as $rule) {
    if (!empty($rule['keyword']) && !empty($rule['reply'])) {
        $settings['rules'][] = [
            'keyword' => trim((string)$rule['keyword']),
            'reply' => trim((string)$rule['reply']),
            'match_type' => 'contains' // Default for now
        ];
    }
}

if (file_put_contents($autoReplyFile, json_encode($settings, JSON_PRETTY_PRINT))) {
    echo json_encode(['status' => 'success', 'message' => 'Auto-reply settings saved.']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save settings. Check permissions on ' . $autoReplyFile]);
}
