<?php
$appId = '2aa25878-fa5d-460b-81c8-b9593134422b';
$apiToken = 'sk_a8b31b077d8d40cb9beec4570b931775';
$filePath = __DIR__ . '/temp_image.jpg';

if (!file_exists($filePath)) {
    die("File not found: $filePath\n");
}

$url = "https://partner.gupshup.io/partner/app/$appId/upload/media";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: $apiToken"
]);

$cFile = new CURLFile($filePath, 'image/jpeg', 'temp_image.jpg');
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => $cFile,
    'file_type' => 'image/jpeg'
]);

echo "Uploading to $url...\n";
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 seconds timeout
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Force IPv4

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($errno) {
    echo "CURL Error ($errno): $error\n";
}
echo "Response: $response\n";
