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
            <script>
                $(document).ready(function() {
                    loadTemplates();

                    // Handle Template Type change
                    $('#templateType').change(function() {
                        var type = $(this).val();
                        if (type !== 'TEXT') {
                            $('#mediaExampleSection').show();
                            // For media, the header is the media itself per Meta docs
                            $('#headerField').val('').prop('disabled', true).attr('placeholder', 'Media headers do not use text');
                        } else {
                            $('#mediaExampleSection').hide();
                            $('#headerField').prop('disabled', false).attr('placeholder', '60 characters max');
                        }
                    });

                    // Dynamic Buttons logic
                    var buttonCount = 0;
                    $('#addButton').click(function() {
                        if (buttonCount >= 3) { alert('Maximum 3 buttons allowed'); return; }
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

                    $('#refreshTemplates').click(function() {
                        loadTemplates();
                    });

                    $('#createTemplateForm').submit(function(e) {
                        e.preventDefault();
                        
                        // Serialize buttons to JSON
                        var btns = [];
                        $('.btn-item').each(function() {
                            var item = {
                                type: $(this).find('.b-type').val(),
                                text: $(this).find('.b-text').val()
                            };
                            if (item.type === 'PHONE_NUMBER') item.phone_number = $(this).find('.b-value').val();
                            if (item.type === 'URL') item.url = $(this).find('.b-value').val();
                            btns.push(item);
                        });
                        
                        if (btns.length > 0) {
                            $('#buttonsJson').val(JSON.stringify(btns));
                        } else {
                            $('#buttonsJson').val('');
                        }

                        $('#submitTemplateBtn').prop('disabled', true).text('Processing...');
                        $('#createError').hide();

                        $.ajax({
                            url: 'create_template.php',
                            method: 'POST',
                            data: $(this).serialize(),
                            success: function(response) {
                                if (response.status === 'success') {
                                    $('#createTemplateModal').modal('hide');
                                    $('#createTemplateForm')[0].reset();
                                    loadTemplates();
                                    alert('Template submitted successfully! It will appear in the list once processed by Gupshup.');
                                } else {
                                    $('#createError').text(response.message || 'Failed to create template.').show();
                                }
                                $('#submitTemplateBtn').prop('disabled', false).text('Submit for Approval');
                            },
                            error: function(xhr) {
                                var msg = 'Error creating template.';
                                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                                $('#createError').text(msg).show();
                                $('#submitTemplateBtn').prop('disabled', false).text('Submit for Approval');
                            }
                        });
                    });

                    function loadTemplates() {
                        $('#templatesLoading').show();
                        $('#templatesContainer').hide();
                        $('#templatesError').hide();

                        $.ajax({
                            url: 'get_templates.php',
                            method: 'GET',
                            cache: false,
                            data: { t: new Date().getTime() },
                            success: function(response) {
                                $('#templatesLoading').hide();
                                $('#templatesContainer').show();
                                
                                var html = '';
                                if (response.status === 'success' && response.templates && response.templates.length > 0) {
                                    console.log('Received templates count:', response.templates.length);
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
                        $('#tType').text(t.templateType);
                        $('#tLang').text(t.languageCode);
                        $('#tWaba').text(t.wabaId);
                        $('#tData').text(t.data);
                        
                        if (t.reason) {
                            $('#tReason').text(t.reason);
                            $('#tReasonAlert').show();
                        } else {
                            $('#tReasonAlert').hide();
                        }

                        // Parse ContainerMeta for Media and Buttons
                        $('#tMediaSection').hide();
                        $('#tButtonsSection').hide();
                        
                        try {
                            var meta = typeof t.containerMeta === 'string' ? JSON.parse(t.containerMeta) : t.containerMeta;
                            
                            if (meta) {
                                // Hande Media
                                if (meta.mediaUrl || meta.sampleMedia) {
                                    var mUrl = meta.mediaUrl || meta.sampleMedia;
                                    var previewHtml = '';
                                    if (t.templateType === 'IMAGE') {
                                        previewHtml = '<img src="' + mUrl + '" style="max-width: 100%; max-height: 200px;" class="rounded shadow-sm">';
                                    } else {
                                        previewHtml = '<a href="' + mUrl + '" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download"></i> View Media Attachment</a>';
                                    }
                                    $('#tMediaPreview').html(previewHtml);
                                    $('#tMediaSection').show();
                                }

                                // Handle Buttons
                                if (meta.buttons && meta.buttons.length > 0) {
                                    var bHtml = '';
                                    meta.buttons.forEach(function(btn) {
                                        var icon = 'fa-reply';
                                        if (btn.type === 'PHONE_NUMBER') icon = 'fa-phone';
                                        if (btn.type === 'URL') icon = 'fa-external-link-alt';
                                        
                                        bHtml += '<div class="mr-2 mb-2 p-2 px-3 border rounded bg-white shadow-sm">';
                                        bHtml += '<i class="fas ' + icon + ' mr-2 text-primary"></i> <strong>' + btn.text + '</strong>';
                                        if (btn.phone_number) bHtml += '<br><small class="text-muted">' + btn.phone_number + '</small>';
                                        if (btn.url) bHtml += '<br><small class="text-muted scroll-x" style="font-size: 10px;">' + btn.url + '</small>';
                                        bHtml += '</div>';
                                    });
                                    $('#tButtonsList').html(bHtml);
                                    $('#tButtonsSection').show();
                                }
                            }
                        } catch(e) { console.error("Error parsing meta:", e); }
                        
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