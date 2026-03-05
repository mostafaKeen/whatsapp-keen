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
$connectorId = 'wosolkeen';
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
        $foundConnector = false;
        foreach ($allConnectors as $connectorItem) {
            // Note: the SDK returns an array of ConnectorItemResult objects 
            // which extends AbstractItem, exposing properties via getters/magic variables
            if ($connectorItem->id === $connectorId) {
                $isRegistered = true;
                $foundConnector = true;
                break;
            }
        }
        $sessionManager->saveRegistration($connectorId, $isRegistered);
    } catch (\Exception $e) {
        $isRegistered = false;
    }

    // Registration logic removed. No connector registration in index.php.
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
                Connector <b>WhatsApp Direct</b> is not registered yet.<br>
                <b>Debug:</b> Registration may have failed or Bitrix24 did not return the connector.<br>
                <b>Connector ID:</b> <?= htmlspecialchars($connectorId) ?><br>
                <b>Session ID:</b> <?= htmlspecialchars($sessionManager->getSessionId()) ?><br>
                <b>Connectors returned:</b> <?= htmlspecialchars(implode(', ', array_map(function($c){return $c->id;}, $allConnectors))) ?><br>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="register">
                <button type="submit" class="btn btn-success btn-lg">Register WhatsApp Direct</button>
            </form>
        <?php else: ?>
            <div class="alert alert-success">
                <h4>✓ WhatsApp Direct Connector is Active!</h4>
                <p>Search for <b>"WhatsApp Direct"</b> in your Bitrix24 Contact Center.</p>
                <b>Debug:</b> Connector found in Bitrix24 connector list.<br>
                <b>Connector ID:</b> <?= htmlspecialchars($connectorId) ?><br>
                <b>Session ID:</b> <?= htmlspecialchars($sessionManager->getSessionId()) ?><br>
                <b>Connectors returned:</b> <?= htmlspecialchars(implode(', ', array_map(function($c){return $c->id;}, $allConnectors))) ?><br>
            </div>
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <strong>Handler URL:</strong> <code><?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/handler.php' ?></code>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isRegistered): ?>
            <!-- WhatsApp Templates Section -->
            <div class="mt-5">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3>WhatsApp Templates</h3>
                    <button id="refreshTemplates" class="btn btn-sm btn-primary">Refresh List</button>
                </div>
                <div id="templatesLoading" class="text-center p-5 border rounded bg-white shadow-sm" style="display:none;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Loading templates...</span>
                    </div>
                    <p class="mt-2 text-muted">Fetching templates from Gupshup...</p>
                </div>
                <div id="templatesError" class="alert alert-danger" style="display:none;"></div>
                <div id="templatesContainer" class="table-responsive bg-white shadow-sm rounded border">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Language</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="templatesList">
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted small">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Template Preview Modal -->
            <div class="modal fade" id="templateModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tName">Template Details</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="p-3 bg-light rounded border mb-3">
                                <strong>Category:</strong> <span id="tCategory"></span><br>
                                <strong>Status:</strong> <span id="tStatus"></span><br>
                                <strong>WABA ID:</strong> <span id="tWaba"></span>
                            </div>
                            <h6>Content Preview:</h6>
                            <pre id="tData" class="p-3 bg-dark text-white rounded" style="white-space: pre-wrap;"></pre>
                        </div>
                    </div>
                </div>
            </div>

            <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
            <script>
                $(document).ready(function() {
                    loadTemplates();

                    $('#refreshTemplates').click(function() {
                        loadTemplates();
                    });

                    function loadTemplates() {
                        $('#templatesLoading').show();
                        $('#templatesContainer').hide();
                        $('#templatesError').hide();

                        $.ajax({
                            url: 'get_templates.php',
                            method: 'GET',
                            success: function(response) {
                                $('#templatesLoading').hide();
                                $('#templatesContainer').show();
                                
                                var html = '';
                                if (response.status === 'success' && response.templates && response.templates.length > 0) {
                                    response.templates.forEach(function(t) {
                                        var statusClass = 'badge-secondary';
                                        if (t.status === 'APPROVED') statusClass = 'badge-success';
                                        if (t.status === 'REJECTED') statusClass = 'badge-danger';
                                        if (t.status === 'PENDING') statusClass = 'badge-warning';

                                        html += '<tr>';
                                        html += '<td><strong>' + t.elementName + '</strong></td>';
                                        html += '<td>' + t.category + '</td>';
                                        html += '<td>' + t.languageCode + '</td>';
                                        html += '<td><span class="badge ' + statusClass + '">' + t.status + '</span></td>';
                                        html += '<td><button class="btn btn-outline-info btn-sm view-btn" data-json=\'' + JSON.stringify(t).replace(/'/g, "&apos;") + '\'>View details</button></td>';
                                        html += '</tr>';
                                    });
                                } else {
                                    html = '<tr><td colspan="5" class="text-center py-4">No templates found or API returned an empty list.</td></tr>';
                                }
                                $('#templatesList').html(html);
                            },
                            error: function(xhr) {
                                $('#templatesLoading').hide();
                                $('#templatesError').text('Error fetching templates: ' + (xhr.responseJSON ? xhr.responseJSON.error : 'Unknown error')).show();
                            }
                        });
                    }

                    $(document).on('click', '.view-btn', function() {
                        var t = $(this).data('json');
                        $('#tName').text(t.elementName);
                        $('#tCategory').text(t.category);
                        $('#tStatus').text(t.status);
                        $('#tWaba').text(t.wabaId);
                        $('#tData').text(t.data);
                        $('#templateModal').modal('show');
                    });
                });
            </script>
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