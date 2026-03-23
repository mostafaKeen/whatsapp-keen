<?php
declare(strict_types=1);
$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');
function bitrix24Call(string $webhookUrl, string $method, array $params = []): array {
    $url = $webhookUrl . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}
$fieldsRes = bitrix24Call($webhookUrl, 'crm.lead.fields');
$fields = $fieldsRes['result'] ?? [];
// Output first 10 fields and search for a custom one starting with UF_
$custom = [];
foreach($fields as $k => $v) {
    if (strpos($k, 'UF_') === 0) {
        $custom[$k] = $v;
        if (count($custom) > 5) break;
    }
}
echo json_encode($custom, JSON_PRETTY_PRINT);
