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
// Debug logging removed for production

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
    <title>KEEN WABA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
</head>
<body class="p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="fab fa-whatsapp text-success"></i> KEEN WABA</h1>
        </div>
        <hr>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= $errorMessage ?>
            </div>
        <?php elseif (!$isRegistered): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-circle"></i> WhatsApp connector is not registered yet. Please set up the connector from your Bitrix24 Contact Center.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="register">
                <button type="submit" class="btn btn-success btn-lg"><i class="fab fa-whatsapp"></i> Register WhatsApp Connector</button>
            </form>
        <?php else: ?>
            <div class="alert alert-success mb-4">
                <h5 class="mb-1"><i class="fas fa-check-circle"></i> WhatsApp Connector is Active</h5>
                <small class="text-muted">Connected and ready to send messages.</small>
            </div>
        <?php endif; ?>

        <?php if ($isRegistered): ?>
            <!-- WhatsApp Templates Section -->
            <div class="mt-5">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3>WhatsApp Templates</h3>
                    <div>
                        <button id="createTemplateBtn" class="btn btn-sm btn-success mr-2" data-toggle="modal" data-target="#createTemplateModal">+ Create New Template</button>
                        <button id="sendCampaignBtn" class="btn btn-sm btn-info mr-2" data-toggle="modal" data-target="#campaignModal"><i class="fas fa-paper-plane"></i> Send Bulk Campaign</button>
                        <button id="campaignAnalysisBtn" class="btn btn-sm btn-secondary mr-2" data-toggle="modal" data-target="#campaignAnalysisModal"><i class="fas fa-chart-bar"></i> Campaign Analysis</button>
                        <button id="refreshTemplates" class="btn btn-sm btn-primary">Refresh List</button>
                    </div>
                </div>

                <!-- Campaign Modal -->
                <div class="modal fade" id="campaignModal" tabindex="-1" data-backdrop="static">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form id="campaignForm">
                                <div class="modal-header bg-info text-white">
                                    <h5 class="modal-title"><i class="fas fa-paper-plane"></i> Send Bulk Campaign</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal" id="campaignCloseBtn">&times;</button>
                                </div>
                                <div class="modal-body">

                                    <div class="form-group">
                                        <label>Select Template *</label>
                                        <select name="templateId" id="campaignTemplateSelect" class="form-control" required>
                                            <option value="">-- Select an approved template --</option>
                                        </select>
                                    </div>
                                    <div class="form-group" id="campaignMediaUrlGroup" style="display:none;">
                                        <label>Media Header URL (Required for this template) *</label>
                                        <input type="url" name="mediaUrl" id="campaignMediaUrl" class="form-control" placeholder="https://example.com/image.jpg">
                                        <small class="text-muted">Direct link to the IMAGE, VIDEO, or DOCUMENT file.</small>
                                    </div>
                                    
                                    <div class="form-group mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="mb-0">Target Phone Numbers (One per line) *</label>
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="toggleContactSelector"><i class="fas fa-users"></i> Add from Bitrix24 Contacts</button>
                                        </div>
                                        
                                        <!-- Contact Selector Section (Hidden by default) -->
                                        <div id="contactSelectorSection" style="display:none;" class="mb-3 border rounded">
                                            <div class="p-2 bg-light border-bottom d-flex justify-content-between align-items-center">
                                                <input type="text" id="contactSearchInput" class="form-control form-control-sm" style="width: 200px;" placeholder="Search contacts...">
                                                <div>
                                                    <div class="custom-control custom-checkbox custom-control-inline mr-2">
                                                        <input type="checkbox" class="custom-control-input" id="selectAllContacts">
                                                        <label class="custom-control-label small" for="selectAllContacts">Select All</label>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-primary" id="applyContacts">Add Selected</button>
                                                </div>
                                            </div>
                                            <div class="contact-list-container" id="contactListContainer">
                                                <div class="text-center p-4" id="contactsLoading">
                                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                                    <span class="ml-2">Loading contacts...</span>
                                                </div>
                                                <div id="contactList"></div>
                                                <div class="text-center p-2" id="loadMoreContactsContainer" style="display:none;">
                                                    <button type="button" class="btn btn-sm btn-link" id="loadMoreContacts">Load More Contacts...</button>
                                                </div>
                                            </div>
                                        </div>

                                        <textarea name="numbers" id="campaignNumbersArea" class="form-control" rows="8" placeholder="918286836XXX&#10;919876543XXX" required></textarea>
                                        <small class="text-muted" id="campaignNumberCount">0 numbers</small>
                                    </div>

                                    <!-- Progress/Status Area (Hidden initially) -->
                                    <div id="campaignStatusArea" style="display:none;" class="mt-4 p-3 border rounded bg-light">
                                        <h6>Campaign Progress</h6>
                                        <p id="campaignStatusText" class="mb-1 text-primary">Starting...</p>
                                        <div class="progress mb-2" style="height: 20px;">
                                            <div id="campaignProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted small">
                                            <span id="campaignStatSuccess" class="text-success">Success: 0</span>
                                            <span id="campaignStatFailed" class="text-danger">Failed: 0</span>
                                            <span id="campaignStatTotal">Total: 0</span>
                                        </div>
                                        <div class="mt-3 text-center" id="campaignControls">
                                            <button type="button" class="btn btn-sm btn-warning" id="pauseCampaignBtn" style="display:none;"><i class="fas fa-pause"></i> Pause</button>
                                            <button type="button" class="btn btn-sm btn-success" id="resumeCampaignBtn" style="display:none;"><i class="fas fa-play"></i> Resume</button>
                                        </div>
                                    </div>
                                    <input type="hidden" id="currentCampaignJobId" value="">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal" id="campaignCancelBtn">Close</button>
                                    <button type="submit" id="startCampaignBtn" class="btn btn-info">Start Campaign</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Campaign Analysis Modal -->
                <div class="modal fade" id="campaignAnalysisModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header bg-secondary text-white">
                                <h5 class="modal-title"><i class="fas fa-chart-bar"></i> Campaign Analysis</h5>
                                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <ul class="nav nav-tabs mb-3" id="analysisTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="campaigns-tab" data-toggle="tab" href="#campaigns-pane" role="tab">Campaign Analysis</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="templates-tab" data-toggle="tab" href="#templates-pane" role="tab">Template Status Logs</a>
                                    </li>
                                </ul>
                                <div class="tab-content" id="analysisTabContent">
                                    <!-- Campaigns Tab -->
                                    <div class="tab-pane fade show active" id="campaigns-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered table-hover">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>Date &amp; Time</th>
                                                        <th>Template / Campaign</th>
                                                        <th>Total Targets</th>
                                                        <th>Sent (API)</th>
                                                        <th class="text-success">Delivered</th>
                                                        <th class="text-primary">Read</th>
                                                        <th class="text-danger">Failed</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="campaignAnalysisList">
                                                    <tr><td colspan="8" class="text-center">Loading campaign data...</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <!-- Templates Tab -->
                                    <div class="tab-pane fade" id="templates-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered table-hover">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>Timestamp</th>
                                                        <th>Template Name</th>
                                                        <th>Event</th>
                                                        <th>Language</th>
                                                        <th>Reason / Details</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="templateStatusList">
                                                    <tr><td colspan="5" class="text-center">Loading template updates...</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" id="refreshAnalysisBtn">Refresh Data</button>
                                <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Job Details Modal -->
                <div class="modal fade" id="jobDetailsModal" tabindex="-1" style="z-index: 1060;">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content border-info">
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title">Campaign Job Details</h5>
                                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div id="jobDetailsContent">
                                    <div class="text-center py-4"><div class="spinner-border text-info"></div> Loading details...</div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            </div>
                        </div>
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
                                        <label>Footer (Optional)</label>
                                        <input type="text" name="footer" class="form-control" placeholder="60 characters max">
                                    </div>
                                    <div class="form-group">
                                        <label>Example (Sample Value) *</label>
                                        <textarea name="example" class="form-control" rows="3" required placeholder="Full content with variables replaced by sample text"></textarea>
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

                <!-- API Response Banner (persistent, always visible after edit/delete) -->
                <div id="apiResponseBanner" class="mt-3" style="display:none;">
                    <div class="alert alert-dismissible mb-0" id="apiResponseAlert" role="alert">
                        <button type="button" class="close" onclick="$('#apiResponseBanner').hide()">&times;</button>
                        <span id="apiResponseTitle"></span>
                    </div>
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
                                <strong>Language:</strong> <span id="tLang"></span>
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
                
                .contact-list-container { max-height: 250px; overflow-y: auto; border: 1px solid #ddd; border-top: none; padding: 10px; border-radius: 0 0 5px 5px; }
                .contact-item { border-bottom: 1px solid #f1f1f1; padding: 5px 0; }
                .contact-item:last-child { border-bottom: none; }
                .contact-item label { margin-bottom: 0; cursor: pointer; display: block; }
                #contactSearchInput { border-radius: 5px 5px 0 0; }
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

                function showApiResponse(type, title, body) {
                    var colorClass = type === 'success' ? 'alert-success' : 'alert-danger';
                    $('#apiResponseAlert').removeClass('alert-success alert-danger').addClass(colorClass);
                    $('#apiResponseTitle').text(title);
                    $('#apiResponseBanner').show();
                    $('html, body').animate({ scrollTop: $('#apiResponseBanner').offset().top - 80 }, 400);
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
                                    var detail = res.message || 'An error occurred while creating the template.';
                                    $('#createError').html('<strong>Error:</strong> ' + detail).show();
                                }
                                $('#submitTemplateBtn').prop('disabled', false).text('Submit for Approval');
                            },
                            error: function(xhr) {
                                var msg = 'Request failed (HTTP ' + xhr.status + '). Please try again.';
                                $('#createError').html('<strong>Error:</strong> ' + msg).show();
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
                                window.allTemplatesData = [];
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
                                    window.allTemplatesData = response.templates;
                                    console.log('Templates loaded successfully:', response.templates.length);
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
                        $form.find('[name="templateId"]').val(t.id || t.templateId || t.externalId || '');
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
                    var isEditSubmitting = false;
                    $('#editTemplateForm').off('submit').on('submit', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        if (isEditSubmitting) {
                            console.warn('Edit already in progress, ignoring duplicate submit');
                            return false;
                        }
                        isEditSubmitting = true;

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
                                isEditSubmitting = false;
                                var res;
                                try {
                                    res = (typeof rawData === 'object') ? rawData : JSON.parse(rawData);
                                } catch(ex) {
                                    showApiResponse('danger', 'An unexpected server error occurred. Please try again.', '');
                                    showToast('Server error', 'error', 5000);
                                    $('#editTemplateModal').modal('hide');
                                    return;
                                }
                                if (res.status === 'success' && !(res.message && res.message.toLowerCase().indexOf('error') !== -1)) {
                                    showApiResponse('success', 'Template updated successfully!', '');
                                    showToast('Template updated successfully!', 'success');
                                    $('#editTemplateModal').modal('hide');
                                    loadTemplates();
                                } else {
                                    var detail = res.message || 'An error occurred while updating the template.';
                                    showApiResponse('danger', detail, '');
                                    showToast('Error updating template', 'error', 5000);
                                    $('#editTemplateModal').modal('hide');
                                }
                            },
                            error: function(xhr) {
                                isEditSubmitting = false;
                                var statusMsg = xhr.status === 429 ? 'Rate limit hit. Please wait a minute before trying again.' :
                                               xhr.status === 400 ? 'This edit was rejected. Please check your template content.' : 'Request failed (HTTP ' + xhr.status + ')';
                                showApiResponse('danger', statusMsg, '');
                                showToast('Edit failed', 'error', 5000);
                                $('#editTemplateModal').modal('hide');
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
                                    showApiResponse('success', 'Delete Successful', 'Template "' + name + '" has been removed.');
                                    loadTemplates();
                                } else {
                                    var detail = res.message || JSON.stringify(res, null, 2);
                                    showApiResponse('danger', detail, '');
                                    showToast('Delete failed', 'error', 5000);
                                }
                            },
                            error: function(xhr) {
                                showApiResponse('danger', 'Delete request failed (HTTP ' + xhr.status + ')', '');
                                showToast('Delete failed', 'error', 5000);
                            }
                        });
                    });

                    // ============================================
                    // BULK CAMPAIGN LOGIC
                    // ============================================
                    var allContacts = [];
                    var nextStart = 50;

                    $('#toggleContactSelector').click(function() {
                        var isVisible = $('#contactSelectorSection').is(':visible');
                        $('#contactSelectorSection').slideToggle();
                        if (!isVisible && allContacts.length === 0) {
                            fetchCampaignContacts(0);
                        }
                    });

                    function fetchCampaignContacts(start) {
                        if (start === 0) {
                            $('#contactsLoading').show();
                            $('#contactList').hide();
                            $('#loadMoreContactsContainer').hide();
                            allContacts = [];
                        } else {
                            $('#loadMoreContacts').prop('disabled', true).text('Loading...');
                        }

                        $.ajax({
                            url: 'get_contacts.php?start=' + start,
                            method: 'GET',
                            cache: false,
                            success: function(res) {
                                var newContacts = (res.result || []).filter(function(c) {
                                    return c.PHONE && c.PHONE.length > 0;
                                });
                                
                                allContacts = allContacts.concat(newContacts);
                                nextStart = res.next || null;

                                renderCampaignContacts($('#contactSearchInput').val());
                                
                                if (start === 0) {
                                    $('#contactsLoading').hide();
                                    $('#contactList').show();
                                } else {
                                    $('#loadMoreContacts').prop('disabled', false).text('Load More Contacts...');
                                }

                                if (nextStart) {
                                    $('#loadMoreContactsContainer').show();
                                } else {
                                    $('#loadMoreContactsContainer').hide();
                                }
                            },
                            error: function() {
                                if (start === 0) {
                                    $('#contactsLoading').html('<span class="text-danger">Failed to load contacts. Ensure Bitrix24 is accessible.</span>');
                                } else {
                                    showToast('Failed to load more contacts', 'error');
                                    $('#loadMoreContacts').prop('disabled', false).text('Load More Contacts...');
                                }
                            }
                        });
                    }

                    $('#loadMoreContacts').click(function() {
                        if (nextStart) {
                            fetchCampaignContacts(nextStart);
                        }
                    });

                    function renderCampaignContacts(search) {
                        var html = '';
                        var filter = (search || '').toLowerCase();
                        var filtered = allContacts.filter(function(c) {
                            var fullName = ((c.NAME || '') + ' ' + (c.LAST_NAME || '')).toLowerCase();
                            return fullName.includes(filter);
                        });
                        
                        if (filtered.length === 0) {
                            if (allContacts.length > 0) {
                                html = '<div class="text-center p-3 text-muted">No contacts match your search.</div>';
                            } else {
                                html = '<div class="text-center p-3 text-muted">No contacts with phone numbers found.</div>';
                            }
                        } else {
                            filtered.forEach(function(c) {
                                c.PHONE.forEach(function(p, pIdx) {
                                    var uniqueId = 'contact_' + c.ID + '_' + pIdx;
                                    html += '<div class="contact-item font-weight-normal">' +
                                        '<div class="custom-control custom-checkbox">' +
                                            '<input type="checkbox" class="custom-control-input contact-checkbox" id="' + uniqueId + '" value="' + p.VALUE + '">' +
                                            '<label class="custom-control-label small" for="' + uniqueId + '">' + (c.NAME || 'No Name') + (c.LAST_NAME ? ' ' + c.LAST_NAME : '') + ' <span class="text-muted">(' + p.VALUE + ')</span></label>' +
                                        '</div>' +
                                    '</div>';
                                });
                            });
                        }
                        $('#contactList').html(html);
                    }

                    $('#contactSearchInput').on('input', function() {
                        renderCampaignContacts($(this).val());
                    });

                    $('#selectAllContacts').change(function() {
                        var checked = $(this).prop('checked');
                        $('.contact-checkbox').prop('checked', checked);
                    });

                    $('#applyContacts').click(function() {
                        var selected = [];
                        $('.contact-checkbox:checked').each(function() {
                            selected.push($(this).val());
                        });
                        
                        if (selected.length === 0) {
                            showToast('Please select at least one contact', 'info');
                            return;
                        }
                        
                        var currentVal = $('#campaignNumbersArea').val().trim();
                        var newVal = currentVal + (currentVal ? "\n" : "") + selected.join("\n");
                        $('#campaignNumbersArea').val(newVal).trigger('input');
                        $('#contactSelectorSection').slideUp();
                    });

                    var currentCampaignTimer = null;
                    var activeJobId = null;

                    $('#campaignModal').on('show.bs.modal', function() {
                        var $sel = $('#campaignTemplateSelect');
                        $sel.empty().append('<option value="">-- Select an approved template --</option>');
                        if (window.allTemplatesData && window.allTemplatesData.length > 0) {
                            window.allTemplatesData.forEach(function(t) {
                                if (t.status === 'APPROVED') {
                                    $sel.append('<option value="'+(t.id || t.templateId || t.externalId)+'">'+t.elementName+' ('+t.templateType+')</option>');
                                }
                            });
                        }
                    });

                    $('#campaignTemplateSelect').on('change', function() {
                        var templateId = $(this).val();
                        var selectedTemplate = (window.allTemplatesData || []).find(function(t) {
                            return (t.id || t.templateId || t.externalId) === templateId;
                        });
                        
                        var autoMediaUrl = '';
                        if (selectedTemplate) {
                            try {
                                var meta = {};
                                // Try containerMeta first
                                if (selectedTemplate.containerMeta) {
                                    meta = typeof selectedTemplate.containerMeta === 'string' ? JSON.parse(selectedTemplate.containerMeta) : selectedTemplate.containerMeta;
                                }
                                if (meta.mediaUrl || meta.sampleMedia) {
                                    autoMediaUrl = meta.mediaUrl || meta.sampleMedia;
                                } else if (selectedTemplate.meta) {
                                    // Try meta second
                                    var metaObj = typeof selectedTemplate.meta === 'string' ? JSON.parse(selectedTemplate.meta) : selectedTemplate.meta;
                                    if (metaObj && (metaObj.mediaUrl || metaObj.sampleMedia)) {
                                        autoMediaUrl = metaObj.mediaUrl || metaObj.sampleMedia;
                                    }
                                }
                            } catch(e) {
                                console.warn('Meta parsing failed:', e);
                            }
                        }

                        // Check if template type requires media (IMAGE, VIDEO, DOCUMENT)
                        if (selectedTemplate && ['IMAGE', 'VIDEO', 'DOCUMENT'].indexOf(selectedTemplate.templateType) !== -1) {
                            $('#campaignMediaUrl').val(autoMediaUrl);
                            if (autoMediaUrl) {
                                // If found in template data, no need to ask user
                                $('#campaignMediaUrlGroup').slideUp();
                                $('#campaignMediaUrl').prop('required', false);
                            } else {
                                // Not found, ask user to provide it
                                $('#campaignMediaUrlGroup').slideDown();
                                $('#campaignMediaUrl').prop('required', true);
                            }
                        } else {
                            $('#campaignMediaUrlGroup').slideUp();
                            $('#campaignMediaUrl').val('').prop('required', false);
                        }
                    });

                    $('#campaignNumbersArea').on('input', function() {
                        var lines = $(this).val().split('\n').filter(function(l) { return l.trim() !== ''; });
                        $('#campaignNumberCount').text(lines.length + ' numbers');
                    });

                    $('#campaignForm').on('submit', function(e) {
                        e.preventDefault();
                        if (activeJobId) return; // Already running/paused

                        var formData = $(this).serializeArray();
                        var templateId = $('#campaignTemplateSelect').val();
                        var selectedTemplate = (window.allTemplatesData || []).find(function(t) {
                            return (t.id || t.templateId || t.externalId) === templateId;
                        });
                        
                        var templateName = $('#campaignTemplateSelect option:selected').text();
                        formData.push({name: 'templateName', value: templateName});
                        if (selectedTemplate) {
                            formData.push({name: 'templateType', value: selectedTemplate.templateType});
                        }

                        $('#startCampaignBtn, #campaignCancelBtn, #campaignCloseBtn').prop('disabled', true);
                        $('#campaignStatusArea').slideDown();
                        $('#campaignStatusText').text('Initializing campaign job...').removeClass('text-danger text-success').addClass('text-info');
                        updateCampaignProgress(0, 0, 0, 0);

                        $.ajax({
                            url: 'create_campaign_job.php',
                            method: 'POST',
                            data: $.param(formData),
                            success: function(res) {
                                if (res.status === 'success') {
                                    activeJobId = res.job_id;
                                    $('textarea[name="numbers"], select[name="templateId"]').prop('readonly', true);
                                    $('#campaignStatusText').text('Campaign Job Created. Starting batches...');
                                    $('#pauseCampaignBtn').show();
                                    $('#resumeCampaignBtn').hide();
                                    processNextCampaignBatch();
                                } else {
                                    campaignError(res.message);
                                }
                            },
                            error: function(xhr) {
                                campaignError('Failed to create campaign job: HTTP ' + xhr.status);
                            }
                        });
                    });

                    function processNextCampaignBatch() {
                        if (!activeJobId) return;
                        
                        $('#campaignStatusText').text('Processing batch...');
                        
                        $.ajax({
                            url: 'send_campaign_batch.php',
                            method: 'POST',
                            data: { job_id: activeJobId, batch_size: 5 },
                            success: function(res) {
                                if (!res || res.status !== 'success') {
                                    pauseCampaignUI('Error from batch script: ' + (res.message||'Unknown'));
                                    return;
                                }
                                
                                updateCampaignProgress(res.processed, res.total, res.success, res.failed);
                                
                                if (res.job_status === 'completed') {
                                    $('#campaignStatusText').text('Campaign Completed!').removeClass('text-info').addClass('text-success');
                                    $('#pauseCampaignBtn, #resumeCampaignBtn').hide();
                                    $('#campaignCancelBtn, #campaignCloseBtn').prop('disabled', false).text('Close');
                                    $('#startCampaignBtn').prop('disabled', false).text('Start New Campaign');
                                    activeJobId = null; 
                                } else if (res.job_status === 'paused' || res.rate_limited) {
                                    pauseCampaignUI('Paused (Rate limit hit 429). Please wait before resuming.');
                                } else {
                                    // Make next request in 1.5 seconds to space out Gupshup API calls
                                    currentCampaignTimer = setTimeout(processNextCampaignBatch, 1500);
                                }
                            },
                            error: function(xhr) {
                                pauseCampaignUI('Connection error while processing batch. Paused.');
                            }
                        });
                    }

                    function updateCampaignProgress(processed, total, success, failed) {
                        var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                        $('#campaignProgressBar').css('width', pct + '%').text(pct + '% (' + processed + '/' + total + ')');
                        $('#campaignStatSuccess').text('Success: ' + (success||0));
                        $('#campaignStatFailed').text('Failed: ' + (failed||0));
                        $('#campaignStatTotal').text('Total: ' + (total||0));
                    }

                    function pauseCampaignUI(msg) {
                        clearTimeout(currentCampaignTimer);
                        $('#campaignStatusText').text(msg).removeClass('text-info').addClass('text-warning');
                        $('#pauseCampaignBtn').hide();
                        $('#resumeCampaignBtn').show();
                        $('#campaignCancelBtn, #campaignCloseBtn').prop('disabled', false);
                    }

                    function campaignError(msg) {
                        $('#campaignStatusText').text(msg).removeClass('text-info text-success').addClass('text-danger');
                        $('#startCampaignBtn, #campaignCancelBtn, #campaignCloseBtn').prop('disabled', false);
                        activeJobId = null;
                    }

                    $('#pauseCampaignBtn').on('click', function() {
                        pauseCampaignUI('Campaign paused by user.');
                    });

                    $('#resumeCampaignBtn').on('click', function() {
                        if (!activeJobId) { alert('No active job to resume.'); return; }
                        $(this).hide();
                        $('#pauseCampaignBtn').show();
                        $('#campaignCancelBtn, #campaignCloseBtn').prop('disabled', true);
                        processNextCampaignBatch();
                    });

                    // --- Campaign Analysis Logic ---
                    function loadCampaignAnalysis() {
                        $('#campaignAnalysisList').html('<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm text-secondary"></div> Loading...</td></tr>');
                        $.ajax({
                            url: 'get_campaign_analysis.php?' + new Date().getTime(),
                            method: 'GET',
                            success: function(res) {
                                if (res.status === 'success' && res.data && res.data.length > 0) {
                                    var html = '';
                                    res.data.forEach(function(job) {
                                        var d = job.delivered || 0;
                                        var r = job.read || 0;
                                        var f = (job.failed || 0) + (job.webhook_failed || 0);
                                        var sent = job.success || 0;
                                        html += '<tr>' +
                                                '<td>' + (job.created_at || '-') + '</td>' +
                                                '<td><strong>' + (job.template_name || 'Unknown') + '</strong></td>' +
                                                '<td>' + job.total + '</td>' +
                                                '<td>' + sent + '</td>' +
                                                '<td class="text-success font-weight-bold">' + d + '</td>' +
                                                '<td class="text-primary font-weight-bold">' + r + '</td>' +
                                                '<td class="text-danger font-weight-bold">' + f + '</td>' +
                                                '<td><button class="btn btn-sm btn-outline-info" onclick="viewCampaignDetails(\'' + job.job_id + '\')"><i class="fas fa-eye"></i> Details</button></td>' +
                                                '</tr>';
                                    });
                                    $('#campaignAnalysisList').html(html);
                                } else {
                                    $('#campaignAnalysisList').html('<tr><td colspan="8" class="text-center text-muted">No campaigns found.</td></tr>');
                                }
                            },
                            error: function() {
                                $('#campaignAnalysisList').html('<tr><td colspan="8" class="text-center text-danger">Failed to load analysis data.</td></tr>');
                            }
                        });
                        loadTemplateUpdates();
                    }

                    function loadTemplateUpdates() {
                        $('#templateStatusList').html('<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm text-secondary"></div> Loading...</td></tr>');
                        $.ajax({
                            url: 'get_template_updates.php?' + new Date().getTime(),
                            method: 'GET',
                            success: function(res) {
                                if (res.status === 'success' && res.data && res.data.length > 0) {
                                    var html = '';
                                    res.data.forEach(function(item) {
                                        var badgeClass = 'badge-secondary';
                                        if (item.event === 'APPROVED') badgeClass = 'badge-success';
                                        if (item.event === 'FAILED' || item.event === 'REJECTED') badgeClass = 'badge-danger';
                                        if (item.event === 'PENDING') badgeClass = 'badge-warning';

                                        html += '<tr>' +
                                                '<td>' + (item.timestamp || '-') + '</td>' +
                                                '<td><strong>' + (item.template_name || '-') + '</strong></td>' +
                                                '<td><span class="badge ' + badgeClass + '">' + (item.event || 'UNKNOWN') + '</span></td>' +
                                                '<td>' + (item.language || '-') + '</td>' +
                                                '<td><small>' + (item.reason || '-') + '</small></td>' +
                                                '</tr>';
                                    });
                                    $('#templateStatusList').html(html);
                                } else {
                                    $('#templateStatusList').html('<tr><td colspan="5" class="text-center text-muted">No template updates found.</td></tr>');
                                }
                            }
                        });
                    }

                    window.viewCampaignDetails = function(jobId) {
                        $('#jobDetailsModal').modal('show');
                        $('#jobDetailsContent').html('<div class="text-center py-4"><div class="spinner-border text-info"></div> Loading job details...</div>');
                        
                        $.ajax({
                            url: 'get_campaign_job_details.php?job_id=' + jobId,
                            method: 'GET',
                            success: function(res) {
                                if (res.status === 'success' && res.data) {
                                    var job = res.data;
                                    var html = '<h6><strong>Campaign:</strong> ' + job.template_name + ' <small class="text-muted">(' + job.job_id + ')</small></h6>' +
                                               '<p class="mb-3">Created: ' + job.created_at + ' | Total: ' + job.total + '</p>' +
                                               '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">' +
                                               '<table class="table table-sm table-striped">' +
                                               '<thead class="thead-dark">' +
                                               '<tr><th>Phone</th><th>Status</th><th>Error Details</th></tr>' +
                                               '</thead><tbody>';
                                    
                                    if (job.targets && job.targets.length > 0) {
                                        job.targets.forEach(function(t) {
                                            var badge = 'badge-secondary';
                                            if (t.status === 'sent' || t.status === 'success') badge = 'badge-info';
                                            if (t.status === 'delivered') badge = 'badge-success';
                                            if (t.status === 'read') badge = 'badge-primary';
                                            if (t.status === 'failed' || t.status === 'webhook_failed') badge = 'badge-danger';

                                            html += '<tr>' +
                                                    '<td>' + t.phone + '</td>' +
                                                    '<td><span class="badge ' + badge + '">' + t.status + '</span></td>' +
                                                    '<td class="text-danger"><small>' + (t.error || '-') + '</small></td>' +
                                                    '</tr>';
                                        });
                                    } else {
                                        html += '<tr><td colspan="3" class="text-center">No targets found in job.</td></tr>';
                                    }
                                    html += '</tbody></table></div>';
                                    $('#jobDetailsContent').html(html);
                                } else {
                                    $('#jobDetailsContent').html('<div class="alert alert-danger">Error: ' + (res.message || 'Could not load details') + '</div>');
                                }
                            }
                        });
                    };

                    $('#campaignAnalysisModal').on('show.bs.modal', function() {
                        loadCampaignAnalysis();
                    });
                    
                    $('#refreshAnalysisBtn').off('click').on('click', function() {
                        loadCampaignAnalysis();
                    });
                });
            </script>
        <?php endif; ?>


    </div>
</body>
</html>
<?php
ob_end_flush();
?>