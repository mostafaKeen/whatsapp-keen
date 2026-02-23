<?php
declare(strict_types=1);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../vendor/autoload.php';
$whatsappConfig = require __DIR__ . '/../config.php';

$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'],
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'],
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'],
]);

session_start();
$request = Request::createFromGlobals();

// Debug: Log what we receive
error_log('=== Bitrix24 WhatsApp Index.php Debug ===');
error_log('REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('$_POST available: ' . (count($_POST) > 0 ? 'YES (' . count($_POST) . ' fields)' : 'NO'));
error_log('$request->request available: ' . (count($request->request->all()) > 0 ? 'YES (' . count($request->request->all()) . ' fields)' : 'NO'));
error_log('$_REQUEST available: ' . (count($_REQUEST) > 0 ? 'YES (' . count($_REQUEST) . ' fields)' : 'NO'));
error_log('Has SESSION B24_AUTH: ' . (isset($_SESSION['B24_AUTH']) ? 'YES' : 'NO'));

// Use $_REQUEST which includes both GET and POST
// This is more reliable for Bitrix24's form submissions
$hasAuthData = isset($_REQUEST['AUTH_ID']) && !empty($_REQUEST['AUTH_ID']);

error_log('Has AUTH_ID in $_REQUEST: ' . ($hasAuthData ? 'YES' : 'NO'));

if ($hasAuthData) {
    error_log('Received Bitrix24 authorization data via POST/REQUEST');
    
    // Extract from $_REQUEST (most reliable for Bitrix24)
    $domain = $_REQUEST['DOMAIN'] ?? null;
    $authId = $_REQUEST['AUTH_ID'] ?? null;
    $refreshId = $_REQUEST['REFRESH_ID'] ?? null;
    $authExpires = $_REQUEST['AUTH_EXPIRES'] ?? null;
    $protocol = $_REQUEST['PROTOCOL'] ?? null;
    $serverEndpoint = $_REQUEST['SERVER_ENDPOINT'] ?? null;
    
    error_log('Extracted DOMAIN: ' . ($domain ? "[$domain]" : 'EMPTY'));
    error_log('Extracted AUTH_ID length: ' . (strlen($authId ?? '') > 0 ? strlen($authId) : 'EMPTY'));
    error_log('Extracted REFRESH_ID length: ' . (strlen($refreshId ?? '') > 0 ? strlen($refreshId) : 'EMPTY'));
    error_log('Extracted AUTH_EXPIRES: ' . ($authExpires ? "[$authExpires]" : 'EMPTY'));
    
    if (!empty($domain) && !empty($authId) && !empty($refreshId) && !empty($authExpires)) {
        $_SESSION['B24_AUTH'] = [
            'DOMAIN' => trim($domain),
            'PROTOCOL' => $protocol,
            'AUTH_ID' => trim($authId),
            'REFRESH_ID' => trim($refreshId),
            'AUTH_EXPIRES' => trim($authExpires),
            'SERVER_ENDPOINT' => $serverEndpoint,
        ];
        error_log('✓ Successfully saved credentials to session');
        error_log('✓ Session DOMAIN: ' . $_SESSION['B24_AUTH']['DOMAIN']);
        error_log('✓ Session AUTH_ID: ' . substr($_SESSION['B24_AUTH']['AUTH_ID'], 0, 30) . '...');
    } else {
        error_log('✗ ERROR: Some required fields are empty!');
        error_log('  DOMAIN empty: ' . (empty($domain) ? 'YES' : 'NO'));
        error_log('  AUTH_ID empty: ' . (empty($authId) ? 'YES' : 'NO'));
        error_log('  REFRESH_ID empty: ' . (empty($refreshId) ? 'YES' : 'NO'));
        error_log('  AUTH_EXPIRES empty: ' . (empty($authExpires) ? 'YES' : 'NO'));
    }
} else {
    error_log('✗ No authorization data found in $_REQUEST');
}

$b24Service = null;
$errorMessage = null;

// Check if we have valid session data
$hasValidSession = isset($_SESSION['B24_AUTH']) && !empty($_SESSION['B24_AUTH']['DOMAIN']) && !empty($_SESSION['B24_AUTH']['AUTH_ID']);

error_log('Has valid session B24_AUTH: ' . ($hasValidSession ? 'YES' : 'NO'));

if ($hasValidSession) {
    try {
        error_log('Restoring from session - DOMAIN: ' . $_SESSION['B24_AUTH']['DOMAIN']);
        
        // Build credentials directly from session
        $authToken = new AuthToken(
            $_SESSION['B24_AUTH']['AUTH_ID'],
            $_SESSION['B24_AUTH']['REFRESH_ID'],
            (int)$_SESSION['B24_AUTH']['AUTH_EXPIRES']
        );
        
        $domain = $_SESSION['B24_AUTH']['DOMAIN'];
        if (strpos($domain, 'https://') !== 0 && strpos($domain, 'http://') !== 0) {
            $domain = 'https://' . $domain;
        }
        
        $credentials = new Credentials(
            $domain,
            $authToken,
            $appProfile
        );
        
        error_log('Creating service builder with domain: ' . $domain);
        $coreBuilder = new CoreBuilder($credentials);
        $b24Service = new \Bitrix24\SDK\Services\ServiceBuilder($coreBuilder->build());
        error_log('✓ Service builder created successfully');
    } catch (\Exception $e) {
        error_log('✗ Error creating service builder: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        $errorMessage = 'Failed to initialize: ' . $e->getMessage();
    }
} else {
    error_log('Cannot create service: No valid session data');
    if (isset($_SESSION['B24_AUTH'])) {
        error_log('Session B24_AUTH exists but incomplete:');
        error_log('  DOMAIN: ' . ($_SESSION['B24_AUTH']['DOMAIN'] ?? 'EMPTY'));
        error_log('  AUTH_ID: ' . ($_SESSION['B24_AUTH']['AUTH_ID'] ?? 'EMPTY'));
        error_log('  REFRESH_ID: ' . ($_SESSION['B24_AUTH']['REFRESH_ID'] ?? 'EMPTY'));
    }
    $errorMessage = 'Session data not saved. Please try again by opening the app from Bitrix24 menu.';
}

$connectorId = 'custom_whatsapp';
$isRegistered = false;

if ($b24Service !== null) {
    // Check if connector is registered
    $connectors = $b24Service->getIMOpenLinesScope()->connector()->list()->getConnectors();
    $isRegistered = isset($connectors[$connectorId]);

    if (isset($_POST['action']) && $_POST['action'] === 'register') {
        $handlerUrl = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/handler.php';
        
        $b24Service->getIMOpenLinesScope()->connector()->register([
            'ID' => $connectorId,
            'NAME' => 'WhatsApp Custom',
            'ICON' => [
                'DATA_IMAGE' => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iIzI1RDM2NiIgZD0iTTEyIDBDNS4zNzMgMCAwIDUuMzczIDAgMTJjMCAyLjEyNC41NDYgNC4xMTkgMS41IDEuODU3TDAgMjRsNy4zOTUtMS4zODRDOC43MDEgMjMuNDU0IDEwLjI5OSAyNCAxMiAyNGM2LjYyNyAwIDEyLTUuMzczIDEyLTEyUzE4LjYyNyAwIDEyIDB6bTAtLjY2N2ExMi42NjcgMTIuNjY3IDAgMCAxIDEyLjY2NyAxMi42NjdBMTIuNjY3IDEyLjY2NyAwIDAgMSAxMiAyNC42NjcgMTIuNjY3IDEyLjY2NyAwIDAgMSAtIDYuNjg0IDEuNzQ5TDAgMjRsMS43NDktNi42ODRBMTIuNjY3IDEyLjY2NyAwIDAgMSAtLjY2NyAxMnoiLz48L3N2Zz4=', 
            ],
            'URL_IM' => $handlerUrl,
        ]);
        $isRegistered = true;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>WhatsApp Connector Settings</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body class="p-4">
    <div class="container">
        <h1>WhatsApp Integration</h1>
        <hr>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <h4>Authorization Required</h4>
                <?= $errorMessage ?>
            </div>
            <div class="card mt-4">
                <div class="card-header bg-light">How to authorize:</div>
                <div class="card-body">
                    <ol>
                        <li>Close this slider window.</li>
                        <li><b>Refresh your main Bitrix24 browser tab.</b></li>
                        <li>Find <b>"wosol-keen local"</b> in your left-hand menu and click it.</li>
                    </ol>
                </div>
            </div>
            
            <div class="mt-4 p-3 border rounded bg-light">
                <small class="text-muted">Debug Info for Developer:</small><br>
                <small>Request Method: <b><?= $_SERVER['REQUEST_METHOD'] ?></b></small><br>
                <small>Session ID: <b><?= session_id() ?></b></small><br>
                <small>Session B24_AUTH exists: <b><?= isset($_SESSION['B24_AUTH']) ? 'YES' : 'NO' ?></b></small>
                <?php if (isset($_SESSION['B24_AUTH'])): ?>
                    <small>Session DOMAIN: <b><?= $_SESSION['B24_AUTH']['DOMAIN'] ?? 'NOT SET' ?></b></small><br>
                    <small>Session AUTH_ID: <b><?= substr($_SESSION['B24_AUTH']['AUTH_ID'] ?? '', 0, 20) . '...' ?></b></small><br>
                <?php endif; ?>
                <small>Data received in request:</small>
                <pre style="font-size: 10px;"><?= print_r($_REQUEST, true) ?></pre>
            </div>
        <?php elseif (!$isRegistered): ?>
            <div class="alert alert-warning">
                Connector is not registered in Bitrix24.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="register">
                <button type="submit" class="btn btn-primary">Register Connector</button>
            </form>
        <?php else: ?>
            <div class="alert alert-success">
                WhatsApp Connector is Active!
            </div>
            <p>Handler URL: <code><?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/handler.php' ?></code></p>
        <?php endif; ?>
    </div>
</body>
</html>