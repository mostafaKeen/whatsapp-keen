<?php
$appId = '2aa25878-fa5d-460b-81c8-b9593134422b';
$apiToken = 'sk_a8b31b077d8d40cb9beec4570b931775';

$url = "https://partner.gupshup.io/partner/app/$appId/templates";

// The handle returned from previous upload test
$mediaHandle = '4::aW1hZ2UvanBlZw==:ARbp3rM3ckF1FmVnRxVacE4b0xV5j1wcpAbBIshKKGerRZK5as7tZkbGXJnBhQvn-4SemquBAGv6obo9QZrRDXrt5I2jy1d5lvBC-aQGUJsEVQ:e:1774950675:340384197887925:61576496337672:ARbDlR-vphcZNGKJyn0';

$postData = [
    'elementName' => 'ticket_check_url_4245343_' . rand(100, 999),
    'languageCode' => 'en_US',
    'content' => 'Your verification code is {{1}}.',
    'footer' => 'This is the footer',
    'category' => 'MARKETING',
    'templateType' => 'IMAGE',
    'vertical' => 'Ticket update',
    'appId' => $appId,
    'example' => 'Your verification code is 213.',
    'exampleMedia' => $mediaHandle,
    'enableSample' => 'true',
    'allowTemplateCategoryChange' => 'false'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
// Gupshup documentation says: application/x-www-form-urlencoded
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'Authorization: ' . $apiToken,
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_VERBOSE, true);

echo "Creating template...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "CURL Error: $error\n";
}
echo "Response: $response\n";
