<?php
declare(strict_types=1);

$whatsappConfig = require __DIR__ . '/../config.php';

header('Content-Type: application/json');

$BASE_VAR_DIR = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
$jobDir = $BASE_VAR_DIR . '/jobs';

if (!is_dir($jobDir)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

$files = glob($jobDir . '/*.json');
$campaigns = [];

foreach ($files as $file) {
    if (!is_readable($file)) continue;
    $content = file_get_contents($file);
    if (!$content) continue;
    
    $data = json_decode($content, true);
    if (!$data) continue;

    // Filter out target list to save bandwidth, just keep macro stats
    unset($data['targets']);
    
    // Add file modification time if created_at is missing
    if (!isset($data['created_at'])) {
        $data['created_at'] = date('Y-m-d H:i:s', filemtime($file));
    }
    
    // Fill missing fields gracefully
    $data['delivered'] = $data['delivered'] ?? 0;
    $data['read'] = $data['read'] ?? 0;
    $data['webhook_failed'] = $data['webhook_failed'] ?? 0;
    
    $campaigns[] = $data;
}

// Sort by created_at descending
usort($campaigns, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

echo json_encode([
    'status' => 'success',
    'data' => $campaigns
]);
