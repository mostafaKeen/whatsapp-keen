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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
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
                    <div>
                        <button id="createTemplateBtn" class="btn btn-sm btn-success mr-2" data-toggle="modal" data-target="#createTemplateModal">+ Create New Template</button>
                        <button id="refreshTemplates" class="btn btn-sm btn-primary">Refresh List</button>
                    </div>
                </div>

                <!-- Create Template Modal -->
                <div class="modal fade" id="createTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form id="createTemplateForm">
                                <div class="modal-header bg-success text-white">
                                    <h5 class="modal-title">Create New WhatsApp Template</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>Template Name (Element Name) *</label>
                                            <input type="text" name="elementName" class="form-control" placeholder="e.g. order_confirmation_01" required>
                                            <small class="text-muted">Unique, lowercase, no spaces (use underscores).</small>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>Category *</label>
                                            <select name="category" class="form-control" required>
                                                <option value="MARKETING">MARKETING</option>
                                                <option value="UTILITY">UTILITY</option>
                                                <option value="AUTHENTICATION">AUTHENTICATION</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>Language *</label>
                                            <input type="text" name="languageCode" class="form-control" value="en_US" placeholder="en_US" required>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>Vertical *</label>
                                            <input type="text" name="vertical" class="form-control" value="TEXT" placeholder="e.g. Ticket Update" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>Template Type *</label>
                                            <select name="templateType" id="templateType" class="form-control" required>
                                                <option value="TEXT">TEXT</option>
                                                <option value="IMAGE">IMAGE</option>
                                                <option value="VIDEO">VIDEO</option>
                                                <option value="DOCUMENT">DOCUMENT</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>Header (Optional)</label>
                                            <input type="text" name="header" id="headerField" class="form-control" placeholder="60 characters max">
                                        </div>
                                    </div>

                                    <!-- Media Example Section -->
                                    <div id="mediaExampleSection" class="p-3 mb-3 border rounded bg-light" style="display:none;">
                                        <h6 class="text-info"><i class="fas fa-photo-video"></i> Media Configuration</h6>
                                        <div class="form-group">
                                            <label>Sample Media URL/Handle *</label>
                                            <input type="text" name="exampleMedia" class="form-control" placeholder="URL to a sample image/file or Gupshup Media ID">
                                            <small class="text-muted">Required for Meta approval of media templates.</small>
                                        </div>
                                        <div class="form-group" id="exampleHeaderGroup">
                                            <label>Example Header Text (Sample)</label>
                                            <input type="text" name="exampleHeader" class="form-control" placeholder="Sample text for header variable">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Content (Body) *</label>
                                        <textarea name="content" class="form-control" rows="3" placeholder="Hi {{1}}, your order {{2}} is ready." required></textarea>
                                        <small class="text-muted">Use {{1}}, {{2}} for variables.</small>
                                    </div>

                                    <!-- Interactive Buttons Section -->
                                    <div class="p-3 mb-3 border rounded bg-light">
                                        <h6 class="text-primary"><i class="fas fa-mouse-pointer"></i> Interactive Buttons (Optional)</h6>
                                        <div id="buttonsContainer">
                                            <!-- Buttons will be added here -->
                                        </div>
                                        <button type="button" id="addButton" class="btn btn-outline-primary btn-sm mt-2 border-dashed">+ Add Button</button>
                                        <input type="hidden" name="buttons" id="buttonsJson">
                                    </div>

                                    <div class="form-group">
                                        <label>Example (Sample Value) *</label>
                                        <textarea name="example" class="form-control" rows="2" placeholder="Hi John, your order #123 is ready." required></textarea>
                                        <small class="text-muted">Gupshup requires a real example for approval.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Footer (Optional)</label>
                                        <input type="text" name="footer" class="form-control" placeholder="60 characters max">
                                    </div>
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="allowCategoryChange" name="allowTemplateCategoryChange" value="true">
                                            <label class="custom-control-label" for="allowCategoryChange">
                                                Allow Meta to automatically update template category
                                                <i class="fas fa-info-circle text-muted" title="If True, Meta will automatically update the category of the template as per the template content."></i>
                                            </label>
                                        </div>
                                    </div>
                                    <div id="createError" class="alert alert-danger" style="display:none;"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" id="submitTemplateBtn" class="btn btn-success">Submit for Approval</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Template Modal -->
                <div class="modal fade" id="editTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form id="editTemplateForm">
                                <input type="hidden" name="templateId">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title">Edit WhatsApp Template</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div id="editError" class="alert alert-danger" style="display:none; white-space: pre-wrap;"></div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>Template Name (Element Name)</label>
                                            <input type="text" name="elementName" class="form-control" readonly>
                                            <small class="text-muted">Element name cannot be changed.</small>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>Category *</label>
                                            <select name="category" class="form-control" required>
                                                <option value="MARKETING">MARKETING</option>
                                                <option value="UTILITY">UTILITY</option>
                                                <option value="AUTHENTICATION">AUTHENTICATION</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>Language *</label>
                                            <input type="text" name="languageCode" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>Template Type *</label>
                                            <select name="templateType" class="form-control" required>
                                                <option value="TEXT">TEXT</option>
                                                <option value="IMAGE">IMAGE</option>
                                                <option value="VIDEO">VIDEO</option>
                                                <option value="DOCUMENT">DOCUMENT</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>Header (Optional)</label>
                                            <input type="text" name="header" class="form-control" placeholder="60 characters max">
                                        </div>
                                    </div>

                                    <!-- Media Example Section -->
                                    <div id="editMediaExampleSection" class="p-3 mb-3 border rounded bg-light" style="display:none;">
                                        <h6 class="text-info"><i class="fas fa-photo-video"></i> Media Configuration</h6>
                                        <div class="form-group">
                                            <label>Sample Media URL/Handle *</label>
                                            <input type="text" name="exampleMedia" class="form-control" placeholder="URL or Handle ID">
                                        </div>
                                        <div class="form-group">
                                            <label>Example Header Text (Sample)</label>
                                            <input type="text" name="exampleHeader" class="form-control">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Content (Body) *</label>
                                        <textarea name="content" class="form-control" rows="3" required></textarea>
                                    </div>

                                    <!-- Interactive Buttons Section -->
                                    <div class="p-3 mb-3 border rounded bg-light">
                                        <h6 class="text-primary"><i class="fas fa-mouse-pointer"></i> Interactive Buttons (Optional)</h6>
                                        <div id="editButtonsContainer"></div>
                                        <button type="button" id="addEditButton" class="btn btn-outline-primary btn-sm mt-2">+ Add Button</button>
                                        <input type="hidden" name="buttons" id="editButtonsJson">
                                    </div>

                                    <div class="form-group">
                                        <label>Example (Sample Value) *</label>
                                        <textarea name="example" class="form-control" rows="2" required></textarea>
                                    </div>
                                        <input type="text" name="footer" class="form-control" placeholder="60 characters max">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" id="submitEditBtn" class="btn btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
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
                            <div id="tReasonAlert" class="alert alert-danger mb-3" style="display:none;">
                                <strong>Rejection/Failure Reason:</strong> <span id="tReason"></span>
                            </div>
                            <div class="p-3 bg-light rounded border mb-3">
                                <strong>Category:</strong> <span id="tCategory"></span><br>
                                <strong>Status:</strong> <span id="tStatus"></span><br>
                                <strong>Type:</strong> <span id="tType"></span><br>
                                <strong>Language:</strong> <span id="tLang"></span><br>
                                <strong>WABA ID:</strong> <span id="tWaba"></span>
                            </div>

                            <div id="tMediaSection" class="mb-3" style="display:none;">
                                <h6>Media Preview:</h6>
                                <div id="tMediaPreview" class="p-2 border rounded text-center bg-white"></div>
                            </div>

                            <h6>Content Preview:</h6>
                            <pre id="tData" class="p-3 bg-dark text-white rounded" style="white-space: pre-wrap; mb-3"></pre>

                            <div id="tButtonsSection" class="mb-3" style="display:none;">
                                <h6>Interactive Buttons:</h6>
                                <div id="tButtonsList" class="d-flex flex-wrap"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
            <style>
                #toastContainer { position: fixed; top: 20px; right: 20px; z-index: 99999; min-width: 280px; }
                .gup-toast { padding: 12px 18px; margin-bottom: 10px; border-radius: 8px; color: #fff; font-size: 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.2); opacity: 1; transition: opacity 0.5s; }
                .gup-toast.success { background: #28a745; }
                .gup-toast.error { background: #dc3545; }
                .gup-toast.info { background: #007bff; }
            </style>
            <div id="toastContainer"></div>
            <script>
                function showToast(msg, type, duration) {
                    type = type || 'info';
                    duration = duration || 3000;
                    var $t = $('<div class="gup-toast ' + type + '">' + msg + '</div>');
                    $('#toastContainer').append($t);
                    setTimeout(function() {
                        $t.css('opacity', 0);
                        setTimeout(function() { $t.remove(); }, 500);
                    }, duration);
                }

                $(document).ready(function() {
                    loadTemplates();
                    $('#refreshTemplates').off('click').on('click', function() { loadTemplates(); });

                    // Handle Template Type change (create form)
                    $('#templateType').change(function() {
                        var type = $(this).val();
                        if (type !== 'TEXT') {
                            $('#mediaExampleSection').show();
                            $('#headerField').val('').prop('disabled', true).attr('placeholder', 'Media headers do not use text');
                        } else {
                            $('#mediaExampleSection').hide();
                            $('#headerField').prop('disabled', false).attr('placeholder', '60 characters max');
                        }
                    });

                    // Dynamic Buttons logic (create form)
                    var buttonCount = 0;
                    $('#addButton').off('click').on('click', function() {
                        if (buttonCount >= 3) { showToast('Maximum 3 buttons allowed', 'error'); return; }
                        buttonCount++;
                        var bHtml = '<div class="btn-item border-bottom pb-2 mb-2" id="btn_' + buttonCount + '">' +
                            '<div class="d-flex justify-content-between"><strong>Button #' + buttonCount + '</strong> <button type="button" class="close remove-btn" data-id="' + buttonCount + '">&times;</button></div>' +
                            '<div class="row">' +
                                '<div class="col-6"><label class="small">Type</label><select class="form-control form-control-sm b-type"><option value="QUICK_REPLY">Quick Reply</option><option value="PHONE_NUMBER">Call Number</option><option value="URL">Visit URL</option></select></div>' +
                                '<div class="col-6"><label class="small">Text</label><input type="text" class="form-control form-control-sm b-text" placeholder="Button text"></div>' +
                            '</div>' +
                            '<div class="row mt-1 b-extra" style="display:none;">' +
                                '<div class="col-12"><label class="small b-extra-label">Value</label><input type="text" class="form-control form-control-sm b-value"></div>' +
                            '</div>' +
                        '</div>';
                        $('#buttonsContainer').append(bHtml);
                    });

                    $(document).on('change', '.b-type', function() {
                        var val = $(this).val();
                        var parent = $(this).closest('.btn-item');
                        if (val === 'QUICK_REPLY') {
                            parent.find('.b-extra').hide();
                        } else {
                            parent.find('.b-extra').show();
                            parent.find('.b-extra-label').text(val === 'PHONE_NUMBER' ? 'Phone Number (with code)' : 'URL (https://...)');
                        }
                    });

                    $(document).on('click', '.remove-btn', function() {
                        $('#btn_' + $(this).data('id')).remove();
                        buttonCount--;
                    });

                    // Create Template Form Submit
                    $('#createTemplateForm').off('submit').on('submit', function(e) {
                        e.preventDefault();
                        var btns = [];
                        $('.btn-item').each(function() {
                            var item = { type: $(this).find('.b-type').val(), text: $(this).find('.b-text').val() };
                            if (item.type === 'PHONE_NUMBER') item.phone_number = $(this).find('.b-value').val();
                            if (item.type === 'URL') item.url = $(this).find('.b-value').val();
                            btns.push(item);
                        });
                        $('#buttonsJson').val(btns.length > 0 ? JSON.stringify(btns) : '');
                        $('#submitTemplateBtn').prop('disabled', true).text('Processing...');
                        $('#createError').hide();

                        $.ajax({
                            url: 'create_template.php?' + new Date().getTime(),
                            method: 'POST',
                            data: $(this).serialize(),
                            success: function(rawData) {
                                var res;
                                try { res = (typeof rawData === 'object') ? rawData : JSON.parse(rawData); }
                                catch(ex) { res = { status: 'error', message: rawData }; }
                                if (res.status === 'success') {
                                    $('#createTemplateModal').modal('hide');
                                    $('#createTemplateForm')[0].reset();
                                    loadTemplates();
                                    showToast('Template submitted for approval!', 'success');
                                } else {
                                    var detail = res.message || JSON.stringify(res, null, 2);
                                    $('#createError').html('<strong>Error from Gupshup:</strong><br><pre style="font-size:11px;white-space:pre-wrap;">' + detail + '</pre>').show();
                                }
                                $('#submitTemplateBtn').prop('disabled', false).text('Submit for Approval');
                            },
                            error: function(xhr) {
                                var msg = 'HTTP ' + xhr.status + ': ' + (xhr.responseText || 'Unknown error');
                                $('#createError').html('<strong>Request Failed:</strong><br><pre style="font-size:11px;white-space:pre-wrap;">' + msg + '</pre>').show();
                                $('#submitTemplateBtn').prop('disabled', false).text('Submit for Approval');
                            }
                        });
                    });

                    // Load Templates
                    function loadTemplates() {
                        $('#templatesLoading').show();
                        $('#templatesContainer').hide();
                        $('#templatesError').hide();

                        $.ajax({
                            url: 'get_templates.php?' + new Date().getTime(),
                            method: 'GET',
                            cache: false,
                            success: function(rawData) {
                                $('#templatesLoading').hide();
                                $('#templatesContainer').show();
                                var response;
                                try { response = (typeof rawData === 'object') ? rawData : JSON.parse(rawData); }
                                catch(ex) { $('#templatesError').text('Invalid response from server: ' + rawData).show(); return; }

                                var html = '';
                                if (response.status === 'success' && response.templates && response.templates.length > 0) {
                                    response.templates.forEach(function(t) {
                                        var statusClass = 'badge-secondary';
                                        if (t.status === 'APPROVED') statusClass = 'badge-success';
                                        if (t.status === 'REJECTED' || t.status === 'FAILED') statusClass = 'badge-danger';
                                        if (t.status === 'PENDING') statusClass = 'badge-warning';
                                        var reasonHtml = t.reason ? '<div class="small text-danger mt-1"><i>' + t.reason + '</i></div>' : '';
                                        html += '<tr>';
                                        html += '<td><strong>' + t.elementName + '</strong></td>';
                                        html += '<td>' + t.category + ' <span class="badge badge-light border">' + t.templateType + '</span></td>';
                                        html += '<td>' + t.languageCode + '</td>';
                                        html += '<td><span class="badge ' + statusClass + '">' + t.status + '</span>' + reasonHtml + '</td>';
                                        html += '<td><div class="btn-group">';
                                        html += '<button class="btn btn-outline-info btn-sm view-btn" data-json=\'' + JSON.stringify(t).replace(/'/g, "&apos;") + '\' title="View details"><i class="fas fa-eye"></i></button>';
                                        html += '<button class="btn btn-outline-primary btn-sm edit-btn" data-json=\'' + JSON.stringify(t).replace(/'/g, "&apos;") + '\' title="Edit template"><i class="fas fa-edit"></i></button>';
                                        html += '<button class="btn btn-outline-danger btn-sm delete-btn" data-name="' + t.elementName + '" title="Delete template"><i class="fas fa-trash"></i></button>';
                                        html += '</div></td></tr>';
                                    });
                                } else {
                                    html = '<tr><td colspan="5" class="text-center py-4">No templates found or API returned an empty list.</td></tr>';
                                }
                                $('#templatesList').html(html);
                            },
                            error: function(xhr) {
                                $('#templatesLoading').hide();
                                $('#templatesError').text('Error fetching templates: HTTP ' + xhr.status + ' - ' + (xhr.responseText || 'Unknown')).show();
                            }
                        });
                    }

                    // View Template
                    $(document).on('click', '.view-btn', function() {
                        var t = $(this).data('json');
                        $('#tName').text(t.elementName);
                        $('#tCategory').text(t.category);
                        $('#tStatus').text(t.status);
                        $('#tType').text(t.templateType);
                        $('#tLang').text(t.languageCode);
                        $('#tWaba').text(t.wabaId);
                        $('#tData').text(t.data);
                        if (t.reason) { $('#tReason').text(t.reason); $('#tReasonAlert').show(); }
                        else { $('#tReasonAlert').hide(); }
                        $('#tMediaSection').hide();
                        $('#tButtonsSection').hide();
                        try {
                            var meta = typeof t.containerMeta === 'string' ? JSON.parse(t.containerMeta) : t.containerMeta;
                            if (meta) {
                                if (meta.mediaUrl || meta.sampleMedia) {
                                    var mUrl = meta.mediaUrl || meta.sampleMedia;
                                    var previewHtml = t.templateType === 'IMAGE' ?
                                        '<img src="' + mUrl + '" style="max-width:100%;max-height:200px;" class="rounded shadow-sm">' :
                                        '<a href="' + mUrl + '" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download"></i> View Media</a>';
                                    $('#tMediaPreview').html(previewHtml);
                                    $('#tMediaSection').show();
                                }
                                if (meta.buttons && meta.buttons.length > 0) {
                                    var bHtml = '';
                                    meta.buttons.forEach(function(btn) {
                                        var icon = btn.type === 'PHONE_NUMBER' ? 'fa-phone' : btn.type === 'URL' ? 'fa-external-link-alt' : 'fa-reply';
                                        bHtml += '<div class="mr-2 mb-2 p-2 px-3 border rounded bg-white shadow-sm"><i class="fas ' + icon + ' mr-2 text-primary"></i><strong>' + btn.text + '</strong>';
                                        if (btn.phone_number) bHtml += '<br><small class="text-muted">' + btn.phone_number + '</small>';
                                        if (btn.url) bHtml += '<br><small class="text-muted">' + btn.url + '</small>';
                                        bHtml += '</div>';
                                    });
                                    $('#tButtonsList').html(bHtml);
                                    $('#tButtonsSection').show();
                                }
                            }
                        } catch(e) { console.error('Error parsing meta:', e); }
                        $('#templateModal').modal('show');
                    });

                    // Edit Template - Open Modal
                    $(document).on('click', '.edit-btn', function() {
                        var t = $(this).data('json');
                        var $form = $('#editTemplateForm');
                        $form.find('[name="templateId"]').val(t.id);
                        $form.find('[name="elementName"]').val(t.elementName);
                        $form.find('[name="category"]').val(t.category);
                        $form.find('[name="languageCode"]').val(t.languageCode);
                        $form.find('[name="templateType"]').val(t.templateType);
                        $form.find('[name="content"]').val(t.data);
                        try {
                            var exMeta = typeof t.meta === 'string' ? JSON.parse(t.meta) : t.meta;
                            $form.find('[name="example"]').val(exMeta ? (exMeta.example || '') : '');
                        } catch(e) { $form.find('[name="example"]').val(''); }
                        try {
                            var meta = typeof t.containerMeta === 'string' ? JSON.parse(t.containerMeta) : t.containerMeta;
                            if (meta) {
                                $form.find('[name="footer"]').val(meta.footer || '');
                                $form.find('[name="header"]').val(meta.header || '');
                                $form.find('[name="exampleMedia"]').val(meta.sampleMedia || '');
                                $form.find('[name="exampleHeader"]').val(meta.sampleText || '');
                                $('#editMediaExampleSection').toggle(['IMAGE','VIDEO','DOCUMENT'].indexOf(t.templateType) !== -1);
                                $('#editButtonsContainer').empty();
                                if (meta.buttons && meta.buttons.length > 0) {
                                    meta.buttons.forEach(function(btn) { addButtonToEditForm(btn); });
                                }
                            }
                        } catch(e) { console.error('Error parsing meta for edit:', e); }
                        $('#editError').hide().text('');
                        $('#submitEditBtn').prop('disabled', false).text('Save Changes');
                        $('#editTemplateModal').modal('show');
                    });

                    function addButtonToEditForm(btn) {
                        btn = btn || {};
                        var id = 'ebi_' + Date.now() + '_' + Math.floor(Math.random()*1000);
                        var html = '<div class="edit-btn-item border-bottom pb-2 mb-2" id="' + id + '">' +
                            '<div class="d-flex justify-content-between"><strong>Button</strong> <button type="button" class="close remove-edit-btn" data-id="' + id + '">&times;</button></div>' +
                            '<div class="row">' +
                                '<div class="col-6"><label class="small">Type</label><select class="form-control form-control-sm eb-type">' +
                                    '<option value="QUICK_REPLY"' + (btn.type==='QUICK_REPLY'?' selected':'') + '>Quick Reply</option>' +
                                    '<option value="PHONE_NUMBER"' + (btn.type==='PHONE_NUMBER'?' selected':'') + '>Call Number</option>' +
                                    '<option value="URL"' + (btn.type==='URL'?' selected':'') + '>Visit URL</option>' +
                                '</select></div>' +
                                '<div class="col-6"><label class="small">Text</label><input type="text" class="form-control form-control-sm eb-text" value="' + (btn.text||'') + '" placeholder="Button text"></div>' +
                            '</div>' +
                            '<div class="row mt-1 eb-extra" style="' + (btn.type && btn.type!=='QUICK_REPLY' ? '' : 'display:none;') + '">' +
                                '<div class="col-12"><label class="small eb-extra-label">' + (btn.type==='PHONE_NUMBER'?'Phone Number':'URL') + '</label><input type="text" class="form-control form-control-sm eb-value" value="' + (btn.phone_number||btn.url||'') + '"></div>' +
                            '</div>' +
                        '</div>';
                        $('#editButtonsContainer').append(html);
                    }

                    $('#addEditButton').off('click').on('click', function() { addButtonToEditForm(); });

                    $(document).on('click', '.remove-edit-btn', function() {
                        $('#' + $(this).data('id')).remove();
                    });

                    $(document).on('change', '.eb-type', function() {
                        var val = $(this).val();
                        var parent = $(this).closest('.edit-btn-item');
                        if (val === 'QUICK_REPLY') {
                            parent.find('.eb-extra').hide();
                        } else {
                            parent.find('.eb-extra').show();
                            parent.find('.eb-extra-label').text(val === 'PHONE_NUMBER' ? 'Phone Number' : 'URL');
                        }
                    });

                    // Edit Template Form Submit
                    $('#editTemplateForm').off('submit').on('submit', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var btns = [];
                        $('#editButtonsContainer .edit-btn-item').each(function() {
                            var item = { type: $(this).find('.eb-type').val(), text: $(this).find('.eb-text').val() };
                            if (item.type === 'PHONE_NUMBER') item.phone_number = $(this).find('.eb-value').val();
                            if (item.type === 'URL') item.url = $(this).find('.eb-value').val();
                            btns.push(item);
                        });
                        $('#editButtonsJson').val(btns.length > 0 ? JSON.stringify(btns) : '');
                        $('#editError').hide().empty();
                        $('#submitEditBtn').prop('disabled', true).text('Saving...');

                        var formData = $(this).serialize();

                        $.ajax({
                            url: 'edit_template.php?' + new Date().getTime(),
                            method: 'POST',
                            data: formData,
                            success: function(rawData) {
                                var res;
                                try {
                                    res = (typeof rawData === 'object') ? rawData : JSON.parse(rawData);
                                } catch(ex) {
                                    // Non-JSON response — show raw content in error div, keep modal open
                                    $('#editError').html('<strong>Server returned non-JSON (possible PHP error):</strong><pre style="font-size:11px;white-space:pre-wrap;max-height:200px;overflow:auto;">' + $('<div>').text(rawData).html() + '</pre>').show();
                                    $('#submitEditBtn').prop('disabled', false).text('Save Changes');
                                    return;
                                }
                                if (res.status === 'success') {
                                    showToast('Template updated successfully!', 'success');
                                    $('#editTemplateModal').modal('hide');
                                    loadTemplates();
                                } else {
                                    var detail = res.message || JSON.stringify(res, null, 2);
                                    $('#editError').html('<strong>Error from Gupshup:</strong><br>' + detail).show();
                                    $('#editTemplateModal .modal-body').animate({ scrollTop: 0 }, 200);
                                    showToast('Error: ' + (res.message || 'See details in form'), 'error', 6000);
                                    $('#submitEditBtn').prop('disabled', false).text('Save Changes');
                                }
                            },
                            error: function(xhr) {
                                var msg = 'HTTP ' + xhr.status + '\n' + (xhr.responseText || 'No response body');
                                $('#editError').html('<strong>Request Failed:</strong><pre style="font-size:11px;white-space:pre-wrap;max-height:200px;overflow:auto;">' + $('<div>').text(msg).html() + '</pre>').show();
                                $('#editTemplateModal .modal-body').animate({ scrollTop: 0 }, 200);
                                showToast('Request failed: HTTP ' + xhr.status, 'error', 6000);
                                $('#submitEditBtn').prop('disabled', false).text('Save Changes');
                            }
                        });
                    });

                    // Delete Template
                    $(document).on('click', '.delete-btn', function() {
                        var name = $(this).data('name');
                        if (!confirm('Delete template "' + name + '"? This is irreversible.')) return;
                        $.ajax({
                            url: 'delete_template.php?' + new Date().getTime(),
                            method: 'POST',
                            data: { elementName: name },
                            success: function(rawData) {
                                var res;
                                try { res = (typeof rawData === 'object') ? rawData : JSON.parse(rawData); }
                                catch(ex) { showToast('Server error: ' + rawData, 'error', 6000); return; }
                                if (res.status === 'success') {
                                    showToast('Template "' + name + '" deleted.', 'info');
                                    loadTemplates();
                                } else {
                                    showToast('Error: ' + (res.message || JSON.stringify(res)), 'error', 6000);
                                }
                            },
                            error: function(xhr) {
                                showToast('Delete failed: HTTP ' + xhr.status, 'error', 6000);
                            }
                        });
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