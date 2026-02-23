<?php
declare(strict_types=1);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\NullLogger;

// Enable error output for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Buffer output to prevent blank pages on errors
ob_start();

// Initialize variables that will be used in HTML section
$b24Service = null;
$errorMessage = null;
$isRegistered = false;
$connectorId = 'whatsapp_direct';
$allConnectors = [];

try {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $whatsappConfig = require __DIR__ . '/../config.php';

$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'],
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'],
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'],
]);

// Use custom session manager instead of PHP sessions for reliability on shared hosting
require_once __DIR__ . '/SessionManager.php';
$sessionManager = new SessionManager();

// Manual Cache Clear
if (isset($_GET['clear_cache'])) {
    $sessionManager->saveRegistration($connectorId, false);
    header('Location: index.php');
    exit;
}

// Debug logs
error_log('=== Bitrix24 WhatsApp Direct Index.php Debug ===');

// Use $_REQUEST which includes both GET and POST
$hasAuthData = isset($_REQUEST['AUTH_ID']) && !empty($_REQUEST['AUTH_ID']);

if ($hasAuthData) {
    // Extract from $_REQUEST (most reliable for Bitrix24)
    $domain = $_REQUEST['DOMAIN'] ?? null;
    $authId = $_REQUEST['AUTH_ID'] ?? null;
    $refreshId = $_REQUEST['REFRESH_ID'] ?? null;
    $authExpires = $_REQUEST['AUTH_EXPIRES'] ?? null;
    $protocol = $_REQUEST['PROTOCOL'] ?? null;
    $serverEndpoint = $_REQUEST['SERVER_ENDPOINT'] ?? null;
    
    if (!empty($domain) && !empty($authId) && !empty($refreshId) && !empty($authExpires)) {
        $authDataToSave = [
            'DOMAIN' => trim($domain),
            'PROTOCOL' => $protocol,
            'AUTH_ID' => trim($authId),
            'REFRESH_ID' => trim($refreshId),
            'AUTH_EXPIRES' => trim($authExpires),
            'SERVER_ENDPOINT' => $serverEndpoint,
        ];
        $sessionManager->saveAuth($authDataToSave);
    }
}

// Check stored auth
$storedAuth = $sessionManager->getAuth();
$hasValidAuth = $storedAuth !== null && !empty($storedAuth['DOMAIN']) && !empty($storedAuth['AUTH_ID']);

if ($hasValidAuth) {
    try {
        $authToken = new AuthToken(
            $storedAuth['AUTH_ID'],
            $storedAuth['REFRESH_ID'],
            (int)$storedAuth['AUTH_EXPIRES']
        );
        $domain = $storedAuth['DOMAIN'];
        if (strpos($domain, 'https://') !== 0 && strpos($domain, 'http://') !== 0) {
            $domain = 'https://' . $domain;
        }
        $endpoints = new Endpoints($domain, $storedAuth['SERVER_ENDPOINT'] ?? 'https://oauth.bitrix.info/rest/');
        $credentials = new Credentials(null, $authToken, $appProfile, $endpoints);
        
        $logger = new NullLogger();
        $core = (new CoreBuilder())->withCredentials($credentials)->withLogger($logger)->build();
        $batch = new Batch($core, $logger);
        $bulkItemsReader = (new BulkItemsReaderBuilder($core, $batch, $logger))->build();
        $b24Service = new ServiceBuilder($core, $batch, $bulkItemsReader, $logger);
    } catch (\Exception $e) {
        $errorMessage = 'Init failed: ' . $e->getMessage();
    }
} else {
    $errorMessage = 'No valid authorization found. Please close this window and open the app from Bitrix24 menu.';
}

if ($b24Service !== null) {
    // Check registration status and get full list for debug
    try {
        $connectorList = $b24Service->getIMOpenLinesScope()->connector()->list();
        $allConnectors = $connectorList->getConnectors();
        
        $isRegistered = false;
        foreach ($allConnectors as $connectorItem) {
            // Note: the SDK returns an array of ConnectorItemResult objects 
            // which extends AbstractItem, exposing properties via getters/magic variables
            if ($connectorItem->id === $connectorId) {
                $isRegistered = true;
                break;
            }
        }
        
        $sessionManager->saveRegistration($connectorId, $isRegistered);
    } catch (\Exception $e) {
        $isRegistered = false;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'register') {
        try {
            $handlerUrl = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/handler.php';
            $b24Service->getIMOpenLinesScope()->connector()->register([
                'ID' => $connectorId,
                'NAME' => 'WhatsApp Direct',
                'ICON' => [
                    'DATA_IMAGE' => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iIzI1RDM2NiIgZD0iTTEyIDBDNS4zNzMgMCAwIDUuMzczIDAgMTJjMCAyLjEyNC41NDYgNC4xMTkgMS41IDEuODU3TDAgMjRsNy4zOTUtMS4zODRDOC43MDEgMjMuNDU0IDEwLjI5OSAyNCAxMiAyNGM2LjYyNyAwIDEyLTUuMzczIDEyLTEyUzE4LjYyNyAwIDEyIDB6bTAtLjY2N2ExMi42NjcgMTIuNjY3IDAgMCAxIDEyLjY2NyAxMi42NjdBMTIuNjY3IDEyLjY2NyAwIDAgMSAxMiAyNC42NjcgMTIuNjY3IDEyLjY2NyAwIDAgMSAtIDYuNjg0IDEuNzQ5TDAgMjRsMS43NDktNi42ODRBMTIuNjY3IDEyLjY2NyAwIDAgMSAtLjY2NyAxMnoiLz48L3N2Zz4=', 
                ],
                'URL_IM' => $handlerUrl,
            ]);
            $isRegistered = true;
            $sessionManager->saveRegistration($connectorId, true);
        } catch (\Exception $e) {
            $errorMessage = 'Registration failed: ' . $e->getMessage();
        }
    }
}

} catch (\Exception $e) {
    $errorMessage = 'FATAL ERROR: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>WhatsApp Direct Settings</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body class="p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <h1>WhatsApp Direct Integration</h1>
            <a href="?clear_cache=1" class="btn btn-sm btn-outline-secondary">Clear Cache</a>
        </div>
        <hr>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <?= $errorMessage ?>
            </div>
        <?php elseif (!$isRegistered): ?>
            <div class="alert alert-warning">
                Connector <b>WhatsApp Direct</b> is not registered yet.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="register">
                <button type="submit" class="btn btn-success btn-lg">Register WhatsApp Direct</button>
            </form>
        <?php else: ?>
            <div class="alert alert-success">
                <h4>✓ WhatsApp Direct Connector is Active!</h4>
                <p>Search for <b>"WhatsApp Direct"</b> in your Bitrix24 Contact Center.</p>
            </div>
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <strong>Handler URL:</strong> <code><?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/handler.php' ?></code>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($allConnectors)): ?>
            <div class="mt-5 p-3 border rounded bg-light">
                <h5>Registered Connectors:</h5>
                <ul class="list-group mt-2">
                    <?php foreach ($allConnectors as $conn): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($conn->NAME ?? $conn->id) ?></strong><br>
                                <small class="text-muted">ID: <?= htmlspecialchars($conn->id) ?></small>
                            </div>
                            <?php if ($conn->id === $connectorId): ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
ob_end_flush();
?>