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
error_log('Has POST AUTH_ID: ' . ($request->request->has('AUTH_ID') ? 'YES' : 'NO'));
error_log('Has SESSION B24_AUTH: ' . (isset($_SESSION['B24_AUTH']) ? 'YES' : 'NO'));

// Handle incoming tokens from Bitrix24 (Initial load) - Bitrix24 sends via POST
if ($request->request->has('AUTH_ID')) {
    error_log('Saving Bitrix24 credentials to session');
    $_SESSION['B24_AUTH'] = [
        'DOMAIN' => $request->request->get('DOMAIN'),
        'PROTOCOL' => $request->request->get('PROTOCOL'),
        'AUTH_ID' => $request->request->get('AUTH_ID'),
        'REFRESH_ID' => $request->request->get('REFRESH_ID'),
        'AUTH_EXPIRES' => $request->request->get('AUTH_EXPIRES'),
        'SERVER_ENDPOINT' => $request->request->get('SERVER_ENDPOINT'),
    ];
    error_log('Saved DOMAIN to session: ' . $_SESSION['B24_AUTH']['DOMAIN']);
}

$b24Service = null;
$errorMessage = null;

if (isset($_SESSION['B24_AUTH'])) {
    try {
        error_log('Session B24_AUTH keys: ' . implode(', ', array_keys($_SESSION['B24_AUTH'])));
        error_log('Session DOMAIN value: ' . ($_SESSION['B24_AUTH']['DOMAIN'] ?? 'EMPTY'));
        
        if (empty($_SESSION['B24_AUTH']['DOMAIN'])) {
            throw new \Exception('DOMAIN is empty in session');
        }
        
        // Build credentials directly from session, bypassing ServiceBuilderFactory's URL parsing
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
        // Create builder and initialize with our credentials
        $coreBuilder = new CoreBuilder($credentials);
        $b24Service = new \Bitrix24\SDK\Services\ServiceBuilder($coreBuilder->build());
        error_log('Service builder created successfully');
    } catch (\Exception $e) {
        error_log('Error creating service builder: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        $errorMessage = 'Session error: ' . $e->getMessage();
    }
} else {
    error_log('No session B24_AUTH found');
    $errorMessage = 'No authorization found. Please open this app from the Bitrix24 menu.';
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
                <small>Data received:</small>
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