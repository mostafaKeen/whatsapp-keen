<?php
declare(strict_types=1);

/**
 * get_leads.php
 * Fetches ALL leads from Bitrix24 for use in the campaign selection UI.
 */

ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(300); // Allow more time for large datasets

$whatsappConfig = require __DIR__ . '/../config.php';
$webhookUrl = rtrim($whatsappConfig['webhook_url'], '/');

$segmentField = $_GET['segmentField'] ?? '';
$allLeads = [];
$next = 0;

function bitrix24Call($url, $params) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

$select = ['ID', 'NAME', 'LAST_NAME', 'PHONE'];
if ($segmentField) {
    $select[] = $segmentField;
}

do {
    $params = [
        'select' => $select,
        'order'  => ['ID' => 'DESC'],
        'start'  => $next
    ];
    
    $data = bitrix24Call($webhookUrl . '/crm.lead.list.json', $params);
    
    if (isset($data['result'])) {
        foreach ($data['result'] as $lead) {
            if (!empty($lead['PHONE'])) {
                $allLeads[] = $lead;
            }
        }
    }
    
    $next = $data['next'] ?? null;
    
    // Safety break to prevent infinite loops if something goes wrong
    if (count($allLeads) > 10000) break; 
    
} while ($next);

header('Content-Type: application/json');
echo json_encode(['result' => $allLeads]);
