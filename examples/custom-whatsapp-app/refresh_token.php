<?php
/**
 * Refresh Bitrix24 OAuth Token
 */

$settings = json_decode(file_get_contents(__DIR__ . '/settings.json'), true);

$params = [
    'grant_type' => 'refresh_token',
    'client_id' => $settings['C_REST_CLIENT_ID'],
    'client_secret' => $settings['C_REST_CLIENT_SECRET'],
    'refresh_token' => $settings['refresh_token']
];

$url = 'https://oauth.bitrix.info/oauth/token/';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
$response = curl_exec($ch);
$data = json_decode($response, true);

if (isset($data['access_token'])) {
    $settings['access_token'] = $data['access_token'];
    $settings['refresh_token'] = $data['refresh_token'];
    $settings['expires'] = time() + $data['expires_in'];
    file_put_contents(__DIR__ . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT));
    echo "TOKEN REFRESHED SUCCESS!\n";
} else {
    echo "REFRESH FAILED: " . json_encode($data) . "\n";
}
