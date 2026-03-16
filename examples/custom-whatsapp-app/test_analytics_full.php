<?php
error_reporting(0);
$config = require __DIR__ . '/../config.php';
$appId = $config['gupshup_app_id'];
$token = $config['gupshup_api_token'];

$output = "";
$output .= "=== Step 1: Fetching Templates ===\n";
$ch = curl_init("https://partner.gupshup.io/partner/app/{$appId}/templates?pageSize=100");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token, 'accept: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) { file_put_contents('test_result.txt', "CURL Error: $err\n"); exit; }

$data = json_decode($resp, true);
if (!$data || !isset($data['templates'])) {
    file_put_contents('test_result.txt', "Bad response. First 200 chars: " . substr($resp, 0, 200) . "\n");
    exit;
}

$output .= "Found " . count($data['templates']) . " templates\n\n";
foreach ($data['templates'] as $t) {
    $output .= sprintf("%-40s %s  [%s]\n", $t['elementName'], $t['id'], $t['status']);
}

$targetId = null;
foreach ($data['templates'] as $t) {
    if ($t['elementName'] === 'test12355') { $targetId = $t['id']; break; }
}

if (!$targetId) { file_put_contents('test_result.txt', $output . "\nTemplate 'test12355' not found!\n"); exit; }

$output .= "\n=== Step 2: Performance API for test12355 (ID: $targetId) ===\n";
$end = time();
$start = $end - (7 * 86400);
$perfUrl = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics?" . http_build_query([
    'start' => $start, 'end' => $end, 'granularity' => 'DAILY',
    'metric_types' => 'SENT,DELIVERED,READ,CLICKED', 'template_ids' => $targetId,
    'limit' => 30, 'product_type' => 'MARKETING_MESSAGES_LITE_API'
]);

$ch = curl_init($perfUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token, 'accept: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$output .= "HTTP Code: $code2\n";
$output .= "Response: " . substr($resp2, 0, 500) . "\n";

$output .= "\n=== Step 3: Compare API ===\n";
$compareUrl = "https://partner.gupshup.io/partner/app/{$appId}/template/analytics/{$targetId}/compare?" . http_build_query([
    'start' => $start, 'end' => $end, 'templateList' => ''
]);

$ch = curl_init($compareUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token, 'token: ' . $token, 'accept: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp3 = curl_exec($ch);
$code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$output .= "HTTP Code: $code3\n";
$output .= "Response: " . substr($resp3, 0, 500) . "\n";

file_put_contents('test_result.txt', $output);
echo "Done. Results in test_result.txt\n";
